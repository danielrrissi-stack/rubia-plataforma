<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static ?array $data = null;
    private static ?string $lockOverride = null;

    /** Arquivo-trava criado pelo instalador (marca que uma instalacao ocorreu). */
    public static function lockFile(): string
    {
        return self::$lockOverride ?? dirname(__DIR__, 2) . '/config/.install.lock';
    }

    public static function setLockFile(?string $path): void
    {
        self::$lockOverride = $path;
    }

    /** Lê uma chave com notação de ponto (ex.: "db.host"). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::load();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Descarta o cache (útil em testes). */
    public static function reset(): void
    {
        self::$data = null;
        self::$lockOverride = null;
    }

    /** Substitui a configuração em memória (testes; [] simula "não instalado"). */
    public static function set(array $data): void
    {
        self::$data = $data;
    }

    /** Há configuração carregada? Sem config/config.php o sistema ainda não foi instalado. */
    public static function isInstalled(): bool
    {
        return self::load() !== [];
    }

    private static function load(): array
    {
        if (self::$data === null) {
            $env = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '');
            $file = dirname(__DIR__, 2) . '/config/' . ($env === 'test' ? 'config.test.php' : 'config.php');
            self::$data = is_file($file) ? (require $file) : [];
        }
        return self::$data;
    }
}
