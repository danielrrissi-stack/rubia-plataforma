<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Diary\DiaryPhotoStore;
use Tests\DbTestCase;

final class PortalDiaryTest extends DbTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $dir;
    private array $med;
    private array $resp;
    private array $patient;
    private int $pid;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/rubia_pd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/uploads', 0777, true);
        Container::set('diaryPhotos', new DiaryPhotoStore($this->dir . '/uploads', true));
        $this->today = date('Y-m-d');
        $this->med = $this->makeUser('medico', ['totp_enabled' => 1]);
        $this->resp = $this->makeUser('responsavel');
        $this->patient = $this->makePatient([
            'full_name' => 'Ana Souza', 'birth_date' => (date('Y') - 8) . '-03-10', 'sex' => 'F',
            'responsible_professional_id' => $this->med['id'], 'created_by' => $this->med['id'],
        ]);
        $this->pid = (int) $this->patient['id'];
        $this->db->insert('guardian_links', ['patient_id' => $this->pid, 'user_id' => $this->resp['id'], 'relationship' => 'mãe', 'created_by' => $this->med['id']]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$d/$f") ? $this->rrmdir("$d/$f") : @unlink("$d/$f");
            }
        }
        @rmdir($d);
    }

    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data = [], array $query = [], array $files = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, $query, $data, $files, ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
    }

    private function ask(string $type, array $over = [], ?int $pid = null): int
    {
        return Container::diaryRequests()->create($pid ?? $this->pid, $over + [
            'type' => $type, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start_date' => $this->day(-10),
        ], $this->med, $this->today, new Request('POST', '/'));
    }

    private function rows(string $table = 'diary_entries'): int
    {
        return (int) $this->db->one("SELECT COUNT(*) c FROM $table WHERE deleted_at IS NULL")['c'];
    }

    private function png(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rubia_png');
        file_put_contents($tmp, base64_decode(self::PNG));
        return ['foto' => ['name' => 'f.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
    }

    private function base(): string
    {
        return '/portal/p/' . $this->pid . '/diario';
    }

    public function test_guardian_records_water_and_corrects(): void
    {
        $req = $this->ask('agua', ['guidance' => 'Beber ao longo do dia']);
        $this->loginAs($this->resp);

        $page = $this->get($this->base());
        $this->assertSame(200, $page->status);
        foreach (['O que a Dra. Rubia pediu para anotar', 'Água', 'Beber ao longo do dia', 'Anotar', 'hoje', 'ontem'] as $s) {
            $this->assertStringContainsString($s, $page->body);
        }
        $this->assertStringContainsString('Anote o que a equipe pediu.', $this->get('/portal/p/' . $this->pid)->body);

        $res = $this->post($this->base() . '/' . $req . '/anotar', ['glasses' => '5'], ['dia' => $this->today]);
        $this->assertSame(302, $res->status);
        $this->assertSame($this->base() . '?dia=' . $this->today, $res->headers['Location']);
        $this->assertStringContainsString('Anotado.', $this->get($this->base())->body);

        $form = $this->get($this->base() . '/' . $req . '/anotar', ['dia' => $this->today]);
        $this->assertStringContainsString('value="5"', $form->body);
        $this->assertStringContainsString('Corrigir', $this->get($this->base())->body);

        $this->post($this->base() . '/' . $req . '/anotar', ['glasses' => '7'], ['dia' => $this->today]);
        $this->assertSame(1, $this->rows());
        $this->assertSame(7, (int) $this->db->one('SELECT glasses FROM diary_entries WHERE deleted_at IS NULL')['glasses']);
    }

    public function test_guardian_records_meal_with_photo_and_sees_it(): void
    {
        $req = $this->ask('alimentacao');
        $this->loginAs($this->resp);

        $form = $this->get($this->base() . '/' . $req . '/anotar', ['dia' => $this->today]);
        $this->assertStringContainsString('multipart/form-data', $form->body);
        $this->assertStringContainsString('Fotografe só o prato.', $form->body);

        $res = $this->post($this->base() . '/' . $req . '/anotar', [
            'meal' => 'almoco', 'meal_time' => '12:30', 'description' => 'Arroz e feijão',
        ], ['dia' => $this->today], $this->png());
        $this->assertSame(302, $res->status);
        $entry = $this->db->one('SELECT * FROM diary_entries WHERE deleted_at IS NULL');
        $this->assertNotNull($entry['photo_path']);

        $page = $this->get($this->base());
        $this->assertStringContainsString('Arroz e feijão', $page->body);
        $this->assertStringContainsString('Almoço', $page->body);
        $this->assertStringContainsString($this->base() . '/fotos/' . $entry['id'], $page->body);
        $this->assertStringContainsString('Anotar refeição', $page->body);
        $photo = $this->get($this->base() . '/fotos/' . $entry['id']);
        $this->assertSame(200, $photo->status);

        $edit = $this->get($this->base() . '/registros/' . $entry['id'] . '/editar');
        $this->assertSame(200, $edit->status);
        $this->assertStringContainsString('Remover foto', $edit->body);
        $this->post($this->base() . '/registros/' . $entry['id'] . '/editar', [
            'meal' => 'almoco', 'description' => 'Arroz, feijão e salada', 'remover_foto' => '1',
        ]);
        $after = $this->db->one('SELECT * FROM diary_entries WHERE id = ?', [$entry['id']]);
        $this->assertNull($after['photo_path']);
        $this->assertSame(404, $this->get($this->base() . '/fotos/' . $entry['id'])->status);

        $res = $this->post($this->base() . '/registros/' . $entry['id'] . '/remover');
        $this->assertSame(302, $res->status);
        $this->assertSame(0, $this->rows());
    }

    public function test_secret_request_leaves_no_trail(): void
    {
        $req = $this->ask('alimentacao', ['guidance' => 'Orientacao reservada']);
        $entry = Container::diaryEntries()->record(
            $req, $this->today, ['meal' => 'almoco', 'description' => 'Prato reservado'], $this->png()['foto'], $this->resp, $this->today, new Request('POST', '/'),
        );
        Container::diaryComments()->add($entry, 'Comentario reservado', $this->med, new Request('POST', '/'));
        $this->loginAs($this->resp);
        $this->assertStringContainsString('Anote o que a equipe pediu.', $this->get('/portal/p/' . $this->pid)->body);
        $this->assertSame(200, $this->get($this->base() . '/fotos/' . $entry)->status);

        $this->db->run('UPDATE diary_requests SET secret = 1 WHERE id = ?', [$req]);
        $this->assertStringNotContainsString('Anote o que a equipe pediu.', $this->get('/portal/p/' . $this->pid)->body);
        $page = $this->get($this->base());
        $this->assertSame(200, $page->status);
        foreach (['Orientacao reservada', 'Prato reservado', 'Comentario reservado'] as $s) {
            $this->assertStringNotContainsString($s, $page->body);
        }
        $this->assertStringContainsString('Nada para anotar neste dia.', $page->body);
        $this->assertSame(404, $this->get($this->base() . '/' . $req . '/anotar')->status);
        $this->assertSame(404, $this->post($this->base() . '/' . $req . '/anotar', ['meal' => 'jantar', 'description' => 'x'], ['dia' => $this->today])->status);
        $this->assertSame(404, $this->get($this->base() . '/fotos/' . $entry)->status);
        $this->assertSame(404, $this->get($this->base() . '/registros/' . $entry . '/editar')->status);
        $this->assertSame(404, $this->post($this->base() . '/registros/' . $entry . '/remover')->status);
        $this->assertSame(1, $this->rows());
    }

    public function test_day_outside_window_or_not_asked_returns_422(): void
    {
        $req = $this->ask('agua', ['weekdays' => [(int) date('N', strtotime($this->today))]]);
        $this->loginAs($this->resp);

        $old = $this->post($this->base() . '/' . $req . '/anotar', ['glasses' => '3'], ['dia' => $this->day(-8)]);
        $this->assertSame(422, $old->status);
        $this->assertStringContainsString('Só dá para anotar até 7 dias atrás.', $old->body);
        $future = $this->post($this->base() . '/' . $req . '/anotar', ['glasses' => '3'], ['dia' => $this->day(1)]);
        $this->assertSame(422, $future->status);
        $other = $this->day(-1);
        $notAsked = $this->post($this->base() . '/' . $req . '/anotar', ['glasses' => '3'], ['dia' => $other]);
        $this->assertSame(422, $notAsked->status);
        $this->assertStringContainsString('Este dia não está entre os pedidos da equipe.', $notAsked->body);
        $this->assertSame(0, $this->rows());
        $this->assertStringContainsString('Nada para anotar neste dia.', $this->get($this->base(), ['dia' => $other])->body);
    }

    public function test_unparseable_day_on_post_is_rejected_not_recorded_today(): void
    {
        $req = $this->ask('agua');
        $this->loginAs($this->resp);
        $res = $this->post($this->base() . '/' . $req . '/anotar', ['glasses' => '3', 'dia' => 'lixo']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Informe um dia válido.', $res->body);
        $this->assertSame(0, $this->rows());
    }

    public function test_only_author_edits_meal_but_anyone_corrects_water(): void
    {
        $meal = $this->ask('alimentacao');
        $water = $this->ask('agua');
        $other = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $this->pid, 'user_id' => $other['id'], 'relationship' => 'pai', 'created_by' => $this->med['id']]);
        $this->loginAs($this->resp);
        $this->post($this->base() . '/' . $meal . '/anotar', ['meal' => 'jantar', 'description' => 'Sopa'], ['dia' => $this->today]);
        $this->post($this->base() . '/' . $water . '/anotar', ['glasses' => '2'], ['dia' => $this->today]);
        $mealEntry = (int) $this->db->one("SELECT e.id FROM diary_entries e WHERE e.request_id = ?", [$meal])['id'];
        $waterEntry = (int) $this->db->one("SELECT e.id FROM diary_entries e WHERE e.request_id = ?", [$water])['id'];

        $this->loginAs($other);
        $this->assertSame(404, $this->get($this->base() . '/registros/' . $mealEntry . '/editar')->status);
        $this->assertSame(404, $this->post($this->base() . '/registros/' . $mealEntry . '/editar', ['meal' => 'jantar', 'description' => 'X'])->status);
        $this->assertSame(404, $this->post($this->base() . '/registros/' . $mealEntry . '/remover')->status);
        $this->assertSame('Sopa', $this->db->one('SELECT description FROM diary_entries WHERE id = ?', [$mealEntry])['description']);

        $this->post($this->base() . '/' . $water . '/anotar', ['glasses' => '6'], ['dia' => $this->today]);
        $row = $this->db->one('SELECT glasses, recorded_by FROM diary_entries WHERE id = ?', [$waterEntry]);
        $this->assertSame(6, (int) $row['glasses']);
        $this->assertSame((int) $other['id'], (int) $row['recorded_by']);
    }

    public function test_comment_appears_for_family(): void
    {
        $req = $this->ask('agua');
        $this->loginAs($this->resp);
        $this->post($this->base() . '/' . $req . '/anotar', ['glasses' => '4'], ['dia' => $this->today]);
        $entry = (int) $this->db->one('SELECT id FROM diary_entries')['id'];
        Container::diaryComments()->add($entry, 'Muito bem, continue assim', $this->med, new Request('POST', '/'));

        $body = $this->get($this->base())->body;
        $this->assertStringContainsString('Comentário de ' . $this->med['name'] . ' em ' . date('d/m/Y') . ': Muito bem, continue assim', $body);
    }

    public function test_medidas_request_links_to_measure_form(): void
    {
        $req = $this->ask('medidas');
        $this->loginAs($this->resp);
        $page = $this->get($this->base());
        $this->assertStringContainsString('Pesar hoje', $page->body);
        $this->assertStringContainsString('/portal/p/' . $this->pid . '/medidas/nova', $page->body);
        $this->assertStringNotContainsString('Peso anotado', $page->body);
        $this->assertSame(404, $this->get($this->base() . '/' . $req . '/anotar')->status);

        $this->db->insert('measurements', [
            'patient_id' => $this->pid, 'measured_on' => $this->today, 'weight_kg' => 28.4, 'source' => 'familia', 'created_by' => $this->resp['id'],
        ]);
        $done = $this->get($this->base())->body;
        $this->assertStringContainsString('Peso anotado ✔', $done);
        $this->assertStringNotContainsString('Pesar hoje', $done);
    }

    public function test_child_account_is_redirected_from_diary(): void
    {
        $req = $this->ask('agua');
        $u = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$u['id'], $this->pid]);
        $this->loginAs($u);
        $res = $this->get($this->base());
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $this->pid . '/metas', $res->headers['Location']);
        $this->assertSame(302, $this->get($this->base() . '/' . $req . '/anotar')->status);
    }

    public function test_adult_patient_sees_secret_request(): void
    {
        $u = $this->makeUser('paciente');
        $p = $this->makePatient([
            'full_name' => 'Joao Adulto', 'birth_date' => (date('Y') - 30) . '-03-10', 'sex' => 'M',
            'responsible_professional_id' => $this->med['id'], 'created_by' => $this->med['id'], 'user_id' => $u['id'],
        ]);
        $pid = (int) $p['id'];
        $req = $this->ask('agua', ['guidance' => 'Pedido antigo sigiloso'], $pid);
        $this->db->run('UPDATE diary_requests SET secret = 1 WHERE id = ?', [$req]);
        $this->loginAs($u);
        $this->assertStringContainsString('Diário', $this->get('/portal/p/' . $pid)->body);
        $page = $this->get('/portal/p/' . $pid . '/diario');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Pedido antigo sigiloso', $page->body);
        $this->assertSame(302, $this->post('/portal/p/' . $pid . '/diario/' . $req . '/anotar', ['glasses' => '3'], ['dia' => $this->today])->status);
    }
}
