<?php
declare(strict_types=1);

namespace App\Auth;

/** TOTP (RFC 6238), HMAC-SHA1, passo de 30 s. */
final class Totp
{
    private const STEP = 30;
    private const ISSUER = 'Dra Rubia';

    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    public static function code(string $secretB32, int $timestamp, int $digits = 6): string
    {
        $counter = intdiv($timestamp, self::STEP);
        $hash = hash_hmac('sha1', pack('J', $counter), Base32::decode($secretB32), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secretB32, string $code, int $timestamp, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $ok = false;
        for ($w = -$window; $w <= $window; $w++) {
            // Avalia todas as janelas (sem curto-circuito); comparacao em tempo constante.
            if (hash_equals(self::code($secretB32, $timestamp + $w * self::STEP), $code)) {
                $ok = true;
            }
        }
        return $ok;
    }

    public static function uri(string $secret, string $email): string
    {
        $issuer = rawurlencode(self::ISSUER);
        return 'otpauth://totp/' . $issuer . ':' . rawurlencode($email)
            . '?secret=' . $secret . '&issuer=' . $issuer;
    }
}
