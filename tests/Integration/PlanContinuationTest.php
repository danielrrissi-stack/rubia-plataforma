<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Diets\DietController;
use App\Nutrition\MealPlanCalculator;
use App\Nutrition\PlanDays;
use Tests\DbTestCase;

final class PlanContinuationTest extends DbTestCase
{
    private array $doc;
    private string $today;
    private int $mailFloor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->run("DELETE FROM settings WHERE `key` = 'plan_continuation_day'");
        // planos de outros testes/seeds nao entram na contagem da rodada
        $this->db->run("UPDATE meal_plans SET continuation_draft_id = 0 WHERE status = 'publicado'");
        $this->doc = $this->makeUser('medico', ['email' => 'dra.cont@example.test']);
        $this->today = date('Y-m-d');
        $this->mailFloor = (int) $this->db->one('SELECT COALESCE(MAX(id), 0) AS m FROM mail_queue')['m'];
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime($this->today . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' days'));
    }

    private function patient(array $over = []): array
    {
        return $this->makePatient($over + ['full_name' => 'Beatriz Lima Souza', 'birth_date' => date('Y-m-d', strtotime('-30 years')),
            'responsible_professional_id' => $this->doc['id'], 'created_by' => $this->doc['id']]);
    }

    /** Plano v2 publicado de $n dias comecando em $start. */
    private function published(array $patient, string $start, int $n = 14, ?array $by = null, int $kcal = 1800): array
    {
        $by ??= $this->doc;
        $sug = (new DietController())->buildSuggestion((int) $patient['id'], 'adulto', $kcal, $start, $n);
        $repo = Container::mealPlans();
        $id = $repo->createDraft((int) $patient['id'], 'estruturado', [
            'title' => $sug['title'], 'guidance_text' => 'Beba água.', 'structure' => $sug['structure'],
        ], (int) $by['id'], null, null, null, $sug['code'], $n);
        $repo->publish($id, (int) $by['id']);
        return $repo->find($id);
    }

    private function queued(): array
    {
        return $this->db->all('SELECT to_email, subject, body_html FROM mail_queue WHERE id > ? ORDER BY id', [$this->mailFloor]);
    }

    public function test_not_before_6am_and_once_per_day(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10));
        $round = Container::planContinuation();
        $this->assertSame(0, $round->runIfDue($this->today . ' 05:59'));
        $this->assertNull(Container::mealPlans()->draft((int) $p['id']));
        $this->assertSame(1, $round->runIfDue($this->today . ' 06:00'));
        $this->assertNotNull(Container::mealPlans()->draft((int) $p['id']));
        $this->assertSame(0, $round->runIfDue($this->today . ' 06:05'));
        $this->assertSame($this->today, $this->db->one("SELECT value FROM settings WHERE `key` = 'plan_continuation_day'")['value']);
        $this->assertCount(1, $this->queued());
        $this->assertSame((int) Container::mealPlans()->draft((int) $p['id'])['id'], (int) Container::mealPlans()->find((int) $plan['id'])['continuation_draft_id']);
    }

    public function test_triggers_with_4_days_left_not_5(): void
    {
        $four = $this->published($this->patient(), $this->day(-10)); // ultimo dia = hoje + 3
        $five = $this->published($this->patient(), $this->day(-9));  // ultimo dia = hoje + 4
        $ids = array_map(static fn(array $x): int => (int) $x['id'], Container::planContinuation()->due($this->today));
        $this->assertContains((int) $four['id'], $ids);
        $this->assertNotContains((int) $five['id'], $ids);
    }

    public function test_draft_has_remaining_plus_new_days(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10));
        $draftId = Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], true, $this->today);
        $draft = Container::mealPlans()->find($draftId);
        $s = $draft['structure'];
        $this->assertSame(2, (int) $s['v']);
        $this->assertSame($this->today, $s['start']);
        $this->assertCount(18, $s['days']);
        for ($i = 0; $i < 4; $i++) {
            $this->assertEquals($plan['structure']['days'][10 + $i]['meals'], $s['days'][$i]['meals'], 'dia ' . $i);
        }
        $this->assertSame(14, (int) $draft['plan_days']);
        $this->assertSame((int) $plan['id'], (int) $draft['source_plan_id']);
        $this->assertSame(1, (int) $draft['auto_generated']);
        $this->assertSame('Beba água.', $draft['guidance_text']);
        $this->assertSame('Plano alimentar – ' . date('d/m', strtotime($this->today)) . ' a ' . date('d/m', strtotime($this->day(17))), $draft['title']);
        $this->assertSame($draftId, (int) Container::mealPlans()->find((int) $plan['id'])['continuation_draft_id']);
        $audit = $this->db->one("SELECT after_json FROM audit_log WHERE entity = 'meal_plans' AND entity_id = ? AND action = 'continuation'", [(int) $plan['id']]);
        $this->assertNotNull($audit);
        $this->assertTrue(json_decode((string) $audit['after_json'], true)['meta']['auto']);

        $this->expectExceptionMessage('Já existe um rascunho aberto.');
        Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], false, $this->today);
    }

    public function test_uses_current_goal_and_restrictions(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10), 14, null, 1500);
        $this->db->insert('energy_goals', ['patient_id' => $p['id'], 'formula' => 'mifflin', 'activity_factor' => 1.2, 'weight_kg' => 90, 'height_cm' => 165,
            'age_years' => 30, 'tmb_kcal' => 1600, 'get_kcal' => 1990, 'created_by' => $this->doc['id']]);
        Container::foodRestrictions()->save((int) $p['id'], ['ovo'], '', (int) $this->doc['id']);
        $draft = Container::mealPlans()->find(Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], true, $this->today));
        $this->assertStringStartsWith('2000kcal', (string) $draft['source_diet_code']);
        $egg = (require APP_ROOT . '/data/dietas/cardapio.php')['tags']['ovo'];
        foreach (array_slice($draft['structure']['days'], 4) as $i => $day) {
            $kcal = MealPlanCalculator::day(['v' => 1, 'meals' => $day['meals']])['kcal'];
            $this->assertEqualsWithDelta(2000, $kcal, 200, 'dia novo ' . $i);
            foreach ($day['meals'] as $m) {
                foreach ($m['options'] as $o) {
                    foreach ($o['items'] as $it) {
                        $this->assertNotContains($it['food_id'], $egg, $it['food_name']);
                    }
                }
            }
        }
    }

    public function test_without_goal_uses_plan_kcal(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10), 7, null, 1700);
        $draft = Container::mealPlans()->find(Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], true, $this->today));
        $this->assertStringStartsWith('1700kcal', (string) $draft['source_diet_code']);
        $this->assertCount(7, $draft['structure']['days']); // dias 7 ja passaram: 0 restantes + 7 novos
        $this->assertSame($this->today, $draft['structure']['start']);
    }

    public function test_open_manual_draft_is_kept_and_mailed(): void
    {
        $p = $this->patient(['social_name' => 'Bia Souza']);
        $plan = $this->published($p, $this->day(-10));
        $manual = Container::mealPlans()->createDraft((int) $p['id'], 'texto', ['title' => 'Manual', 'body_text' => 'x'], (int) $this->doc['id']);
        $round = Container::planContinuation();
        $this->assertSame(0, $round->runIfDue($this->today . ' 06:00'));
        $d = Container::mealPlans()->draft((int) $p['id']);
        $this->assertSame($manual, (int) $d['id']);
        $this->assertSame('Manual', $d['title']);
        $q = $this->queued();
        $this->assertCount(1, $q);
        $this->assertSame('dra.cont@example.test', $q[0]['to_email']);
        $this->assertSame('Plano alimentar de Bia para revisar', $q[0]['subject']);
        $this->assertStringContainsString('termina em ' . date('d/m/Y', strtotime($this->day(3))) . ' e há um rascunho aberto', $q[0]['body_html']);
        $this->assertStringContainsString('/planos/' . $manual . '/editar', $q[0]['body_html']);
        $this->assertSame(0, (int) Container::mealPlans()->find((int) $plan['id'])['continuation_draft_id']);

        $this->assertSame(0, $round->runIfDue($this->day(1) . ' 06:00'));
        $this->assertCount(1, $this->queued());
    }

    public function test_discarded_continuation_not_regenerated(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10));
        $draftId = Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], true, $this->today);
        Container::mealPlans()->discard($draftId, (int) $this->doc['id']);
        $ids = array_map(static fn(array $x): int => (int) $x['id'], Container::planContinuation()->due($this->day(1)));
        $this->assertNotContains((int) $plan['id'], $ids);
        $this->assertSame(0, Container::planContinuation()->runIfDue($this->day(1) . ' 06:00'));
        $this->assertNull(Container::mealPlans()->draft((int) $p['id']));
    }

    public function test_mail_goes_to_publisher_or_doctors(): void
    {
        $nutri = $this->makeUser('nutricionista', ['email' => 'nutri.cont@example.test']);
        $p1 = $this->patient(['full_name' => 'Carla Dias']);
        $this->db->insert('professional_access', ['patient_id' => $p1['id'], 'professional_id' => $nutri['id'], 'granted_by' => $this->doc['id']]);
        $plan1 = $this->published($p1, $this->day(-10), 14, $nutri);
        $this->assertSame(1, Container::planContinuation()->runIfDue($this->today . ' 06:00'));
        $q = $this->queued();
        $this->assertSame(['nutri.cont@example.test'], array_column($q, 'to_email'));
        $this->assertSame('Plano alimentar de Carla para revisar', $q[0]['subject']);
        $draft = Container::mealPlans()->draft((int) $p1['id']);
        $this->assertStringContainsString('termina em ' . date('d/m/Y', strtotime($this->day(3))) . '.', $q[0]['body_html']);
        $this->assertStringContainsString('http://localhost:8001/planos/' . $draft['id'] . '/editar', $q[0]['body_html']);
        $this->assertSame((int) $nutri['id'], (int) $draft['created_by']);

        // publicadora desativada: medicos ativos
        $p2 = $this->patient(['full_name' => 'Dora Reis']);
        $this->db->insert('professional_access', ['patient_id' => $p2['id'], 'professional_id' => $nutri['id'], 'granted_by' => $this->doc['id']]);
        $this->published($p2, $this->day(-10), 14, $nutri);
        $this->db->run('UPDATE users SET active = 0 WHERE id = ?', [$nutri['id']]);
        $this->makeUser('medico', ['email' => 'inativo.cont@example.test', 'active' => 0]);
        $this->mailFloor = (int) $this->db->one('SELECT COALESCE(MAX(id), 0) AS m FROM mail_queue')['m'];
        $this->assertSame(1, Container::planContinuation()->runIfDue($this->day(1) . ' 06:00'));
        $to = array_column($this->queued(), 'to_email');
        $doctors = array_column($this->db->all("SELECT email FROM users WHERE role = 'medico' AND active = 1"), 'email');
        $this->assertContains('dra.cont@example.test', $to);
        $this->assertNotContains('nutri.cont@example.test', $to);
        $this->assertNotContains('inativo.cont@example.test', $to);
        $this->assertCount(count(array_unique(array_map('strtolower', $doctors))), $to);
        $this->assertNotNull($plan1);
    }

    public function test_one_failing_plan_does_not_block_others(): void
    {
        $pBad = $this->patient(['full_name' => 'Elisa Falha']);
        $bad = $this->published($pBad, $this->day(-10));
        $pOk = $this->patient(['full_name' => 'Fabio Certo']);
        $ok = $this->published($pOk, $this->day(-10));
        // publicador inexistente: o INSERT do rascunho falha por FK (PDOException, nao RuntimeException)
        $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->run('UPDATE meal_plans SET published_by = 999999 WHERE id = ?', [$bad['id']]);
        $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
        $this->assertLessThan((int) $ok['id'], (int) $bad['id'], 'o plano com erro vem antes');

        $this->assertSame(1, Container::planContinuation()->runIfDue($this->today . ' 06:00'));
        $this->assertNull(Container::mealPlans()->draft((int) $pBad['id']));
        $this->assertNull(Container::mealPlans()->find((int) $bad['id'])['continuation_draft_id'], 'fica para o dia seguinte');
        $this->assertNotNull(Container::mealPlans()->draft((int) $pOk['id']));
        $q = $this->queued();
        $this->assertCount(1, $q);
        $this->assertSame('Plano alimentar de Fabio para revisar', $q[0]['subject']);
        $this->assertSame($this->today, $this->db->one("SELECT value FROM settings WHERE `key` = 'plan_continuation_day'")['value']);
    }

    public function test_null_publisher_falls_back_to_doctor(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10));
        $this->db->run('UPDATE meal_plans SET published_by = NULL WHERE id = ?', [$plan['id']]);
        $this->assertSame(1, Container::planContinuation()->runIfDue($this->today . ' 06:00'));
        $first = (int) $this->db->one("SELECT id FROM users WHERE role = 'medico' AND active = 1 ORDER BY id LIMIT 1")['id'];
        $draft = Container::mealPlans()->draft((int) $p['id']);
        $this->assertSame($first, (int) $draft['created_by']);
        $this->assertContains('dra.cont@example.test', array_column($this->queued(), 'to_email'));
    }

    public function test_auto_skips_when_marker_set_after_due(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10));
        $this->assertContains((int) $plan['id'], array_map(static fn(array $x): int => (int) $x['id'], Container::planContinuation()->due($this->today)));
        // entre due() e generate(): continuacao criada e descartada
        $draftId = Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], false, $this->today);
        Container::mealPlans()->discard($draftId, (int) $this->doc['id']);
        try {
            Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], true, $this->today);
            $this->fail('a rodada nao pode refazer a continuacao');
        } catch (\RuntimeException $e) {
            $this->assertSame('A continuação deste plano já foi tratada.', $e->getMessage());
        }
        $this->assertNull(Container::mealPlans()->draft((int) $p['id']));
        // o botao (auto = false) continua podendo gerar
        $this->assertGreaterThan($draftId, Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], false, $this->today));
    }

    public function test_publishing_continuation_has_no_gap(): void
    {
        $p = $this->patient();
        $plan = $this->published($p, $this->day(-10));
        $draftId = Container::planContinuation()->generate((int) $plan['id'], (int) $this->doc['id'], true, $this->today);
        Container::mealPlans()->publish($draftId, (int) $this->doc['id']);
        $cur = Container::mealPlans()->current((int) $p['id']);
        $this->assertSame($draftId, (int) $cur['id']);
        $this->assertSame(14, (int) $cur['plan_days']);
        $w = PlanDays::window($cur['structure'], $this->today);
        $this->assertSame(0, $w[0]['index']);
        $this->assertSame($this->today, $w[0]['date']);
        $old = PlanDays::dayStructure($plan['structure'], (int) PlanDays::indexFor($plan['structure'], $this->today));
        $this->assertEquals($old['meals'], PlanDays::dayStructure($cur['structure'], 0)['meals']);
    }
}
