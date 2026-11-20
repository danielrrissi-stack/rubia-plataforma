<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Nutrition\PofMeasureImporter;
use PHPUnit\Framework\TestCase;

final class PofMeasureImporterTest extends TestCase
{
    private function rows(): array
    {
        return PofMeasureImporter::parseCsv(APP_ROOT . '/data/pof/medidas.csv');
    }

    public function test_parses_real_file(): void
    {
        $rows = $this->rows();
        $this->assertGreaterThan(100, count($rows));
        $this->assertGreaterThanOrEqual(25, count(array_unique(array_column($rows, 'taco_code'))));
        foreach ($rows as $r) {
            $this->assertGreaterThan(0, $r['grams'], $r['name']);
            $this->assertGreaterThan(0, $r['page']);
            $this->assertNotSame('', $r['name']);
        }
    }

    public function test_every_taco_code_exists_in_taco(): void
    {
        $taco = [];
        $h = fopen(APP_ROOT . '/data/taco/food.csv', 'rb');
        fgetcsv($h, 0, ',', '"', '');
        while (($f = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $taco[(int) $f[0]] = true;
        }
        fclose($h);
        foreach ($this->rows() as $r) {
            $this->assertArrayHasKey($r['taco_code'], $taco);
        }
    }

    public function test_seed_ids_are_sequential_per_food_and_upsert(): void
    {
        $sql = PofMeasureImporter::seedSql($this->rows());
        $this->assertStringNotContainsString('REPLACE', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString("(301,3,'pof','colher de sopa','colheres de sopa',25.0)", $sql);
        $this->assertStringContainsString("(5301,53,'pof','unidade','unidades',50.0)", $sql);
        $this->assertStringContainsString("(5302,53,'pof','metade','metades',25.0)", $sql);
        $this->assertStringNotContainsString('active=', str_replace(' ', '', $sql));
    }

    public function test_rejects_invalid_row(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'pof');
        file_put_contents($f, "taco_code,medida,medida_plural,gramas,pagina_pof\n3,colher,colheres,0,35\n");
        $this->expectException(\RuntimeException::class);
        try {
            PofMeasureImporter::parseCsv($f);
        } finally {
            unlink($f);
        }
    }
}
