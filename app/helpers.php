<?php
declare(strict_types=1);

/** Escapa texto para saída HTML. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL relativa à raiz do app (respeita subpasta de base_url). */
function url(string $path = '/'): string
{
    $base = (string) parse_url((string) \App\Core\Config::get('base_url', ''), PHP_URL_PATH);
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(\App\Core\Csrf::token()) . '">';
}

/** "2019-03-05" => "05/03/2019". */
function fmt_date(?string $ymd): string
{
    if ($ymd === null || $ymd === '') {
        return '';
    }
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    return $d ? $d->format('d/m/Y') : '';
}

/** Número com vírgula decimal; null => "-". */
function fmt_num(?float $n, int $dec = 1): string
{
    return $n === null ? '-' : number_format($n, $dec, ',', '');
}
