<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Growth\Age;
use App\Growth\GrowthCalculator;
use App\Growth\Lms;
use App\Growth\WhoReferenceRepository;
use Tests\DbTestCase;

final class GrowthCalculatorTest extends DbTestCase
{
    private WhoReferenceRepository $ref;
    private GrowthCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ref = new WhoReferenceRepository($this->db);
        $this->calc = new GrowthCalculator($this->ref);
    }

    public function testBabyAtMedianHasZeroZAndP50(): void
    {
        $days = Age::days('2020-01-01', '2020-07-01');
        $wfa = $this->ref->lms('wfa', 'M', $days, 'who2006');
        $hfa = $this->ref->lms('hfa', 'M', $days, 'who2006');
        $hc = $this->ref->lms('hcfa', 'M', $days, 'who2006');
        $r = $this->calc->indicators('M', '2020-01-01', '2020-07-01', $wfa['m'], $hfa['m'], 'deitado', $hc['m']);

        foreach (['wfa', 'hfa', 'hcfa'] as $k) {
            $this->assertEqualsWithDelta(0.0, $r[$k]['z'], 0.005, $k);
            $this->assertSame(50.0, $r[$k]['percentile'], $k);
            $this->assertSame('who2006', $r[$k]['source']);
            $this->assertFalse($r[$k]['implausible']);
        }
        $this->assertSame('Peso adequado para a idade', $r['wfa']['status']);
        $this->assertSame('Estatura adequada para a idade', $r['hfa']['status']);
        $this->assertSame('Adequado', $r['hcfa']['status']);
        $this->assertNotNull($r['bfa']);
        $this->assertNotNull($r['wflh']);
    }

    public function testSchoolChildAtMedianUsesWho2007ByMonth(): void
    {
        $months = Age::months('2016-01-01', '2024-01-01');
        $hfa = $this->ref->lms('hfa', 'M', $months, 'who2007');
        $r = $this->calc->indicators('M', '2016-01-01', '2024-01-01', null, $hfa['m'], 'em_pe', null);
        $this->assertEqualsWithDelta(0.0, $r['hfa']['z'], 0.005);
        $this->assertSame(50.0, $r['hfa']['percentile']);
        $this->assertSame('who2007', $r['hfa']['source']);
        $this->assertNull($r['wfa']);
        $this->assertNull($r['bfa']);
        $this->assertNull($r['wflh']);
        $this->assertNull($r['hcfa']);
    }

    public function testEightYearOldWithSevereObesityUsesRestrictedZ(): void
    {
        $months = Age::months('2016-01-01', '2024-01-01');
        $lms = $this->ref->lms('bfa', 'M', $months, 'who2007');
        $bmi = 45 / (1.28 ** 2);
        $r = $this->calc->indicators('M', '2016-01-01', '2024-01-01', 45.0, 128.0, 'em_pe', null);

        $this->assertSame('Obesidade grave', $r['bfa']['status']);
        $this->assertEqualsWithDelta($bmi, $r['bfa']['value'], 1e-9);
        $restricted = Lms::zRestricted($bmi, $lms['l'], $lms['m'], $lms['s']);
        $plain = Lms::z($bmi, $lms['l'], $lms['m'], $lms['s']);
        $this->assertGreaterThan(3, $restricted);
        $this->assertEqualsWithDelta($restricted, $r['bfa']['z'], 0.005);
        $this->assertGreaterThan(0.05, abs($plain - $r['bfa']['z']));
        $this->assertSame('who2007', $r['bfa']['source']);
        $this->assertSame('Peso elevado para a idade', $r['wfa']['status']);
    }

    public function testElevenYearOldHasNoWeightForAge(): void
    {
        $r = $this->calc->indicators('M', '2013-01-01', '2024-01-15', 40.0, 145.0, 'em_pe', null);
        $this->assertNull($r['wfa']);
        $this->assertNotNull($r['bfa']);
        $this->assertNotNull($r['hfa']);
    }

    public function testThreeYearOldMeasuredLyingHasHeightCorrected(): void
    {
        $r = $this->calc->indicators('F', '2021-01-01', '2024-01-01', 14.0, 95.0, 'deitado', null);
        $this->assertEqualsWithDelta(94.3, $r['hfa']['value'], 1e-9);
        $wfh = $this->ref->lms('wfh', 'F', 94.3, 'who2006');
        $this->assertEqualsWithDelta(Lms::z(14.0, $wfh['l'], $wfh['m'], $wfh['s']), $r['wflh']['z'], 0.005);
        $this->assertEqualsWithDelta(14.0, $r['wflh']['value'], 1e-9);
        $this->assertEqualsWithDelta(14.0 / (0.943 ** 2), $r['bfa']['value'], 1e-9);
        $this->assertNull($r['hcfa']);
    }

    public function testBabyMeasuredStandingGetsPlus07(): void
    {
        $r = $this->calc->indicators('M', '2023-01-01', '2023-07-01', 7.5, 66.0, 'em_pe', null);
        $this->assertEqualsWithDelta(66.7, $r['hfa']['value'], 1e-9);
        $wfl = $this->ref->lms('wfl', 'M', 66.7, 'who2006');
        $this->assertEqualsWithDelta(Lms::z(7.5, $wfl['l'], $wfl['m'], $wfl['s']), $r['wflh']['z'], 0.005);
    }

    public function testWeightForLengthOutOfRangeIsNull(): void
    {
        $r = $this->calc->indicators('M', '2023-01-01', '2024-01-01', 5.0, 40.0, 'deitado', 40.0);
        $this->assertNull($r['wflh']);
        $this->assertNotNull($r['wfa']);
    }

    public function testBmiZOfSixIsImplausible(): void
    {
        $months = Age::months('2016-01-01', '2024-01-01');
        $lms = $this->ref->lms('bfa', 'M', $months, 'who2007');
        $sd3 = Lms::valueAt(3, $lms['l'], $lms['m'], $lms['s']);
        $sd2 = Lms::valueAt(2, $lms['l'], $lms['m'], $lms['s']);
        $bmi = $sd3 + 3 * ($sd3 - $sd2);
        $r = $this->calc->indicators('M', '2016-01-01', '2024-01-01', $bmi * 1.3 ** 2, 130.0, 'em_pe', null);
        $this->assertEqualsWithDelta(6.0, $r['bfa']['z'], 0.005);
        $this->assertTrue($r['bfa']['implausible']);
    }

    public function testFiveYearBoundarySwitchesSource(): void
    {
        $birth = '2019-01-01';
        $on1826 = (new \DateTimeImmutable($birth, new \DateTimeZone('UTC')))->modify('+1826 days')->format('Y-m-d');
        $on1827 = (new \DateTimeImmutable($birth, new \DateTimeZone('UTC')))->modify('+1827 days')->format('Y-m-d');
        $a = $this->calc->indicators('F', $birth, $on1826, 18.0, 108.0, 'em_pe', 50.0);
        $b = $this->calc->indicators('F', $birth, $on1827, 18.0, 108.0, 'em_pe', 50.0);
        $this->assertSame('who2006', $a['bfa']['source']);
        $this->assertNotNull($a['hcfa']);
        $this->assertNotNull($a['wflh']);
        $this->assertSame('who2007', $b['bfa']['source']);
        $this->assertNull($b['hcfa']);
        $this->assertNull($b['wflh']);
    }

    public function testAdultIsAllNull(): void
    {
        $birth = '2005-01-01';
        $on = (new \DateTimeImmutable($birth, new \DateTimeZone('UTC')))->modify('+' . (int) ceil(230 * Age::DAYS_PER_MONTH) . ' days')->format('Y-m-d');
        $r = $this->calc->indicators('F', $birth, $on, 60.0, 165.0, 'em_pe', 55.0);
        $this->assertSame(['bfa' => null, 'wfa' => null, 'hfa' => null, 'wflh' => null, 'hcfa' => null], $r);
    }
}
