<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Energy\EnergyCalculator;
use PHPUnit\Framework\TestCase;

final class EnergyCalculatorTest extends TestCase
{
    public function testMifflin(): void
    {
        $this->assertSame(1780.0, EnergyCalculator::mifflin('M', 80, 180, 30));
        $this->assertSame(1345.25, EnergyCalculator::mifflin('F', 60, 165, 25));
    }

    public function testHarrisBenedict(): void
    {
        $this->assertEqualsWithDelta(1864.545, EnergyCalculator::harrisBenedict('M', 80, 180, 30), 0.001);
        $this->assertEqualsWithDelta(1417.194, EnergyCalculator::harrisBenedict('F', 60, 165, 25), 0.001);
    }

    public function testFaoBandsLowerBoundInclusive(): void
    {
        $this->assertSame(1403.0, EnergyCalculator::faoOms1985('M', 40, 9.5));
        $this->assertSame(1351.0, EnergyCalculator::faoOms1985('M', 40, 10.0));
        $this->assertSame(1356.0, EnergyCalculator::faoOms1985('F', 50, 14));
    }

    public function testAllNullForChildren(): void
    {
        $r = EnergyCalculator::all('F', 30, 130, 9);
        $this->assertNull($r['mifflin']);
        $this->assertNull($r['harris_benedict']);
        $this->assertIsInt($r['fao_oms_1985']);
        $adult = EnergyCalculator::all('M', 80, 180, 30);
        $this->assertSame(1780, $adult['mifflin']);
        $this->assertSame(1865, $adult['harris_benedict']);
    }
}
