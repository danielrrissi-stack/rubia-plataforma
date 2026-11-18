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
use App\Diary\DiaryWeek;
use Tests\DbTestCase;

final class DiaryWeekPagesTest extends DbTestCase
{
    private string $dir;
    private array $med;
    private array $nutri;
    private array $resp;
    private array $patient;
    private int $pid;
    private string $today;
    private string $monday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/rubia_dw_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/uploads', 0777, true);
        Container::set('diaryPhotos', new DiaryPhotoStore($this->dir . '/uploads', true));
        $this->today = date('Y-m-d');
        $this->monday = DiaryWeek::monday($this->today);
        $this->med = $this->makeUser('medico', ['totp_enabled' => 1]);
        $this->nutri = $this->makeUser('nutricionista', ['totp_enabled' => 1]);
        $this->resp = $this->makeUser('responsavel');
        $this->patient = $this->makePatient(['birth_date' => '2015-05-10', 'responsible_professional_id' => $this->med['id'], 'created_by' => $this->med['id']]);
        $this->pid = (int) $this->patient['id'];
        $this->db->insert('guardian_links', ['patient_id' => $this->pid, 'user_id' => $this->resp['id'], 'relationship' => 'mãe', 'created_by' => $this->med['id']]);
        Container::accessGrants()->grant($this->pid, (int) $this->nutri['id'], (int) $this->med['id']);
        $this->loginAs($this->med);
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

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function request(string $type, array $over = []): int
    {
        return $this->db->insert('diary_requests', $over + [
            'patient_id' => $this->pid, 'type' => $type, 'weekdays' => 127,
            'start_date' => date('Y-m-d', strtotime('-20 days')), 'created_by' => $this->med['id'],
        ]);
    }

    private function food(int $req, array $over = []): int
    {
        return $this->db->insert('diary_entries', $over + [
            'request_id' => $req, 'day' => $this->today, 'meal' => 'almoco', 'meal_time' => '12:00:00',
            'description' => 'arroz e feijao da familia', 'recorded_by' => $this->resp['id'], 'recorded_by_role' => 'responsavel',
        ]);
    }

    private function plan(string $mode = 'estruturado'): int
    {
        $item = [
            'food_id' => 1, 'food_name' => 'Arroz', 'qty' => 2, 'measure_id' => 1, 'measure_name' => 'colher', 'measure_plural' => 'colheres',
            'grams' => 100.0, 'per100' => ['kcal' => 128.0, 'protein' => 2.5, 'carb' => 25.0, 'fat' => 0.2, 'fiber' => 1.6],
            'note' => null, 'subs' => [],
        ];
        $structure = ['v' => 1, 'meals' => [['name' => 'Refeicao do plano XYZ', 'time' => '12:00', 'note' => null, 'options' => [['items' => [$item]]]]]];
        $id = Container::mealPlans()->createDraft($this->pid, $mode, [
            'title' => 'Plano', 'body_text' => $mode === 'texto' ? 'Texto do plano ABC' : null, 'guidance_text' => null,
            'structure' => $mode === 'texto' ? null : $structure,
        ], (int) $this->med['id']);
        Container::mealPlans()->publish($id, (int) $this->med['id']);
        $this->db->run('UPDATE meal_plans SET published_at = ? WHERE id = ?', [date('Y-m-d', strtotime('-10 days')) . ' 08:00:00', $id]);
        return $id;
    }

