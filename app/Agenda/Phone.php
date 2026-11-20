<?php
declare(strict_types=1);

namespace App\Agenda;

/** Numero de WhatsApp (wa.me) a partir do telefone digitado. */
final class Phone
{
    /**
     * So digitos; 10-11 digitos (DDD + numero) ganham 55 na frente; 12-13 digitos comecando por 55 ficam como estao;
     * outro formato (ou vazio) = null (invalido).
     */
    public static function wa(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);
        $len = strlen($digits);
        if ($len === 10 || $len === 11) {
            return '55' . $digits;
        }
        if (($len === 12 || $len === 13) && str_starts_with($digits, '55')) {
            return $digits;
        }
        return null;
    }
}
