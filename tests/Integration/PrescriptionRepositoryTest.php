<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Medications\InvalidMedicationException;
use Tests\DbTestCase;

final class PrescriptionRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18';

    private array $medico;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2015-03-10']);
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function data(array $over = []): array
    {
        return $over + [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => '2026-10-01', 'end_date' => '',
            'next_review_date' => '', 'family_logs' => 0, 'secret' => 0, 'notes' => '',
            'dose' => '2,5 mg', 'frequency' => '1x ao dia', 'instructions' => '',
        ];
    }

    private function make(array $over = [], ?array $patient = null, ?array $user = null): int
    {
        return Container::prescriptions()->create((int) ($patient ?? $this->patient)['id'], $this->data($over), $user ?? $this->medico, self::TODAY, $this->r());
    }

    private function audits(string $action, string $entity): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM audit_log WHERE action = ? AND entity = ?', [$action, $entity])['n'];
    }

    private function change(int $id, string $date, string $dose = '5 mg'): int
    {
        return Container::prescriptions()->changeDose($id, ['effective_date' => $date, 'dose' => $dose, 'frequency' => '1x ao dia', 'instructions' => '', 'reason' => ''], (int) $this->medico['id'], self::TODAY, $this->r());
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

    public function test_create_stores_first_dose_and_audits(): void
    {
        $id = $this->make();
        $p = Container::prescriptions()->find($id);
        $this->assertSame('Metformina', $p['drug_name']);
        $doses = Container::prescriptions()->doses($id);
        $this->assertCount(1, $doses);
        $this->assertSame('2026-10-01', $doses[0]['effective_date']);
        $this->assertSame(1, $this->audits('create', 'prescriptions'));
        $this->assertSame(1, $this->audits('create', 'prescription_doses'));
    }

    public function test_create_validates(): void
    {
        $this->rejects(fn () => $this->make(['drug_name' => ' ']));
        $this->rejects(fn () => $this->make(['route' => 'xx']));
        $this->rejects(fn () => $this->make(['dose' => '']));
        $this->rejects(fn () => $this->make(['frequency' => str_repeat('a', 121)]));
        $this->rejects(fn () => $this->make(['end_date' => '2026-09-01']));
        $this->rejects(fn () => $this->make(['start_date' => '2019-01-01']));
        $this->rejects(fn () => $this->make(['next_review_date' => '2026-02-31']));
    }

    public function test_change_dose_appends_row_and_dose_on_follows_dates(): void
    {
        $repo = Container::prescriptions();
        $id = $this->make();
        $this->change($id, '2026-11-01');
        $this->assertSame('2,5 mg', $repo->doseOn($id, '2026-10-31')['dose']);
        $this->assertSame('5 mg', $repo->doseOn($id, '2026-11-01')['dose']);
        $this->assertNull($repo->doseOn($id, '2026-09-30'));
        $this->assertCount(2, $repo->doses($id));
        $this->assertSame(2, $this->audits('create', 'prescription_doses'));
    }

    public function test_change_dose_rejects_out_of_order_future_and_ended(): void
    {
        $id = $this->make();
        $this->change($id, '2026-11-01');
        $this->rejects(fn () => $this->change($id, '2026-10-15'));
        $this->rejects(fn () => $this->change($id, '2026-11-19'));
        $id2 = $this->make(['start_date' => '2026-10-10']);
        $this->rejects(fn () => $this->change($id2, '2026-10-05'));
        Container::prescriptions()->end($id2, '2026-11-10', null, (int) $this->medico['id'], self::TODAY, $this->r());
        $this->rejects(fn () => $this->change($id2, '2026-11-12'));
        $fut = $this->make(['start_date' => '2026-12-01']);
        $this->assertGreaterThan(0, $this->change($fut, '2026-12-01'));
    }

    public function test_update_rules_for_start_date(): void
    {
        $repo = Container::prescriptions();
        $id = $this->make();
        $repo->update($id, $this->data(['start_date' => '2026-10-05']), $this->medico, self::TODAY, $this->r());
        $this->assertSame('2026-10-05', $repo->doses($id)[0]['effective_date']);
        $this->change($id, '2026-11-01');
        $this->rejects(fn () => $repo->update($id, $this->data(['start_date' => '2026-11-02']), $this->medico, self::TODAY, $this->r()));
        $repo->update($id, $this->data(['start_date' => '2026-11-01', 'drug_name' => 'Outra']), $this->medico, self::TODAY, $this->r());
        $this->assertSame('Outra', $repo->find($id)['drug_name']);
        $this->assertSame('2026-11-01', $repo->doses($id)[0]['effective_date']);
        $this->assertSame('2,5 mg', $repo->doses($id)[0]['dose']);
        $this->assertSame(2, $this->audits('update', 'prescriptions'));
    }

    public function test_secret_only_for_minor_by_doctor(): void
    {
        $repo = Container::prescriptions();
        $this->assertSame(1, (int) $repo->find($this->make(['secret' => 1]))['secret']);
        $adult = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2008-11-18']);
        $this->assertSame(0, (int) $repo->find($this->make(['secret' => 1], $adult))['secret']);
        $nutri = $this->makeUser('nutricionista');
        $this->assertSame(0, (int) $repo->find($this->make(['secret' => 1], null, $nutri))['secret']);
    }

    public function test_visibility_rules(): void
    {
        $repo = Container::prescriptions();
        $open = $this->make();
        $secret = $this->make(['secret' => 1, 'drug_name' => 'Sigilosa']);
        $pid = (int) $this->patient['id'];
        $resp = $this->makeUser('responsavel');
        $this->assertNull($repo->visibleTo($secret, $resp));
        $this->assertNotNull($repo->visibleTo($open, $resp));
        $this->assertCount(1, $repo->forViewer($pid, $resp, self::TODAY));
        foreach (['paciente', 'medico'] as $role) {
            $u = $this->makeUser($role);
            $this->assertNotNull($repo->visibleTo($secret, $u));
            $this->assertCount(2, $repo->forViewer($pid, $u, self::TODAY));
        }
        foreach (['nutricionista', 'secretaria', 'crianca'] as $role) {
            $u = $this->makeUser($role);
            $this->assertNull($repo->visibleTo($open, $u));
            $this->assertSame([], $repo->forViewer($pid, $u, self::TODAY));
        }
        $repo->delete($open, (int) $this->medico['id'], $this->r());
        $this->assertNull($repo->visibleTo($open, $this->medico));
        $this->assertCount(1, $repo->forViewer($pid, $this->medico, self::TODAY));
        $this->assertNotNull($repo->find($open));
    }

    public function test_status_and_ordering(): void
    {
        $repo = Container::prescriptions();
        $old = $this->make(['start_date' => '2026-01-01', 'end_date' => '2026-03-01', 'drug_name' => 'Antiga']);
        $this->make(['start_date' => '2026-01-01', 'end_date' => '2026-06-01', 'drug_name' => 'Antiga2']);
        $act = $this->make(['start_date' => '2026-10-01', 'drug_name' => 'Ativa']);
        $actEnds = $this->make(['start_date' => '2026-10-02', 'end_date' => '2026-11-18', 'drug_name' => 'FimHoje']);
        $fut = $this->make(['start_date' => '2026-12-01', 'drug_name' => 'Futura']);
        $this->assertSame('encerrada', $repo->status($repo->find($old), self::TODAY));
        $this->assertSame('ativa', $repo->status($repo->find($actEnds), self::TODAY));
        $this->assertSame('ativa', $repo->status($repo->find($act), self::TODAY));
        $this->assertSame('futura', $repo->status($repo->find($fut), self::TODAY));
        $list = $repo->forViewer((int) $this->patient['id'], $this->medico, self::TODAY);
        $this->assertSame(['Ativa', 'FimHoje', 'Futura', 'Antiga2', 'Antiga'], array_column($list, 'drug_name'));
        $this->assertSame('futura', $list[2]['status']);
        $this->assertSame('2,5 mg', $list[2]['current']['dose']);
        $this->assertSame(['Antiga', 'Antiga2', 'Ativa', 'FimHoje', 'Futura'], $repo->drugNames());
    }

    public function test_end_and_delete_are_audited(): void
    {
        $repo = Container::prescriptions();
        $uid = (int) $this->medico['id'];
        $id = $this->make();
        $this->rejects(fn () => $repo->end($id, '2026-11-19', null, $uid, self::TODAY, $this->r()));
        $this->rejects(fn () => $repo->end($id, '2026-09-30', null, $uid, self::TODAY, $this->r()));
        $repo->end($id, self::TODAY, 'Terminou', $uid, self::TODAY, $this->r());
        $this->assertSame('Terminou', $repo->find($id)['end_reason']);
        $this->assertSame(1, $this->audits('end', 'prescriptions'));
        $fut = $this->make(['start_date' => '2026-12-01']);
        $this->rejects(fn () => $repo->end($fut, self::TODAY, null, $uid, self::TODAY, $this->r()));
        $repo->delete($id, $uid, $this->r());
        $this->assertNotNull($repo->find($id)['deleted_at']);
        $this->assertSame(1, $this->audits('delete', 'prescriptions'));
    }
}
