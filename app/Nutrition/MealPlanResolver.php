<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Support\Num;

/**
 * Valida a entrada do editor e a transforma na estrutura resolvida (nomes, medidas e valores por 100 g copiados
 * do banco), que e o que vai em structure_json. `grams` do item = quantidade total em gramas (qty x gramas da medida).
 * A regra "toda refeicao tem item ou nota" para publicar fica a cargo de quem publica ($allowEmptyMeals = false
 * apenas exige item por opcao, salvo refeicao com nota).
 */
final class MealPlanResolver
{
    private const MAX_MEALS = 12;
    private const MAX_OPTIONS = 5;
    private const MAX_ITEMS = 30;
    private const MAX_SUBS = 10;
    private const MAX_QTY = 5000.0;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(private FoodRepository $foods)
    {
    }

    /** @throws InvalidPlanException */
    public function resolve(array $input, bool $allowEmptyMeals = false): array
    {
        $this->errors = [];
        $meals = $input['meals'] ?? null;
        $meals = is_array($meals) ? array_values($meals) : [];
        $out = ['v' => 1, 'meals' => []];
        if ($meals === []) {
            $this->errors[] = 'Informe ao menos uma refeição.';
        } elseif (count($meals) > self::MAX_MEALS) {
            $this->errors[] = 'O plano pode ter no máximo ' . self::MAX_MEALS . ' refeições.';
        } else {
            foreach ($meals as $i => $meal) {
                $out['meals'][] = $this->meal(is_array($meal) ? $meal : [], $i + 1, $allowEmptyMeals);
            }
        }
        if ($this->errors !== []) {
            throw new InvalidPlanException($this->errors);
        }
        return $out;
    }

    private function meal(array $m, int $pos, bool $allowEmpty): array
    {
        $name = trim((string) ($m['name'] ?? ''));
        $label = $name === '' ? 'Refeição ' . $pos : $name;
        if ($name === '' || mb_strlen($name, 'UTF-8') > 60) {
            $this->errors[] = $label . ': informe o nome da refeição (até 60 caracteres).';
        }
        $time = trim((string) ($m['time'] ?? ''));
        if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $this->errors[] = $label . ': informe o horário no formato HH:MM.';
        }
        $note = mb_substr(trim((string) ($m['note'] ?? '')), 0, 500, 'UTF-8');
        $options = $m['options'] ?? [];
        $options = is_array($options) ? array_values($options) : [];
        if (count($options) > self::MAX_OPTIONS) {
            $this->errors[] = $label . ': no máximo ' . self::MAX_OPTIONS . ' opções por refeição.';
            $options = [];
        }
        if ($options === [] && $note === '' && !$allowEmpty) {
            $this->errors[] = $label . ': inclua ao menos uma opção com itens.';
        }
        $resolved = [];
        foreach ($options as $j => $opt) {
            $resolved[] = $this->option(is_array($opt) ? $opt : [], $label . ', opção ' . ($j + 1), $note !== '' || $allowEmpty);
        }
        return ['name' => $name, 'time' => $time, 'note' => $note, 'options' => $resolved];
    }

    private function option(array $o, string $label, bool $emptyOk): array
    {
        $items = $o['items'] ?? [];
        $items = is_array($items) ? array_values($items) : [];
        if ($items === [] && !$emptyOk) {
            $this->errors[] = $label . ': inclua ao menos um item.';
        }
        if (count($items) > self::MAX_ITEMS) {
            $this->errors[] = $label . ': no máximo ' . self::MAX_ITEMS . ' itens por opção.';
            return ['items' => []];
        }
        $out = [];
        foreach ($items as $k => $it) {
            $it = is_array($it) ? $it : [];
            $itemLabel = $label . ', item ' . ($k + 1);
            $row = $this->line($it, $itemLabel);
            $row['note'] = mb_substr(trim((string) ($it['note'] ?? '')), 0, 200, 'UTF-8');
            $subs = $it['subs'] ?? [];
            $subs = is_array($subs) ? array_values($subs) : [];
            if (count($subs) > self::MAX_SUBS) {
                $this->errors[] = $itemLabel . ': no máximo ' . self::MAX_SUBS . ' substitutos por item.';
                $subs = [];
            }
            $row['subs'] = [];
            foreach ($subs as $s => $sub) {
                $row['subs'][] = $this->line(is_array($sub) ? $sub : [], $itemLabel . ', substituto ' . ($s + 1));
            }
            $out[] = $row;
        }
        return ['items' => $out];
    }

    /** Alimento + quantidade + medida; erros sao acumulados e a linha volta com o que foi possivel resolver. */
    private function line(array $in, string $label): array
    {
        $qty = $this->qty($in['qty'] ?? null);
        if ($qty === null) {
            $this->errors[] = $label . ': informe uma quantidade maior que zero (até 5000).';
            $qty = 0.0;
        }
        $foodId = $this->int($in['food_id'] ?? null);
        $food = $foodId === null ? null : $this->foods->find($foodId);
        if ($food === null) {
            $this->errors[] = $label . ': alimento não encontrado.';
        }
        $measureId = $this->int($in['measure_id'] ?? null);
        $measure = null;
        if ($measureId !== null && $food !== null) {
            $measure = $this->foods->findMeasure($measureId);
            if ($measure === null) {
                $this->errors[] = $label . ': medida não encontrada.';
            } elseif ((int) $measure['food_id'] !== (int) $food['id']) {
                $this->errors[] = $label . ': a medida não pertence ao alimento escolhido.';
                $measure = null;
            }
        }
        $perGram = $measure !== null ? (float) $measure['grams'] : 1.0;
        return [
            'food_id' => $food === null ? ($foodId ?? 0) : (int) $food['id'],
            'food_name' => $food === null ? '' : (string) $food['name'],
            'qty' => $qty,
            'measure_id' => $measure === null ? null : (int) $measure['id'],
            'measure_name' => $measure === null ? null : (string) $measure['name'],
            'measure_plural' => $measure === null || $measure['name_plural'] === null ? null : (string) $measure['name_plural'],
            'grams' => round($qty * $perGram, 4),
            'per100' => [
                'kcal' => $this->nutrient($food, 'kcal'),
                'protein' => $this->nutrient($food, 'protein_g'),
                'carb' => $this->nutrient($food, 'carb_g'),
                'fat' => $this->nutrient($food, 'fat_g'),
                'fiber' => $this->nutrient($food, 'fiber_g'),
            ],
        ];
    }

    private function qty(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            $v = (float) $raw;
        } elseif (is_string($raw)) {
            try {
                $v = Num::parse($raw);
            } catch (\InvalidArgumentException) {
                return null;
            }
        } else {
            return null;
        }
        if ($v === null || !is_finite($v) || $v <= 0 || $v > self::MAX_QTY) {
            return null;
        }
        return $v;
    }

    private function int(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        return is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : null;
    }

    private function nutrient(?array $food, string $col): ?float
    {
        return $food === null || $food[$col] === null ? null : (float) $food[$col];
    }
}