    private function png(): array
    {
        $tmp = $this->dir . '/in_' . bin2hex(random_bytes(3)) . '.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        file_put_contents($tmp, $png);
        return ['name' => 'f.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)];
    }

    private function photoEntry(int $req): int
    {
        return Container::diaryEntries()->record(
            $req, $this->today, ['meal' => 'almoco', 'meal_time' => '12:00', 'description' => 'prato'], $this->png(),
            $this->resp, $this->today, new Request('POST', '/'),
        );
    }

    private function week(): string
    {
        return '/pacientes/' . $this->pid . '/diario';
    }

    public function test_week_page_shows_grid_and_plan_side_by_side(): void
    {
        $req = $this->request('alimentacao');
        $this->food($req);
        $this->plan();
        $body = $this->get($this->week())->body;
        $this->assertStringContainsString('Semana de ' . date('d/m', strtotime($this->monday)) . ' a ' . date('d/m', strtotime($this->monday . ' +6 days')), $body);
        $this->assertStringContainsString('Semana anterior', $body);
        $this->assertStringContainsString('Próxima semana', $body);
        $this->assertStringContainsString('tabela-rolagem', $body);
        $this->assertStringContainsString('1 refeição', $body);
        $this->assertStringContainsString('Anotado pela família', $body);
        $this->assertStringContainsString('arroz e feijao da familia', $body);
        $this->assertStringContainsString('Plano alimentar', $body);
        $this->assertStringContainsString('Refeicao do plano XYZ', $body);
        $this->assertStringContainsString('Pedidos de registro', $body);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action = 'view' AND entity = 'diary'")['n']);
    }

    public function test_text_plan_and_missing_plan(): void
    {
        $this->food($this->request('alimentacao'));
        $this->assertStringContainsString('Sem plano alimentar publicado neste dia.', $this->get($this->week())->body);
        $this->plan('texto');
        $this->assertStringContainsString('Texto do plano ABC', $this->get($this->week())->body);
    }

    public function test_invalid_week_param_falls_back_to_current(): void
    {
        $this->request('agua');
        $cur = 'Semana de ' . date('d/m', strtotime($this->monday));
        $this->assertStringContainsString($cur, $this->get($this->week(), ['semana' => 'lixo'])->body);
        $this->assertStringContainsString($cur, $this->get($this->week(), ['semana' => '2026-02-30'])->body);
        $this->assertStringContainsString($cur, $this->get($this->week())->body);
        $this->assertStringContainsString('Semana de 02/11 a 08/11', $this->get($this->week(), ['semana' => '2026-11-05'])->body);
    }

    public function test_comment_flow_and_redirect_anchor(): void
    {
        $e = $this->food($this->request('alimentacao'));
        $anchor = $this->week() . '?semana=' . $this->monday . '#dia-' . $this->today;
        $res = $this->post('/diario/registros/' . $e . '/comentar', ['body' => 'Muito bom!']);
        $this->assertSame(302, $res->status);
        $this->assertSame($anchor, $res->headers['Location']);
        $cid = (int) $this->db->one('SELECT id FROM diary_comments WHERE entry_id = ?', [$e])['id'];
        $page = $this->get($this->week())->body;
        $this->assertStringContainsString('Muito bom!', $page);
        $this->assertStringContainsString('id="dia-' . $this->today . '"', $page);

        $res = $this->post('/diario/comentarios/' . $cid . '/editar', ['body' => 'Otimo!']);
        $this->assertSame($anchor, $res->headers['Location']);
        $this->assertSame('Otimo!', $this->db->one('SELECT body FROM diary_comments WHERE id = ?', [$cid])['body']);

        $this->post('/diario/registros/' . $e . '/comentar', ['body' => '']);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM diary_comments WHERE deleted_at IS NULL')['n']);

        $res = $this->post('/diario/comentarios/' . $cid . '/excluir', []);
        $this->assertSame($anchor, $res->headers['Location']);
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM diary_comments WHERE id = ?', [$cid])['deleted_at']);

        $this->loginAs($this->resp);
        $this->assertSame(403, $this->post('/diario/registros/' . $e . '/comentar', ['body' => 'x'])->status);
    }

    public function test_comment_on_invisible_entry_is_404(): void
    {
        $secret = $this->food($this->request('alimentacao', ['secret' => 1]));
        $sono = $this->food($this->request('sono'), ['meal' => null, 'description' => null]);
        $this->loginAs($this->nutri);
        $this->assertSame(404, $this->post('/diario/registros/' . $secret . '/comentar', ['body' => 'x'])->status);
        $this->assertSame(404, $this->post('/diario/registros/' . $sono . '/comentar', ['body' => 'x'])->status);
        $this->assertSame(404, $this->post('/diario/registros/999999/comentar', ['body' => 'x'])->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM diary_comments')['n']);
    }

    public function test_photo_route_visibility(): void
    {
        $req = $this->request('alimentacao');
        $e = $this->photoEntry($req);
        $this->assertSame(200, $this->get('/diario/registros/' . $e . '/foto')->status);
        $this->loginAs($this->nutri);
        $this->assertSame(200, $this->get('/diario/registros/' . $e . '/foto')->status);
        $this->loginAs($this->resp);
        $this->assertSame(403, $this->get('/diario/registros/' . $e . '/foto')->status);
        $this->loginAs($this->med);
        $this->assertSame(404, $this->get('/diario/registros/999999/foto')->status);
        $noPhoto = $this->food($req);
        $this->assertSame(404, $this->get('/diario/registros/' . $noPhoto . '/foto')->status);
        $this->db->run('UPDATE diary_entries SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$this->med['id'], $e]);
        $this->assertSame(404, $this->get('/diario/registros/' . $e . '/foto')->status);
    }

    public function test_photo_of_secret_request_is_404_for_nutritionist(): void
    {
        $req = $this->request('alimentacao');
        $e = $this->photoEntry($req);
        $this->db->run('UPDATE diary_requests SET secret = 1 WHERE id = ?', [$req]);
        $this->loginAs($this->nutri);
        $this->assertSame(404, $this->get('/diario/registros/' . $e . '/foto')->status);
        $this->assertStringNotContainsString('prato', $this->get($this->week())->body);
    }

    public function test_nutritionist_without_grant_cannot_open_photo(): void
    {
        $other = $this->makeUser('nutricionista', ['totp_enabled' => 1]);
        $e = $this->photoEntry($this->request('alimentacao'));
        $this->loginAs($other);
        $this->assertSame(403, $this->get('/diario/registros/' . $e . '/foto')->status);
        $this->assertSame(403, $this->get($this->week())->status);
    }

    public function test_page_never_shows_calories_from_diary(): void
    {
        $this->food($this->request('alimentacao'));
        $this->plan();
        $body = $this->get($this->week())->body;
        $this->assertStringContainsString('kcal', $body); // o plano, ao lado, mostra os valores dele
        $a = strpos($body, 'Anotado pela família');
        $b = strpos($body, 'Plano alimentar', (int) $a);
        $this->assertNotFalse($a);
        $this->assertNotFalse($b);
        $this->assertStringNotContainsString('kcal', substr($body, $a, $b - $a));
    }

    public function test_patient_card_shows_seven_day_count(): void
    {
        $this->food($this->request('alimentacao'));
        $this->assertStringContainsString('1 de 7 dias nos últimos 7', $this->get('/pacientes/' . $this->pid)->body);
    }
}
