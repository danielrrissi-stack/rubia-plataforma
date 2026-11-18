<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class AdverseEventPagesTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function post(string $path, array $data = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function n(string $sql, array $params = []): int
    {
        return (int) $this->db->one($sql, $params)['n'];
    }

    /** @return array{0:array,1:array} */
    private function setupDoctor(): array
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => '2015-05-10', 'sex' => 'M', 'full_name' => 'Pedrinho Teste', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        return [$med, $p];
    }

    private function familyReport(array $p, string $text = 'Enjoo depois da dose'): int
    {
        $g = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $p['created_by']]);
        return Container::adverseEvents()->report((int) $p['id'], ['occurred_on' => date('Y-m-d'), 'description' => $text], $g, date('Y-m-d'), new Request('POST', '/x'));
    }

    private function rx(array $p, array $med, string $review = '', string $drug = 'Metformina'): int
    {
        return Container::prescriptions()->create((int) $p['id'], [
            'drug_name' => $drug, 'route' => 'oral', 'start_date' => date('Y-m-d', strtotime('-30 days')),
            'dose' => '500 mg', 'frequency' => '2x', 'instructions' => '', 'family_logs' => 1, 'next_review_date' => $review,
        ], $med, date('Y-m-d'), new Request('POST', '/x'));
    }

    public function test_doctor_registers_event_and_reviews_family_report(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $rx = $this->rx($p, $med);
        $evId = $this->familyReport($p);
        $this->loginAs($med);

        $form = $this->get('/pacientes/' . $pid . '/efeitos/novo');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('Metformina', $form->body);

        $res = $this->post('/pacientes/' . $pid . '/efeitos/novo', [
            'occurred_on' => date('Y-m-d'), 'description' => 'Tontura', 'prescription_id' => (string) $rx,
            'severity' => 'leve', 'conduct' => 'Observar',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $pid . '/tratamento', $res->headers['Location']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM adverse_events WHERE source = 'equipe' AND patient_id = ? AND severity = 'leve'", [$pid]));

        $bad = $this->post('/pacientes/' . $pid . '/efeitos/novo', ['occurred_on' => date('Y-m-d'), 'description' => '']);
        $this->assertSame(422, $bad->status);

        $page = $this->get('/pacientes/' . $pid . '/tratamento');
        $this->assertStringContainsString('/efeitos/' . $evId . '/ciente', $page->body);
        $this->assertStringContainsString('/pacientes/' . $pid . '/efeitos/novo', $page->body);

        $res = $this->post('/efeitos/' . $evId . '/ciente', ['severity' => 'moderada', 'conduct' => 'Ajustar horário']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $pid . '/tratamento', $res->headers['Location']);
        $row = $this->db->one('SELECT * FROM adverse_events WHERE id = ?', [$evId]);
        $this->assertSame((int) $med['id'], (int) $row['reviewed_by']);
        $this->assertSame('moderada', $row['severity']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'review' AND entity = 'adverse_events' AND patient_id = ?", [$pid]));
    }

    public function test_review_twice_is_404(): void
    {
        [$med, $p] = $this->setupDoctor();
        $evId = $this->familyReport($p);
        $this->loginAs($med);
        $this->assertSame(302, $this->post('/efeitos/' . $evId . '/ciente')->status);
        $this->assertSame(404, $this->post('/efeitos/' . $evId . '/ciente')->status);
        $this->assertSame(404, $this->post('/efeitos/999999/ciente')->status);
    }

    public function test_dashboard_lists_unreviewed_and_due_reviews(): void
    {
        [$med, $p] = $this->setupDoctor();
        $evId = $this->familyReport($p, 'Dor de cabeça forte');
        $this->rx($p, $med, date('Y-m-d', strtotime('-3 days')));
        $far = $this->makePatient(['birth_date' => '2015-05-10', 'sex' => 'M', 'full_name' => 'Longe Futuro', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->rx($far, $med, date('Y-m-d', strtotime('+10 days')), 'Zolpidem');
        $this->loginAs($med);

        $body = $this->get('/')->body;
        $this->assertStringContainsString('Relatos da família para ver', $body);
        $this->assertStringContainsString('Dor de cabeça forte', $body);
        $this->assertStringContainsString('Pedrinho Teste', $body);
        $this->assertStringContainsString('Reavaliações', $body);
        $this->assertStringContainsString('vencida', $body);
        $this->assertStringContainsString('Metformina', $body);
        $this->assertStringNotContainsString('Zolpidem', $body);

        $this->post('/efeitos/' . $evId . '/ciente');
        $after = $this->get('/')->body;
        $this->assertStringNotContainsString('Relatos da família para ver', $after);
        $this->assertStringNotContainsString('Dor de cabeça forte', $after);
        $this->assertStringContainsString('Reavaliações', $after);
    }

    public function test_dashboard_sections_hidden_for_nutritionist(): void
    {
        [$med, $p] = $this->setupDoctor();
        $this->familyReport($p);
        $this->rx($p, $med, date('Y-m-d', strtotime('-3 days')));
        $this->loginAs($this->makeUser('nutricionista'));
        $body = $this->get('/')->body;
        $this->assertStringNotContainsString('Relatos da família para ver', $body);
        $this->assertStringNotContainsString('Reavaliações', $body);
    }

    public function test_other_roles_get_403(): void
    {
        [, $p] = $this->setupDoctor();
        $evId = $this->familyReport($p);
        foreach (['nutricionista', 'secretaria', 'responsavel'] as $role) {
            $this->loginAs($this->makeUser($role));
            $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/efeitos/novo')->status, $role);
            $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/efeitos/novo', ['occurred_on' => date('Y-m-d'), 'description' => 'x'])->status, $role);
            $this->assertSame(403, $this->post('/efeitos/' . $evId . '/ciente')->status, $role);
        }
        $this->assertNull($this->db->one('SELECT id FROM adverse_events WHERE reviewed_by IS NOT NULL'));
    }

    public function test_dashboard_uses_social_name_not_legal_name(): void
    {
        [$med, $p] = $this->setupDoctor();
        $this->db->run('UPDATE patients SET social_name = ?, full_name = ? WHERE id = ?', ['Nomesocial Pedro', 'Nomelegal Registrado', $p['id']]);
        $this->familyReport($p);
        $this->rx($p, $med, date('Y-m-d', strtotime('-3 days')));
        $this->loginAs($med);
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Nomesocial Pedro', $body);
        $this->assertStringNotContainsString('Nomelegal Registrado', $body);
    }
}
