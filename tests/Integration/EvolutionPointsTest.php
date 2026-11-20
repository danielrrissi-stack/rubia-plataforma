<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Evolution\EvolutionPoints;
use Tests\DbTestCase;

final class EvolutionPointsTest extends DbTestCase
{
    private array $medico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
    }

    private function patient(string $birth): array
    {
        return $this->makePatient(['birth_date' => $birth, 'sex' => 'F', 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function measure(array $p, string $on, ?float $kg, ?float $cm, string $source = 'equipe'): void
    {
        Container::measurements()->create((int) $p['id'], ['measured_on' => $on, 'weight_kg' => $kg, 'height_cm' => $cm, 'position' => $cm === null ? null : 'em_pe'], $source, (int) $this->medico['id']);
    }

    private function points(array $p): array
    {
        return EvolutionPoints::build($p, Container::measurements()->current((int) $p['id']), EvolutionPoints::isChild($p, '2026-11-19'));
    }

    public function test_adult_points_are_kg_one_per_day(): void
    {
        $p = $this->patient('1980-05-01');
        $this->measure($p, '2026-03-01', 90.0, 170.0);
        $this->measure($p, '2026-03-01', 89.5, null, 'familia');
        $this->measure($p, '2026-03-08', 89.0, null, 'familia');
        $pts = $this->points($p);
        $this->assertCount(2, $pts);
        $this->assertSame(['2026-03-01', 89.5, 'familia'], [$pts[0]['on'], $pts[0]['value'], $pts[0]['source']]);
        $this->assertSame(89.0, $pts[1]['weight']);
    }

    private function z(string $on, float $kg, float $cm): float
    {
        return (float) Container::growth()->indicators('F', '2016-01-01', $on, $kg, $cm, 'em_pe', null)['bfa']['z'];
    }

    public function test_child_weight_without_height_uses_height_from_last_90_days(): void
    {
        $p = $this->patient('2016-01-01');
        $this->measure($p, '2026-01-10', 45.0, 130.0);
        $this->measure($p, '2026-04-09', 44.0, null, 'familia'); // 89 dias depois
        $this->measure($p, '2026-04-11', 43.5, null, 'familia'); // 91 dias depois
        $pts = $this->points($p);
        $this->assertSame(['2026-01-10', '2026-04-09'], array_column($pts, 'on'));
        $this->assertEqualsWithDelta($this->z('2026-04-09', 44.0, 130.0), $pts[1]['value'], 1e-9);
        $this->assertSame(44.0, $pts[1]['weight']);
    }

    public function test_child_uses_own_height_when_present(): void
    {
        $p = $this->patient('2016-01-01');
        $this->measure($p, '2026-01-10', 45.0, 130.0);
        $this->measure($p, '2026-02-10', 44.0, 131.0);
        $this->assertEqualsWithDelta($this->z('2026-02-10', 44.0, 131.0), $this->points($p)[1]['value'], 1e-9);
    }

    public function test_is_child_boundary(): void
    {
        $this->assertTrue(EvolutionPoints::isChild(['birth_date' => '2007-11-20'], '2026-11-19'));  // 228 meses
        $this->assertFalse(EvolutionPoints::isChild(['birth_date' => '2007-10-19'], '2026-11-19')); // 229 meses
    }
}
