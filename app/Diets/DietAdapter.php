<?php
declare(strict_types=1);

namespace App\Diets;

use App\Nutrition\FoodRepository;

/**
 * Adapta uma dieta pronta as restricoes alimentares do paciente (sem banco).
 * Lactose: mantem o alimento com a observacao "sem lactose". Demais: troca por alternativa de mesmas calorias.
 * Trocas ("ou ...") com alimento restrito saem. "Outros alimentos a evitar" (texto livre) so gera aviso.
 */
final class DietAdapter
{
    public const RESTRICTIONS = [
        'lactose' => 'Intolerância à lactose',
        'leite' => 'Alergia à proteína do leite de vaca',
        'ovo' => 'Ovo',
        'gluten' => 'Glúten (trigo, aveia, centeio, cevada)',
        'amendoim' => 'Amendoim',
        'castanhas' => 'Castanhas e nozes',
        'peixe' => 'Peixe',
        'frutos_mar' => 'Frutos do mar',
        'soja' => 'Soja',
    ];

    /** Alternativas por alimento restrito, em ordem de preferencia (a primeira sem restricao vale). */
    private const ALTERNATIVES = [
        458 => [582], 457 => [582], 449 => [582], 448 => [582],
        461 => [488, 410], 463 => [488, 410], 464 => [], 469 => [488, 410], 468 => [163], 484 => [488, 410],
        262 => [260], 261 => [260], 551 => [533],
        488 => [461, 410],
        53 => [533, 551], 52 => [533, 551], 48 => [533, 551], 63 => [533, 551], 40 => [3], 7 => [22], 13 => [99],
        558 => [163], 588 => [163], 589 => [163], 594 => [],
        301 => [410, 377], 276 => [410, 377], 313 => [410, 377], 318 => [410, 377], 277 => [410, 377], 284 => [410, 377],
        584 => [488, 410], 582 => [457, 478],
    ];

    /**
     * @param array<string,list<int>> $tags etiqueta => codigos (cardapio.php 'tags')
     * @param array<int,array{name:string,kcal:?float}> $foods por id
     * @param array<int,list<array{id:int,name:string,grams:float}>> $measures por id do alimento
     */
    public function __construct(private array $tags, private array $foods, private array $measures)
    {
    }

    /** Ids de alimentos que este adaptador pode precisar (estrutura + alternativas), para carregar do banco. */
    public static function foodIds(array $structure): array
    {
        $ids = [];
        foreach ($structure['meals'] ?? [] as $m) {
            foreach ($m['options'] ?? [] as $o) {
                foreach ($o['items'] ?? [] as $it) {
                    $ids[] = (int) $it['food_id'];
                    foreach ($it['subs'] ?? [] as $s) {
                        $ids[] = (int) $s['food_id'];
                    }
                }
            }
        }
        foreach (self::ALTERNATIVES as $alts) {
            array_push($ids, ...$alts);
        }
        return array_values(array_unique([...$ids, ...array_keys(self::ALTERNATIVES)]));
    }

    /**
     * @param list<string> $restrictions chaves de RESTRICTIONS
     * @return array{structure:array,changes:list<string>,warnings:list<string>}
     */
    public function adapt(array $structure, array $restrictions, string $otherText = ''): array
    {
        $restrictions = array_values(array_intersect($restrictions, array_keys(self::RESTRICTIONS)));
        $changes = [];
        $warnings = [];
        foreach ($structure['meals'] as $mi => $meal) {
            foreach ($meal['options'] as $oi => $opt) {
                $items = [];
                foreach ($opt['items'] as $it) {
                    $hit = $this->hits((int) $it['food_id'], $restrictions);
                    if ($hit !== []) {
                        $it = $this->fix($it, $hit, $restrictions, (string) $meal['name'], $changes, $warnings);
                        if ($it === null) {
                            continue;
                        }
                    }
                    $it['subs'] = array_values(array_filter($it['subs'] ?? [], fn(array $s): bool =>
                        $this->hits((int) $s['food_id'], array_diff($restrictions, ['lactose'])) === []));
                    $items[] = $it;
                }
                $structure['meals'][$mi]['options'][$oi]['items'] = $items;
            }
        }
        foreach ($this->otherWarnings($structure, $otherText) as $w) {
            $warnings[] = $w;
        }
        return ['structure' => $structure, 'changes' => array_values(array_unique($changes)), 'warnings' => array_values(array_unique($warnings))];
    }

    /** @return list<string> restricoes que atingem o alimento */
    private function hits(int $foodId, array $restrictions): array
    {
        return array_values(array_filter($restrictions, fn(string $r): bool => in_array($foodId, $this->tags[$r] ?? [], true)));
    }

