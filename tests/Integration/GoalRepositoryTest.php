<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Goals\InvalidGoalException;
use Tests\DbTestCase;

final class GoalRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18'; // quarta-feira

    private array $medico;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function child(): array
    {
        $c = $this->makeUser('crianca');
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$c['id'], $this->patient['id']]);
        return $c;
    }

    private function data(array $over = []): array
    {
        return $over + [
            'family_text' => 'Beber água nas refeições', 'child_text' => null, 'emoji' => '💧', 'category' => 'hidratacao',
            'times_per_week' => 7, 'start_date' => '2026-11-01', 'end_date' => null, 'secret' => 0, 'template_id' => null,
        ];
    }

    private function goal(array $over = []): int
    {
        return Container::goals()->create((int) $this->patient['id'], $this->data($over), (int) $this->medico['id'], $this->r());
    }

    private function invalid(callable $fn): array
    {
        try {
            $fn();
        } catch (InvalidGoalException $e) {
            return $e->errors;
        }
        $this->fail('Deveria lançar InvalidGoalException.');
    }

    private function countChecks(int $goalId): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM goal_checks WHERE goal_id = ?', [$goalId])['n'];
    }

    public function test_create_validates_and_audits(): void
    {
        $pid = (int) $this->patient['id'];
        $uid = (int) $this->medico['id'];
        $goals = Container::goals();
        foreach ([
            ['family_text' => ''],
            ['category' => 'dieta'],
            ['times_per_week' => 0],
            ['times_per_week' => 8],
            ['start_date' => '2026-11-10', 'end_date' => '2026-11-09'],
            ['secret' => 1], // paciente sem conta de crianca
        ] as $bad) {
            $this->assertNotEmpty($this->invalid(fn () => $goals->create($pid, $this->data($bad), $uid, $this->r())), json_encode($bad));
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM patient_goals')['n']);

        $id = $this->goal();
        $row = $this->db->one('SELECT * FROM patient_goals WHERE id = ?', [$id]);
        $this->assertSame($pid, (int) $row['patient_id']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'create' AND entity = 'patient_goals' AND entity_id = ?", [$id])['n']);

        $this->child();
        $this->assertGreaterThan(0, $this->goal(['secret' => 1]));
    }

    public function test_check_is_idempotent(): void
    {
        $id = $this->goal();
        $u = $this->makeUser('responsavel');
        $goals = Container::goals();
        $goals->check($id, '2026-11-17', $u, self::TODAY, $this->r());
        $goals->check($id, '2026-11-17', $u, self::TODAY, $this->r());
        $this->assertSame(1, $this->countChecks($id));
        $this->assertSame('responsavel', $this->db->one('SELECT checked_by_role r FROM goal_checks WHERE goal_id = ?', [$id])['r']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'check' AND entity = 'goal_checks'")['n']);

        $goals->uncheck($id, '2026-11-17', $u, self::TODAY, $this->r());
        $this->assertSame(0, $this->countChecks($id));
    }

    public function test_check_outside_window_or_period_rejected(): void
    {
        $id = $this->goal(['start_date' => '2026-11-14', 'end_date' => '2026-11-17']);
        $u = $this->makeUser('responsavel');
        $goals = Container::goals();
        foreach (['2026-11-19', '2026-11-18', '2026-11-13', '2026-11-11'] as $day) {
            $this->invalid(fn () => $goals->check($id, $day, $u, self::TODAY, $this->r()));
        }
        $this->assertSame(0, $this->countChecks($id));
        $goals->check($id, '2026-11-14', $u, self::TODAY, $this->r());
        $this->assertSame(1, $this->countChecks($id));
    }

    public function test_for_viewer_visibility(): void
    {
        $child = $this->child();
        $this->goal(['category' => 'alimentacao']);
        $this->goal(['category' => 'sono']);
        $this->goal(['category' => 'rotina', 'secret' => 1]);
        $ids = fn (array $v): array => array_column(Container::goals()->forViewer((int) $this->patient['id'], $v, '2026-11-12', self::TODAY)['goals'], 'category');

        $nutri = $this->makeUser('nutricionista');
        $this->assertSame(['alimentacao'], $ids($nutri));
        $resp = $this->makeUser('responsavel');
        $this->assertSame(['alimentacao', 'sono'], $ids($resp));
        $this->assertCount(3, $ids($this->medico));
        $this->assertCount(3, $ids($child));
        $this->assertCount(3, $ids($this->makeUser('paciente')));
        $this->assertSame([], $ids($this->makeUser('secretaria')));

        // periodo: meta terminada antes de `from` nao aparece
        $this->goal(['category' => 'telas', 'start_date' => '2026-10-01', 'end_date' => '2026-11-11']);
        $this->assertCount(3, $ids($this->medico));
        $this->assertCount(4, Container::goals()->allForViewer((int) $this->patient['id'], $this->medico)['goals']);

        // marcacoes: crianca e responsavel marcam a meta de alimentacao
        $food = (int) $this->db->one("SELECT id FROM patient_goals WHERE category = 'alimentacao'")['id'];
        Container::goals()->check($food, '2026-11-16', $child, self::TODAY, $this->r());
        Container::goals()->check($food, '2026-11-17', $resp, self::TODAY, $this->r());
        $checks = fn (array $v): array => array_column(Container::goals()->forViewer((int) $this->patient['id'], $v, '2026-11-12', self::TODAY)['checks'], 'day');
        $this->assertSame(['2026-11-16', '2026-11-17'], $checks($resp));

        $this->db->run('UPDATE patients SET guardians_see_child_checks = 0 WHERE id = ?', [$this->patient['id']]);
        $this->assertSame(['2026-11-17'], $checks($resp));
        $this->assertSame(['2026-11-16', '2026-11-17'], $checks($child));
        $this->assertSame(['2026-11-16', '2026-11-17'], $checks($this->medico));

        // from/to limitam as marcacoes
        $only = Container::goals()->forViewer((int) $this->patient['id'], $this->medico, '2026-11-17', '2026-11-17')['checks'];
        $this->assertSame(['2026-11-17'], array_column($only, 'day'));
    }

    public function test_guardian_cannot_check_secret_goal(): void
    {
        $child = $this->child();
        $id = $this->goal(['secret' => 1]);
        $resp = $this->makeUser('responsavel');
        $this->invalid(fn () => Container::goals()->check($id, '2026-11-17', $resp, self::TODAY, $this->r()));
        $this->assertSame(0, $this->countChecks($id));
        Container::goals()->check($id, '2026-11-17', $child, self::TODAY, $this->r());
        $this->assertSame(1, $this->countChecks($id));
        $this->invalid(fn () => Container::goals()->uncheck($id, '2026-11-17', $resp, self::TODAY, $this->r()));
        $this->assertSame(1, $this->countChecks($id));
    }

    public function test_update_end_and_delete(): void
    {
        $id = $this->goal();
        $uid = (int) $this->medico['id'];
        $goals = Container::goals();
        $goals->update($id, $this->data(['family_text' => 'Beber mais água', 'times_per_week' => 5]), $uid, $this->r());
        $this->assertSame('Beber mais água', $goals->find($id)['family_text']);
        $this->invalid(fn () => $goals->update($id, $this->data(['times_per_week' => 9]), $uid, $this->r()));
        $this->invalid(fn () => $goals->end($id, '2026-10-01', $uid, $this->r()));
        $goals->end($id, '2026-11-20', $uid, $this->r());
        $this->assertSame('2026-11-20', $goals->find($id)['end_date']);
        $goals->delete($id, $uid, $this->r());
        $this->assertSame([], $goals->allForViewer((int) $this->patient['id'], $this->medico)['goals']);
        foreach (['update', 'end', 'delete'] as $a) {
            $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = ? AND entity = 'patient_goals'", [$a])['n'], $a);
        }
    }

    public function test_seed_templates_cover_all_categories(): void
    {
        $templates = Container::goals()->templates();
        $this->assertGreaterThanOrEqual(18, count($templates));
        $by = [];
        foreach ($templates as $t) {
            $by[$t['category']][] = $t;
            $this->assertNotSame('', trim((string) $t['child_text']));
            $this->assertGreaterThanOrEqual(1, (int) $t['times_per_week']);
            $this->assertLessThanOrEqual(7, (int) $t['times_per_week']);
        }
        foreach (['alimentacao', 'hidratacao', 'sono', 'atividade', 'telas', 'rotina'] as $c) {
            $this->assertGreaterThanOrEqual(3, count($by[$c] ?? []), $c);
        }
        $this->assertNotNull(Container::goals()->findTemplate((int) $templates[0]['id']));
    }
}
