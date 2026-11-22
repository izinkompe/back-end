<?php
namespace App\Http\Controllers;
use App\Models\Order;
use App\Services\Midtrans;
use Illuminate\Http\Request;

class WebhookController
{
    public function __invoke(Request $r, Midtrans $midtrans)
    {
        $v = $r->validate(['order_id' => 'required|string|max:50', 'status_code' => 'required|string|max:10', 'gross_amount' => 'required|string|max:30', 'signature_key' => 'required|string|size:128']);
        $midtrans->verifySignature($v);
        $order = Order::where('number', $v['order_id'])->firstOrFail();
        // Notification status is untrusted: always fetch current status using the server key.
        $midtrans->reconcile($order);
        return ['message' => 'OK'];
    }
}
