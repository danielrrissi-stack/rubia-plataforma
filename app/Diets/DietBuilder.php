<?php
declare(strict_types=1);

namespace App\Diets;

/**
 * Gera os dias do cardapio a partir de data/dietas/cardapio.php: escolhe os blocos pela semana e dia da semana, ajusta as
 * porcoes ao nivel de calorias e arredonda para medidas caseiras. A saida `structure` esta no formato de entrada do
 * editor estruturado (MealPlanResolver).
 */
final class DietBuilder
{
    public const BANDS = ['infantil' => [1000, 2000], 'adulto' => [1000, 3000]];
    public const TOLERANCE = 0.03;
    public const SEASON_LABEL = ['verao' => 'verão', 'outono' => 'outono', 'inverno' => 'inverno', 'primavera' => 'primavera'];

    private const ROUND_STEP = ['c' => 10.0, 'p' => 10.0, 'd' => 10.0, 'f' => 5.0, 'fr' => 10.0];
    private const MIN_GRAMS = ['c' => 10.0, 'p' => 30.0, 'd' => 50.0, 'f' => 5.0, 'fr' => 50.0];

    /**
     * @param array $menu conteudo de data/dietas/cardapio.php
     * @param array<int,array{name:string,kcal:float,protein:float,carb:float,fat:float,fiber:float}> $foods por codigo TACO
     * @param array<int,array<string,array{id:int,grams:float}>> $measures por codigo TACO e nome da medida
     */
    public function __construct(private array $menu, private array $foods, private array $measures)
    {
    }

    public static function season(int $week): string
    {
        return match (true) {
            $week <= 12 || $week >= 52 => 'verao',
            $week <= 25 => 'outono',
            $week <= 38 => 'inverno',
            default => 'primavera',
        };
    }

    /** Semana ISO da data (a 53a usa a 52). */
    public static function weekOf(string $ymd): int
    {
        return min(52, (int) (new \DateTimeImmutable($ymd))->format('W'));
    }

    public static function code(int $kcal, int $week, string $band): string
    {
        return $kcal . 'kcalSemana' . $week . ($band === 'infantil' ? 'Infantil' : '');
    }

    /** @return list<int> niveis de calorias da versao */
    public static function levels(string $band): array
    {
        [$lo, $hi] = self::BANDS[$band];
        return range($lo, $hi, 100);
    }

    /** Versao pela idade em anos completos; null abaixo de 3 anos. */
    public static function bandForAge(int $years): ?string
    {
        return $years < 3 ? null : ($years <= 10 ? 'infantil' : 'adulto');
    }

