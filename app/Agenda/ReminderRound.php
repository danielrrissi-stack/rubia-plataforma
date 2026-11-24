<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Core\Database;
use App\Core\Mailer;

/**
 * Rodada da vespera (cron/run.php): a partir das 08:00, uma vez por dia, poe na fila, para as consultas
 * agendada/confirmada de amanha, um e-mail "Avisos das consultas de {data}" por profissional ativa com e-mail e o
 * lembrete da familia de cada consulta (profissional desativada: nada, nem para a familia). Uma transacao cobre a fila e a chave settings.agenda_reminder_day
 * (linha travada com FOR UPDATE: duas rodadas sobrepostas nao enviam duas vezes). Dias perdidos nao sao reenviados.
 * Na agenda de nutricionista, paciente sem liberacao aparece so como "HH:MM — Paciente sem liberação".
 */
final class ReminderRound
{
    public const KEY = 'agenda_reminder_day';
    private const FROM = '08:00';

    public function __construct(
        private Database $db,
        private AppointmentRepository $appointments,
        private ReminderText $text,
        private AgendaMailer $agendaMailer,
        private Mailer $mailer,
        private AgendaAccess $access,
    ) {
    }

    /** $now 'Y-m-d H:i'. Devolve quantos e-mails foram para a fila. */
    public function runIfDue(string $now): int
    {
        if (substr($now, 11, 5) < self::FROM) {
            return 0;
        }
        $today = substr($now, 0, 10);
        // garante a linha para poder trava-la (fora da transacao; em producao confirma na hora)
        $this->db->run('INSERT IGNORE INTO settings (`key`, value) VALUES (?, NULL)', [self::KEY]);
        return (int) $this->db->transaction(function () use ($today): int {
            $row = $this->db->one('SELECT value FROM settings WHERE `key` = ? FOR UPDATE', [self::KEY]);
            if ($row !== null && (string) $row['value'] === $today) {
                return 0;
            }
            $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
            $byProfessional = [];
            foreach ($this->appointments->remindable($tomorrow) as $a) {
                $byProfessional[(int) $a['professional_id']][] = $a;
            }
            $count = 0;
            foreach ($byProfessional as $professionalId => $list) {
                $prof = $this->db->one('SELECT email, active FROM users WHERE id = ?', [$professionalId]);
                if ($prof === null || (int) $prof['active'] !== 1) {
                    continue; // profissional desativada: a consulta nao vai acontecer, ninguem recebe aviso
                }
                $count += $this->professionalEmail($professionalId, trim((string) $prof['email']), $list, $tomorrow, $today);
                foreach ($list as $a) {
                    $count += $this->agendaMailer->reminder($a, $today);
                }
            }
            $this->db->run(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [self::KEY, $today],
            );
            return $count;
        });
    }

    /** @param list<array> $list consultas da profissional, em ordem de hora */
    private function professionalEmail(int $professionalId, string $to, array $list, string $day, string $today): int
    {
        if ($to === '') {
            return 0;
        }
        $html = '<p>' . e('Avisos das consultas de ' . fmt_date($day) . ', para enviar pelo WhatsApp:') . '</p>';
        foreach ($list as $a) {
            $time = substr((string) $a['starts_at'], 11, 5);
            if (!$this->access->patientVisibleOnAgenda($professionalId, (int) $a['patient_id'])) {
                $html .= '<hr><h3>' . e($time . ' — Paciente sem liberação') . '</h3>';
                continue;
            }
            $html .= '<hr><h3>' . e($time . ' — ' . $a['patient_name']) . '</h3>';
            $contacts = $this->text->contacts($a);
            if ($contacts === []) {
                $html .= '<p>' . e('Sem telefone cadastrado.') . '</p>';
            } else {
                $html .= '<ul>';
                foreach ($contacts as $c) {
                    $number = $c['wa'] ?? 'telefone em formato inválido';
                    $html .= '<li>' . e($c['label'] . ': ' . $c['raw'] . ' (' . $number . ')') . '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '<pre>' . e($this->text->render($a, $today)) . '</pre>';
        }
        $this->mailer->queue($to, 'Avisos das consultas de ' . fmt_date($day), $html);
        return 1;
    }
}
