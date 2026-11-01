<?php
declare(strict_types=1);

namespace App\Install;

use App\Backup\BackupKeys;
use App\Core\Database;
use App\Core\Migrator;
use App\Users\UserRepository;

final class Installer
{
    private string $privateKeyPem = '';
    private string $storagePath = '';
    private string $storageWarning = '';

    public function __construct(
        private string $configPath,
        private string $appRoot,
        private ?string $storageOverride = null,
    ) {
    }

    public function privateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function storageWarning(): string
    {
        return $this->storageWarning;
    }

    /**
     * Escolhe a pasta de dados: fora da raiz pública se possível, senão APP_ROOT/storage.
     * @return array{path:string,warning:string}
     */
    public function chooseStorage(): array
    {
        if ($this->storageOverride !== null) {
            return ['path' => $this->storageOverride, 'warning' => ''];
        }
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $real = $docRoot !== '' ? realpath($docRoot) : false;
        $public = $real !== false ? $real : $this->appRoot . '/public';
        $candidate = dirname($public) . DIRECTORY_SEPARATOR . 'rubia_storage';
        $ok = is_dir($candidate) ? is_writable($candidate) : is_writable(dirname($candidate));
        if ($ok) {
            return ['path' => str_replace('\\', '/', $candidate), 'warning' => ''];
        }
        return [
            'path' => str_replace('\\', '/', $this->appRoot) . '/storage',
            'warning' => 'Não foi possível usar uma pasta fora de public_html. Os dados ficarão em storage/, protegida por .htaccess.',
        ];
    }

    /** @return array<string,string> campo => mensagem */
    public function validate(array $in): array
    {
        $e = [];
        $s = static fn (string $k): string => trim((string) ($in[$k] ?? ''));
        foreach (['db_host' => 'o servidor do banco', 'db_name' => 'o nome do banco', 'db_user' => 'o usuário do banco'] as $k => $label) {
            if ($s($k) === '') {
                $e[$k] = 'Informe ' . $label . '.';
            }
        }
        if (!preg_match('#^https?://[^\s/]+(/\S*)?$#i', $s('base_url'))) {
            $e['base_url'] = 'Informe o endereço do site, começando com http:// ou https://.';
        }
        if (!filter_var($s('mail_from'), FILTER_VALIDATE_EMAIL)) {
            $e['mail_from'] = 'Informe um e-mail remetente válido.';
        }
        if ($s('admin_name') === '') {
            $e['admin_name'] = 'Informe o nome da médica.';
        }
        if (!filter_var($s('admin_email'), FILTER_VALIDATE_EMAIL)) {
            $e['admin_email'] = 'Informe um e-mail válido.';
        }
        if ($s('admin_crm') === '') {
            $e['admin_crm'] = 'Informe o CRM.';
        }
        $pw = (string) ($in['admin_password'] ?? '');
        if (mb_strlen($pw) < 10) {
            $e['admin_password'] = 'A senha deve ter pelo menos 10 caracteres.';
        } elseif ($pw !== (string) ($in['admin_password2'] ?? '')) {
            $e['admin_password'] = 'A confirmação da senha não confere.';
        }
        $bp = (string) ($in['backup_pass'] ?? '');
        if (mb_strlen($bp) < 12) {
            $e['backup_pass'] = 'A senha de backup deve ter pelo menos 12 caracteres.';
        } elseif ($bp !== (string) ($in['backup_pass2'] ?? '')) {
            $e['backup_pass'] = 'A confirmação da senha de backup não confere.';
        }
        return $e;
    }

    public function run(array $input): void
    {
        if (is_file($this->configPath)) {
            throw new \RuntimeException('Sistema já instalado.');
        }
        $errors = $this->validate($input);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }
        $configDir = dirname($this->configPath);
        if (!is_dir($configDir) || !is_writable($configDir)) {
            throw new \RuntimeException('A pasta de configuração não é gravável: ' . $configDir);
        }

        $lockPath = $configDir . '/.install.lock';
        $lock = @fopen($lockPath, 'x');
        if ($lock === false) {
            throw new \RuntimeException('Instalação já realizada ou em andamento.');
        }
        fclose($lock);

