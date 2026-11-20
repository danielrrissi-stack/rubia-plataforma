<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class CertificatePagesTest extends DbTestCase
{
    private array $med;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->med = $this->makeUser('medico', ['name' => 'Dra. Rúbia Teste', 'professional_register' => 'CRM-SP 1', 'specialty' => 'Pediatra', 'rqe' => '2']);
        $this->patient = $this->makePatient(['full_name' => 'Lia Souza', 'sex' => 'F', 'cpf' => '12345678901', 'birth_date' => '2017-03-10',
            'responsible_professional_id' => $this->med['id'], 'created_by' => $this->med['id']]);
    }

    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function base(): string
    {
        return '/pacientes/' . $this->patient['id'] . '/atestados';
    }

    private function visit(array $over = []): array
    {
        return $over + ['tipo' => 'comparecimento', 'data' => date('Y-m-d'), 'inicio' => '08:30', 'fim' => '09:00'];
    }

    private function stored(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM certificates')['n'];
    }

    private function issue(): int
    {
        $this->loginAs($this->med);
        $res = $this->post($this->base(), $this->visit());
        $this->assertSame(302, $res->status);
        return (int) $this->db->one('SELECT MAX(id) id FROM certificates')['id'];
    }

    public function test_doctor_issues_comparecimento(): void
    {
        $this->loginAs($this->med);
        $this->assertSame(200, $this->get($this->base() . '/novo')->status);
        $form = $this->get($this->base() . '/novo', ['tipo' => 'comparecimento']);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="inicio"', $form->body);
        $res = $this->post($this->base(), $this->visit());
        $this->assertSame(302, $res->status);
        $c = $this->db->one('SELECT * FROM certificates ORDER BY id DESC LIMIT 1');
        $this->assertSame((int) $this->patient['id'], (int) $c['patient_id']);
        $this->assertSame((int) $this->med['id'], (int) $c['author_id']);
        $this->assertStringContainsString('<strong>Lia Souza</strong>', $c['body_html']);
        $this->assertStringContainsString('/atestados/' . $c['id'], $res->headers['Location']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE entity = 'certificates' AND action = 'create'")['n']);
        $show = $this->get($this->base() . '/' . $c['id']);
        $this->assertSame(200, $show->status);
        $this->assertStringContainsString('DECLARAÇÃO DE COMPARECIMENTO', $show->body);
        $list = $this->get($this->base());
        $this->assertStringContainsString('Declaração de comparecimento', $list->body);
    }

    public function test_cid_error_saves_nothing(): void
    {
        $this->loginAs($this->med);
        $res = $this->post($this->base(), $this->visit(['cid' => 'E66.9']));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Marque a autorização', $res->body);
        $this->assertStringContainsString('value="E66.9"', $res->body);
        $this->assertSame(0, $this->stored());
    }

    public function test_companion_prefilled_with_guardian(): void
    {
        $resp = $this->makeUser('responsavel', ['name' => 'Ana Souza']);
        $this->db->insert('guardian_links', ['patient_id' => $this->patient['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $this->med['id']]);
        $this->loginAs($this->med);
        $form = $this->get($this->base() . '/novo', ['tipo' => 'acompanhante']);
        $this->assertStringContainsString('value="Ana Souza"', $form->body);
    }

    public function test_other_roles_denied(): void
    {
        $id = $this->issue();
        foreach (['nutricionista', 'secretaria'] as $role) {
            $u = $this->makeUser($role);
            $this->loginAs($u);
            foreach ([$this->base(), $this->base() . '/novo', $this->base() . '/' . $id, $this->base() . '/' . $id . '/pdf'] as $path) {
                $this->assertSame(403, $this->get($path)->status, $role . ' ' . $path);
            }
            $this->assertSame(403, $this->post($this->base(), $this->visit())->status, $role);
            $this->assertSame(403, $this->post($this->base() . '/' . $id . '/anular', ['motivo' => 'x'])->status, $role);
        }
        $this->assertSame(1, $this->stored());
    }

    public function test_certificate_of_other_patient_is_404(): void
    {
        $id = $this->issue();
        $other = $this->makePatient(['responsible_professional_id' => $this->med['id'], 'created_by' => $this->med['id']]);
        $this->assertSame(404, $this->get('/pacientes/' . $other['id'] . '/atestados/' . $id)->status);
        $this->assertSame(404, $this->get('/pacientes/' . $other['id'] . '/atestados/' . $id . '/pdf')->status);
        $this->assertSame(404, $this->post('/pacientes/' . $other['id'] . '/atestados/' . $id . '/anular', ['motivo' => 'x'])->status);
    }

    public function test_pdf_download(): void
    {
        $id = $this->issue();
        $pdf = $this->get($this->base() . '/' . $id . '/pdf');
        $this->assertSame(200, $pdf->status);
        $this->assertStringStartsWith('%PDF', $pdf->body);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE entity = 'certificates' AND action = 'view'")['n']);
    }

    public function test_void_once(): void
    {
        $id = $this->issue();
        $this->assertSame(302, $this->post($this->base() . '/' . $id . '/anular', ['motivo' => 'Data errada'])->status);
        $show = $this->get($this->base() . '/' . $id);
        $this->assertStringContainsString('Anulado', $show->body);
        $this->assertStringContainsString('Data errada', $show->body);
        $this->assertStringNotContainsString('name="motivo"', $show->body);
        $again = $this->post($this->base() . '/' . $id . '/anular', ['motivo' => 'De novo']);
        $this->assertSame(422, $again->status);
        $this->assertSame('Data errada', $this->db->one('SELECT void_reason FROM certificates WHERE id = ?', [$id])['void_reason']);
        $this->assertStringStartsWith('%PDF', $this->get($this->base() . '/' . $id . '/pdf')->body);
    }

    public function test_patient_page_card_only_for_doctor(): void
    {
        $this->issue();
        $page = $this->get('/pacientes/' . $this->patient['id']);
        $this->assertStringContainsString('id="cartao-atestados"', $page->body);
        $this->assertStringContainsString('Novo atestado', $page->body);
        $this->loginAs($this->makeUser('secretaria'));
        $this->assertStringNotContainsString('id="cartao-atestados"', $this->get('/pacientes/' . $this->patient['id'])->body);
    }
}
