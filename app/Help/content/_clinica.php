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
            'intro' => 'O plano começa como rascunho. Só depois de publicado a família vê no portal.',
            'steps' => [
                'Na ficha, no cartão "Plano alimentar", clique em "Ver plano alimentar".',
                'Em "Novo plano", escolha o "Modo" (Texto ou Estruturado) e como começar ("Em branco", "Cópia do plano vigente" ou um "Modelo"). Clique em "Criar rascunho".',
                'Escreva o plano. No modo estruturado, busque os alimentos da tabela TACO e as medidas caseiras.',
                'Quando estiver pronto, abra o rascunho e clique em "Publicar". A família passa a ver o plano no portal.',
                'Abra o plano vigente para "Imprimir", gerar o PDF, "Salvar como modelo" ou "Editar (novo rascunho a partir deste)".',
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
