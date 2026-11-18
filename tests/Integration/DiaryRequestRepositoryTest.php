<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Diary\InvalidDiaryException;
use Tests\DbTestCase;

final class DiaryRequestRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18';

    private array $medico;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2015-03-10']);
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function data(array $over = []): array
    {
        return $over + ['type' => 'agua', 'weekdays' => 127, 'start_date' => '2026-11-02', 'end_date' => '', 'guidance' => '', 'secret' => 0];
    }

    private function make(array $over = [], ?array $user = null, ?array $patient = null): int
    {
        return Container::diaryRequests()->create((int) ($patient ?? $this->patient)['id'], $this->data($over), $user ?? $this->medico, self::TODAY, $this->r());
    }

    private function audits(string $action, string $entity = 'diary_requests'): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM audit_log WHERE action = ? AND entity = ?', [$action, $entity])['n'];
    }

    private function rejects(callable $fn, ?string $message = null): void
    {
        try {
            $fn();
            $this->fail('Esperava InvalidDiaryException');
        } catch (InvalidDiaryException $e) {
            $this->assertNotSame([], $e->errors());
            if ($message !== null) {
                $this->assertContains($message, $e->errors());
            }
        }
    }

    private function addEntry(int $requestId): void
    {
        $this->db->insert('diary_entries', [
            'request_id' => $requestId, 'day' => '2026-11-17', 'glasses' => 5,
            'recorded_by' => $this->medico['id'], 'recorded_by_role' => 'medico',
        ]);
    }

    public function test_create_and_applies_on_weekdays(): void
    {
        $id = $this->make(['type' => 'medidas', 'weekdays' => [1]]);
        $p = Container::diaryRequests()->find($id);
        $this->assertSame(1, (int) $p['weekdays']);
        $repo = Container::diaryRequests();
        $this->assertTrue($repo->appliesOn($p, '2026-11-09'));
        $this->assertFalse($repo->appliesOn($p, '2026-11-10'));
        $this->assertFalse($repo->appliesOn($p, '2026-11-01'));
        $this->assertSame(1, $this->audits('create'));
    }

    public function test_validation(): void
    {
        $this->rejects(fn () => $this->make(['type' => 'xx']));
        $this->rejects(fn () => $this->make(['weekdays' => 0]), 'Escolha pelo menos um dia da semana.');
        $this->rejects(fn () => $this->make(['weekdays' => []]), 'Escolha pelo menos um dia da semana.');
        $this->rejects(fn () => $this->make(['start_date' => '']));
        $this->rejects(fn () => $this->make(['start_date' => '2040-01-01']));
        $this->rejects(fn () => $this->make(['end_date' => '2026-10-01']));
        $this->rejects(fn () => $this->make(['guidance' => str_repeat('a', 256)]));
        $this->assertSame(0, $this->audits('create'));
    }

    public function test_overlap_is_rejected_but_disjoint_days_allowed(): void
    {
        $this->make(['type' => 'agua', 'weekdays' => [1, 2, 3, 4, 5]]);
        $this->make(['type' => 'agua', 'weekdays' => [6, 7]]);
        $this->rejects(fn () => $this->make(['type' => 'agua', 'weekdays' => [3]]), 'Já existe um pedido de Água para esses dias.');
        $this->make(['type' => 'sono', 'weekdays' => [3]]); // outro tipo
        $this->make(['type' => 'medidas', 'weekdays' => [1], 'start_date' => '2026-01-01', 'end_date' => '2026-02-01']);
        $this->make(['type' => 'medidas', 'weekdays' => [1], 'start_date' => '2026-03-01']); // periodo encerrado antes
        $this->rejects(fn () => $this->make(['type' => 'medidas', 'weekdays' => [1], 'start_date' => '2026-02-01', 'end_date' => '2026-02-10']));
    }

    public function test_update_does_not_overlap_with_itself_and_excluded_ignored(): void
    {
        $a = $this->make(['type' => 'agua', 'weekdays' => [1]]);
        Container::diaryRequests()->update($a, $this->data(['weekdays' => [1, 2]]), $this->medico, self::TODAY, $this->r());
        $this->assertSame(3, (int) Container::diaryRequests()->find($a)['weekdays']);
        $this->assertSame(1, $this->audits('update'));
        Container::diaryRequests()->delete($a, (int) $this->medico['id'], $this->r());
        $this->make(['type' => 'agua', 'weekdays' => [1]]);
    }

    public function test_type_locked_and_delete_blocked_with_entries(): void
    {
        $id = $this->make(['type' => 'agua']);
        Container::diaryRequests()->update($id, $this->data(['type' => 'sono']), $this->medico, self::TODAY, $this->r()); // sem registros: pode
        $this->assertSame('sono', Container::diaryRequests()->find($id)['type']);
        $this->addEntry($id);
        $this->assertTrue(Container::diaryRequests()->hasEntries($id));
        $this->rejects(fn () => Container::diaryRequests()->update($id, $this->data(['type' => 'tela']), $this->medico, self::TODAY, $this->r()), 'O tipo não pode mudar depois que há registros.');
        $this->rejects(fn () => Container::diaryRequests()->delete($id, (int) $this->medico['id'], $this->r()), 'Este pedido já tem registros; encerre em vez de excluir.');
        $this->assertNull(Container::diaryRequests()->find($id)['deleted_at']);
    }

    public function test_delete_without_entries_is_logical(): void
    {
        $id = $this->make();
        Container::diaryRequests()->delete($id, (int) $this->medico['id'], $this->r());
        $this->assertNotNull(Container::diaryRequests()->find($id)['deleted_at']);
        $this->assertSame(1, $this->audits('delete'));
    }

    public function test_end_rules(): void
    {
        $id = $this->make();
        Container::diaryRequests()->end($id, (int) $this->medico['id'], self::TODAY, $this->r());
        $this->assertSame(self::TODAY, Container::diaryRequests()->find($id)['end_date']);
        $this->assertSame(1, $this->audits('end'));
        $future = $this->make(['type' => 'sono', 'start_date' => '2026-12-01']);
        $this->rejects(fn () => Container::diaryRequests()->end($future, (int) $this->medico['id'], self::TODAY, $this->r()), 'Pedido que ainda não começou: exclua em vez de encerrar.');
        $this->assertNull(Container::diaryRequests()->find($future)['end_date']);
    }

    public function test_secret_rules(): void
    {
        $repo = Container::diaryRequests();
        $pid = (int) $this->patient['id'];
        $this->assertTrue($repo->mayMarkSecret($this->medico, $pid, self::TODAY));
        $this->assertSame(1, (int) $repo->find($this->make(['secret' => 1]))['secret']);

        $adult = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2008-11-18']);
        $this->assertFalse($repo->mayMarkSecret($this->medico, (int) $adult['id'], self::TODAY));
        $this->assertSame(0, (int) $repo->find($this->make(['secret' => 1], null, $adult))['secret']);

        $nutri = $this->makeUser('nutricionista');
        $this->assertFalse($repo->mayMarkSecret($nutri, $pid, self::TODAY));
        $this->assertSame(0, (int) $repo->find($this->make(['type' => 'alimentacao', 'secret' => 1], $nutri))['secret']);

        $id = $this->make(['type' => 'sono', 'secret' => 1]);
        $repo->update($id, $this->data(['type' => 'sono', 'guidance' => 'x']), $this->medico, self::TODAY, $this->r());
        $this->assertSame(1, (int) $repo->find($id)['secret']); // R6: sem secret_shown mantem
        $repo->update($id, $this->data(['type' => 'sono', 'secret_shown' => 1]), $this->medico, self::TODAY, $this->r());
        $this->assertSame(0, (int) $repo->find($id)['secret']); // so a medica desmarca
    }

    public function test_visibility_rules(): void
    {
        $repo = Container::diaryRequests();
        $secret = $this->make(['type' => 'alimentacao', 'secret' => 1]);
        $sono = $this->make(['type' => 'sono']);
        $agua = $this->make(['type' => 'agua']);
        $this->assertGreaterThan(0, $agua);
        $viewers = [];
        foreach (['medico', 'paciente', 'responsavel', 'nutricionista', 'secretaria', 'crianca'] as $role) {
            $viewers[$role] = $this->makeUser($role);
        }
        $expect = [
            //                 sigiloso sono agua
            'medico' => [true, true, true],
            'paciente' => [true, true, true],
            'responsavel' => [false, true, true],
            'nutricionista' => [false, false, true],
            'secretaria' => [false, false, false],
            'crianca' => [false, false, false],
        ];
        foreach ($expect as $role => [$s, $z, $a]) {
            $v = $viewers[$role];
            $this->assertSame($s, $repo->canSee($repo->find($secret), $v), $role . ' sigiloso');
            $this->assertSame($z, $repo->canSee($repo->find($sono), $v), $role . ' sono');
            $this->assertSame($a, $repo->canSee($repo->find($agua), $v), $role . ' agua');
            $this->assertSame($s ? true : false, $repo->visibleTo($secret, $v) !== null);
        }

        $pid = (int) $this->patient['id'];
        $this->assertCount(3, $repo->forViewer($pid, $viewers['medico'], '2026-11-01', '2026-11-30'));
        $ids = array_column($repo->forViewer($pid, $viewers['responsavel'], '2026-11-01', '2026-11-30'), 'id');
        $this->assertEqualsCanonicalizing([$sono, $agua], array_map('intval', $ids));
        $ids = array_column($repo->forViewer($pid, $viewers['nutricionista'], '2026-11-01', '2026-11-30'), 'id');
        $this->assertSame([$agua], array_map('intval', $ids));
        $this->assertSame([], $repo->forViewer($pid, $viewers['secretaria'], '2026-11-01', '2026-11-30'));

        // fora do periodo consultado
        $this->assertSame([], $repo->forViewer($pid, $viewers['medico'], '2025-01-01', '2025-01-31'));

        // excluido some para todos
        $repo->delete($sono, (int) $this->medico['id'], $this->r());
        foreach ($viewers as $v) {
            $this->assertNull($repo->visibleTo($sono, $v));
            $this->assertFalse($repo->canSee($repo->find($sono), $v));
        }
        $this->assertCount(2, $repo->forViewer($pid, $viewers['medico'], '2026-11-01', '2026-11-30'));
    }

    public function test_for_viewer_orders_by_type_then_start(): void
    {
        $repo = Container::diaryRequests();
        $tela = $this->make(['type' => 'tela']);
        $agua2 = $this->make(['type' => 'agua', 'weekdays' => [6], 'start_date' => '2026-11-10']);
        $agua1 = $this->make(['type' => 'agua', 'weekdays' => [1], 'start_date' => '2026-11-03']);
        $ali = $this->make(['type' => 'alimentacao']);
        $ids = array_map('intval', array_column($repo->forViewer((int) $this->patient['id'], $this->medico, '2026-11-01', '2026-11-30'), 'id'));
        $this->assertSame([$ali, $agua1, $agua2, $tela], $ids);
    }

    public function test_list_for_staff_splits_current_and_ended(): void
    {
        $repo = Container::diaryRequests();
        $cur = $this->make(['type' => 'agua']);
        $fut = $this->make(['type' => 'sono', 'start_date' => '2026-12-01']);
        $e1 = $this->make(['type' => 'tela', 'start_date' => '2026-01-01', 'end_date' => '2026-02-01']);
        $e2 = $this->make(['type' => 'tela', 'start_date' => '2026-03-01', 'end_date' => '2026-04-01']);
        $l = $repo->listForStaff((int) $this->patient['id'], $this->medico, self::TODAY);
        $this->assertEqualsCanonicalizing([$cur, $fut], array_map('intval', array_column($l['current'], 'id')));
        $this->assertSame([$e2, $e1], array_map('intval', array_column($l['ended'], 'id')));
    }

    public function test_nutritionist_may_only_touch_nutritional_types(): void
    {
        $repo = Container::diaryRequests();
        $nutri = $this->makeUser('nutricionista');
        $this->rejects(fn () => $this->make(['type' => 'sono'], $nutri), 'Este tipo de pedido é só da médica.');
        $ok = $this->make(['type' => 'alimentacao'], $nutri);
        $this->assertTrue($repo->mayEdit($repo->find($ok), $nutri));
        $this->assertFalse($repo->mayEdit($repo->find($this->make(['type' => 'sono'])), $nutri));
        $secret = $this->make(['type' => 'agua', 'secret' => 1]);
        $this->assertFalse($repo->mayEdit($repo->find($secret), $nutri));
        $this->assertTrue($repo->mayEdit($repo->find($secret), $this->medico));
        $this->rejects(fn () => $repo->update($secret, $this->data(), $nutri, self::TODAY, $this->r()));
        // nutricionista nao muda um pedido nutricional para um tipo da medica
        $this->rejects(fn () => $repo->update($ok, $this->data(['type' => 'sono']), $nutri, self::TODAY, $this->r()), 'Este tipo de pedido é só da médica.');
    }

    public function test_weekdays_label(): void
    {
        $this->assertSame('todo dia', \App\Diary\DiaryType::weekdaysLabel(127));
        $this->assertSame('seg, qua, sex', \App\Diary\DiaryType::weekdaysLabel(1 | 4 | 16));
    }
}
