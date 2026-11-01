<?php
declare(strict_types=1);

namespace App\Growth;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/**
 * Medidas antropometricas versionadas. Nada e apagado fisicamente: correcao cria nova linha
 * (previous_id) e marca a antiga com superseded_at; remocao e logica (deleted_at + motivo).
 */
final class MeasurementRepository
{
    public const RETROACTIVE_DAYS = 7;
    private const FIELDS = ['measured_on', 'weight_kg', 'height_cm', 'position', 'waist_cm', 'hip_cm', 'head_cm', 'systolic', 'diastolic'];
    private const MEASURES = ['weight_kg', 'height_cm', 'waist_cm', 'hip_cm', 'head_cm', 'systolic', 'diastolic'];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /**
     * Validacao de dominio. $d: measured_on (Y-m-d|null) e medidas ja numericas (ou null).
     *
     * @return string[] mensagens pt-BR
     */
    public static function validate(array $d, string $birthYmd, string $todayYmd): array
    {
        $errors = [];
        $on = $d['measured_on'] ?? null;
        if (!is_string($on) || $on === '') {
            $errors[] = 'Informe a data da medida.';
        } elseif ($on < substr($birthYmd, 0, 10)) {
            $errors[] = 'A data da medida não pode ser anterior ao nascimento.';
        } elseif ($on > $todayYmd) {
            $errors[] = 'A data da medida não pode ser futura.';
        }

        $any = false;
        foreach (self::MEASURES as $k) {
            if (($d[$k] ?? null) !== null) {
                $any = true;
            }
        }
        if (!$any) {
            $errors[] = 'Preencha ao menos um campo de medida.';
        }

        $ranges = [
            'weight_kg' => ['Peso', 0.3, 350.0, 'kg'],
            'height_cm' => ['Altura', 30.0, 250.0, 'cm'],
            'waist_cm' => ['Cintura', 20.0, 250.0, 'cm'],
            'hip_cm' => ['Quadril', 20.0, 250.0, 'cm'],
            'head_cm' => ['Perímetro cefálico', 20.0, 70.0, 'cm'],
            'systolic' => ['Pressão sistólica', 50.0, 260.0, 'mmHg'],
            'diastolic' => ['Pressão diastólica', 30.0, 160.0, 'mmHg'],
        ];
        foreach ($ranges as $k => [$label, $min, $max, $unit]) {
            $v = $d[$k] ?? null;
            if ($v !== null && ((float) $v < $min || (float) $v > $max)) {
                $errors[] = sprintf('%s deve estar entre %s e %s %s.', $label, self::n($min), self::n($max), $unit);
            }
        }

        $sys = $d['systolic'] ?? null;
        $dia = $d['diastolic'] ?? null;
        if (($sys === null) !== ($dia === null)) {
            $errors[] = 'Informe a pressão sistólica e a diastólica juntas.';
        } elseif ($sys !== null && $dia !== null && (int) $sys <= (int) $dia) {
            $errors[] = 'A pressão sistólica deve ser maior que a diastólica.';
        }
        return $errors;
    }

    public function create(int $patientId, array $d, string $source, int $by, ?Request $r = null): int
    {
        if (!in_array($source, ['equipe', 'familia'], true)) {
            throw new \InvalidArgumentException('Origem inválida.');
        }
        $row = $this->normalize($patientId, $d);
        return $this->db->transaction(function () use ($patientId, $row, $source, $by, $r): int {
            $id = $this->db->insert('measurements', $row + [
                'patient_id' => $patientId, 'source' => $source, 'created_by' => $by,
                'retroactive' => $this->isRetroactive($row['measured_on']),
            ]);
            $this->audit->record($by, 'create', 'measurements', $id, $patientId, null, $row + ['source' => $source], $r);
            return $id;
        });
    }

