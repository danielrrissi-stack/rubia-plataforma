<?php
declare(strict_types=1);

namespace App\Backup;

use App\Access\Policy;
use App\Core\Config;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Pagina de backups do administrador: listar, gerar, baixar (.rbk e chave privada cifrada). */
final class BackupController
{
    private const NAME_RE = '/^backup-\d{8}-\d{6}\.rbk$/';

    public function index(Request $r): Response
    {
        $this->authorize($r, 'view');
        $files = [];
        foreach (glob(Container::backups()->backupDir() . '/*.rbk') ?: [] as $f) {
            $name = basename($f);
            if (preg_match(self::NAME_RE, $name) === 1) {
                $files[] = [
                    'name' => $name,
                    'mtime' => (int) filemtime($f),
                    'mb' => number_format(filesize($f) / 1048576, 2, ',', '.'),
                ];
            }
        }
        usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $last = Container::db()->one("SELECT value FROM settings WHERE `key` = 'last_backup_at'");
        $user = $r->attributes['user'];
        return Response::html(View::render('Backup/views/index.php', [
            'title' => 'Backups',
            'userName' => $user['name'],
            'active' => '/admin/backups',
            'files' => $files,
            'lastBackupAt' => $last['value'] ?? null,
        ]));
    }

    public function generate(Request $r): Response
    {
        $actor = $this->authorize($r, 'edit');
        try {
            $path = Container::backups()->create();
            Container::audit()->record((int) $actor['id'], 'create', 'backup', null, null, null, ['arquivo' => basename($path)], $r);
            Session::flash('ok', 'Backup gerado: ' . basename($path));
        } catch (\Throwable $e) {
            Session::flash('erro', 'Não foi possível gerar o backup: ' . $e->getMessage());
        }
        return Response::redirect('/admin/backups');
    }

    public function download(Request $r): Response
    {
        $actor = $this->authorize($r, 'view');
        $name = (string) ($r->params['nome'] ?? '');
        if (preg_match(self::NAME_RE, $name) !== 1) {
            throw new HttpException(404);
        }
        $path = Container::backups()->backupDir() . '/' . $name;
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        $this->auditExport($actor, $name, $r);
        return Response::file($path, 'application/octet-stream', $name);
    }

    public function privateKey(Request $r): Response
    {
        $actor = $this->authorize($r, 'view');
        $path = rtrim((string) Config::get('storage_path'), '/\\') . '/keys/backup_private.pem';
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        $this->auditExport($actor, 'backup_private.pem', $r);
        return Response::file($path, 'application/x-pem-file', 'backup_private.pem');
    }

    private function auditExport(array $actor, string $name, Request $r): void
    {
        Container::audit()->record((int) $actor['id'], 'export', 'backup', null, null, null, ['arquivo' => $name], $r);
    }

    private function authorize(Request $r, string $action): array
    {
        $user = $r->attributes['user'];
        $section = $action === 'edit' ? Policy::USUARIOS : Policy::AUDITORIA;
        Container::gate()->authorize($user, $action, $section, null, $r);
        return $user;
    }
}
