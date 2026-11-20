<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class EduCardTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function post(string $path, array $data = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function card(array $bands, string $title, bool $publish = true): int
    {
        $med = $this->makeUser('medico');
        $cards = Container::eduCards();
        $id = $cards->save(null, [
            'title' => $title, 'body' => 'Texto do cartão ' . $title . '.', 'emoji' => '🍎', 'bands' => $bands, 'sort_order' => '0',
        ], (int) $med['id'], new Request('POST', '/'));
        if ($publish) {
            $cards->publish($id, (int) $med['id'], new Request('POST', '/'));
        }
        return $id;
    }

    public function test_only_doctor_manages_cards(): void
    {
        $med = $this->makeUser('medico', ['totp_enabled' => 1]);
        foreach (['nutricionista', 'secretaria', 'responsavel', 'paciente'] as $role) {
            $this->loginAs($this->makeUser($role, ['totp_enabled' => 1]));
            $this->assertSame(403, $this->get('/cartoes')->status, $role);
            $this->assertSame(403, $this->get('/cartoes/novo')->status, $role);
            $this->assertSame(403, $this->get('/cartoes/1/editar')->status, $role);
            $this->assertSame(403, $this->post('/cartoes/1/publicar')->status, $role);
            $this->assertSame(403, $this->post('/cartoes/1/editar', ['title' => 'Mudado por outro'])->status, $role);
        }
        $this->assertSame('rascunho', Container::eduCards()->find(1)['status']);

        $this->loginAs($med);
        $res = $this->get('/cartoes');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Cartões educativos', $res->body);
        $this->assertStringContainsString('Rascunho', $res->body);
        $this->assertSame(404, $this->get('/cartoes/99999/editar')->status);

        $res = $this->post('/cartoes/novo', ['title' => 'x', 'body' => '', 'emoji' => '', 'sort_order' => '0']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('aviso-erro', $res->body);

        $res = $this->post('/cartoes/novo', ['title' => 'Hora de brincar', 'body' => 'Brincar faz bem todos os dias.', 'emoji' => '⚽', 'bands' => ['7-10'], 'sort_order' => '3']);
        $this->assertSame(302, $res->status);
        $id = (int) $this->db->one("SELECT id FROM edu_cards WHERE title = 'Hora de brincar'")['id'];
        $this->assertSame('rascunho', Container::eduCards()->find($id)['status']);

        $res = $this->post('/cartoes/' . $id . '/editar', ['title' => 'Hora de brincar!', 'body' => 'Brincar faz bem todos os dias.', 'emoji' => '⚽', 'bands' => ['7-10', '11-14'], 'sort_order' => '3']);
        $this->assertSame(302, $res->status);
        $this->assertSame('7-10,11-14', Container::eduCards()->find($id)['bands']);

        $this->assertSame(302, $this->post('/cartoes/' . $id . '/publicar')->status);
        $this->assertSame('publicado', Container::eduCards()->find($id)['status']);
        $this->assertSame(302, $this->post('/cartoes/' . $id . '/despublicar')->status);
        $this->assertSame('rascunho', Container::eduCards()->find($id)['status']);
    }

    public function test_child_reads_published_card_of_own_band_only(): void
    {
        $med = $this->makeUser('medico', ['totp_enabled' => 1]);
        $kid = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $p = $this->makePatient([
            'full_name' => 'Ana Souza', 'birth_date' => (date('Y') - 8) . '-01-01', 'sex' => 'F',
            'responsible_professional_id' => $med['id'], 'created_by' => $med['id'], 'child_user_id' => $kid['id'],
        ]);
        $other = $this->makePatient(['birth_date' => (date('Y') - 8) . '-01-01', 'sex' => 'M']);
        $pid = (int) $p['id'];
        $mine = $this->card(['7-10'], 'Cartão da faixa dela');
        $teen = $this->card(['15-19'], 'Cartão de adolescente');
        $draft = $this->card(['7-10'], 'Cartão em rascunho', false);

        $this->loginAs($kid);
        $res = $this->get('/portal/p/' . $pid . '/metas');
        $this->assertStringContainsString('Para você ler', $res->body);
        $this->assertStringContainsString('/portal/p/' . $pid . '/cartoes/' . $mine, $res->body);
        $this->assertStringNotContainsString('Cartão de adolescente', $res->body);
        $this->assertStringNotContainsString('Cartão em rascunho', $res->body);

        $res = $this->get('/portal/p/' . $pid . '/cartoes/' . $mine);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Texto do cartão Cartão da faixa dela.', $res->body);
        $this->assertStringContainsString('/portal/p/' . $pid . '/metas', $res->body);
        $this->assertStringNotContainsString('href="/portal/p/' . $pid . '"', $res->body, 'criança só volta para as metas');

        $this->assertSame(404, $this->get('/portal/p/' . $pid . '/cartoes/' . $teen)->status);
        $this->assertSame(404, $this->get('/portal/p/' . $pid . '/cartoes/' . $draft)->status);
        $this->assertSame(404, $this->get('/portal/p/' . $pid . '/cartoes/99999')->status);
        $res = $this->get('/portal/p/' . $other['id'] . '/cartoes/' . $mine);
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $pid . '/metas', $res->headers['Location']);

        // Responsavel le o mesmo cartao; equipe volta para o painel.
        $g = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $pid, 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        $this->loginAs($g);
        $this->assertSame(200, $this->get('/portal/p/' . $pid . '/cartoes/' . $mine)->status);
        $this->loginAs($med);
        $this->assertSame(302, $this->get('/portal/p/' . $pid . '/cartoes/' . $mine)->status);
    }
}