    private function fix(array $it, array $hit, array $restrictions, string $meal, array &$changes, array &$warnings): ?array
    {
        $id = (int) $it['food_id'];
        $name = $this->foods[$id]['name'] ?? ('alimento ' . $id);
        if ($hit === ['lactose']) {
            $it['note'] = trim('versão sem lactose' . ((string) ($it['note'] ?? '') === '' ? '' : '; ' . $it['note']));
            $changes[] = $meal . ': ' . $name . ' na versão sem lactose.';
            return $it;
        }
        $strict = $restrictions;
        foreach (self::ALTERNATIVES[$id] ?? [] as $alt) {
            if ($this->hits($alt, $strict) !== [] || !isset($this->foods[$alt]) || (float) ($this->foods[$alt]['kcal'] ?? 0) <= 0) {
                continue;
            }
            $kcal = (float) ($this->foods[$id]['kcal'] ?? 0) * $this->grams($it) / 100;
            [$qty, $mid] = $this->portion($alt, $kcal);
            $changes[] = $meal . ': ' . $name . ' trocado por ' . $this->foods[$alt]['name'] . ' (' . $this->label($hit) . ').';
            $lactoseOnly = in_array('lactose', $restrictions, true) && $this->hits($alt, ['lactose']) !== [];
            return ['food_id' => $alt, 'qty' => $qty, 'measure_id' => $mid, 'note' => $lactoseOnly ? 'versão sem lactose' : '', 'subs' => $it['subs'] ?? []];
        }
        $warnings[] = $meal . ': ' . $name . ' foi retirado (' . $this->label($hit) . '); complete a refeição se necessário.';
        return null;
    }

    private function grams(array $it): float
    {
        $mid = $it['measure_id'] ?? null;
        if ($mid === null) {
            return (float) $it['qty'];
        }
        foreach ($this->measures[(int) $it['food_id']] ?? [] as $m) {
            if ((int) $m['id'] === (int) $mid) {
                return (float) $it['qty'] * (float) $m['grams'];
            }
        }
        return (float) $it['qty'];
    }

    /** Porcao com as mesmas calorias: em unidades se o alimento tiver "unidade", senao em gramas de 5 em 5. */
    private function portion(int $food, float $kcal): array
    {
        $g = $kcal / (float) $this->foods[$food]['kcal'] * 100;
        foreach ($this->measures[$food] ?? [] as $m) {
            if ($m['name'] === 'unidade') {
                return [max(0.5, round($g / (float) $m['grams'] * 2) / 2), (int) $m['id']];
            }
        }
        return [max(5.0, round($g / 5) * 5), null];
    }

    private function label(array $hit): string
    {
        return implode(', ', array_map(static fn(string $r): string => mb_strtolower(self::RESTRICTIONS[$r], 'UTF-8'), $hit));
    }

    /** Palavras do texto livre (3+ letras, sem acento, singular simples) encontradas nos nomes dos alimentos. */
    private function otherWarnings(array $structure, string $text): array
    {
        $words = [];
        foreach (preg_split('/[\s,;\/.()]+|\be\b/u', FoodRepository::normalize($text)) ?: [] as $w) {
            $w = trim($w);
            if (mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, ['com', 'sem', 'para', 'alergia', 'intolerancia', 'evitar', 'nao', 'gosta'], true)) {
                $words[] = preg_replace('/(oes|aes)$/u', 'ao', preg_replace('/s$/u', '', $w));
            }
        }
        $out = [];
        foreach ($structure['meals'] as $meal) {
            foreach ($meal['options'] as $opt) {
                foreach ($opt['items'] as $it) {
                    foreach ([$it, ...($it['subs'] ?? [])] as $line) {
                        $name = $this->foods[(int) $line['food_id']]['name'] ?? '';
                        $norm = FoodRepository::normalize($name);
                        foreach ($words as $w) {
                            if ($w !== '' && str_contains($norm, $w)) {
                                $out[] = $meal['name'] . ': ' . $name . ' pode conflitar com "' . $w . '" (outros alimentos a evitar). Confira e troque se necessário.';
                            }
                        }
                    }
                }
                $note = FoodRepository::normalize((string) ($meal['note'] ?? ''));
                foreach ($words as $w) {
                    if ($w !== '' && str_contains($note, $w)) {
                        $out[] = $meal['name'] . ': a observação da refeição cita "' . $w . '". Confira.';
                    }
                }
            }
        }
        return $out;
    }
}
