<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Nutrition\TacoImporter;
use PHPUnit\Framework\TestCase;

final class TacoImporterTest extends TestCase
{
    private function rows(): array
    {
        return TacoImporter::parse(APP_ROOT . '/data/taco/food.csv', APP_ROOT . '/data/taco/nutrients.csv', APP_ROOT . '/data/taco/categories.csv');
    }

    public function test_parses_real_files(): void
    {
        $rows = $this->rows();
        $this->assertCount(597, $rows);
        $by = array_column($rows, null, 'taco_code');
        $this->assertSame('Pão, trigo, francês', $by[53]['name']);
        $this->assertSame('Cereais e derivados', $by[53]['group_name']);
        $this->assertSame([300.0, 8.0, 58.6, 3.1, 2.3], [$by[53]['kcal'], $by[53]['protein_g'], $by[53]['carb_g'], $by[53]['fat_g'], $by[53]['fiber_g']]);
        $this->assertSame([76.0, 4.8, 13.6, 0.5, 8.5], [$by[561]['kcal'], $by[561]['protein_g'], $by[561]['carb_g'], $by[561]['fat_g'], $by[561]['fiber_g']]);
        $this->assertNull($by[259]['protein_g']);   // NA na TACO (óleo): fica nulo, não zero
        $this->assertSame(884.0, $by[259]['kcal']);
        $this->assertNull($by[458]['kcal']);        // sem override: leite fluido vem vazio da TACO
    }

    public function test_overrides_fill_fluid_milk(): void
    {
        $d = APP_ROOT . '/data/taco';
        $by = array_column(TacoImporter::parse($d . '/food.csv', $d . '/nutrients.csv', $d . '/categories.csv', $d . '/overrides.csv'), null, 'taco_code');
        $this->assertSame([60.0, 3.2, 4.5, 3.3, 0.0], [$by[458]['kcal'], $by[458]['protein_g'], $by[458]['carb_g'], $by[458]['fat_g'], $by[458]['fiber_g']]);
        $this->assertSame([34.2, 3.4, 5.0, 0.1, 0.0], [$by[457]['kcal'], $by[457]['protein_g'], $by[457]['carb_g'], $by[457]['fat_g'], $by[457]['fiber_g']]);
        $this->assertSame(300.0, $by[53]['kcal']);  // demais alimentos intactos
    }

    public function test_seed_sql_is_upsert_in_batches(): void
    {
        $sql = TacoImporter::seedSql($this->rows());
        $this->assertStringNotContainsString('REPLACE', $sql);
        $this->assertSame(6, substr_count($sql, 'ON DUPLICATE KEY UPDATE'));   // 597 linhas / lotes de 100
        $this->assertStringContainsString("(53,'taco',53,'Pão, trigo, francês','pao, trigo, frances','Cereais e derivados',300.0,8.0,58.6,3.1,2.3)", $sql);
        $this->assertStringNotContainsString('active=', str_replace(' ', '', $sql));
    }
}
