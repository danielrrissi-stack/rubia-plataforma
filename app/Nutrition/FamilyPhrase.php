<?php
declare(strict_types=1);

namespace App\Nutrition;

/** Frase em linguagem leiga de um item do plano ("2 colheres de sopa de Arroz, tipo 1, cozido"). */
final class FamilyPhrase
{
    /** Ate 2 casas, virgula decimal, sem zeros a direita. */
    public static function qty(float $q): string
    {
        $s = number_format($q, 2, ',', '');
        return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    /** Nome do alimento, sem quantidade nem medida. */
    public static function foodName(array $item): string
    {
        return (string) ($item['food_name'] ?? '');
    }

    public static function item(array $item): string
    {
        $qty = (float) ($item['qty'] ?? 0);
        $food = (string) ($item['food_name'] ?? '');
        $note = trim((string) ($item['note'] ?? ''));
        if (($item['measure_id'] ?? null) === null) {
            $s = self::qty($qty) . ' g de ' . $food;
        } else {
            $one = (string) ($item['measure_name'] ?? '');
            $many = $item['measure_plural'] ?? null;
            if ($qty <= 1) {
                $s = self::qty($qty) . ' ' . $one . ' de ' . $food;
            } elseif ($many !== null && $many !== '') {
                $s = self::qty($qty) . ' ' . $many . ' de ' . $food;
            } else {
                $s = self::qty($qty) . ' × ' . $one . ' de ' . $food;
            }
        }
        return $note === '' ? $s : $s . ' (' . $note . ')';
    }
}
