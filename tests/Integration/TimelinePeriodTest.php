<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Timeline\TimelinePeriod;
use Tests\DbTestCase;

final class TimelinePeriodTest extends DbTestCase
{
    private const TODAY = '2026-11-19';

    private array $medico;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function appt(string $startsAt, string $status): void
    {
        $this->db->insert('appointments', [
            'professional_id' => $this->medico['id'], 'patient_id' => $this->patient['id'], 'starts_at' => $startsAt,
            'ends_at' => substr($startsAt, 0, 11) . '23:00:00', 'kind' => 'retorno', 'status' => $status, 'created_by' => $this->medico['id'],
        ]);
    }

    private function resolve(string $choice): array
    {
        return TimelinePeriod::resolve((int) $this->patient['id'], $choice, self::TODAY);
    }

    public function test_last_done_visit_before_today(): void
    {
        $this->appt('2026-11-09 10:00:00', 'realizada');
        $this->appt('2026-11-18 10:00:00', 'cancelada');
        $this->appt('2026-11-19 08:00:00', 'realizada'); // a consulta de hoje nao conta
        $r = $this->resolve('visita');
        $this->assertSame('2026-11-09', $r['from']);
        $this->assertSame('2026-11-09', $r['ref']);
        $this->assertSame(10, $r['days']);
        $this->assertSame('Desde a última visita', $r['label']);
        $this->assertSame($r, $this->resolve('xyz'));
    }

    public function test_falls_back_to_evolution_then_everything(): void
    {
        $this->assertNull($this->resolve('visita')['from']);
        $this->assertSame('Sem visita anterior — mostrando tudo', $this->resolve('visita')['label']);
        $this->db->insert('clinical_entries', ['patient_id' => $this->patient['id'], 'kind' => 'evolucao', 'payload' => '{}', 'encounter_date' => '2026-10-01', 'created_by' => $this->medico['id']]);
        $this->assertSame('2026-10-01', $this->resolve('visita')['from']);
    }

    public function test_nutritionist_reference_uses_only_her_visits(): void
    {
        $nutri = $this->makeUser('nutricionista');
        $this->appt('2026-11-09 10:00:00', 'realizada'); // da medica: a nutricionista nao ve
        $this->db->insert('clinical_entries', ['patient_id' => $this->patient['id'], 'kind' => 'evolucao', 'payload' => '{}', 'encounter_date' => '2026-11-12', 'created_by' => $this->medico['id']]);
        $r = TimelinePeriod::resolve((int) $this->patient['id'], 'visita', self::TODAY, (int) $nutri['id']);
        $this->assertNull($r['from']);
        $this->db->insert('appointments', [
            'professional_id' => $nutri['id'], 'patient_id' => $this->patient['id'], 'starts_at' => '2026-10-20 10:00:00',
            'ends_at' => '2026-10-20 11:00:00', 'kind' => 'retorno', 'status' => 'realizada', 'created_by' => $this->medico['id'],
        ]);
        $this->assertSame('2026-10-20', TimelinePeriod::resolve((int) $this->patient['id'], 'visita', self::TODAY, (int) $nutri['id'])['from']);
    }

    public function test_fixed_periods(): void
    {
        $this->assertSame('2026-08-19', $this->resolve('3m')['from']);
        $this->assertSame('2025-11-19', $this->resolve('12m')['from']);
        $this->assertNull($this->resolve('tudo')['from']);
    }

    public function test_month_periods_clamp_to_end_of_month(): void
    {
        $pid = (int) $this->patient['id'];
        $this->assertSame('2026-02-28', TimelinePeriod::resolve($pid, '3m', '2026-05-31')['from']);
        $this->assertSame('2027-02-28', TimelinePeriod::resolve($pid, '12m', '2028-02-29')['from']);
        $this->assertSame('2026-04-30', TimelinePeriod::resolve($pid, '3m', '2026-07-31')['from']);
    }
}
