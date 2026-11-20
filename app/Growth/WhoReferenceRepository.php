<?php
declare(strict_types=1);

namespace App\Growth;

use App\Core\Database;

/** Acesso as tabelas LMS da OMS (who_lms), com interpolacao linear em x. */
final class WhoReferenceRepository
{
    /** @var array<string, list<array{0:float,1:float,2:float,3:float}>> */
    private array $cache = [];

    public function __construct(private Database $db)
    {
    }

    /**
     * @param string $sex 'M'|'F'
     * @return array{l:float,m:float,s:float}|null  null quando x esta fora da tabela
     */
    public function lms(string $indicator, string $sex, float $x, string $source): ?array
    {
        $rows = $this->table($indicator, $sex, $source);
        $xr = round($x, 6); // mesma precisao da comparacao em SQL usada antes
        // busca binaria: lo = maior x <= xr; hi = menor x >= xr
        $lo = null;
        $hi = null;
        $a = 0;
        $b = count($rows) - 1;
        while ($a <= $b) {
            $mid = intdiv($a + $b, 2);
            if ($rows[$mid][0] <= $xr) {
                $lo = $mid;
                $a = $mid + 1;
            } else {
                $b = $mid - 1;
            }
        }
        if ($lo !== null && $rows[$lo][0] == $xr) {
            $hi = $lo;
        } elseif ($lo !== null && $lo + 1 < count($rows)) {
            $hi = $lo + 1;
        }
        if ($lo === null || $hi === null) {
            return null;
        }
        [$x0, $l0, $m0, $s0] = $rows[$lo];
        [$x1, $l1, $m1, $s1] = $rows[$hi];
        $t = $x1 > $x0 ? ($x - $x0) / ($x1 - $x0) : 0.0;
        return ['l' => $l0 + $t * ($l1 - $l0), 'm' => $m0 + $t * ($m1 - $m0), 's' => $s0 + $t * ($s1 - $s0)];
    }

    /**
     * Tabela inteira (ordenada por x), carregada uma vez por requisicao: dados de referencia fixos,
     * evita duas consultas por medicao nas telas que calculam muitos escores.
     * @return list<array{0:float,1:float,2:float,3:float}>
     */
    private function table(string $indicator, string $sex, string $source): array
    {
        $key = $indicator . '|' . $sex . '|' . $source;
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = array_map(
                static fn (array $r): array => [(float) $r['x'], (float) $r['l'], (float) $r['m'], (float) $r['s']],
                $this->db->all('SELECT x, l, m, s FROM who_lms WHERE indicator = ? AND sex = ? AND source = ? ORDER BY x', [$indicator, self::sexCode($sex), $source]),
            );
        }
        return $this->cache[$key];
    }

    /** @return array{min:float,max:float}|null */
    public function range(string $indicator, string $sex, string $source): ?array
    {
        $r = $this->db->one(
            'SELECT MIN(x) AS mn, MAX(x) AS mx FROM who_lms WHERE indicator = ? AND sex = ? AND source = ?',
            [$indicator, self::sexCode($sex), $source],
        );
        if ($r === null || $r['mn'] === null) {
            return null;
        }
        return ['min' => (float) $r['mn'], 'max' => (float) $r['mx']];
    }

    private static function sexCode(string $sex): int
    {
        return match ($sex) {
            'M' => 1,
            'F' => 2,
            default => throw new \InvalidArgumentException('Sexo invalido: ' . $sex),
        };
    }
}
