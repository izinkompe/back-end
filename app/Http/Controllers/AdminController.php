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
            $verification->update($v + ['reviewer_id' => $r->user()->id, 'reviewed_at' => now()]); Activity::audit($verification, 'verification.reviewed', $before, $v['reason']);
            Activity::notify($verification->user_id, 'Hasil verifikasi: '.$v['status'], '/profile'); return $verification;
        });
    }
    public function complaints(Request $r) { $v = $r->validate(['status' => 'nullable|in:open,clarification,closed']); return Complaint::query()->when(isset($v['status']), fn ($q) => $q->where('status', $v['status']))->latest()->paginate(20); }
    public function respond(Request $r, Complaint $complaint)
    {
        $v = $r->validate(['status' => 'required|in:clarification,closed', 'response' => 'required|string|max:5000']);
        return DB::transaction(function () use ($complaint, $v) { $before = $complaint->getAttributes(); $complaint->update($v); Activity::audit($complaint, 'complaint.responded', $before, $v['response']); Activity::notify($complaint->user_id, 'Pengaduan telah ditanggapi.', '/orders/'.$complaint->order_id); return $complaint; });
    }
    public function resolve(Request $r, Order $order, OrderFlow $flow)
    {
        $v = $r->validate(['action' => 'required|in:complete,cancel', 'reason' => 'required|string|max:5000', 'received_kg' => 'required_if:action,complete|numeric|min:0.001|max:999999.999|decimal:0,3', 'buyer_agreed' => 'required_if:action,complete|boolean', 'restock_safe' => 'required_if:action,cancel|boolean']);
        return $flow->mutate($order, function ($o, $offer) use ($v, $flow) {
            abort_unless($o->status === 'issue', 409, 'Hanya pesanan bermasalah dapat diselesaikan admin.');
            if ($v['action'] === 'complete') { abort_unless($v['buyer_agreed'], 422, 'Catat persetujuan pembeli di alasan.'); $flow->complete($o, $offer, Pricing::grams($v['received_kg']), $v['reason']); }
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
            $refund = Refund::updateOrCreate(['order_id' => $o->id], $v + ['recorded_by' => $r->user()->id]); Activity::audit($refund, 'refund.recorded', [], $v['reason']);
        });
    }
    public function factors() { return EmissionFactor::latest()->paginate(20); }
    public function factor(Request $r)
    {
        $v = $r->validate(['category_id' => 'required|exists:categories,id', 'kg_co2e_per_kg' => 'required|numeric|min:0|max:100000|decimal:0,6', 'source' => 'required|string|max:2000', 'version' => 'required|string|max:100', 'method' => 'required|string|max:5000', 'assumptions' => 'required|string|max:5000']);
        return DB::transaction(function () use ($v) {
            \App\Models\Category::whereKey($v['category_id'])->lockForUpdate()->firstOrFail();
            EmissionFactor::where('category_id', $v['category_id'])->update(['active' => false]);
            $factor = EmissionFactor::create($v + ['active' => true]); Activity::audit($factor, 'factor.created'); return $factor;
        });
    }
    public function audit(Request $r) { return AuditLog::latest()->paginate(50); }
    public function cancelPayment(Request $r, Order $order, \App\Services\Midtrans $midtrans)
    {
        $r->validate(['reason' => 'required|string|max:2000']);
        abort_unless($order->status === 'issue', 409, 'Tandai permintaan pembatalan melalui alur pesanan terlebih dahulu.');
        return $midtrans->cancel($order);
    }
}
