<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use Tests\DbTestCase;

final class ReminderRoundTest extends DbTestCase
{
    private array $doc;
    private array $nutri;
    private array $sec;
    private array $adult;
    private array $child;
    private int $mailFloor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->run("DELETE FROM settings WHERE `key` = 'agenda_reminder_day'");
        $this->doc = $this->makeUser('medico', ['name' => 'Dra. Teste', 'email' => 'dra@example.test']);
        $this->nutri = $this->makeUser('nutricionista', ['name' => 'Nutri Teste', 'email' => 'nutri@example.test']);
        $this->sec = $this->makeUser('secretaria');
        $this->adult = $this->makePatient(['full_name' => 'Ana Paciente Souza', 'birth_date' => '1990-01-10', 'phone' => '(11) 98765-4321', 'email' => 'ana@example.test']);
        $this->child = $this->makePatient(['full_name' => 'Caio Pequeno', 'birth_date' => '2018-05-05', 'phone' => '', 'email' => null]);
        $mom = $this->makeUser('responsavel', ['name' => 'Mãe Teste', 'phone' => '11 3456-7890', 'email' => 'mae@example.test']);
        Container::guardians()->link((int) $this->child['id'], (int) $mom['id'], 'mãe', (int) $this->doc['id']);
        Container::schedules()->applyClinicDefault((int) $this->doc['id'], (int) $this->doc['id'], new Request('POST', '/'));
        Container::schedules()->applyClinicDefault((int) $this->nutri['id'], (int) $this->nutri['id'], new Request('POST', '/'));
        $this->mailFloor = (int) $this->db->one('SELECT COALESCE(MAX(id), 0) AS m FROM mail_queue')['m'];
    }

    /** @return list<array{to_email:string,subject:string,body_html:string}> */
    private function queued(): array
    {
        return $this->db->all('SELECT to_email, subject, body_html FROM mail_queue WHERE id > ? ORDER BY id', [$this->mailFloor]);
    }

    private function book(array $prof, array $patient, string $startsAt): int
    {
        return Container::appointments()->book((int) $prof['id'], (int) $patient['id'], $startsAt, 'primeira', 'nota interna', $this->sec, '2026-11-16 07:00', new Request('POST', '/'));
    }

    private function insertAppointment(array $prof, array $patient, string $startsAt, string $status = 'agendada'): int
    {
        return $this->db->insert('appointments', [
            'professional_id' => (int) $prof['id'], 'patient_id' => (int) $patient['id'],
            'starts_at' => $startsAt . ':00', 'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt . ' +30 minutes')),
            'kind' => 'primeira', 'status' => $status, 'created_by' => (int) $this->sec['id'],
        ]);
    }

    private function processedDay(): ?string
    {
        $row = $this->db->one("SELECT value FROM settings WHERE `key` = 'agenda_reminder_day'");
        return $row === null ? null : $row['value'];
    }

    public function test_reminder_round_runs_once_after_eight(): void
    {
        // segunda 16/11: consultas de terca 17/11
        $this->book($this->doc, $this->child, '2026-11-17 09:00');
        $this->book($this->doc, $this->adult, '2026-11-17 08:00');
        $cancelled = $this->book($this->doc, $this->adult, '2026-11-17 10:00');
        Container::appointments()->cancel($cancelled, 'pediu', $this->sec, '2026-11-16 07:00', new Request('POST', '/'));
        $this->insertAppointment($this->nutri, $this->adult, '2026-11-18 08:00'); // outro dia: nutricionista nao recebe

        $round = Container::reminderRound();
        $this->assertSame(0, $round->runIfDue('2026-11-16 07:55'));
        $this->assertSame([], $this->queued());
        $this->assertNull($this->processedDay());

        $this->assertSame(3, $round->runIfDue('2026-11-16 08:00'));
        $q = $this->queued();
        $this->assertSame('2026-11-16', $this->processedDay());
        $this->assertSame(['dra@example.test', 'ana@example.test', 'mae@example.test'], array_column($q, 'to_email'));
        $this->assertSame('Avisos das consultas de 17/11/2026', $q[0]['subject']);
        $this->assertSame('Lembrete: consulta amanhã às 08:00', $q[1]['subject']);
        $this->assertSame('Lembrete: consulta amanhã às 09:00', $q[2]['subject']);
        $this->assertNotContains('nutri@example.test', array_column($q, 'to_email'));
        $prof = $q[0]['body_html'];
        $this->assertLessThan(strpos($prof, '09:00'), strpos($prof, '08:00'), 'em ordem de hora');
        $this->assertStringNotContainsString('10:00', $prof, 'cancelada fora');
        foreach ($q as $m) {
            $this->assertStringNotContainsString('nota interna', $m['body_html']);
        }
        $adultText = Container::reminderText()->render(Container::appointments()->remindable('2026-11-17')[0], '2026-11-16');
        $this->assertStringContainsString(e($adultText), $q[1]['body_html']);

        $this->assertSame(0, (new \App\Agenda\ReminderRound($this->db, Container::appointments(), Container::reminderText(), Container::agendaMailer(), Container::mailer(), Container::agendaAccess()))->runIfDue('2026-11-16 08:05'));
        $this->assertCount(3, $this->queued());
        $this->assertSame(0, $round->runIfDue('2026-11-16 23:59'));
        $this->assertSame(0, $round->runIfDue('2026-11-17 07:59'));
        $this->assertCount(3, $this->queued());
    }

    public function test_saturday_sends_sunday(): void
    {
        // sabado 21/11 envia os de domingo 22/11 (fora da grade: inserida direto)
        $this->insertAppointment($this->doc, $this->adult, '2026-11-22 10:00');
        $this->insertAppointment($this->doc, $this->adult, '2026-11-21 10:00'); // sabado: nao entra
        $this->insertAppointment($this->doc, $this->adult, '2026-11-23 10:00'); // segunda: nao entra
        $this->assertSame(2, Container::reminderRound()->runIfDue('2026-11-21 08:30'));
        $q = $this->queued();
        $this->assertSame('Avisos das consultas de 22/11/2026', $q[0]['subject']);
        $this->assertStringContainsString('amanhã, domingo, 22/11', $q[0]['body_html']);
        $this->assertSame('2026-11-21', $this->processedDay());
    }

    public function test_no_appointments_only_records_day(): void
    {
        $this->db->run("INSERT INTO settings (`key`, value) VALUES ('agenda_reminder_day', '2026-11-10')");
        $this->assertSame(0, Container::reminderRound()->runIfDue('2026-11-16 09:00'));
        $this->assertSame('2026-11-16', $this->processedDay());
        $this->assertSame([], $this->queued());
    }

    public function test_inactive_professional_sends_nothing(): void
    {
        // profissional desativada: a consulta nao vai acontecer, nem ela nem a familia recebem aviso
        $this->book($this->doc, $this->adult, '2026-11-17 08:00');
        Container::accessGrants()->grant((int) $this->adult['id'], (int) $this->nutri['id'], (int) $this->doc['id']);
        $this->book($this->nutri, $this->adult, '2026-11-17 09:00');
        $this->db->run('UPDATE users SET active = 0 WHERE id = ?', [(int) $this->doc['id']]);
        $this->assertSame(2, Container::reminderRound()->runIfDue('2026-11-16 08:00'));
        $q = $this->queued();
        $this->assertSame(['nutri@example.test', 'ana@example.test'], array_column($q, 'to_email'));
        $this->assertSame('Lembrete: consulta amanhã às 09:00', $q[1]['subject']);
        $this->assertSame('2026-11-16', $this->processedDay());
    }

    public function test_nutritionist_unreleased_patient_is_hidden(): void
    {
        Container::accessGrants()->grant((int) $this->adult['id'], (int) $this->nutri['id'], (int) $this->doc['id']);
        $this->insertAppointment($this->nutri, $this->adult, '2026-11-17 08:00');
        $this->insertAppointment($this->nutri, $this->child, '2026-11-17 09:00');
        $this->assertSame(3, Container::reminderRound()->runIfDue('2026-11-16 08:00'));
        $q = $this->queued();
        $this->assertSame(['nutri@example.test', 'ana@example.test', 'mae@example.test'], array_column($q, 'to_email'));
        $body = $q[0]['body_html'];
        $this->assertStringContainsString('Ana Paciente Souza', $body);
        $this->assertStringContainsString('09:00 — Paciente sem liberação', $body);
        $this->assertStringNotContainsString('Caio', $body);
        $this->assertStringNotContainsString('551134567890', $body);
        $this->assertStringNotContainsString('Mãe Teste', $body);
        $this->assertSame(1, substr_count($body, '<pre>'), 'so o aviso do paciente liberado');
        $this->assertStringContainsString('Caio', $q[2]['body_html'], 'lembrete da familia nao muda');
    }

    public function test_professional_email_contains_text_and_numbers(): void
    {
        $this->book($this->doc, $this->adult, '2026-11-17 08:00');
        $this->book($this->doc, $this->child, '2026-11-17 09:00');
        Container::reminderRound()->runIfDue('2026-11-16 08:00');
        $body = $this->queued()[0]['body_html'];
        $rows = Container::appointments()->remindable('2026-11-17');
        foreach ($rows as $a) {
            $this->assertStringContainsString('<pre>' . e(Container::reminderText()->render($a, '2026-11-16')) . '</pre>', $body);
        }
        $this->assertStringContainsString('Ana Paciente Souza', $body);
        $this->assertStringContainsString('Caio Pequeno', $body);
        $this->assertStringContainsString('5511987654321', $body);
        $this->assertStringContainsString('551134567890', $body);
        $this->assertStringContainsString('WhatsApp — Mãe Teste', $body);
    }
}
