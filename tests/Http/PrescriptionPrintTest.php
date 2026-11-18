<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class PrescriptionPrintTest extends DbTestCase
{
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

    private function n(string $sql, array $params = []): int
    {
        return (int) $this->db->one($sql, $params)['n'];
    }

    /** @return array{0:array,1:array} */
    private function setupDoctor(): array
    {
        $med = $this->makeUser('medico', ['name' => 'Dra. Rubia Teste', 'professional_register' => '12345-SP']);
        $p = $this->makePatient(['birth_date' => '2015-05-10', 'sex' => 'M', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->loginAs($med);
        return [$med, $p];
    }

    private function rx(int $pid, array $med, string $drug, string $route, array $over = []): int
    {
        return Container::prescriptions()->create($pid, $over + [
            'drug_name' => $drug, 'route' => $route, 'start_date' => date('Y-m-d', strtotime('-5 days')),
            'dose' => '10 mg', 'frequency' => '1 vez ao dia', 'instructions' => 'Orientacao de ' . $drug,
        ], $med, date('Y-m-d'), new Request('POST', '/'));
    }

    private function setting(string $k, string $v): void
    {
        $this->db->run('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, $v]);
    }

    public function test_print_groups_by_route_numbers_items_and_shows_header(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $this->setting('rx_clinic_name', 'Consultorio Rubia');
        $this->setting('rx_city', 'Campinas');
        $a = $this->rx($pid, $med, 'Sema', 'subcutanea');
        $b = $this->rx($pid, $med, 'Metformina', 'oral');
        $c = $this->rx($pid, $med, 'Vitamina D', 'oral');

        $choose = $this->get('/pacientes/' . $pid . '/receita');
        $this->assertSame(200, $choose->status);
        $this->assertStringContainsString('name="p[]"', $choose->body);
        $this->assertStringContainsString('Sema', $choose->body);

        $res = $this->get('/pacientes/' . $pid . '/receita/imprimir', ['p' => [(string) $a, (string) $b, (string) $c]]);
        $this->assertSame(200, $res->status);
        $body = $res->body;
        $this->assertStringContainsString('Consultorio Rubia', $body);
        $this->assertStringContainsString('Receituário', $body);
        $this->assertStringContainsString('Paciente:', $body);
        $this->assertLessThan(strpos($body, 'Uso subcutâneo'), strpos($body, 'Uso oral'));
        $this->assertStringContainsString('1. ', $body);
        $this->assertStringContainsString('2. ', $body);
        $this->assertStringContainsString('3. Sema — 10 mg', $body);
        $this->assertLessThan(strpos($body, '3. Sema'), strpos($body, '1. Metformina'));
        $this->assertStringContainsString('Orientacao de Sema', $body);
        $this->assertStringContainsString('CRM 12345-SP', $body);
        $this->assertStringContainsString('Dra. Rubia Teste', $body);
        $this->assertStringContainsString('Campinas, ', $body);
        $this->assertMatchesRegularExpression('/\d{1,2} de [a-zç]+ de \d{4}/u', $body);
    }

    public function test_print_hides_internal_notes_and_ignores_foreign_or_ended_ids(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $other = $this->makePatient(['birth_date' => '2015-05-10', 'sex' => 'F']);
        $ok = $this->rx($pid, $med, 'Droga Valida', 'oral', ['notes' => 'NOTA INTERNA SECRETA']);
        $ended = $this->rx($pid, $med, 'Droga Encerrada', 'oral', ['start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('-2 days'))]);
        $deleted = $this->rx($pid, $med, 'Droga Excluida', 'oral');
        $this->db->run('UPDATE prescriptions SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$med['id'], $deleted]);
        $foreign = $this->rx((int) $other['id'], $med, 'Droga Alheia', 'oral');

        $res = $this->get('/pacientes/' . $pid . '/receita/imprimir', ['p' => [(string) $ok, (string) $ended, (string) $deleted, (string) $foreign, 'abc']]);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Droga Valida', $res->body);
        $this->assertStringNotContainsString('NOTA INTERNA SECRETA', $res->body);
        foreach (['Droga Encerrada', 'Droga Excluida', 'Droga Alheia'] as $x) {
            $this->assertStringNotContainsString($x, $res->body);
        }

        $none = $this->get('/pacientes/' . $pid . '/receita/imprimir', ['p' => [(string) $ended]]);
        $this->assertSame(302, $none->status);
        $this->assertSame('/pacientes/' . $pid . '/receita', $none->headers['Location']);
        $this->assertSame(302, $this->get('/pacientes/' . $pid . '/receita/imprimir')->status);
    }

    public function test_print_is_audited(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $a = $this->rx($pid, $med, 'Metformina', 'oral');
        $this->get('/pacientes/' . $pid . '/receita/imprimir', ['p' => [(string) $a]]);
        $row = $this->db->one("SELECT after_json FROM audit_log WHERE action = 'view' AND entity = 'prescriptions' AND patient_id = ? AND after_json LIKE '%print%'", [$pid]);
        $this->assertNotNull($row);
        $after = json_decode((string) $row['after_json'], true);
        $this->assertTrue($after['print']);
        $this->assertSame('medicamentos', $after['section']);
        $this->assertSame([$a], array_map('intval', $after['ids']));
    }

    public function test_non_doctor_cannot_print(): void
    {
        [, $p] = $this->setupDoctor();
        $nutri = $this->makeUser('nutricionista', ['totp_enabled' => 1]);
        $this->loginAs($nutri);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/receita')->status);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/receita/imprimir', ['p' => ['1']])->status);
    }

    public function test_settings_admin_only_and_audited(): void
    {
        $this->setupDoctor();
        $this->assertSame(403, $this->get('/admin/receita')->status);
        $this->assertSame(403, $this->post('/admin/receita', ['rx_clinic_name' => 'X'])->status);

        $admin = $this->makeUser('medico', ['is_admin' => 1, 'totp_enabled' => 1]);
        $this->loginAs($admin);
        $this->assertSame(200, $this->get('/admin/receita')->status);
        $res = $this->post('/admin/receita', [
            'rx_clinic_name' => 'Clinica Nova', 'rx_address' => 'Rua A, 10', 'rx_phone' => '(19) 3333-4444', 'rx_city' => 'Campinas',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/admin/receita', $res->headers['Location']);
        $this->assertSame('Clinica Nova', $this->db->one("SELECT value FROM settings WHERE `key` = 'rx_clinic_name'")['value']);
        $this->post('/admin/receita', ['rx_clinic_name' => 'Clinica Nova 2', 'rx_address' => '', 'rx_phone' => '', 'rx_city' => '']);
        $this->assertSame('Clinica Nova 2', $this->db->one("SELECT value FROM settings WHERE `key` = 'rx_clinic_name'")['value']);
        $this->assertSame(2, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'update' AND entity = 'settings'"));
        $row = $this->db->one("SELECT before_json, after_json FROM audit_log WHERE action = 'update' AND entity = 'settings' ORDER BY id DESC LIMIT 1");
        $this->assertSame('Clinica Nova', json_decode((string) $row['before_json'], true)['rx_clinic_name']);
        $this->assertSame('Clinica Nova 2', json_decode((string) $row['after_json'], true)['rx_clinic_name']);
        $this->assertArrayHasKey('rx_city', json_decode((string) $row['after_json'], true));
    }

    public function test_settings_rejects_too_long_values(): void
    {
        $admin = $this->makeUser('medico', ['is_admin' => 1, 'totp_enabled' => 1]);
        $this->loginAs($admin);
        $res = $this->post('/admin/receita', ['rx_clinic_name' => str_repeat('a', 121), 'rx_address' => '', 'rx_phone' => '', 'rx_city' => '']);
        $this->assertSame(422, $res->status);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM settings WHERE `key` = 'rx_clinic_name'"));
    }
}
