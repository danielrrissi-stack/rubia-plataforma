<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Growth\Velocity;
use PHPUnit\Framework\TestCase;

final class VelocityTest extends TestCase
{
    public function testOneYearGivesSixCmPerYear(): void
    {
        $r = Velocity::heightVelocity([
            ['measured_on' => '2024-01-01', 'height_cm' => '100.0'],
            ['measured_on' => '2025-01-01', 'height_cm' => '106.0'],
        ]);
        $this->assertCount(1, $r);
        $this->assertSame('2024-01-01', $r[0]['from']);
        $this->assertSame('2025-01-01', $r[0]['to']);
        $this->assertEqualsWithDelta(6.0, $r[0]['cm_per_year'], 0.05);
    }

    public function testShortIntervalIgnored(): void
    {
        $r = Velocity::heightVelocity([
            ['measured_on' => '2024-01-01', 'height_cm' => 100.0],
            ['measured_on' => '2024-03-01', 'height_cm' => 101.0],
        ]);
        $this->assertSame([], $r);
    }

    public function testMeasurementsWithoutHeightAreSkippedAndOrderIsByDate(): void
    {
        $r = Velocity::heightVelocity([
            ['measured_on' => '2025-01-01', 'height_cm' => 106.0],
            ['measured_on' => '2024-06-01', 'height_cm' => null],
            ['measured_on' => '2024-01-01', 'height_cm' => 100.0],
        ]);
        $this->assertCount(1, $r);
        $this->assertSame('2024-01-01', $r[0]['from']);
    }
}
