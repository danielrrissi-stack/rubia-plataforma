<?php
declare(strict_types=1);

namespace App\Growth;

use App\Core\Database;

/** Limiares de PA da AAP 2017 (tabela bp_reference). Nao final: testes usam limiares falsos. */
class BpReferenceRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{sys90:int,sys95:int,dia90:int,dia95:int} */
    public function thresholds(string $sex, int $age, int $heightPct): array
    {
        $rows = $this->db->all(
            'SELECT bp_pct, systolic, diastolic FROM bp_reference
             WHERE sex = ? AND age_years = ? AND height_pct = ? AND bp_pct IN (90, 95)',
            [self::sexCode($sex), $age, $heightPct],
        );
        $by = array_column($rows, null, 'bp_pct');
        if (!isset($by[90], $by[95])) {
            throw new \RuntimeException("Referencia de PA ausente: sexo $sex, $age anos, percentil de altura $heightPct.");
        }
        return [
            'sys90' => (int) $by[90]['systolic'],
            'sys95' => (int) $by[95]['systolic'],
            'dia90' => (int) $by[90]['diastolic'],
            'dia95' => (int) $by[95]['diastolic'],
        ];
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
