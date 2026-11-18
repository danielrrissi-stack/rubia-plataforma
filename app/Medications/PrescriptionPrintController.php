<?php
declare(strict_types=1);

namespace App\Medications;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Labs\Clinician;

/** Receita simples para imprimir (so medico) e cabecalho configuravel (so administrador). */
final class PrescriptionPrintController
{
    private const SETTINGS = ['rx_clinic_name' => 120, 'rx_address' => 200, 'rx_phone' => 40, 'rx_city' => 80];
    private const LABELS = ['rx_clinic_name' => 'Nome do consultório', 'rx_address' => 'Endereço', 'rx_phone' => 'Telefone', 'rx_city' => 'Cidade'];
    private const MONTHS = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    /** GET /pacientes/{id}/receita */
    public function choose(Request $r): Response
    {
        $id = $this->authPatient($r);
        $user = $r->attributes['user'];
        $patient = $this->patient($id);
        $items = $this->open($id, $user, date('Y-m-d'));
        return Response::html(View::render('Medications/views/rx_choose.php', [
            'title' => 'Receita', 'userName' => $user['name'], 'active' => '/pacientes',
            'patient' => $patient, 'items' => $items,
        ], 'Layout/views/layout.php'));
    }

    /** GET /pacientes/{id}/receita/imprimir?p[]=.. */
    public function print(Request $r): Response
    {
        $id = $this->authPatient($r);
        $user = $r->attributes['user'];
        $patient = $this->patient($id);
        $today = date('Y-m-d');
        $asked = [];
        foreach ((array) ($r->query['p'] ?? []) as $v) {
            if (is_string($v) && ctype_digit($v)) {
                $asked[(int) $v] = true;
            }
        }
        $groups = [];
        foreach ($this->open($id, $user, $today) as $p) {
            if (isset($asked[(int) $p['id']])) {
                $groups[$p['route']][] = $p;
            }
        }
        if ($groups === []) {
            return Response::redirect('/pacientes/' . $id . '/receita');
        }
        $ordered = [];
        $ids = [];
        foreach (Via::ROUTES as $route) {
            if (isset($groups[$route])) {
                $ordered[$route] = $groups[$route];
                foreach ($groups[$route] as $p) {
                    $ids[] = (int) $p['id'];
                }
            }
        }
        Container::audit()->record((int) $user['id'], 'view', 'prescriptions', null, $id, null,
            ['section' => 'medicamentos', 'print' => true, 'ids' => $ids], $r);
        $doc = Container::db()->one('SELECT professional_register FROM users WHERE id = ?', [(int) $user['id']]);
        return Response::html(View::render('Medications/views/rx_print.php', [
            'patient' => $patient, 'groups' => $ordered, 'head' => $this->settings(),
            'doctorName' => (string) $user['name'], 'register' => trim((string) ($doc['professional_register'] ?? '')),
            'dateText' => self::longDate($today),
        ], null));
    }

    /** GET /admin/receita */
    public function settingsForm(Request $r): Response
    {
        $user = $this->authAdmin($r);
        return $this->settingsPage($user, $this->settings(), []);
    }

    /** POST /admin/receita */
    public function saveSettings(Request $r): Response
    {
        $user = $this->authAdmin($r);
        $before = $this->settings();
        $values = [];
        $errors = [];
        foreach (self::SETTINGS as $key => $max) {
            $v = trim((string) ($r->post[$key] ?? ''));
            if (mb_strlen($v) > $max) {
                $errors[] = self::LABELS[$key] . ': no máximo ' . $max . ' caracteres.';
            }
            $values[$key] = $v;
        }
        if ($errors !== []) {
            return $this->settingsPage($user, $values, $errors, 422);
        }
        foreach ($values as $key => $v) {
            Container::db()->run('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $v]);
        }
        Container::audit()->record((int) $user['id'], 'update', 'settings', null, null, $before, $values, $r);
        Session::flash('ok', 'Cabeçalho da receita salvo.');
        return Response::redirect('/admin/receita');
    }

    /** "17 de novembro de 2026" sem depender de intl. */
    public static function longDate(string $ymd): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        return $d . ' de ' . self::MONTHS[$m] . ' de ' . $y;
    }

    /** @return array<string,string> as 4 chaves, vazias quando nao gravadas */
    private function settings(): array
    {
        $out = array_fill_keys(array_keys(self::SETTINGS), '');
        foreach (Container::db()->all('SELECT `key`, value FROM settings') as $row) {
            if (array_key_exists($row['key'], $out)) {
                $out[$row['key']] = (string) $row['value'];
            }
        }
        return $out;
    }

    /** Prescricoes ativas e futuras (nao excluidas, nao encerradas). */
    private function open(int $patientId, array $user, string $today): array
    {
        return array_values(array_filter(
            Container::prescriptions()->forViewer($patientId, $user, $today),
            static fn(array $p): bool => $p['status'] !== 'encerrada' && $p['current'] !== null
        ));
    }

    private function settingsPage(array $user, array $values, array $errors, int $status = 200): Response
    {
        return Response::html(View::render('Medications/views/rx_settings.php', [
            'title' => 'Receita (cabeçalho)', 'userName' => $user['name'], 'active' => '/admin/receita',
            'values' => $values, 'errors' => $errors, 'labels' => self::LABELS, 'limits' => self::SETTINGS,
        ], 'Layout/views/layout.php'), $status);
    }

    private function authAdmin(Request $r): array
    {
        $user = $r->attributes['user'];
        if ((int) ($user['is_admin'] ?? 0) !== 1) {
            throw new HttpException(403);
        }
        return $user;
    }

    private function authPatient(Request $r): int
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Clinician::assert($user, $r, $id, 'prescriptions', 'medicamentos');
        Container::gate()->authorize($user, 'view', Policy::MEDICAMENTOS, $id, $r);
        return $id;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }
}