        $cfgHandle = null;
        $committed = false;
        try {
            $dbCfg = [
                'host' => trim((string) $input['db_host']),
                'name' => trim((string) $input['db_name']),
                'user' => trim((string) $input['db_user']),
                'pass' => (string) ($input['db_pass'] ?? ''),
            ];
            $db = new Database($dbCfg);
            try {
                $db->pdo();
            } catch (\PDOException) {
                throw new \RuntimeException('Não foi possível conectar ao banco de dados. Confira servidor, nome, usuário e senha.');
            }

            $chosen = $this->chooseStorage();
            $this->storagePath = $chosen['path'];
            $this->storageWarning = $chosen['warning'];

            (new Migrator($db, $this->appRoot . '/database/migrations'))->migrate();
            if ((int) ($db->one('SELECT COUNT(*) AS n FROM users')['n'] ?? 0) > 0) {
                throw new \RuntimeException('O banco de dados já contém usuários; sistema já instalado.');
            }
            SeedLoader::loadDir($db, $this->appRoot . '/database/seeds');

            $this->prepareStorage();
            $keys = BackupKeys::generate((string) $input['backup_pass'], $this->storagePath . '/keys');
            $pem = file_get_contents($keys['private']);
            $this->privateKeyPem = $pem === false ? '' : $pem;

            $cfgHandle = @fopen($this->configPath, 'x');
            if ($cfgHandle === false) {
                $cfgHandle = null;
                throw new \RuntimeException('Não foi possível criar o arquivo de configuração (ou ele já existe).');
            }

            $term = (string) file_get_contents(__DIR__ . '/default_consent_v1.html');
            $db->transaction(function (Database $db) use ($input, $term): void {
                (new UserRepository($db))->create([
                    'name' => trim((string) $input['admin_name']),
                    'email' => (string) $input['admin_email'],
                    'password' => (string) $input['admin_password'],
                    'role' => 'medico',
                    'is_admin' => 1,
                    'professional_register' => trim((string) $input['admin_crm']),
                    'totp_enabled' => 0,
                    'active' => 1,
                ]);
                $db->insert('consent_terms', ['version' => '1.0', 'body_html' => $term, 'active' => 1]);
                $db->run(
                    'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    ['installed_at', date('Y-m-d H:i:s')]
                );
            });
            $committed = true;

            $this->writeConfig($cfgHandle, $dbCfg, $input);
            $cfgHandle = null;
        } catch (\Throwable $e) {
            if (is_resource($cfgHandle)) {
                fclose($cfgHandle);
            }
            if ($cfgHandle !== null || $committed) {
                @unlink($this->configPath);
            }
            if (!$committed) {
                @unlink($lockPath);
                if ($e instanceof \RuntimeException) {
                    throw $e;
                }
                $this->logError($e);
                throw new \RuntimeException('Falha inesperada na instalação. Verifique os dados informados e tente novamente.');
            }
            throw new \RuntimeException(
                'Os dados foram gravados, mas não foi possível gravar a configuração (' . $e->getMessage() . '). '
                . 'Para tentar de novo, apague as tabelas do banco de dados e o arquivo ' . $lockPath . '.',
                0,
                $e
            );
        }
    }

    private function logError(\Throwable $e): void
    {
        $dir = ($this->storagePath !== '' ? $this->storagePath : $this->appRoot . '/storage') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents(
            $dir . '/app.log',
            sprintf("[%s] install %s: %s in %s:%d
", date('Y-m-d H:i:s'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()),
            FILE_APPEND | LOCK_EX
        );
    }

    private function prepareStorage(): void
    {
        foreach (['', '/uploads', '/backups', '/keys', '/logs'] as $sub) {
            $d = $this->storagePath . $sub;
            if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) {
                throw new \RuntimeException('Não foi possível criar a pasta ' . $d);
            }
        }
        $ht = $this->storagePath . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
    }

    /** @param resource $fh handle aberto com 'x' */
    private function writeConfig($fh, array $dbCfg, array $in): void
    {
        $baseUrl = rtrim(trim((string) $in['base_url']), '/');
        $config = [
            'db' => $dbCfg,
            'base_url' => $baseUrl,
            'storage_path' => $this->storagePath,
            'force_https' => stripos($baseUrl, 'https://') === 0,
            'mail' => ['from' => trim((string) $in['mail_from']), 'from_name' => 'Dra. Rubia'],
            'session' => ['staff_idle_minutes' => 30, 'family_idle_minutes' => 120],
        ];
        $php = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $ok = fwrite($fh, $php) === strlen($php);
        fclose($fh);
        if (!$ok) {
            throw new \RuntimeException('Não foi possível gravar o arquivo de configuração.');
        }
        @chmod($this->configPath, 0640);
    }
}
