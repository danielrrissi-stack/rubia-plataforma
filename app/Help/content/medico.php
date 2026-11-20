<?php
declare(strict_types=1);

use App\Help\Manual;

/** Manual da medica: tudo o que a equipe ve, mais prontuario, exames, tratamento, evolucao e administracao. */
$e = (require __DIR__ . '/_equipe.php')('medica', 'medico');
$c = (require __DIR__ . '/_clinica.php')('medica', 'medico');
$img = static fn (string $name, string $url, string $caption): array
    => Manual::img('medica/' . $name . '.jpg', $url, 'medico', 'desktop', $caption);

return [
    $e['entrar'],
    $e['pacientes'],
    $e['ficha'],
    $e['cadastrar'],
    $e['responsaveis'],
    [
        'id' => 'conta-crianca',
        'title' => 'Conta própria da criança',
        'intro' => 'A criança pode ter uma conta só dela, com nome de usuário, para marcar as próprias metas. Ela não usa código de segurança.',
        'steps' => [
            'Na ficha, clique em "Conta da criança".',
            'Escolha o "Nome de usuário" e clique em "Criar conta". O sistema mostra uma senha provisória: anote e entregue à criança.',
            'Na primeira entrada a criança troca a senha.',
            'Esqueceu a senha? Use "Redefinir senha (gera nova provisória)". Para tirar o acesso, "Desativar conta".',
            'Em "Sigilo", escolha se os responsáveis veem as marcações feitas pela própria criança.',
        ],
        'images' => [$img('conta-crianca', '/pacientes/{crianca}/acesso-crianca', 'Conta da criança.')],
    ],
    [
        'id' => 'acesso-profissionais',
        'title' => 'Liberar a nutricionista',
        'intro' => 'A nutricionista só vê os pacientes que você liberar, e só as partes de nutrição e acompanhamento (não vê prontuário, medicamentos nem exames).',
        'steps' => [
            'Na ficha, clique em "Acesso de profissionais".',
            'Em "Liberar nutricionista", escolha a profissional e clique em "Liberar acesso".',
            'Para tirar o acesso, clique em "Revogar".',
        ],
        'images' => [$img('acesso-profissionais', '/pacientes/{crianca}/acesso-profissionais', 'Profissionais com acesso à paciente.')],
    ],
    [
        'id' => 'prontuario',
        'title' => 'Prontuário',
        'intro' => 'Histórico (familiar, gestacional, antecedentes, alergias, comorbidades, medicamentos em uso, tratamentos anteriores) e evoluções de cada consulta.',
        'steps' => [
            'Na ficha, no cartão "Prontuário", clique em "Abrir prontuário".',
            'Em cada item do histórico, use "Registrar" (ou "Corrigir" para mudar o que já existe).',
            'Para a consulta do dia, clique em "Nova evolução", preencha a data do atendimento, o CID-10 (opcional) e os campos de anamnese a encaminhamentos, e clique em "Salvar evolução".',
            'Nada se perde: "Ver versões" mostra as versões anteriores; remover pede um motivo.',
        ],
        'images' => [$img('prontuario', '/pacientes/{crianca}/prontuario', 'Prontuário com histórico e evoluções.')],
    ],
    $c['crescimento'],
    $c['energia'],
    $c['plano'],
    $c['modelos'],
    [
        'id' => 'exames',
        'title' => 'Exames laboratoriais',
        'intro' => 'Cada coleta guarda os valores, a classificação pela faixa de referência e os laudos em PDF ou foto.',
        'steps' => [
            'Na ficha, no cartão "Exames", clique em "Ver exames" e depois em "Nova coleta".',
            'Preencha a data da coleta e só os valores que vieram; anexe os laudos se quiser; clique em "Salvar".',
            'Na tela de exames, clique no nome de um exame para ver o gráfico dele ao longo do tempo.',
            'Abra a coleta e clique em "Liberar para a família" quando a família puder ver o resultado ("Recolher" desfaz).',
        ],
        'images' => [
            $img('exames', '/pacientes/{adulto}/exames', 'Exames de um paciente adulto.'),
            $img('coleta', '/exames/{coleta}', 'Uma coleta com resultados e laudos.'),
        ],
    ],
    [
        'id' => 'tratamento',
        'title' => 'Tratamento e medicamentos',
        'steps' => [
            'Na ficha, no cartão "Tratamento", clique em "Ver tratamento" e depois em "Nova prescrição".',
            'Preencha medicamento, via, dose, frequência e início. Marque "A família pode registrar doses dadas" se quiser que a família anote cada dose.',
            'Para mudar a dose, abra o medicamento e clique em "Alterar dose": informe a partir de quando vale e o motivo. O histórico de doses fica guardado.',
            'Para parar, use "Encerrar prescrição" com a data de término.',
            'Efeitos relatados pela família aparecem em "Relatos de efeitos": registre gravidade e conduta e clique em "Ciente".',
        ],
        'images' => [
            $img('tratamento', '/pacientes/{adulto}/tratamento', 'Tratamento com medicamentos ativos e relato da família.'),
            $img('prescricao', '/prescricoes/{receita}', 'Um medicamento com o histórico de doses.'),
        ],
    ],
    [
        'id' => 'receita',
        'title' => 'Receita em PDF',
        'steps' => [
            'Na tela de tratamento, clique em "Receita".',
            'Marque os medicamentos que vão na receita e clique em "Gerar receita". O PDF é baixado pronto para imprimir ou enviar.',
        ],
        'tip' => 'O cabeçalho (nome do consultório, endereço, telefone e cidade) é configurado em "Receita (cabeçalho)" no menu.',
        'images' => [$img('receita', '/pacientes/{adulto}/receita', 'Escolha dos medicamentos da receita.')],
    ],
    [
        'id' => 'atestados',
        'title' => 'Atestados e declarações',
        'intro' => 'Quatro tipos prontos: comparecimento, acompanhante, afastamento e aptidão para atividade física. O sistema preenche nome, CPF, data e o seu cabeçalho; você completa o resto.',
        'steps' => [
            'Na ficha do paciente, no cartão "Atestados", clique em "Novo atestado" e escolha o tipo.',
            'Preencha a data e, conforme o tipo, o horário, os dias de afastamento ou o nome do acompanhante (o primeiro responsável cadastrado já vem sugerido).',
            'Se quiser o CID, digite o código e marque a caixa de autorização do paciente ou responsável. Sem a caixa marcada, o CID não sai.',
            'Clique em "Emitir". Na tela seguinte, clique em "Baixar PDF", imprima, assine e carimbe.',
        ],
        'tip' => 'Todo atestado fica gravado na ficha e na linha do tempo. Emitiu por engano? Use "Anular" com o motivo: ele continua na lista, marcado como anulado. Especialidade e RQE do cabeçalho vêm do seu cadastro na equipe, preenchido pela médica administradora.',
        'images' => [
            $img('atestado-novo', '/pacientes/{adulto}/atestados/novo?tipo=comparecimento', 'Formulário da declaração de comparecimento.'),
            $img('atestado', '/pacientes/{adulto}/atestados/{atestado}', 'Atestado emitido, com o botão para baixar o PDF.'),
        ],
    ],
    [
        'id' => 'evolucao',
        'title' => 'Painel de evolução',
        'intro' => 'Cruza a curva de peso com as doses de cada remédio para mostrar se a dose atual ainda está fazendo efeito.',
        'steps' => [
            'Na ficha, no cartão "Tratamento" (ou na meta de peso), clique em "Evolução".',
            'Escolha o período: 3, 6 ou 12 meses, ou tudo.',
            'O quadro "Efeito da dose atual" avisa se a perda de peso continua ou estabilizou nas últimas 4 semanas.',
            'O gráfico mostra o peso (bolinha vazia = pesagem em casa) e, embaixo, uma faixa por remédio com a dose de cada período.',
            '"Ritmo por dose" mostra quanto o paciente perdeu por semana em cada dose.',
            'Abra "Metabolismo" para ver cintura, pressão e exames no mesmo período.',
            'Em "Critério de \"estabilizou\"", ajuste por paciente o mínimo de perda (padrão 0,2 kg por semana, 1% em 4 semanas, ou 0,05 de escore-z para crianças) e clique em "Salvar critério".',
        ],
        'tip' => 'Logo depois de uma troca de dose o aviso espera pelo menos 14 dias e 3 pesagens antes de concluir.',
        'images' => [$img('evolucao', '/pacientes/{adulto}/evolucao', 'Painel de evolução: peso, doses e aviso de estabilização.')],
    ],
    $c['metas'],
    $c['biblioteca'],
    [
        'id' => 'cartoes',
        'title' => 'Cartões educativos',
        'intro' => 'Textos curtos que aparecem no portal para a família e a criança, conforme a idade.',
        'steps' => [
            'Clique em "Cartões educativos" no menu.',
            'Use "Novo cartão" para escrever um, ou "Revisar" para ajustar um pronto.',
            'Clique em "Publicar" para a família ver ("Despublicar" tira do portal).',
        ],
        'images' => [$img('cartoes', '/cartoes', 'Lista de cartões educativos.')],
    ],
    $c['diario'],
    $e['agenda'],
    $e['horarios'],
    $c['linha-do-tempo'],
    $c['meta-peso'],
    $e['ditado'],
    [
        'id' => 'equipe',
        'admin' => true,
        'title' => 'Equipe',
        'steps' => [
            'Clique em "Equipe" no menu e depois em "Novo usuário".',
            'Preencha nome, e-mail, papel (médico, nutricionista ou secretária) e o registro profissional; marque "Administrador" só para médicas.',
            'Clique em "Salvar". A pessoa recebe o convite por e-mail.',
            'Na lista, "Redefinir 2FA" libera quem perdeu o celular; "Desativar" tira o acesso.',
        ],
        'images' => [$img('equipe', '/equipe', 'Lista da equipe.')],
    ],
    [
        'id' => 'backups',
        'admin' => true,
        'title' => 'Backups',
        'steps' => [
            'Clique em "Backups" no menu.',
            'Clique em "Gerar backup agora" para uma cópia na hora (o sistema também faz cópias automáticas).',
            'Guarde a chave privada ("Baixar a chave privada de backup") num lugar seguro: sem ela a cópia não pode ser aberta.',
        ],
        'images' => [$img('backups', '/admin/backups', 'Tela de backups.')],
    ],
    [
        'id' => 'cabecalho-receita',
        'admin' => true,
        'title' => 'Cabeçalho da receita',
        'steps' => [
            'Clique em "Receita (cabeçalho)" no menu.',
            'Preencha nome do consultório, endereço, telefone e cidade e clique em "Salvar".',
        ],
        'images' => [$img('cabecalho-receita', '/admin/receita', 'Cabeçalho da receita.')],
    ],
    [
        'id' => 'avisos-agenda',
        'admin' => true,
        'title' => 'Textos do aviso de consulta',
        'steps' => [
            'Clique em "Agenda (avisos)" no menu.',
            'Ajuste o texto para paciente adulto e para menor de 18 anos e clique em "Salvar". "Restaurar texto padrão" volta ao original.',
        ],
        'images' => [$img('avisos-agenda', '/admin/agenda', 'Textos do aviso de consulta.')],
    ],
];
