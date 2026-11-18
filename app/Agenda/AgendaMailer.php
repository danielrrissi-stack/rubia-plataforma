<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Core\Database;
use App\Core\Mailer;
use App\Growth\Age;

/**
 * E-mails da agenda para a familia (marcada, cancelada, remarcada, lembrete da vespera), pela fila mail_queue.
 * Destinatarios: adulto -> patients.email, senao e-mail da conta ativa (patients.user_id); menor (< 18 na data da consulta)
 * -> cada responsavel ativo (vinculo e conta ativos) com e-mail; repetidos (sem diferenca de maiusculas) uma vez so.
 * Nunca leva a observacao interna nem o motivo de cancelamento.
 */
final class AgendaMailer
{
    public function __construct(private Database $db, private Mailer $mailer, private ReminderText $text)
    {
    }

    /** @return list<string> */
    public function familyRecipients(array $appointment): array
    {
        if (!$this->isMinor($appointment)) {
            $email = trim((string) ($appointment['patient_email'] ?? ''));
            if ($email === '' && !empty($appointment['patient_user_id'])) {
                $row = $this->db->one('SELECT email FROM users WHERE id = ? AND active = 1', [(int) $appointment['patient_user_id']]);
                $email = trim((string) ($row['email'] ?? ''));
            }
            return $email === '' ? [] : [$email];
        }
        $rows = $this->db->all(
            "SELECT u.email FROM guardian_links g JOIN users u ON u.id = g.user_id
             WHERE g.patient_id = ? AND g.active = 1 AND u.active = 1 AND u.email IS NOT NULL AND TRIM(u.email) <> ''
             ORDER BY g.id",
            [(int) $appointment['patient_id']],
        );
        $out = [];
        foreach ($rows as $row) {
            $email = trim((string) $row['email']);
            $out[mb_strtolower($email)] ??= $email;
        }
        return array_values($out);
    }

    public function booked(int $id, string $today): void
    {
        $a = $this->find($id);
        if ($a !== null) {
            $this->send($a, 'Consulta marcada — ' . self::when($a), $this->bookedBody($a));
        }
    }

    public function cancelled(int $id, string $today): void
    {
        $a = $this->find($id);
        if ($a === null) {
            return;
        }
        $body = '<p>' . e(ucfirst($this->subjectPhrase($a)) . ' de ' . self::longDate($a) . ' foi cancelada. Para marcar outro horário, fale com a clínica.') . '</p>';
        $this->send($a, 'Consulta cancelada — ' . self::when($a), $body);
    }

    public function rescheduled(int $oldId, int $newId, string $today): void
    {
        $a = $this->find($newId);
        if ($a === null) {
            return;
        }
        $old = $this->find($oldId);
        $before = $old === null ? '' : '<p>' . e('(Antes: ' . self::longDate($old) . '.)') . '</p>';
        $this->send($a, 'Consulta remarcada — ' . self::when($a), $this->bookedBody($a, $before));
    }

    /** Lembrete da vespera (rodada das 08:00): o mesmo texto do aviso. Devolve quantos e-mails foram para a fila. */
    public function reminder(array $appointment, string $today): int
    {
        $subject = 'Lembrete: consulta amanhã às ' . substr((string) $appointment['starts_at'], 11, 5);
        return $this->send($appointment, $subject, '<p>' . e($this->text->render($appointment, $today)) . '</p>');
    }

    /** Frase principal, $extra (HTML pronto, ex.: "(Antes: …)") e o endereco. */
    private function bookedBody(array $a, string $extra = ''): string
    {
        $intro = $this->isMinor($a) ? 'A consulta de ' . ReminderText::callName($a) . ' com ' : 'Sua consulta com ';
        return '<p>' . e($intro . $a['professional_name'] . ' está marcada para ' . self::longDate($a) . '.') . '</p>'
            . $extra . $this->addressLine($a);
    }

    private function addressLine(array $a): string
    {
        $address = $this->text->address($a);
        return $address === '' ? '' : '<p>' . e('Endereço: ' . $address . '.') . '</p>';
    }

    /** "a consulta com {profissional}" / "a consulta de {nome} com {profissional}" */
    private function subjectPhrase(array $a): string
    {
        return 'a consulta ' . ($this->isMinor($a) ? 'de ' . ReminderText::callName($a) . ' ' : '') . 'com ' . $a['professional_name'];
    }

    private function send(array $a, string $subject, string $html): int
    {
        $n = 0;
        foreach ($this->familyRecipients($a) as $to) {
            $this->mailer->queue($to, $subject, $html);
            $n++;
        }
        return $n;
    }

    private function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT a.*, p.full_name AS patient_name, p.social_name, p.birth_date, p.email AS patient_email,
                    p.user_id AS patient_user_id, u.name AS professional_name, ps.address AS professional_address
             FROM appointments a
             JOIN patients p ON p.id = a.patient_id
             JOIN users u ON u.id = a.professional_id
             LEFT JOIN professional_schedules ps ON ps.professional_id = a.professional_id
             WHERE a.id = ?',
            [$id],
        );
    }

    private function isMinor(array $a): bool
    {
        return Age::years((string) $a['birth_date'], substr((string) $a['starts_at'], 0, 10)) < 18;
    }

    /** "dd/mm/aaaa às HH:MM" */
    private static function when(array $a): string
    {
        $s = (string) $a['starts_at'];
        return fmt_date(substr($s, 0, 10)) . ' às ' . substr($s, 11, 5);
    }

    /** "terça-feira, 17/11/2026, às 08:00" */
    private static function longDate(array $a): string
    {
        $s = (string) $a['starts_at'];
        $weekday = AgendaStatus::WEEKDAY_LONG[(int) (new \DateTimeImmutable(substr($s, 0, 10)))->format('N')];
        return $weekday . ', ' . fmt_date(substr($s, 0, 10)) . ', às ' . substr($s, 11, 5);
    }
}
