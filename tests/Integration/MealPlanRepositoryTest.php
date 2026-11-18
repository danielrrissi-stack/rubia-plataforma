<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Audit\AuditLog;
use App\Nutrition\MealPlanCalculator;
use App\Nutrition\MealPlanRepository;
use Tests\DbTestCase;

final class MealPlanRepositoryTest extends DbTestCase
{
    private MealPlanRepository $repo;
    private int $uid;
    private int $pid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new MealPlanRepository($this->db, new AuditLog($this->db));
        $this->uid = (int) $this->makeUser('medico')['id'];
        $this->pid = (int) $this->makePatient()['id'];
    }

    private function structure(string $meal = 'Almoço', int $items = 1, ?string $note = null): array
    {
        $its = [];
        for ($i = 0; $i < $items; $i++) {
            $its[] = [
                'food_id' => 1, 'food_name' => 'Arroz', 'qty' => 2, 'measure_id' => 1,
                'measure_name' => 'colher', 'measure_plural' => 'colheres', 'grams' => 100.0,
                'per100' => ['kcal' => 128.0, 'protein' => 2.5, 'carb' => 25.0, 'fat' => 0.2, 'fiber' => 1.6],
                'note' => null, 'subs' => [],
            ];
        }
        return ['v' => 1, 'meals' => [['name' => $meal, 'time' => '12:00', 'note' => $note, 'options' => [['items' => $its]]]]];
    }

    private function data(?array $structure = null, string $title = 'Plano A', ?string $body = null): array
    {
        return ['title' => $title, 'body_text' => $body, 'guidance_text' => null, 'structure' => $structure];
    }

    private function structured(string $title = 'Plano A'): int
    {
        return $this->repo->createDraft($this->pid, 'estruturado', $this->data($this->structure(), $title), $this->uid);
    }

    private function auditCount(int $id, string $action): int
    {
        return (int) $this->db->one(
            "SELECT COUNT(*) c FROM audit_log WHERE entity='meal_plans' AND entity_id=? AND action=?",
            [$id, $action]
        )['c'];
    }

    public function test_publish_supersedes_previous_and_freezes_totals(): void
    {
        $a = $this->structured('A');
        $this->repo->publish($a, $this->uid);
        $b = $this->structured('B');
        $this->repo->publish($b, $this->uid);

        $ra = $this->repo->find($a);
        $rb = $this->repo->find($b);
        $this->assertSame('substituido', $ra['status']);
        $this->assertNotNull($ra['superseded_at']);
        $this->assertSame('publicado', $rb['status']);
        $expected = round(MealPlanCalculator::day($this->structure())['kcal'], 1);
        $this->assertEquals($expected, (float) $rb['total_kcal']);
        $this->assertSame($b, (int) $this->repo->current($this->pid)['id']);
        $this->assertSame(1, $this->auditCount($b, 'publish'));
    }

    public function test_single_draft_rule(): void
    {
        $id = $this->structured();
        try {
            $this->structured('Outro');
            $this->fail('esperava exceção');
        } catch (\RuntimeException $e) {
            $this->assertSame('Já existe um rascunho para este paciente.', $e->getMessage());
        }
        $this->repo->discard($id, $this->uid);
        $this->assertNull($this->repo->draft($this->pid));
        $this->assertGreaterThan(0, $this->structured('Outro'));
    }

    public function test_published_is_immutable(): void
    {
        $id = $this->structured();
        $this->repo->publish($id, $this->uid);
        $before = $this->repo->find($id);
        try {
            $this->repo->saveDraft($id, $this->data($this->structure(), 'Mudou'), $this->uid);
            $this->fail('esperava exceção');
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, $this->repo->find($id));
    }

    public function test_end_requires_reason_and_hides_current(): void
    {
        $id = $this->structured();
        $this->repo->publish($id, $this->uid);
        try {
            $this->repo->end($id, '  ', $this->uid);
            $this->fail('esperava exceção');
        } catch (\RuntimeException) {
        }
        $this->repo->end($id, 'Paciente errado', $this->uid);
        $this->assertNull($this->repo->current($this->pid));
        $h = $this->repo->history($this->pid);
        $this->assertCount(1, $h);
        $this->assertSame('encerrado', $h[0]['status']);
        $this->assertSame('Paciente errado', $h[0]['ended_reason']);
        $this->assertNotEmpty($h[0]['ender']);
    }

    public function test_draft_from_copies_published(): void
    {
        $id = $this->structured('Base');
        $this->repo->publish($id, $this->uid);
        $orig = $this->repo->find($id);
        $d = $this->repo->draftFrom($id, $this->uid);
        $draft = $this->repo->find($d);
        $this->assertSame('rascunho', $draft['status']);
        $this->assertSame($id, (int) $draft['source_plan_id']);
        $this->assertSame('Base', $draft['title']);
        $this->assertSame($orig['structure'], $draft['structure']);
        $this->assertSame($orig, $this->repo->find($id));
    }

    public function test_conflict_flag(): void
    {
        $id = $this->structured();
        $r1 = $this->repo->saveDraft($id, $this->data($this->structure()), $this->uid, null, false, 1);
        $this->assertFalse($r1['conflict']);
        $this->assertSame(2, $r1['revision']);
        $r2 = $this->repo->saveDraft($id, $this->data($this->structure()), $this->uid, null, false, 1);
        $this->assertTrue($r2['conflict']);
        $this->assertSame(3, $r2['revision']);
    }

    public function test_autosave_audits_at_most_every_10_minutes(): void
    {
        $id = $this->structured();
        for ($i = 0; $i < 3; $i++) {
            $this->repo->saveDraft($id, $this->data($this->structure()), $this->uid, null, true);
        }
        $this->assertSame(1, $this->auditCount($id, 'update'));
        $this->db->run('UPDATE meal_plans SET last_audited_at = NOW() - INTERVAL 11 MINUTE WHERE id = ?', [$id]);
        $this->repo->saveDraft($id, $this->data($this->structure()), $this->uid, null, true);
        $this->assertSame(2, $this->auditCount($id, 'update'));
    }

    public function test_publish_rejects_empty(): void
    {
        $t = $this->repo->createDraft($this->pid, 'texto', $this->data(null, 'Texto', '  '), $this->uid);
        try {
            $this->repo->publish($t, $this->uid);
            $this->fail('esperava exceção');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vazio', $e->getMessage());
        }
        $this->repo->discard($t, $this->uid);

        $s = $this->repo->createDraft($this->pid, 'estruturado', $this->data($this->structure('Almoço', 0)), $this->uid);
        try {
            $this->repo->publish($s, $this->uid);
            $this->fail('esperava exceção');
        } catch (\RuntimeException $e) {
            $this->assertSame('Refeição sem itens: Almoço.', $e->getMessage());
        }
    }

    public function test_publish_rejects_empty_extra_option(): void
    {
        $st = $this->structure('Almoço', 1);
        $st['meals'][0]['options'][] = ['items' => []];
        $id = $this->repo->createDraft($this->pid, 'estruturado', $this->data($st), $this->uid);
        try {
            $this->repo->publish($id, $this->uid);
            $this->fail('esperava exceção');
        } catch (\RuntimeException $e) {
            $this->assertSame('Refeição Almoço: a opção 2 está vazia.', $e->getMessage());
        }
        $this->assertSame('rascunho', $this->repo->find($id)['status']);

        // Mesmo com observacao: refeicao com 2+ opcoes nao publica opcao vazia.
        $st['meals'][0]['note'] = 'fruta à vontade';
        $this->repo->saveDraft($id, $this->data($st), $this->uid);
        try {
            $this->repo->publish($id, $this->uid);
            $this->fail('esperava exceção');
        } catch (\RuntimeException $e) {
            $this->assertSame('Refeição Almoço: a opção 2 está vazia.', $e->getMessage());
        }

        // Refeicao so com observacao (opcao unica vazia) continua valendo.
        $this->repo->saveDraft($id, $this->data($this->structure('Almoço', 0, 'fruta à vontade')), $this->uid);
        $this->repo->publish($id, $this->uid);
        $this->assertSame('publicado', $this->repo->find($id)['status']);
    }

    public function test_text_plan_publishes_with_null_totals(): void
    {
        $t = $this->repo->createDraft($this->pid, 'texto', $this->data(null, 'Texto', 'Café da manhã: pão'), $this->uid);
        $this->repo->publish($t, $this->uid);
        $this->assertNull($this->repo->find($t)['total_kcal']);
    }

    public function test_publish_twice_fails_and_keeps_single_published(): void
    {
        $id = $this->structured();
        $this->repo->publish($id, $this->uid);
        try {
            $this->repo->publish($id, $this->uid);
            $this->fail('esperava exceção');
        } catch (\RuntimeException) {
        }
        $n = $this->db->one("SELECT COUNT(*) c FROM meal_plans WHERE patient_id = ? AND status = 'publicado'", [$this->pid]);
        $this->assertSame(1, (int) $n['c']);
        $this->assertSame(1, $this->auditCount($id, 'publish'));
        $this->assertSame($id, (int) $this->repo->current($this->pid)['id']);
    }

    public function test_audit_rows_carry_full_content(): void
    {
        $id = $this->structured('Completo');
        $create = $this->db->one("SELECT after_json FROM audit_log WHERE entity='meal_plans' AND entity_id=? AND action='create'", [$id]);
        $this->assertEquals($this->structure(), json_decode($create['after_json'], true)['structure']);
        $this->repo->saveDraft($id, $this->data($this->structure(), 'Completo'), $this->uid);
        $upd = $this->db->one("SELECT after_json FROM audit_log WHERE entity='meal_plans' AND entity_id=? AND action='update'", [$id]);
        $this->assertEquals($this->structure(), json_decode($upd['after_json'], true)['structure']);
        $this->repo->publish($id, $this->uid);
        $pub = $this->db->one("SELECT after_json FROM audit_log WHERE entity='meal_plans' AND entity_id=? AND action='publish'", [$id]);
        $after = json_decode($pub['after_json'], true);
        $this->assertEquals($this->structure(), $after['structure']);
        $this->assertSame('Completo', $after['title']);
        $this->assertArrayHasKey('body_text', $after);
        $this->assertArrayHasKey('guidance_text', $after);
    }

    public function test_on_date_returns_plan_valid_that_day(): void
    {
        $a = $this->structured('Plano A');
        $this->repo->publish($a, $this->uid);
        $b = $this->structured('Plano B');
        $this->repo->publish($b, $this->uid);
        $this->db->run('UPDATE meal_plans SET published_at = ?, superseded_at = ? WHERE id = ?', ['2026-11-01 09:00:00', '2026-11-10 08:00:00', $a]);
        $this->db->run('UPDATE meal_plans SET published_at = ? WHERE id = ?', ['2026-11-10 08:00:00', $b]);
        $this->assertNull($this->repo->onDate($this->pid, '2026-10-31'));
        $this->assertSame($a, (int) $this->repo->onDate($this->pid, '2026-11-05')['id']);
        $this->assertSame($a, (int) $this->repo->onDate($this->pid, '2026-11-09')['id']);
        $this->assertSame($b, (int) $this->repo->onDate($this->pid, '2026-11-10')['id']); // dia da troca: o novo vence
        $this->assertIsArray($this->repo->onDate($this->pid, '2026-11-05')['structure']);
        $this->db->run("UPDATE meal_plans SET status = 'encerrado', ended_at = ? WHERE id = ?", ['2026-11-15 10:00:00', $b]);
        $this->assertSame($b, (int) $this->repo->onDate($this->pid, '2026-11-14')['id']);
        $this->assertSame($b, (int) $this->repo->onDate($this->pid, '2026-11-15')['id']); // dia do encerramento ainda vale
        $this->assertNull($this->repo->onDate($this->pid, '2026-11-16'));
    }
}
