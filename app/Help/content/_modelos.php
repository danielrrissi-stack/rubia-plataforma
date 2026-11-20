<?php
declare(strict_types=1);

use App\Help\Manual;

/**
 * Secoes detalhadas da aba Modelos (modelo em texto, estruturado, trechos, uso no paciente, permissoes).
 * Usadas no manual da nutricionista: nada aqui pode citar medicamentos, exames, evolucao nem receita.
 * @return list<array>
 */
return static function (string $slug, string $as): array {
    $img = static fn (string $name, string $url, string $caption): array
        => Manual::img($slug . '/' . $name . '.jpg', $url, $as, 'desktop', $caption);

    return [
        [
            'id' => 'modelos',
            'title' => 'Modelos: a aba',
            'intro' => 'A aba "Modelos" guarda planos alimentares prontos e frases prontas, para não começar cada plano do zero. '
                . 'Tudo ali é da equipe: o que uma pessoa cadastra, a outra vê e pode usar. Um modelo não fica ligado a nenhum paciente.',
            'steps' => [
                'Clique em "Modelos" no menu. A lista mostra o título, o modo (Texto ou Estruturado) e o autor de cada modelo.',
                'Use "Novo modelo em texto" ou "Novo modelo estruturado" para criar um modelo do zero.',
                'Ou crie a partir de um plano que já existe: na página do plano, use o cartão "Salvar como modelo".',
                'O botão "Trechos de texto" leva às frases prontas.',
            ],
            'tip' => 'Só a médica e a nutricionista veem esta aba. Secretária e família não têm acesso.',
            'images' => [$img('modelos', '/modelos-plano', 'Lista de modelos de plano.')],
        ],
        [
            'id' => 'modelo-texto',
            'title' => 'Novo modelo em texto',
            'intro' => 'O plano é escrito livremente, como no Word; o sistema só organiza a aparência. É o mais rápido para quem já tem planos prontos em documentos.',
            'steps' => [
                'Em "Modelos", clique em "Novo modelo em texto", dê um título (2 a 150 caracteres) e clique em "Criar e abrir o editor".',
                'No campo "Plano (cole o texto aqui)", cole ou digite o plano. A "Pré-visualização", ao lado, mostra como vai ficar.',
                'Formatação: uma linha terminada em dois-pontos (Café da manhã:) ou toda em maiúsculas (ALMOÇO) vira título de refeição.',
                'Linhas começando com -, *, • ou – viram itens de lista; com 1. ou 1) viram lista numerada; texto entre ** e ** fica em negrito; uma linha em branco separa os blocos.',
                'Os botões acima do campo ajudam: "Título de refeição" insere "Nome da refeição:" para você digitar por cima; "Item" começa uma linha com marcador; "Negrito" envolve o texto selecionado.',
                'Escreva as recomendações gerais no campo "Orientações" e clique em "Salvar rascunho".',
            ],
            'tip' => 'Ao colar do Word, os marcadores viram itens e os espaços a mais são limpos. Cores, fontes e links do Word não são levados.',
            'images' => [$img('modelo-texto', '/modelos-plano/{modelo_texto}/editar', 'Editor do modelo em texto com a pré-visualização.')],
        ],
        [
            'id' => 'modelo-estruturado',
            'title' => 'Novo modelo estruturado',
            'intro' => 'O modelo é montado alimento por alimento, da tabela TACO e dos alimentos da equipe, e o sistema calcula sozinho calorias, macronutrientes e fibras.',
            'steps' => [
                'Em "Modelos", clique em "Novo modelo estruturado", dê um título e clique em "Criar e abrir o editor".',
                'O editor já traz seis refeições: café da manhã, lanche da manhã, almoço, lanche da tarde, jantar e ceia. Renomeie, mude a ordem com ↑ ↓ ou remova.',
                'Em cada refeição, preencha o horário (ex.: 07:30) e, se quiser, uma observação ("fruta à vontade").',
                'Clique em "+ Adicionar item", digite 2 letras ou mais do alimento e escolha na lista (com o mouse, ou com as setas e Enter).',
                'Informe a quantidade e a unidade: gramas ou uma medida caseira (colher, fatia, unidade), que mostra quantos gramas vale.',
                'Use "+ substituto" para oferecer trocas de um alimento, e "Adicionar opção" para um cardápio alternativo da mesma refeição.',
                'Escreva as "Orientações" no fim da tela e clique em "Salvar rascunho".',
            ],
            'tip' => 'O "Total do dia", no topo, soma a Opção 1 de cada refeição; cada opção mostra o próprio total. O rascunho também é salvo sozinho a cada 30 segundos; '
                . 'linhas sem alimento escolhido ficam de fora (o aviso abaixo dos botões diz quais). Limites: 12 refeições, 5 opções por refeição, 30 itens por opção e 10 substitutos por item. '
                . 'Alimento que não está na tabela: cadastre antes em "Alimentos" (os da TACO não podem ser editados).',
            'images' => [$img('modelo-estruturado', '/modelos-plano/{modelo_estruturado}/editar', 'Editor do modelo estruturado com o total do dia.')],
        ],
        [
            'id' => 'trechos',
            'title' => 'Trechos de texto',
            'intro' => 'Trechos são frases ou parágrafos prontos, com nome, que você insere com um clique: hidratação, mastigação, lanche da escola.',
            'steps' => [
                'Em "Modelos", clique em "Trechos de texto" e depois em "Novo trecho".',
                'Preencha o "Título" (2 a 100 caracteres), que é o nome na lista, e o "Texto" (até 5.000 caracteres), que é o que será inserido. Clique em "Salvar".',
                'Para usar: no editor do plano ou do modelo, clique em "Inserir trecho" e escolha o trecho.',
                'No modo texto, escolha "em" qual campo ele vai ("Texto do plano" ou "Orientações"); no estruturado vai para "Orientações".',
                'Clique no ponto do texto onde quer o trecho e depois em "Inserir no cursor".',
            ],
            'tip' => 'O trecho é copiado para o plano: mudar o trecho depois não altera os planos onde ele já foi inserido. O link "Gerenciar trechos" abre a lista.',
            'images' => [$img('trechos', '/trechos', 'Lista de trechos de texto.')],
        ],
        [
            'id' => 'usar-modelo',
            'title' => 'Usar um modelo no plano do paciente',
            'intro' => 'Usar um modelo cria um rascunho de plano com uma cópia dele. Depois disso, plano e modelo seguem separados.',
            'steps' => [
                'Na ficha do paciente, abra o "Plano alimentar".',
                'No cartão "Novo plano", em "Começar de", escolha "Modelo" e selecione o modelo na lista.',
                'Clique em "Criar rascunho". O editor abre com o conteúdo do modelo; o modo (texto ou estruturado) é sempre o do modelo.',
                'Ajuste para o paciente, troque o título se ele for interno ("base 1.400 kcal") e publique quando estiver pronto.',
            ],
            'tip' => 'No modelo estruturado, as calorias são recalculadas com os valores atuais da tabela e o total passa a ser comparado com a meta de energia do paciente. '
                . 'Para publicar, toda refeição precisa de item ou de observação. Ao usar "Salvar como modelo" num plano, o que estiver escrito vai junto: '
                . 'apague do modelo o nome da criança ou outro dado pessoal.',
            'images' => [],
        ],
        [
            'id' => 'dietas-prontas',
            'title' => 'Dietas prontas e "Sugestão da semana"',
            'intro' => 'O sistema traz um banco de 1.664 dietas prontas: uma por semana do ano, com frutas e legumes da estação (sopas no frio, '
                . 'pratos leves no calor), de 100 em 100 kcal. Há duas versões: infantil (3 a 10 anos, 1.000 a 2.000 kcal, com leite no lugar do café e lancheira) '
                . 'e adolescente/adulto (1.000 a 3.000 kcal). Cada dieta tem um nome, como 1500kcalSemana5 ou 1500kcalSemana5Infantil.',
            'steps' => [
                'Na ficha do paciente, abra o "Plano alimentar". Confira primeiro o cartão "Restrições e alergias alimentares": marque lactose, alergia à proteína do leite, ovo, glúten, amendoim, castanhas, peixe, frutos do mar ou soja, e escreva outros alimentos a evitar. Clique em "Salvar restrições".',
                'No cartão "Sugestão da semana", a versão já vem pela idade, as calorias vêm da meta de energia vigente (arredondada para a centena) e a semana é a atual. Troque o que precisar.',
                'Clique em "Gerar sugestão da semana". O sistema cria um rascunho estruturado já adaptado às restrições e abre o editor, com o total comparado à meta.',
                'Leia as mensagens no topo: as adaptações feitas (por exemplo, pão trocado por cuscuz no glúten) e os avisos (alimentos citados em "outros alimentos a evitar"), que você troca à mão.',
                'Avalie, ajuste e clique em "Publicar". A família vê o plano como sempre, sem calorias e sem o nome da dieta.',
                'Para consultar o banco inteiro, use "Modelos" → "Dietas prontas" e filtre por versão, semana e calorias.',
            ],
            'tip' => 'Na intolerância à lactose, leite, iogurte e queijo continuam, com a observação "versão sem lactose". '
                . 'Nas outras restrições, o alimento é trocado por outro de mesmas calorias, e as trocas ("ou ...") com alimento restrito saem. '
                . 'A sugestão é um ponto de partida: confira sempre antes de publicar.',
            'images' => [],
        ],
        [
            'id' => 'alterar-modelos',
            'title' => 'Editar, renomear e excluir modelos e trechos',
            'intro' => 'Toda a equipe clínica usa qualquer modelo ou trecho, mas só quem criou, ou a médica administradora, altera ou exclui.',
            'steps' => [
                'Na lista, os links "Editar" e "Excluir" só aparecem nos itens que você pode alterar.',
                'Editar: abre o mesmo editor da criação. Renomear: troque o "Título" no topo do editor e salve.',
                'O modo não muda depois de criado: para passar um modelo de texto para estruturado, crie um modelo novo.',
                'Excluir: o modelo ou trecho sai da lista. Os planos já criados a partir dele continuam iguais.',
            ],
            'tip' => 'Toda criação, alteração e exclusão fica registrada na auditoria, com quem fez e quando.',
            'images' => [],
        ],
    ];
};
