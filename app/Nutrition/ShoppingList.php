<?php
declare(strict_types=1);

namespace App\Nutrition;

/** Lista de compras dos dias exibidos (so a opcao 1 de cada refeicao, sem trocas). Sem banco. */
final class ShoppingList
{
    /**
     * @param list<array> $dayStructures estruturas v1, uma por dia
     * @param array<int,string> $groups food_id => nome do grupo (para reconhecer bebidas)
     * @return list<array{name:string, text:string}>
     */
    public static function build(array $dayStructures, array $groups = []): array
    {
        $acc = [];
        foreach ($dayStructures as $day) {
            foreach ((array) ($day['meals'] ?? []) as $meal) {
                $first = ((array) ($meal['options'] ?? []))[0] ?? null;
                foreach ((array) ($first['items'] ?? []) as $item) {
                    $grams = (float) ($item['grams'] ?? 0);
                    if ($grams <= 0) {
                        continue;
                    }
                    $id = (int) ($item['food_id'] ?? 0);
                    $acc[$id] ??= ['item' => $item, 'grams' => 0.0, 'qty' => 0.0, 'unit' => null, 'plain' => false, 'mixed' => false];
                    $acc[$id]['grams'] += $grams;
                    $unit = self::unitWord($item);
                    if ($unit === null) {
                        $acc[$id]['plain'] = true;
                    } else {
                        if ($acc[$id]['unit'] !== null && $acc[$id]['unit'] !== $unit) {
                            $acc[$id]['mixed'] = true;
                        }
                        $acc[$id]['unit'] = $unit;
                        $acc[$id]['qty'] += (float) ($item['qty'] ?? 0);
                    }
                }
            }
        }
        $out = [];
        foreach ($acc as $id => $a) {
            $name = FamilyPhrase::foodName($a['item']);
            if ($a['unit'] !== null && !$a['plain'] && !$a['mixed']) {
                $n = (int) ceil($a['qty'] - 1e-9);
                $text = $n . ' ' . ($n === 1 ? $a['unit'][0] : $a['unit'][1]);
            } elseif (self::isLiquid($name, (string) ($groups[$id] ?? ''))) {
                $l = ceil($a['grams'] / 500 - 1e-9) / 2;
                $text = rtrim(rtrim(number_format($l, 1, ',', ''), '0'), ',') . ' L';
            } elseif (ceil($a['grams'] / 50 - 1e-9) * 50 >= 1000) {
                $text = number_format(ceil($a['grams'] / 100 - 1e-9) / 10, 1, ',', '') . ' kg';
            } else {
                $text = (int) (ceil($a['grams'] / 50 - 1e-9) * 50) . ' g';
            }
            $out[] = ['name' => $name, 'text' => $text];
        }
        usort($out, static fn(array $x, array $y): int => strcmp(self::sortKey($x['name']), self::sortKey($y['name'])) ?: strcmp($x['name'], $y['name']));
        return $out;
    }

    /** @return ?array{0:string,1:string} singular e plural, se a medida for do tipo unidade */
    private static function unitWord(array $item): ?array
    {
        if (($item['measure_id'] ?? null) === null) {
            return null;
        }
        $m = mb_strtolower(trim((string) ($item['measure_name'] ?? '')), 'UTF-8');
        if (str_starts_with($m, 'unidade') || str_starts_with($m, 'pão')) {
            return ['unidade', 'unidades'];
        }
        if (str_starts_with($m, 'fatia')) {
            return ['fatia', 'fatias'];
        }
        return null;
    }

    private static function isLiquid(string $name, string $group): bool
    {
        return str_starts_with(mb_strtolower($name, 'UTF-8'), 'leite') || str_starts_with(mb_strtolower($group, 'UTF-8'), 'bebidas');
    }

    /** Chave de ordenacao sem acento e sem diferenca de caixa. */
    private static function sortKey(string $name): string
    {
        return strtr(mb_strtolower($name, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }
}
