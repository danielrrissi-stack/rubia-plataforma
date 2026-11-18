<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Diary\DiaryPhotoStore;
use App\Diary\InvalidDiaryException;
use Tests\DbTestCase;

final class DiaryEntryRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18'; // quarta-feira

    private array $medico;
    private array $nutri;
    private array $respA;
    private array $respB;
    private array $paciente;
    private array $patient;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/rubia_de_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/uploads', 0777, true);
        mkdir($this->dir . '/in', 0777, true);
        Container::set('diaryPhotos', new DiaryPhotoStore($this->dir . '/uploads', true));
        $this->medico = $this->makeUser('medico');
        $this->nutri = $this->makeUser('nutricionista');
        $this->respA = $this->makeUser('responsavel');
        $this->respB = $this->makeUser('responsavel');
        $this->paciente = $this->makeUser('paciente');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2015-03-10']);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$d/$f") ? $this->rrmdir("$d/$f") : @unlink("$d/$f");
            }
        }
        @rmdir($d);
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function request(string $type, array $over = []): int
    {
        return $this->db->insert('diary_requests', $over + [
            'patient_id' => $this->patient['id'], 'type' => $type, 'weekdays' => 127,
            'start_date' => '2026-11-02', 'created_by' => $this->medico['id'],
        ]);
    }

    private function entries(): \App\Diary\DiaryEntryRepository
    {
        return Container::diaryEntries();
    }

    private function rowCount(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM diary_entries')['n'];
    }

    private function audits(string $action): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM audit_log WHERE action = ? AND entity = ?', [$action, 'diary_entries'])['n'];
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

    private function food(int $req, string $day, string $meal, string $time, array $user, ?array $photo = null): int
    {
        return $this->entries()->record($req, $day, ['meal' => $meal, 'meal_time' => $time, 'description' => 'arroz e feijão'], $photo, $user, self::TODAY, $this->r());
    }

    public function test_record_food_with_several_per_day(): void
    {
        $req = $this->request('alimentacao');
        $a = $this->food($req, '2026-11-17', 'almoco', '12:30', $this->respA);
        $b = $this->food($req, '2026-11-17', 'cafe_manha', '07:00', $this->respB);
        $c = $this->food($req, '2026-11-17', 'cafe_manha', '08:00', $this->respA);
        $this->assertCount(3, array_unique([$a, $b, $c]));
        $e = $this->entries()->find($a);
        $this->assertSame('almoco', $e['meal']);
        $this->assertSame('12:30:00', $e['meal_time']);
        $this->assertSame((int) $this->patient['id'], (int) $e['patient_id']);
        $this->assertSame('alimentacao', $e['type']);
        $this->assertSame('responsavel', $e['recorded_by_role']);
        $this->assertSame(3, $this->audits('create'));
        $rows = $this->entries()->forRange((int) $this->patient['id'], $this->medico, '2026-11-01', '2026-11-30');
        $this->assertSame([$b, $c, $a], array_map(fn ($x) => (int) $x['id'], $rows));
        $this->assertSame($this->respB['name'], $rows[0]['recorder_name']);
        // paciente tambem pode anotar
        $this->assertGreaterThan(0, $this->food($req, '2026-11-18', 'jantar', '19:00', $this->paciente));
        // refeicao e descricao obrigatorias
        $this->rejects(fn () => $this->entries()->record($req, '2026-11-17', ['meal' => 'almoco', 'description' => ' '], null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $this->entries()->record($req, '2026-11-17', ['description' => 'x'], null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $this->entries()->record($req, '2026-11-17', ['meal' => 'almoco', 'meal_time' => '25:00', 'description' => 'x'], null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $this->entries()->record($req, '2026-11-17', ['meal' => 'almoco', 'description' => str_repeat('a', 1001)], null, $this->respA, self::TODAY, $this->r()));
        // equipe nao registra pela familia
        $this->rejects(fn () => $this->food($req, '2026-11-17', 'almoco', '12:00', $this->medico));
    }

    public function test_one_per_day_upserts(): void
    {
        $req = $this->request('agua');
        $id = $this->entries()->record($req, '2026-11-17', ['glasses' => 5], null, $this->respA, self::TODAY, $this->r());
        $id2 = $this->entries()->record($req, '2026-11-17', ['glasses' => '6'], null, $this->respB, self::TODAY, $this->r());
        $this->assertSame($id, $id2);
        $this->assertSame(1, $this->rowCount());
        $e = $this->entries()->find($id);
        $this->assertSame(6, (int) $e['glasses']);
        $this->assertSame((int) $this->respB['id'], (int) $e['recorded_by']);
        $this->assertSame((int) $this->respB['id'], (int) $e['updated_by']);
        $this->assertSame(1, $this->audits('create'));
        $this->assertSame(1, $this->audits('update'));
        $this->rejects(fn () => $this->entries()->record($req, '2026-11-17', ['glasses' => 31], null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $this->entries()->record($req, '2026-11-16', [], null, $this->respA, self::TODAY, $this->r()));
    }

    public function test_rejects_day_not_asked_future_too_old_deleted_request_and_medidas(): void
    {
        $e = $this->entries();
        $rec = fn (int $req, string $day) => $e->record($req, $day, ['glasses' => 3], null, $this->respA, self::TODAY, $this->r());
        $tue = $this->request('agua', ['weekdays' => 1 << 1]); // so terca (2026-11-17)
        $this->rejects(fn () => $rec($tue, '2026-11-16'), 'Este dia não está entre os pedidos da equipe.');
        $this->assertGreaterThan(0, $rec($tue, '2026-11-17'));
        $range = $this->request('tela', ['start_date' => '2026-11-13', 'end_date' => '2026-11-16']);
        $scr = fn (string $day) => $e->record($range, $day, ['minutes' => 30], null, $this->respA, self::TODAY, $this->r());
        $this->rejects(fn () => $scr('2026-11-12'), 'Este dia não está entre os pedidos da equipe.');
        $this->rejects(fn () => $scr('2026-11-17'), 'Este dia não está entre os pedidos da equipe.');
        $this->assertGreaterThan(0, $scr('2026-11-14'));

        $sono = $this->request('sono');
        $night = ['slept_at' => '22:00', 'woke_at' => '06:00'];
        $this->rejects(fn () => $e->record($sono, '2026-11-19', $night, null, $this->respA, self::TODAY, $this->r()), 'Não dá para anotar dias futuros.');
        $this->rejects(fn () => $e->record($sono, '2026-11-10', $night, null, $this->respA, self::TODAY, $this->r()), 'Só dá para anotar até 7 dias atrás.');
        $this->assertGreaterThan(0, $e->record($sono, '2026-11-11', $night, null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->record($sono, 'abc', [], null, $this->respA, self::TODAY, $this->r()));

        $gone = $this->request('atividade', ['deleted_at' => '2026-11-18 08:00:00', 'deleted_by' => $this->medico['id']]);
        $this->rejects(fn () => $e->record($gone, '2026-11-17', ['activity' => 'bola'], null, $this->respA, self::TODAY, $this->r()));
        $med = $this->request('medidas');
        $this->rejects(fn () => $e->record($med, '2026-11-17', [], null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->record(999999, '2026-11-17', [], null, $this->respA, self::TODAY, $this->r()));
        $this->assertSame(3, $this->rowCount());
    }

    public function test_sleep_across_midnight_and_nap_and_equal_times(): void
    {
        $req = $this->request('sono');
        $e = $this->entries();
        $id = $e->record($req, '2026-11-17', ['slept_at' => '23:00', 'woke_at' => '06:30', 'sleep_quality' => '3'], null, $this->respA, self::TODAY, $this->r());
        $row = $e->find($id);
        $this->assertSame('23:00:00', $row['slept_at']);
        $this->assertSame('06:30:00', $row['woke_at']);
        $this->assertSame(3, (int) $row['sleep_quality']);
        $nap = $e->record($req, '2026-11-16', ['slept_at' => '13:00', 'woke_at' => '15:00'], null, $this->respA, self::TODAY, $this->r());
        $this->assertNull($e->find($nap)['sleep_quality']);
        $this->rejects(fn () => $e->record($req, '2026-11-15', ['slept_at' => '22:00', 'woke_at' => '22:00'], null, $this->respA, self::TODAY, $this->r()), 'O horário de acordar deve ser diferente do de dormir.');
        $this->rejects(fn () => $e->record($req, '2026-11-15', ['slept_at' => '22:00'], null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->record($req, '2026-11-15', ['slept_at' => '22:00', 'woke_at' => '06:00', 'sleep_quality' => 4], null, $this->respA, self::TODAY, $this->r()));
        $this->assertSame(2, $this->rowCount());
    }

    public function test_update_and_remove_rules(): void
    {
        $e = $this->entries();
        $food = $this->request('alimentacao');
        $a = $this->food($food, '2026-11-17', 'almoco', '12:00', $this->respA);
        $this->rejects(fn () => $e->update($a, ['meal' => 'jantar', 'description' => 'x'], null, false, $this->respB, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->remove($a, $this->respB, self::TODAY, $this->r()));
        $e->update($a, ['meal' => 'jantar', 'meal_time' => '', 'description' => 'sopa'], null, false, $this->respA, self::TODAY, $this->r());
        $row = $e->find($a);
        $this->assertSame('jantar', $row['meal']);
        $this->assertNull($row['meal_time']);
        $this->assertSame('sopa', $row['description']);
        $this->assertSame((int) $this->respA['id'], (int) $row['updated_by']);
        $this->assertSame(1, $this->audits('update'));

        $water = $this->request('agua');
        $w = $e->record($water, '2026-11-17', ['glasses' => 4], null, $this->respA, self::TODAY, $this->r());
        $e->update($w, ['glasses' => 7], null, false, $this->respB, self::TODAY, $this->r());
        $this->assertSame(7, (int) $e->find($w)['glasses']);

        $old = $this->db->insert('diary_entries', ['request_id' => $water, 'day' => '2026-11-10', 'glasses' => 2, 'recorded_by' => $this->respA['id'], 'recorded_by_role' => 'responsavel']);
        $this->rejects(fn () => $e->update($old, ['glasses' => 3], null, false, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->remove($old, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->update($w, ['glasses' => 1], null, false, $this->medico, self::TODAY, $this->r()));

        $e->remove($w, $this->respB, self::TODAY, $this->r());
        $this->assertNull($e->find($w));
        $this->assertNotNull($this->db->one('SELECT 1 FROM diary_entries WHERE id = ? AND deleted_at IS NOT NULL AND deleted_by = ?', [$w, $this->respB['id']]));
        $this->assertSame(1, $this->audits('delete'));
        $this->rejects(fn () => $e->remove($w, $this->respB, self::TODAY, $this->r()));

        // pedido editado depois (o dia nao vale mais): ainda corrige e continua aparecendo
        $this->db->run('UPDATE diary_requests SET weekdays = 1 WHERE id = ?', [$food]);
        $e->update($a, ['meal' => 'ceia', 'description' => 'leite'], null, false, $this->respA, self::TODAY, $this->r());
        $this->assertCount(1, $e->forRange((int) $this->patient['id'], $this->respA, '2026-11-17', '2026-11-17'));
    }

    public function test_secret_request_leaves_no_trail(): void
    {
        $e = $this->entries();
        $req = $this->request('agua');
        $id = $e->record($req, '2026-11-17', ['glasses' => 4], null, $this->respA, self::TODAY, $this->r());
        $pid = (int) $this->patient['id'];
        foreach ([$this->respA, $this->nutri, $this->medico, $this->paciente] as $v) {
            $this->assertNotNull($e->visibleTo($id, $v));
            $this->assertCount(1, $e->forRange($pid, $v, '2026-11-01', '2026-11-30'));
        }
        $this->db->run('UPDATE diary_requests SET secret = 1 WHERE id = ?', [$req]);
        foreach ([$this->respA, $this->nutri] as $v) {
            $this->assertNull($e->visibleTo($id, $v));
            $this->assertSame([], $e->forRange($pid, $v, '2026-11-01', '2026-11-30'));
        }
        foreach ([$this->medico, $this->paciente] as $v) {
            $this->assertNotNull($e->visibleTo($id, $v));
            $this->assertCount(1, $e->forRange($pid, $v, '2026-11-01', '2026-11-30'));
        }
        $this->rejects(fn () => $e->record($req, '2026-11-16', ['glasses' => 1], null, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->update($id, ['glasses' => 1], null, false, $this->respA, self::TODAY, $this->r()));
        $this->rejects(fn () => $e->remove($id, $this->respA, self::TODAY, $this->r()));

        $sono = $this->request('sono');
        $e->record($sono, '2026-11-17', ['slept_at' => '22:00', 'woke_at' => '06:00'], null, $this->respA, self::TODAY, $this->r());
        $this->db->run('UPDATE diary_requests SET secret = 0 WHERE id = ?', [$req]);
        $this->assertCount(1, $e->forRange($pid, $this->nutri, '2026-11-01', '2026-11-30'));
    }

    public function test_invalid_photo_saves_nothing(): void
    {
        $req = $this->request('alimentacao');
        $tmp = $this->dir . '/in/fake';
        file_put_contents($tmp, "%PDF-1.4\n%%EOF");
        $photo = ['name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 14];
        $this->rejects(fn () => $this->food($req, '2026-11-17', 'almoco', '12:00', $this->respA, $photo));
        $this->assertSame(0, $this->rowCount());
        $this->assertSame([], glob($this->dir . '/uploads/diario/*/*/*') ?: []);
    }

    public function test_photo_only_for_food_and_failed_write_discards_file(): void
    {
        if (extension_loaded('gd')) {
            $this->markTestSkipped('Usa o original sem GD.');
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $mk = function () use ($png): array {
            $tmp = $this->dir . '/in/' . bin2hex(random_bytes(4));
            file_put_contents($tmp, $png);
            return ['name' => 'f.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)];
        };
        $req = $this->request('alimentacao');
        $id = $this->food($req, '2026-11-17', 'almoco', '12:00', $this->respA, $mk());
        $row = $this->entries()->find($id);
        $this->assertSame('image/png', $row['photo_mime']);
        $this->assertFileExists($this->dir . '/uploads/' . $row['photo_path']);
        $old = $row['photo_path'];
        $this->entries()->update($id, ['meal' => 'almoco', 'description' => 'x'], $mk(), false, $this->respA, self::TODAY, $this->r());
        $new = $this->entries()->find($id)['photo_path'];
        $this->assertNotSame($old, $new);
        $this->assertFileExists($this->dir . '/uploads/' . $old);
        $this->entries()->update($id, ['meal' => 'almoco', 'description' => 'x'], null, true, $this->respA, self::TODAY, $this->r());
        $this->assertNull($this->entries()->find($id)['photo_path']);
        $water = $this->request('agua');
        $w = $this->entries()->record($water, '2026-11-17', ['glasses' => 2], $mk(), $this->respA, self::TODAY, $this->r());
        $this->assertNull($this->entries()->find($w)['photo_path']);
        $before = count(glob($this->dir . '/uploads/diario/*/*/*') ?: []);
        $this->rejects(fn () => $this->entries()->record($req, '2026-11-17', ['meal' => 'almoco', 'description' => ''], $mk(), $this->respA, self::TODAY, $this->r()));
        $this->assertSame($before, count(glob($this->dir . '/uploads/diario/*/*/*') ?: []));
    }
}
