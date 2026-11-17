<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class TemplateRepositoryTest extends DbTestCase
{
    private function publishedPlan(array $admin, array $patient): int
    {
        $id = Container::mealPlans()->createDraft((int) $patient['id'], 'texto', [
            'title' => 'Plano origem', 'body_text' => "CAFE\n• pao", 'guidance_text' => 'Beber agua', 'structure' => null,
        ], (int) $admin['id']);
        Container::mealPlans()->publish($id, (int) $admin['id']);
        return $id;
    }

    public function test_from_plan_copies_and_is_independent(): void
    {
        $admin = $this->makeUser('medico', ['is_admin' => 1]);
        $p = $this->makePatient(['responsible_professional_id' => $admin['id'], 'created_by' => $admin['id']]);
        $planId = $this->publishedPlan($admin, $p);
        $tid = Container::mealPlanTemplates()->fromPlan($planId, 'Meu modelo', (int) $admin['id']);
        $t = Container::mealPlanTemplates()->find($tid);
        $this->assertSame('texto', $t['mode']);
        $this->assertSame("CAFE\n• pao", $t['body_text']);
        $this->assertSame('Beber agua', $t['guidance_text']);

        $draft = Container::mealPlans()->createDraft((int) $p['id'], 'texto', [
            'title' => 'x', 'body_text' => $t['body_text'], 'guidance_text' => null, 'structure' => null,
        ], (int) $admin['id'], null, $tid);
        Container::mealPlanTemplates()->update($tid, [
            'title' => 'Outro titulo', 'mode' => 'texto', 'body_text' => 'mudou', 'guidance_text' => '', 'structure' => null,
        ], $admin);
        $this->assertSame("CAFE\n• pao", Container::mealPlans()->find($planId)['body_text']);
        $this->assertSame("CAFE\n• pao", Container::mealPlans()->find($draft)['body_text']);
        $this->assertSame('mudou', Container::mealPlanTemplates()->find($tid)['body_text']);
    }

    public function test_autosave_audit_is_throttled(): void
    {
        $a = $this->makeUser('nutricionista');
        $repo = Container::mealPlanTemplates();
        $tid = $repo->create(['title' => 'Modelo A', 'mode' => 'texto', 'body_text' => 'x', 'guidance_text' => null, 'structure' => null], (int) $a['id']);
        $count = fn(): int => (int) $this->db->one(
            "SELECT COUNT(*) c FROM audit_log WHERE entity = 'meal_plan_templates' AND entity_id = ? AND action = 'update'",
            [$tid],
        )['c'];
        $upd = ['title' => 'Modelo A', 'mode' => 'texto', 'body_text' => 'y', 'guidance_text' => null, 'structure' => null];
        $repo->update($tid, $upd, $a, null, true);
        $repo->update($tid, $upd, $a, null, true);
        $repo->update($tid, $upd, $a, null, true);
        $this->assertSame(1, $count());                 // automaticos: no maximo um a cada 10 min
        $repo->update($tid, $upd, $a);
        $this->assertSame(2, $count());                 // explicito: sempre
        $this->db->run("UPDATE audit_log SET created_at = NOW() - INTERVAL 11 MINUTE WHERE entity = 'meal_plan_templates' AND entity_id = ?", [$tid]);
        $repo->update($tid, $upd, $a, null, true);
        $this->assertSame(3, $count());
    }

    public function test_authorship_rule_and_delete(): void
    {
        $admin = $this->makeUser('medico', ['is_admin' => 1]);
        $a = $this->makeUser('nutricionista');
        $b = $this->makeUser('nutricionista');
        $repo = Container::mealPlanTemplates();
        $tid = $repo->create(['title' => 'Modelo A', 'mode' => 'texto', 'body_text' => 'x', 'guidance_text' => null, 'structure' => null], (int) $a['id']);
        $upd = ['title' => 'Novo', 'mode' => 'texto', 'body_text' => 'y', 'guidance_text' => null, 'structure' => null];
        try {
            $repo->update($tid, $upd, $b);
            $this->fail('B nao pode editar');
        } catch (\RuntimeException $e) {
            $this->assertSame('Somente quem criou o modelo ou a administradora pode alterá-lo.', $e->getMessage());
        }
        $repo->update($tid, $upd, $admin);
        $this->assertSame('Novo', $repo->find($tid)['title']);
        try {
            $repo->delete($tid, $b);
            $this->fail('B nao pode excluir');
        } catch (\RuntimeException) {
        }
        $repo->delete($tid, $a);
        $this->assertSame([], array_column($repo->all(), 'id'));
        $this->assertNull($repo->find($tid));
    }

    public function test_title_validation(): void
    {
        $a = $this->makeUser('nutricionista');
        $this->expectException(\RuntimeException::class);
        Container::mealPlanTemplates()->create(['title' => 'x', 'mode' => 'texto', 'body_text' => null, 'guidance_text' => null, 'structure' => null], (int) $a['id']);
    }

    public function test_snippets(): void
    {
        $a = $this->makeUser('nutricionista');
        $b = $this->makeUser('nutricionista');
        $repo = Container::textSnippets();
        $id = $repo->create('Hidratação', 'Beber 2 litros de água por dia.', (int) $a['id']);
        $this->assertSame(['Hidratação'], array_column($repo->all(), 'title'));
        try {
            $repo->update($id, 'Outro', 'corpo', $b);
            $this->fail('B nao pode editar');
        } catch (\RuntimeException $e) {
            $this->assertSame('Somente quem criou o trecho ou a administradora pode alterá-lo.', $e->getMessage());
        }
        $repo->update($id, 'Hidratação 2', 'corpo novo', $a);
        $this->assertSame('corpo novo', $repo->find($id)['body']);
        $repo->delete($id, $a);
        $this->assertSame([], $repo->all());
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE entity='text_snippets' AND action='delete'")['c']);
    }
}
