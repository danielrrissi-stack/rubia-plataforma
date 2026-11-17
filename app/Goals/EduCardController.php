<?php
declare(strict_types=1);

namespace App\Goals;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Labs\Clinician;

/** Cartoes educativos: so a medica revisa, edita, publica e despublica. Ordem: papel -> 404. */
final class EduCardController
{
    /** GET /cartoes */
    public function index(Request $r): Response
    {
        $this->doctor($r);
        return $this->page('Goals/views/cards_index.php', 'Cartões educativos', $r, ['cards' => Container::eduCards()->all()]);
    }

    /** GET /cartoes/novo */
    public function createForm(Request $r): Response
    {
        $this->doctor($r);
        return $this->form($r, null, ['title' => '', 'body' => '', 'emoji' => '', 'bands' => [], 'sort_order' => '0'], []);
    }

    /** POST /cartoes/novo */
    public function create(Request $r): Response
    {
        $user = $this->doctor($r);
        $data = $this->input($r);
        try {
            Container::eduCards()->save(null, $data, (int) $user['id'], $r);
        } catch (InvalidGoalException $e) {
            return $this->form($r, null, $data, $e->errors);
        }
        Session::flash('ok', 'Cartão criado como rascunho.');
        return Response::redirect('/cartoes');
    }

    /** GET /cartoes/{cardId}/editar */
    public function editForm(Request $r): Response
    {
        $this->doctor($r);
        $card = $this->card($r);
        return $this->form($r, $card, ['bands' => explode(',', (string) $card['bands'])] + $card, []);
    }

    /** POST /cartoes/{cardId}/editar */
    public function update(Request $r): Response
    {
        $user = $this->doctor($r);
        $card = $this->card($r);
        $data = $this->input($r);
        try {
            Container::eduCards()->save((int) $card['id'], $data, (int) $user['id'], $r);
        } catch (InvalidGoalException $e) {
            return $this->form($r, $card, $data, $e->errors);
        }
        Session::flash('ok', 'Cartão salvo.');
        return Response::redirect('/cartoes');
    }

    /** POST /cartoes/{cardId}/publicar */
    public function publish(Request $r): Response
    {
        $user = $this->doctor($r);
        $card = $this->card($r);
        Container::eduCards()->publish((int) $card['id'], (int) $user['id'], $r);
        Session::flash('ok', 'Cartão publicado: já aparece no portal para a faixa etária.');
        return Response::redirect('/cartoes');
    }

    /** POST /cartoes/{cardId}/despublicar */
    public function unpublish(Request $r): Response
    {
        $user = $this->doctor($r);
        $card = $this->card($r);
        Container::eduCards()->unpublish((int) $card['id'], (int) $user['id'], $r);
        Session::flash('ok', 'Cartão voltou para rascunho e saiu do portal.');
        return Response::redirect('/cartoes');
    }

    private function doctor(Request $r): array
    {
        $user = $r->attributes['user'];
        if (!Clinician::is($user)) {
            Container::audit()->record(
                isset($user['id']) ? (int) $user['id'] : null, 'denied', 'edu_cards', null, null, null,
                ['acao' => 'equipe', 'section' => 'cartoes'], $r,
            );
            throw new HttpException(403, 'Acesso negado');
        }
        return $user;
    }

    private function card(Request $r): array
    {
        $card = Container::eduCards()->find((int) $r->params['cardId']);
        if ($card === null) {
            throw new HttpException(404);
        }
        return $card;
    }

    private function input(Request $r): array
    {
        $bands = $r->input('bands', []);
        return [
            'title' => (string) $r->input('title', ''),
            'body' => (string) $r->input('body', ''),
            'emoji' => (string) $r->input('emoji', ''),
            'bands' => is_array($bands) ? array_values(array_filter($bands, 'is_string')) : [],
            'sort_order' => (string) $r->input('sort_order', '0'),
        ];
    }

    private function form(Request $r, ?array $card, array $values, array $errors): Response
    {
        return $this->page('Goals/views/card_form.php', $card === null ? 'Novo cartão' : 'Editar cartão', $r, [
            'card' => $card, 'values' => $values, 'errors' => $errors,
        ]);
    }

    private function page(string $template, string $title, Request $r, array $data): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => '/cartoes',
        ], 'Layout/views/layout.php'));
    }
}
