<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Medications\InvalidMedicationException;
use Tests\DbTestCase;

final class MedicationLogRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18';
    private const NOW = '2026-11-18 10:00';

    private array $medico;
    private array $patient;
    private array $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2015-03-10']);
        $this->guardian = $this->makeUser('responsavel');
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function make(array $over = []): int
    {
        $data = $over + [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => '2026-10-01', 'end_date' => '',
            'next_review_date' => '', 'family_logs' => 1, 'secret' => 0, 'notes' => '',
            'dose' => '2,5 mg', 'frequency' => '1x ao dia', 'instructions' => '',
        ];
        return Container::prescriptions()->create((int) $this->patient['id'], $data, $this->medico, self::TODAY, $this->r());
    }

    private function record(int $rx, string $at, ?array $user = null, ?string $note = null, string $now = self::NOW): void
    {
        Container::medicationLogs()->record($rx, $at, $note, $user ?? $this->guardian, $now, $this->r());
    }

    private function rejects(callable $fn, ?string $message = null): void
    {
        try {
            $fn();
            $this->fail('Esperava InvalidMedicationException');
        } catch (InvalidMedicationException $e) {
            $this->assertNotSame([], $e->errors());
            if ($message !== null) {
                $this->assertContains($message, $e->errors());
            }
        }
    }

    private function liveLogs(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM medication_logs WHERE deleted_at IS NULL')['n'];
    }

    public function test_records_current_dose_snapshot(): void
    {
        $id = $this->make();
        Container::prescriptions()->changeDose($id, ['effective_date' => '2026-11-14', 'dose' => '5 mg', 'frequency' => '1x ao dia', 'instructions' => '', 'reason' => ''], (int) $this->medico['id'], self::TODAY, $this->r());
        $this->record($id, '2026-11-12 08:00');
        $this->record($id, '2026-11-16 08:00');
        $rows = Container::medicationLogs()->forPrescription($id, $this->guardian, '2026-11-01 00:00');
        $this->assertSame(['5 mg', '2,5 mg'], array_column($rows, 'dose'));
        $this->assertSame('responsavel', $rows[0]['recorded_by_role']);
        $this->assertSame($this->guardian['name'], $rows[0]['recorder_name']);
        $this->assertSame(2, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'create' AND entity = 'medication_logs'")['n']);
    }

    public function test_rejects_outside_window_and_period(): void
    {
        $id = $this->make();
        $this->rejects(fn () => $this->record($id, '2026-11-18 11:00'));
        $this->rejects(fn () => $this->record($id, '2026-11-10 09:00'));
        $this->rejects(fn () => $this->record($id, 'lixo'));

        $future = $this->make(['start_date' => '2026-11-20']);
        $this->rejects(fn () => $this->record($future, '2026-11-18 09:00'));

        $ended = $this->make(['end_date' => '2026-11-15']);
        $this->rejects(fn () => $this->record($ended, '2026-11-16 09:00'));
        $this->record($ended, '2026-11-15 09:00');

        $off = $this->make(['family_logs' => 0]);
        $this->rejects(fn () => $this->record($off, '2026-11-17 09:00'));

        $gone = $this->make();
        Container::prescriptions()->delete($gone, (int) $this->medico['id'], $this->r());
        $this->rejects(fn () => $this->record($gone, '2026-11-17 09:00'), 'Medicamento não encontrado.');

        $this->assertSame(1, $this->liveLogs());
    }

    public function test_log_is_idempotent_per_minute(): void
    {
        $id = $this->make();
        $this->record($id, '2026-11-17 09:00');
        $this->record($id, '2026-11-17 09:00');
        $this->assertSame(1, $this->liveLogs());
        $this->record($id, '2026-11-17 09:01');
        $this->assertSame(2, $this->liveLogs());
        $other = $this->makeUser('responsavel');
        $this->record($id, '2026-11-17 09:00', $other);
        $this->assertSame(3, $this->liveLogs());
    }

    public function test_only_author_removes_within_window(): void
    {
        $id = $this->make();
        $this->record($id, '2026-11-17 09:00');
        $logId = (int) $this->db->one('SELECT id FROM medication_logs')['id'];
        $svc = Container::medicationLogs();

        $other = $this->makeUser('responsavel');
        $this->rejects(fn () => $svc->remove($logId, $other, self::NOW, $this->r()), 'Registro não encontrado.');
        $this->rejects(fn () => $svc->remove($logId, $this->guardian, '2026-11-25 10:00', $this->r()), 'Registro não encontrado.');
        $this->assertSame(1, $this->liveLogs());

        $svc->remove($logId, $this->guardian, self::NOW, $this->r());
        $this->assertSame(0, $this->liveLogs());
        $this->assertSame([], $svc->forPrescription($id, $this->guardian, '2026-11-01 00:00'));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'delete' AND entity = 'medication_logs'")['n']);
        $this->rejects(fn () => $svc->remove($logId, $this->guardian, self::NOW, $this->r()), 'Registro não encontrado.');
    }

    public function test_staff_roles_cannot_record(): void
    {
        $id = $this->make();
        foreach (['medico', 'nutricionista', 'secretaria'] as $role) {
            $this->rejects(fn () => $this->record($id, '2026-11-17 09:00', $this->makeUser($role)));
        }
        $this->assertSame(0, $this->liveLogs());
        $paciente = $this->makeUser('paciente');
        $this->record($id, '2026-11-17 09:00', $paciente);
        $this->assertSame(1, $this->liveLogs());
    }

    public function test_guardian_never_sees_secret_prescription_trail(): void
    {
        $id = $this->make();
        $this->record($id, '2026-11-17 09:00');
        Container::prescriptions()->update($id, [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => '2026-10-01', 'end_date' => '',
            'next_review_date' => '', 'family_logs' => 1, 'secret' => 1, 'notes' => '',
        ], $this->medico, self::TODAY, $this->r());
        $this->assertSame(1, (int) Container::prescriptions()->find($id)['secret']);

        $svc = Container::medicationLogs();
        $this->assertSame([], $svc->forPrescription($id, $this->guardian, '2026-11-01 00:00'));
        $this->rejects(fn () => $this->record($id, '2026-11-17 10:00'), 'Medicamento não encontrado.');
        $this->assertCount(1, $svc->forPrescription($id, $this->medico, '2026-11-01 00:00'));
        $logId = (int) $this->db->one('SELECT id FROM medication_logs')['id'];
        $this->rejects(fn () => $svc->remove($logId, $this->guardian, self::NOW, $this->r()), 'Registro não encontrado.');
    }
}
