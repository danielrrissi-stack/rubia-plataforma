<?php
declare(strict_types=1);

// Uso: php tools/restore.php <arquivo.rbk> <backup_private.pem> <saida.zip>
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

require __DIR__ . '/../app/bootstrap.php';

use App\Backup\BackupService;
use App\Backup\Dumper;
use App\Core\Database;

if ($argc !== 4) {
    fwrite(STDERR, "Uso: php tools/restore.php <arquivo.rbk> <backup_private.pem> <saida.zip>\n");
    exit(2);
}
[, $rbk, $pemFile, $outZip] = $argv;
$pem = @file_get_contents($pemFile);
if ($pem === false) {
    fwrite(STDERR, "Não foi possível ler a chave privada: $pemFile\n");
    exit(2);
}

echo 'Senha de backup: ';
$windows = DIRECTORY_SEPARATOR === '\\';
if ($windows) {
    echo "\n[aviso] No Windows a senha será exibida enquanto você digita.\nSenha de backup: ";
    $pass = (string) fgets(STDIN);
} else {
    system('stty -echo');
    $pass = (string) fgets(STDIN);
    system('stty echo');
    echo "\n";
}
$pass = rtrim($pass, "\r\n");

try {
    // Restauracao nao usa banco nem armazenamento; Database nao conecta ate o primeiro uso.
    $db = new Database([]);
    (new BackupService($db, new Dumper($db), ['storage_path' => '']))->restoreToZip($rbk, $pem, $pass, $outZip);
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Backup descriptografado em: $outZip\n";
echo "Próximos passos:\n";
echo "  1. Extraia o zip.\n";
echo "  2. Importe database.sql pelo phpMyAdmin (aba Importar) no banco da aplicação.\n";
echo "  3. Copie a pasta storage/uploads/ para o storage da aplicação.\n";
