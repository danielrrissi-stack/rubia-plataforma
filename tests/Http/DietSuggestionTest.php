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
        $this->assertStringContainsString('Sugestão da semana', $body);
        $this->assertStringContainsString('<option value="1600" selected>', $body);
        $this->assertStringContainsString('<option value="adulto" selected>', $body);
        $this->assertStringContainsString('Restrições e alergias alimentares', $body);
    }

    public function testSuggestCreatesStructuredDraftWithoutKcalInTitle(): void
    {
        [, $p] = $this->doctorWith(7);
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'infantil', 'kcal' => '1400', 'semana' => '30']));
        $this->assertSame('estruturado', $plan['mode']);
        $this->assertSame('rascunho', $plan['status']);
        $this->assertSame('1400kcalSemana30Infantil', $plan['source_diet_code']);
        $this->assertSame('Plano alimentar – semana 30 (inverno)', $plan['title']);
        $this->assertStringNotContainsString('kcal', $plan['title'] . $plan['guidance_text']);
        $this->assertCount(6, $plan['structure']['meals']);
        $editor = $this->get('/planos/' . $plan['id'] . '/editar')->body;
        $this->assertStringContainsString('1400kcalSemana30Infantil', $editor);
    }

    public function testRestrictionsAreSavedAndApplied(): void
    {
        [, $p] = $this->doctorWith(30);
        $this->assertSame(302, $this->post('/pacientes/' . $p['id'] . '/restricoes', ['restricoes' => ['lactose', 'gluten', 'xx'], 'outros' => 'morango'])->status);
        $rs = Container::foodRestrictions()->get((int) $p['id']);
        $this->assertSame(['lactose', 'gluten'], $rs['items']);
        $this->assertSame('morango', $rs['other_text']);

        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'adulto', 'kcal' => '1800', 'semana' => '40']));
        $gluten = (require APP_ROOT . '/data/dietas/cardapio.php')['tags']['gluten'];
        $dairyNotes = 0;
        foreach ($plan['structure']['meals'] as $m) {
            foreach ($m['options'][0]['items'] as $it) {
                $this->assertNotContains($it['food_id'], $gluten, $it['food_name']);
                if (in_array($it['food_id'], [457, 458, 448, 449, 461, 463], true)) {
                    $this->assertStringContainsString('sem lactose', $it['note']);
                    $dairyNotes++;
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
        $res = $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['kcal' => '1500', 'semana' => '1']);
        $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
        $this->assertNotNull(Container::mealPlans()->draft((int) $p['id']));
        $plan = $this->planFrom($this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['kcal' => '1500', 'semana' => '1', 'descartar_rascunho' => '1']));
        $this->assertNotSame($old, (int) $plan['id']);
        $this->assertNotNull(Container::mealPlans()->find($old)['discarded_at']);
    }

    public function testInvalidLevelAndToddler(): void
    {
        [$doc, $p] = $this->doctorWith(30);
        $res = $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['versao' => 'infantil', 'kcal' => '2500', 'semana' => '1']);
        $this->assertSame('/pacientes/' . $p['id'] . '/plano', $res->headers['Location']);
        $this->assertNull(Container::mealPlans()->draft((int) $p['id']));

        $baby = $this->makePatient(['birth_date' => $this->years(1), 'sex' => 'M', 'responsible_professional_id' => $doc['id'], 'created_by' => $doc['id']]);
        $this->assertStringContainsString('a partir de 3 anos', $this->get('/pacientes/' . $baby['id'] . '/plano')->body);
        $this->post('/pacientes/' . $baby['id'] . '/plano/sugestao', ['kcal' => '1000', 'semana' => '1']);
        $this->assertNull(Container::mealPlans()->draft((int) $baby['id']));
    }

    public function testNutritionistWithoutGrantIsDenied(): void
    {
        [, $p] = $this->doctorWith(30);
        $this->loginAs($this->makeUser('nutricionista'));
        $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/plano/sugestao', ['kcal' => '1500', 'semana' => '1'])->status);
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
        $this->assertStringContainsString('Sugestão da semana', $body);
        $this->assertStringNotContainsString('camarão', $body);
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
