<?php
declare(strict_types=1);

use App\Help\Manual;

/**
 * Secoes do portal da familia (responsavel e paciente adulto). $voce = true fala com o paciente adulto
 * ("seus dados"); false fala com o responsavel ("a criança"). Linguagem simples, sem termos clinicos.
 * @return array<string, array>
 */
return static function (string $slug, string $as, bool $voce): array {
    $p = $voce ? '{adulto}' : '{crianca}';
    $img = static fn (string $name, string $url, string $caption): array
        => Manual::img($slug . '/' . $name . '.jpg', $url, $as, 'mobile', $caption);

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
            'tip' => 'Dá para usar no celular como um aplicativo: no navegador, escolha "Adicionar à tela inicial".',
            'images' => [
                Manual::img($slug . '/entrar-login.jpg', '/login', null, 'mobile', 'Tela de entrada no celular.'),
            ],
        ],
        'escolher' => [
            'id' => 'escolher',
            'title' => 'Escolher a criança',
            'intro' => 'Se você cuida de mais de uma criança acompanhada pela clínica, o portal pergunta quem você quer acompanhar.',
            'steps' => [
                'Toque no nome da criança.',
                'Para mudar depois, toque em "Trocar de pessoa", no fim da página.',
            ],
            'images' => [$img('escolher', '/portal', 'Escolha da criança.')],
        ],
        'inicio' => [
            'id' => 'inicio',
            'title' => 'Página inicial',
            'intro' => 'A página inicial tem um atalho para cada parte do acompanhamento e mostra as consultas marcadas.',
            'steps' => [
                'Toque no atalho que quiser: metas, crescimento, alimentação, exames, tratamento ou diário (só aparecem os que a equipe liberou).',
                'Em "Consultas" você vê as próximas consultas.',
                'Para voltar, toque em "Voltar" no alto da tela.',
            ],
            'images' => [$img('inicio', '/portal/p/' . $p, 'Página inicial do portal.')],
        ],
        'pesagem' => [
            'id' => 'pesagem',
            'title' => 'Registrar o peso em casa',
            'intro' => 'Pesar em casa ajuda a equipe a acompanhar entre uma consulta e outra.',
            'steps' => [
                'Na página inicial, toque em "Registrar medida".',
                'Confira a data e escreva o peso (use vírgula: 28,4). Altura e cintura são opcionais.',
                'Toque em "Salvar medida".',
            ],
            'tip' => 'Toque em "Como medir" para ver as dicas: mesma balança, mesmo horário, roupas leves.',
            'images' => [$img('pesagem', '/portal/p/' . $p . '/medidas/nova', 'Registrar uma medida.')],
        ],
        'crescimento' => [
            'id' => 'crescimento',
            'title' => 'Crescimento',
            'steps' => [
                'Na página inicial, toque em "Crescimento".',
                'Veja a última medida e o gráfico: a faixa colorida é o esperado para a idade e os pontos são as medidas.',
            ],
            'images' => [$img('crescimento', '/portal/p/' . $p . '/crescimento', 'Gráfico de crescimento.')],
        ],
        'alimentacao' => [
            'id' => 'alimentacao',
            'title' => 'Plano alimentar',
            'steps' => [
                'Na página inicial, toque em "Alimentação".',
                'Veja as refeições do dia e as orientações.',
                'Para imprimir ou salvar em PDF, toque em "Versão para imprimir".',
            ],
            'images' => [$img('alimentacao', '/portal/p/' . $p . '/alimentacao', 'Plano alimentar no portal.')],
        ],
        'exames' => [
            'id' => 'exames',
            'title' => 'Exames',
            'intro' => 'Só aparecem os exames que a Dra. Rubia liberou.',
            'steps' => [
                'Na página inicial, toque em "Exames".',
                'Veja os resultados de cada coleta. Em caso de dúvida, converse com a médica na próxima consulta.',
            ],
            'images' => [$img('exames', '/portal/p/' . $p . '/exames', 'Exames liberados.')],
        ],
        'tratamento' => [
            'id' => 'tratamento',
            'title' => 'Remédios e doses',
            'steps' => [
                'Na página inicial, toque em "Tratamento" para ver cada remédio, a dose e como usar.',
                'Quando a equipe pedir, registre cada dose: confira a data e a hora e toque em "Registrar dose". Errou? Toque em "Remover".',
                'Se algo diferente acontecer depois de um remédio, toque em "Contar um efeito ou intercorrência", escreva o que aconteceu e toque em "Enviar relato".',
            ],
            'tip' => 'O relato não é lido na hora. Em caso de urgência, procure um pronto-socorro.',
            'images' => [$img('tratamento', '/portal/p/' . ($voce ? '{adulto}' : '{adolescente}') . '/tratamento', 'Remédios e registro de doses.')],
        ],
        'metas' => [
            'id' => 'metas',
            'title' => $voce ? 'Minhas metas' : 'Metas da semana',
            'intro' => $voce
                ? 'Cada meta combinada com a equipe aparece com os dias da semana.'
                : 'As metas são combinadas com a equipe. A criança também pode marcar pela conta dela, se tiver uma.',
            'steps' => [
                'Na página inicial, toque nas metas.',
                'Toque no dia em que a meta foi cumprida: aparece um ✓. Tocou errado? Toque de novo para desmarcar.',
                'Cada marcação vale uma estrela ⭐. As conquistas aparecem embaixo.',
                'Toque num cartão em "Para você ler" para ver uma dica da equipe.',
            ],
            'images' => [$img('metas', '/portal/p/' . $p . '/metas', 'Metas da semana com os dias marcados.')],
        ],
        'diario' => [
            'id' => 'diario',
            'title' => 'Diário',
            'intro' => 'Quando a equipe pede, você anota no diário o que ' . ($voce ? 'você' : 'a criança') . ' comeu, bebeu, dormiu ou brincou.',
            'steps' => [
                'Na página inicial, toque em "Diário".',
                'Escolha o dia no alto (hoje, ontem ou um dos dias da semana).',
                'Toque em "Anotar refeição" (ou no que a equipe pediu), preencha e salve. Dá para pôr uma foto do prato.',
                'Para mudar uma anotação, toque em "Corrigir"; para apagar, "Remover".',
                'Os comentários da equipe aparecem embaixo de cada anotação.',
            ],
            'images' => [$img('diario', '/portal/p/' . $p . '/diario', 'Diário com uma anotação e o comentário da equipe.')],
        ],
        'conta' => [
            'id' => 'conta',
            'title' => 'Minha conta',
            'steps' => [
                'No fim da página inicial, toque em "Minha conta".',
                'Para trocar a senha, escreva a senha atual e a nova duas vezes (pelo menos 10 caracteres) e toque em "Trocar senha".',
                'Para mais segurança, ative a verificação em duas etapas: um código no celular a cada entrada.',
            ],
            'tip' => 'Esqueceu a senha? Na tela de entrada, toque em "Esqueci minha senha" e siga o link que chega por e-mail.',
            'images' => [$img('conta', '/portal/conta', 'Minha conta.')],
        ],
    ];
};
