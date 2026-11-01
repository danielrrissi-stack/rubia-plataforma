<?php
declare(strict_types=1);

namespace App\Backup;

final class BackupKeys
{
    /**
     * Gera par RSA 4096: publica em claro, privada protegida pela senha.
     * @return array{public:string,private:string}
     */
    public static function generate(string $passphrase, string $dir): array
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar a pasta de chaves: ' . $dir);
        }
        $opts = ['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = @openssl_pkey_new($opts);
        if ($key === false) {
            $cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
            if (is_file($cnf)) {
                $opts['config'] = $cnf;
                $key = @openssl_pkey_new($opts);
            }
        }
        if ($key === false) {
            throw new \RuntimeException(
                'Não foi possível gerar as chaves de backup (OpenSSL do PHP sem configuração). '
                . 'Peça à hospedagem para habilitar a extensão OpenSSL.'
            );
        }
        $exportOpts = ['encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC];
        if (isset($opts['config'])) {
            $exportOpts['config'] = $opts['config'];
        }
        $private = '';
        if (!openssl_pkey_export($key, $private, $passphrase, $exportOpts)) {
            throw new \RuntimeException('Não foi possível exportar a chave privada de backup.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || empty($details['key'])) {
            throw new \RuntimeException('Não foi possível obter a chave pública de backup.');
        }
        $base = rtrim($dir, '/\\');
        $pubPath = $base . '/backup_public.pem';
        $privPath = $base . '/backup_private.pem';
        if (file_put_contents($pubPath, $details['key']) === false || file_put_contents($privPath, $private) === false) {
            throw new \RuntimeException('Não foi possível gravar as chaves em ' . $dir);
        }
        @chmod($privPath, 0600);
        @chmod($pubPath, 0640);
        return ['public' => $pubPath, 'private' => $privPath];
    }
}
