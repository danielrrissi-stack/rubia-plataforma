<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class AgendaAccessTest extends DbTestCase
{
    private array $admin;
    private array $medica;
    private array $nutri;
    private array $secretaria;
    private array $responsavel;
    private array $inativa;

    protected function setUp(): void
    {
        parent::setUp();
        // isola a lista de profissionais dos usuarios ja existentes no banco de teste
        $this->db->run("UPDATE users SET active = 0 WHERE role IN ('medico','nutricionista')");
        $this->admin = $this->makeUser('medico', ['is_admin' => 1, 'name' => 'Ana Admin']);
        $this->medica = $this->makeUser('medico', ['name' => 'Beatriz Medica']);
        $this->nutri = $this->makeUser('nutricionista', ['name' => 'Carla Nutri']);
        $this->secretaria = $this->makeUser('secretaria', ['name' => 'Dora Secretaria']);
        $this->responsavel = $this->makeUser('responsavel');
        $this->inativa = $this->makeUser('nutricionista', ['name' => 'Eva Inativa', 'active' => 0]);
    }

    private function ids(array $list): array
    {
        return array_map(fn (array $x): int => (int) $x['id'], $list);
    }

    public function test_professionals_lists_active_staff_by_name(): void
    {
        $list = Container::agendaAccess()->professionals();
        $this->assertSame([(int) $this->admin['id'], (int) $this->medica['id'], (int) $this->nutri['id']], $this->ids($list));
        $this->assertSame(['id', 'name', 'role'], array_keys($list[0]));
        $this->assertSame('nutricionista', $list[2]['role']);
    }

    public function test_access_matrix(): void
    {
        $a = Container::agendaAccess();
        $all = [(int) $this->admin['id'], (int) $this->medica['id'], (int) $this->nutri['id']];
        $this->assertSame($all, $this->ids($a->agendasFor($this->admin)));
        $this->assertSame($all, $this->ids($a->agendasFor($this->secretaria)));
        $this->assertSame([(int) $this->medica['id']], $this->ids($a->agendasFor($this->medica)));
        $this->assertSame([(int) $this->nutri['id']], $this->ids($a->agendasFor($this->nutri)));
        $this->assertSame([], $a->agendasFor($this->responsavel));
        $this->assertSame([], $a->agendasFor(['id' => $this->medica['id'], 'role' => 'medico', 'active' => 0, 'is_admin' => 0]));

        $m = (int) $this->medica['id'];
        $n = (int) $this->nutri['id'];
        $ad = (int) $this->admin['id'];
        $off = (int) $this->inativa['id'];
        // [usuario, profissional, canView, canEditWeek, canAddClosure]
        $cases = [
            [$this->admin, $m, true, true, true],
            [$this->admin, $n, true, true, true],
            [$this->admin, $ad, true, true, true],
            [$this->admin, $off, false, false, false],
            [$this->medica, $m, true, true, true],
            [$this->medica, $n, false, false, false],
            [$this->medica, $ad, false, false, false],
            [$this->nutri, $n, true, true, true],
            [$this->nutri, $m, false, false, false],
            [$this->secretaria, $m, true, false, true],
            [$this->secretaria, $n, true, false, true],
            [$this->secretaria, $off, false, false, false],
            [$this->responsavel, $m, false, false, false],
        ];
        foreach ($cases as $i => [$user, $pid, $view, $week, $closure]) {
            $this->assertSame($view, $a->canView($user, $pid), 'canView #' . $i);
            $this->assertSame($view, $a->canManage($user, $pid), 'canManage #' . $i);
            $this->assertSame($week, $a->canEditWeek($user, $pid), 'canEditWeek #' . $i);
            $this->assertSame($closure, $a->canAddClosure($user, $pid), 'canAddClosure #' . $i);
        }

        $this->assertTrue($a->canEditTexts($this->admin));
        $this->assertFalse($a->canEditTexts($this->medica));
        $this->assertFalse($a->canEditTexts($this->nutri));
        $this->assertFalse($a->canEditTexts($this->makeUser('secretaria', ['is_admin' => 1])));
    }

    public function test_can_manage_existing_covers_inactive_professionals(): void
    {
        $a = Container::agendaAccess();
        $m = (int) $this->medica['id'];
        $n = (int) $this->nutri['id'];
        $off = (int) $this->inativa['id'];
        $secretaria = (int) $this->secretaria['id'];
        // [usuario, profissional, canManageExisting]
        $cases = [
            [$this->secretaria, $m, true],
            [$this->secretaria, $off, true],
            [$this->admin, $off, true],
            [$this->admin, $n, true],
            [$this->medica, $m, true],
            [$this->medica, $n, false],
            [$this->medica, $off, false],
            [$this->nutri, $n, true],
            [$this->nutri, $m, false],
            [$this->inativa, $off, false],
            [$this->responsavel, $m, false],
            [$this->secretaria, $secretaria, false],
            [$this->secretaria, 999999, false],
            [['id' => $this->secretaria['id'], 'role' => 'secretaria', 'active' => 0], $m, false],
        ];
        foreach ($cases as $i => [$user, $pid, $expected]) {
            $this->assertSame($expected, $a->canManageExisting($user, $pid), 'canManageExisting #' . $i);
        }
        $this->assertFalse($a->canManage($this->secretaria, $off));
    }

    public function test_nutritionist_booking_requires_release(): void
    {
        $a = Container::agendaAccess();
        $patient = $this->makePatient(['created_by' => $this->admin['id'], 'responsible_professional_id' => $this->admin['id']]);
        $pid = (int) $patient['id'];
        $n = (int) $this->nutri['id'];
        $msg = 'Peça à médica para liberar este paciente para a nutricionista.';

        $this->assertSame($msg, $a->mayBookPatient($n, $pid, $this->secretaria));
        $this->assertSame($msg, $a->mayBookPatient($n, $pid, $this->admin));
        $this->assertSame('Paciente não encontrado.', $a->mayBookPatient($n, $pid, $this->nutri));
        $this->assertFalse($a->patientVisibleOnAgenda($n, $pid));
        $this->assertNull($a->mayBookPatient((int) $this->medica['id'], $pid, $this->secretaria));
        $this->assertNull($a->mayBookPatient((int) $this->medica['id'], $pid, $this->medica));
        $this->assertTrue($a->patientVisibleOnAgenda((int) $this->medica['id'], $pid));
        $this->assertSame('Paciente não encontrado.', $a->mayBookPatient((int) $this->medica['id'], 999999, $this->secretaria));

        $grant = $this->db->insert('professional_access', ['patient_id' => $pid, 'professional_id' => $n, 'granted_by' => $this->admin['id']]);
        $this->assertNull($a->mayBookPatient($n, $pid, $this->secretaria));
        $this->assertNull($a->mayBookPatient($n, $pid, $this->nutri));
        $this->assertTrue($a->patientVisibleOnAgenda($n, $pid));

        $this->db->run('UPDATE professional_access SET revoked_at = NOW(), revoked_by = ? WHERE id = ?', [$this->admin['id'], $grant]);
        $this->assertFalse($a->patientVisibleOnAgenda($n, $pid));
        $this->assertSame($msg, $a->mayBookPatient($n, $pid, $this->secretaria));
    }
}
