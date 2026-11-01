<?php
declare(strict_types=1);

namespace App\Auth;

/** Base32 (RFC 4648), alfabeto A-Z2-7, sem padding na saida. */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bin): string
    {
        if ($bin === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($bin) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=-]+/', '', $b32) ?? '');
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $pos = strpos(self::ALPHABET, $b32[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException('Base32 invalido');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
