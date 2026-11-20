<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Textos do aviso de consulta (/admin/agenda): so a medica administradora. Vazio = texto padrao. */
final class AgendaSettingsController
{
    use AgendaPages;

    private const MAX = 1000;
    private const LABELS = ['adult' => 'Texto para paciente adulto', 'child' => 'Texto para paciente menor de 18 anos'];

    /** GET /admin/agenda */
    public function form(Request $r): Response
    {
        $this->admin($r);
        return $this->settingsPage($r, Container::reminderText()->templates(), []);
    }

    /** POST /admin/agenda (restaurar=adult|child volta aquele texto ao padrao) */
    public function save(Request $r): Response
    {
        $user = $this->admin($r);
        $restore = (string) $r->input('restaurar', '');
        $values = [];
        $errors = [];
        foreach (ReminderText::KEYS as $kind => $key) {
            $raw = $r->post[$key] ?? '';
            $v = is_string($raw) ? trim(str_replace("\r\n", "\n", $raw)) : '';
            if ($restore === $kind || $v === ReminderText::DEFAULTS[$kind]) {
                $v = '';
            }
            if (mb_strlen($v) > self::MAX) {
                $errors[] = self::LABELS[$kind] . ': no máximo ' . self::MAX . ' caracteres.';
            }
            $values[$key] = $v;
        }
        if ($errors !== []) {
            $shown = [];
            foreach (ReminderText::KEYS as $kind => $key) {
                $shown[$kind] = $values[$key] === '' ? ReminderText::DEFAULTS[$kind] : $values[$key];
            }
            return $this->settingsPage($r, $shown, $errors, 422);
        }
        $db = Container::db();
        $before = [];
        foreach (ReminderText::KEYS as $key) {
            $row = $db->one('SELECT value FROM settings WHERE `key` = ?', [$key]);
            $before[$key] = $row === null ? '' : (string) $row['value'];
        }
        $db->transaction(function () use ($db, $values, $before, $user, $r): void {
            foreach ($values as $key => $v) {
                $db->run('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $v]);
            }
            Container::audit()->record((int) $user['id'], 'update', 'settings', null, null, $before, $values, $r);
        });
        Container::reminderText()->forget();
        Session::flash('ok', $restore !== '' ? 'Texto padrão restaurado.' : 'Textos do aviso salvos.');
        return Response::redirect('/admin/agenda');
    }

    private function admin(Request $r): array
    {
        $user = $this->staff($r);
        if (!Container::agendaAccess()->canEditTexts($user)) {
            $this->deny($user, $r);
        }
        return $user;
    }

    /** @param array{adult:string,child:string} $texts */
    private function settingsPage(Request $r, array $texts, array $errors, int $status = 200): Response
    {
        return Response::html(View::render('Agenda/views/settings.php', [
            'title' => 'Agenda (avisos)', 'userName' => $r->attributes['user']['name'], 'active' => '/admin/agenda',
            'texts' => $texts, 'errors' => $errors, 'labels' => self::LABELS, 'keys' => ReminderText::KEYS,
            'defaults' => ReminderText::DEFAULTS, 'max' => self::MAX,
        ], 'Layout/views/layout.php'), $status);
    }
}
