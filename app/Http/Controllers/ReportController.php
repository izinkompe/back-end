<?php
namespace App\Http\Controllers;
use App\Models\{Distribution, Offer};
use Illuminate\Http\Request;

class ReportController
{
    private function commodityFilter($query, string $value): void
    {
        $query->where(fn ($q) => $q->where('orders.product_snapshot->commodity', $value)->orWhere(fn ($legacy) => $legacy->whereNull('orders.product_snapshot->commodity')->where('orders.product_snapshot->name', $value)));
    }
    private function query(Request $r)
    {
        $v = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'area' => 'nullable|string|max:255', 'commodity' => 'nullable|string|max:150']);
        $q = Distribution::query()->join('orders', 'orders.id', '=', 'distributions.order_id')->where('orders.status', 'completed');
        if ($r->user()->role === 'farmer') { $q->where('orders.farmer_id', $r->user()->id); }
        if (isset($v['from'])) { $q->where('orders.completed_at', '>=', $v['from']); }
        if (isset($v['to'])) { $q->where('orders.completed_at', '<=', $v['to']); }
        if (isset($v['area'])) { $q->where('orders.product_snapshot->area', $v['area']); }
        if (isset($v['commodity'])) { $this->commodityFilter($q, $v['commodity']); }
        return $q;
    }
    public function index(Request $r)
    {
        $q = $this->query($r);
        $totals = (clone $q)->selectRaw('COUNT(*) as completed_orders, COALESCE(SUM(distributions.received_g), 0) as distributed_g, SUM(distributions.estimated_co2e_kg) as estimated_co2e_kg, COALESCE(SUM(CASE WHEN distributions.emission_factor_id IS NULL THEN 1 ELSE 0 END), 0) as uncalculated_orders')->first();
        return ['summary' => $totals, 'label' => 'Pangan tersalurkan', 'weight_unit' => 'gram', 'carbon_unit' => 'kgCO2e', 'display_timezone' => 'Asia/Jakarta', 'transactions' => $q->select('distributions.*', 'orders.number', 'orders.product_snapshot', 'orders.completed_at')->orderByDesc('orders.completed_at')->paginate(20), 'inventory' => $r->user()->role === 'farmer' ? Offer::with('steps')->where('farmer_id', $r->user()->id)->get(['id', 'name', 'stock_g', 'reserved_g', 'sold_g', 'withheld_g', 'status', 'minimum_price', 'price_per_kg', 'closes_at']) : null];
    }
    public static function csvCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value) ? "'".$value : $value;
    }
    public function export(Request $r)
    {
        $scope = $r->validate(['scope' => 'sometimes|in:distribution,orders'])['scope'] ?? 'distribution';
        if ($scope === 'distribution') {
            $q = $this->query($r)->select('distributions.*', 'orders.number', 'orders.product_snapshot', 'orders.quantity_g', 'orders.completed_at', 'orders.status as order_status')->orderBy('distributions.id');
        } else {
            $v = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'area' => 'nullable|string|max:255', 'commodity' => 'nullable|string|max:150']);
            $q = \App\Models\Order::query()->leftJoin('distributions', 'distributions.order_id', '=', 'orders.id');
            if (isset($v['from'])) { $q->where('orders.created_at', '>=', $v['from']); }
            if (isset($v['to'])) { $q->where('orders.created_at', '<=', $v['to']); }
            if (isset($v['area'])) { $q->where('orders.product_snapshot->area', $v['area']); }
            if (isset($v['commodity'])) { $this->commodityFilter($q, $v['commodity']); }
            $q->select('orders.number', 'orders.product_snapshot', 'orders.quantity_g', 'orders.status as order_status', 'orders.created_at as completed_at', 'distributions.received_g', 'distributions.estimated_co2e_kg', 'distributions.factor_snapshot')->orderBy('orders.id');
        }
        $generated = now()->toIso8601String();
        return response()->streamDownload(function () use ($q, $r, $scope, $generated) {
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['cakupan', 'periode_dari', 'periode_sampai', 'dibuat_utc', 'tanggal_utc', 'transaksi', 'komoditas', 'wilayah', 'berat_dipesan_kg', 'berat_diterima_kg', 'status', 'estimasi_kgCO2e', 'faktor_sumber', 'faktor_versi'], ',', '"', '');
            foreach ($q->cursor() as $row) {
                $snapshot = json_decode($row->getRawOriginal('product_snapshot'), true);
                $factor = json_decode($row->getRawOriginal('factor_snapshot') ?? 'null', true);
                $values = [$scope, $r->input('from', ''), $r->input('to', ''), $generated, $row->completed_at, $row->number, $snapshot['commodity'] ?? $snapshot['name'] ?? '', $snapshot['area'] ?? '', number_format($row->quantity_g / 1000, 3, '.', ''), $row->received_g === null ? '' : number_format($row->received_g / 1000, 3, '.', ''), $row->order_status, $row->estimated_co2e_kg ?? 'belum dihitung', $factor['source'] ?? '', $factor['version'] ?? ''];
                fputcsv($out, array_map([self::class, 'csvCell'], $values), ',', '"', '');
            }
            fclose($out);
        }, 'pangantani-'.$scope.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
