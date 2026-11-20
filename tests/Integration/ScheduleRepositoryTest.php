<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Agenda\AgendaStatus;
use App\Agenda\InvalidAgendaException;
use App\Core\Container;
use App\Core\Request;
use Tests\DbTestCase;

final class ScheduleRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18';

    private array $medico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function pid(): int
    {
        return (int) $this->medico['id'];
    }

    private function audits(string $action, string $entity): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM audit_log WHERE action = ? AND entity = ?', [$action, $entity])['n'];
    }

    private function rejects(callable $fn, ?string $message = null): void
    {
        try {
            $fn();
            $this->fail('Esperava InvalidAgendaException');
        } catch (InvalidAgendaException $e) {
            $this->assertNotSame([], $e->errors());
            if ($message !== null) {
                $this->assertContains($message, $e->errors());
            }
        }
    }

    private function range(int $wd, string $s, string $e): array
    {
        return ['weekday' => $wd, 'start_time' => $s, 'end_time' => $e];
    }

    public function test_profile_creates_default_row(): void
    {
        $s = Container::schedules();
        $this->assertSame(['slot_minutes' => 30, 'address' => null], $s->profile($this->pid()));
        $this->assertSame(['slot_minutes' => 30, 'address' => null], $s->profile($this->pid()));
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM professional_schedules WHERE professional_id = ?', [$this->pid()])['n']);
    }

    public function test_save_week_validates_overlap_and_order(): void
    {
        $s = Container::schedules();
        $this->rejects(fn () => $s->saveWeek($this->pid(), [$this->range(1, '08:00', '10:00'), $this->range(1, '09:30', '11:00')], 30, null, $this->pid(), $this->r()), 'As faixas de segunda-feira se sobrepõem.');
        $this->rejects(fn () => $s->saveWeek($this->pid(), [$this->range(2, '10:00', '09:00')], 30, null, $this->pid(), $this->r()));
        $this->rejects(fn () => $s->saveWeek($this->pid(), [$this->range(2, '09:00', '09:00')], 30, null, $this->pid(), $this->r()));
        $this->rejects(fn () => $s->saveWeek($this->pid(), [$this->range(8, '09:00', '10:00')], 30, null, $this->pid(), $this->r()));
        $this->rejects(fn () => $s->saveWeek($this->pid(), [$this->range(1, '25:00', '26:00')], 30, null, $this->pid(), $this->r()));
        foreach ([5, 9, 33, 245] as $bad) {
            $this->rejects(fn () => $s->saveWeek($this->pid(), [], $bad, null, $this->pid(), $this->r()));
        }
        $this->rejects(fn () => $s->saveWeek($this->pid(), [], 30, str_repeat('a', 201), $this->pid(), $this->r()));
        $this->assertSame([], $s->ranges($this->pid()));
        $this->assertSame(0, $this->audits('update', 'professional_schedules'));

        // faixas encostadas sao validas
        $s->saveWeek($this->pid(), [$this->range(1, '12:30', '20:00'), $this->range(1, '07:30', '12:30'), $this->range(6, '08:00', '12:00')], 45, '  Rua A, 10  ', $this->pid(), $this->r());
        $ranges = $s->ranges($this->pid());
        $this->assertSame([[1, '07:30', '12:30'], [1, '12:30', '20:00'], [6, '08:00', '12:00']], array_map(fn (array $x): array => [(int) $x['weekday'], substr((string) $x['start_time'], 0, 5), substr((string) $x['end_time'], 0, 5)], $ranges));
        $this->assertSame(['slot_minutes' => 45, 'address' => 'Rua A, 10'], $s->profile($this->pid()));
        $this->assertSame(1, $this->audits('update', 'professional_schedules'));

        // trocar: exclusao logica das antigas
        $s->saveWeek($this->pid(), [$this->range(3, '14:00', '16:00')], 30, '', $this->pid(), $this->r());
        $this->assertCount(1, $s->ranges($this->pid()));
        $this->assertSame(3, (int) $this->db->one('SELECT COUNT(*) n FROM schedule_ranges WHERE professional_id = ? AND deleted_at IS NOT NULL AND deleted_by = ?', [$this->pid(), $this->pid()])['n']);
        $this->assertSame(['slot_minutes' => 30, 'address' => null], $s->profile($this->pid()));
        $audit = $this->db->one("SELECT * FROM audit_log WHERE action = 'update' AND entity = 'professional_schedules' ORDER BY id DESC LIMIT 1");
        $this->assertSame($this->pid(), (int) $audit['entity_id']);
        $this->assertSame(45, json_decode((string) $audit['before_json'], true)['slot_minutes']);
        $this->assertSame(30, json_decode((string) $audit['after_json'], true)['slot_minutes']);
        $this->assertCount(1, json_decode((string) $audit['after_json'], true)['ranges']);

        // slotsOn usa os dados do banco: quarta 14:00-16:00 / 30
        $this->assertSame(['14:00', '14:30', '15:00', '15:30'], array_column($s->slotsOn($this->pid(), '2026-11-18'), 'start'));
        $this->assertSame([], $s->slotsOn($this->pid(), '2026-11-16'));
    }

    public function test_apply_clinic_default_only_when_empty(): void
    {
        $s = Container::schedules();
        $s->saveWeek($this->pid(), [], 30, 'Rua B', $this->pid(), $this->r());
        $s->applyClinicDefault($this->pid(), $this->pid(), $this->r());
        $this->assertCount(count(AgendaStatus::CLINIC_DEFAULT), $s->ranges($this->pid()));
        $this->assertSame(['slot_minutes' => 30, 'address' => 'Rua B'], $s->profile($this->pid()));
        $this->assertCount(23, $s->slotsOn($this->pid(), '2026-11-16'));
        $this->assertCount(10, $s->slotsOn($this->pid(), '2026-11-21'));
        $this->rejects(fn () => $s->applyClinicDefault($this->pid(), $this->pid(), $this->r()), 'Esta agenda já tem horários.');
        $this->assertCount(count(AgendaStatus::CLINIC_DEFAULT), $s->ranges($this->pid()));
    }

    public function test_closure_and_extra_rules(): void
    {
        $s = Container::schedules();
        $s->applyClinicDefault($this->pid(), $this->pid(), $this->r());
        $p = $this->pid();
        $r = $this->r();
        $t = self::TODAY;
        $this->rejects(fn () => $s->addClosure($p, '2026-11-20', '2026-11-20', '08:00', null, null, $p, $r, $t));
        $this->rejects(fn () => $s->addClosure($p, '2026-11-20', '2026-11-20', null, '09:00', null, $p, $r, $t));
        $this->rejects(fn () => $s->addClosure($p, '2026-11-20', '2026-11-20', '09:00', '08:00', null, $p, $r, $t));
        $this->rejects(fn () => $s->addClosure($p, '2026-11-21', '2026-11-20', null, null, null, $p, $r, $t));
        $this->rejects(fn () => $s->addClosure($p, '2029-01-01', '2029-01-02', null, null, null, $p, $r, $t));
        $this->rejects(fn () => $s->addClosure($p, '2026-11-20', '2026-11-20', null, null, str_repeat('x', 121), $p, $r, $t));
        $this->rejects(fn () => $s->addClosure($p, 'amanha', '2026-11-20', null, null, null, $p, $r, $t));
        $this->rejects(fn () => $s->addExtra($p, '2026-11-22', '', '', $p, $r, $t));
        $this->rejects(fn () => $s->addExtra($p, '2026-11-22', '09:00', '', $p, $r, $t));
        $this->rejects(fn () => $s->addExtra($p, '2026-11-22', '10:00', '09:00', $p, $r, $t));
        $this->rejects(fn () => $s->addExtra($p, '2024-11-01', '09:00', '10:00', $p, $r, $t));
        $this->assertSame(0, $this->audits('create', 'schedule_exceptions'));

        $closure = $s->addClosure($p, '2026-11-16', '2026-11-16', '08:00', '09:00', '  Congresso ', $p, $r, $t);
        $extra = $s->addExtra($p, '2026-11-22', '09:00', '10:00', $p, $r, $t);
        $whole = $s->addClosure($p, '2026-12-01', '2026-12-05', null, null, '', $p, $r, $t);
        $this->assertSame(3, $this->audits('create', 'schedule_exceptions'));
        $row = $this->db->one('SELECT * FROM schedule_exceptions WHERE id = ?', [$closure]);
        $this->assertSame('fechado', $row['kind']);
        $this->assertSame('Congresso', $row['reason']);
        $this->assertNull($this->db->one('SELECT reason FROM schedule_exceptions WHERE id = ?', [$whole])['reason']);

        $this->assertSame([$closure, $extra], array_map(fn (array $x): int => (int) $x['id'], $s->exceptions($p, '2026-11-16', '2026-11-30')));
        $this->assertSame([$whole], array_map(fn (array $x): int => (int) $x['id'], $s->exceptions($p, '2026-12-05', '2026-12-10')));
        $this->assertCount(21, $s->slotsOn($p, '2026-11-16'));
        $this->assertSame(['09:00', '09:30'], array_column($s->slotsOn($p, '2026-11-22'), 'start'));
        $this->assertSame([], $s->slotsOn($p, '2026-12-02'));

        $s->removeException($closure, $p, $r);
        $this->assertSame(1, $this->audits('delete', 'schedule_exceptions'));
        $row = $this->db->one('SELECT * FROM schedule_exceptions WHERE id = ?', [$closure]);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame($p, (int) $row['deleted_by']);
        $this->assertCount(23, $s->slotsOn($p, '2026-11-16'));
        $this->assertSame([$extra], array_map(fn (array $x): int => (int) $x['id'], $s->exceptions($p, '2026-11-16', '2026-11-30')));
        $this->rejects(fn () => $s->removeException($closure, $p, $r));
        $this->rejects(fn () => $s->removeException(999999, $p, $r));
    }

    public function test_booked_in_closure_counts_occupying_only(): void
    {
        $patient = $this->makePatient();
        $base = ['professional_id' => $this->pid(), 'patient_id' => $patient['id'], 'kind' => 'primeira', 'created_by' => $this->pid()];
        $this->db->insert('appointments', $base + ['starts_at' => '2026-12-02 08:00:00', 'ends_at' => '2026-12-02 08:30:00', 'status' => 'agendada']);
        $this->db->insert('appointments', $base + ['starts_at' => '2026-12-02 09:00:00', 'ends_at' => '2026-12-02 09:30:00', 'status' => 'cancelada']);
        $this->db->insert('appointments', $base + ['starts_at' => '2026-12-03 15:00:00', 'ends_at' => '2026-12-03 15:30:00', 'status' => 'confirmada']);
        $this->db->insert('appointments', $base + ['starts_at' => '2026-12-09 08:00:00', 'ends_at' => '2026-12-09 08:30:00', 'status' => 'agendada']);
        $s = Container::schedules();
        $this->assertSame(1, $s->bookedInClosure($this->pid(), '2026-12-02', '2026-12-02', null, null));
        $this->assertSame(2, $s->bookedInClosure($this->pid(), '2026-12-01', '2026-12-05', null, null));
        $this->assertSame(1, $s->bookedInClosure($this->pid(), '2026-12-01', '2026-12-05', '07:00', '12:00'));
        $this->assertSame(0, $s->bookedInClosure($this->pid(), '2026-12-01', '2026-12-05', '08:30', '09:30'));
        $this->assertSame(1, $s->bookedInClosure($this->pid(), '2026-12-01', '2026-12-05', '08:15', '08:45'));
        $other = $this->makeUser('medico');
        $this->assertSame(0, $s->bookedInClosure((int) $other['id'], '2026-12-01', '2026-12-05', null, null));
    }

    public function test_occupying_unique_index_allows_freed_slot(): void
    {
        $patient = $this->makePatient();
        $row = ['professional_id' => $this->pid(), 'patient_id' => $patient['id'], 'kind' => 'primeira', 'created_by' => $this->pid(), 'starts_at' => '2026-12-02 08:00:00', 'ends_at' => '2026-12-02 08:30:00'];
        $this->db->insert('appointments', $row + ['status' => 'cancelada']);
        $this->db->insert('appointments', $row + ['status' => 'remarcada']);
        $this->db->insert('appointments', $row + ['status' => 'agendada']);
        $this->expectException(\PDOException::class);
        $this->db->insert('appointments', $row + ['status' => 'confirmada']);
    }
}
