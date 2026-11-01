<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessao com cookie HttpOnly/SameSite=Lax (Secure em HTTPS).
 * Em CLI/testes usa um array em memoria compartilhado entre requisicoes.
 */
final class Session
{
    private static array $mem = [];
    private static bool $started = false;

    private static function inMemory(): bool
    {
        return PHP_SAPI === 'cli';
    }

    public static function start(array $cfg = []): void
    {
        if (self::$started || self::inMemory()) {
            self::$started = true;
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('rubia_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // O GC do PHP nao pode apagar a sessao antes do maior tempo de inatividade (familia: 120 min).
        ini_set('session.gc_maxlifetime', '7200');
        session_start();
        self::$started = true;
    }

    /** Limpa o estado em memoria (testes). */
    public static function reset(): void
    {
        self::$mem = [];
        if (!self::inMemory() && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::store()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::inMemory()) {
            self::$mem[$key] = $value;
        } else {
            $_SESSION[$key] = $value;
        }
    }

    public static function forget(string $key): void
    {
        if (self::inMemory()) {
            unset(self::$mem[$key]);
        } else {
            unset($_SESSION[$key]);
        }
    }

    /** Com $value: grava mensagem para a proxima requisicao. Sem $value: le e remove. */
    public static function flash(string $key, mixed $value = null): mixed
    {
        $all = (array) self::get('_flash', []);
        if (func_num_args() > 1) {
            $all[$key] = $value;
            self::set('_flash', $all);
            return null;
        }
        $v = $all[$key] ?? null;
        unset($all[$key]);
        self::set('_flash', $all);
        return $v;
    }

    public static function regenerate(): void
    {
        if (!self::inMemory() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function store(): array
    {
        return self::inMemory() ? self::$mem : ($_SESSION ?? []);
    }
}
