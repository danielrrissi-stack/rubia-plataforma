<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Growth\Lms;
use PHPUnit\Framework\TestCase;

final class LmsTest extends TestCase
{
    public function testZWithNonZeroL(): void
    {
        $this->assertEqualsWithDelta(1.0, Lms::z(11, 1, 10, 0.1), 1e-9);
    }

    public function testZWithZeroL(): void
    {
        $this->assertEqualsWithDelta(0.0, Lms::z(10, 0, 10, 0.1), 1e-9);
        $this->assertEqualsWithDelta(log(1.1) / 0.1, Lms::z(11, 0, 10, 0.1), 1e-9);
    }

    public function testValueAtIsInverseOfZ(): void
    {
        $x = Lms::valueAt(2.0, -1.6, 17.0, 0.12);
        $this->assertEqualsWithDelta(2.0, Lms::z($x, -1.6, 17.0, 0.12), 1e-9);
        $x0 = Lms::valueAt(-1.5, 0.0, 17.0, 0.12);
        $this->assertEqualsWithDelta(-1.5, Lms::z($x0, 0.0, 17.0, 0.12), 1e-9);
    }

    public function testRestrictedAboveThree(): void
    {
        [$l, $m, $s] = [-1.6, 17.0, 0.12];
        $sd3 = Lms::valueAt(3, $l, $m, $s);
        $sd2 = Lms::valueAt(2, $l, $m, $s);
        $x = $sd3 + 2 * ($sd3 - $sd2);
        $this->assertEqualsWithDelta(5.0, Lms::zRestricted($x, $l, $m, $s), 1e-9);
        $this->assertGreaterThan(1e-3, abs(Lms::z($x, $l, $m, $s) - 5.0));
    }

    public function testRestrictedBelowMinusThree(): void
    {
        [$l, $m, $s] = [-1.6, 17.0, 0.12];
        $sd3n = Lms::valueAt(-3, $l, $m, $s);
        $sd2n = Lms::valueAt(-2, $l, $m, $s);
        $x = $sd3n - ($sd2n - $sd3n);
        $this->assertEqualsWithDelta(-4.0, Lms::zRestricted($x, $l, $m, $s), 1e-9);
    }

    public function testRestrictedEqualsZInsideRange(): void
    {
        $this->assertEqualsWithDelta(Lms::z(18, -1.6, 17, 0.12), Lms::zRestricted(18, -1.6, 17, 0.12), 1e-12);
    }

    public function testPercentile(): void
    {
        $this->assertSame(50.0, Lms::percentile(0));
        $this->assertEqualsWithDelta(97.0, Lms::percentile(1.881), 0.1);
        $this->assertEqualsWithDelta(15.0, Lms::percentile(-1.036), 0.1);
        $this->assertEqualsWithDelta(2.3, Lms::percentile(-2.0), 0.05);
    }
}
