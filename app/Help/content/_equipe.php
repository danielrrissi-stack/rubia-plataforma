<?php
declare(strict_types=1);

use App\Help\Manual;

/**
 * Secoes comuns a equipe (medica, nutricionista, secretaria). Recebe o slug do manual que as inclui,
 * porque as fotos sao tiradas com o login daquele perfil (o menu muda).
 * @return array<string, array>
 */
return static function (string $slug, string $as): array {
    return [
        'entrar' => [
            'id' => 'entrar',
            'title' => 'Entrar pela primeira vez',
            'intro' => 'Você recebe um convite por e-mail com o assunto "Convite — Plataforma Dra. Rubia". O link vale por 72 horas.',
            'steps' => [
                'Abra o e-mail do convite e clique no link.',
                'Na tela "Crie a sua senha", escolha uma senha, repita no campo de confirmação e clique em "Definir senha".',
                'Entre com seu e-mail e a senha nova na tela de entrada e clique em "Entrar".',
                'Na primeira entrada o sistema pede a verificação em duas etapas: instale no celular um aplicativo autenticador (Google Authenticator, Microsoft Authenticator ou Authy) e leia o QR code da tela.',
                'Digite o código de 6 dígitos que aparece no aplicativo e clique em "Ativar".',
                'Nas próximas vezes, depois da senha, digite o código do aplicativo e clique em "Verificar".',
            ],
            'tip' => 'Esqueceu a senha? Clique em "Esqueci minha senha" na tela de entrada. Trocou de celular e perdeu o aplicativo? Peça à administradora para redefinir a verificação em duas etapas.',
            'images' => [
                Manual::img($slug . '/entrar-login.jpg', '/login', null, 'desktop', 'Tela de entrada.'),
                Manual::img($slug . '/entrar-2fa.jpg', '/2fa/configurar', 'novo', 'desktop', 'Ativação da verificação em duas etapas.'),
            ],
        ],
    ];
};
