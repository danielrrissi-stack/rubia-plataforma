<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class MealPlanTextTest extends DbTestCase
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

    private function setup2(): array
    {
        $nutri = $this->makeUser('nutricionista');
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $nutri['id'], 'created_by' => $nutri['id']]);
        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $nutri['id']]);
        $this->loginAs($nutri);
        return [$nutri, $p];
    }

    private function newDraft(int $pid, array $extra = []): Response
    {
        return $this->post('/pacientes/' . $pid . '/plano/novo', $extra + ['modo' => 'texto', 'origem' => 'branco']);
    }

    private function planIdFrom(Response $res): int
    {
        $this->assertSame(302, $res->status);
        $this->assertMatchesRegularExpression('#^/planos/(\d+)/editar$#', $res->headers['Location']);
        return (int) preg_replace('#\D#', '', $res->headers['Location']);
    }

    public function testCreateEditPublishTextPlan(): void
    {
        [, $p] = $this->setup2();
        $planId = $this->planIdFrom($this->newDraft((int) $p['id']));
        $this->assertSame(200, $this->get('/planos/' . $planId . '/editar')->status);
        $res = $this->post('/planos/' . $planId . '/editar', [
            'titulo' => 'Plano de teste', 'corpo' => "CAFÉ DA MANHÃ\n• 1 pão", 'orientacoes' => '', 'revisao' => '1',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame(302, $this->post('/planos/' . $planId . '/publicar', [])->status);
        $res = $this->get('/pacientes/' . $p['id'] . '/plano');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Plano vigente', $res->body);
        $this->assertStringContainsString('<h3>CAFÉ DA MANHÃ</h3>', $this->get('/planos/' . $planId)->body);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE entity='meal_plans' AND action='publish' AND entity_id = ?", [$planId])['c']);
    }

    public function testEndPlanWithReason(): void
    {
        [, $p] = $this->setup2();
        $planId = $this->planIdFrom($this->newDraft((int) $p['id']));
        $this->post('/planos/' . $planId . '/editar', ['titulo' => 'Plano', 'corpo' => "ALMOÇO\n• arroz", 'orientacoes' => '', 'revisao' => '1']);
        $this->post('/planos/' . $planId . '/publicar', []);
        $res = $this->post('/planos/' . $planId . '/encerrar', ['motivo' => '']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Informe o motivo', $res->body);
        $res = $this->post('/planos/' . $planId . '/encerrar', ['motivo' => 'Paciente errado']);
        $this->assertSame(302, $res->status);
        $this->assertNull(Container::mealPlans()->current((int) $p['id']));
    }

    public function testSecondNewPlanAsksToContinueOrDiscard(): void
    {
        [, $p] = $this->setup2();
        $first = $this->planIdFrom($this->newDraft((int) $p['id']));
        $res = $this->newDraft((int) $p['id']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Continuar o rascunho', $res->body);
        $second = $this->planIdFrom($this->newDraft((int) $p['id'], ['descartar_rascunho' => '1']));
        $this->assertNotSame($first, $second);
        $this->assertNotNull($this->db->one('SELECT discarded_at FROM meal_plans WHERE id = ?', [$first])['discarded_at']);
    }

    public function testPreviewEscapesHtml(): void
    {
        $this->setup2();
        $res = $this->post('/planos/previa', ['texto' => '<script>x</script>']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('&lt;script&gt;', json_decode($res->body, true)['html']);
    }

    public function testInvalidInputIsHandled(): void
    {
        [, $p] = $this->setup2();
        $planId = $this->planIdFrom($this->newDraft((int) $p['id']));
        $res = $this->post('/planos/' . $planId . '/editar', ['titulo' => 'x', 'corpo' => ['a'], 'orientacoes' => '', 'revisao' => '1']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('título', $res->body);
        $res = $this->post('/planos/previa', ['texto' => ['a']]);
        $this->assertSame(200, $res->status);
        $res = $this->post('/planos/' . $planId . '/editar', ['titulo' => 'Plano', 'corpo' => "ALMO\xC7O\n• arroz", 'orientacoes' => '', 'revisao' => '1']);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('arroz', (string) Container::mealPlans()->find($planId)['body_text']);
    }

    public function testFamilyAndSecretaryBlocked(): void
    {
        $nutri = $this->makeUser('nutricionista');
        $p = $this->makePatient(['responsible_professional_id' => $nutri['id'], 'created_by' => $nutri['id']]);
        $planId = Container::mealPlans()->createDraft((int) $p['id'], 'texto', ['title' => 'Plano', 'body_text' => 'x'], (int) $nutri['id']);
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $nutri['id']]);
        $sec = $this->makeUser('secretaria');
        foreach ([$resp, $sec] as $u) {
            \App\Core\Session::reset();
            $this->loginAs($u);
            $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/plano')->status);
            $this->assertSame(403, $this->get('/planos/' . $planId)->status);
            $this->assertSame(403, $this->post('/planos/' . $planId . '/publicar', [])->status);
        }
    }

    public function testNutritionistWithoutGrant403(): void
    {
        $other = $this->makeUser('medico');
        $p = $this->makePatient(['responsible_professional_id' => $other['id'], 'created_by' => $other['id']]);
        $nutri = $this->makeUser('nutricionista');
        $this->loginAs($nutri);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/plano')->status);
    }
}
