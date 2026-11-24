<?php
declare(strict_types=1);

namespace App\Diets;

use App\Core\Database;

/** Banco de dietas prontas (seed food_4_diet_library.sql). Somente leitura. */
final class DietLibraryRepository
{
    public function __construct(private Database $db)
    {
    }

    public function findByCode(string $code): ?array
    {
        return $this->decode($this->db->one('SELECT * FROM diet_library WHERE code = ?', [$code]));
    }

    public function pick(string $band, int $week, int $kcal): ?array
    {
        return $this->decode($this->db->one(
            'SELECT * FROM diet_library WHERE band = ? AND week = ? AND kcal_target = ?',
            [$band, $week, $kcal],
        ));
    }

    /** Lista para a tela de consulta (sem a estrutura). Filtros opcionais. */
    public function search(?string $band, ?int $week, ?int $kcal): array
    {
        $where = [];
        $args = [];
        foreach (['band' => $band, 'week' => $week, 'kcal_target' => $kcal] as $col => $v) {
            if ($v !== null) {
                $where[] = $col . ' = ?';
                $args[] = $v;
            }
        }
        return $this->db->all(
            'SELECT id, code, band, week, season, kcal_target, kcal, protein_g, carb_g, fat_g, fiber_g, title FROM diet_library'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY band, week, kcal_target LIMIT 200',
            $args,
        );
    }

    public function count(): int
    {
        return (int) ($this->db->one('SELECT COUNT(*) AS n FROM diet_library')['n'] ?? 0);
    }

    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['structure'] = json_decode((string) $row['structure_json'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }
}
