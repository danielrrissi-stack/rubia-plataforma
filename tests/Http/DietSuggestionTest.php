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

final class DietSuggestionTest extends DbTestCase
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

    private function years(int $n): string
    {
        return date('Y-m-d', strtotime('-' . $n . ' years -2 months'));
    }

    private function doctorWith(int $age): array
    {
        $doc = $this->makeUser('medico', ['is_admin' => 1]);
        $p = $this->makePatient(['birth_date' => $this->years($age), 'sex' => 'F', 'responsible_professional_id' => $doc['id'], 'created_by' => $doc['id']]);
        $this->loginAs($doc);
        return [$doc, $p];
    }

    private function planFrom(Response $res): array
    {
        $this->assertSame(302, $res->status, (string) Session::flash('erro'));
        $this->assertMatchesRegularExpression('#^/planos/\d+/editar$#', $res->headers['Location']);
        return Container::mealPlans()->find((int) preg_replace('#\D#', '', $res->headers['Location']));
    }

    public function testLibraryIsSeeded(): void
    {
        $this->assertSame(1664, Container::dietLibrary()->count());
        $d = Container::dietLibrary()->findByCode('1500kcalSemana5Infantil');
        $this->assertSame('infantil', $d['band']);
        $this->assertSame('verao', $d['season']);
    }

    public function testMilkHasCaloriesNow(): void
    {
        $this->assertEquals(60.0, (float) Container::foods()->find(458)['kcal']);
        $this->assertEquals(34.2, (float) Container::foods()->find(457)['kcal']);
    }

    public function testPlanPageShowsSuggestionWithGoalKcal(): void
    {
        [$doc, $p] = $this->doctorWith(35);
        $this->db->insert('energy_goals', ['patient_id' => $p['id'], 'formula' => 'mifflin', 'activity_factor' => 1.2, 'weight_kg' => 90, 'height_cm' => 165, 'age_years' => 35, 'tmb_kcal' => 1400, 'get_kcal' => 1640, 'created_by' => $doc['id']]);
        $body = $this->get('/pacientes/' . $p['id'] . '/plano')->body;
        $this->assertStringContainsString('Sugestão de cardápio', $body);
        $this->assertStringContainsString('<option value="1600" selected>', $body);
        $this->assertStringContainsString('<option value="adulto" selected>', $body);
        $this->assertStringContainsString('Restrições e alergias alimentares', $body);
    }

    public function testSuggestCreatesStructuredDraftWithoutKcalInTitle(): void
    {
        [, $p] = $this->doctorWith(7);
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'infantil', 'kcal' => '1400', 'dias' => '7', 'inicio' => date('Y-m-d')]));
        $this->assertSame('estruturado', $plan['mode']);
        $this->assertSame('rascunho', $plan['status']);
        $this->assertStringStartsWith('1400kcalSemana', (string) $plan['source_diet_code']);
        $this->assertStringNotContainsString('kcal', $plan['title'] . $plan['guidance_text']);
        $this->assertSame(2, (int) $plan['structure']['v']);
        $editor = $this->get('/planos/' . $plan['id'] . '/editar')->body;
        $this->assertStringContainsString((string) $plan['source_diet_code'], $editor);
    }

    public function testRestrictionsAreSavedAndApplied(): void
    {
        [, $p] = $this->doctorWith(30);
        $this->assertSame(302, $this->post('/pacientes/' . $p['id'] . '/restricoes', ['restricoes' => ['lactose', 'gluten', 'xx'], 'outros' => 'morango'])->status);
        $rs = Container::foodRestrictions()->get((int) $p['id']);
        $this->assertSame(['lactose', 'gluten'], $rs['items']);
        $this->assertSame('morango', $rs['other_text']);

        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800', 'dias' => '7', 'inicio' => date('Y-m-d')]));
        $gluten = (require APP_ROOT . '/data/dietas/cardapio.php')['tags']['gluten'];
        $dairyNotes = 0;
        foreach ($plan['structure']['days'] as $day) {
            foreach ($day['meals'] as $m) {
                foreach ($m['options'][0]['items'] as $it) {
                    $this->assertNotContains($it['food_id'], $gluten, $it['food_name']);
                    if (in_array($it['food_id'], [457, 458, 448, 449, 461, 463], true)) {
                        $this->assertStringContainsString('sem lactose', $it['note']);
                        $dairyNotes++;
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $dairyNotes);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE entity = 'patient_food_restrictions'")['c']);
    }

    public function testExistingDraftNeedsExplicitDiscard(): void
    {
        [$doc, $p] = $this->doctorWith(30);
        $old = Container::mealPlans()->createDraft((int) $p['id'], 'texto', ['title' => 'Antigo', 'body_text' => 'x'], (int) $doc['id']);
        $res = $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['kcal' => '1500', 'dias' => '7', 'inicio' => date('Y-m-d')]);
        $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
        $this->assertNotNull(Container::mealPlans()->draft((int) $p['id']));
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['kcal' => '1500', 'dias' => '7', 'inicio' => date('Y-m-d'), 'descartar_rascunho' => '1']));
        $this->assertNotSame($old, (int) $plan['id']);
        $this->assertNotNull(Container::mealPlans()->find($old)['discarded_at']);
    }

    public function testInvalidLevelAndToddler(): void
    {
        [$doc, $p] = $this->doctorWith(30);
        $res = $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'infantil', 'kcal' => '2500', 'dias' => '7', 'inicio' => date('Y-m-d')]);
        $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
        $this->assertNull(Container::mealPlans()->draft((int) $p['id']));

        $baby = $this->makePatient(['birth_date' => $this->years(1), 'sex' => 'M', 'responsible_professional_id' => $doc['id'], 'created_by' => $doc['id']]);
        $this->assertStringContainsString('a partir de 3 anos', $this->get('/pacientes/' . $baby['id'] . '/plano')->body);
        $this->post('/pacientes/' . $baby['id'] . '/plano/sugestao', ['kcal' => '1000', 'dias' => '7', 'inicio' => date('Y-m-d')]);
        $this->assertNull(Container::mealPlans()->draft((int) $baby['id']));
    }

    public function testNutritionistWithoutGrantIsDenied(): void
    {
        [, $p] = $this->doctorWith(30);
        $this->loginAs($this->makeUser('nutricionista'));
        $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['kcal' => '1500', 'dias' => '7', 'inicio' => date('Y-m-d')])->status);
        $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/restricoes', ['restricoes' => ['ovo']])->status);
    }

    public function testNutritionistDoesNotSeeRecordAllergies(): void
    {
        [$doc, $p] = $this->doctorWith(30);
        Container::clinicalEntries()->add((int) $p['id'], 'alergias', ['texto' => 'Alergia a camarão'], null, null, (int) $doc['id']);
        $this->assertStringContainsString('Alergia a camarão', $this->get('/pacientes/' . $p['id'] . '/plano')->body);
        $nutri = $this->makeUser('nutricionista');
        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $doc['id']]);
        $this->loginAs($nutri);
        $body = $this->get('/pacientes/' . $p['id'] . '/plano')->body;
        $this->assertStringContainsString('Sugestão de cardápio', $body);
        $this->assertStringNotContainsString('camarão', $body);
    }

    public function testSuggestCreatesFourteenDatedDays(): void
    {
        [, $p] = $this->doctorWith(30);
        $today = date('Y-m-d');
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800', 'dias' => '14', 'inicio' => $today]));
        $this->assertSame(2, (int) $plan['structure']['v']);
        $this->assertCount(14, $plan['structure']['days']);
        $this->assertSame($today, $plan['structure']['start']);
        $this->assertSame(14, (int) $plan['plan_days']);
        $this->assertSame('Plano alimentar – ' . date('d/m') . ' a ' . date('d/m', strtotime('+13 days')), $plan['title']);
        $this->assertStringNotContainsString('kcal', $plan['title'] . $plan['guidance_text']);
    }

    public function testSevenDaysOption(): void
    {
        [, $p] = $this->doctorWith(30);
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800', 'dias' => '7', 'inicio' => date('Y-m-d')]));
        $this->assertCount(7, $plan['structure']['days']);
    }

    public function testRestrictionsAppliedOnEveryDay(): void
    {
        [, $p] = $this->doctorWith(30);
        $this->post('/pacientes/' . $p['id'] . '/restricoes', ['restricoes' => ['leite']]);
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800', 'dias' => '14', 'inicio' => date('Y-m-d')]));
        foreach ($plan['structure']['days'] as $day) {
            foreach ($day['meals'] as $m) {
                foreach ($m['options'][0]['items'] as $it) {
                    $this->assertNotContains($it['food_id'], [457, 458]);
                }
            }
        }
    }

    public function testInvalidDurationOrStart(): void
    {
        [, $p] = $this->doctorWith(30);
        foreach ([['dias' => '10', 'inicio' => date('Y-m-d')], ['dias' => '7', 'inicio' => 'x'], ['dias' => '7', 'inicio' => date('Y-m-d', strtotime('+8 days'))], ['dias' => '7', 'inicio' => date('Y-m-d', strtotime('-8 days'))]] as $bad) {
            $res = $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800'] + $bad);
            $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
            $this->assertNull(Container::mealPlans()->draft((int) $p['id']));
        }
    }

    public function testStartBoundaries(): void
    {
        [, $p] = $this->doctorWith(30);
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800', 'dias' => '7', 'inicio' => date('Y-m-d', strtotime('+7 days')), 'descartar_rascunho' => '1']));
        $this->assertSame(date('Y-m-d', strtotime('+7 days')), $plan['structure']['start']);
    }

    public function testBuilderFailureIsFlashedNotFatal(): void
    {
        [, $p] = $this->doctorWith(30);
        $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->run('DELETE FROM foods');
        $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
        $res = $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800', 'dias' => '7', 'inicio' => date('Y-m-d')]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
        $this->assertStringContainsString('Não foi possível montar a sugestão', (string) Session::flash('erro'));
        $this->assertNull(Container::mealPlans()->draft((int) $p['id']));
    }

    private function publishedFrom(array $doc, array $p, string $start): array
    {
        $sug = (new \App\Diets\DietController())->buildSuggestion((int) $p['id'], 'adulto', 1800, $start, 14);
        $id = Container::mealPlans()->createDraft((int) $p['id'], 'estruturado', ['title' => $sug['title'], 'structure' => $sug['structure']],
            (int) $doc['id'], null, null, null, $sug['code'], 14);
        Container::mealPlans()->publish($id, (int) $doc['id']);
        return Container::mealPlans()->find($id);
    }

    public function testContinueNowButton(): void
    {
        [$doc, $p] = $this->doctorWith(30);
        $pid = (int) $p['id'];
        $draft = $this->publishedFrom($doc, $p, date('Y-m-d', strtotime('-10 days')));
        $body = $this->get('/pacientes/' . $pid . '/plano')->body;
        $this->assertStringContainsString('Gerar continuação agora', $body);
        $this->assertStringContainsString('action="/planos/' . $draft['id'] . '/continuacao"', $body);

        $cont = $this->planFrom($this->post('/planos/' . $draft['id'] . '/continuacao', []));
        $this->assertSame(2, (int) $cont['structure']['v']);
        $this->assertCount(18, $cont['structure']['days']);
        $this->assertSame(0, (int) $cont['auto_generated']);
        $this->assertSame((int) $draft['id'], (int) $cont['source_plan_id']);
        $this->assertStringNotContainsString('Gerar continuação agora', $this->get('/pacientes/' . $pid . '/plano')->body);
        // continuacao ainda aberta: leva para ela
        $again = $this->post('/planos/' . $draft['id'] . '/continuacao', []);
        $this->assertSame('/planos/' . $cont['id'] . '/editar', $again->headers['Location']);
        // descartada: gera outra
        Container::mealPlans()->discard((int) $cont['id'], (int) $doc['id']);
        $other = $this->planFrom($this->post('/planos/' . $draft['id'] . '/continuacao', []));
        $this->assertNotSame((int) $cont['id'], (int) $other['id']);

        // faltando 5 dias: sem botao
        [$doc2, $p2] = $this->doctorWith(30);
        $d2 = $this->publishedFrom($doc2, $p2, date('Y-m-d', strtotime('-9 days')));
        $this->assertStringNotContainsString('Gerar continuação agora', $this->get('/pacientes/' . $p2['id'] . '/plano')->body);

        // nutricionista sem liberacao: 403
        $this->loginAs($this->makeUser('nutricionista'));
        $this->assertSame(403, $this->post('/planos/' . $d2['id'] . '/continuacao', [])->status);
    }

    public function testLibraryShowListsSevenDays(): void
    {
        $this->doctorWith(30);
        $body = $this->get('/dietas-prontas/1500kcalSemana5')->body;
        foreach (['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'] as $n) {
            $this->assertStringContainsString($n, $body);
        }
    }

    public function testLibraryPages(): void
    {
        $this->doctorWith(30);
        $list = $this->get('/dietas-prontas', ['versao' => 'adulto', 'semana' => '5'])->body;
        $this->assertStringContainsString('1500kcalSemana5', $list);
        $this->assertStringNotContainsString('1500kcalSemana6', $list);
        $show = $this->get('/dietas-prontas/1500kcalSemana5');
        $this->assertSame(200, $show->status);
        $this->assertStringContainsString('Café da manhã', $show->body);
        $this->assertStringContainsString('ORIENTAÇÕES GERAIS', $show->body);
        $this->assertSame(404, $this->get('/dietas-prontas/9999kcalSemana99')->status);
    }
}
