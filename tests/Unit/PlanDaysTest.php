<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Nutrition\PlanDays;
use PHPUnit\Framework\TestCase;

final class PlanDaysTest extends TestCase
{
    private static function plan(string $start, int $n): array
    {
        return ['v' => 2, 'start' => $start, 'days' => array_fill(0, $n, ['meals' => []])];
    }

    public function test_index_inside_before_and_after_end(): void
    {
        $p = self::plan('2026-11-23', 14);
        $this->assertSame(0, PlanDays::indexFor($p, '2026-11-23'));
        $this->assertSame(13, PlanDays::indexFor($p, '2026-12-06'));
        $this->assertNull(PlanDays::indexFor($p, '2026-11-22'));
        $this->assertSame(7, PlanDays::indexFor($p, '2026-12-07'));
        $this->assertSame(13, PlanDays::indexFor($p, '2026-12-13'));
        $this->assertSame(7, PlanDays::indexFor($p, '2026-12-14'));
    }

    public function test_short_plan_cycles_all_days(): void
    {
        $p = self::plan('2026-11-23', 3);
        $this->assertSame(0, PlanDays::indexFor($p, '2026-11-26'));
        $this->assertSame(2, PlanDays::indexFor($p, '2026-11-28'));
        $this->assertSame(0, PlanDays::indexFor($p, '2026-11-29'));
    }

    public function test_window_starts_today_or_at_start(): void
    {
        $p = self::plan('2026-11-23', 14);
        $w = PlanDays::window($p, '2026-11-20');
        $this->assertSame(
            ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29'],
            array_column($w, 'date')
        );
        $w = PlanDays::window($p, '2026-12-04');
        $this->assertSame(
            [['date' => '2026-12-04', 'index' => 11], ['date' => '2026-12-05', 'index' => 12],
             ['date' => '2026-12-06', 'index' => 13], ['date' => '2026-12-07', 'index' => 7],
             ['date' => '2026-12-08', 'index' => 8], ['date' => '2026-12-09', 'index' => 9],
             ['date' => '2026-12-10', 'index' => 10]],
            $w
        );
    }

    public function test_year_boundary(): void
    {
        $p = self::plan('2026-12-28', 14);
        $this->assertSame('2027-01-10', PlanDays::lastDate($p));
        $this->assertSame(4, PlanDays::window($p, '2027-01-01')[0]['index']);
    }

    public function test_v1_is_single_day(): void
    {
        $v1 = ['v' => 1, 'meals' => [['name' => 'x']]];
        $this->assertFalse(PlanDays::isMulti($v1));
        $this->assertFalse(PlanDays::isMulti(null));
        $this->assertSame([['date' => null, 'meals' => [['name' => 'x']]]], PlanDays::days($v1));
        $this->assertNull(PlanDays::lastDate($v1));
        $this->assertSame(0, PlanDays::indexFor($v1, '2030-01-01'));
        $this->assertSame([], PlanDays::window($v1, '2026-11-20'));
        $this->assertSame($v1, PlanDays::dayStructure($v1, 0));
    }

    public function test_day_structure_v2(): void
    {
        $p = self::plan('2026-11-23', 2);
        $p['days'][1]['meals'] = [['name' => 'b']];
        $this->assertSame(['v' => 1, 'meals' => [['name' => 'b']]], PlanDays::dayStructure($p, 1));
    }

    public function test_remaining(): void
    {
        $p = self::plan('2026-11-23', 14);
        $this->assertSame(4, PlanDays::remainingFrom($p, '2026-12-03'));
        $this->assertSame(1, PlanDays::remainingFrom($p, '2026-12-06'));
        $this->assertSame(0, PlanDays::remainingFrom($p, '2026-12-07'));
    }
}
