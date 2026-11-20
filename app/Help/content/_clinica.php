<?php
declare(strict_types=1);

use App\Help\Manual;

/**
 * Secoes de acompanhamento comuns a medica e nutricionista. Mesmo cuidado de _equipe.php: nada aqui pode
 * citar medicamentos, exames, evolucao nem receita (o manual da nutricionista usa estas secoes).
 * @return array<string, array>
 */
return static function (string $slug, string $as): array {
    $img = static fn (string $name, string $url, string $caption): array
        => Manual::img($slug . '/' . $name . '.jpg', $url, $as, 'desktop', $caption);

    return [
        'crescimento' => [
            'id' => 'crescimento',
            'title' => 'Medidas e curvas de crescimento',
            'intro' => 'As curvas seguem a referência da OMS. Pesagens feitas pela família em casa aparecem junto com as do consultório.',
            'steps' => [
                'Na ficha, no cartão "Crescimento", clique em "Ver crescimento".',
                'Escolha a curva ("IMC/idade", "Peso/idade" ou "Altura/idade") e se quer ver em "Escore Z" ou "Percentil".',
                'Para lançar uma medida, clique em "Registrar medida", preencha a data, o peso, a altura e o que mais tiver medido, e clique em "Salvar".',
                'Errou um valor? Use "Corrigir" na tabela de medidas; o sistema guarda a versão anterior em "Versões".',
            ],
            'images' => [
                $img('crescimento', '/pacientes/{crianca}/crescimento', 'Curva de IMC por idade.'),
                $img('medida-nova', '/pacientes/{crianca}/medidas/nova', 'Formulário de nova medida.'),
            ],
        ],
        'energia' => [
            'id' => 'energia',
            'title' => 'Gasto de energia',
            'steps' => [
                'Na ficha, no cartão "Energia", clique em "Ver energia".',
                'Confira o peso e a altura (clique em "Recalcular" se mudar algum).',
                'Escolha o "Fator de atividade" e, se quiser, escreva observações.',
                'Clique em "Salvar meta energética". As metas anteriores ficam no "Histórico".',
            ],
            'images' => [$img('energia', '/pacientes/{crianca}/energia', 'Cálculo do gasto de energia.')],
        ],
        'plano' => [
            'id' => 'plano',
            'title' => 'Plano alimentar',
            'intro' => 'O plano começa como rascunho. Só depois de publicado a família vê no portal. Se ninguém publicar a continuação a tempo, a família continua vendo os últimos 7 dias publicados, em ciclo.',
            'steps' => [
                'Na ficha, no cartão "Plano alimentar", clique em "Ver plano alimentar".',
                'Em "Novo plano", escolha o "Modo" (Texto ou Estruturado) e como começar ("Em branco", "Cópia do plano vigente" ou um "Modelo"). Clique em "Criar rascunho".',
                'Para um cardápio de vários dias, use o cartão "Sugestão de cardápio": escolha a "Duração" (7 ou 14 dias; o padrão é 14) e o "Início" (o padrão é hoje) e clique em "Gerar sugestão". O sistema cria um rascunho chamado "Plano alimentar – dd/mm a dd/mm" e abre o editor.',
                'No editor há uma aba para cada dia ("Seg 23/11", "Ter 24/11"...), com o total de calorias do dia. O dia que fica mais de 10 % longe da meta aparece destacado. Em cada dia, "Copiar de outro dia" repete um cardápio e "Limpar dia" esvazia o dia.',
                'Escreva o plano. No modo estruturado, busque os alimentos da tabela TACO e as medidas caseiras.',
                'Quando estiver pronto, abra o rascunho e clique em "Publicar". A família passa a ver o plano no portal: hoje e os próximos 6 dias, nas abas "Hoje", "Amanhã", "Qua 26/11"..., e a "Lista de compras" desses 7 dias. A família não vê calorias.',
                'Continuação automática: a partir das 06:00, quando faltam 4 dias ou menos para o plano acabar, o sistema monta um rascunho de continuação (os dias que restam mais 7 ou 14 dias novos, com a meta de energia e as restrições atuais) e avisa por e-mail quem publicou o plano (ou, se não der, os médicos). Abra o rascunho, revise e publique.',
                'No painel, o cartão "Planos para revisar" mostra cada plano que está terminando: pendente, "vencido" (em vermelho: ninguém publicou a continuação e a família está vendo os últimos 7 dias se repetirem), "há um rascunho aberto" ou "sem continuação". Use "Gerar continuação agora" no cartão, ou na página do plano quando faltarem 4 dias ou menos e não houver rascunho.',
                'Se você descartar um rascunho de continuação, o sistema não gera outro sozinho para aquele plano: use "Gerar continuação agora".',
                'Abra o plano vigente para "Imprimir", gerar o PDF, "Salvar como modelo" (num plano de vários dias, escolha o "dia" que vira modelo) ou "Editar (novo rascunho a partir deste)".',
            ],
            'images' => [
                $img('plano', '/pacientes/{crianca}/plano', 'Plano vigente e criação de um novo.'),
                $img('plano-vigente', '/planos/{plano}', 'Plano publicado.'),
            ],
        ],
        'modelos' => [
            'id' => 'modelos',
            'title' => 'Modelos, trechos e alimentos',
            'intro' => 'Modelos e trechos economizam tempo: um plano base e frases que você usa sempre.',
            'steps' => [
                'Clique em "Modelos" no menu. Use "Novo modelo em texto" ou "Novo modelo estruturado".',
                'Em "Trechos de texto", cadastre frases prontas para inserir nos planos.',
                'Em "Alimentos", busque na tabela e cadastre os seus com "Novo alimento" (os da TACO não podem ser editados).',
                'Em "Dietas prontas", consulte as 1.664 dietas da semana (por estação, de 100 em 100 kcal, versão infantil e adolescente/adulto). '
                    . 'No plano alimentar do paciente, marque as "Restrições e alergias alimentares" e use "Gerar sugestão": '
                    . 'vira um rascunho já adaptado, para você avaliar e publicar. Ali aparecem também as alergias anotadas no histórico.',
            ],
            'images' => [$img('modelos', '/modelos-plano', 'Lista de modelos de plano.')],
        ],
        'metas' => [
            'id' => 'metas',
            'title' => 'Metas da semana',
            'intro' => 'As metas aparecem para a família e para a criança marcarem o que conseguiram fazer.',
            'steps' => [
                'Na ficha, no cartão "Metas", clique em "Ver metas" e depois em "Nova meta".',
                'Escolha uma meta pronta da biblioteca ou escreva a sua em "Texto para a família" (e, se quiser, em "Texto para a criança").',
                'Escolha o emoji, a categoria, quantas vezes por semana e o início; clique em "Salvar".',
                'Marque "Meta sigilosa" quando os responsáveis não devem ver (adolescentes).',
                'Para parar uma meta, use "Encerrar"; "Excluir" apaga de vez.',
            ],
            'images' => [
                $img('metas', '/pacientes/{crianca}/metas', 'Metas ativas da paciente.'),
                $img('meta-nova', '/pacientes/{crianca}/metas/nova', 'Nova meta com a biblioteca.'),
            ],
        ],
        'biblioteca' => [
            'id' => 'biblioteca',
            'title' => 'Biblioteca de metas',
            'steps' => [
                'Clique em "Biblioteca de metas" no menu.',
                'Use "Nova meta pronta" para cadastrar uma meta que você usa com vários pacientes, ou "Editar" para ajustar uma existente.',
            ],
            'images' => [$img('biblioteca', '/metas-modelo', 'Biblioteca de metas prontas.')],
        ],
        'diario' => [
            'id' => 'diario',
            'title' => 'Diário da família',
            'intro' => 'Você pede o que a família deve anotar (alimentação, água, sono, atividade, tela ou medidas) e acompanha a semana.',
            'steps' => [
                'Na ficha, no cartão "Diário", clique em "Pedir registros" (ou "Abrir diário").',
                'Clique em "Novo pedido", escolha "O que registrar", os dias da semana (ou "Todo dia") e o início; clique em "Salvar".',
                'No diário, navegue com "Semana anterior" e "Próxima semana".',
                'Escreva em "Comentar" embaixo de um registro para responder à família.',
            ],
            'images' => [$img('diario', '/pacientes/{crianca}/diario', 'Semana do diário com registros.')],
        ],
        'linha-do-tempo' => [
            'id' => 'linha-do-tempo',
            'title' => 'Linha do tempo',
            'intro' => 'Tudo o que aconteceu com o paciente, em ordem de data: medidas, planos, metas, consultas e registros.',
            'steps' => [
                'Na ficha, clique em "Linha do tempo".',
                'Escolha o período: "Desde a última visita", "3 meses", "12 meses" ou "Tudo".',
                'Clique num item para abrir a tela de origem.',
            ],
            'images' => [$img('linha-do-tempo', '/pacientes/{crianca}/linha-do-tempo', 'Linha do tempo desde a última visita.')],
        ],
        'meta-peso' => [
            'id' => 'meta-peso',
            'title' => 'Meta de peso',
            'intro' => 'Para adultos a meta é em kg; para crianças e adolescentes, em escore-z do IMC.',
            'steps' => [
                'Na ficha, no cartão "Meta de peso", clique em "Definir meta" (ou "Ver meta").',
                'Preencha a meta, o prazo e, se quiser, observações; clique em "Salvar meta".',
                'O gráfico mostra o caminho esperado e as pesagens; o resumo diz se o paciente está à frente ou atrás do caminho.',
                'Para mudar, use "Trocar meta"; para parar, "Encerrar meta".',
            ],
            'images' => [$img('meta-peso', '/pacientes/' . ($as === 'medico' ? '{adulto}' : '{crianca}') . '/meta-peso', 'Meta de peso com o caminho esperado.')],
        ],
    ];
};
