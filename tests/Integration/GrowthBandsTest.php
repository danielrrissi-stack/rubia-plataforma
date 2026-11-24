<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Growth\Age;
use App\Growth\AdultAnthropometry;
use App\Growth\BmiSummary;
use App\Growth\GrowthBands;
use App\Growth\GrowthCalculator;
use App\Growth\Lms;
use App\Growth\WhoReferenceRepository;
use Tests\DbTestCase;

final class GrowthBandsTest extends DbTestCase
{
    private WhoReferenceRepository $ref;
    private GrowthCalculator $calc;
    private GrowthBands $bands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ref = new WhoReferenceRepository($this->db);
        $this->calc = new GrowthCalculator($this->ref);
        $this->bands = new GrowthBands($this->ref, $this->calc);
    }

    private function m(string $on, ?float $kg, ?float $cm, ?string $pos = 'em_pe'): array
    {
        return ['measured_on' => $on, 'weight_kg' => $kg, 'height_cm' => $cm, 'position' => $pos];
    }

    private function patient(string $birth, string $sex = 'F'): array
    {
        return ['birth_date' => $birth, 'sex' => $sex];
    }

    private function labels(array $chart): array
    {
        return array_column($chart['zones'], 'label');
    }

    public function test_age_key_boundaries(): void
    {
        $birth = '2010-01-01';
        $at = static fn (int $days): string => (new \DateTimeImmutable($birth))->modify('+' . $days . ' days')->format('Y-m-d');
        $this->assertSame('0-2', GrowthBands::ageKey($birth, $at(730)));
        $this->assertSame('2-5', GrowthBands::ageKey($birth, $at(731)));
        $this->assertSame('2-5', GrowthBands::ageKey($birth, $at(1826)));
        $this->assertSame('5-10', GrowthBands::ageKey($birth, $at(1827)));
        $this->assertSame('5-10', GrowthBands::ageKey($birth, $at(3652)));
        $this->assertSame('10-19', GrowthBands::ageKey($birth, $at(3653)));
        $this->assertSame('10-19', GrowthBands::ageKey($birth, $at(6970)));
        $this->assertNull(GrowthBands::ageKey($birth, $at(6971)));
        $this->assertNull(GrowthBands::ageKey($birth, '2009-12-31'));
    }

    public function test_school_age_has_three_charts_with_model_zones(): void
    {
        $birth = '2020-08-05';
        $j = $this->bands->data($this->patient($birth), [$this->m('2025-10-10', 20.5, 114.0)], '5-10');
        $this->assertSame(['min' => 60, 'max' => 120], $j['x']);
        [$h, $w, $b] = $j['charts'];
        $this->assertSame(['hfa', 'wfa', 'bfa'], array_column($j['charts'], 'key'));
        $this->assertSame(['Muito baixo', 'Baixo', 'Adequado'], $this->labels($h));
        $this->assertSame(['Muito baixo', 'Baixo', 'Adequado', 'Elevado'], $this->labels($w));
        $this->assertSame(['Magreza acentuada', 'Magreza', 'Eutrofia', 'Sobrepeso', 'Obesidade', 'Obesidade grave'], $this->labels($b));
        $this->assertSame(['grave', 'alerta', 'ok', 'atencao', 'alerta', 'grave'], array_column($b['zones'], 'tone'));
        foreach ($j['charts'] as $c) {
            $this->assertCount(count($c['zones']) - 1, $c['lines']);
            foreach ($c['lines'] as $line) {
                $this->assertCount(61, $line);
                $this->assertGreaterThan($c['y']['min'], min(array_column($line, 1)));
                $this->assertLessThan($c['y']['max'], max(array_column($line, 1)));
            }
        }
        $this->assertSame(114.0, $h['points'][0]['y']);
        $this->assertSame(20.5, $w['points'][0]['y']);
        $this->assertSame(15.8, $b['points'][0]['y']);
        $this->assertSame('10/10/2025', $b['points'][0]['date']);
        $this->assertFalse($b['points'][0]['bad']);
    }

    public function test_lines_are_the_who_cuts(): void
    {
        $j = $this->bands->data($this->patient('2018-01-01', 'M'), [], '5-10');
        $lms = $this->ref->lms('bfa', 'M', 84.0, 'who2007');
        foreach ([-3, -2, 1, 2, 3] as $i => $z) {
            $point = $j['charts'][2]['lines'][$i][24];
            $this->assertSame(84.0, $point[0]);
            $this->assertEqualsWithDelta(Lms::valueAt($z, $lms['l'], $lms['m'], $lms['s']), $point[1], 0.006);
        }
    }

    public function test_every_age_band_has_complete_lines_for_both_sexes(): void
    {
        foreach (['M', 'F'] as $sex) {
            foreach (GrowthBands::AGES as $key => [, $from, $to]) {
                $j = $this->bands->data($this->patient('2015-01-01', $sex), [], $key);
                $this->assertSame($key === '10-19' ? ['hfa', 'bfa'] : ['hfa', 'wfa', 'bfa'], array_column($j['charts'], 'key'));
                foreach ($j['charts'] as $c) {
                    foreach ($c['lines'] as $line) {
                        $this->assertSame([(float) $from, (float) $to], [$line[0][0], $line[count($line) - 1][0]], "$sex $key");
                        $this->assertCount($to - $from + 1, $line);
                    }
                }
            }
        }
    }

    public function test_under_five_uses_who2006_names(): void
    {
        $j = $this->bands->data($this->patient('2022-01-01'), [], '2-5');
        $this->assertSame(['Magreza acentuada', 'Magreza', 'Eutrofia', 'Risco de sobrepeso', 'Sobrepeso', 'Obesidade'], $this->labels($j['charts'][2]));
    }

    public function test_only_points_of_the_band_and_implausible_does_not_stretch_the_axis(): void
    {
        $birth = '2016-01-01';
        $all = [$this->m('2019-01-01', 14.0, 95.0), $this->m('2024-01-01', 26.0, 128.0), $this->m('2024-02-01', 260.0, 128.5)];
        $j = $this->bands->data($this->patient($birth), $all, '5-10');
        $w = $j['charts'][1];
        $this->assertCount(2, $w['points']);
        $this->assertTrue($w['points'][1]['bad']);
        $this->assertLessThan(100, $w['y']['max']);
        $this->assertSame(['2-5' => '2 a 5 anos', '5-10' => '5 a 10 anos', '10-19' => '10 a 19 anos'], GrowthBands::available($birth, '2026-11-23', $all));
        $this->assertSame(['10-19' => '10 a 19 anos'], GrowthBands::available($birth, '2026-11-23', []));
    }

    public function test_summary_limits_are_the_eutrophic_limits(): void
    {
        foreach ([['2012-09-01', '2026-11-01', 168.0], ['2019-03-10', '2026-11-01', 124.0], ['2023-05-01', '2026-11-01', 98.0]] as [$birth, $on, $cm]) {
            foreach (['F', 'M'] as $sex) {
                $p = $this->patient($birth, $sex);
                $s = BmiSummary::make($p, [$this->m($on, 40.0, $cm)], $this->calc);
                $status = fn (float $kg): string => $this->calc->indicators($sex, $birth, $on, $kg, $cm, 'em_pe', null)['bfa']['status'];
                $this->assertSame('Eutrofia', $status($s['min']));
                $this->assertSame('Eutrofia', $status($s['max']));
                $this->assertSame('Magreza', $status($s['min'] - 0.5));
                $this->assertNotSame('Eutrofia', $status($s['max'] + 0.5));
            }
        }
    }

    public function test_summary_of_the_example_girl(): void
    {
        // menina de 14 anos, 168 cm e 94,2 kg
        $s = BmiSummary::make($this->patient('2012-11-01'), [$this->m('2026-11-01', 94.2, 168.0)], $this->calc);
        $this->assertSame(33.4, $s['bmi']);
        $this->assertSame('Obesidade', $s['status']);
        $this->assertSame('alerta', $s['tone']);
        $this->assertEqualsWithDelta(43.7, $s['min'], 0.15);
        $this->assertEqualsWithDelta(64.1, $s['max'], 0.15);
    }

    public function test_abran_range_is_only_a_comparison(): void
    {
        $girl = BmiSummary::make($this->patient('2012-11-01'), [$this->m('2026-11-01', 94.2, 168.0)], $this->calc);
        $this->assertSame(['bmiMin' => 19.3, 'bmiMax' => 23.8, 'min' => 54.5, 'max' => 67.1], $girl['abran']);
        // abaixo do minimo da ABRAN, mas Eutrofia pela OMS: a categoria nao muda
        $thin = BmiSummary::make($this->patient('2012-11-01'), [$this->m('2026-11-01', 50.0, 168.0)], $this->calc);
        $this->assertSame(['Eutrofia', 'ok'], [$thin['status'], $thin['tone']]);
        // fora da tabela: 5 e 16 anos, adulto e meninos de 8 anos (valor da pagina a conferir)
        foreach ([['2021-06-01', 'F'], ['2010-06-01', 'M'], ['1990-01-01', 'F'], ['2018-06-01', 'M']] as [$birth, $sex]) {
            $this->assertNull(BmiSummary::make($this->patient($birth, $sex), [$this->m('2026-11-01', 30.0, 125.0)], $this->calc)['abran'], $birth);
        }
    }

    public function test_summary_uses_last_complete_measure_and_handles_none(): void
    {
        $p = $this->patient('2016-01-01');
        $this->assertNull(BmiSummary::make($p, [], $this->calc));
        $this->assertNull(BmiSummary::make($p, [$this->m('2026-01-01', 30.0, null)], $this->calc));
        $s = BmiSummary::make($p, [$this->m('2025-01-01', 28.0, 130.0), $this->m('2026-01-01', 31.0, null)], $this->calc);
        $this->assertSame('2025-01-01', $s['date']);
        $this->assertSame(28.0, $s['weight']);
    }

    public function test_summary_corrects_height_by_position(): void
    {
        $p = $this->patient('2025-11-01');
        $on = '2026-11-01';
        $lying = BmiSummary::make($p, [$this->m($on, 9.0, 74.7, 'deitado')], $this->calc);
        $standing = BmiSummary::make($p, [$this->m($on, 9.0, 74.0, 'em_pe')], $this->calc);
        $this->assertSame($lying['max'], $standing['max']);
    }

    public function test_adult_summary(): void
    {
        $p = $this->patient('1990-01-01');
        foreach ([[50.0, 'alerta'], [60.0, 'ok'], [75.0, 'atencao'], [85.0, 'alerta'], [100.0, 'grave']] as [$kg, $tone]) {
            $s = BmiSummary::make($p, [$this->m('2026-11-01', $kg, 168.0)], $this->calc);
            $this->assertTrue($s['adult']);
            $this->assertSame($tone, $s['tone']);
        }
        $this->assertSame('Eutrofia', AdultAnthropometry::bmiClass(AdultAnthropometry::bmi($s['min'], 168.0)));
        $this->assertSame('Eutrofia', AdultAnthropometry::bmiClass(AdultAnthropometry::bmi($s['max'], 168.0)));
        $this->assertSame([52.3, 70.2], [$s['min'], $s['max']]);
    }

    public function test_bmi_tone(): void
    {
        $this->assertSame(
            ['grave', 'alerta', 'ok', 'ok', 'atencao', 'alerta', 'grave'],
            array_map([GrowthBands::class, 'bmiTone'], [-3.01, -2.01, -2.0, 1.0, 1.01, 2.01, 3.01])
        );
    }
}
