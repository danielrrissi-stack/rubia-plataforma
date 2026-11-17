<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class TemplateTest extends DbTestCase
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

    private function setupDoctor(): array
    {
        $doc = $this->makeUser('medico', ['is_admin' => 1]);
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $doc['id'], 'created_by' => $doc['id']]);
        $this->loginAs($doc);
        return [$doc, $p];
    }

    public function testDraftFromTextTemplate(): void
    {
        [$doc, $p] = $this->setupDoctor();
        $tid = Container::mealPlanTemplates()->create(['title' => 'Modelo texto', 'mode' => 'texto', 'body_text' => "ALMOCO\n• arroz", 'guidance_text' => 'Mastigar bem', 'structure' => null], (int) $doc['id']);
        $res = $this->post('/pacientes/' . $p['id'] . '/plano/novo', ['modo' => 'texto', 'origem' => 'modelo', 'modelo_id' => (string) $tid]);
        $this->assertSame(302, $res->status);
        $planId = (int) preg_replace('#\D#', '', $res->headers['Location']);
        $plan = Container::mealPlans()->find($planId);
        $this->assertSame("ALMOCO\n• arroz", $plan['body_text']);
        $this->assertSame('Mastigar bem', $plan['guidance_text']);
        $this->assertSame($tid, (int) $plan['source_template_id']);
    }

    public function testDraftFromStructuredTemplateWithInactiveFood(): void
    {
        [$doc, $p] = $this->setupDoctor();
        $fid = $this->db->insert('foods', ['source' => 'proprio', 'name' => 'Aveia da casa', 'name_search' => 'aveia da casa', 'kcal' => 100, 'protein_g' => 10, 'carb_g' => 10, 'fat_g' => 1, 'fiber_g' => 1]);
        $input = ['meals' => [['name' => 'Café', 'time' => '07:00', 'note' => '', 'options' => [['items' => [['food_id' => $fid, 'qty' => 50, 'measure_id' => null, 'note' => '', 'subs' => []]]]]]]];
        $structure = (new \App\Nutrition\MealPlanResolver(Container::foods()))->resolve($input);
        $tid = Container::mealPlanTemplates()->create(['title' => 'Modelo est', 'mode' => 'estruturado', 'body_text' => null, 'guidance_text' => null, 'structure' => $structure], (int) $doc['id']);
        $this->db->run('UPDATE foods SET active = 0, kcal = 200 WHERE id = ?', [$fid]);
        $res = $this->post('/pacientes/' . $p['id'] . '/plano/novo', ['modo' => 'texto', 'origem' => 'modelo', 'modelo_id' => (string) $tid]);
        $this->assertSame(302, $res->status);
        $this->assertMatchesRegularExpression('#^/planos/\d+/editar$#', $res->headers['Location']);
        $plan = Container::mealPlans()->find((int) preg_replace('#\D#', '', $res->headers['Location']));
        $this->assertSame('estruturado', $plan['mode']);
        $this->assertEquals(200.0, $plan['structure']['meals'][0]['options'][0]['items'][0]['per100']['kcal']); // valor atual
    }

    public function testSaveAsTemplateAndList(): void
    {
        [$doc, $p] = $this->setupDoctor();
        $planId = Container::mealPlans()->createDraft((int) $p['id'], 'texto', ['title' => 'Plano X', 'body_text' => 'CAFE', 'guidance_text' => null, 'structure' => null], (int) $doc['id']);
        Container::mealPlans()->publish($planId, (int) $doc['id']);
        $res = $this->post('/planos/' . $planId . '/salvar-como-modelo', ['titulo' => 'Modelo salvo']);
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('Modelo salvo', $this->get('/modelos-plano')->body);
        $res = $this->post('/planos/' . $planId . '/salvar-como-modelo', ['titulo' => 'x']);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM meal_plan_templates')['c']);
    }

    public function testCreateEditDeleteTemplateViaHttp(): void
    {
        $this->setupDoctor();
        $res = $this->post('/modelos-plano/novo', ['titulo' => 'Novo modelo', 'modo' => 'texto']);
        $this->assertSame(302, $res->status);
        $this->assertMatchesRegularExpression('#^/modelos-plano/(\d+)/editar$#', $res->headers['Location']);
        $tid = (int) preg_replace('#\D#', '', $res->headers['Location']);
        $this->assertSame(200, $this->get('/modelos-plano/' . $tid . '/editar')->status);
        $res = $this->post('/modelos-plano/' . $tid . '/editar', ['titulo' => 'Titulo 2', 'corpo' => 'ABC', 'orientacoes' => '']);
        $this->assertSame(302, $res->status);
        $this->assertSame('ABC', Container::mealPlanTemplates()->find($tid)['body_text']);
        $this->assertSame(302, $this->post('/modelos-plano/' . $tid . '/excluir', [])->status);
        $this->assertNull(Container::mealPlanTemplates()->find($tid));
    }

    public function testStructuredTemplateJsonSave(): void
    {
        $this->setupDoctor();
        $res = $this->post('/modelos-plano/novo', ['titulo' => 'Modelo est', 'modo' => 'estruturado']);
        $tid = (int) preg_replace('#\D#', '', $res->headers['Location']);
        $this->assertSame(200, $this->get('/modelos-plano/' . $tid . '/editar')->status);
        $meals = [['name' => 'Café', 'time' => '', 'note' => 'livre', 'options' => [['items' => []]]]];
        $res = $this->post('/modelos-plano/' . $tid . '/editar', ['titulo' => 'Modelo est 2', 'orientacoes' => '', 'estrutura' => json_encode(['meals' => $meals]), 'revisao' => '1']);
        $this->assertSame(200, $res->status);
        $j = json_decode($res->body, true);
        $this->assertTrue($j['ok']);
        $this->assertFalse($j['conflict']);
        $this->assertSame('Café', Container::mealPlanTemplates()->find($tid)['structure']['meals'][0]['name']);
        $res = $this->post('/modelos-plano/' . $tid . '/editar', ['titulo' => 'x', 'estrutura' => '[]', 'revisao' => '1']);
        $this->assertSame(422, $res->status);
    }

    public function testSnippetsJsonAndCrud(): void
    {
        $this->setupDoctor();
        $res = $this->post('/trechos/novo', ['titulo' => 'Água', 'corpo' => 'Beber água']);
        $this->assertSame(302, $res->status);
        $j = json_decode($this->get('/trechos.json')->body, true);
        $this->assertSame('Água', $j['items'][0]['title']);
        $this->assertSame('Beber água', $j['items'][0]['body']);
        $id = (int) $j['items'][0]['id'];
        $this->assertSame(302, $this->post('/trechos/' . $id . '/editar', ['titulo' => 'Água 2', 'corpo' => 'Mais água'])->status);
        $this->assertSame('Mais água', Container::textSnippets()->find($id)['body']);
        $this->assertSame(302, $this->post('/trechos/' . $id . '/excluir', [])->status);
        $this->assertSame([], json_decode($this->get('/trechos.json')->body, true)['items']);
    }

    public function testGuardianForbidden(): void
    {
        $resp = $this->makeUser('responsavel');
        $this->loginAs($resp);
        $this->assertSame(403, $this->get('/modelos-plano')->status);
        $this->assertSame(403, $this->get('/trechos.json')->status);
    }
}
