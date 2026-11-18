<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Agenda\AgendaCards;
use App\Core\App;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class AgendaCardsTest extends DbTestCase
{
    private array $doc;
    private array $nutri;
    private array $sec;
    private array $resp;
    private array $patient;
    private int $pid;
    private int $docAppt;
    private int $nutriAppt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = $this->makeUser('medico', ['name' => 'Dra. Comum', 'totp_enabled' => 1]);
        $this->nutri = $this->makeUser('nutricionista', ['name' => 'Nutri Teste', 'totp_enabled' => 1]);
        $this->sec = $this->makeUser('secretaria', ['name' => 'Secretaria Teste', 'totp_enabled' => 1]);
        $this->resp = $this->makeUser('responsavel');
        $this->patient = $this->makePatient(['full_name' => 'Ana Souza', 'birth_date' => (date('Y') - 8) . '-03-10', 'responsible_professional_id' => $this->doc['id'], 'created_by' => $this->doc['id']]);
        $this->pid = (int) $this->patient['id'];
        $this->db->insert('guardian_links', ['patient_id' => $this->pid, 'user_id' => $this->resp['id'], 'relationship' => 'mãe', 'created_by' => $this->doc['id']]);
        Container::accessGrants()->grant($this->pid, (int) $this->nutri['id'], (int) $this->doc['id']);
        foreach ([$this->doc, $this->nutri] as $p) {
            Container::schedules()->applyClinicDefault((int) $p['id'], (int) $p['id'], new Request('POST', '/'));
        }
        $mon = date('Y-m-d', strtotime('next monday'));
        $this->docAppt = $this->insertAppt($this->doc, $mon . ' 08:00:00', 'agendada', 'NOTA-SECRETA-X', null);
        $this->nutriAppt = $this->insertAppt($this->nutri, $mon . ' 09:00:00', 'confirmada', null, null);
        $this->insertAppt($this->doc, date('Y-m-d', strtotime('last monday')) . ' 08:00:00', 'cancelada', null, 'MOTIVO-SECRETO-Y');
    }

    private function insertAppt(array $prof, string $at, string $status, ?string $note, ?string $reason): int
    {
        return $this->db->insert('appointments', [
            'professional_id' => $prof['id'], 'patient_id' => $this->pid, 'starts_at' => $at,
            'ends_at' => date('Y-m-d H:i:s', strtotime($at) + 1800), 'kind' => 'primeira', 'status' => $status,
            'note' => $note, 'cancel_reason' => $reason, 'created_by' => $this->sec['id'],
        ]);
    }

    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    public function test_staff_card_by_role(): void
    {
        $this->loginAs($this->nutri);
        $body = $this->get('/pacientes/' . $this->pid)->body;
        $this->assertStringContainsString('id="cartao-consultas"', $body);
        $this->assertStringContainsString('Nutri Teste', $body);
        $this->assertStringNotContainsString('Dra. Comum — ', $body, 'nutricionista so ve as dela');
        $this->assertStringContainsString('/pacientes/' . $this->pid . '/agendar', $body);
        $this->assertStringContainsString('/agenda/consultas/' . $this->nutriAppt, $body);

        $this->loginAs($this->sec);
        $body = $this->get('/pacientes/' . $this->pid)->body;
        $this->assertStringContainsString('Nutri Teste', $body);
        $this->assertStringContainsString('Dra. Comum', $body);
        $this->assertStringContainsString('Agendar', $body);
        $this->assertStringContainsString('/agenda/consultas/' . $this->docAppt, $body);
        $this->assertStringNotContainsString('NOTA-SECRETA-X', $body);
    }

    public function test_secretary_agendar_uses_first_bookable_agenda(): void
    {
        // primeira agenda por nome = nutricionista sem liberacao; o botao continua, apontando para a medica
        $this->db->run("UPDATE users SET active = 0 WHERE role IN ('medico','nutricionista') AND id NOT IN (?, ?)", [$this->doc['id'], $this->nutri['id']]);
        $first = $this->makeUser('nutricionista', ['name' => 'Aaa Nutri Sem Liberacao', 'totp_enabled' => 1]);
        Container::schedules()->applyClinicDefault((int) $first['id'], (int) $first['id'], new Request('POST', '/'));
        $this->assertSame((int) $this->doc['id'], Container::agendaAccess()->firstBookableAgenda($this->sec, $this->pid));

        $this->loginAs($this->sec);
        $body = $this->get('/pacientes/' . $this->pid)->body;
        $this->assertStringContainsString('/pacientes/' . $this->pid . '/agendar?prof=' . $this->doc['id'], $body);
        $page = $this->get('/pacientes/' . $this->pid . '/agendar');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Próximos horários livres — Dra. Comum', $page->body, 'sem ?prof abre a mesma agenda do botao');
        $this->assertStringNotContainsString('Peça à médica', $page->body);
    }

    public function test_nutritionist_without_release_has_no_card(): void
    {
        $other = $this->makeUser('nutricionista', ['totp_enabled' => 1]);
        $this->assertNull(AgendaCards::forStaff($other, $this->pid, date('Y-m-d H:i')));
    }

    public function test_portal_card_for_family(): void
    {
        $this->loginAs($this->resp);
        $body = $this->get('/portal/p/' . $this->pid)->body;
        $this->assertStringContainsString('id="portal-consultas"', $body);
        $this->assertStringContainsString('Dra. Comum', $body);
        $this->assertStringContainsString('Confirmada', $body);
        $this->assertStringContainsString('Cancelada', $body);
        $this->assertStringNotContainsString('NOTA-SECRETA-X', $body);
        $this->assertStringNotContainsString('MOTIVO-SECRETO-Y', $body);
        $this->assertStringNotContainsString('/agenda/consultas', $body);

        $this->loginAs($this->makeUser('responsavel'));
        $this->assertSame(403, $this->get('/portal/p/' . $this->pid)->status, 'outra familia nao ve');
    }

    public function test_portal_empty_state(): void
    {
        $this->db->run('DELETE FROM appointments');
        $this->loginAs($this->resp);
        $this->assertStringContainsString('Nenhuma consulta marcada.', $this->get('/portal/p/' . $this->pid)->body);
    }

    public function test_child_account_has_no_card(): void
    {
        $kid = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$kid['id'], $this->pid]);
        $this->assertNull(AgendaCards::forFamily($kid, $this->pid, date('Y-m-d H:i')));
        $this->assertNull(AgendaCards::forStaff($kid, $this->pid, date('Y-m-d H:i')));
    }
}
