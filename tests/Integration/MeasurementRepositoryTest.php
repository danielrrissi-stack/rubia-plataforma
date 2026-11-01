<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class MeasurementRepositoryTest extends DbTestCase
{
    private array $patient;
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patient = $this->makePatient(['birth_date' => '2015-03-10', 'sex' => 'F']);
        $this->staff = $this->makeUser('nutricionista');
    }

    private function repo(): \App\Growth\MeasurementRepository
    {
        return Container::measurements();
    }

    private function data(array $over = []): array
    {
        return $over + [
            'measured_on' => date('Y-m-d'), 'weight_kg' => 32.5, 'height_cm' => 130.0, 'position' => 'em_pe',
            'waist_cm' => null, 'hip_cm' => null, 'head_cm' => null, 'systolic' => null, 'diastolic' => null,
        ];
    }

    public function testCreateStoresRowAndAudits(): void
    {
        $id = $this->repo()->create((int) $this->patient['id'], $this->data(), 'equipe', (int) $this->staff['id']);
        $row = $this->db->one('SELECT * FROM measurements WHERE id = ?', [$id]);
        $this->assertSame('32.500', $row['weight_kg']);
        $this->assertSame('equipe', $row['source']);
        $this->assertSame(0, (int) $row['retroactive']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE entity='measurements' AND action='create' AND entity_id = ?", [$id]));
    }

    public function testRetroactiveFlagWhenMoreThanSevenDaysBack(): void
    {
        $id = $this->repo()->create((int) $this->patient['id'], $this->data(['measured_on' => date('Y-m-d', strtotime('-8 days'))]), 'equipe', (int) $this->staff['id']);
        $this->assertSame(1, (int) $this->db->one('SELECT retroactive FROM measurements WHERE id = ?', [$id])['retroactive']);
        $id2 = $this->repo()->create((int) $this->patient['id'], $this->data(['measured_on' => date('Y-m-d', strtotime('-7 days'))]), 'equipe', (int) $this->staff['id']);
        $this->assertSame(0, (int) $this->db->one('SELECT retroactive FROM measurements WHERE id = ?', [$id2])['retroactive']);
    }

    public function testValidationRejectsBadValues(): void
    {
        $pid = (int) $this->patient['id'];
        foreach ([
            ['measured_on' => '2015-03-09'],
            ['measured_on' => date('Y-m-d', strtotime('+1 day'))],
            ['weight_kg' => 0.2],
            ['height_cm' => 251.0],
            ['systolic' => 80, 'diastolic' => 80],
            ['weight_kg' => null, 'height_cm' => null],
        ] as $over) {
            try {
                $this->repo()->create($pid, $this->data($over), 'equipe', (int) $this->staff['id']);
                $this->fail('esperava InvalidArgumentException para ' . json_encode($over));
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM measurements')['c']);
    }

    public function testCorrectCreatesNewVersionAndCurrentShowsOnlyNew(): void
    {
        $pid = (int) $this->patient['id'];
        $old = $this->repo()->create($pid, $this->data(), 'familia', (int) $this->staff['id']);
        $new = $this->repo()->correct($old, $this->data(['weight_kg' => 33.0]), (int) $this->staff['id']);
        $cur = $this->repo()->current($pid);
        $this->assertCount(1, $cur);
        $this->assertSame($new, (int) $cur[0]['id']);
        $this->assertSame('33.000', $cur[0]['weight_kg']);
        $this->assertSame($old, (int) $cur[0]['previous_id']);
        $this->assertNotNull($this->db->one('SELECT superseded_at FROM measurements WHERE id = ?', [$old])['superseded_at']);
        $versions = $this->repo()->versions($new);
        $this->assertSame([$old, $new], array_map(static fn(array $v): int => (int) $v['id'], $versions));
        $this->assertSame([$old, $new], array_map(static fn(array $v): int => (int) $v['id'], $this->repo()->versions($old)));
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE entity='measurements' AND action='update' AND entity_id = ?", [$new]));
    }

    public function testRemoveRequiresReasonAndIsLogical(): void
    {
        $pid = (int) $this->patient['id'];
        $id = $this->repo()->create($pid, $this->data(), 'equipe', (int) $this->staff['id']);
        try {
            $this->repo()->remove($id, '  ', (int) $this->staff['id']);
            $this->fail('motivo obrigatorio');
        } catch (\InvalidArgumentException) {
        }
        $this->assertCount(1, $this->repo()->current($pid));
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM measurements')['c'];
        $this->repo()->remove($id, 'Lançada no paciente errado', (int) $this->staff['id']);
        $this->assertSame([], $this->repo()->current($pid));
        $this->assertNull($this->repo()->latest($pid));
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) c FROM measurements')['c']);
        $this->assertSame('Lançada no paciente errado', $this->db->one('SELECT deleted_reason FROM measurements WHERE id = ?', [$id])['deleted_reason']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE entity='measurements' AND action='delete' AND entity_id = ?", [$id]));
    }

    public function testLatestIsMostRecentMeasuredOn(): void
    {
        $pid = (int) $this->patient['id'];
        $this->repo()->create($pid, $this->data(['measured_on' => '2024-01-10']), 'equipe', (int) $this->staff['id']);
        $b = $this->repo()->create($pid, $this->data(['measured_on' => '2024-06-10', 'weight_kg' => 40.0]), 'equipe', (int) $this->staff['id']);
        $this->assertSame($b, (int) $this->repo()->latest($pid)['id']);
    }
}
