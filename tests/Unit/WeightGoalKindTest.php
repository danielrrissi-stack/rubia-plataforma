<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Growth\Age;
use App\WeightGoals\WeightGoalKind;
use PHPUnit\Framework\TestCase;

final class WeightGoalKindTest extends TestCase
{
    public function test_child_under_13_only_z(): void
    {
        $this->assertSame(['imc_z'], WeightGoalKind::allowed('2016-01-10', '2026-11-19'));
    }

    public function test_teen_chooses(): void
    {
        $this->assertSame(['imc_z', 'peso'], WeightGoalKind::allowed('2013-03-01', '2026-11-19'));
    }

    public function test_adult_only_kg(): void
    {
        $this->assertSame(['peso'], WeightGoalKind::allowed('2000-01-01', '2026-11-19'));
    }

    public function test_z_deadline_limit_is_last_day_before_229_months(): void
    {
        $d = WeightGoalKind::zDeadlineLimit('2010-01-15');
        $next = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d');
        $this->assertLessThan(229, Age::months('2010-01-15', $d));
        $this->assertGreaterThanOrEqual(229, Age::months('2010-01-15', $next));
    }
}
