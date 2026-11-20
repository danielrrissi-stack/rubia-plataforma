<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class AgendaMailerTest extends DbTestCase
{
    /** segunda-feira */
    private const NOW = '2026-11-16 09:00';
    private const TODAY = '2026-11-16';

    private array $doc;
    private array $sec;
    private int $mailFloor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = $this->makeUser('medico', ['name' => 'Dra. Teste', 'totp_enabled' => 1]);
        $this->sec = $this->makeUser('secretaria', ['name' => 'Secretaria Teste', 'totp_enabled' => 1]);
        Container::schedules()->applyClinicDefault((int) $this->doc['id'], (int) $this->doc['id'], new Request('POST', '/'));
        $this->mailFloor = (int) $this->db->one('SELECT COALESCE(MAX(id), 0) AS m FROM mail_queue')['m'];
    }

    /** @return list<array{to_email:string,subject:string,body_html:string}> */
    private function queued(): array
    {
        return $this->db->all('SELECT to_email, subject, body_html FROM mail_queue WHERE id > ? ORDER BY id', [$this->mailFloor]);
    }

    private function book(array $patient, string $startsAt, ?string $note = null, string $now = self::NOW): int
    {
        return Container::appointments()->book((int) $this->doc['id'], (int) $patient['id'], $startsAt, 'primeira', $note, $this->sec, $now, new Request('POST', '/'));
    }

    private function child(array $over = []): array
    {
        return $this->makePatient(array_merge(['full_name' => 'Caio Pequeno Silva', 'birth_date' => '2018-05-05', 'email' => null], $over));
    }

    private function guardian(array $child, array $over): array
    {
        $g = $this->makeUser('responsavel', $over);
        Container::guardians()->link((int) $child['id'], (int) $g['id'], 'mãe', (int) $this->doc['id']);
        return $g;
    }

    public function test_adult_gets_ficha_email_else_account(): void
    {
        $mailer = Container::agendaMailer();
        $account = $this->makeUser('paciente', ['email' => 'conta-ana@example.test']);
        $withEmail = $this->makePatient(['full_name' => 'Ana Adulta', 'birth_date' => '1990-01-10', 'email' => 'ficha@example.test', 'user_id' => $account['id']]);
        $a1 = $this->book($withEmail, '2026-11-17 08:00');
        $this->assertSame(['ficha@example.test'], $mailer->familyRecipients(Container::appointments()->find($a1)));

        $account2 = $this->makeUser('paciente', ['email' => 'conta@example.test']);
        $noEmail = $this->makePatient(['full_name' => 'Bia Adulta', 'birth_date' => '1990-01-10', 'email' => null, 'user_id' => $account2['id']]);
        $a2 = $this->book($noEmail, '2026-11-17 09:00');
        $this->assertSame(['conta@example.test'], $mailer->familyRecipients(Container::appointments()->find($a2)));

        $nothing = $this->makePatient(['full_name' => 'Cia Adulta', 'birth_date' => '1990-01-10', 'email' => '']);
        $a3 = $this->book($nothing, '2026-11-17 10:00');
        $this->assertSame([], $mailer->familyRecipients(Container::appointments()->find($a3)));
        $mailer->booked($a3, self::TODAY);
        $this->assertSame([], $this->queued(), 'sem e-mail: nada na fila');

        // conta do paciente desativada: nao serve de alternativa
        $this->db->run('UPDATE users SET active = 0 WHERE id = ?', [(int) $account2['id']]);
        $this->assertSame([], $mailer->familyRecipients(Container::appointments()->find($a2)));

        // adulto com e-mail de responsavel vinculado nao usa o responsavel
        $this->guardian($withEmail, ['email' => 'mae@example.test']);
        $this->assertSame(['ficha@example.test'], $mailer->familyRecipients(Container::appointments()->find($a1)));
    }

    public function test_minor_each_guardian_once(): void
    {
        $child = $this->child(['email' => 'crianca@example.test']);
        $this->guardian($child, ['email' => 'Mae@Example.test']);
        $this->guardian($child, ['email' => null]);
        // mesma conta vinculada duas vezes (e-mail repetido); users.email e unico sem diferenca de maiusculas
        $mom = $this->db->one("SELECT id FROM users WHERE email = 'Mae@Example.test'");
        Container::guardians()->link((int) $child['id'], (int) $mom['id'], 'mãe', (int) $this->doc['id']);
        $this->guardian($child, ['email' => 'pai@example.test']);
        $inactiveLink = $this->guardian($child, ['email' => 'ex@example.test']);
        $this->db->run('UPDATE guardian_links SET active = 0 WHERE user_id = ?', [(int) $inactiveLink['id']]);

        $id = $this->book($child, '2026-11-17 08:00');
        $this->assertSame(['Mae@Example.test', 'pai@example.test'], Container::agendaMailer()->familyRecipients(Container::appointments()->find($id)));

        Container::agendaMailer()->booked($id, self::TODAY);
        $this->assertSame(['Mae@Example.test', 'pai@example.test'], array_column($this->queued(), 'to_email'));
        $body = $this->queued()[0]['body_html'];
        $this->assertStringContainsString('A consulta de Caio com Dra. Teste está marcada para terça-feira, 17/11/2026, às 08:00.', $body);
    }

    public function test_inactive_guardian_account_gets_no_contact_nor_email(): void
    {
        $child = $this->child();
        $off = $this->guardian($child, ['name' => 'Mãe Inativa', 'email' => 'inativa@example.test', 'phone' => '(11) 98765-4321']);
        $this->db->run('UPDATE users SET active = 0 WHERE id = ?', [(int) $off['id']]);
        $id = $this->book($child, '2026-11-17 08:00');
        $a = Container::appointments()->find($id);
        $this->assertSame([], Container::reminderText()->contacts(array_merge($a, ['patient_phone' => ''])));
        $this->assertSame([], Container::agendaMailer()->familyRecipients($a));
        Container::agendaMailer()->booked($id, self::TODAY);
        $this->assertSame([], $this->queued());
    }

    public function test_subjects_exact_and_no_internal_note(): void
    {
        $this->db->run('UPDATE professional_schedules SET address = ? WHERE professional_id = ?', ['Rua A, 10 <sala 2>', (int) $this->doc['id']]);
        $p = $this->makePatient(['full_name' => 'Ana Adulta', 'social_name' => 'Aninha', 'birth_date' => '1990-01-10', 'email' => 'ana@example.test']);
        $mailer = Container::agendaMailer();

        $id = $this->book($p, '2026-11-17 08:00', 'pagar na recepção');
        $mailer->booked($id, self::TODAY);
        Container::appointments()->cancel($id, 'motivo secreto do cancelamento', $this->sec, self::NOW, new Request('POST', '/'));
        $mailer->cancelled($id, self::TODAY);
        $old = $this->book($p, '2026-11-18 09:00', 'pagar na recepção');
        $new = Container::appointments()->reschedule($old, (int) $this->doc['id'], '2026-11-19 14:30', $this->sec, self::NOW, new Request('POST', '/'));
        $mailer->rescheduled($old, $new, self::TODAY);

        $q = $this->queued();
        $this->assertSame([
            'Consulta marcada — 17/11/2026 às 08:00',
            'Consulta cancelada — 17/11/2026 às 08:00',
            'Consulta remarcada — 19/11/2026 às 14:30',
        ], array_column($q, 'subject'));
        $this->assertSame(['ana@example.test', 'ana@example.test', 'ana@example.test'], array_column($q, 'to_email'));
        $this->assertStringContainsString('Sua consulta com Dra. Teste está marcada para terça-feira, 17/11/2026, às 08:00.', $q[0]['body_html']);
        $this->assertStringContainsString('Endereço: Rua A, 10 &lt;sala 2&gt;.', $q[0]['body_html']);
        $this->assertStringContainsString('A consulta com Dra. Teste de terça-feira, 17/11/2026, às 08:00 foi cancelada. Para marcar outro horário, fale com a clínica.', $q[1]['body_html']);
        $this->assertStringContainsString('Sua consulta com Dra. Teste está marcada para quinta-feira, 19/11/2026, às 14:30.', $q[2]['body_html']);
        $this->assertStringContainsString('(Antes: quarta-feira, 18/11/2026, às 09:00.)', $q[2]['body_html']);
        foreach ($q as $m) {
            $this->assertStringNotContainsString('pagar', $m['body_html']);
            $this->assertStringNotContainsString('motivo secreto', $m['body_html']);
            $this->assertStringNotContainsString('<sala 2>', $m['body_html']);
        }
    }

    public function test_controller_queues_on_book_cancel_reschedule(): void
    {
        $mon = date('Y-m-d', strtotime('next monday'));
        $p = $this->makePatient(['full_name' => 'Ana Adulta', 'birth_date' => '1990-01-10', 'email' => 'ana@example.test', 'responsible_professional_id' => $this->doc['id'], 'created_by' => $this->doc['id']]);
        Session::reset();
        Container::auth()->completeLogin((int) $this->sec['id'], new Request('POST', '/login'));

        $res = $this->post('/agenda/marcar', ['prof' => (string) $this->doc['id'], 'inicio' => $mon . ' 08:00', 'patient_id' => (string) $p['id'], 'kind' => 'primeira', 'note' => '']);
        $this->assertSame(302, $res->status);
        $id = (int) $this->db->one('SELECT MAX(id) AS id FROM appointments WHERE patient_id = ?', [(int) $p['id']])['id'];
        $this->assertSame(['Consulta marcada — ' . date('d/m/Y', strtotime($mon)) . ' às 08:00'], array_column($this->queued(), 'subject'));

        $res = $this->post('/agenda/consultas/' . $id . '/remarcar', ['prof' => (string) $this->doc['id'], 'inicio' => $mon . ' 09:00']);
        $this->assertSame(302, $res->status);
        $newId = (int) $this->db->one('SELECT rescheduled_to_id FROM appointments WHERE id = ?', [$id])['rescheduled_to_id'];
        $this->assertSame('Consulta remarcada — ' . date('d/m/Y', strtotime($mon)) . ' às 09:00', array_column($this->queued(), 'subject')[1]);

        $res = $this->post('/agenda/consultas/' . $newId . '/cancelar', ['motivo' => 'pediu']);
        $this->assertSame(302, $res->status);
        $this->assertSame('Consulta cancelada — ' . date('d/m/Y', strtotime($mon)) . ' às 09:00', array_column($this->queued(), 'subject')[2]);

        // falha de cancelamento (ja cancelada): nada novo na fila
        $this->post('/agenda/consultas/' . $newId . '/cancelar', ['motivo' => 'de novo']);
        $this->assertCount(3, $this->queued());
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }
}
