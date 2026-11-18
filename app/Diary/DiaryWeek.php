<?php
declare(strict_types=1);

namespace App\Diary;

use App\Core\Database;

/** Grade semanal do diario e contagem dos ultimos 7 dias (so o que o usuario pode ver). Nada aqui avalia a alimentacao. */
final class DiaryWeek
{
    public function __construct(
        private DiaryRequestRepository $requests,
        private DiaryEntryRepository $entries,
        private Database $db,
    ) {
    }

    /** Segunda-feira da semana de $ymd (data valida). */
    public static function monday(string $ymd): string
    {
        $d = new \DateTimeImmutable($ymd);
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }

    /**
     * @return array{days: list<string>, rows: list<array{request: array, cells: array<string, array{asked: bool, entries: list<array>, label: ?string}>}>}
     */
    public function grid(int $patientId, array $viewer, string $monday): array
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = (new \DateTimeImmutable($monday))->modify("+$i days")->format('Y-m-d');
        }
        $sunday = $days[6];
        $requests = $this->requests->forViewer($patientId, $viewer, $monday, $sunday);
        $byKey = [];
        foreach ($this->entries->forRange($patientId, $viewer, $monday, $sunday) as $e) {
            $byKey[(int) $e['request_id']][(string) $e['day']][] = $e;
        }
        $measured = $this->measuredDays($patientId, $monday, $sunday);
        $rows = [];
        foreach ($requests as $req) {
            $cells = [];
            foreach ($days as $day) {
                $list = $byKey[(int) $req['id']][$day] ?? [];
                $cells[$day] = [
                    'asked' => $this->requests->appliesOn($req, $day),
                    'entries' => $list,
                    'label' => DiaryFormat::cell((string) $req['type'], $list, isset($measured[$day])),
                ];
            }
            $rows[] = ['request' => $req, 'cells' => $cells];
        }
        return ['days' => $days, 'rows' => $rows];
    }

    /** @return array{filled: int, asked: int} */
    public function lastSevenDays(int $patientId, array $viewer, string $today): array
    {
        $from = (new \DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
        $requests = $this->requests->forViewer($patientId, $viewer, $from, $today);
        $have = [];
        foreach ($this->entries->forRange($patientId, $viewer, $from, $today) as $e) {
            $have[(int) $e['request_id']][(string) $e['day']] = true;
        }
        $measured = $this->measuredDays($patientId, $from, $today);
        $asked = 0;
        $filled = 0;
        for ($i = 0; $i < 7; $i++) {
            $day = (new \DateTimeImmutable($from))->modify("+$i days")->format('Y-m-d');
            $due = array_filter($requests, fn (array $r): bool => $this->requests->appliesOn($r, $day));
            if ($due === []) {
                continue;
            }
            $asked++;
            $all = true;
            foreach ($due as $r) {
                $done = (string) $r['type'] === 'medidas' ? isset($measured[$day]) : isset($have[(int) $r['id']][$day]);
                if (!$done) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $filled++;
            }
        }
        return ['filled' => $filled, 'asked' => $asked];
    }

    /** @return array<string, true> dias com medida enviada pela familia */
    private function measuredDays(int $patientId, string $from, string $to): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT measured_on FROM measurements
              WHERE patient_id = ? AND source = 'familia' AND deleted_at IS NULL AND measured_on BETWEEN ? AND ?",
            [$patientId, $from, $to],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['measured_on']] = true;
        }
        return $out;
    }
}
