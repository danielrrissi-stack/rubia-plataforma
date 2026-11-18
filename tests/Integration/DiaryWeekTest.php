<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class DiaryWeekTest extends DbTestCase
{
    private const MONDAY = '2026-11-16';
    private const TODAY = '2026-11-18'; // quarta-feira

    private array $medico;
    private array $nutri;
    private array $resp;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->nutri = $this->makeUser('nutricionista');
        $this->resp = $this->makeUser('responsavel');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2015-03-10']);
    }

    private function request(string $type, array $over = []): int
    {
        return $this->db->insert('diary_requests', $over + [
            'patient_id' => $this->patient['id'], 'type' => $type, 'weekdays' => 127,
            'start_date' => '2026-11-01', 'created_by' => $this->medico['id'],
        ]);
    }

    private function entry(int $req, string $day, array $over = []): int
    {
        return $this->db->insert('diary_entries', $over + [
            'request_id' => $req, 'day' => $day, 'glasses' => 5, 'recorded_by' => $this->resp['id'], 'recorded_by_role' => 'responsavel',
        ]);
    }

    private function week(): \App\Diary\DiaryWeek
    {
        return Container::diaryWeek();
    }

    public function test_monday(): void
    {
        $this->assertSame(self::MONDAY, \App\Diary\DiaryWeek::monday('2026-11-22'));
        $this->assertSame(self::MONDAY, \App\Diary\DiaryWeek::monday('2026-11-16'));
        $this->assertSame('2026-11-09', \App\Diary\DiaryWeek::monday('2026-11-15'));
    }

    public function test_grid_cells_asked_empty_and_filled(): void
    {
        $req = $this->request('agua', ['weekdays' => 1]); // so segunda
        $grid = $this->week()->grid((int) $this->patient['id'], $this->medico, self::MONDAY);
        $this->assertCount(7, $grid['days']);
        $this->assertSame(self::MONDAY, $grid['days'][0]);
        $this->assertCount(1, $grid['rows']);
        $cells = $grid['rows'][0]['cells'];
        $this->assertTrue($cells[self::MONDAY]['asked']);
        $this->assertNull($cells[self::MONDAY]['label']);
        $this->assertFalse($cells['2026-11-17']['asked']);
        $this->assertNull($cells['2026-11-17']['label']);
        $this->entry($req, self::MONDAY, ['glasses' => 1]);
        $cells = $this->week()->grid((int) $this->patient['id'], $this->medico, self::MONDAY)['rows'][0]['cells'];
        $this->assertSame('1 copo', $cells[self::MONDAY]['label']);
        $this->assertCount(1, $cells[self::MONDAY]['entries']);
    }

    public function test_entry_outside_new_days_still_shows(): void
    {
        $req = $this->request('agua', ['weekdays' => 1]);
        $this->entry($req, '2026-11-17', ['glasses' => 4]); // terca, dia desmarcado depois
        $cells = $this->week()->grid((int) $this->patient['id'], $this->medico, self::MONDAY)['rows'][0]['cells'];
        $this->assertFalse($cells['2026-11-17']['asked']);
        $this->assertSame('4 copos', $cells['2026-11-17']['label']);
    }

    public function test_last_seven_days_counts_only_fully_filled_days(): void
    {
        $agua = $this->request('agua');
        $tela = $this->request('tela');
        $pid = (int) $this->patient['id'];
        $this->entry($agua, '2026-11-18');
        $this->entry($agua, '2026-11-17');
        $this->entry($tela, '2026-11-17', ['glasses' => null, 'minutes' => 30]);
        $this->assertSame(['filled' => 1, 'asked' => 7], $this->week()->lastSevenDays($pid, $this->medico, self::TODAY));
    }

    public function test_counts_respect_viewer(): void
    {
        $pid = (int) $this->patient['id'];
        $agua = $this->request('agua');
        $sono = $this->request('sono');
        $this->request('tela', ['secret' => 1]);
        $this->entry($agua, self::TODAY);
        $this->entry($sono, self::TODAY, ['glasses' => null, 'slept_at' => '22:00:00', 'woke_at' => '06:00:00']);
        // nutricionista nao conta sono; responsavel nao conta o sigiloso; a medica conta tudo
        $this->assertSame(['filled' => 1, 'asked' => 7], $this->week()->lastSevenDays($pid, $this->nutri, self::TODAY));
        $this->assertSame(['filled' => 1, 'asked' => 7], $this->week()->lastSevenDays($pid, $this->resp, self::TODAY));
        $this->assertSame(['filled' => 0, 'asked' => 7], $this->week()->lastSevenDays($pid, $this->medico, self::TODAY));
        $this->assertCount(1, $this->week()->grid($pid, $this->nutri, self::MONDAY)['rows']);
        $this->assertCount(2, $this->week()->grid($pid, $this->resp, self::MONDAY)['rows']);
        $this->assertCount(3, $this->week()->grid($pid, $this->medico, self::MONDAY)['rows']);
    }

    public function test_medidas_cell_uses_family_measurement(): void
    {
        $pid = (int) $this->patient['id'];
        $this->request('medidas');
        $row = ['patient_id' => $pid, 'weight_kg' => 30, 'created_by' => $this->medico['id']];
        $this->db->insert('measurements', $row + ['measured_on' => '2026-11-16', 'source' => 'equipe']);
        $this->db->insert('measurements', $row + ['measured_on' => '2026-11-17', 'source' => 'familia']);
        $this->db->insert('measurements', $row + ['measured_on' => '2026-11-18', 'source' => 'familia', 'deleted_at' => '2026-11-18 10:00:00']);
        $cells = $this->week()->grid($pid, $this->medico, self::MONDAY)['rows'][0]['cells'];
        $this->assertNull($cells['2026-11-16']['label']);
        $this->assertSame('pesou', $cells['2026-11-17']['label']);
        $this->assertNull($cells['2026-11-18']['label']);
        $this->assertSame(['filled' => 1, 'asked' => 7], $this->week()->lastSevenDays($pid, $this->medico, self::TODAY));
    }
}
