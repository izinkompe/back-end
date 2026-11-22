<?php
namespace App\Http\Controllers;
use App\Models\{FarmerVerification, Complaint, Order, Refund, EmissionFactor, AuditLog};
use App\Services\{Activity, OrderFlow, Pricing};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController
{
    public function verifications(Request $r) { $v = $r->validate(['status' => 'nullable|in:pending,approved,rejected,needs_revision']); return FarmerVerification::query()->when(isset($v['status']), fn ($q) => $q->where('status', $v['status']))->latest()->paginate(20); }
    public function review(Request $r, FarmerVerification $verification)
    {
        $v = $r->validate(['status' => 'required|in:approved,rejected,needs_revision', 'reason' => 'required|string|max:2000']);
        return DB::transaction(function () use ($r, $verification, $v) {
            $verification = FarmerVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();
            abort_unless($verification->status === 'pending', 409, 'Pengajuan sudah diputuskan.'); $before = $verification->getAttributes();
            if ($v['status'] === 'approved') { abort_unless($verification->evidence()->exists(), 422, 'Bukti pengajuan diperlukan sebelum persetujuan.'); }
            $verification->update($v + ['reviewer_id' => $r->user()->id, 'reviewed_at' => now()]); Activity::audit($verification, 'verification.reviewed', $before, $v['reason']);
            Activity::notify($verification->user_id, 'Hasil verifikasi: '.$v['status'], '/profile'); return $verification;
        });
    }
    public function complaints(Request $r) { $v = $r->validate(['status' => 'nullable|in:open,clarification,closed', 'type' => 'nullable|in:quality,weight,pickup,payment,cancellation,other']); return Complaint::with('messages')->when(isset($v['status']), fn ($q) => $q->where('status', $v['status']))->when(isset($v['type']), fn ($q) => $q->where('type', $v['type']))->latest()->paginate(20); }
    public function respond(Request $r, Complaint $complaint)
    {
        $v = $r->validate(['status' => 'required|in:clarification,closed', 'response' => 'required|string|max:5000']);
        return DB::transaction(function () use ($complaint, $v) {
            $complaint = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            $before = $complaint->getAttributes(); $complaint->update($v); Activity::audit($complaint, 'complaint.responded', $before, $v['response']);
            \App\Models\ComplaintMessage::create(['complaint_id' => $complaint->id, 'user_id' => auth()->id(), 'message' => $v['response']]);
            $order = Order::findOrFail($complaint->order_id);
            foreach ([$order->buyer_id, $order->farmer_id] as $user) { Activity::notify($user, 'Pengaduan: '.$v['status'], '/orders/'.$complaint->order_id); }
            return $complaint->load('messages');
        });
    }
    public function resolve(Request $r, Order $order, OrderFlow $flow)
    {
        $v = $r->validate(['action' => 'required|in:complete,cancel', 'reason' => 'required|string|max:5000', 'received_kg' => 'required_if:action,complete|numeric|min:0.001|max:999999.999|decimal:0,3', 'buyer_agreed' => 'required_if:action,complete|boolean', 'agreement' => 'required_if:action,complete|string|max:5000', 'restock_safe' => 'required_if:action,cancel|boolean']);
        return $flow->mutate($order, function ($o, $offer) use ($v, $flow) {
            abort_unless($o->status === 'issue', 409, 'Hanya pesanan bermasalah dapat diselesaikan admin.');
            if ($v['action'] === 'complete') {
                abort_unless($v['buyer_agreed'], 422, 'Persetujuan pembeli wajib dicatat.');
                $o->resolution_agreement = $v['agreement']; $o->save();
                $flow->complete($o, $offer, Pricing::grams($v['received_kg']), $v['reason']);
            }
            else {
                // The gateway must already be terminal, or paid funds must have a documented refund.
                $p = $o->payment;
                $safeUnpaid = $o->payment_status !== 'paid' && in_array($p->status, ['not_started', 'deny', 'cancel', 'expire'], true);
                $refunded = $o->refund?->status === 'completed' && (int) $o->refund->amount_idr === $o->total_idr;
                abort_unless($safeUnpaid || $refunded || $o->payment_status === 'refund', 409, 'Sinkronkan pembatalan Midtrans atau catat refund penuh sebelum membatalkan.');
                $flow->release($o, $offer);
                if (!$v['restock_safe']) { $before = $offer->getAttributes(); $offer->update(['status' => 'closed']); Activity::audit($offer, 'offer.closed', $before, $v['reason']); }
                $o->update(['status' => 'cancelled', 'issue_reason' => $v['reason']]);
            }
            Activity::audit($o, 'order.resolved', [], $v['reason']); Activity::notify($o->buyer_id, 'Penanganan pesanan selesai.', '/orders/'.$o->id);
        });
    }
    public function refund(Request $r, Order $order, OrderFlow $flow)
    {
        $v = $r->validate(['amount_idr' => 'required|integer|min:1|max:999999999', 'status' => 'required|in:requested,processing,completed', 'reason' => 'required|string|max:5000', 'reference' => 'required_if:status,completed|nullable|string|max:255']);
        return $flow->mutate($order, function ($o) use ($v, $r) {
            abort_unless(in_array($o->payment_status, ['paid', 'refund', 'partial_refund']), 409, 'Belum ada pembayaran terkonfirmasi.');
            abort_if($v['amount_idr'] > $o->total_idr, 422, 'Refund melebihi tagihan.');
            $existing = Refund::where('order_id', $o->id)->first();
            abort_if($existing?->status === 'completed', 409, 'Refund selesai tidak dapat ditimpa.');
            if ($v['status'] === 'completed') { abort_unless($existing && $existing->evidence()->exists(), 422, 'Buat catatan refund requested/processing, upload bukti, lalu tandai completed.'); }
            $before = $existing?->getAttributes() ?? [];
            $refund = Refund::updateOrCreate(['order_id' => $o->id], $v + ['recorded_by' => $r->user()->id]); Activity::audit($refund, 'refund.recorded', $before, $v['reason']);
            Activity::notify($o->buyer_id, 'Status pengembalian dana: '.$refund->status, '/orders/'.$o->id);
        });
    }
    public function factors() { return EmissionFactor::latest()->paginate(20); }
    public function factor(Request $r)
    {
        $v = $r->validate(['category_id' => 'required|exists:categories,id', 'kg_co2e_per_kg' => 'required|numeric|min:0|max:100000|decimal:0,6', 'unit' => 'sometimes|in:kgCO2e/kg', 'method_code' => 'sometimes|in:weight_factor_v1', 'valid_from' => 'nullable|date', 'valid_until' => 'nullable|date', 'source' => 'required|string|max:2000', 'version' => 'required|string|max:100', 'method' => 'required|string|max:5000', 'assumptions' => 'required|string|max:5000']);
        if (isset($v['valid_from'], $v['valid_until'])) { abort_unless(\Carbon\Carbon::parse($v['valid_until'])->gt(\Carbon\Carbon::parse($v['valid_from'])), 422, 'Akhir masa berlaku harus setelah awal masa berlaku.'); }
        return DB::transaction(function () use ($v) {
            \App\Models\Category::whereKey($v['category_id'])->lockForUpdate()->firstOrFail();
            foreach (EmissionFactor::where('category_id', $v['category_id'])->where('active', true)->lockForUpdate()->get() as $old) { $before = $old->getAttributes(); $old->update(['active' => false]); Activity::audit($old, 'factor.retired', $before); }
            $factor = EmissionFactor::create($v + ['active' => true]); Activity::audit($factor, 'factor.created'); return $factor;
        });
    }
    public function audit(Request $r) { return AuditLog::latest()->paginate(50); }
    public function releaseModeration(Request $r, \App\Models\Offer $offer)
    {
        $v = $r->validate(['reason' => 'required|string|max:2000']);
        return DB::transaction(function () use ($offer, $v) {
            $offer = \App\Models\Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail(); $before = $offer->getAttributes();
            $offer->update(['moderated_at' => null, 'moderation_reason' => null]);
            Activity::audit($offer, 'offer.moderation_released', $before, $v['reason']);
            Activity::notify($offer->farmer_id, 'Peninjauan admin selesai. Periksa kelengkapan sebelum menerbitkan ulang.', '/offers/'.$offer->id);
            return $offer;
        });
    }
    public function correctDistribution(Request $r, Order $order, OrderFlow $flow)
    {
        $v = $r->validate(['received_kg' => 'required|numeric|min:0|max:999999.999|decimal:0,3', 'expected_received_kg' => 'required|numeric|min:0|max:999999.999|decimal:0,3', 'reason' => 'required|string|max:5000', 'agreement' => 'required|string|max:5000', 'buyer_agreed' => 'required|accepted']);
        $key = $r->header('Idempotency-Key'); abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key), 422, 'Idempotency-Key wajib untuk koreksi.');
        $grams = (float) $v['received_kg'] == 0 ? 0 : Pricing::grams($v['received_kg']);
        $expected = (float) $v['expected_received_kg'] == 0 ? 0 : Pricing::grams($v['expected_received_kg']);
        return $flow->correctDistribution($order, $grams, $expected, $key, $v['reason'], $v['agreement']);
    }
    public function cancelPayment(Request $r, Order $order, \App\Services\Midtrans $midtrans)
    {
        $r->validate(['reason' => 'required|string|max:2000']);
        abort_unless($order->status === 'issue', 409, 'Tandai permintaan pembatalan melalui alur pesanan terlebih dahulu.');
        return $midtrans->cancel($order);
    }
}
