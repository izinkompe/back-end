<?php
namespace App\Services;
use App\Models\EmissionFactor;

class Impact
{
    public const METHOD = 'weight_factor_v1';
    public const UNIT = 'kgCO2e/kg';
    public function factor(?int $category): ?EmissionFactor
    {
        return EmissionFactor::where('category_id', $category)->where('active', true)
            ->where('method_code', self::METHOD)->where('unit', self::UNIT)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->latest('id')->first();
    }
    public function estimate(int $grams, ?array $snapshot): ?float
    {
        if (!$snapshot || ($snapshot['method_code'] ?? '') !== self::METHOD || ($snapshot['unit'] ?? '') !== self::UNIT) { return null; }
        return round($grams / 1000 * (float) $snapshot['kg_co2e_per_kg'], 6);
    }
    public function snapshot(?EmissionFactor $factor): ?array
    {
        return $factor?->only(['kg_co2e_per_kg', 'unit', 'source', 'version', 'method', 'method_code', 'assumptions', 'valid_from', 'valid_until']);
    }
}
