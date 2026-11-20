<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Growth\BloodPressure as BP;
use App\Growth\BpReferenceRepository;
use PHPUnit\Framework\TestCase;

final class BloodPressureTest extends TestCase
{
    /** Repositorio falso com limiares fixos; registra o percentil de altura pedido. */
    private static function ref(int $sys90 = 110, int $sys95 = 114, int $dia90 = 70, int $dia95 = 74): BpReferenceRepository
    {
        return new class ($sys90, $sys95, $dia90, $dia95) extends BpReferenceRepository {
            /** @var list<array{string,int,int}> */
            public array $calls = [];

            public function __construct(private int $s90, private int $s95, private int $d90, private int $d95)
            {
            }

            public function thresholds(string $sex, int $age, int $heightPct): array
            {
                $this->calls[] = [$sex, $age, $heightPct];
                return ['sys90' => $this->s90, 'sys95' => $this->s95, 'dia90' => $this->d90, 'dia95' => $this->d95];
            }
        };
    }

    public function testChildTableRule(): void
    {
        $ref = self::ref();
        $c = fn(int $s, int $d) => BP::classifyChild('M', 8, 50.0, $s, $d, $ref);
        $this->assertSame('Normal', $c(105, 65));
        $this->assertSame('PA elevada', $c(111, 65));
        $this->assertSame('HA estágio 1', $c(115, 65));
        $this->assertSame('HA estágio 2', $c(127, 65)); // >= 114 + 12
        $this->assertSame('HA estágio 1', $c(125, 65));
    }

    public function testChildDiastolicClassifiedSeparatelyWorstWins(): void
    {
        $ref = self::ref();
        $c = fn(int $s, int $d) => BP::classifyChild('F', 8, 50.0, $s, $d, $ref);
        $this->assertSame('PA elevada', $c(100, 70));
        $this->assertSame('HA estágio 1', $c(100, 74));
        $this->assertSame('HA estágio 2', $c(111, 86)); // >= 74 + 12
    }

    public function testChildAbsoluteCutoffsWhenLower(): void
    {
        // P90 sistolica 125 > 120: vale 120 (o menor).
        $ref = self::ref(125, 129, 75, 79);
        $this->assertSame('PA elevada', BP::classifyChild('M', 8, 50.0, 121, 60, $ref));
        $this->assertSame('Normal', BP::classifyChild('M', 8, 50.0, 119, 60, $ref));

        // Limiares altos: 130/80 e 140/90 valem por serem menores.
        $high = self::ref(135, 140, 85, 88);
        $this->assertSame('HA estágio 1', BP::classifyChild('M', 12, 50.0, 130, 60, $high));
        $this->assertSame('HA estágio 2', BP::classifyChild('M', 12, 50.0, 140, 60, $high));
        $this->assertSame('HA estágio 1', BP::classifyChild('M', 12, 50.0, 100, 80, $high));
        $this->assertSame('HA estágio 2', BP::classifyChild('M', 12, 50.0, 100, 90, $high));
    }

    public function testChildUsesNearestTableHeightPercentileTiesToLower(): void
    {
        $cases = [[3.0, 5], [7.5, 5], [7.6, 10], [17.5, 10], [37.0, 25], [37.5, 25], [38.0, 50],
            [62.5, 50], [80.0, 75], [82.5, 75], [92.5, 90], [93.0, 95], [99.9, 95], [0.1, 5]];
        foreach ($cases as [$pct, $expected]) {
            $ref = self::ref();
            BP::classifyChild('F', 5, $pct, 100, 60, $ref);
            $this->assertSame([['F', 5, $expected]], $ref->calls, "percentil $pct");
        }
    }

    public function testChildNeedsHeight(): void
    {
        $this->assertSame('Altura necessária para classificar', BP::classifyChild('M', 8, null, 100, 60, self::ref()));
    }

    public function testAgeBoundaries(): void
    {
        $ref = self::ref();
        $this->assertSame('Não se aplica', BP::classifyChild('M', 0, 50.0, 100, 60, $ref));
        $this->assertSame('PA elevada', BP::classifyChild('M', 1, 50.0, 111, 60, $ref));
        $this->assertSame('PA elevada', BP::classifyChild('M', 12, 50.0, 111, 60, $ref));
        // 13 anos: regra fixa (111/60 normal), nao consulta a tabela nem precisa de altura.
        $this->assertSame('Normal', BP::classifyChild('M', 13, null, 111, 60, $ref));
        $this->assertSame('HA estágio 1', BP::classifyChild('M', 17, null, 130, 60, $ref));
        // 18 anos ou mais: classificacao adulta.
        $this->assertSame('Pré-hipertensão', BP::classifyChild('M', 18, null, 130, 60, $ref));
        $this->assertSame([['M', 1, 50], ['M', 12, 50]], $ref->calls);
    }

    public function testAdolescentStaticRule(): void
    {
        $ref = self::ref();
        $c = fn(int $s, int $d) => BP::classifyChild('F', 15, 50.0, $s, $d, $ref);
        $this->assertSame('Normal', $c(119, 79));
        $this->assertSame('PA elevada', $c(125, 75));
        $this->assertSame('PA elevada', $c(129, 79));
        $this->assertSame('HA estágio 1', $c(135, 75));
        $this->assertSame('HA estágio 1', $c(110, 80));
        $this->assertSame('HA estágio 1', $c(139, 89));
        $this->assertSame('HA estágio 2', $c(120, 90));
        $this->assertSame('HA estágio 2', $c(140, 70));
    }

    public function testAdult(): void
    {
        $this->assertSame('PA ótima', BP::classifyAdult(119, 79));
        $this->assertSame('PA normal', BP::classifyAdult(120, 79));
        $this->assertSame('PA normal', BP::classifyAdult(110, 84));
        $this->assertSame('Pré-hipertensão', BP::classifyAdult(135, 80));
        $this->assertSame('Pré-hipertensão', BP::classifyAdult(110, 85));
        $this->assertSame('HA estágio 1', BP::classifyAdult(150, 95));
        $this->assertSame('HA estágio 1', BP::classifyAdult(140, 70));
        $this->assertSame('HA estágio 2', BP::classifyAdult(160, 70));
        $this->assertSame('HA estágio 2', BP::classifyAdult(110, 100));
        $this->assertSame('HA estágio 3', BP::classifyAdult(118, 112));
        $this->assertSame('HA estágio 3', BP::classifyAdult(180, 70));
    }
}
