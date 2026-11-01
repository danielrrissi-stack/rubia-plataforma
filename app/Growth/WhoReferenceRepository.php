<?php
declare(strict_types=1);

namespace App\Growth;

use App\Core\Database;

/** Acesso as tabelas LMS da OMS (who_lms), com interpolacao linear em x. */
final class WhoReferenceRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param string $sex 'M'|'F'
     * @return array{l:float,m:float,s:float}|null  null quando x esta fora da tabela
     */
    public function lms(string $indicator, string $sex, float $x, string $source): ?array
    {
        $params = [$indicator, self::sexCode($sex), $source, sprintf('%.6F', $x)];
        $base = 'SELECT x, l, m, s FROM who_lms WHERE indicator = ? AND sex = ? AND source = ?';
        $lo = $this->db->one($base . ' AND x <= ? ORDER BY x DESC LIMIT 1', $params);
        $hi = $this->db->one($base . ' AND x >= ? ORDER BY x ASC LIMIT 1', $params);
        if ($lo === null || $hi === null) {
            return null;
        }
        $x0 = (float) $lo['x'];
        $x1 = (float) $hi['x'];
        $t = $x1 > $x0 ? ($x - $x0) / ($x1 - $x0) : 0.0;
        $out = [];
        foreach (['l', 'm', 's'] as $k) {
            $out[$k] = (float) $lo[$k] + $t * ((float) $hi[$k] - (float) $lo[$k]);
        }
        return $out;
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
