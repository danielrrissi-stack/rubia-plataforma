<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Growth\BloodPressure;
use App\Growth\BpReferenceImporter;
use App\Growth\BpReferenceRepository;
use Tests\DbTestCase;

final class BpReferenceTest extends DbTestCase
{
    private BpReferenceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BpReferenceRepository($this->db);
    }

    public function testAllRowsImported(): void
    {
        $this->assertSame(714, (int) $this->db->one('SELECT COUNT(*) AS n FROM bp_reference')['n']);
        $this->assertSame(
            ['5', '10', '25', '50', '75', '90', '95'],
            array_map('strval', array_column($this->db->all('SELECT DISTINCT height_pct FROM bp_reference ORDER BY height_pct'), 'height_pct')),
        );
    }

    public function testThresholdsMatchPublishedTables(): void
    {
        // Tabela 4 (meninos), 8 anos, estatura P50: P90 110/71, P95 114/74.
        $this->assertSame(['sys90' => 110, 'sys95' => 114, 'dia90' => 71, 'dia95' => 74], $this->repo->thresholds('M', 8, 50));
        // Tabela 5 (meninas), 10 anos, estatura P95: P90 116/73, P95 120/76.
        $this->assertSame(['sys90' => 116, 'sys95' => 120, 'dia90' => 73, 'dia95' => 76], $this->repo->thresholds('F', 10, 95));
    }

    public function testSys95AboveSys90Everywhere(): void
    {
        foreach (['M', 'F'] as $sex) {
            for ($age = 1; $age <= 17; $age++) {
                foreach (BloodPressure::HEIGHT_PCTS as $h) {
                    $t = $this->repo->thresholds($sex, $age, $h);
                    $this->assertGreaterThan($t['sys90'], $t['sys95'], "$sex $age $h");
                    $this->assertGreaterThanOrEqual($t['dia90'], $t['dia95'], "$sex $age $h");
                }
            }
        }
    }

    public function testMissingRowThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->repo->thresholds('M', 18, 50);
    }

    public function testCsvMatchesRawSourceFiles(): void
    {
        $fromDat = BpReferenceImporter::parseDat(APP_ROOT . '/data/bp/flynn2017_bp_boys.dat', 1);
        $fromDat = array_merge($fromDat, BpReferenceImporter::parseDat(APP_ROOT . '/data/bp/flynn2017_bp_girls.dat', 2));
        $csv = BpReferenceImporter::parseCsv(APP_ROOT . '/data/bp/aap2017.csv');
        $this->assertCount(714, $csv);
        $this->assertEqualsCanonicalizing($fromDat, $csv);
    }

    public function testClassifyChildWithRealTable(): void
    {
        // Menino de 8 anos, estatura P50 (limiares 110/71 e 114/74).
        $this->assertSame('PA elevada', BloodPressure::classifyChild('M', 8, 48.0, 110, 60, $this->repo));
        $this->assertSame('HA estágio 1', BloodPressure::classifyChild('M', 8, 48.0, 100, 74, $this->repo));
    }
}
