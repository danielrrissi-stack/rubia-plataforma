<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class LetterheadTest extends DbTestCase
{
    private const TITLES = ['receita' => 'Receita', 'exames' => 'Pedido de Exames', 'anotacoes' => 'Anotações'];

    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function doctorWithPatient(array $over = []): array
    {
        $med = $this->makeUser('medico', ['name' => 'Dra. Rubia Teste']);
        $p = $this->makePatient(['responsible_professional_id' => $med['id'], 'created_by' => $med['id']] + $over);
        $this->loginAs($med);
        return $p;
    }

    public function test_patient_papers_open_inline_as_pdf(): void
    {
        $p = $this->doctorWithPatient(['full_name' => 'Ana Paciente']);
        foreach (self::TITLES as $slug => $title) {
            $res = $this->get('/pacientes/' . $p['id'] . '/papel/' . $slug);
            $this->assertSame(200, $res->status, $slug);
            $this->assertStringStartsWith('%PDF-', $res->body);
            $this->assertSame('application/pdf', $res->headers['Content-Type']);
            $this->assertSame('no-store', $res->headers['Cache-Control']);
            $this->assertStringStartsWith('inline;', $res->headers['Content-Disposition']);
            $this->assertStringContainsString(rawurlencode($title . ' - Ana Paciente - ' . date('d-m-Y') . '.pdf'), $res->headers['Content-Disposition']);
        }
    }

    public function test_invalid_type_is_404(): void
    {
        $p = $this->doctorWithPatient();
        $this->assertSame(404, $this->get('/pacientes/' . $p['id'] . '/papel/outro')->status);
        $this->loginAs($this->makeUser('medico', ['is_admin' => 1]));
        $this->assertSame(404, $this->get('/admin/receita/papel/outro')->status);
    }

    public function test_patient_paper_is_doctor_only_and_audited(): void
    {
        $p = $this->doctorWithPatient();
        $this->get('/pacientes/' . $p['id'] . '/papel/exames');
        $row = $this->db->one("SELECT after_json FROM audit_log WHERE action = 'view' AND entity = 'patients' AND patient_id = ? AND after_json LIKE '%papel_timbrado%'", [$p['id']]);
        $this->assertNotNull($row);
        $this->assertSame(['section' => 'papel_timbrado', 'tipo' => 'exames'], json_decode((string) $row['after_json'], true));
        foreach (['nutricionista', 'secretaria'] as $role) {
            $this->loginAs($this->makeUser($role));
            $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/papel/receita')->status, $role);
        }
    }

    public function test_blank_paper_is_admin_only(): void
    {
        $this->doctorWithPatient();
        $this->assertSame(403, $this->get('/admin/receita/papel/receita')->status);
        $this->loginAs($this->makeUser('medico', ['is_admin' => 1]));
        foreach (array_keys(self::TITLES) as $slug) {
            $res = $this->get('/admin/receita/papel/' . $slug);
            $this->assertSame(200, $res->status);
            $this->assertStringStartsWith('%PDF-', $res->body);
            $this->assertStringStartsWith('inline;', $res->headers['Content-Disposition']);
        }
    }

    public function test_buttons_come_before_the_header_form(): void
    {
        $this->loginAs($this->makeUser('medico', ['is_admin' => 1]));
        $body = $this->get('/admin/receita')->body;
        $form = strpos($body, 'action="' . url('/admin/receita') . '"');
        $this->assertNotFalse($form);
        foreach (self::TITLES as $slug => $title) {
            $pos = strpos($body, 'href="' . url('/admin/receita/papel/' . $slug) . '" target="_blank" rel="noopener">' . $title . '</a>');
            $this->assertNotFalse($pos, $slug);
            $this->assertLessThan($form, $pos);
        }
    }

    public function test_buttons_close_the_patient_page_for_the_doctor_only(): void
    {
        $p = $this->doctorWithPatient();
        $path = '/pacientes/' . $p['id'];
        $body = $this->get($path)->body;
        $last = (int) strrpos($body, 'id="cartao-');
        foreach (array_keys(self::TITLES) as $slug) {
            $pos = strpos($body, 'href="' . url($path . '/papel/' . $slug) . '" target="_blank"');
            $this->assertNotFalse($pos, $slug);
            $this->assertGreaterThan($last, $pos);
            $last = $pos;
        }
        $this->loginAs($this->makeUser('secretaria'));
        $other = $this->get($path);
        $this->assertSame(200, $other->status);
        $this->assertStringNotContainsString('/papel/', $other->body);
    }
}
