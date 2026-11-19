<?php
declare(strict_types=1);

use App\Help\Manual;

/**
 * Secoes comuns ao portal da familia (responsavel e paciente adulto). $voce = true troca o texto para o
 * paciente adulto ("seus dados"); false fala com o responsavel ("os dados da criança").
 * @return array<string, array>
 */
return static function (string $slug, string $as, bool $voce): array {
    return [
        'entrar' => [
            'id' => 'entrar',
            'title' => 'Entrar pela primeira vez',
            'intro' => 'A clínica cadastra o seu e-mail e o sistema manda um convite com um link para criar a senha.',
            'steps' => [
                'Abra o e-mail do convite e clique no link.',
                'Na tela "Crie a sua senha", escolha uma senha, repita e clique em "Definir senha".',
                'Entre com seu e-mail e a senha nova e clique em "Entrar".',
                'Na primeira vez aparece o termo de consentimento. Leia, marque "Li e aceito" e clique em "Confirmar".',
                $voce ? 'Pronto: você vê a página inicial com os seus dados.' : 'Pronto: você vê a página inicial com os dados da criança.',
            ],
            'tip' => 'Para mais segurança, você pode ativar o código de verificação no celular em "Minha conta".',
            'images' => [
                Manual::img($slug . '/entrar-login.jpg', '/login', null, 'mobile', 'Tela de entrada no celular.'),
            ],
        ],
    ];
};
