<?php
declare(strict_types=1);

namespace App\Core;

/** Fila de e-mails em mail_queue; envio por mail() (ou transporte em memória em APP_ENV=test). */
final class Mailer
{
    public const MAX_ATTEMPTS = 5;

    /** @var array<int,array{to:string,subject:string,html:string}> */
    public static array $sent = [];

    public function __construct(private Database $db, private array $cfg)
    {
    }

    public static function resetSent(): void
    {
        self::$sent = [];
    }

    public function queue(string $to, string $subject, string $html): int
    {
        return $this->db->insert('mail_queue', [
            'to_email' => self::clean($to),
            'subject' => self::clean($subject),
            'body_html' => $html,
        ]);
    }

    /** Envia até $max e-mails pendentes; devolve quantos foram enviados. */
    public function sendPending(int $max = 20): int
    {
        $rows = $this->db->all(
            'SELECT * FROM mail_queue WHERE sent_at IS NULL AND attempts < ? ORDER BY id LIMIT ' . max(1, $max),
            [self::MAX_ATTEMPTS]
        );
        $count = 0;
        foreach ($rows as $row) {
            try {
                $ok = $this->deliver(self::clean($row['to_email']), self::clean($row['subject']), $row['body_html']);
                $error = $ok ? null : 'mail() retornou false';
            } catch (\Throwable $e) {
                $ok = false;
                $error = $e->getMessage();
            }
            if ($ok) {
                $this->db->run('UPDATE mail_queue SET sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?', [$row['id']]);
                $count++;
            } else {
                $this->db->run('UPDATE mail_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?', [$error, $row['id']]);
            }
        }
        return $count;
    }

    private function deliver(string $to, string $subject, string $html): bool
    {
        if ($this->isTest()) {
            self::$sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            return true;
        }
        $from = self::clean((string) ($this->cfg['from'] ?? ''));
        $name = self::clean((string) ($this->cfg['from_name'] ?? ''));
        $fromHeader = $name !== '' ? mb_encode_mimeheader($name, 'UTF-8') . ' <' . $from . '>' : $from;
        $headers = implode("\r\n", [
            'From: ' . $fromHeader,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ]);
        return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $html, $headers);
    }

    private function isTest(): bool
    {
        return (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '')) === 'test';
    }

    /** Remove CR/LF (injeção de cabeçalhos). */
    private static function clean(string $s): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $s) ?? '');
    }
}
