<?php
namespace App\Http\Controllers;
use App\Models\{Order, Complaint, AuditLog};
use App\Services\{OrderFlow, Midtrans, Activity, Pricing};
use Illuminate\Http\Request;

class OrderController
{
    public function index(Request $r)
    {
        $v = $r->validate(['status' => 'nullable|in:awaiting_payment,processing,ready,completed,cancelled,issue', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $q = Order::with(['payment', 'refund'])->latest();
        if ($r->user()->role !== 'admin') { $q->where($r->user()->role === 'farmer' ? 'farmer_id' : 'buyer_id', $r->user()->id); }
        if (isset($v['status'])) { $q->where('status', $v['status']); }
        if (isset($v['from'])) { $q->where('created_at', '>=', $v['from']); }
        if (isset($v['to'])) { $q->where('created_at', '<=', $v['to']); }
        return $q->paginate(20);
    }
    public function show(Request $r, Order $order)
    {
        $this->authorize($r, $order);
        return ['order' => $order->load(['payment', 'complaints', 'refund']), 'contacts' => \App\Models\User::whereIn('id', [$order->buyer_id, $order->farmer_id])->get(['id', 'name', 'phone', 'organization']), 'history' => AuditLog::where('subject_type', Order::class)->where('subject_id', $order->id)->orderBy('id')->get(['action', 'before', 'after', 'reason', 'created_at'])];
    }
    public function store(Request $r, OrderFlow $flow)
    {
        $v = $r->validate(['offer_id' => 'required|integer', 'pickup_slot_id' => 'required|integer', 'quantity_kg' => 'required|numeric', 'expected_price_per_kg' => 'required|integer|min:1']);
        $key = $r->header('Idempotency-Key'); abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key), 422, 'Idempotency-Key wajib, 8–100 karakter huruf/angka/_/-.');
        return response()->json($flow->checkout($r->user()->id, $v, $key), 201);
    }
    public function snap(Request $r, Order $order, Midtrans $midtrans) { $this->buyer($r, $order); return $midtrans->snap($order); }
    public function sync(Request $r, Order $order, Midtrans $midtrans) { $this->authorize($r, $order); return $midtrans->reconcile($order); }
    public function ready(Request $r, Order $order, OrderFlow $flow)
    {
        abort_unless($order->farmer_id === $r->user()->id, 403);
        return $flow->mutate($order, function ($o) {
            if ($o->status === 'ready') { return; }
            abort_unless($o->status === 'processing' && $o->payment_status === 'paid', 409, 'Pesanan belum dapat disiapkan.');
            $o->update(['status' => 'ready']); Activity::notify($o->buyer_id, 'Pesanan siap diambil.', '/orders/'.$o->id, 'ready:'.$o->id);
        });
    }
    public function receive(Request $r, Order $order, OrderFlow $flow)
    {
        $this->buyer($r, $order); $v = $r->validate(['received_kg' => 'required|numeric|min:0|max:999999.999|decimal:0,3']);
        $grams = (float) $v['received_kg'] === 0.0 ? 0 : Pricing::grams($v['received_kg']);
        return $flow->mutate($order, function ($o, $offer) use ($grams, $flow) {
            abort_unless(in_array($o->status, ['ready', 'completed']), 409, 'Pesanan belum siap atau sedang ditangani.');
            abort_if($grams > $o->quantity_g, 422, 'Berat melebihi pesanan.');
            if ($o->status === 'completed') { abort_unless($o->received_g === $grams, 409); return; }
            if ($grams !== $o->quantity_g) { $o->update(['received_g' => $grams]); $flow->issue($o, 'Selisih berat saat penerimaan.'); }
            else { $flow->complete($o, $offer, $grams); }
        });
    }
    public function cancel(Request $r, Order $order, OrderFlow $flow)
    {
        $this->buyer($r, $order); $v = $r->validate(['reason' => 'required|string|max:2000']);
        return $flow->mutate($order, function ($o, $offer) use ($flow, $v) {
            if ($o->status === 'cancelled') { return; }
            abort_if($o->status === 'completed', 409, 'Pesanan sudah selesai. Gunakan pengaduan.');
            if ($o->status === 'awaiting_payment' && $o->payment->status === 'not_started') { $flow->release($o, $offer); $o->update(['status' => 'cancelled', 'issue_reason' => $v['reason']]); }
            else { $flow->issue($o, 'Permintaan pembatalan: '.$v['reason']); }
        });
    }
    public function complaint(Request $r, Order $order)
    {
        $this->authorize($r, $order); $v = $r->validate(['description' => 'required|string|max:5000']);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($r, $order, $v) {
            $c = Complaint::create($v + ['order_id' => $order->id, 'user_id' => $r->user()->id]); Activity::audit($c, 'complaint.created'); return $c;
        });
    }
    public function propose(Request $r, Order $order, OrderFlow $flow)
    {
        abort_unless($order->farmer_id === $r->user()->id, 403);
        $v = $r->validate(['starts_at' => 'required|date|after:now', 'ends_at' => 'required|date|after:starts_at']);
        return $flow->mutate($order, function ($o) use ($v) {
            abort_unless(in_array($o->status, ['processing', 'ready']), 409);
            $o->update(['proposed_pickup' => $v]); Activity::notify($o->buyer_id, 'Petani mengusulkan jadwal baru.', '/orders/'.$o->id);
        });
    }
    public function scheduleDecision(Request $r, Order $order, OrderFlow $flow)
    {
        $this->buyer($r, $order); $v = $r->validate(['accept' => 'required|boolean']);
        return $flow->mutate($order, function ($o) use ($v) {
            abort_unless($o->proposed_pickup && in_array($o->status, ['processing', 'ready']), 409, 'Tidak ada usulan jadwal aktif.');
            if ($v['accept']) { abort_unless(\Carbon\Carbon::parse($o->proposed_pickup['starts_at'])->isFuture(), 409, 'Usulan sudah lewat.'); $o->pickup_starts_at = $o->proposed_pickup['starts_at']; $o->pickup_ends_at = $o->proposed_pickup['ends_at']; }
            $o->proposed_pickup = null; $o->save(); Activity::notify($o->farmer_id, $v['accept'] ? 'Jadwal baru disetujui.' : 'Jadwal baru ditolak.', '/orders/'.$o->id);
        });
    }
    private function authorize(Request $r, Order $o): void { abort_unless($r->user()->role === 'admin' || in_array($r->user()->id, [$o->buyer_id, $o->farmer_id], true), 403); }
    private function buyer(Request $r, Order $o): void { abort_unless($r->user()->id === $o->buyer_id, 403); }
}
