<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class EvolutionPagesTest extends DbTestCase
{
    private array $medico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico', ['is_admin' => 1]);
    }

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

    private function patient(string $birth = '1980-05-01'): array
    {
        return $this->makePatient(['birth_date' => $birth, 'sex' => 'F', 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    private function measure(array $p, string $on, ?float $kg, ?float $cm = null, array $extra = [], string $source = 'familia'): void
    {
        Container::measurements()->create((int) $p['id'], ['measured_on' => $on, 'weight_kg' => $kg, 'height_cm' => $cm, 'position' => $cm === null ? null : 'em_pe'] + $extra, $source, (int) $this->medico['id']);
    }

    private function tirzepatida(array $p): int
    {
        $id = Container::prescriptions()->create((int) $p['id'], [
            'drug_name' => 'Tirzepatida', 'route' => 'subcutanea', 'start_date' => $this->day(-60),
            'dose' => '2,5 mg', 'frequency' => '1x por semana', 'instructions' => '', 'family_logs' => 0, 'next_review_date' => '',
        ], $this->medico, date('Y-m-d'), new Request('POST', '/x'));
        Container::prescriptions()->changeDose($id, ['effective_date' => $this->day(-30), 'dose' => '5 mg', 'frequency' => '1x por semana', 'instructions' => '', 'reason' => ''], (int) $this->medico['id'], date('Y-m-d'), new Request('POST', '/'));
        return $id;
    }

    private function json(array $p, string $q = ''): array
    {
        parse_str(ltrim($q, '?'), $query);
        $res = App::create()->handle(new Request('GET', '/pacientes/' . $p['id'] . '/evolucao.json', $query));
        $this->assertSame(200, $res->status);
        return json_decode($res->body, true);
    }

    public function test_doctor_sees_page_and_json(): void
    {
        $p = $this->patient();
        $this->measure($p, $this->day(-56), 100.0, 165.0, [], 'equipe');
        foreach ([-49, -42, -35, -28, -21, -14, -7, 0] as $i => $d) {
            $this->measure($p, $this->day($d), 99.5 - $i * 0.4);
        }
        $this->tirzepatida($p);
        $this->loginAs($this->medico);
        $res = $this->get('/pacientes/' . $p['id'] . '/evolucao');
        $this->assertSame(200, $res->status);
        foreach (['id="evolution-chart"', 'Ritmo por dose', 'Tirzepatida', 'evolution-chart.js?v=', 'id="evolucao-metabolismo"'] as $s) {
            $this->assertStringContainsString($s, $res->body);
        }
        $j = $this->json($p);
        $this->assertFalse($j['child']);
        $this->assertSame('kg', $j['unit']);
        $this->assertCount(9, $j['points']);
        $this->assertSame('equipe', $j['points'][0][2]);
        $this->assertSame('Tirzepatida', $j['meds'][0]['name']);
        $this->assertSame(['2,5 mg', '5 mg'], array_column($j['meds'][0]['segments'], 'dose'));
        $this->assertNull($j['meds'][0]['segments'][1]['to']);
        $this->assertSame('funcionando', $j['plateau']['status']);
        $this->assertStringStartsWith('Ainda perdendo peso', $j['plateau']['text']);
        $audit = $this->db->one("SELECT after_json FROM audit_log WHERE action = 'view' AND patient_id = ? ORDER BY id DESC LIMIT 1", [$p['id']]);
        $this->assertStringContainsString('evolucao', (string) ($audit['after_json'] ?? ''));
    }

    public function test_child_json_uses_z(): void
    {
        $p = $this->patient('2016-01-01');
        $this->measure($p, $this->day(-20), 45.0, 135.0, [], 'equipe');
        $this->measure($p, $this->day(-10), 44.8);
        $this->loginAs($this->medico);
        $j = $this->json($p);
        $this->assertTrue($j['child']);
        $this->assertSame('z', $j['unit']);
        $this->assertCount(2, $j['points']);
    }

    public function test_empty_patient_renders(): void
    {
        $p = $this->patient();
        $this->loginAs($this->medico);
        $res = $this->get('/pacientes/' . $p['id'] . '/evolucao');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Sem pesagens para avaliar.', $res->body);
        $j = $this->json($p);
        $this->assertSame([], $j['points']);
        $this->assertSame([], $j['meds']);
        $this->assertSame([], $j['metabolism']);
    }

    public function test_metabolism_only_series_with_data(): void
    {
        $p = $this->patient();
        $this->measure($p, $this->day(-10), 90.0, 165.0, ['waist_cm' => 98.0, 'systolic' => 130, 'diastolic' => 85], 'equipe');
        $tg = (int) Container::labCatalog()->findByCode('triglicerides')['id'];
        Container::labs()->create((int) $p['id'], ['collected_on' => $this->day(-9)], [$tg => '180'], (int) $this->medico['id'], new Request('POST', '/'));
        $this->loginAs($this->medico);
        $j = $this->json($p);
        $this->assertSame(['cintura', 'pa', 'triglicerides'], array_column($j['metabolism'], 'key'));
        $pa = $j['metabolism'][1];
        $this->assertSame(130, (int) $pa['points'][0][1]);
        $this->assertSame(85, (int) $pa['points2'][0][1]);
        $this->assertNotNull($pa['points'][0][2]);
        $this->assertStringStartsWith('alterado', (string) $j['metabolism'][2]['points'][0][2]);
    }

    public function test_period_filter(): void
    {
        $p = $this->patient();
        $this->measure($p, $this->day(-150), 100.0, 165.0, [], 'equipe');
        $this->measure($p, $this->day(-30), 95.0);
        $this->loginAs($this->medico);
        $this->assertCount(1, $this->json($p, '?periodo=3m')['points']);
        $this->assertCount(2, $this->json($p, '?periodo=6m')['points']);
        $this->assertCount(2, $this->json($p, '?periodo=xyz')['points']);
        $this->assertCount(2, $this->json($p, '?periodo=tudo')['points']);
    }

    public function test_others_forbidden(): void
    {
        $p = $this->patient();
        $nutri = $this->makeUser('nutricionista');
        $sec = $this->makeUser('secretaria');
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $this->medico['id']]);
        foreach ([$nutri, $sec, $resp] as $u) {
            $this->loginAs($u);
            $this->assertContains($this->get('/pacientes/' . $p['id'] . '/evolucao')->status, [302, 403], $u['role']);
            $this->assertContains($this->get('/pacientes/' . $p['id'] . '/evolucao.json')->status, [302, 403], $u['role']);
            $this->assertContains($this->post('/pacientes/' . $p['id'] . '/evolucao/criterio', ['modo' => 'kg', 'kg' => '0,3', 'pct' => '1', 'z' => '0,05'])->status, [302, 403], $u['role']);
        }
        $this->assertNull($this->db->one('SELECT patient_id FROM evolution_settings WHERE patient_id = ?', [$p['id']]));
    }

    public function test_save_settings(): void
    {
        $p = $this->patient();
        $this->loginAs($this->medico);
        $url = '/pacientes/' . $p['id'] . '/evolucao/criterio';
        $res = $this->post($url, ['modo' => 'pct', 'kg' => '0,2', 'pct' => '1,5', 'z' => '0,05']);
        $this->assertSame(302, $res->status);
        $this->assertSame(1.5, Container::evolutionSettings()->get((int) $p['id'])['pct_per_4w']);
        $bad = $this->post($url, ['modo' => 'kg', 'kg' => '9', 'pct' => '1', 'z' => '0,05']);
        $this->assertSame(200, $bad->status);
        $this->assertStringContainsString('menor que 5', $bad->body);
        $this->assertSame('pct', Container::evolutionSettings()->get((int) $p['id'])['adult_mode']);
    }

    public function test_links_for_doctor_only(): void
    {
        $p = $this->patient();
        $this->tirzepatida($p);
        $href = 'href="/pacientes/' . $p['id'] . '/evolucao"';
        $this->loginAs($this->medico);
        $this->assertStringContainsString($href, $this->get('/pacientes/' . $p['id'])->body);
        $this->assertStringContainsString($href, $this->get('/pacientes/' . $p['id'] . '/linha-do-tempo')->body);
        $this->assertStringContainsString($href, $this->get('/pacientes/' . $p['id'] . '/meta-peso')->body);
        $nutri = $this->makeUser('nutricionista');
        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $nutri['id']]);
        $this->loginAs($nutri);
        $page = $this->get('/pacientes/' . $p['id']);
        $this->assertSame(200, $page->status);
        $this->assertStringNotContainsString('/evolucao"', $page->body);
        $this->assertStringNotContainsString('/evolucao"', $this->get('/pacientes/' . $p['id'] . '/meta-peso')->body);
    }
}
