<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Nutrition\MealPlanCalculator;
use PHPUnit\Framework\TestCase;

final class MealPlanCalculatorTest extends TestCase
{
    private function pao(float $grams): array
    {
        return ['grams' => $grams, 'per100' => ['kcal' => 300.0, 'protein' => 8.0, 'carb' => 58.6, 'fat' => 3.1, 'fiber' => 2.3]];
    }

    public function test_item_scales_per_100g(): void
    {
        $r = MealPlanCalculator::item($this->pao(50));
        $this->assertEqualsWithDelta(150.0, $r['kcal'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $r['protein'], 1e-9);
        $this->assertEqualsWithDelta(29.3, $r['carb'], 1e-9);
        $this->assertEqualsWithDelta(1.55, $r['fat'], 1e-9);
        $this->assertEqualsWithDelta(1.15, $r['fiber'], 1e-9);
        $this->assertFalse($r['missing']);
    }

    public function test_null_nutrient_counts_zero_and_flags_missing(): void
    {
        $r = MealPlanCalculator::item(['grams' => 10, 'per100' => ['kcal' => 884.0, 'protein' => null, 'carb' => null, 'fat' => 100.0, 'fiber' => null]]);
        $this->assertEqualsWithDelta(88.4, $r['kcal'], 1e-9);
        $this->assertSame(0.0, $r['protein']);
        $this->assertTrue($r['missing']);
    }

    public function test_option_sums_items_without_substitutes(): void
    {
        $o = MealPlanCalculator::option(['items' => [$this->pao(50) + ['subs' => [$this->pao(500)]], $this->pao(100)]]);
        $this->assertEqualsWithDelta(450.0, $o['kcal'], 1e-9);
        $this->assertArrayNotHasKey('missing', $o);
    }

    public function test_day_uses_option_one_and_ignores_substitutes(): void
    {
        $sub = $this->pao(500);
        $structure = ['meals' => [
            ['options' => [['items' => [$this->pao(50) + ['subs' => [$sub]]]], ['items' => [$this->pao(100)]]]],
            ['options' => [['items' => [$this->pao(50)]]]],
        ]];
        $this->assertEqualsWithDelta(300.0, MealPlanCalculator::day($structure)['kcal'], 1e-9);
    }

    public function test_day_tolerates_meal_without_options(): void
    {
        $this->assertSame(0.0, MealPlanCalculator::day(['meals' => [['options' => []]]])['kcal']);
    }

    public function test_macro_percents(): void
    {
        $p = MealPlanCalculator::macroPercents(['kcal' => 150, 'protein' => 4.0, 'carb' => 29.3, 'fat' => 1.55, 'fiber' => 1.15]);
        $this->assertSame(['protein' => 10.9, 'carb' => 79.6, 'fat' => 9.5], $p);
        $this->assertSame(['protein' => 0.0, 'carb' => 0.0, 'fat' => 0.0], MealPlanCalculator::macroPercents(['kcal' => 0, 'protein' => 0, 'carb' => 0, 'fat' => 0, 'fiber' => 0]));
    }

    public function test_plan_average(): void
    {
        $mk = fn(float $kcal): array => ['meals' => [['options' => [['items' => [['grams' => 100.0, 'per100' => ['kcal' => $kcal, 'protein' => 0, 'carb' => 0, 'fat' => 0, 'fiber' => 0]]]]]]]];
        $r = MealPlanCalculator::plan(['v' => 2, 'start' => '2026-11-23', 'days' => [$mk(1000.0), $mk(1200.0)]]);
        $this->assertEqualsWithDelta(1100.0, $r['average']['kcal'], 1e-9);
        $this->assertEqualsWithDelta(1200.0, $r['days'][1]['kcal'], 1e-9);
        $v1 = MealPlanCalculator::plan($mk(500.0));
        $this->assertCount(1, $v1['days']);
        $this->assertEqualsWithDelta(500.0, $v1['average']['kcal'], 1e-9);
    }
}
