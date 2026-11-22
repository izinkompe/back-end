<?php
namespace App\Http\Controllers;
use App\Models\{Distribution, Offer};
use Illuminate\Http\Request;

class ReportController
{
    private function query(Request $r)
    {
        $v = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'area' => 'nullable|string|max:255', 'commodity' => 'nullable|string|max:150']);
        $q = Distribution::query()->join('orders', 'orders.id', '=', 'distributions.order_id')->where('orders.status', 'completed');
        if ($r->user()->role === 'farmer') { $q->where('orders.farmer_id', $r->user()->id); }
        if (isset($v['from'])) { $q->where('orders.completed_at', '>=', $v['from']); }
        if (isset($v['to'])) { $q->where('orders.completed_at', '<=', $v['to']); }
        if (isset($v['area'])) { $q->where('orders.product_snapshot->area', $v['area']); }
        if (isset($v['commodity'])) { $q->where('orders.product_snapshot->name', $v['commodity']); }
        return $q;
    }
    public function index(Request $r)
    {
        $q = $this->query($r);
        $totals = (clone $q)->selectRaw('COUNT(*) as completed_orders, COALESCE(SUM(distributions.received_g), 0) as distributed_g, SUM(distributions.estimated_co2e_kg) as estimated_co2e_kg, COALESCE(SUM(CASE WHEN distributions.emission_factor_id IS NULL THEN 1 ELSE 0 END), 0) as uncalculated_orders')->first();
        return ['summary' => $totals, 'label' => 'Pangan tersalurkan', 'weight_unit' => 'gram', 'carbon_unit' => 'kgCO2e', 'display_timezone' => 'Asia/Jakarta', 'transactions' => $q->select('distributions.*', 'orders.number', 'orders.product_snapshot', 'orders.completed_at')->orderByDesc('orders.completed_at')->paginate(20), 'inventory' => $r->user()->role === 'farmer' ? Offer::where('farmer_id', $r->user()->id)->get(['id', 'name', 'stock_g', 'reserved_g', 'sold_g', 'status', 'minimum_price', 'price_per_kg', 'closes_at']) : null];
    }
    public static function csvCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value) ? "'".$value : $value;
    }
    public function export(Request $r)
    {
        $q = $this->query($r)->select('distributions.*', 'orders.number', 'orders.product_snapshot', 'orders.completed_at')->orderBy('distributions.id');
        return response()->streamDownload(function () use ($q, $r) {
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['periode_dari', 'periode_sampai', 'dibuat_utc', 'tanggal_selesai_utc', 'transaksi', 'komoditas', 'wilayah', 'berat_kg', 'status', 'estimasi_kgCO2e', 'faktor_sumber', 'faktor_versi'], ',', '"', '');
            foreach ($q->cursor() as $row) {
                $snapshot = json_decode($row->getRawOriginal('product_snapshot'), true);
                $values = [$r->input('from', ''), $r->input('to', ''), now()->toIso8601String(), $row->completed_at, $row->number, $snapshot['name'] ?? '', $snapshot['area'] ?? '', number_format($row->received_g / 1000, 3, '.', ''), 'completed', $row->estimated_co2e_kg ?? 'belum dihitung', $row->factor_snapshot['source'] ?? '', $row->factor_snapshot['version'] ?? ''];
                fputcsv($out, array_map([self::class, 'csvCell'], $values), ',', '"', '');
            }
            fclose($out);
        }, 'pangantani-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
