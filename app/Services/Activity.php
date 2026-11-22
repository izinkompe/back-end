<?php
namespace App\Services;
use App\Models\{AuditLog, Notice};
use Illuminate\Database\Eloquent\Model;

class Activity
{
    public static function audit(Model $model, string $action, array $before = [], ?string $reason = null): void
    {
        $safe = ['status', 'payment_status', 'stock_state', 'stock_g', 'reserved_g', 'sold_g', 'price_per_kg', 'minimum_price', 'quantity_g', 'received_g', 'total_idr', 'pickup_starts_at', 'pickup_ends_at', 'proposed_pickup', 'response', 'amount_idr', 'reference', 'active', 'kg_co2e_per_kg', 'source', 'version', 'method', 'assumptions', 'closes_at', 'condition'];
        AuditLog::create(['actor_id' => auth()->id(), 'action' => $action, 'subject_type' => $model->getMorphClass(), 'subject_id' => $model->id, 'before' => array_intersect_key($before, array_flip($safe)), 'after' => array_intersect_key($model->getAttributes(), array_flip($safe)), 'reason' => $reason]);
    }
    public static function notify(int $user, string $title, string $link, ?string $key = null): void
    {
        Notice::firstOrCreate(['user_id' => $user, 'event_key' => $key ?? (string) \Illuminate\Support\Str::uuid()], ['title' => mb_substr($title, 0, 255), 'link' => $link]);
    }
}
