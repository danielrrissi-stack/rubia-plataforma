<?php
declare(strict_types=1);

namespace App\Evolution;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Support\Num;

/** Criterio de "estabilizou" por paciente. Sem linha salva valem os padroes. */
final class EvolutionSettingsRepository
{
    public const DEFAULTS = ['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05];

    /** campo do formulario => [coluna, maximo (exclusivo), rotulo] */
    private const FIELDS = [
        'kg' => ['kg_per_week', 5.0, 'kg por semana'],
        'pct' => ['pct_per_4w', 20.0, '% em 4 semanas'],
        'z' => ['z_per_4w', 1.0, 'escore-z em 4 semanas'],
    ];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return array{adult_mode:string,kg_per_week:float,pct_per_4w:float,z_per_4w:float,saved:bool} */
    public function get(int $patientId): array
    {
        $row = $this->db->one('SELECT * FROM evolution_settings WHERE patient_id = ?', [$patientId]);
        if ($row === null) {
            return self::DEFAULTS + ['saved' => false];
        }
        return [
            'adult_mode' => (string) $row['adult_mode'],
            'kg_per_week' => (float) $row['kg_per_week'],
            'pct_per_4w' => (float) $row['pct_per_4w'],
            'z_per_4w' => (float) $row['z_per_4w'],
            'saved' => true,
        ];
    }

    /** @param array{modo?:string,kg?:string,pct?:string,z?:string} $raw */
    public function save(int $patientId, array $raw, int $by, Request $r): void
    {
        $errors = [];
        $mode = (string) ($raw['modo'] ?? '');
        if (!in_array($mode, ['kg', 'pct'], true)) {
            $errors['modo'] = 'Escolha kg por semana ou % em 4 semanas.';
        }
        $values = [];
        foreach (self::FIELDS as $field => [$col, $max, $label]) {
            try {
                $v = Num::parse((string) ($raw[$field] ?? ''));
            } catch (\InvalidArgumentException) {
                $v = null;
            }
            if ($v === null || $v <= 0 || $v >= $max) {
                $errors[$field] = 'Informe um valor maior que 0 e menor que ' . fmt_num($max, 0) . ' (' . $label . ').';
                continue;
            }
            $values[$col] = $v;
        }
        if ($errors !== []) {
            throw new InvalidEvolutionSettingsException($errors);
        }
        $before = $this->get($patientId);
        $this->db->run(
            'INSERT INTO evolution_settings (patient_id, adult_mode, kg_per_week, pct_per_4w, z_per_4w, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE adult_mode = VALUES(adult_mode), kg_per_week = VALUES(kg_per_week),
               pct_per_4w = VALUES(pct_per_4w), z_per_4w = VALUES(z_per_4w), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$patientId, $mode, $values['kg_per_week'], $values['pct_per_4w'], $values['z_per_4w'], $by],
        );
        $this->audit->record($by, 'update', 'evolution_settings', $patientId, $patientId, $before['saved'] ? $before : null, $this->get($patientId), $r);
    }
}
