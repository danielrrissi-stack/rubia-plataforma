<?php
declare(strict_types=1);

namespace App\Growth;

/** Calculos de idade a partir de datas Y-m-d (sem fuso: apenas calendario). */
final class Age
{
    public const DAYS_PER_MONTH = 30.4375;

    public static function days(string $birthYmd, string $onYmd): int
    {
        $b = new \DateTimeImmutable(substr($birthYmd, 0, 10));
        $o = new \DateTimeImmutable(substr($onYmd, 0, 10));
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
        $b = new \DateTimeImmutable(substr($birth, 0, 10));
        $m = (int) $b->diff(new \DateTimeImmutable(substr($on, 0, 10)))->format('%m') + 12 * $years;
        return $m . ($m === 1 ? ' mês' : ' meses');
    }

    /** Anos completos pelo calendario (29/02 completa ano em 01/03 nos anos nao bissextos). */
    public static function years(string $birth, string $on): int
    {
        $b = new \DateTimeImmutable(substr($birth, 0, 10));
        $o = new \DateTimeImmutable(substr($on, 0, 10));
        return (int) $b->diff($o)->format('%r%y');
    }
}
