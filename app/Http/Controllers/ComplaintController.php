<?php
namespace App\Http\Controllers;
use App\Models\{Complaint, ComplaintMessage, Order};
use App\Services\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComplaintController
{
    private function authorize(Request $r, Complaint $complaint): Order
    {
        $order = Order::findOrFail($complaint->order_id);
        abort_unless($r->user()->role === 'admin' || in_array($r->user()->id, [$order->buyer_id, $order->farmer_id], true), 403);
        return $order;
    }
    public function show(Request $r, Complaint $complaint)
    {
        $this->authorize($r, $complaint); return $complaint->load('messages');
    }
    public function message(Request $r, Complaint $complaint)
    {
        $order = $this->authorize($r, $complaint); $v = $r->validate(['message' => 'required|string|max:5000']);
        return DB::transaction(function () use ($r, $complaint, $order, $v) {
            $complaint = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            abort_if($complaint->status === 'closed', 409, 'Pengaduan telah ditutup.');
            $message = ComplaintMessage::create($v + ['complaint_id' => $complaint->id, 'user_id' => $r->user()->id]);
            Activity::audit($message, 'complaint.clarification');
            $recipients = \App\Models\User::where('role', 'admin')->pluck('id')->merge([$order->buyer_id, $order->farmer_id])->unique();
            foreach ($recipients as $user) { if ($user !== $r->user()->id) { Activity::notify($user, 'Ada klarifikasi pengaduan.', '/orders/'.$order->id); } }
            return $message;
        });
    }
}
