<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Growth\Age;
use PHPUnit\Framework\TestCase;

final class AgeTest extends TestCase
{
    public function testDaysAndMonths(): void
    {
        $this->assertSame(0, Age::days('2020-01-01', '2020-01-01'));
        $this->assertSame(366, Age::days('2020-01-01', '2021-01-01'));
        $this->assertEqualsWithDelta(60 / 30.4375, Age::months('2020-01-01', '2020-03-01'), 0.0001);
    }

    public function testCompleteYears(): void
    {
        $this->assertSame(17, Age::years('2008-11-02', '2026-11-01'));
        $this->assertSame(18, Age::years('2008-11-01', '2026-11-01'));
        $this->assertSame(18, Age::years('2008-02-29', '2026-03-01'));
        $this->assertSame(17, Age::years('2008-02-29', '2026-02-28'));
    }
}
