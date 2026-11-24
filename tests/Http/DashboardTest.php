<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class DashboardTest extends DbTestCase
{
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

    private function pendingPatient(): array
    {
        $p = $this->makePatient(['birth_date' => '2000-01-01', 'full_name' => 'Jovem Maior']);
        $g = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $p['created_by']]);
        return $p;
    }

    public function testDoctorSeesMajorityBlock(): void
    {
        $this->pendingPatient();
        $this->loginAs($this->makeUser('medico'));
        $res = $this->get('/');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Decisões de maioridade pendentes', $res->body);
        $this->assertStringContainsString('Jovem Maior', $res->body);
    }

    public function testSecretaryDoesNotSeeMajorityBlock(): void
    {
        $this->pendingPatient();
        $this->loginAs($this->makeUser('secretaria'));
        $res = $this->get('/');
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('Decisões de maioridade pendentes', $res->body);
        $this->assertStringContainsString('Novo paciente', $res->body);
        $this->assertSame(403, $this->get('/maioridade')->status);
    }

    public function testFamilyRedirectedToPortal(): void
    {
        $this->loginAs($this->makeUser('responsavel'));
        $res = $this->get('/');
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal', $res->headers['Location']);
    }

    public function testNutritionistSeesOnlyGrantedPatients(): void
    {
        $med = $this->makeUser('medico');
        $nut = $this->makeUser('nutricionista');
        $a = $this->makePatient(['full_name' => 'Liberado Um']);
        $this->makePatient(['full_name' => 'Oculto Dois']);
        Container::accessGrants()->grant((int) $a['id'], (int) $nut['id'], (int) $med['id']);
        $this->loginAs($nut);
        $res = $this->get('/');
        $this->assertStringContainsString('Liberado Um', $res->body);
        $this->assertStringNotContainsString('Oculto Dois', $res->body);
    }

    /** Plano v2 publicado de $n dias comecando em $start. */
    private function publishedV2(int $pid, int $by, string $start, int $n = 14): int
    {
        $days = [];
        for ($i = 0; $i < $n; $i++) {
            $days[] = ['meals' => [['name' => 'Cafe', 'time' => '', 'note' => null, 'options' => [['items' => [[
                'food_id' => 1, 'food_name' => 'Arroz', 'qty' => 2, 'measure_id' => 1, 'measure_name' => 'colher', 'measure_plural' => 'colheres',
                'grams' => 100.0, 'per100' => ['kcal' => 128.0, 'protein' => 2.5, 'carb' => 25.0, 'fat' => 0.2, 'fiber' => 1.6],
                'note' => null, 'subs' => [],
            ]]]]]]];
        }
        $id = Container::mealPlans()->createDraft($pid, 'estruturado', ['title' => 'Plano', 'structure' => ['v' => 2, 'start' => $start, 'days' => $days]], $by);
        $this->db->run('UPDATE meal_plans SET plan_days = ? WHERE id = ?', [$n, $id]);
        Container::mealPlans()->publish($id, $by);
        return $id;
    }

    private function dayOffset(int $n): string
    {
        return date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
    }

    public function testReviewCardShowsPendingAndExpired(): void
    {
        $med = $this->makeUser('medico');
        $a = $this->makePatient(['full_name' => 'Pendente Silva']);
        $b = $this->makePatient(['full_name' => 'Vencido Souza']);
        $planA = $this->publishedV2((int) $a['id'], (int) $med['id'], $this->dayOffset(-10)); // termina em hoje+3
        $draft = Container::mealPlans()->createDraft((int) $a['id'], 'texto', ['title' => 'Cont', 'body_text' => 'x'], (int) $med['id']);
        $this->db->run('UPDATE meal_plans SET continuation_draft_id = ? WHERE id = ?', [$draft, $planA]);
        $this->publishedV2((int) $b['id'], (int) $med['id'], $this->dayOffset(-20)); // terminou ha 7 dias
        $this->loginAs($med);
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Planos para revisar', $body);
        $this->assertStringContainsString('Pendente Silva', $body);
        $this->assertStringContainsString('termina em ' . date('d/m', strtotime('+3 days')), $body);
        $this->assertStringContainsString('/planos/' . $draft . '/editar', $body);
        $this->assertStringContainsString('Vencido Souza', $body);
        $this->assertStringContainsString('família vendo repetição desde ' . date('d/m', strtotime('-6 days')), $body);
        $this->assertStringContainsString('selo selo-alerta">vencido', $body);
        $this->assertStringContainsString('Gerar continuação agora', $body);
        $this->assertLessThan(strpos($body, 'Pendente Silva'), strpos($body, 'Vencido Souza'));
    }

    public function testReviewCardShowsSemContinuacaoWhenDraftDiscarded(): void
    {
        $med = $this->makeUser('medico');
        $a = $this->makePatient(['full_name' => 'Descartada Lima']);
        $plan = $this->publishedV2((int) $a['id'], (int) $med['id'], $this->dayOffset(-10));
        $draft = Container::mealPlans()->createDraft((int) $a['id'], 'texto', ['title' => 'Cont', 'body_text' => 'x'], (int) $med['id']);
        $this->db->run('UPDATE meal_plans SET continuation_draft_id = ? WHERE id = ?', [$draft, $plan]);
        Container::mealPlans()->discard($draft, (int) $med['id'], new Request('POST', '/x'));
        $this->loginAs($med);
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Descartada Lima', $body);
        $this->assertStringContainsString('sem continuação', $body);
        $this->assertStringContainsString('Gerar continuação agora', $body);
    }

    public function testReviewCardOnlyForGrantedPatients(): void
    {
        $med = $this->makeUser('medico');
        $nut = $this->makeUser('nutricionista');
        $a = $this->makePatient(['full_name' => 'Liberado Plano']);
        $b = $this->makePatient(['full_name' => 'Oculto Plano']);
        $this->publishedV2((int) $a['id'], (int) $med['id'], $this->dayOffset(-10));
        $this->publishedV2((int) $b['id'], (int) $med['id'], $this->dayOffset(-10));
        Container::accessGrants()->grant((int) $a['id'], (int) $nut['id'], (int) $med['id']);
        $this->loginAs($nut);
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Planos para revisar', $body);
        $this->assertStringContainsString('Liberado Plano', $body);
        $this->assertStringNotContainsString('Oculto Plano', $body);
    }

    public function testNoCardWhenNothingToReview(): void
    {
        $med = $this->makeUser('medico');
        $a = $this->makePatient();
        $this->publishedV2((int) $a['id'], (int) $med['id'], $this->dayOffset(0)); // termina em 13 dias
        $this->loginAs($med);
        $this->assertStringNotContainsString('Planos para revisar', $this->get('/')->body);
    }

    public function testDoctorGrantsAndRevokesViaHttp(): void
    {
        $med = $this->makeUser('medico');
        $nut = $this->makeUser('nutricionista');
        $sec = $this->makeUser('secretaria');
        $p = $this->makePatient();
        $this->loginAs($med);
        $this->assertSame(302, $this->post('/pacientes/' . $p['id'] . '/acesso-profissionais', ['profissional' => (string) $sec['id']])->status);
        $this->assertSame([], Container::accessGrants()->activeFor((int) $p['id']));
        $this->post('/pacientes/' . $p['id'] . '/acesso-profissionais', ['profissional' => (string) $nut['id']]);
        $grants = Container::accessGrants()->activeFor((int) $p['id']);
        $this->assertCount(1, $grants);
        $this->assertStringContainsString($nut['name'], $this->get('/pacientes/' . $p['id'] . '/acesso-profissionais')->body);
        $this->post('/acesso-profissionais/' . $grants[0]['id'] . '/revogar');
        $this->assertSame([], Container::accessGrants()->activeFor((int) $p['id']));
    }

    public function testSecretaryCannotManageGrants(): void
    {
        $p = $this->makePatient();
        $this->loginAs($this->makeUser('secretaria'));
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/acesso-profissionais')->status);
    }

    public function testMajorityDecisionViaHttp(): void
    {
        $p = $this->pendingPatient();
        $this->loginAs($this->makeUser('medico'));
        $this->assertSame(200, $this->get('/maioridade')->status);
        $res = $this->post('/pacientes/' . $p['id'] . '/maioridade', ['decisao' => 'encerrar', 'observacoes' => 'x']);
        $this->assertSame(302, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM guardian_links WHERE patient_id = ? AND active = 1', [$p['id']])['c']);
    }
}
