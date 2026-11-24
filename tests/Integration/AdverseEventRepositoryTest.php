<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Medications\InvalidMedicationException;
use Tests\DbTestCase;

final class AdverseEventRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18';

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

    private function rx(array $over = [], ?array $patient = null): int
    {
        $data = $over + [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => '2026-10-01', 'end_date' => '',
            'next_review_date' => '', 'family_logs' => 1, 'secret' => 0, 'notes' => '',
            'dose' => '2,5 mg', 'frequency' => '1x ao dia', 'instructions' => '',
        ];
        return Container::prescriptions()->create((int) ($patient ?? $this->patient)['id'], $data, $this->medico, self::TODAY, $this->r());
    }

    private function report(array $data, ?array $user = null, ?array $patient = null): int
    {
        return Container::adverseEvents()->report((int) ($patient ?? $this->patient)['id'], $data, $user ?? $this->guardian, self::TODAY, $this->r());
    }

    private function rejects(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Esperava InvalidMedicationException');
        } catch (InvalidMedicationException $e) {
            $this->assertNotSame([], $e->errors());
        }
    }

    private function event(int $id): array
    {
        return $this->db->one('SELECT * FROM adverse_events WHERE id = ?', [$id]);
    }

    public function test_family_report_limits_and_ignores_severity(): void
    {
        $id = $this->report(['occurred_on' => '2026-11-17', 'description' => 'Enjoo', 'severity' => 'grave', 'conduct' => 'x']);
        $e = $this->event($id);
        $this->assertSame('familia', $e['source']);
        $this->assertNull($e['severity']);
        $this->assertNull($e['conduct']);
        $this->assertNull($e['reviewed_by']);
        $this->assertSame('responsavel', $e['reported_by_role']);

        $this->report(['occurred_on' => '2026-08-20', 'description' => 'No limite']);
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-08-19', 'description' => 'Antigo']));
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-11-19', 'description' => 'Futuro']));
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-11-17', 'description' => ' ']));
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-11-17', 'description' => str_repeat('a', 2001)]));
        $this->rejects(fn () => $this->report(['occurred_on' => 'lixo', 'description' => 'x']));
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-11-17', 'description' => 'x'], $this->makeUser('nutricionista')));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'create' AND entity = 'adverse_events' AND entity_id = ?", [$id])['n']);
    }

    public function test_doctor_report_is_born_reviewed(): void
    {
        $id = $this->report(['occurred_on' => '2026-11-17', 'description' => 'Rash', 'severity' => 'leve', 'conduct' => 'Observar'], $this->medico);
        $e = $this->event($id);
        $this->assertSame('equipe', $e['source']);
        $this->assertSame('leve', $e['severity']);
        $this->assertSame('Observar', $e['conduct']);
        $this->assertSame((int) $this->medico['id'], (int) $e['reviewed_by']);
        $this->assertNotNull($e['reviewed_at']);
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-11-17', 'description' => 'x', 'severity' => 'xx'], $this->medico));
        $this->assertSame([], Container::adverseEvents()->unreviewed(null));
    }

    public function test_review_marks_and_audits(): void
    {
        $id = $this->report(['occurred_on' => '2026-11-17', 'description' => 'Enjoo']);
        $svc = Container::adverseEvents();
        $svc->review($id, 'moderada', 'Reduzir dose', (int) $this->medico['id'], $this->r());
        $e = $this->event($id);
        $this->assertSame('moderada', $e['severity']);
        $this->assertSame('Reduzir dose', $e['conduct']);
        $this->assertSame((int) $this->medico['id'], (int) $e['reviewed_by']);
        $this->assertNotNull($e['reviewed_at']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'review' AND entity = 'adverse_events'")['n']);
        $this->rejects(fn () => $svc->review($id, 'leve', null, (int) $this->medico['id'], $this->r()));
        $this->assertSame('moderada', $this->event($id)['severity']);
    }

    public function test_unreviewed_lists_oldest_first(): void
    {
        $a = $this->report(['occurred_on' => '2026-11-17', 'description' => 'A']);
        $b = $this->report(['occurred_on' => '2026-11-10', 'description' => 'B']);
        $other = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
        $c = $this->report(['occurred_on' => '2026-11-16', 'description' => 'C'], null, $other);
        $this->report(['occurred_on' => '2026-11-16', 'description' => 'D'], $this->medico);
        $this->db->run('UPDATE adverse_events SET created_at = ? WHERE id = ?', ['2026-11-17 08:00:00', $a]);
        $this->db->run('UPDATE adverse_events SET created_at = ? WHERE id = ?', ['2026-11-17 09:00:00', $b]);
        $this->db->run('UPDATE adverse_events SET created_at = ? WHERE id = ?', ['2026-11-17 10:00:00', $c]);

        $svc = Container::adverseEvents();
        $all = $svc->unreviewed(null);
        $this->assertSame([$a, $b, $c], array_map(fn ($x) => (int) $x['id'], $all));
        $this->assertSame($other['full_name'], $all[2]['patient_name']);
        $this->assertSame([$c], array_map(fn ($x) => (int) $x['id'], $svc->unreviewed([(int) $other['id']])));
        $this->assertSame([], $svc->unreviewed([]));
        $this->db->run('UPDATE patients SET deleted_at = NOW() WHERE id = ?', [$other['id']]);
        $this->assertSame([$a, $b], array_map(fn ($x) => (int) $x['id'], $svc->unreviewed(null)));
    }

    public function test_family_cannot_link_other_patients_prescription(): void
    {
        $other = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
        $foreign = $this->rx([], $other);
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-11-17', 'description' => 'x', 'prescription_id' => $foreign]));
        $mine = $this->rx();
        $id = $this->report(['occurred_on' => '2026-11-17', 'description' => 'x', 'prescription_id' => $mine]);
        $this->assertSame($mine, (int) $this->event($id)['prescription_id']);
        $gone = $this->rx();
        Container::prescriptions()->delete($gone, (int) $this->medico['id'], $this->r());
        $this->rejects(fn () => $this->report(['occurred_on' => '2026-11-17', 'description' => 'x', 'prescription_id' => $gone]));
    }

    public function test_guardian_never_sees_secret_prescription_trail(): void
    {
        $rx = $this->rx();
        $linked = $this->report(['occurred_on' => '2026-11-17', 'description' => 'Ligado', 'prescription_id' => $rx]);
        $free = $this->report(['occurred_on' => '2026-11-16', 'description' => 'Livre']);
        $team = $this->report(['occurred_on' => '2026-11-15', 'description' => 'Equipe'], $this->medico);
        Container::prescriptions()->update($rx, [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => '2026-10-01', 'end_date' => '',
            'next_review_date' => '', 'family_logs' => 1, 'secret' => 1, 'notes' => '',
        ], $this->medico, self::TODAY, $this->r());

        $svc = Container::adverseEvents();
        $ids = fn (array $rows) => array_map(fn ($x) => (int) $x['id'], $rows);
        $pid = (int) $this->patient['id'];
        $this->assertSame([$free], $ids($svc->forViewer($pid, $this->guardian)));
        $this->assertSame([$linked, $free, $team], $ids($svc->forViewer($pid, $this->medico)));
        $paciente = $this->makeUser('paciente');
        $this->assertSame([$linked, $free], $ids($svc->forViewer($pid, $paciente)));
        $this->assertSame([], $svc->forViewer($pid, $this->makeUser('nutricionista')));
        $this->assertSame([], $svc->forViewer($pid, $this->makeUser('secretaria')));
        $first = $svc->forViewer($pid, $this->medico)[0];
        $this->assertSame('Metformina', $first['drug_name']);
        $this->assertNotSame('', (string) $first['reporter_name']);
    }
}
