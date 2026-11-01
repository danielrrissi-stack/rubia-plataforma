<?php
declare(strict_types=1);

namespace App\Patients;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Growth\Age;

final class PatientRepository
{
    private const COLUMNS = [
        'user_id', 'full_name', 'social_name', 'birth_date', 'sex', 'cpf', 'cns', 'phone', 'email',
        'address', 'responsible_professional_id', 'admin_notes',
    ];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function create(array $d, int $byUserId, ?Request $r = null): int
    {
        $row = array_intersect_key($d, array_flip(self::COLUMNS));
        $row['created_by'] = $byUserId;
        $id = $this->db->insert('patients', $row);
        $this->audit->record($byUserId, 'create', 'patients', $id, $id, null, $this->find($id), $r);
        return $id;
    }

    public function update(int $id, array $d, int $byUserId, ?Request $r = null): void
    {
        $before = $this->find($id);
        $fields = array_intersect_key($d, array_flip(self::COLUMNS));
        if ($before === null || $fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($fields)));
        $this->db->run("UPDATE patients SET $set, updated_at = NOW() WHERE id = ?", [...array_values($fields), $id]);
        $this->audit->record($byUserId, 'update', 'patients', $id, $id, $before, $this->find($id), $r);
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM patients WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** @param int[]|null $onlyIds null = sem restricao; [] = nenhum */
    public function search(string $q, ?array $onlyIds, int $limit = 50): array
    {
        if ($onlyIds === []) {
            return [];
        }
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            $cond = 'p.full_name LIKE ? OR p.social_name LIKE ?';
            $params[] = $like;
            $params[] = $like;
            if ($digits !== '') {
                $cond .= " OR REPLACE(REPLACE(p.cpf, '.', ''), '-', '') LIKE ?";
                $params[] = '%' . $digits . '%';
            }
            $where[] = "($cond)";
        }
        if ($onlyIds !== null) {
            $where[] = 'p.id IN (' . implode(',', array_map('intval', $onlyIds)) . ')';
        }
        return $this->db->all(
            'SELECT p.* FROM patients p WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.full_name LIMIT ' . max(1, $limit),
            $params
        );
    }

    public function ageInDays(array $patient, string $onDate): int
    {
        return Age::days((string) $patient['birth_date'], $onDate);
    }

    public function isPediatric(array $p, string $onDate): bool
    {
        return Age::months((string) $p['birth_date'], $onDate) < 228;
    }
}
