<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Core\Database;

/** Base de alimentos (TACO + proprios) e medidas caseiras. */
final class FoodRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Minusculas, sem acento, espacos colapsados ("Pão, Trigo,  FRANCÊS" -> "pao, trigo, frances"). */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Inclui inativos. */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM foods WHERE id = ?', [$id]);
    }

    /** Somente ativos; cada termo precisa aparecer em name_search; proprios primeiro. */
    public function search(string $q, int $limit = 20): array
    {
        $terms = array_values(array_filter(explode(' ', self::normalize($q)), static fn(string $t): bool => $t !== ''));
        if ($terms === [] || mb_strlen(implode('', $terms), 'UTF-8') < 2) {
            return [];
        }
        $where = ['active = 1'];
        $params = [];
        foreach ($terms as $t) {
            $where[] = 'name_search LIKE ?';
            $params[] = '%' . addcslashes($t, '\\%_') . '%';
        }
        $rows = $this->db->all(
            'SELECT * FROM foods WHERE ' . implode(' AND ', $where)
            . " ORDER BY (source = 'proprio') DESC, CHAR_LENGTH(name), name LIMIT " . max(1, $limit),
            $params,
        );
        foreach ($rows as &$r) {
            $r['measures'] = $this->measures((int) $r['id']);
        }
        unset($r);
        return $rows;
    }

    /** Medidas ativas do alimento. */
    public function measures(int $foodId): array
    {
        return $this->db->all('SELECT * FROM food_measures WHERE food_id = ? AND active = 1 ORDER BY name', [$foodId]);
    }

    public function findMeasure(int $id): ?array
    {
        return $this->db->one('SELECT * FROM food_measures WHERE id = ?', [$id]);
    }
}
