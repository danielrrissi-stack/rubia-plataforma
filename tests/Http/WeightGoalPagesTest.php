<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class WeightGoalPagesTest extends DbTestCase
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

    private function patient(string $birth, string $sex = 'F'): array
    {
        return $this->makePatient(['birth_date' => $birth, 'sex' => $sex, 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function measure(array $p, string $on, float $kg, ?float $cm = 170.0): void
    {
        Container::measurements()->create((int) $p['id'], ['measured_on' => $on, 'weight_kg' => $kg, 'height_cm' => $cm, 'position' => $cm === null ? null : 'em_pe'], 'equipe', (int) $this->medico['id']);
    }

    private function future(string $mod): string
    {
        return (new \DateTimeImmutable('today'))->modify($mod)->format('Y-m-d');
    }

    private function url(array $p, string $suffix = ''): string
    {
        return '/pacientes/' . $p['id'] . '/meta-peso' . $suffix;
    }

    public function test_doctor_sets_adult_goal(): void
    {
        $p = $this->patient('1990-04-02');
        $this->measure($p, $this->future('-3 days'), 92.0);
        $this->loginAs($this->medico);
        $page = $this->get($this->url($p));
        $this->assertSame(200, $page->status);
        $this->assertStringNotContainsString('name="tipo"', $page->body); // adulto: so kg
        $res = $this->post($this->url($p), ['alvo' => '82,5', 'prazo' => $this->future('+6 months'), 'notas' => '']);
        $this->assertSame(302, $res->status);
        $g = Container::weightGoals()->active((int) $p['id']);
        $this->assertSame('peso', $g['kind']);
        $this->assertSame(92.0, (float) $g['start_value']);
        $body = $this->get($this->url($p))->body;
        $this->assertStringContainsString('82,5', $body);
        $this->assertStringContainsString('Aguardando a primeira medição', $body);
        $views = (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'view' AND patient_id = ? AND after_json LIKE '%meta_peso%'", [$p['id']])['n'];
        $this->assertSame(2, $views); // as duas aberturas da pagina
    }

    public function test_kind_choice_by_age(): void
    {
        $teen = $this->patient('2011-02-01');
        $this->measure($teen, $this->future('-3 days'), 70.0, 165.0);
        $kid = $this->patient('2016-05-10');
        $this->measure($kid, $this->future('-3 days'), 40.0, 140.0);
        $this->loginAs($this->medico);
        $this->assertStringContainsString('name="tipo"', $this->get($this->url($teen))->body);
        $this->assertStringNotContainsString('name="tipo"', $this->get($this->url($kid))->body);
        $res = $this->post($this->url($kid), ['tipo' => 'peso', 'alvo' => '36', 'prazo' => $this->future('+6 months')]);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('não está disponível', $res->body);
        $this->assertNull(Container::weightGoals()->active((int) $kid['id']));
        $this->assertSame(302, $this->post($this->url($kid), ['tipo' => 'imc_z', 'alvo' => '1,0', 'prazo' => $this->future('+6 months')])->status);
        $this->assertStringContainsString('com a altura atual', $this->get($this->url($kid))->body);
    }

    public function test_replace_and_end(): void
    {
        $p = $this->patient('1990-04-02');
        $this->measure($p, $this->future('-3 days'), 92.0);
        $this->loginAs($this->medico);
        $this->post($this->url($p), ['alvo' => '85', 'prazo' => $this->future('+6 months')]);
        $this->post($this->url($p), ['alvo' => '80', 'prazo' => $this->future('+8 months')]);
        $this->assertStringContainsString('substituída', $this->get($this->url($p))->body);
        $g = Container::weightGoals()->active((int) $p['id']);
        $this->assertSame(302, $this->post($this->url($p, '/encerrar'), ['meta' => (string) $g['id']])->status);
        $this->assertNull(Container::weightGoals()->active((int) $p['id']));
        $this->assertStringContainsString('Nenhuma meta de peso', $this->get('/pacientes/' . $p['id'])->body);
    }

    public function test_without_measurement_hides_form(): void
    {
        $p = $this->patient('1990-04-02');
        $this->loginAs($this->medico);
        $body = $this->get($this->url($p))->body;
        $this->assertStringContainsString('Registre uma medição', $body);
        $this->assertStringNotContainsString('name="alvo"', $body);
    }

    public function test_json_points_path_and_trend(): void
    {
        $p = $this->patient('1990-04-02');
        $this->measure($p, $this->future('-50 days'), 100.0);
        $this->loginAs($this->medico);
        $this->post($this->url($p), ['alvo' => '90', 'prazo' => $this->future('+50 days')]);
        foreach ([-40, -30, -20] as $d) {
            $this->measure($p, $this->future($d . ' days'), 100 + $d / 20);
        }
        $j = json_decode($this->get($this->url($p, '.json'))->body, true);
        $this->assertSame('kg', $j['unit']);
        $this->assertCount(4, $j['points']);
        $this->assertSame([$this->future('-50 days'), 100], [$j['path'][0][0], (int) $j['path'][0][1]]);
        $this->assertSame([$this->future('+50 days'), 90], [$j['path'][1][0], (int) $j['path'][1][1]]);
        $this->assertNull($j['trend']);
        $page = $this->get($this->url($p))->body;
        $this->assertStringContainsString('esperado em ' . fmt_date($this->future('-20 days')) . ' no caminho', $page);
        $this->assertStringNotContainsString('esperado hoje', $page);
        $this->measure($p, $this->future('-10 days'), 99.0);
        $j = json_decode($this->get($this->url($p, '.json'))->body, true);
        $this->assertCount(2, $j['trend']);
        $this->assertSame($this->future('+50 days'), $j['trend'][1][0]);
    }

    public function test_access(): void
    {
        $p = $this->patient('1990-04-02');
        $this->measure($p, $this->future('-3 days'), 92.0);
        $nutri = $this->makeUser('nutricionista');
        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $this->medico['id']]);
        $this->loginAs($nutri);
        $this->assertSame(200, $this->get($this->url($p))->status);
        $this->assertSame(302, $this->post($this->url($p), ['alvo' => '85', 'prazo' => $this->future('+6 months')])->status);
        $this->assertStringContainsString('cartao-meta-peso', $this->get('/pacientes/' . $p['id'])->body);

        $other = $this->makeUser('nutricionista');
        $this->loginAs($other);
        $this->assertSame(403, $this->get($this->url($p))->status);
        $this->assertSame(403, $this->get($this->url($p, '.json'))->status);
        $this->assertSame(403, $this->post($this->url($p), ['alvo' => '80', 'prazo' => $this->future('+6 months')])->status);

        $sec = $this->makeUser('secretaria');
        $this->loginAs($sec);
        $this->assertSame(403, $this->get($this->url($p))->status);
        $this->assertStringNotContainsString('cartao-meta-peso', $this->get('/pacientes/' . $p['id'])->body);
    }

    public function test_family_never_sees_goal(): void
    {
        $kid = $this->patient('2016-05-10');
        $this->measure($kid, $this->future('-3 days'), 40.0, 140.0);
        $this->loginAs($this->medico);
        $this->post($this->url($kid), ['tipo' => 'imc_z', 'alvo' => '1,0', 'prazo' => $this->future('+6 months')]);
        $g = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $kid['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $this->medico['id']]);
        $this->loginAs($g);
        $this->assertSame(403, $this->get($this->url($kid))->status);
        $this->assertSame(403, $this->get($this->url($kid, '.json'))->status);
        foreach (['', '/crescimento'] as $s) {
            $res = $this->get('/portal/p/' . $kid['id'] . $s);
            $this->assertSame(200, $res->status, $s);
            $this->assertStringNotContainsString('Meta de peso', $res->body, $s);
        }
    }
}
