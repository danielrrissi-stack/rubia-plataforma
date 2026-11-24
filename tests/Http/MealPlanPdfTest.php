<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use Tests\DbTestCase;

final class MealPlanPdfTest extends DbTestCase
{
    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    /** @return array{0:array,1:array} */
    private function setup2(): array
    {
        $nutri = $this->makeUser('nutricionista');
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $nutri['id'], 'created_by' => $nutri['id']]);
        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $nutri['id']]);
        $this->loginAs($nutri);
        return [$nutri, $p];
    }

    private function textPlan(int $pid, int $uid, string $body, bool $publish = true): int
    {
        $id = Container::mealPlans()->createDraft($pid, 'texto', ['title' => 'Plano de teste', 'body_text' => $body], $uid);
        if ($publish) {
            Container::mealPlans()->publish($id, $uid);
        }
        return $id;
    }

    private function structuredPlan(int $pid, int $uid): int
    {
        $item = [
            'food_id' => 1, 'food_name' => 'Arroz', 'qty' => 100.0, 'measure_id' => null, 'measure_name' => null,
            'measure_plural' => null, 'grams' => 100.0, 'note' => '', 'subs' => [],
            'per100' => ['kcal' => 130.0, 'protein' => 2.5, 'carb' => 28.0, 'fat' => 0.2, 'fiber' => 1.6],
        ];
        $structure = ['v' => 1, 'meals' => [['name' => 'Almoço', 'time' => '', 'note' => '', 'options' => [['items' => [$item]], ['items' => [$item]]]]]];
        $id = Container::mealPlans()->createDraft($pid, 'estruturado', ['title' => 'Estruturado', 'structure' => $structure], $uid);
        Container::mealPlans()->publish($id, $uid);
        return $id;
    }

    public function test_published_plan_pdf_downloads(): void
    {
        [$nutri, $p] = $this->setup2();
        $id = $this->textPlan((int) $p['id'], (int) $nutri['id'], "CAFÉ DA MANHÃ\n• 1 pão");
        $res = $this->get('/planos/' . $id . '/pdf');
        $this->assertSame(200, $res->status);
        $this->assertStringStartsWith('%PDF-', $res->body);
        $name = $p['social_name'] ?: $p['full_name'];
        $this->assertStringContainsString(rawurlencode('Plano alimentar - ' . $name . ' - ' . date('d-m-Y') . '.pdf'), $res->headers['Content-Disposition']);
    }

    public function test_pdf_never_has_totals(): void
    {
        [$nutri, $p] = $this->setup2();
        $id = $this->structuredPlan((int) $p['id'], (int) $nutri['id']);
        $html = View::render('Nutrition/views/_plan_doc.php', ['plan' => Container::mealPlans()->find($id), 'patient' => $p], null);
        $this->assertStringContainsString('Arroz', $html);
        $this->assertStringContainsString('Opção 2', $html);
        $this->assertStringNotContainsString('Totais do dia', $html);
        $this->assertStringNotContainsString('kcal', $html);
        $this->assertStringNotContainsString('data-print', $html);
        $plain = $this->get('/planos/' . $id . '/pdf');
        $flag = $this->get('/planos/' . $id . '/pdf', ['totais' => '1']);
        $this->assertSame(200, $flag->status);
        $this->assertSame(strlen($plain->body) > 0, strlen($flag->body) > 0);
        $this->assertSame($plain->headers['Content-Disposition'], $flag->headers['Content-Disposition']);
    }

    public function test_draft_plan_pdf_redirects(): void
    {
        [$nutri, $p] = $this->setup2();
        $id = $this->textPlan((int) $p['id'], (int) $nutri['id'], 'x', false);
        $res = $this->get('/planos/' . $id . '/pdf');
        $this->assertSame(302, $res->status);
        $this->assertSame('/planos/' . $id, $res->headers['Location']);
        $this->assertStringNotContainsString('Baixar PDF', $this->get('/planos/' . $id . '/imprimir')->body);
    }

    public function test_published_print_page_links_pdf(): void
    {
        [$nutri, $p] = $this->setup2();
        $id = $this->textPlan((int) $p['id'], (int) $nutri['id'], 'x');
        $body = $this->get('/planos/' . $id . '/imprimir')->body;
        $this->assertStringContainsString('Baixar PDF', $body);
        $this->assertStringContainsString(e(url('/planos/' . $id . '/pdf')), $body);
    }

    public function test_pdf_permissions_match_print(): void
    {
        $other = $this->makeUser('medico');
        $p = $this->makePatient(['responsible_professional_id' => $other['id'], 'created_by' => $other['id']]);
        $id = $this->textPlan((int) $p['id'], (int) $other['id'], 'x');
        foreach (['nutricionista', 'secretaria'] as $role) {
            $this->loginAs($this->makeUser($role));
            $print = $this->get('/planos/' . $id . '/imprimir')->status;
            $this->assertSame(403, $print, $role);
            $this->assertSame($print, $this->get('/planos/' . $id . '/pdf')->status, $role);
        }
    }

    public function test_pdf_audited(): void
    {
        [$nutri, $p] = $this->setup2();
        $id = $this->textPlan((int) $p['id'], (int) $nutri['id'], 'x');
        $this->get('/planos/' . $id . '/pdf');
        $row = $this->db->one("SELECT after_json FROM audit_log WHERE action = 'view' AND entity = 'meal_plans' AND entity_id = ? AND after_json LIKE '%pdf%'", [$id]);
        $this->assertNotNull($row);
        $after = json_decode((string) $row['after_json'], true);
        $this->assertTrue($after['pdf']);
        $this->assertSame('nutricao', $after['section']);
    }

    public function test_long_plan_renders(): void
    {
        [$nutri, $p] = $this->setup2();
        $lines = [];
        for ($i = 1; $i <= 300; $i++) {
            $lines[] = ($i % 20 === 1 ? 'REFEIÇÃO ' . $i : '• item <' . $i . '> & cia');
        }
        $id = $this->textPlan((int) $p['id'], (int) $nutri['id'], implode("\n", $lines));
        $res = $this->get('/planos/' . $id . '/pdf');
        $this->assertSame(200, $res->status);
        $this->assertStringStartsWith('%PDF-', $res->body);
    }

    public function testPdfV2HasAllDaysAndNoKcal(): void
    {
        [$nutri, $p] = $this->setup2();
        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $days[] = ['meals' => [['name' => sprintf('RefD%02dX', $i), 'time' => '', 'note' => '', 'options' => [['items' => [[
                'food_id' => 1, 'food_name' => 'Arroz', 'qty' => 100.0, 'measure_id' => null, 'measure_name' => null,
                'measure_plural' => null, 'grams' => 100.0, 'note' => '', 'subs' => [],
                'per100' => ['kcal' => 130.0, 'protein' => 2.5, 'carb' => 28.0, 'fat' => 0.2, 'fiber' => 1.6],
            ]]]]]]];
        }
        $id = Container::mealPlans()->createDraft((int) $p['id'], 'estruturado', ['title' => 'Por dias', 'structure' => ['v' => 2, 'start' => '2026-11-23', 'days' => $days]], (int) $nutri['id']);
        Container::mealPlans()->publish($id, (int) $nutri['id']);
        $html = View::render('Nutrition/views/_plan_doc.php', ['plan' => Container::mealPlans()->find($id), 'patient' => $p], null);
        $this->assertSame(14, substr_count($html, 'class="plano-dia"'));
        $this->assertStringContainsString('RefD13X', $html);
        $this->assertStringNotContainsString('kcal', $html);
        $res = $this->get('/planos/' . $id . '/pdf');
        $this->assertSame(200, $res->status);
        $this->assertStringStartsWith('%PDF-', $res->body);
    }}
