<?php
declare(strict_types=1);

namespace App\Diary;

/** Constantes do diario de habitos e funcoes puras. */
final class DiaryType
{
    public const TYPES = ['alimentacao', 'agua', 'sono', 'atividade', 'tela', 'medidas'];

    public const LABELS = [
        'alimentacao' => 'Alimentação',
        'agua' => 'Água',
        'sono' => 'Sono',
        'atividade' => 'Atividade ou brincadeira',
        'tela' => 'Tempo de tela',
        'medidas' => 'Peso e medidas',
    ];

    /** Tipos que a nutricionista pode pedir e ver. */
    public const NUTRITIONAL = ['alimentacao', 'agua'];

    /** Tipos com um unico registro por dia. */
    public const ONE_PER_DAY = ['agua', 'sono', 'tela'];

    public const MEALS = ['cafe_manha', 'lanche_manha', 'almoco', 'lanche_tarde', 'jantar', 'ceia', 'outro'];

    public const MEAL_LABELS = [
        'cafe_manha' => 'Café da manhã',
        'lanche_manha' => 'Lanche da manhã',
        'almoco' => 'Almoço',
        'lanche_tarde' => 'Lanche da tarde',
        'jantar' => 'Jantar',
        'ceia' => 'Ceia',
        'outro' => 'Outro',
    ];

    /** Bit N-1 = date('N'): segunda = 1 ... domingo = 7. */
    public const WEEKDAY_LABELS = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];

    public const ALL_DAYS = 127;

    public static function weekdaysLabel(int $mask): string
    {
        if ($mask === self::ALL_DAYS) {
            return 'todo dia';
        }
        $names = [];
        foreach (self::WEEKDAY_LABELS as $n => $label) {
            if (($mask & (1 << ($n - 1))) !== 0) {
                $names[] = $label;
            }
        }
        return implode(', ', $names);
    }
}
