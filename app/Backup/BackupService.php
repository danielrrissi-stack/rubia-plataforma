<?php
declare(strict_types=1);

namespace App\Backup;

use App\Core\Database;

/**
 * Backup criptografado: zip (database.sql + storage/uploads) selado com openssl_seal.
 *
 * Formato .rbk: linha JSON {"v":1,"ek":base64,"iv":base64,"created":ISO} + "\n" + dados cifrados.
 *
 * Memoria: openssl_seal/openssl_open sao one-shot, entao o zip inteiro fica em memoria durante a
 * cifragem. Aceitavel para o porte desta clinica (dump + anexos de poucas dezenas de MB).
 */
final class BackupService
{
    private const CIPHER = 'aes-256-cbc';
    private const MAX_AGE_SECONDS = 86400;
    private const KEEP_SECONDS = 30 * 86400;

    /** @var callable():int */
    private $clock;

    /** @param array{storage_path:string} $cfg  @param (callable():int)|null $clock devolve timestamp Unix */
    public function __construct(private Database $db, private Dumper $dumper, private array $cfg, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function storage(): string
    {
        return rtrim((string) $this->cfg['storage_path'], '/\\');
    }

    public function backupDir(): string
    {
        return $this->storage() . '/backups';
    }

    public function create(): string
    {
        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar a pasta de backups.');
        }
        $pubPem = @file_get_contents($this->storage() . '/keys/backup_public.pem');
        $pubKey = $pubPem === false ? false : openssl_pkey_get_public($pubPem);
        if ($pubKey === false) {
            throw new \RuntimeException('Chave pública de backup ausente ou inválida.');
        }

        $stamp = date('Ymd-His', $this->now());
        $final = $dir . '/backup-' . $stamp . '.rbk';
        $suffix = bin2hex(random_bytes(4));
        $dumpFile = $dir . '/dump-' . $suffix . '.tmp';
        $zipFile = $dir . '/zip-' . $suffix . '.tmp';
        $tmpOut = $dir . '/out-' . $suffix . '.tmp';
        try {
            $this->dumper->dump($dumpFile);
            $this->buildZip($zipFile, $dumpFile);
            $zipBytes = (string) file_get_contents($zipFile);

            $iv = '';
            $sealed = '';
            $ekeys = [];
            if (openssl_seal($zipBytes, $sealed, $ekeys, [$pubKey], self::CIPHER, $iv) === false) {
                throw new \RuntimeException('Falha ao criptografar o backup.');
            }
            unset($zipBytes);
            $header = json_encode([
                'v' => 1,
                'ek' => base64_encode($ekeys[0]),
                'iv' => base64_encode($iv),
                'created' => date('c', $this->now()),
            ], JSON_THROW_ON_ERROR);
            if (file_put_contents($tmpOut, $header . "\n" . $sealed) === false) {
                throw new \RuntimeException('Não foi possível gravar o backup.');
            }
            if (!rename($tmpOut, $final)) {
                throw new \RuntimeException('Não foi possível finalizar o backup.');
            }
            @chmod($final, 0640);
        } finally {
            foreach ([$dumpFile, $zipFile, $tmpOut] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }

        $this->db->run(
            'INSERT INTO settings (`key`, value) VALUES (\'last_backup_at\', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [date('Y-m-d H:i:s', $this->now())]
        );
        return $final;
    }

    /** Cria backup se nunca houve ou se o ultimo tem mais de 24 h; depois apaga .rbk com mais de 30 dias. */
    public function runIfDue(): ?string
    {
        $row = $this->db->one("SELECT value FROM settings WHERE `key` = 'last_backup_at'");
        $last = $row === null ? false : strtotime((string) $row['value']);
        $created = null;
        if ($last === false || $this->now() - $last > self::MAX_AGE_SECONDS) {
            $created = $this->create();
        }
        $this->prune();
        return $created;
    }

    private function prune(): void
    {
        foreach (glob($this->backupDir() . '/*.rbk') ?: [] as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false && $this->now() - $mtime > self::KEEP_SECONDS) {
                @unlink($f);
            }
        }
    }

    public function restoreToZip(string $rbk, string $privatePem, string $passphrase, string $outZip): void
    {
        $raw = @file_get_contents($rbk);
        if ($raw === false) {
            throw new \RuntimeException('Não foi possível ler o arquivo de backup.');
        }
        $nl = strpos($raw, "\n");
        $header = $nl === false ? null : json_decode(substr($raw, 0, $nl), true);
        $ek = is_array($header) ? base64_decode((string) ($header['ek'] ?? ''), true) : false;
        $iv = is_array($header) ? base64_decode((string) ($header['iv'] ?? ''), true) : false;
        if (!is_array($header) || ($header['v'] ?? null) !== 1 || $ek === false || $iv === false || $ek === '') {
            throw new \RuntimeException('Arquivo de backup inválido ou corrompido');
        }
        $key = @openssl_pkey_get_private($privatePem, $passphrase);
        if ($key === false) {
            throw new \RuntimeException('Senha de backup incorreta');
        }
        $plain = '';
        if (!@openssl_open(substr($raw, $nl + 1), $plain, $ek, $key, self::CIPHER, $iv)) {
            throw new \RuntimeException('Arquivo de backup inválido ou corrompido');
        }
        if (file_put_contents($outZip, $plain) === false) {
            throw new \RuntimeException('Não foi possível gravar o zip de saída.');
        }
    }

    private function buildZip(string $zipFile, string $dumpFile): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o zip do backup.');
        }
        $zip->addFile($dumpFile, 'database.sql');
        $uploads = $this->storage() . '/uploads';
        if (is_dir($uploads)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1));
                    $zip->addFile($file->getPathname(), 'storage/uploads/' . $rel);
                }
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Não foi possível finalizar o zip do backup.');
        }
    }
}
