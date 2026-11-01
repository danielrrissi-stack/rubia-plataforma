<?php
declare(strict_types=1);

namespace App\Install;

final class Requirements
{
    /**
     * @return list<array{item:string,ok:bool,required:bool,detail:string}>
     */
    public static function check(?string $storageDir = null, ?array $server = null): array
    {
        $server ??= $_SERVER;
        $items = [];
        $add = static function (string $item, bool $ok, bool $required, string $detail = '') use (&$items): void {
            $items[] = ['item' => $item, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
        };

        $add('PHP 8.1 ou superior', version_compare(PHP_VERSION, '8.1.0', '>='), true, 'Versão atual: ' . PHP_VERSION);
        foreach (['pdo_mysql', 'openssl', 'zip', 'mbstring', 'json'] as $ext) {
            $loaded = extension_loaded($ext);
            $add('Extensão ' . $ext, $loaded, true, $loaded ? 'Disponível' : 'Ausente: peça à hospedagem para habilitar');
        }

        $root = dirname(__DIR__, 2);
        $storageDir ??= (new Installer('', $root))->chooseStorage()['path'];
        $add('Pasta de dados gravável', self::writableOrCreatable($storageDir), true, $storageDir);
        $configDir = $root . '/config';
        $add('Pasta config gravável', is_dir($configDir) && is_writable($configDir), true, $configDir);

        $norm = str_replace('\\', '/', $storageDir);
        $outside = !str_starts_with($norm, str_replace('\\', '/', $root) . '/') && !str_contains($norm, '/public_html/');
        $add('Pasta de dados fora da raiz pública', $outside, false, $outside ? 'Sim' : 'Não: será protegida por .htaccess');

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $execOk = function_exists('exec') && !in_array('exec', $disabled, true);
        $dump = false;
        if ($execOk) {
            $out = [];
            $code = 1;
            @exec('mysqldump --version', $out, $code);
            $dump = $code === 0;
        }
        $add('exec e mysqldump disponíveis', $execOk && $dump, false, $dump ? 'Disponíveis' : 'Backup usará exportação interna');
        $add('upload_max_filesize', true, false, (string) ini_get('upload_max_filesize'));

        $https = (($server['HTTPS'] ?? '') !== '' && ($server['HTTPS'] ?? '') !== 'off')
            || ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $add('HTTPS ativo', $https, false, $https ? 'Sim' : 'Não: ative o certificado SSL antes de usar com pacientes');

        return $items;
    }

    /** Verdadeiro se nenhum item obrigatório falhou. */
    public static function passes(array $items): bool
    {
        foreach ($items as $i) {
            if ($i['required'] && !$i['ok']) {
                return false;
            }
        }
        return true;
    }

    private static function writableOrCreatable(string $dir): bool
    {
        while ($dir !== '' && !is_dir($dir)) {
            if (file_exists($dir)) {
                return false;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return false;
            }
            $dir = $parent;
        }
        return $dir !== '' && is_writable($dir);
    }
}
