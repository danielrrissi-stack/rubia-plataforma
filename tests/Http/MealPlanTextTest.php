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

    public function testInvalidSourceDoesNotDiscardDraft(): void
    {
        [, $p] = $this->setup2();
        $draft = $this->planIdFrom($this->newDraft((int) $p['id']));
        foreach ([['origem' => 'modelo', 'modelo_id' => '999999'], ['origem' => 'vigente']] as $src) {
            $res = $this->newDraft((int) $p['id'], $src + ['descartar_rascunho' => '1']);
            $this->assertSame(302, $res->status);
            $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
            $this->assertNull($this->db->one('SELECT discarded_at FROM meal_plans WHERE id = ?', [$draft])['discarded_at']);
        }
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
        Container::mealPlans()->publish($planId, (int) $nutri['id']);
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $nutri['id']]);
        $sec = $this->makeUser('secretaria');
        foreach ([$resp, $sec] as $u) {
            \App\Core\Session::reset();
            $this->loginAs($u);
            $before = (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='denied' AND user_id = ?", [$u['id']])['c'];
            $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/plano')->status);
            $this->assertSame(403, $this->get('/planos/' . $planId)->status);
            $this->assertSame(403, $this->post('/planos/' . $planId . '/publicar', [])->status);
            $after = (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='denied' AND user_id = ?", [$u['id']])['c'];
            $this->assertSame($before + 3, $after);
            $this->assertSame('publicado', Container::mealPlans()->find($planId)['status']);
        }
    }

    public function testConflictDoesNotPublish(): void
    {
        [, $p] = $this->setup2();
        $planId = $this->planIdFrom($this->newDraft((int) $p['id']));
        $body = ['titulo' => 'Plano', 'corpo' => "ALMOÇO\n• arroz", 'orientacoes' => '', 'acao' => 'publicar'];
        $this->post('/planos/' . $planId . '/editar', ['revisao' => '1', 'acao' => ''] + $body); // revisao passa a 2
        $res = $this->post('/planos/' . $planId . '/editar', ['revisao' => '1'] + $body);
        $this->assertSame(302, $res->status);
        $this->assertSame('rascunho', Container::mealPlans()->find($planId)['status']);
    }

    public function testResponsibleCannotPreview(): void
    {
        $nutri = $this->makeUser('nutricionista');
        $p = $this->makePatient(['responsible_professional_id' => $nutri['id'], 'created_by' => $nutri['id']]);
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $nutri['id']]);
        $this->loginAs($resp);
        $this->assertSame(403, $this->post('/planos/previa', ['texto' => 'x'])->status);
    }

    public function testNoGrantBlocksPlanRoutes(): void
    {
        $other = $this->makeUser('medico');
        $p = $this->makePatient(['responsible_professional_id' => $other['id'], 'created_by' => $other['id']]);
        $planId = Container::mealPlans()->createDraft((int) $p['id'], 'texto', ['title' => 'Plano', 'body_text' => 'x'], (int) $other['id']);
        Container::mealPlans()->publish($planId, (int) $other['id']);
        $this->loginAs($this->makeUser('nutricionista'));
        $this->assertSame(403, $this->get('/planos/' . $planId)->status);
        $this->assertSame(403, $this->get('/planos/' . $planId . '/imprimir')->status);
        $this->assertSame(403, $this->post('/planos/' . $planId . '/publicar', [])->status);
    }

    public function testNewDraftFromCurrentCopiesAndSetsSource(): void
    {
        [$nutri, $p] = $this->setup2();
        $repo = Container::mealPlans();
        $cur = $repo->createDraft((int) $p['id'], 'texto', ['title' => 'Vigente', 'body_text' => "ALMOÇO\n• arroz"], (int) $nutri['id']);
        $repo->publish($cur, (int) $nutri['id']);
        $id = $this->planIdFrom($this->newDraft((int) $p['id'], ['origem' => 'vigente']));
        $row = $repo->find($id);
        $this->assertSame($cur, (int) $row['source_plan_id']);
        $this->assertSame("ALMOÇO\n• arroz", $row['body_text']);
    }

    public function testDiscardAndNewDraftActions(): void
    {
        [$nutri, $p] = $this->setup2();
        $repo = Container::mealPlans();
        $d = $this->planIdFrom($this->newDraft((int) $p['id']));
        $this->assertSame(302, $this->post('/planos/' . $d . '/descartar', [])->status);
        $this->assertNotNull($repo->find($d)['discarded_at']);
        $cur = $repo->createDraft((int) $p['id'], 'texto', ['title' => 'Vigente', 'body_text' => 'x'], (int) $nutri['id']);
        $repo->publish($cur, (int) $nutri['id']);
        $new = $this->planIdFrom($this->post('/planos/' . $cur . '/novo-rascunho', []));
        $this->assertSame('rascunho', $repo->find($new)['status']);
    }

    public function testPrintTotalsOnlyWithFlag(): void
    {
        [$nutri, $p] = $this->setup2();
        $repo = Container::mealPlans();
        $item = [
            'food_id' => 1, 'food_name' => 'Arroz', 'qty' => 100.0, 'measure_id' => null, 'measure_name' => null,
            'measure_plural' => null, 'grams' => 100.0, 'note' => '', 'subs' => [],
            'per100' => ['kcal' => 130.0, 'protein' => 2.5, 'carb' => 28.0, 'fat' => 0.2, 'fiber' => 1.6],
        ];
        $structure = ['v' => 1, 'meals' => [['name' => 'Almoço', 'time' => '', 'note' => '', 'options' => [['items' => [$item]]]]]];
        $id = $repo->createDraft((int) $p['id'], 'estruturado', ['title' => 'Estruturado', 'structure' => $structure], (int) $nutri['id']);
        $repo->publish($id, (int) $nutri['id']);
        $this->assertStringNotContainsString('Totais do dia', $this->get('/planos/' . $id . '/imprimir')->body);
        $with = $this->get('/planos/' . $id . '/imprimir', ['totais' => '1']);
        $this->assertSame(200, $with->status);
        $this->assertStringContainsString('Totais do dia', $with->body);
        $this->assertStringContainsString('kcal', $with->body);
        // Sem o quadro de totais, a impressao pode ir para a familia: nenhuma caloria (nem por opcao).
        $without = $this->get('/planos/' . $id . '/imprimir');
        $this->assertStringContainsString('Arroz', $without->body);
        $this->assertStringNotContainsString('kcal', $without->body);
    }

    public function testPublishFailureKeepsSavedRevision(): void
    {
        [, $p] = $this->setup2();
        $planId = $this->planIdFrom($this->newDraft((int) $p['id']));
        $res = $this->post('/planos/' . $planId . '/editar', [
            'titulo' => 'Plano', 'corpo' => '', 'orientacoes' => '', 'revisao' => '1', 'acao' => 'publicar',
        ]);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Plano vazio', $res->body);
        // O salvamento aconteceu (revisao 2): a tela devolvida carrega a revisao nova.
        $this->assertSame(2, (int) Container::mealPlans()->find($planId)['revision']);
        $this->assertStringContainsString('name="revisao" value="2"', $res->body);
        $res = $this->post('/planos/' . $planId . '/editar', [
            'titulo' => 'Plano', 'corpo' => "ALMOÇO
• arroz", 'orientacoes' => '', 'revisao' => '2', 'acao' => 'publicar',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
        $this->assertSame('publicado', Container::mealPlans()->find($planId)['status']);
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
