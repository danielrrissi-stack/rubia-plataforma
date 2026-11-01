<?php
declare(strict_types=1);

namespace App\Install;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class InstallController
{
    /** Fabrica alternativa do Installer (testes). @var ?callable */
    public static $installerFactory = null;

    private const TEXT_FIELDS = [
        'db_host', 'db_name', 'db_user', 'base_url', 'mail_from', 'admin_name', 'admin_email', 'admin_crm',
    ];

    public function show(Request $r): Response
    {
        $this->guard();
        $host = (string) ($r->server['HTTP_HOST'] ?? 'localhost');
        $values = [
            'db_host' => 'localhost', 'db_name' => '', 'db_user' => '',
            'base_url' => ($r->isHttps() ? 'https://' : 'http://') . $host,
            'mail_from' => '', 'admin_name' => '', 'admin_email' => '', 'admin_crm' => '',
        ];
        return $this->form($r, $values, []);
    }

    public function install(Request $r): Response
    {
        $this->guard();
        $values = [];
        foreach (self::TEXT_FIELDS as $k) {
            $values[$k] = trim((string) ($r->post[$k] ?? ''));
        }
        $installer = $this->installer();
        $errors = $installer->validate($r->post);
        $global = '';
        if ($errors === [] && !Requirements::passes(Requirements::check($installer->chooseStorage()['path'], $r->server))) {
            $global = 'Corrija os requisitos obrigatórios listados abaixo antes de instalar.';
        }
        if ($errors === [] && $global === '') {
            try {
                $installer->run($r->post);
                $res = Response::html(View::render('Install/views/done.php', [
                    'title' => 'Instalação concluída',
                    'pem' => $installer->privateKeyPem(),
                    'warning' => $installer->storageWarning(),
                    'loginUrl' => rtrim($values['base_url'], '/') . '/login',
                ], 'Layout/views/layout.php'));
                $res->headers['Cache-Control'] = 'no-store';
                $res->headers['Pragma'] = 'no-cache';
                return $res;
            } catch (\RuntimeException $e) {
                $global = $e->getMessage();
            }
        }
        return $this->form($r, $values, $errors, $global);
    }

    private function form(Request $r, array $values, array $errors, string $global = ''): Response
    {
        $installer = $this->installer();
        $chosen = $installer->chooseStorage();
        return Response::html(View::render('Install/views/form.php', [
            'title' => 'Instalação',
            'nav' => [],
            'values' => $values,
            'errors' => $errors,
            'global' => $global,
            'requirements' => Requirements::check($chosen['path'], $r->server),
            'storageWarning' => $chosen['warning'],
        ]), $global !== '' || $errors !== [] ? 422 : 200);
    }

    private function installer(): Installer
    {
        if (self::$installerFactory !== null) {
            return (self::$installerFactory)();
        }
        return new Installer(APP_ROOT . '/config/config.php', APP_ROOT);
    }

    private function guard(): void
    {
        if (Config::isInstalled()) {
            throw new HttpException(404);
        }
    }
}
