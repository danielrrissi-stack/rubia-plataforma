<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class PortalExamsTest extends DbTestCase
{
    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
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

    private function test(string $code): int
    {
        return (int) Container::labCatalog()->findByCode($code)['id'];
    }

    /** @return array{0:array,1:array,2:array} responsavel, paciente (crianca), medico */
    private function family(): array
    {
        $med = $this->makeUser('medico');
        $g = $this->makeUser('responsavel');
        $p = $this->makePatient(['full_name' => 'Ana Souza', 'birth_date' => '2014-05-10', 'sex' => 'F',
            'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        return [$g, $p, $med];
    }

    private function collection(int $pid, array $values, int $uid, bool $release, string $on = '2026-05-10'): int
    {
        $r = new Request('POST', '/');
        $cid = Container::labs()->create($pid, [
            'collected_on' => $on, 'lab_name' => 'Laboratorio Secreto', 'notes' => 'Observacao secreta da medica',
        ], $values, $uid, $r);
        if ($release) {
            Container::labs()->release($cid, $uid, $r);
        }
        return $cid;
    }

    public function test_family_sees_only_released_with_phrases(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $this->collection($pid, [$this->test('ldl') => '140', $this->test('glicemia') => '90', $this->test('insulina') => '14'], (int) $med['id'], true);
        $this->collection($pid, [$this->test('hdl') => '30'], (int) $med['id'], false, '2026-06-01');

        $this->loginAs($g);
        $res = $this->get('/portal/p/' . $pid . '/exames');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Exames de 10/05/2026', $res->body);
        $this->assertStringContainsString('acima do esperado', $res->body);
        $this->assertStringContainsString('dentro do esperado', $res->body);
        $this->assertStringContainsString('140', $res->body);
        $this->assertStringNotContainsString('01/06/2026', $res->body);
        $this->assertStringNotContainsString('HDL', $res->body);
        $this->assertStringNotContainsString(': 30 ', $res->body);
        $this->assertStringNotContainsString('HOMA', $res->body);
        $this->assertStringNotContainsString('Laboratorio Secreto', $res->body);
        $this->assertStringNotContainsString('Observacao secreta', $res->body);
        $this->assertStringNotContainsStringIgnoringCase('laudo', $res->body);
        $this->assertStringNotContainsString('Ainda não há exames liberados', $res->body);

        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) n FROM audit_log WHERE user_id = ? AND action = 'view' AND entity = 'lab_collections'
             AND entity_id IS NULL AND patient_id = ? AND JSON_EXTRACT(after_json, '$.portal') = true
             AND JSON_UNQUOTE(JSON_EXTRACT(after_json, '$.section')) = 'exames'",
            [$g['id'], $pid],
        )['n']);

        $this->assertStringContainsString('/portal/p/' . $pid . '/exames', $this->get('/portal/p/' . $pid)->body);
    }

    public function test_no_released_collection_shows_message_and_hides_home_link(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $this->collection($pid, [$this->test('ldl') => '140'], (int) $med['id'], false);
        $this->loginAs($g);
        $res = $this->get('/portal/p/' . $pid . '/exames');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Ainda não há exames liberados pela Dra. Rubia.', $res->body);
        $this->assertStringNotContainsString('140', $res->body);
        $this->assertStringNotContainsString('/portal/p/' . $pid . '/exames', $this->get('/portal/p/' . $pid)->body);
    }

    public function test_adult_patient_sees_own_and_not_others(): void
    {
        $med = $this->makeUser('medico');
        $u = $this->makeUser('paciente');
        $p = $this->makePatient(['birth_date' => '1985-02-03', 'sex' => 'M', 'user_id' => $u['id'], 'created_by' => $med['id']]);
        $other = $this->makePatient(['birth_date' => '1990-01-01']);
        $this->collection((int) $p['id'], [$this->test('glicemia') => '90'], (int) $med['id'], true);
        $this->loginAs($u);
        $res = $this->get('/portal/p/' . $p['id'] . '/exames');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('dentro do esperado', $res->body);
        $this->assertSame(403, $this->get('/portal/p/' . $other['id'] . '/exames')->status);
    }

    public function test_child_role_sees_nothing(): void
    {
        [, $p, $med] = $this->family();
        $this->collection((int) $p['id'], [$this->test('ldl') => '140'], (int) $med['id'], true);
        $this->loginAs($this->makeUser('crianca'));
        $food = $this->get('/portal/p/' . $p['id'] . '/alimentacao');
        $res = $this->get('/portal/p/' . $p['id'] . '/exames');
        $this->assertSame($food->status, $res->status);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Acesso ainda não disponível', $res->body);
        $this->assertStringNotContainsString('140', $res->body);
        $this->assertStringNotContainsString('esperado', $res->body);
    }

    public function test_staff_is_sent_away_from_portal_exams(): void
    {
        [, $p, $med] = $this->family();
        $this->loginAs($med);
        $res = $this->get('/portal/p/' . $p['id'] . '/exames');
        $this->assertSame(302, $res->status);
    }

    public function test_family_gets_403_on_every_staff_exam_route(): void
    {
        [$g, $child, $med] = $this->family();
        $u = $this->makeUser('paciente');
        $adult = $this->makePatient(['birth_date' => '1985-02-03', 'sex' => 'M', 'user_id' => $u['id'], 'created_by' => $med['id']]);
        $ldl = $this->test('ldl');
        foreach ([[$g, $child], [$u, $adult]] as [$user, $patient]) {
            $pid = (int) $patient['id'];
            $cid = $this->collection($pid, [$this->test('glicemia') => '90'], (int) $med['id'], false);
            $aid = $this->db->insert('lab_attachments', [
                'collection_id' => $cid, 'stored_path' => 'exames/teste/inexistente.pdf', 'original_name' => 'laudo.pdf',
                'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'uploaded_by' => $med['id'],
            ]);
            $this->loginAs($user);
            // Com 'view EXAMES' a familia passaria no Gate: quem barra e o Clinician::assert.
            $this->assertTrue(Container::policy()->allows($user, 'view', \App\Access\Policy::EXAMES));
            $role = $user['role'];
            $gets = [
                ['/pacientes/' . $pid . '/exames', []],
                ['/pacientes/' . $pid . '/exames/nova', []],
                ['/exames/' . $cid, []],
                ['/exames/' . $cid . '/editar', []],
                ['/exames/anexos/' . $aid, []],
                ['/pacientes/' . $pid . '/exames/serie.json', ['exame' => (string) $this->test('glicemia')]],
                ['/catalogo-exames', []],
                ['/catalogo-exames/novo', []],
                ['/catalogo-exames/' . $ldl . '/editar', []],
            ];
            foreach ($gets as [$path, $query]) {
                $this->assertSame(403, $this->get($path, $query)->status, "$role GET $path");
            }
            $posts = [
                '/pacientes/' . $pid . '/exames/nova',
                '/exames/' . $cid . '/editar',
                '/exames/' . $cid . '/excluir',
                '/exames/' . $cid . '/liberar',
                '/exames/' . $cid . '/recolher',
                '/exames/' . $cid . '/anexos',
                '/exames/anexos/' . $aid . '/excluir',
                '/catalogo-exames/novo',
                '/catalogo-exames/' . $ldl . '/editar',
                '/catalogo-exames/' . $ldl . '/confirmar',
            ];
            foreach ($posts as $path) {
                $this->assertSame(403, $this->post($path, ['coletado_em' => '2026-05-10', 'valor' => []])->status, "$role POST $path");
            }
            $row = $this->db->one('SELECT released_at, deleted_at FROM lab_collections WHERE id = ?', [$cid]);
            $this->assertNull($row['released_at']);
            $this->assertNull($row['deleted_at']);
            $this->assertNotNull($this->db->one('SELECT id FROM lab_attachments WHERE id = ? AND deleted_at IS NULL', [$aid]));
            $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM lab_collections WHERE patient_id = ?', [$pid])['n'] - 1);
        }
    }
}
