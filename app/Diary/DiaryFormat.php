<?php
declare(strict_types=1);

namespace App\Diary;

/** Textos curtos do diario (funcoes puras). Nada aqui avalia a alimentacao. */
final class DiaryFormat
{
    public const QUALITY_EMOJI = [1 => '😟', 2 => '😐', 3 => '😊'];

    /** 45 -> "45 min"; 60 -> "1h"; 80 -> "1h20". */
    public static function minutes(int $m): string
    {
        if ($m < 60) {
            return $m . ' min';
        }
        $rest = $m % 60;
        return intdiv($m, 60) . 'h' . ($rest === 0 ? '' : sprintf('%02d', $rest));
    }

    /** "23:00–06:30" (aceita HH:MM ou HH:MM:SS). */
    public static function sleepRange(string $slept, string $woke): string
    {
        return substr($slept, 0, 5) . '–' . substr($woke, 0, 5);
    }

    /**
     * Resumo de um dia para um tipo; null quando nao ha o que mostrar.
     *
     * @param list<array> $entries
     */
    public static function cell(string $type, array $entries, bool $measured): ?string
    {
        if ($type === 'medidas') {
            return $measured ? 'pesou' : null;
        }
        if ($entries === []) {
            return null;
        }
        $first = $entries[0];
        switch ($type) {
            case 'agua':
                $n = (int) $first['glasses'];
                return $n . ($n === 1 ? ' copo' : ' copos');
            case 'sono':
                $text = self::sleepRange((string) $first['slept_at'], (string) $first['woke_at']);
                $q = (int) ($first['sleep_quality'] ?? 0);
                return isset(self::QUALITY_EMOJI[$q]) ? $text . ' ' . self::QUALITY_EMOJI[$q] : $text;
            case 'atividade':
                $sum = 0;
                foreach ($entries as $e) {
                    $sum += (int) ($e['minutes'] ?? 0);
                }
                if ($sum > 0) {
                    return self::minutes($sum) . ' de atividade';
                }
                return count($entries) . (count($entries) === 1 ? ' atividade' : ' atividades');
            case 'tela':
                return self::minutes((int) $first['minutes']) . ' de tela';
            case 'alimentacao':
                $c = count($entries);
                return $c . ($c === 1 ? ' refeição' : ' refeições');
        }
        return null;
    }
}
