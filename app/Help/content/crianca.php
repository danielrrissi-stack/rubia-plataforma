<?php
declare(strict_types=1);

use App\Help\Manual;

/** Manual da crianca: curto, frases simples, mais imagem que texto. */
return [
    [
        'id' => 'entrar',
        'title' => 'Entrar pela primeira vez',
        'intro' => 'A doutora cria a sua conta e entrega para você um nome de usuário e uma senha provisória.',
        'steps' => [
            'Na tela de entrada, escreva o seu nome de usuário e a senha que a doutora deu.',
            'Toque em "Entrar".',
            'Na primeira vez, o sistema pede para você criar uma senha nova. Escreva a senha nova duas vezes e toque em "Salvar senha".',
            'Pronto! Você já vê as suas metas.',
        ],
        'tip' => 'Guarde a sua senha em segredo. Se esquecer, peça ajuda para a doutora.',
        'images' => [
            Manual::img('crianca/entrar-login.jpg', '/login', null, 'mobile', 'Tela de entrada.'),
        ],
    ],
];
