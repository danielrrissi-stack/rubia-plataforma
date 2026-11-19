<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class TimelinePagesTest extends DbTestCase
{
    private array $medico;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico', ['is_admin' => 1]);
        $this->patient = $this->makePatient(['birth_date' => '1990-04-02', 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
        $pid = $this->patient['id'];
        $this->db->insert('appointments', ['professional_id' => $this->medico['id'], 'patient_id' => $pid, 'starts_at' => $this->day('-20 days') . ' 09:00:00', 'ends_at' => $this->day('-20 days') . ' 10:00:00', 'kind' => 'retorno', 'status' => 'realizada', 'created_by' => $this->medico['id']]);
        Container::measurements()->create($pid, ['measured_on' => $this->day('-5 days'), 'weight_kg' => 80.0], 'equipe', (int) $this->medico['id']);
        Container::measurements()->create($pid, ['measured_on' => $this->day('-200 days'), 'weight_kg' => 88.8], 'equipe', (int) $this->medico['id']);
    }

    private function day(string $mod): string
    {
        return (new \DateTimeImmutable('today'))->modify($mod)->format('Y-m-d');
    }

    private function get(string $path): Response
    {
        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        return App::create()->handle(new Request('GET', (string) parse_url($path, PHP_URL_PATH), $query));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function url(string $q = ''): string
    {
        return '/pacientes/' . $this->patient['id'] . '/linha-do-tempo' . $q;
    }

    public function test_doctor_sees_since_last_visit(): void
    {
        $this->loginAs($this->medico);
        $res = $this->get($this->url());
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Desde a última visita (' . fmt_date($this->day('-20 days')) . ') — 20 dias', $res->body);
        $this->assertStringContainsString('80,0 kg', $res->body);
        $this->assertStringNotContainsString('88,8 kg', $res->body);
        $this->assertStringContainsString('/pacientes/' . $this->patient['id'] . '/crescimento', $res->body);
        $this->assertStringContainsString('88,8 kg', $this->get($this->url('?periodo=tudo'))->body);
        $views = (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'view' AND patient_id = ? AND after_json LIKE '%linha_do_tempo%'", [$this->patient['id']])['n'];
        $this->assertSame(2, $views);
        $this->assertStringContainsString('/linha-do-tempo', $this->get('/pacientes/' . $this->patient['id'])->body);
    }

    public function test_empty_period(): void
    {
        $this->loginAs($this->medico);
        $this->db->run('UPDATE measurements SET deleted_at = NOW(), deleted_reason = ? WHERE patient_id = ?', ['x', $this->patient['id']]);
        $this->db->run("UPDATE appointments SET status = 'cancelada' WHERE patient_id = ?", [$this->patient['id']]);
        $this->assertStringContainsString('Nada registrado neste período.', $this->get($this->url('?periodo=3m'))->body);
    }

    public function test_access(): void
    {
        $sec = $this->makeUser('secretaria');
        $this->loginAs($sec);
        $this->assertSame(403, $this->get($this->url())->status);
        $this->assertStringNotContainsString('/linha-do-tempo', $this->get('/pacientes/' . $this->patient['id'])->body);
        $nutri = $this->makeUser('nutricionista');
        $this->loginAs($nutri);
        $this->assertSame(403, $this->get($this->url())->status);
        $this->db->insert('professional_access', ['patient_id' => $this->patient['id'], 'professional_id' => $nutri['id'], 'granted_by' => $this->medico['id']]);
        $this->assertSame(200, $this->get($this->url())->status);
    }
}
