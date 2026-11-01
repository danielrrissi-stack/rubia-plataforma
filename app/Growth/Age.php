<?php
declare(strict_types=1);

namespace App\Growth;

/** Calculos de idade a partir de datas Y-m-d (sem fuso: apenas calendario). */
final class Age
{
    public const DAYS_PER_MONTH = 30.4375;

    public static function days(string $birthYmd, string $onYmd): int
    {
        $b = self::d($birthYmd);
        $o = self::d($onYmd);
        return (int) $b->diff($o)->format('%r%a');
    }

    public static function months(string $birth, string $on): float
    {
        return self::days($birth, $on) / self::DAYS_PER_MONTH;
    }

    /** Texto curto: "8 meses" (< 2 anos) ou "6 anos". */
    public static function label(string $birth, string $on): string
    {
        $years = self::years($birth, $on);
        if ($years >= 2) {
            return $years . ' anos';
        }
        $b = self::d($birth);
        $m = (int) $b->diff(self::d($on))->format('%m') + 12 * $years;
        return $m . ($m === 1 ? ' mês' : ' meses');
    }

    /** Anos completos pelo calendario (29/02 completa ano em 01/03 nos anos nao bissextos). */
    public static function years(string $birth, string $on): int
    {
        $b = self::d($birth);
        $o = self::d($on);
        return (int) $b->diff($o)->format('%r%y');
    }

    /** Data Y-m-d em UTC (evita meia-noite inexistente em inicio de horario de verao). */
    private static function d(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable(substr($ymd, 0, 10), new \DateTimeZone('UTC'));
    }
}
