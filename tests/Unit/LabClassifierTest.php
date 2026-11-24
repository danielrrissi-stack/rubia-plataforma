<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Labs\LabClassifier;
use PHPUnit\Framework\TestCase;

final class LabClassifierTest extends TestCase
{
    private const LDL_CHILD = ['sex' => null, 'age_min_months' => 24, 'age_max_months' => 239, 'borderline_high' => 110.0, 'abnormal_high' => 130.0, 'borderline_low' => null, 'abnormal_low' => null];
    private const HDL = ['sex' => null, 'age_min_months' => 24, 'age_max_months' => null, 'borderline_high' => null, 'abnormal_high' => null, 'borderline_low' => 45.0, 'abnormal_low' => 39.0];

    public function testCutBelongsToWorseCategory(): void
    {
        $this->assertSame('normal', LabClassifier::classify(109.0, self::LDL_CHILD));
        $this->assertSame('limitrofe_alto', LabClassifier::classify(110.0, self::LDL_CHILD));
        $this->assertSame('limitrofe_alto', LabClassifier::classify(129.9, self::LDL_CHILD));
        $this->assertSame('alterado_alto', LabClassifier::classify(130.0, self::LDL_CHILD));
    }

    public function testLowSideOnly(): void
    {
        $this->assertSame('normal', LabClassifier::classify(46.0, self::HDL));
        $this->assertSame('limitrofe_baixo', LabClassifier::classify(45.0, self::HDL));
        $this->assertSame('limitrofe_baixo', LabClassifier::classify(40.0, self::HDL));
        $this->assertSame('alterado_baixo', LabClassifier::classify(39.0, self::HDL));
    }

    public function testPickRangeByAgeAndSex(): void
    {
        $tg = [
            ['sex' => null, 'age_min_months' => 24, 'age_max_months' => 119, 'borderline_high' => 75.0, 'abnormal_high' => 100.0, 'borderline_low' => null, 'abnormal_low' => null],
            ['sex' => null, 'age_min_months' => 120, 'age_max_months' => 239, 'borderline_high' => 90.0, 'abnormal_high' => 130.0, 'borderline_low' => null, 'abnormal_low' => null],
        ];
        $this->assertSame(75.0, LabClassifier::pickRange($tg, 'F', 119)['borderline_high']);
        $this->assertSame(90.0, LabClassifier::pickRange($tg, 'F', 120)['borderline_high']);
        $this->assertNull(LabClassifier::pickRange($tg, 'F', 23));
        $this->assertNull(LabClassifier::pickRange($tg, 'M', 240));
        $alt = [['sex' => 'M', 'age_min_months' => 24, 'age_max_months' => 239, 'borderline_high' => null, 'abnormal_high' => 27.0, 'borderline_low' => null, 'abnormal_low' => null],
                ['sex' => 'F', 'age_min_months' => 24, 'age_max_months' => 239, 'borderline_high' => null, 'abnormal_high' => 23.0, 'borderline_low' => null, 'abnormal_low' => null]];
        $this->assertSame(23.0, LabClassifier::pickRange($alt, 'F', 100)['abnormal_high']);
    }

    public function testHomaIr(): void
    {
        $this->assertSame(3.11, LabClassifier::homaIr(90.0, 14.0));
        $this->assertNull(LabClassifier::homaIr(90.0, null));
        $this->assertNull(LabClassifier::homaIr(0.0, 14.0));
    }

    public function testAgeMonthsAndPhrases(): void
    {
        $this->assertSame(119, LabClassifier::ageMonths('2016-05-10', '2026-05-09'));
        $this->assertSame(120, LabClassifier::ageMonths('2016-05-10', '2026-05-10'));
        $this->assertSame('um pouco acima do esperado', LabClassifier::familyPhrase('limitrofe_alto'));
        $this->assertSame('dentro do esperado', LabClassifier::familyPhrase('normal'));
        $this->assertNull(LabClassifier::familyPhrase(null));
        $this->assertSame('▲', LabClassifier::MARK['alterado_alto']);
        $this->assertSame('limítrofe', LabClassifier::LABEL['limitrofe_baixo']);
    }
}
