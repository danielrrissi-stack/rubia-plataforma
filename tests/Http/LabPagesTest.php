<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Labs\AttachmentStore;
use Tests\DbTestCase;

final class LabPagesTest extends DbTestCase
{
    private const PDF = "%PDF-1.4\n%%EOF";

    private string $storage;
    private string $uploads;
    private int $glic;
    private int $ldl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/rubia_labpages_' . bin2hex(random_bytes(4));
        $this->uploads = $this->storage . '/uploads';
        mkdir($this->uploads, 0777, true);
        mkdir($this->storage . '/incoming', 0777, true);
        // Em producao o store recusa arquivos que nao vieram de upload HTTP; aqui aceitamos os arquivos do teste.
        Container::set('labAttachments', new AttachmentStore($this->db, Container::audit(), $this->uploads, true));
        $this->glic = (int) Container::labCatalog()->findByCode('glicemia')['id'];
        $this->ldl = (int) Container::labCatalog()->findByCode('ldl')['id'];
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->storage);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$d/$f") ? $this->rrmdir("$d/$f") : @unlink("$d/$f");
        }
        @rmdir($d);
    }

    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data, array $files = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, $files, ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    /** @return array{0:array,1:array} medico logado e paciente */
    private function setupDoctor(): array
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->loginAs($med);
        return [$med, $p];
    }

    /** Campo laudos[] no formato multi-arquivo do PHP. */
    private function laudos(string $name = 'laudo.pdf', string $content = self::PDF): array
    {
        $tmp = $this->storage . '/incoming/' . bin2hex(random_bytes(6));
        file_put_contents($tmp, $content);
        return ['laudos' => [
            'name' => [$name], 'type' => ['application/pdf'], 'tmp_name' => [$tmp],
            'error' => [UPLOAD_ERR_OK], 'size' => [strlen($content)],
        ]];
    }

    private function newCollection(int $pid, array $values, array $files = []): Response
    {
        return $this->post('/pacientes/' . $pid . '/exames/nova', [
            'coletado_em' => '2026-05-10', 'laboratorio' => 'Lab Teste', 'observacao' => '', 'valor' => $values,
        ], $files);
    }

    private function idFrom(Response $res): int
    {
        $this->assertSame(302, $res->status);
        $this->assertMatchesRegularExpression('#^/exames/(\d+)$#', $res->headers['Location']);
        return (int) preg_replace('#\D#', '', $res->headers['Location']);
    }

    private function n(string $sql, array $params = []): int
    {
        return (int) $this->db->one($sql, $params)['n'];
    }

    public function test_doctor_creates_collection_and_sees_evolution(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $this->assertSame(200, $this->get('/pacientes/' . $pid . '/exames/nova')->status);
        $cid = $this->idFrom($this->newCollection($pid, [$this->glic => '98', $this->ldl => '140'], $this->laudos()));
        $this->assertSame(1, $this->n('SELECT COUNT(*) n FROM lab_attachments WHERE collection_id = ?', [$cid]));

        $res = $this->get('/pacientes/' . $pid . '/exames');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('LDL-colesterol', $res->body);
        $this->assertStringContainsString('140', $res->body);
        $this->assertStringContainsString('<span class="lab-valor">140</span> <span class="lab-marca" aria-hidden="true">▲</span><span class="sr-only">alterado (alto)</span>', $res->body);
        $this->assertSame(1, $this->n(
            "SELECT COUNT(*) n FROM audit_log WHERE entity = 'lab_collections' AND action = 'view' AND patient_id = ?", [$pid],
        ));

        $show = $this->get('/exames/' . $cid);
        $this->assertSame(200, $show->status);
        $this->assertStringContainsString('laudo.pdf', $show->body);
        $this->assertStringContainsString('Lab Teste', $show->body);

        // Editar re-mostra os valores gravados e grava a alteracao.
        $edit = $this->get('/exames/' . $cid . '/editar');
        $this->assertSame(200, $edit->status);
        $this->assertStringContainsString('value="140"', $edit->body);
        $res = $this->post('/exames/' . $cid . '/editar', [
            'coletado_em' => '2026-05-10', 'laboratorio' => 'Lab Teste', 'observacao' => 'ok', 'valor' => [$this->glic => '99'],
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/exames/' . $cid, $res->headers['Location']);
        $this->assertSame(1, $this->n('SELECT COUNT(*) n FROM lab_results WHERE collection_id = ?', [$cid]));
    }

    public function test_validation_keeps_values_and_asks_files_again(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $res = $this->newCollection($pid, [$this->glic => '98', $this->ldl => '12abc'], $this->laudos());
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('LDL-colesterol: valor inválido.', $res->body);
        $this->assertMatchesRegularExpression('#name="valor\[' . $this->glic . '\]"[^>]*value="98"#', $res->body);
        $this->assertStringContainsString('Escolha os arquivos do laudo de novo.', $res->body);
        $this->assertSame(0, $this->n('SELECT COUNT(*) n FROM lab_collections WHERE patient_id = ?', [$pid]));
    }

    /**
     * Fora da transacao do teste (que tornaria a do controller apenas interna): o laudo invalido desfaz a coleta de verdade.
     * Os registros criados sao apagados no fim.
     */
    public function test_bad_attachment_rolls_back_collection(): void
    {
        $this->db->pdo()->rollBack();
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $pid = (int) $p['id'];
        try {
            $this->loginAs($med);
            $res = $this->newCollection($pid, [$this->glic => '98'], $this->laudos('laudo.pdf', '<?php echo 1;'));
            $this->assertSame(200, $res->status);
            $this->assertStringContainsString('Envie o laudo em PDF, JPG ou PNG.', $res->body);
            $this->assertStringContainsString('Escolha os arquivos do laudo de novo.', $res->body);
            $this->assertSame(0, $this->n('SELECT COUNT(*) n FROM lab_collections WHERE patient_id = ?', [$pid]));
            $this->assertSame(0, $this->n(
                "SELECT COUNT(*) n FROM audit_log WHERE entity = 'lab_collections' AND action = 'create' AND patient_id = ?", [$pid],
            ));
        } finally {
            $this->db->run('DELETE r FROM lab_results r JOIN lab_collections c ON c.id = r.collection_id WHERE c.patient_id = ?', [$pid]);
            $this->db->run('DELETE a FROM lab_attachments a JOIN lab_collections c ON c.id = a.collection_id WHERE c.patient_id = ?', [$pid]);
            $this->db->run('DELETE FROM lab_collections WHERE patient_id = ?', [$pid]);
            $this->db->run('DELETE FROM audit_log WHERE patient_id = ? OR user_id = ?', [$pid, $med['id']]);
            $this->db->run('DELETE FROM patients WHERE id = ?', [$pid]);
            $this->db->run('DELETE FROM users WHERE id = ?', [$med['id']]);
            $this->db->pdo()->beginTransaction();
        }
    }

    public function test_nutritionist_and_secretary_get_403(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $cid = $this->idFrom($this->newCollection($pid, [$this->glic => '98']));
        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $sec = $this->makeUser('secretaria');
        foreach ([$nutri, $sec] as $u) {
            $this->loginAs($u);
            $this->assertSame(403, $this->get('/pacientes/' . $pid . '/exames')->status, $u['role']);
            $this->assertSame(403, $this->get('/exames/' . $cid)->status, $u['role']);
            $this->assertSame(403, $this->post('/exames/' . $cid . '/liberar', [])->status, $u['role']);
        }
        $this->assertNull($this->db->one('SELECT released_at FROM lab_collections WHERE id = ?', [$cid])['released_at']);
        $this->assertGreaterThanOrEqual(2, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'denied' AND patient_id = ?", [$pid]));
    }

    public function test_release_unrelease_and_delete_are_audited(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $cid = $this->idFrom($this->newCollection($pid, [$this->glic => '98', $this->ldl => '140']));
        $audit = fn(string $a): int => $this->n(
            "SELECT COUNT(*) n FROM audit_log WHERE entity = 'lab_collections' AND action = ? AND entity_id = ?", [$a, $cid],
        );

        $this->assertSame(302, $this->post('/exames/' . $cid . '/liberar', [])->status);
        $this->assertNotNull($this->db->one('SELECT released_at FROM lab_collections WHERE id = ?', [$cid])['released_at']);
        $this->assertSame(1, $audit('release'));

        $this->assertSame(302, $this->post('/exames/' . $cid . '/recolher', [])->status);
        $this->assertNull($this->db->one('SELECT released_at FROM lab_collections WHERE id = ?', [$cid])['released_at']);
        $this->assertSame(1, $audit('unrelease'));

        $res = $this->post('/exames/' . $cid . '/excluir', []);
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $pid . '/exames', $res->headers['Location']);
        $this->assertSame(1, $audit('delete'));
        $index = $this->get('/pacientes/' . $pid . '/exames');
        $this->assertStringNotContainsString('/exames/' . $cid . '"', $index->body);
        $this->assertStringNotContainsString('LDL-colesterol', $index->body);
        $this->assertSame(404, $this->get('/exames/' . $cid)->status);
        $this->assertSame(404, $this->post('/exames/' . $cid . '/liberar', [])->status);
    }

    public function test_download_requires_doctor(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $cid = $this->idFrom($this->newCollection($pid, [$this->glic => '98']));
        $this->assertSame(302, $this->post('/exames/' . $cid . '/anexos', [], $this->laudos('resultado.pdf'))->status);
        $attId = (int) $this->db->one('SELECT id FROM lab_attachments WHERE collection_id = ?', [$cid])['id'];

        $res = $this->get('/exames/anexos/' . $attId);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('attachment', $res->headers['Content-Disposition']);
        $this->assertSame(1, $this->n(
            "SELECT COUNT(*) n FROM audit_log WHERE entity = 'lab_attachments' AND action = 'download' AND entity_id = ?", [$attId],
        ));

        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $this->loginAs($nutri);
        $this->assertSame(403, $this->get('/exames/anexos/' . $attId)->status);
        $this->assertSame(403, $this->post('/exames/anexos/' . $attId . '/excluir', [])->status);

        $this->loginAs($med);
        $this->assertSame(302, $this->post('/exames/anexos/' . $attId . '/excluir', [])->status);
        $this->assertSame(404, $this->get('/exames/anexos/' . $attId)->status);
        $this->assertSame(404, $this->get('/exames/anexos/999999')->status);
    }

    public function test_patient_card_shows_exams_only_for_doctor(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $this->newCollection($pid, [$this->glic => '98', $this->ldl => '140']);
        $res = $this->get('/pacientes/' . $pid);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('cartao-exames', $res->body);
        $this->assertStringContainsString('10/05/2026', $res->body);
        $this->assertStringContainsString('1 valor alterado', $res->body);
        $this->assertStringContainsString('Ver exames', $res->body);

        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $this->loginAs($nutri);
        $res = $this->get('/pacientes/' . $pid);
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('cartao-exames', $res->body);
    }

    public function test_index_limits_to_six_with_see_all(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        for ($i = 1; $i <= 7; $i++) {
            Container::labs()->create($pid, ['collected_on' => sprintf('2026-0%d-01', $i)], [$this->glic => (string) (90 + $i)], (int) $med['id'], new Request('POST', '/'));
        }
        $res = $this->get('/pacientes/' . $pid . '/exames');
        $this->assertStringContainsString('?todas=1', $res->body);
        $this->assertStringNotContainsString('>91<', $res->body);
        $all = $this->get('/pacientes/' . $pid . '/exames', ['todas' => '1']);
        $this->assertStringContainsString('>91<', $all->body);
    }

    public function test_cell_without_range_says_so(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => '2025-12-01', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->loginAs($med);
        $this->idFrom($this->newCollection((int) $p['id'], [$this->ldl => '140']));
        $res = $this->get('/pacientes/' . $p['id'] . '/exames');
        $this->assertStringContainsString('title="sem faixa para esta idade"', $res->body);
        $this->assertStringContainsString('<span class="sr-only">sem faixa para esta idade</span>', $res->body);
        $this->assertStringNotContainsString('class="lab-marca"', $res->body);
    }

    public function test_editing_keeps_result_of_deactivated_test(): void
    {
        [, $p] = $this->setupDoctor();
        $cid = $this->idFrom($this->newCollection((int) $p['id'], [$this->glic => '98', $this->ldl => '140']));
        $this->db->run('UPDATE lab_tests SET active = 0 WHERE id = ?', [$this->ldl]);

        $form = $this->get('/exames/' . $cid . '/editar');
        $this->assertSame(200, $form->status);
        $this->assertMatchesRegularExpression('#name="valor\[' . $this->ldl . '\]"[^>]*value="140"#', $form->body);
        $this->assertStringContainsString('(exame desativado no catálogo)', $form->body);
        $this->assertStringNotContainsString('será removido', $form->body);

        // So a data muda; o formulario reenvia o LDL (desativado) e ele continua gravado.
        $res = $this->post('/exames/' . $cid . '/editar', [
            'coletado_em' => '2026-05-11', 'laboratorio' => '', 'observacao' => '', 'valor' => [$this->glic => '98', $this->ldl => '140'],
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) n FROM lab_results WHERE collection_id = ? AND test_id = ?', [$cid, $this->ldl]));
        $this->assertStringContainsString('LDL-colesterol', $this->get('/pacientes/' . $p['id'] . '/exames')->body);
    }

    private function eightYearOldWithTwoLdl(): array
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => '2018-01-01', 'sex' => 'M', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->loginAs($med);
        foreach (['2026-06-01' => '140', '2026-03-01' => '100'] as $date => $v) {
            $this->idFrom($this->post('/pacientes/' . $p['id'] . '/exames/nova', [
                'coletado_em' => $date, 'laboratorio' => '', 'observacao' => '', 'valor' => [$this->ldl => $v],
            ]));
        }
        return [$med, $p];
    }

    public function test_series_json_shape(): void
    {
        [, $p] = $this->eightYearOldWithTwoLdl();
        $res = $this->get('/pacientes/' . $p['id'] . '/exames/serie.json', ['exame' => (string) $this->ldl]);
        $this->assertSame(200, $res->status);
        $j = json_decode($res->body, true);
        $this->assertSame(['2026-03-01', '2026-06-01'], array_column($j['points'], 'date'));
        $this->assertEquals([100.0, 140.0], array_column($j["points"], "value"));
        $this->assertSame(110.0, (float) $j['band']['high']);
        $this->assertArrayHasKey('unit', $j['test']);
        $this->assertArrayHasKey('decimals', $j['test']);
        $this->assertArrayHasKey('name', $j['test']);
    }

    public function test_series_json_403_for_nutritionist(): void
    {
        [$med, $p] = $this->eightYearOldWithTwoLdl();
        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant((int) $p['id'], (int) $nutri['id'], (int) $med['id']);
        $this->loginAs($nutri);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/exames/serie.json', ['exame' => (string) $this->ldl])->status);
    }

    public function test_series_json_404_unknown_test(): void
    {
        [, $p] = $this->eightYearOldWithTwoLdl();
        $this->assertSame(404, $this->get('/pacientes/' . $p['id'] . '/exames/serie.json', ['exame' => '987654'])->status);
        $this->assertSame(404, $this->get('/pacientes/' . $p['id'] . '/exames/serie.json')->status);
    }

    public function test_index_has_chart_container_and_script(): void
    {
        [, $p] = $this->eightYearOldWithTwoLdl();
        $html = $this->get('/pacientes/' . $p['id'] . '/exames')->body;
        $this->assertStringContainsString('id="lab-chart"', $html);
        $this->assertStringContainsString('lab-chart.js', $html);
        $this->assertStringContainsString('data-exame="' . $this->ldl . '"', $html);
    }
}
