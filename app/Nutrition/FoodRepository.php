<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;

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

    private const MACROS = ['protein_g' => 'Proteína', 'carb_g' => 'Carboidrato', 'fat_g' => 'Gordura', 'fiber_g' => 'Fibra'];

    /** Valida e normaliza os campos de um alimento proprio (valores por 100 g). */
    private static function cleanFood(array $f): array
    {
        $name = is_string($f['name'] ?? null) ? trim(mb_scrub($f['name'], 'UTF-8')) : '';
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 2 || $len > 200) {
            throw new \RuntimeException('Informe o nome do alimento (2 a 200 caracteres).');
        }
        $group = is_string($f['group_name'] ?? null) ? trim(mb_scrub($f['group_name'], 'UTF-8')) : '';
        if (mb_strlen($group, 'UTF-8') > 100) {
            throw new \RuntimeException('O grupo deve ter no máximo 100 caracteres.');
        }
        $kcal = $f['kcal'] ?? null;
        if (!is_int($kcal) && !is_float($kcal)) {
            throw new \RuntimeException('Calorias: informe um número entre 0 e 900 (por 100 g).');
        }
        if ($kcal < 0 || $kcal > 900) {
            throw new \RuntimeException('Calorias devem estar entre 0 e 900 por 100 g.');
        }
        $row = [
            'name' => $name,
            'name_search' => self::normalize($name),
            'group_name' => $group === '' ? null : $group,
            'kcal' => round((float) $kcal, 1),
        ];
        foreach (self::MACROS as $k => $label) {
            $v = $f[$k] ?? null;
            if ($v === null) {
                $row[$k] = null;
                continue;
            }
            if ((!is_int($v) && !is_float($v)) || $v < 0 || $v > 100) {
                throw new \RuntimeException($label . ' deve ser um número entre 0 e 100 (por 100 g).');
            }
            $row[$k] = round((float) $v, 1);
        }
        return $row;
    }

    private static function cleanMeasure(array $m): array
    {
        $name = is_string($m['name'] ?? null) ? trim(mb_scrub($m['name'], 'UTF-8')) : '';
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 2 || $len > 100) {
            throw new \RuntimeException('Informe o nome da medida (2 a 100 caracteres).');
        }
        $plural = is_string($m['name_plural'] ?? null) ? trim(mb_scrub($m['name_plural'], 'UTF-8')) : '';
        if (mb_strlen($plural, 'UTF-8') > 100) {
            throw new \RuntimeException('O plural da medida deve ter no máximo 100 caracteres.');
        }
        $g = $m['grams'] ?? null;
        if ((!is_int($g) && !is_float($g)) || $g < 0.1 || $g > 2000) {
            throw new \RuntimeException('O peso da medida deve estar entre 0,1 e 2000 gramas.');
        }
        return ['name' => $name, 'name_plural' => $plural === '' ? null : $plural, 'grams' => round((float) $g, 1)];
    }

    private function ownFood(int $id): array
    {
        $food = $this->find($id);
        if ($food === null) {
            throw new \RuntimeException('Alimento não encontrado.');
        }
        if ($food['source'] !== 'proprio') {
            throw new \RuntimeException('Alimentos da tabela TACO não podem ser alterados; você pode adicionar medidas caseiras.');
        }
        return $food;
    }

    private function ownMeasure(int $id): array
    {
        $m = $this->findMeasure($id);
        if ($m === null) {
            throw new \RuntimeException('Medida não encontrada.');
        }
        if ($m['source'] !== 'proprio') {
            throw new \RuntimeException('Medidas padrão (POF) não podem ser alteradas.');
        }
        return $m;
    }

    private static function foodAudit(array $row): array
    {
        return array_intersect_key($row, array_flip(['name', 'group_name', 'kcal', 'protein_g', 'carb_g', 'fat_g', 'fiber_g', 'active']));
    }

    public function createOwn(array $f, int $by, ?Request $r = null): int
    {
        $row = self::cleanFood($f);
        return $this->db->transaction(function () use ($row, $by, $r): int {
            $id = $this->db->insert('foods', $row + ['source' => 'proprio', 'created_by' => $by]);
            Container::audit()->record($by, 'create', 'foods', $id, null, null, self::foodAudit($row), $r);
            return $id;
        });
    }

    public function updateOwn(int $id, array $f, int $by, ?Request $r = null): void
    {
        $before = $this->ownFood($id);
        $row = self::cleanFood($f);
        $this->db->transaction(function () use ($id, $row, $before, $by, $r): void {
            $this->db->run(
                'UPDATE foods SET name = ?, name_search = ?, group_name = ?, kcal = ?, protein_g = ?, carb_g = ?, fat_g = ?, fiber_g = ?, updated_at = NOW() WHERE id = ?',
                [$row['name'], $row['name_search'], $row['group_name'], $row['kcal'], $row['protein_g'], $row['carb_g'], $row['fat_g'], $row['fiber_g'], $id],
            );
            Container::audit()->record($by, 'update', 'foods', $id, null, self::foodAudit($before), self::foodAudit($row), $r);
        });
    }

    public function setActive(int $id, bool $on, int $by, ?Request $r = null): void
    {
        $this->ownFood($id);
        $this->db->transaction(function () use ($id, $on, $by, $r): void {
            $this->db->run('UPDATE foods SET active = ?, updated_at = NOW() WHERE id = ?', [$on ? 1 : 0, $id]);
            Container::audit()->record($by, 'update', 'foods', $id, null, ['active' => $on ? 0 : 1], ['active' => $on ? 1 : 0], $r);
        });
    }

    public function addMeasure(int $foodId, array $m, int $by, ?Request $r = null): int
    {
        if ($this->find($foodId) === null) {
            throw new \RuntimeException('Alimento não encontrado.');
        }
        $row = self::cleanMeasure($m);
        return $this->db->transaction(function () use ($foodId, $row, $by, $r): int {
            $id = $this->db->insert('food_measures', $row + ['food_id' => $foodId, 'source' => 'proprio', 'created_by' => $by]);
            Container::audit()->record($by, 'create', 'food_measures', $id, null, null, $row + ['food_id' => $foodId], $r);
            return $id;
        });
    }

    public function updateMeasure(int $id, array $m, int $by, ?Request $r = null): void
    {
        $before = $this->ownMeasure($id);
        $row = self::cleanMeasure($m);
        $this->db->transaction(function () use ($id, $row, $before, $by, $r): void {
            $this->db->run('UPDATE food_measures SET name = ?, name_plural = ?, grams = ? WHERE id = ?', [$row['name'], $row['name_plural'], $row['grams'], $id]);
            Container::audit()->record($by, 'update', 'food_measures', $id, null, [
                'name' => $before['name'], 'name_plural' => $before['name_plural'], 'grams' => $before['grams'],
            ], $row, $r);
        });
    }

    public function setMeasureActive(int $id, bool $on, int $by, ?Request $r = null): void
    {
        $this->ownMeasure($id);
        $this->db->transaction(function () use ($id, $on, $by, $r): void {
            $this->db->run('UPDATE food_measures SET active = ? WHERE id = ?', [$on ? 1 : 0, $id]);
            Container::audit()->record($by, 'update', 'food_measures', $id, null, ['active' => $on ? 0 : 1], ['active' => $on ? 1 : 0], $r);
        });
    }

    /** Lista para a tela "Minha tabela": inclui inativos; $onlyOwn filtra proprios. */
    public function adminList(string $q, bool $onlyOwn, int $limit = 100): array
    {
        $where = [];
        $params = [];
        foreach (array_filter(explode(' ', self::normalize($q)), static fn(string $t): bool => $t !== '') as $t) {
            $where[] = 'name_search LIKE ?';
            $params[] = '%' . addcslashes($t, '\\%_') . '%';
        }
        if ($onlyOwn) {
            $where[] = "source = 'proprio'";
        }
        return $this->db->all(
            'SELECT * FROM foods' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . " ORDER BY (source = 'proprio') DESC, name LIMIT " . max(1, $limit),
            $params,
        );
    }

    /** Todas as medidas (inclusive inativas) para a tela de edicao. */
    public function allMeasures(int $foodId): array
    {
        return $this->db->all('SELECT * FROM food_measures WHERE food_id = ? ORDER BY active DESC, name', [$foodId]);
    }
}