    /** @return list<array> todas as dietas (uma por semana e nivel de cada versao) */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::BANDS) as $band) {
            for ($w = 1; $w <= 52; $w++) {
                foreach (self::levels($band) as $kcal) {
                    $out[] = $this->build($band, $w, $kcal);
                }
            }
        }
        return $out;
    }

    /**
     * @return array{code:string,band:string,week:int,season:string,kcal_target:int,totals:array,title:string,guidance:string,structure:array}
     */
    public function build(string $band, int $week, int $kcal): array
    {
        $season = self::season($week);
        // Data de referencia: a semana N tem o mesmo cardapio todo ano (t depende so da semana e do dia da semana).
        $monday = (new \DateTimeImmutable('2026-01-01'))->setISODate(2026, $week, 1);
        $structure = null;
        $sum = ['kcal' => 0.0, 'protein' => 0.0, 'carb' => 0.0, 'fat' => 0.0, 'fiber' => 0.0];
        for ($d = 0; $d < 7; $d++) {
            $day = $this->buildDay($band, $monday->modify('+' . $d . ' day')->format('Y-m-d'), $kcal);
            $structure ??= ['v' => 1, 'meals' => $day['meals']];
            foreach ($sum as $k => $_) {
                $sum[$k] += $day['totals'][$k];
            }
        }
        return [
            'code' => self::code($kcal, $week, $band), 'band' => $band, 'week' => $week, 'season' => $season, 'kcal_target' => $kcal,
            'totals' => array_map(static fn(float $v): float => round($v / 7, 1), $sum),
            'title' => 'Plano alimentar – semana ' . $week . ' (' . self::SEASON_LABEL[$season] . ')',
            'guidance' => $this->guidance($band, $season),
            'structure' => $structure,
        ];
    }

    /**
     * N dias a partir de $startYmd (estrutura v2). `totals` traz os totais de cada dia, na ordem.
     *
     * @return array{code:string,title:string,guidance:string,structure:array,totals:list<array>}
     */
    public function buildDays(string $band, int $kcal, string $startYmd, int $n): array
    {
        $start = new \DateTimeImmutable($startYmd);
        $days = [];
        $totals = [];
        for ($d = 0; $d < $n; $d++) {
            $day = $this->buildDay($band, $start->modify('+' . $d . ' day')->format('Y-m-d'), $kcal);
            $days[] = ['meals' => $day['meals']];
            $totals[] = $day['totals'];
        }
        return [
            'code' => self::code($kcal, self::weekOf($startYmd), $band),
            'title' => 'Plano alimentar – ' . $start->format('d/m') . ' a ' . $start->modify('+' . max(0, $n - 1) . ' day')->format('d/m'),
            'guidance' => $this->guidance($band, self::season(self::weekOf($startYmd))),
            'structure' => ['v' => 2, 'start' => $start->format('Y-m-d'), 'days' => $days],
            'totals' => $totals,
        ];
    }

    /**
     * Um dia do cardapio. Indice do dia: t = (posicao da semana na estacao) * 7 + dia da semana (0 = segunda).
     * Almoco e jantar: pool[(t + mi) % n], sem repetir em 7 dias seguidos se n >= 8; demais: pool[(t*(2mi+1) + mi) % n].
     *
     * @return array{meals:list<array>,totals:array{kcal:float,protein:float,carb:float,fat:float,fiber:float},season:string}
     */
    public function buildDay(string $band, string $ymd, int $kcal): array
    {
        $week = self::weekOf($ymd);
        $season = self::season($week);
        $kid = $band === 'infantil';
        $t = $this->seasonIndex($week, $season) * 7 + (int) (new \DateTimeImmutable($ymd))->format('N') - 1;
        $slots = ['fruta' => 0, 'legume' => 0, 'salada' => 0];
        $meals = [];
        foreach ($this->menu['schedule'] as $mi => [$key, $name, $tAdult, $tKid]) {
            if ($key === 'ceia' && $kcal < $this->menu['ceia_from'][$band]) {
                continue;
            }
            $pool = array_values(array_filter($this->menu['meals'][$key], static fn(array $b): bool =>
                in_array($season, $b['seasons'] ?? ['verao', 'outono', 'inverno', 'primavera'], true)
                && !($kid && ($b['adult_only'] ?? false)) && !(!$kid && ($b['kid_only'] ?? false))));
            if ($pool === []) {
                throw new \RuntimeException("Sem blocos para $key/$season/$band");
            }
            $block = $key === 'almoco' || $key === 'jantar'
                ? $pool[($t + $mi) % count($pool)]
                : $pool[($t * (2 * $mi + 1) + $mi) % count($pool)];
            $items = [];
            foreach ($block['items'] as $spec) {
                $item = $this->expand($spec, $kid, $season, $t, $slots);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            $note = (string) ($block['note'] ?? '');
            if (str_contains($note, '{SALADA}')) {
                $sal = $this->menu['saladas'][$season];
                $note = str_replace('{SALADA}', $sal[($t + $slots['salada']++) % count($sal)], $note);
            }
            $meals[] = ['name' => $name, 'time' => $kid ? $tKid : $tAdult, 'note' => $note, 'items' => $items];
        }

        $this->fit($meals, $kcal, $kid);

        $out = [];
        foreach ($meals as $m) {
            $items = [];
            foreach ($m['items'] as $it) {
                $items[] = ['food_id' => $it['code'], 'qty' => $it['qty'], 'measure_id' => $it['measure_id'], 'note' => $it['note'],
                    'subs' => $it['sub'] === null ? [] : [$this->sub($it)]];
            }
            $out[] = ['name' => $m['name'], 'time' => $m['time'], 'note' => $m['note'], 'options' => [['items' => $items]]];
        }
        return ['meals' => $out, 'totals' => $this->totals($meals), 'season' => $season];
    }

    private function seasonIndex(int $week, string $season): int
    {
        $n = 0;
        for ($w = 1; $w < $week; $w++) {
            if (self::season($w) === $season) {
                $n++;
            }
        }
        return $n;
    }

    /** Aplica a troca infantil e os placeholders; null = item fora desta versao. */
    private function expand(array $spec, bool $kid, string $season, int $t, array &$slots): ?array
    {
        [$code, $grams, $role] = $spec;
        $o = $spec[3] ?? [];
        $sub = $o['sub'] ?? null;
        $measure = $o['m'] ?? null;
        if ($kid && array_key_exists('kid', $o)) {
            if ($o['kid'] === false) {
                return null;
            }
            [$code, $grams] = $o['kid'];
            $o['n'] = $o['kid'][2] ?? '';
            // Mantem a medida se o novo alimento a tiver (iogurte -> iogurte); cafe -> leite usa xicara de cha.
            $measure = $measure !== 'xícara de café' && isset($this->measures[$code][(string) $measure]) ? $measure
                : (isset($this->measures[$code]['xícara de chá']) ? 'xícara de chá' : null);
            $sub = $sub === $code ? null : $sub;
        }
        if ($code === 'FRUTA') {
            $pool = $this->menu['fruits'][$season];
            $k = ($t * 2 + $slots['fruta']++) % count($pool);
            [$code, $grams, $measure] = $pool[$k];
            $sub = $pool[($k + 1) % count($pool)][0];
        } elseif ($code === 'LEGUME') {
            $pool = $this->menu['legumes'][$season];
            [$code, $grams] = $pool[($t + 2 * $slots['legume']++) % count($pool)];
            $measure = null;
        } elseif ($role === 'fr' && $sub === null) {
            $pool = $this->menu['fruits'][$season];
            $sub = $pool[($t + $slots['fruta']) % count($pool)][0];
            $sub = $sub === $code ? $pool[($t + $slots['fruta'] + 1) % count($pool)][0] : $sub;
        }
        if (!isset($this->foods[$code])) {
            throw new \RuntimeException('Alimento TACO inexistente no cardápio: ' . $code);
        }
        $m = $measure === null ? null : ($this->measures[$code][$measure] ?? throw new \RuntimeException("Medida '$measure' inexistente para $code"));
        return [
            'code' => (int) $code, 'ref' => (float) $grams, 'role' => $role, 'note' => (string) ($o['n'] ?? ''),
            'mid' => $m['id'] ?? null, 'mg' => $m['grams'] ?? null,
            'step' => (float) ($o['step'] ?? ($m === null ? (self::ROUND_STEP[$role] ?? 0) : 0.5)),
            'min' => (float) ($o['min'] ?? ($m === null ? self::minGrams($role, (float) $grams) : 0.5)),
            'sub' => $sub === null || $sub === $code ? null : (int) $sub,
            'qty' => 0.0, 'measure_id' => $m['id'] ?? null, 'grams' => (float) $grams,
        ];
    }

    /** Minimo em gramas: nunca acima da porcao de referencia; carboidrato fica em ao menos 1/4 dela. */
    private static function minGrams(string $role, float $ref): float
    {
        $min = min(self::MIN_GRAMS[$role] ?? 0.0, $ref);
        return $role === 'c' ? max($min, round($ref / 4 / 10) * 10) : $min;
    }

    private static function weight(string $role, float $f, bool $kid): float
    {
        return match ($role) {
            'c', 'f' => $f,
            'p' => $f ** 0.5,
            'd' => $f ** ($kid ? 0.3 : 0.5),
            'fr' => max(0.75, min(1.25, $f ** 0.25)),
            default => 1.0,
        };
    }

    /** Ajusta porcoes: fator continuo por bissecao, arredonda e corrige o resto passo a passo. */
    private function fit(array &$meals, int $target, bool $kid): void
    {
        $refs = [];
        foreach ($meals as $mi => $m) {
            foreach ($m['items'] as $ii => $it) {
                $refs[] = [$mi, $ii];
            }
        }
        $kcalAt = function (float $f) use ($meals, $refs, $kid): float {
            $s = 0.0;
            foreach ($refs as [$mi, $ii]) {
                $it = $meals[$mi]['items'][$ii];
                $s += $this->foods[$it['code']]['kcal'] * $it['ref'] * self::weight($it['role'], $f, $kid) / 100;
            }
            return $s;
        };
        [$lo, $hi] = [0.05, 6.0];
        for ($n = 0; $n < 60; $n++) {
            $mid = ($lo + $hi) / 2;
            $kcalAt($mid) < $target ? $lo = $mid : $hi = $mid;
        }
        $f = ($lo + $hi) / 2;

        foreach ($refs as [$mi, $ii]) {
            $it = &$meals[$mi]['items'][$ii];
            $g = $it['ref'] * self::weight($it['role'], $f, $kid);
            if ($it['mg'] !== null) {
                $it['qty'] = max($it['min'], round($g / $it['mg'] / $it['step']) * $it['step']);
                $it['grams'] = $it['qty'] * $it['mg'];
            } elseif ($it['step'] > 0) {
                $it['grams'] = max($it['min'], round($g / $it['step']) * $it['step']);
                $it['qty'] = $it['grams'];
            } else {
                $it['grams'] = $it['ref'];
                $it['qty'] = $it['ref'];
            }
            unset($it);
        }

        // Correcao gulosa: um passo por vez no item que mais aproxima da meta (carboidrato antes dos demais).
        $order = ['c' => 0, 'f' => 1, 'd' => 2, 'p' => 3];
        for ($n = 0; $n < 200; $n++) {
            $diff = $target - $this->totals($meals)['kcal'];
            if (abs($diff) <= $target * 0.01) {
                break;
            }
            $best = null;
            foreach ($refs as [$mi, $ii]) {
                $it = $meals[$mi]['items'][$ii];
                if (!isset($order[$it['role']]) || $it['step'] <= 0) {
                    continue;
                }
                $unitG = $it['mg'] ?? 1.0;
                $deltaQty = $diff > 0 ? $it['step'] : -$it['step'];
                $newQty = $it['qty'] + $deltaQty;
                if ($newQty < $it['min'] || $newQty * $unitG > max($it['ref'] * 3, $it['ref'] + 60)) {
                    continue;
                }
                $gain = $this->foods[$it['code']]['kcal'] * $deltaQty * $unitG / 100;
                $score = abs($diff - $gain) + $order[$it['role']] * 2;
                if ($best === null || $score < $best[0]) {
                    $best = [$score, $mi, $ii, $newQty, abs($diff - $gain) < abs($diff)];
                }
            }
            if ($best === null || !$best[4]) {
                break;
            }
            $it = &$meals[$best[1]]['items'][$best[2]];
            $it['qty'] = $best[3];
            $it['grams'] = $best[3] * ($it['mg'] ?? 1.0);
            unset($it);
        }
    }

    /** Troca equivalente em calorias ("ou ..."): unidade se o alimento tiver, senao gramas de 10 em 10. */
    private function sub(array $it): array
    {
        $kcal = $this->foods[$it['code']]['kcal'] * $it['grams'] / 100;
        $per = max(1.0, $this->foods[$it['sub']]['kcal']);
        $g = $kcal / $per * 100;
        $unit = $this->measures[$it['sub']]['unidade'] ?? $this->measures[$it['sub']]['fatia'] ?? null;
        if ($unit !== null) {
            return ['food_id' => $it['sub'], 'qty' => max(0.5, round($g / $unit['grams'] * 2) / 2), 'measure_id' => $unit['id']];
        }
        return ['food_id' => $it['sub'], 'qty' => max(10.0, round($g / 10) * 10), 'measure_id' => null];
    }

    /** @return array{kcal:float,protein:float,carb:float,fat:float,fiber:float} */
    private function totals(array $meals): array
    {
        $t = ['kcal' => 0.0, 'protein' => 0.0, 'carb' => 0.0, 'fat' => 0.0, 'fiber' => 0.0];
        foreach ($meals as $m) {
            foreach ($m['items'] as $it) {
                foreach ($t as $k => $_) {
                    $t[$k] += $this->foods[$it['code']][$k] * $it['grams'] / 100;
                }
            }
        }
        return array_map(static fn(float $v): float => round($v, 1), $t);
    }

    private function guidance(string $band, string $season): string
    {
        $g = $this->menu['guidance'];
        $lines = array_merge($g['base'], $g[$band], $g[$season]);
        $out = "ORIENTAÇÕES GERAIS:\n";
        foreach ($lines as $n => $l) {
            $out .= ($n + 1) . ') ' . $l . "\n";
        }
        return rtrim($out);
    }

    // ---- Carga dos dados de referencia (CSV) --------------------------------------------------------------------

    /** Alimentos (TACO + overrides) e medidas POF a partir dos CSV, no formato do construtor. */
    public static function fromDataDir(string $root): self
    {
        $taco = $root . '/data/taco';
        $foods = [];
        foreach (\App\Nutrition\TacoImporter::parse($taco . '/food.csv', $taco . '/nutrients.csv', $taco . '/categories.csv', $taco . '/overrides.csv') as $r) {
            $foods[$r['taco_code']] = [
                'name' => $r['name'], 'kcal' => (float) ($r['kcal'] ?? 0), 'protein' => (float) ($r['protein_g'] ?? 0),
                'carb' => (float) ($r['carb_g'] ?? 0), 'fat' => (float) ($r['fat_g'] ?? 0), 'fiber' => (float) ($r['fiber_g'] ?? 0),
            ];
        }
        $measures = [];
        $seq = [];
        foreach (\App\Nutrition\PofMeasureImporter::parseCsv($root . '/data/pof/medidas.csv') as $m) {
            $n = $seq[$m['taco_code']] = ($seq[$m['taco_code']] ?? 0) + 1; // mesmo id do seed: codigo*100 + n
            $measures[$m['taco_code']][$m['name']] = ['id' => $m['taco_code'] * 100 + $n, 'grams' => round($m['grams'], 1)];
        }
        return new self(require $root . '/data/dietas/cardapio.php', $foods, $measures);
    }

    /** A mesma montagem, com os alimentos TACO e as medidas POF do banco (que ja trazem a correcao do leite). */
    public static function fromDatabase(\App\Core\Database $db): self
    {
        $foods = [];
        foreach ($db->all("SELECT taco_code, name, kcal, protein_g, carb_g, fat_g, fiber_g FROM foods WHERE source = 'taco' AND taco_code IS NOT NULL") as $r) {
            $foods[(int) $r['taco_code']] = [
                'name' => (string) $r['name'], 'kcal' => (float) ($r['kcal'] ?? 0), 'protein' => (float) ($r['protein_g'] ?? 0),
                'carb' => (float) ($r['carb_g'] ?? 0), 'fat' => (float) ($r['fat_g'] ?? 0), 'fiber' => (float) ($r['fiber_g'] ?? 0),
            ];
        }
        $measures = [];
        $rows = $db->all("SELECT f.taco_code, m.id, m.name, m.grams FROM food_measures m JOIN foods f ON f.id = m.food_id
            WHERE f.source = 'taco' AND m.source = 'pof' ORDER BY m.id");
        foreach ($rows as $m) {
            $measures[(int) $m['taco_code']][(string) $m['name']] = ['id' => (int) $m['id'], 'grams' => (float) $m['grams']];
        }
        return new self(require APP_ROOT . '/data/dietas/cardapio.php', $foods, $measures);
    }
}
