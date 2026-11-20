<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Diets\DietBuilder;
use App\Diets\DietSeed;
use PHPUnit\Framework\TestCase;

final class DietBuilderTest extends TestCase
{
    private static ?array $all = null;

    private static function builder(): DietBuilder
    {
        return DietBuilder::fromDataDir(APP_ROOT);
    }

    private static function all(): array
    {
        return self::$all ??= self::builder()->all();
    }

    /** As 1.664 dietas ocupam muita memoria: libera antes das proximas classes. */
    public static function tearDownAfterClass(): void
    {
        self::$all = null;
        gc_collect_cycles();
    }

    public function test_counts_names_and_tolerance(): void
    {
        $all = self::all();
        $this->assertCount(52 * 11 + 52 * 21, $all);
        $codes = array_column($all, 'code');
        $this->assertCount(count($all), array_unique($codes));
        $this->assertContains('1500kcalSemana5', $codes);
        $this->assertContains('1500kcalSemana5Infantil', $codes);
        $this->assertNotContains('2100kcalSemana1Infantil', $codes);
        foreach ($all as $d) {
            $err = abs($d['totals']['kcal'] - $d['kcal_target']) / $d['kcal_target'];
            $this->assertLessThanOrEqual(DietBuilder::TOLERANCE, $err, $d['code'] . ' = ' . $d['totals']['kcal']);
        }
    }

    public function test_seasons_and_weeks(): void
    {
        $this->assertSame('verao', DietBuilder::season(1));
        $this->assertSame('verao', DietBuilder::season(12));
        $this->assertSame('outono', DietBuilder::season(13));
        $this->assertSame('inverno', DietBuilder::season(30));
        $this->assertSame('primavera', DietBuilder::season(45));
        $this->assertSame('verao', DietBuilder::season(52));
        $this->assertSame(52, DietBuilder::weekOf('2026-12-31')); // semana ISO 53 vira 52
        $this->assertSame(1, DietBuilder::weekOf('2026-01-01'));
        $this->assertNull(DietBuilder::bandForAge(2));
        $this->assertSame('infantil', DietBuilder::bandForAge(3));
        $this->assertSame('infantil', DietBuilder::bandForAge(10));
        $this->assertSame('adulto', DietBuilder::bandForAge(11));
    }

    public function test_kid_version_has_no_coffee_and_text_has_no_kcal(): void
    {
        foreach (self::all() as $d) {
            $this->assertStringNotContainsStringIgnoringCase('kcal', $d['title'] . $d['guidance'] . json_encode($d['structure']));
            if ($d['band'] !== 'infantil') {
                continue;
            }
            foreach ($d['structure']['meals'] as $m) {
                foreach ($m['options'][0]['items'] as $it) {
                    $this->assertNotSame(471, $it['food_id'], $d['code'] . ' tem café');
                }
            }
        }
    }

    public function test_ceia_threshold_and_meal_shape(): void
    {
        $b = self::builder();
        $this->assertCount(5, $b->build('adulto', 10, 1700)['structure']['meals']);
        $this->assertCount(6, $b->build('adulto', 10, 1800)['structure']['meals']);
        $this->assertCount(5, $b->build('infantil', 10, 1200)['structure']['meals']);
        $this->assertCount(6, $b->build('infantil', 10, 1300)['structure']['meals']);
        $d = $b->build('adulto', 30, 2000);
        $this->assertSame('Plano alimentar – semana 30 (inverno)', $d['title']);
        $this->assertSame(['Café da manhã', '07:00'], [$d['structure']['meals'][0]['name'], $d['structure']['meals'][0]['time']]);
        $this->assertStringNotContainsString('{SALADA}', json_encode($d['structure'], JSON_UNESCAPED_UNICODE));
    }

    public function test_weeks_differ_within_a_season(): void
    {
        $b = self::builder();
        $sig = static fn(array $d): string => (string) json_encode(array_map(
            static fn(array $m): array => array_column($m['options'][0]['items'], 'food_id'),
            $d['structure']['meals'],
        ));
        $seen = [];
        for ($w = 26; $w <= 38; $w++) {
            $seen[] = $sig($b->build('adulto', $w, 1800));
        }
        $this->assertGreaterThanOrEqual(10, count(array_unique($seen)));
    }

    public function test_seed_file_is_up_to_date(): void
    {
        $file = APP_ROOT . '/database/seeds/food_4_diet_library.sql';
        $this->assertTrue(
            DietSeed::sql(self::all()) === str_replace("\r\n", "\n", (string) file_get_contents($file)),
            'Seed desatualizado: rode php tools/build_diets.php --sql-only',
        );
    }
}
