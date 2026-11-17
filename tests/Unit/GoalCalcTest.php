<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Goals\GoalCalc;
use PHPUnit\Framework\TestCase;

final class GoalCalcTest extends TestCase
{
    public function testWeekStartAndWindow(): void
    {
        $this->assertSame('2026-11-16', GoalCalc::weekStart('2026-11-22'));
        $this->assertSame('2026-11-23', GoalCalc::weekStart('2026-11-23'));
        $g = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-11-01', 'end_date' => null];
        $this->assertTrue(GoalCalc::canMark($g, '2026-11-11', '2026-11-17'));
        $this->assertFalse(GoalCalc::canMark($g, '2026-11-10', '2026-11-17'));
        $this->assertFalse(GoalCalc::canMark($g, '2026-11-18', '2026-11-17'));
        $late = ['id' => 2, 'times_per_week' => 7, 'start_date' => '2026-11-15', 'end_date' => '2026-11-16'];
        $this->assertFalse(GoalCalc::canMark($late, '2026-11-14', '2026-11-17'));
        $this->assertFalse(GoalCalc::canMark($late, '2026-11-17', '2026-11-17'));
    }

    public function testWeekStatusMidWeekStartAndNPerWeek(): void
    {
        $daily = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-11-19', 'end_date' => null];
        $s = GoalCalc::weekStatus($daily, ['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'], '2026-11-16');
        $this->assertSame(['done' => 4, 'target' => 4, 'applicable' => true, 'complete' => true], $s);
        $three = ['id' => 2, 'times_per_week' => 3, 'start_date' => '2026-11-01', 'end_date' => null];
        $this->assertFalse(GoalCalc::weekStatus($three, ['2026-11-16', '2026-11-18'], '2026-11-16')['complete']);
        $this->assertTrue(GoalCalc::weekStatus($three, ['2026-11-16', '2026-11-18', '2026-11-22'], '2026-11-16')['complete']);
    }

    public function testStarsIgnoreChecksOutsidePeriod(): void
    {
        $g = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12'];
        $checks = [['goal_id' => 1, 'day' => '2026-11-10'], ['goal_id' => 1, 'day' => '2026-11-13'], ['goal_id' => 9, 'day' => '2026-11-10']];
        $this->assertSame(1, GoalCalc::stars([$g], $checks));
    }

    public function testBadgesOnlyForFinishedWeeksAndStreaks(): void
    {
        $g = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-10-26', 'end_date' => null];
        $checks = [];
        for ($d = new \DateTimeImmutable('2026-10-26'); $d <= new \DateTimeImmutable('2026-11-15'); $d = $d->modify('+1 day')) {
            $checks[] = ['goal_id' => 1, 'day' => $d->format('Y-m-d')];
        }
        $this->assertSame(21, GoalCalc::stars([$g], $checks));

        $b = GoalCalc::badges([$g], $checks, '2026-11-17');
        foreach (['primeira_estrela', 'estrelas_10', 'semana_completa', 'sequencia_3'] as $k) {
            $this->assertContains($k, $b);
        }
        $this->assertNotContains('sequencia_8', $b);
        $this->assertNotContains('estrelas_50', $b);

        $b2 = GoalCalc::badges([$g], $checks, '2026-11-15');
        $this->assertContains('semana_completa', $b2);
        $this->assertNotContains('sequencia_3', $b2);
    }

    public function testWeekWithoutApplicableGoalBreaksStreak(): void
    {
        $g1 = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-10-26', 'end_date' => '2026-11-01'];
        $g2 = ['id' => 2, 'times_per_week' => 7, 'start_date' => '2026-11-09', 'end_date' => null];
        $checks = [];
        for ($d = new \DateTimeImmutable('2026-10-26'); $d <= new \DateTimeImmutable('2026-11-15'); $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            if ($day >= '2026-11-02' && $day <= '2026-11-08') {
                continue;
            }
            $checks[] = ['goal_id' => $day <= '2026-11-01' ? 1 : 2, 'day' => $day];
        }
        $b = GoalCalc::badges([$g1, $g2], $checks, '2026-11-17');
        $this->assertContains('semana_completa', $b);
        $this->assertNotContains('sequencia_3', $b);
    }
}
