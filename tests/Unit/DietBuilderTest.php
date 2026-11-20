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
        gc_mem_caches();
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
        // A segunda-feira guardada tambem fica dentro da tolerancia (amostra: um nivel por versao e estacao).
        $b = self::builder();
        foreach ([['adulto', 1000], ['adulto', 3000], ['infantil', 1000], ['infantil', 2000]] as [$band, $kcal]) {
            foreach ([5, 20, 30, 45] as $w) {
                $monday = (new \DateTimeImmutable())->setISODate(2026, $w, 1)->format('Y-m-d');
                $t = $b->buildDay($band, $monday, $kcal)['totals']['kcal'];
                $this->assertLessThanOrEqual(DietBuilder::TOLERANCE, abs($t - $kcal) / $kcal, "$band $w $kcal = $t");
            }
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

    /** Blocos de cada refeicao, como o DietBuilder filtra (estacao e versao). */
    private static function pool(array $menu, string $key, string $band, string $season): array
    {
        $kid = $band === 'infantil';
        return array_values(array_filter($menu['meals'][$key], static fn(array $b): bool =>
            in_array($season, $b['seasons'] ?? $menu['seasons'], true)
            && !($kid && ($b['adult_only'] ?? false)) && !(!$kid && ($b['kid_only'] ?? false))));
    }

    /** Assinatura de uma refeicao (alimentos da opcao 1, na ordem). */
    private static function mealSig(array $meal): string
    {
        return (string) json_encode([$meal['note'], array_column($meal['options'][0]['items'], 'food_id')]);
    }

    private static function mealByName(array $day, string $name): ?array
    {
        foreach ($day['meals'] as $m) {
            if ($m['name'] === $name) {
                return $m;
            }
        }
        return null;
    }

    public function test_pool_sizes_per_band_and_season(): void
    {
        $menu = require APP_ROOT . '/data/dietas/cardapio.php';
        foreach (array_keys(DietBuilder::BANDS) as $band) {
            foreach ($menu['seasons'] as $season) {
                foreach (array_keys($menu['meals']) as $key) {
                    $n = count(self::pool($menu, $key, $band, $season));
                    $where = "$key/$band/$season = $n";
                    if ($key === 'almoco' || $key === 'jantar') {
                        $this->assertGreaterThanOrEqual(8, $n, $where);
                    } else {
                        $this->assertGreaterThanOrEqual(2, $n, $where);
                        $this->assertNotSame(0, $n % 7, $where);
                    }
                }
            }
        }
    }

    public function test_build_days_fourteen_within_tolerance(): void
    {
        $p = self::builder()->buildDays('adulto', 1800, '2026-11-23', 14);
        $this->assertSame('1800kcalSemana48', $p['code']);
        $this->assertSame('Plano alimentar – 23/11 a 06/12', $p['title']);
        $this->assertStringContainsString('ORIENTAÇÕES GERAIS', $p['guidance']);
        $this->assertSame(2, $p['structure']['v']);
        $this->assertSame('2026-11-23', $p['structure']['start']);
        $this->assertCount(14, $p['structure']['days']);
        $this->assertCount(14, $p['totals']);
        foreach ($p['totals'] as $d => $t) {
            $this->assertLessThanOrEqual(DietBuilder::TOLERANCE, abs($t['kcal'] - 1800) / 1800, "dia $d = {$t['kcal']}");
            $this->assertCount(6, $p['structure']['days'][$d]['meals']);
        }
    }

    public function test_no_lunch_or_dinner_repeat_in_7_days(): void
    {
        $b = self::builder();
        foreach (array_keys(DietBuilder::BANDS) as $band) {
            $sigs = ['Almoço' => [], 'Jantar' => []];
            for ($d = new \DateTimeImmutable('2026-03-23'); $d <= new \DateTimeImmutable('2026-06-21'); $d = $d->modify('+1 day')) {
                $day = $b->buildDay($band, $d->format('Y-m-d'), 1500);
                $this->assertSame('outono', $day['season']);
                foreach ($sigs as $name => $_) {
                    $sigs[$name][] = self::mealSig(self::mealByName($day, $name));
                }
            }
            foreach ($sigs as $name => $list) {
                for ($s = 0; $s + 7 <= count($list); $s++) {
                    $win = array_slice($list, $s, 7);
                    $this->assertCount(7, array_unique($win), "$band $name repete na janela que começa no dia $s");
                }
            }
        }
    }

    public function test_consecutive_days_differ(): void
    {
        $b = self::builder();
        foreach (array_keys(DietBuilder::BANDS) as $band) {
            foreach (['2026-01-05', '2026-03-23', '2026-06-22', '2026-09-21'] as $start) { // uma semana de cada estacao
                $p = $b->buildDays($band, 2000, $start, 14);
                for ($d = 0; $d + 1 < 14; $d++) {
                    foreach (['Café da manhã', 'Lanche da manhã', 'Lanche da tarde', 'Ceia'] as $name) {
                        $this->assertNotSame(
                            self::mealSig(self::mealByName($p['structure']['days'][$d], $name)),
                            self::mealSig(self::mealByName($p['structure']['days'][$d + 1], $name)),
                            "$band $start dia $d: $name igual ao dia seguinte",
                        );
                    }
                }
            }
        }
    }

    public function test_season_changes_on_the_right_day(): void
    {
        $menu = require APP_ROOT . '/data/dietas/cardapio.php';
        $p = self::builder()->buildDays('adulto', 2000, '2026-03-16', 14);
        $fruitIds = static function (array $day): array {
            $ids = [];
            foreach ($day['meals'] as $m) {
                foreach ($m['options'][0]['items'] as $it) {
                    $ids[] = $it['food_id'];
                }
            }
            return $ids;
        };
        $outono = array_column($menu['fruits']['outono'], 0);
        $verao = array_column($menu['fruits']['verao'], 0);
        $onlyVerao = array_diff($verao, $outono);
        // 2026-03-23 (dia 7) e a segunda-feira da semana 13: a partir dele so frutas de outono.
        for ($d = 7; $d < 14; $d++) {
            $this->assertSame([], array_values(array_intersect($fruitIds($p['structure']['days'][$d]), $onlyVerao)), "dia $d");
        }
        $seen = [];
        for ($d = 0; $d < 7; $d++) {
            $seen = array_merge($seen, array_intersect($fruitIds($p['structure']['days'][$d]), $onlyVerao));
        }
        $this->assertNotSame([], $seen, 'a semana 12 deveria ter frutas de verão');
    }

    public function test_same_date_same_menu(): void
    {
        $this->assertSame(
            self::builder()->buildDay('infantil', '2026-07-15', 1400),
            self::builder()->buildDay('infantil', '2026-07-15', 1400),
        );
        $b = self::builder();
        $this->assertNotSame($b->buildDay('adulto', '2026-07-15', 1800)['meals'], $b->buildDay('adulto', '2026-07-16', 1800)['meals']);
    }

    public function test_year_boundary_days(): void
    {
        $p = self::builder()->buildDays('infantil', 1500, '2026-12-28', 14);
        $this->assertSame('Plano alimentar – 28/12 a 10/01', $p['title']);
        $this->assertSame('1500kcalSemana52Infantil', $p['code']);
        $this->assertCount(14, $p['structure']['days']);
        foreach ($p['totals'] as $d => $t) {
            $this->assertLessThanOrEqual(DietBuilder::TOLERANCE, abs($t['kcal'] - 1500) / 1500, "dia $d");
        }
    }

    public function test_library_totals_are_7_day_average(): void
    {
        $b = self::builder();
        $d = $b->build('adulto', 30, 2000);
        $sum = 0.0;
        $monday = new \DateTimeImmutable('2026-07-20'); // segunda-feira da semana ISO 30
        for ($k = 0; $k < 7; $k++) {
            $sum += $b->buildDay('adulto', $monday->modify("+$k day")->format('Y-m-d'), 2000)['totals']['kcal'];
        }
        $this->assertEqualsWithDelta(round($sum / 7, 1), $d['totals']['kcal'], 0.05);
        $this->assertSame(1, $d['structure']['v']);
        $this->assertSame($b->buildDay('adulto', '2026-07-20', 2000)['meals'], $d['structure']['meals']);
    }

    public function test_from_database_matches_data_dir(): void
    {
        $db = new \App\Core\Database((array) \App\Core\Config::get('db'));
        $this->assertSame(
            self::builder()->buildDay('adulto', '2026-11-23', 1800),
            DietBuilder::fromDatabase($db)->buildDay('adulto', '2026-11-23', 1800),
        );
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
