<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Mailer;
use Tests\DbTestCase;

final class MailerTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mailer::resetSent();
    }

    public function test_queue_and_send_marks_sent(): void
    {
        $mailer = new Mailer($this->db, ['from' => 'a@b.test', 'from_name' => 'X']);
        $id = $mailer->queue("dest@example.test\r\nBcc: x@y.z", "Assunto\r\nBcc: z", '<p>Olá</p>');

        $this->assertSame(1, $mailer->sendPending());
        $row = $this->db->one('SELECT * FROM mail_queue WHERE id = ?', [$id]);
        $this->assertNotNull($row['sent_at']);
        $this->assertSame(0, $mailer->sendPending());
        $this->assertCount(1, Mailer::$sent);
        $this->assertSame('dest@example.test Bcc: x@y.z', Mailer::$sent[0]['to']);
        $this->assertStringNotContainsString("\n", Mailer::$sent[0]['subject']);
        $this->assertSame('<p>Olá</p>', Mailer::$sent[0]['html']);
    }

    public function test_skips_rows_with_five_attempts(): void
    {
        $mailer = new Mailer($this->db, []);
        $id = $mailer->queue('a@example.test', 'S', 'B');
        $this->db->run('UPDATE mail_queue SET attempts = 5 WHERE id = ?', [$id]);
        $this->assertSame(0, $mailer->sendPending());
    }
}
