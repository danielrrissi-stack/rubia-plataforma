<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

/** Base de alimentos para a equipe (nesta fase: so a busca usada pelo editor estruturado). */
final class FoodController
{
    /** GET /alimentos/busca?q= */
    public function search(Request $r): Response
    {
        Staff::assert($r->attributes['user'], $r);
        $q = $r->query['q'] ?? '';
        $q = is_string($q) ? mb_substr(mb_scrub($q, 'UTF-8'), 0, 100, 'UTF-8') : '';
        $items = [];
        foreach (Container::foods()->search($q, 20) as $f) {
            $items[] = [
                'id' => (int) $f['id'],
                'name' => (string) $f['name'],
                'group' => $f['group_name'] === null ? null : (string) $f['group_name'],
                'source' => (string) $f['source'],
                'per100' => [
                    'kcal' => self::num($f['kcal']),
                    'protein' => self::num($f['protein_g']),
                    'carb' => self::num($f['carb_g']),
                    'fat' => self::num($f['fat_g']),
                    'fiber' => self::num($f['fiber_g']),
                ],
                'measures' => array_map(static fn(array $m): array => [
                    'id' => (int) $m['id'],
                    'name' => (string) $m['name'],
                    'plural' => $m['name_plural'] === null ? null : (string) $m['name_plural'],
                    'grams' => (float) $m['grams'],
                ], $f['measures']),
            ];
        }
        return Response::json(['items' => $items]);
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
