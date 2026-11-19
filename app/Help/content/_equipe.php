<?php
declare(strict_types=1);

use App\Help\Manual;

/**
 * Secoes da equipe (medica, nutricionista, secretaria). Recebe o slug do manual que as inclui e o login
 * usado nas fotos, porque o menu e os cartoes mudam de perfil para perfil.
 * Atencao: o manual da nutricionista nao pode citar medicamentos, exames, evolucao nem receita
 * (HelpManualTest) — por isso o texto de algumas secoes muda conforme $as.
 * @return array<string, array>
 */
return static function (string $slug, string $as): array {
    $img = static fn (string $name, string $url, string $caption, ?string $login = null): array
        => Manual::img($slug . '/' . $name . '.jpg', $url, $login ?? $as, 'desktop', $caption);
    $nutri = $as === 'nutricionista';

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
        'pacientes' => [
            'id' => 'pacientes',
            'title' => 'Encontrar um paciente',
            'intro' => $nutri
                ? 'A lista mostra só os pacientes que a médica liberou para você.'
                : 'Clique em "Pacientes" no menu de cima para ver a lista.',
            'steps' => [
                'Clique em "Pacientes" no menu.',
                'Para procurar, escreva parte do nome (ou o CPF) em "Buscar por nome ou CPF" e clique em "Buscar".',
                'Clique no nome do paciente para abrir a ficha.',
            ],
            'images' => [$img('pacientes', '/pacientes', 'Lista de pacientes.')],
        ],
        'ficha' => [
            'id' => 'ficha',
            'title' => 'A ficha do paciente',
            'intro' => $as === 'secretaria'
                ? 'Na ficha você vê o cadastro, os responsáveis e as consultas do paciente.'
                : 'A ficha reúne, em cartões, um resumo de cada parte do acompanhamento. Cada cartão tem um link para a tela completa.',
            'steps' => $as === 'secretaria' ? [
                'Abra o paciente pela lista.',
                'No cartão "Cadastro" estão os dados e os responsáveis; use "Editar cadastro" ou "Responsáveis" no topo para mudar.',
                'No cartão "Consultas" estão as últimas consultas; "Agendar" abre os horários livres.',
            ] : [
                'Abra o paciente pela lista.',
                'Leia o resumo de cada cartão (crescimento, meta de peso, plano alimentar, metas, diário, consultas…).',
                'Clique no link do cartão (por exemplo "Ver crescimento") para abrir a tela completa.',
                'O link "Linha do tempo", no topo, mostra tudo o que aconteceu com o paciente em ordem de data.',
            ],
            'images' => [$img('ficha', '/pacientes/{crianca}', 'Ficha de uma paciente.')],
        ],
        'cadastrar' => [
            'id' => 'cadastrar',
            'title' => 'Cadastrar um paciente novo',
            'steps' => [
                'Em "Pacientes", clique em "Novo paciente".',
                'Preencha "Nome completo", "Data de nascimento", "Sexo biológico" e "Telefone". Os campos marcados como opcionais podem ficar em branco.',
                'Escolha o "Profissional responsável" e clique em "Salvar".',
            ],
            'tip' => 'O sexo biológico e a data de nascimento são usados nas curvas de crescimento: confira antes de salvar.',
            'images' => [$img('cadastrar', '/pacientes/novo', 'Formulário de novo paciente.')],
        ],
        'responsaveis' => [
            'id' => 'responsaveis',
            'title' => 'Responsáveis e acesso da família',
            'intro' => 'Cada responsável cadastrado recebe por e-mail um convite para entrar no portal da família.',
            'steps' => [
                'Na ficha do paciente, clique em "Responsáveis".',
                'Em "Adicionar responsável", preencha "Nome", "E-mail", "Celular" (opcional) e "Parentesco".',
                'Clique em "Adicionar". O convite sai na hora.',
                'Para trocar o celular, use "Editar celular"; para tirar o acesso, "Remover".',
            ],
            'tip' => 'O celular é usado pelo botão de WhatsApp da agenda.',
            'images' => [$img('responsaveis', '/pacientes/{crianca}/responsaveis', 'Tela de responsáveis.')],
        ],
        'agenda' => [
            'id' => 'agenda',
            'title' => 'Agenda: ver o dia e marcar consultas',
            'steps' => [
                'Clique em "Agenda" no menu. Em "Profissional" escolha de quem é a agenda e clique em "Ver".',
                'Use "Dia anterior", "Hoje", "Próximo dia" ou "Ver semana" para navegar.',
                'Num horário livre, clique em "Marcar", busque o paciente pelo nome e confirme.',
                'Também dá para marcar pela ficha: no cartão "Consultas", clique em "Agendar" e escolha um dos próximos horários livres.',
                'Clique numa consulta para marcar "Realizada" ou "Faltou", "Remarcar" ou cancelar (com o motivo).',
            ],
            'tip' => 'O botão "WhatsApp — paciente" abre a conversa com a mensagem de lembrete já escrita.',
            'images' => [
                $img('agenda', '/agenda', 'Agenda do dia.'),
                $img('consulta', '/agenda/consultas/{consulta}', 'Detalhe de uma consulta.'),
            ],
        ],
        'horarios' => [
            'id' => 'horarios',
            'title' => 'Horários de atendimento',
            'intro' => $as === 'secretaria'
                ? 'Você pode ajustar os horários de cada profissional.'
                : 'Os horários livres da agenda saem da sua semana-modelo.',
            'steps' => [
                'Na agenda, clique em "Meus horários" (ou "Horários de…" da profissional escolhida).',
                'Em "Semana-modelo", preencha os períodos de cada dia, a "Duração da consulta" e o "Endereço de atendimento"; clique em "Salvar horários".',
                'Para férias ou um dia sem atendimento, use "Novo fechamento" e clique em "Lançar fechamento".',
                'Para atender num dia fora do normal, use "Novo horário extra" e clique em "Lançar horário extra".',
            ],
            'images' => [$img('horarios', '/agenda/horarios', 'Semana-modelo e fechamentos.')],
        ],
        'ditado' => [
            'id' => 'ditado',
            'title' => 'Ditado por voz',
            'intro' => 'Os campos de texto têm um botão 🎤 ao lado (no Chrome, Edge e Safari).',
            'steps' => [
                'Clique no 🎤 ao lado do campo e fale.',
                'Diga "vírgula", "ponto", "dois pontos" ou "nova linha" para a pontuação.',
                'Clique em ■ para parar. O texto entra no fim do campo.',
                'Revise o texto e clique no botão de salvar da tela: o ditado sozinho não salva nada.',
            ],
            'tip' => 'O reconhecimento é do navegador: o áudio vai para o Google, a Microsoft ou a Apple. Se o navegador pedir, permita o microfone.',
            'images' => [$img('ditado', match ($as) {
                'medico' => '/pacientes/{crianca}/prontuario/evolucao',
                'nutricionista' => '/pacientes/{crianca}/metas/nova',
                default => '/pacientes/{crianca}/editar',
            }, 'Campos com o botão de ditado.')],
        ],
    ];
};
