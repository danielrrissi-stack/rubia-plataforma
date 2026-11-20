<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class MealPlanStructuredTest extends DbTestCase
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

    /** @return array{0:array,1:array} medico + paciente */
    private function setupDoctor(): array
    {
        $doc = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $doc['id'], 'created_by' => $doc['id']]);
        $this->loginAs($doc);
        return [$doc, $p];
    }

    private function newStructured(int $pid): int
    {
        $res = $this->post('/pacientes/' . $pid . '/plano/novo', ['modo' => 'estruturado', 'origem' => 'branco']);
        $this->assertSame(302, $res->status);
        $this->assertMatchesRegularExpression('#^/planos/(\d+)/editar$#', $res->headers['Location']);
        return (int) preg_replace('#\D#', '', $res->headers['Location']);
    }

    private function meal(array $items, string $name = 'Café da manhã', string $note = ''): array
    {
        return ['name' => $name, 'time' => '07:00', 'note' => $note, 'options' => [['items' => $items]]];
    }

    private function save(int $planId, array $meals, string $rev = '1'): Response
    {
        return $this->post('/planos/' . $planId . '/editar', [
            'titulo' => 'Plano estruturado', 'orientacoes' => 'Beber água',
            'estrutura' => json_encode(['meals' => $meals], JSON_UNESCAPED_UNICODE), 'revisao' => $rev, 'auto' => '0',
        ]);
    }

    public function test_search_returns_measures(): void
    {
        $this->setupDoctor();
        $res = $this->get('/alimentos/busca', ['q' => 'pao frances']);
        $this->assertSame(200, $res->status);
        $items = json_decode($res->body, true)['items'];
        $hit = array_values(array_filter($items, fn(array $i): bool => $i['id'] === 53));
        $this->assertCount(1, $hit);
        $this->assertSame(300.0, (float) $hit[0]['per100']['kcal']);
        $this->assertNotEmpty($hit[0]['measures']);
        $this->assertArrayHasKey('plural', $hit[0]['measures'][0]);
        $this->assertArrayHasKey('grams', $hit[0]['measures'][0]);
        $this->assertSame([], json_decode($this->get('/alimentos/busca', ['q' => 'p'])->body, true)['items']);
        $this->assertSame([], json_decode($this->get('/alimentos/busca', ['q' => ['x']])->body, true)['items']);
    }

    public function test_search_forbidden_for_family(): void
    {
        $doc = $this->makeUser('medico');
        $p = $this->makePatient(['responsible_professional_id' => $doc['id'], 'created_by' => $doc['id']]);
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $doc['id']]);
        $this->loginAs($resp);
        $this->assertSame(403, $this->get('/alimentos/busca', ['q' => 'pao'])->status);
    }

    public function test_new_structured_draft_has_suggested_meals(): void
    {
        [, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        $plan = Container::mealPlans()->find($planId);
        $this->assertSame(
            ['Café da manhã', 'Lanche da manhã', 'Almoço', 'Lanche da tarde', 'Jantar', 'Ceia'],
            array_column($plan['structure']['meals'], 'name'),
        );
        $res = $this->get('/planos/' . $planId . '/editar');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('data-plano=', $res->body);
        $this->assertStringContainsString('meal-plan-editor.js', $res->body);
        $this->assertStringContainsString('/pacientes/' . $p['id'] . '/energia', $res->body); // sem meta: aviso com link
    }

    public function test_save_returns_totals_and_persists_snapshot(): void
    {
        [, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        $m = Container::foods()->measures(53)[0];
        $res = $this->save($planId, [$this->meal([['food_id' => 53, 'qty' => '1', 'measure_id' => $m['id'], 'note' => '', 'subs' => []]])]);
        $this->assertSame(200, $res->status, $res->body);
        $j = json_decode($res->body, true);
        $this->assertTrue($j['ok']);
        $this->assertSame(2, $j['revision']);
        $this->assertFalse($j['conflict']);
        $this->assertEqualsWithDelta(300 * (float) $m['grams'] / 100, $j['totals']['day']['kcal'], 1e-6);
        $this->assertEqualsWithDelta(300 * (float) $m['grams'] / 100, $j['totals']['options'][0][0]['kcal'], 1e-6);
        $this->assertArrayHasKey('protein', $j['percents']);
        $edit = $this->get('/planos/' . $planId . '/editar')->body;
        $this->assertSame(1, preg_match('/data-plano="([^"]*)"/', $edit, $mm));
        $init = json_decode(html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertSame(53, $init['meals'][0]['options'][0]['items'][0]['food_id']);
        $this->assertSame((int) $m['id'], $init['meals'][0]['options'][0]['items'][0]['measure_id']);
        $this->assertSame(53, $init['foods'][0]['id']);
        $this->assertContains((int) $m['id'], array_column($init['foods'][0]['measures'], 'id'));
        $this->assertStringContainsString('data-revisao="2"', $edit);
        $row = $this->db->one('SELECT structure_json, guidance_text, title FROM meal_plans WHERE id = ?', [$planId]);
        $s = json_decode((string) $row['structure_json'], true);
        $this->assertSame('Pão, trigo, francês', $s['meals'][0]['options'][0]['items'][0]['food_name']);
        $this->assertSame(300.0, (float) $s['meals'][0]['options'][0]['items'][0]['per100']['kcal']);
        $this->assertSame('Beber água', $row['guidance_text']);
        $this->assertSame('Plano estruturado', $row['title']);
    }

    public function test_save_flags_conflict_and_auto(): void
    {
        [, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        $meals = [$this->meal([['food_id' => 53, 'qty' => '50', 'measure_id' => null]])];
        $this->assertSame(2, json_decode($this->save($planId, $meals)->body, true)['revision']);
        $j = json_decode($this->save($planId, $meals, '1')->body, true);
        $this->assertTrue($j['conflict']);
        $this->assertSame(3, $j['revision']);
    }

    public function test_invalid_quantity_422_and_nothing_saved(): void
    {
        [, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        $res = $this->save($planId, [$this->meal([['food_id' => 53, 'qty' => '-1', 'measure_id' => null]])]);
        $this->assertSame(422, $res->status);
        $j = json_decode($res->body, true);
        $this->assertFalse($j['ok']);
        $this->assertStringContainsString('maior que zero', $j['errors'][0]);
        $this->assertSame(1, (int) Container::mealPlans()->find($planId)['revision']);
    }

    public function test_malformed_input_is_422(): void
    {
        [, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        foreach ([['estrutura' => '{nao json'], ['estrutura' => ['x']], ['titulo' => ['x']], ['orientacoes' => ['x']]] as $bad) {
            $res = $this->post('/planos/' . $planId . '/editar', $bad + [
                'titulo' => 'Plano', 'orientacoes' => '', 'estrutura' => '{"meals":[]}', 'revisao' => '1',
            ]);
            $this->assertSame(422, $res->status, json_encode($bad));
            $this->assertNotEmpty(json_decode($res->body, true)['errors']);
        }
        $this->assertSame(1, (int) Container::mealPlans()->find($planId)['revision']);
    }

    public function test_publish_rejects_empty_meal(): void
    {
        [, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        $res = $this->save($planId, [
            $this->meal([['food_id' => 53, 'qty' => '50', 'measure_id' => null]]),
            ['name' => 'Almoço', 'time' => '', 'note' => '', 'options' => []],
        ]);
        $this->assertSame(200, $res->status, $res->body); // rascunho aceita refeicao vazia
        $res = $this->post('/planos/' . $planId . '/publicar', []);
        $this->assertSame(302, $res->status);
        $this->assertSame('rascunho', Container::mealPlans()->find($planId)['status']);
        $page = $this->get($res->headers['Location']);
        if ($page->status === 302) {
            $page = $this->get($page->headers['Location']);
        }
        $this->assertStringContainsString('Refeição sem itens: Almoço.', $page->body);
    }

    public function test_publish_keeps_snapshot_after_food_change(): void
    {
        [$doc, $p] = $this->setupDoctor();
        $food = $this->db->insert('foods', ['source' => 'proprio', 'name' => 'Bolo da vó', 'name_search' => 'bolo da vo', 'kcal' => 350, 'protein_g' => 5, 'carb_g' => 50, 'fat_g' => 12, 'fiber_g' => 1]);
        $planId = $this->newStructured((int) $p['id']);
        $this->assertSame(200, $this->save($planId, [$this->meal([['food_id' => $food, 'qty' => '200', 'measure_id' => null]])])->status);
        $this->assertSame(302, $this->post('/planos/' . $planId . '/publicar', [])->status);
        $this->assertSame('publicado', Container::mealPlans()->find($planId)['status']);
        $this->db->run("UPDATE foods SET name = 'Outro', kcal = 1 WHERE id = ?", [$food]);
        $body = $this->get('/planos/' . $planId)->body;
        $this->assertStringContainsString('Bolo da vó', $body);
        $this->assertStringNotContainsString('Outro', $body);
        $this->assertSame(700.0, (float) Container::mealPlans()->find($planId)['total_kcal']);
        $this->assertStringContainsString('Total do dia: 700 kcal', $body);
    }

    public function test_editor_shows_energy_goal_and_non_draft_save_is_rejected(): void
    {
        [$doc, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        $this->db->insert('energy_goals', $this->goalRow((int) $p['id'], (int) $doc['id']));
        $this->assertStringContainsString('data-meta-kcal="1800"', $this->get('/planos/' . $planId . '/editar')->body);
        $this->save($planId, [$this->meal([['food_id' => 53, 'qty' => '50', 'measure_id' => null]])]);
        Container::mealPlans()->publish($planId, (int) $doc['id']);
        $res = $this->save($planId, [$this->meal([['food_id' => 53, 'qty' => '50', 'measure_id' => null]])], '2');
        $this->assertSame(409, $res->status);
        $this->assertFalse(json_decode($res->body, true)['ok']);
    }

    public function test_secretary_cannot_save(): void
    {
        [$doc, $p] = $this->setupDoctor();
        $planId = $this->newStructured((int) $p['id']);
        Session::reset();
        $this->loginAs($this->makeUser('secretaria'));
        $this->assertSame(403, $this->save($planId, [$this->meal([['food_id' => 53, 'qty' => '50', 'measure_id' => null]])])->status);
        $this->assertSame(403, $this->get('/alimentos/busca', ['q' => 'pao'])->status);
    }

    private function goalRow(int $pid, int $by): array
    {
        return [
            'patient_id' => $pid, 'formula' => 'mifflin', 'activity_factor' => 1.5, 'weight_kg' => 30, 'height_cm' => 130,
            'age_years' => 10, 'tmb_kcal' => 1200, 'get_kcal' => 1800, 'created_by' => $by,
        ];
    }
}
