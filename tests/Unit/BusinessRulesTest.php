<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\{Pricing, Reminders};
use App\Http\Controllers\ReportController;

class BusinessRulesTest extends TestCase
{
    public function test_weights_and_rounding_use_integer_arithmetic(): void
    {
        $this->assertSame(12345, Pricing::grams('12.345'));
        $this->assertSame(1, Pricing::grams('0.001'));
        $this->assertSame(1502, Pricing::total(1500, 1001));
        $this->assertSame(500, Pricing::total(1, 500000));
    }
    public function test_distance_is_straight_line_in_km(): void
    {
        $this->assertEqualsWithDelta(111.195, Reminders::distance(0, 0, 1, 0), 0.01);
        $this->assertSame(0.0, Reminders::distance(-6.2, 106.8, -6.2, 106.8));
    }
    public function test_csv_formula_prefixes_are_neutralized(): void
    {
        foreach (['=1+1', '+cmd', '-1', '@SUM(A1)', "\t=1+1", '   =1+1'] as $cell) { $this->assertSame("'".$cell, ReportController::csvCell($cell)); }
        $this->assertSame('Bayam', ReportController::csvCell('Bayam'));
    }
}
