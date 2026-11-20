<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\WeightGoals\WeightGoalPoints;
use Tests\DbTestCase;

final class WeightGoalPointsTest extends DbTestCase
{
    private array $medico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
    }

    private function patient(string $birth, string $sex = 'F'): array
    {
        return $this->makePatient(['birth_date' => $birth, 'sex' => $sex, 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function m(array $p, string $on, ?float $kg, ?float $cm = null, string $source = 'equipe'): int
    {
        return Container::measurements()->create((int) $p['id'], ['measured_on' => $on, 'weight_kg' => $kg, 'height_cm' => $cm, 'position' => $cm === null ? null : 'em_pe'], $source, (int) $this->medico['id']);
    }

    private function points(string $kind, array $p, ?string $from = null): array
    {
        return WeightGoalPoints::build($kind, $p, Container::measurements()->current((int) $p['id']), $from);
    }

    public function test_corrected_and_removed_measurements(): void
    {
        $p = $this->patient('1990-01-01');
        $a = $this->m($p, '2026-10-01', 95.0);
        $b = $this->m($p, '2026-10-15', 94.0);
        $this->m($p, '2026-09-01', 99.0);
        Container::measurements()->correct($a, ['measured_on' => '2026-10-01', 'weight_kg' => 96.0], (int) $this->medico['id']);
        Container::measurements()->remove($b, 'digitado errado', (int) $this->medico['id']);
        $pts = $this->points('peso', $p, '2026-10-01');
        $this->assertSame([['2026-10-01', 96.0]], array_map(fn ($x) => [$x['on'], $x['value']], $pts));
    }

    public function test_same_day_keeps_latest_and_family_source(): void
    {
        $p = $this->patient('1990-01-01');
        $this->m($p, '2026-10-01', 95.0);
        $last = $this->m($p, '2026-10-01', 94.5, null, 'familia');
        $pts = $this->points('peso', $p);
        $this->assertCount(1, $pts);
        $this->assertSame($last, $pts[0]['id']);
        $this->assertSame(94.5, $pts[0]['value']);
        $this->assertSame('familia', $pts[0]['source']);
    }

    public function test_z_goal_needs_height_and_uses_bfa_z(): void
    {
        $p = $this->patient('2012-03-01', 'M'); // 14 anos
        $this->m($p, '2026-09-01', 60.0, 160.0);
        $this->m($p, '2026-10-01', 59.0);
        $z = $this->points('imc_z', $p);
        $this->assertSame(['2026-09-01'], array_column($z, 'on'));
        $ind = Container::growth()->indicators('M', '2012-03-01', '2026-09-01', 60.0, 160.0, 'em_pe', null);
        $this->assertSame($ind['bfa']['z'], $z[0]['value']);
        $this->assertSame(160.0, $z[0]['height']);
        $kg = $this->points('peso', $p);
        $this->assertSame(['2026-09-01', '2026-10-01'], array_column($kg, 'on'));
        $this->assertNotNull($kg[0]['z']); // adolescente com meta em kg: z informativo
        $this->assertNull($kg[1]['z']);
    }

    public function test_starting_is_latest_point(): void
    {
        $p = $this->patient('1990-01-01');
        $this->assertNull(WeightGoalPoints::starting('peso', $p, []));
        $this->m($p, '2026-09-01', 99.0, 170.0);
        $this->m($p, '2026-10-01', 97.0, 170.0);
        $s = WeightGoalPoints::starting('peso', $p, Container::measurements()->current((int) $p['id']));
        $this->assertSame('2026-10-01', $s['on']);
        $this->assertSame(97.0, $s['value']);
        $this->assertEqualsWithDelta(97 / 1.7 ** 2, $s['bmi'], 0.05);
    }
}
