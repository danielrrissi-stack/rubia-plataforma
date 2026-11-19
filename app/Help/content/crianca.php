<?php
declare(strict_types=1);

use App\Help\Manual;

/** Manual da crianca: curto, frases simples, mais imagem que texto. */
$img = static fn (string $name, string $url, string $caption): array
    => Manual::img('crianca/' . $name . '.jpg', $url, 'crianca', 'mobile', $caption);

return [
    [
        'id' => 'entrar',
        'title' => 'Entrar pela primeira vez',
        'intro' => 'A doutora cria a sua conta e entrega para você um nome de usuário e uma senha.',
        'steps' => [
            'Escreva o seu nome de usuário e a senha que a doutora deu.',
            'Toque em "Entrar".',
            'Na primeira vez, crie uma senha nova: escreva duas vezes e toque em "Salvar senha".',
            'Pronto! Você já vê as suas metas.',
        ],
        'tip' => 'A senha é segredo seu. Se esquecer, peça ajuda para a doutora.',
        'images' => [
            Manual::img('crianca/entrar-login.jpg', '/login', null, 'mobile', 'Tela de entrada.'),
        ],
    ],
    [
        'id' => 'metas',
        'title' => 'Minhas metas',
        'intro' => 'As metas são as coisas legais que você combinou com a doutora.',
        'steps' => [
            'Fez a meta hoje? Toque no dia. Aparece um ✓.',
            'Tocou errado? Toque de novo.',
            'Cada ✓ vale uma estrela ⭐. Junte estrelas e ganhe conquistas!',
        ],
        'images' => [$img('metas', '/portal/p/{crianca}/metas', 'Suas metas da semana.')],
    ],
    [
        'id' => 'cartoes',
        'title' => 'Cartões para ler',
        'steps' => [
            'Embaixo das metas, em "Para você ler", toque num cartão.',
            'Leia a dica e toque em "Voltar às metas".',
        ],
        'images' => [$img('cartao', '/portal/p/{crianca}/cartoes/{cartao}', 'Um cartão com uma dica.')],
    ],
    [
        'id' => 'conta',
        'title' => 'Trocar a senha',
        'steps' => [
            'Toque em "Minha conta", no fim da página.',
            'Escreva a senha de agora e a senha nova duas vezes.',
            'Toque em "Trocar senha".',
        ],
        'images' => [$img('conta', '/portal/conta', 'Minha conta.')],
    ],
];