    /** Nova versao (origem "equipe"); a anterior fica substituida. Retorna o novo id. */
    public function correct(int $id, array $d, int $by, ?Request $r = null): int
    {
        return $this->db->transaction(function () use ($id, $d, $by, $r): int {
            $old = $this->db->one('SELECT * FROM measurements WHERE id = ? FOR UPDATE', [$id]);
            if ($old === null || $old['superseded_at'] !== null || $old['deleted_at'] !== null) {
                throw new \InvalidArgumentException('Esta medida não pode mais ser corrigida (já foi substituída ou removida).');
            }
            $pid = (int) $old['patient_id'];
            $row = $this->normalize($pid, $d);
            $new = $this->db->insert('measurements', $row + [
                'patient_id' => $pid, 'source' => 'equipe', 'created_by' => $by, 'previous_id' => $id,
                'retroactive' => $this->isRetroactive($row['measured_on']),
            ]);
            $this->db->run('UPDATE measurements SET superseded_at = NOW() WHERE id = ?', [$id]);
            $this->audit->record($by, 'update', 'measurements', $new, $pid, self::snapshot($old), $row + ['previous_id' => $id], $r);
            return $new;
        });
    }

    public function remove(int $id, string $reason, int $by, ?Request $r = null): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('Informe o motivo da remoção (até 255 caracteres).');
        }
        $this->db->transaction(function () use ($id, $reason, $by, $r): void {
            $old = $this->db->one('SELECT * FROM measurements WHERE id = ? FOR UPDATE', [$id]);
            if ($old === null || $old['superseded_at'] !== null || $old['deleted_at'] !== null) {
                throw new \InvalidArgumentException('Esta medida não pode mais ser removida.');
            }
            $this->db->run('UPDATE measurements SET deleted_at = NOW(), deleted_reason = ? WHERE id = ?', [$reason, $id]);
            $this->audit->record($by, 'delete', 'measurements', $id, (int) $old['patient_id'], self::snapshot($old), ['deleted_reason' => $reason], $r);
        });
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM measurements WHERE id = ?', [$id]);
    }

    /** Medidas vigentes (nao substituidas, nao removidas), por data. */
    public function current(int $patientId): array
    {
        return $this->db->all(
            'SELECT * FROM measurements WHERE patient_id = ? AND superseded_at IS NULL AND deleted_at IS NULL ORDER BY measured_on, id',
            [$patientId],
        );
    }

    public function latest(int $patientId): ?array
    {
        return $this->db->one(
            'SELECT * FROM measurements WHERE patient_id = ? AND superseded_at IS NULL AND deleted_at IS NULL ORDER BY measured_on DESC, id DESC LIMIT 1',
            [$patientId],
        );
    }

    /** Cadeia completa de versoes (da primeira a mais recente) a que $id pertence. */
    public function versions(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return [];
        }
        while ($row['previous_id'] !== null) {
            $prev = $this->find((int) $row['previous_id']);
            if ($prev === null) {
                break;
            }
            $row = $prev;
        }
        $chain = [$row];
        while (($next = $this->db->one('SELECT * FROM measurements WHERE previous_id = ? ORDER BY id LIMIT 1', [$row['id']])) !== null) {
            $chain[] = $next;
            $row = $next;
        }
        return $chain;
    }

    /** @return array<string,mixed> campos validados, prontos para gravar */
    private function normalize(int $patientId, array $d): array
    {
        $p = $this->db->one('SELECT birth_date FROM patients WHERE id = ?', [$patientId]);
        if ($p === null) {
            throw new \InvalidArgumentException('Paciente não encontrado.');
        }
        $errors = self::validate($d, (string) $p['birth_date'], date('Y-m-d'));
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        $row = [];
        foreach (self::FIELDS as $k) {
            $row[$k] = $d[$k] ?? null;
        }
        if (!in_array($row['position'], ['deitado', 'em_pe'], true) || $row['height_cm'] === null) {
            $row['position'] = null;
        }
        foreach (['systolic', 'diastolic'] as $k) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
        return $row;
    }

    private function isRetroactive(string $measuredOn): int
    {
        return Age::days($measuredOn, date('Y-m-d')) > self::RETROACTIVE_DAYS ? 1 : 0;
    }

    private static function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(array_merge(self::FIELDS, ['source', 'retroactive'])));
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
}
