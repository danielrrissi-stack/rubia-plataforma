<?php
declare(strict_types=1);

/*
 * Cardapios-base das dietas prontas (escritos a mao; ver docs/superpowers/specs/2026-11-20-dietas-prontas-design.md).
 * tools/build_diets.php combina estes blocos por semana e ajusta as porcoes a cada nivel de calorias.
 *
 * Item: [codigo TACO | 'FRUTA' | 'LEGUME', gramas de referencia (adulto ~2.000 kcal), papel, opcoes]
 *   papel: c = carboidrato, p = proteina, d = laticinio, f = gordura (escalam); fr = fruta (varia pouco);
 *          v = legume (fixo); x = bebida/fixo.
 *   opcoes: m = nome da medida caseira POF preferida (senao gramas); step/min em unidades da medida (ou gramas);
 *           kid = [codigo, gramas] troca na versao infantil, ou false (sai na versao infantil);
 *           adult = false (so na versao infantil); sub = codigo de troca equivalente ("ou ..."); n = observacao do item.
 * Bloco: ['items' => [...], 'note' => observacao da refeicao, 'seasons' => estacoes (padrao: todas)].
 * Placeholders: FRUTA = fruta da estacao da semana; LEGUME = legume cozido da estacao. {SALADA} na nota = salada da estacao.
 */


return [
    'seasons' => ['verao', 'outono', 'inverno', 'primavera'],

    // Frutas da estacao: [codigo, gramas de 1 porcao, medida preferida|null]. Banana, mamao e maca o ano todo.
    'fruits' => [
        'verao' => [[231, 140, null], [164, 150, null], [235, 220, null], [236, 220, null], [256, 120, null], [244, 180, null], [182, 75, 'unidade'], [226, 170, 'fatia'], [200, 130, null]],
        'outono' => [[189, 100, null], [222, 150, 'unidade'], [200, 130, null], [251, 180, null], [182, 75, 'unidade'], [226, 170, 'fatia'], [243, 130, null], [214, 180, 'unidade']],
        'inverno' => [[214, 180, 'unidade'], [238, 180, null], [239, 200, null], [207, 130, null], [243, 130, null], [182, 75, 'unidade'], [222, 150, 'unidade'], [251, 180, null], [226, 170, 'fatia']],
        'primavera' => [[239, 200, null], [164, 150, null], [226, 170, 'fatia'], [236, 220, null], [231, 140, null], [182, 75, 'unidade'], [214, 180, 'unidade'], [222, 150, 'unidade'], [256, 120, null]],
    ],

    // Legumes cozidos/refogados da estacao (gramas fixas).
    'legumes' => [
        'verao' => [[72, 100], [95, 100], [162, 80], [118, 100], [109, 80], [112, 100]],
        'outono' => [[64, 100], [112, 100], [109, 80], [97, 80], [68, 100], [95, 100]],
        'inverno' => [[100, 100], [118, 100], [116, 50], [151, 80], [120, 60], [109, 80], [97, 80]],
        'primavera' => [[72, 100], [97, 80], [162, 80], [112, 100], [109, 80], [100, 100]],
    ],

    'saladas' => [
        'verao' => ['alface, tomate e pepino', 'rúcula, tomate e cenoura ralada', 'alface, pepino e pimentão', 'agrião, tomate e cebola'],
        'outono' => ['alface, tomate e cenoura ralada', 'acelga, tomate e pepino', 'rúcula, beterraba ralada e tomate'],
        'inverno' => ['alface, cenoura e beterraba raladas', 'repolho, cenoura e tomate', 'agrião, tomate e rabanete', 'acelga e cenoura ralada'],
        'primavera' => ['alface, rúcula e tomate', 'alface, beterraba ralada e pepino', 'agrião, tomate e cenoura ralada'],
    ],

    'meals' => [
        'cafe' => [
            ['items' => [[53, 50, 'c', ['m' => 'unidade']], [488, 45, 'p', ['m' => 'unidade', 'n' => 'mexido ou cozido, com pouco óleo']], ['FRUTA', 0, 'fr'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']], [457, 100, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]]]],
            ['items' => [[52, 50, 'c', ['m' => 'fatia']], [461, 30, 'd', ['n' => 'queijo branco']], ['FRUTA', 0, 'fr'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']], [457, 100, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]]]],
            ['items' => [[551, 50, 'c', ['m' => 'unidade']], [488, 45, 'p', ['m' => 'unidade', 'n' => 'como recheio']], ['FRUTA', 0, 'fr'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']], [457, 100, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]]]],
            ['items' => [[533, 120, 'c'], [488, 45, 'p', ['m' => 'unidade']], ['FRUTA', 0, 'fr'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']], [457, 100, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]]]],
            ['items' => [[449, 200, 'd', ['m' => 'pote', 'kid' => [448, 200]]], [7, 30, 'c', ['m' => 'colher de sopa']], ['FRUTA', 0, 'fr', ['n' => 'picada']], [594, 10, 'f', ['kid' => false]], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']]], 'note' => 'Montar uma tigela: iogurte, fruta picada e aveia por cima.', 'seasons' => ['verao', 'primavera']],
            ['items' => [[53, 50, 'c', ['m' => 'unidade']], [468, 15, 'f', ['n' => 'passar fino']], [463, 20, 'd', ['m' => 'fatia']], ['FRUTA', 0, 'fr'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']], [457, 100, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]]]],
            ['items' => [[457, 200, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]], [182, 75, 'fr', ['m' => 'unidade']], [7, 15, 'c', ['m' => 'colher de sopa']], [52, 25, 'c', ['m' => 'fatia']], [469, 30, 'p', ['n' => 'ricota temperada']]], 'note' => 'Vitamina: bater o leite com a banana e a aveia, sem açúcar.'],
            ['items' => [[457, 200, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]], [7, 30, 'c', ['m' => 'colher de sopa']], ['FRUTA', 0, 'fr'], [53, 25, 'c', ['m' => 'metade']], [488, 45, 'p', ['m' => 'unidade']]], 'note' => 'Mingau: cozinhar a aveia no leite, com canela, sem açúcar.', 'seasons' => ['outono', 'inverno']],
            ['items' => [[129, 100, 'c', ['n' => 'cozida']], [461, 30, 'd'], [488, 45, 'p', ['m' => 'unidade']], ['FRUTA', 0, 'fr'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']], [457, 100, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]]], 'seasons' => ['outono', 'inverno']],
            ['items' => [[52, 50, 'c', ['m' => 'fatia']], [469, 30, 'p', ['n' => 'ricota temperada com ervas']], [157, 30, 'v', ['n' => 'rodelas de tomate']], ['FRUTA', 0, 'fr'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => false, 'n' => 'sem açúcar']], [457, 100, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]]], 'seasons' => ['verao', 'primavera']],
        ],

        'lanche_manha' => [
            ['items' => [['FRUTA', 0, 'fr'], [589, 8, 'f']], 'adult_only' => true],
            ['items' => [[449, 200, 'd', ['m' => 'pote']], ['FRUTA', 0, 'fr']], 'adult_only' => true],
            ['items' => [['FRUTA', 0, 'fr'], [558, 15, 'f', ['n' => 'sem sal, se possível']]], 'adult_only' => true],
            ['items' => [['FRUTA', 0, 'fr'], [461, 30, 'd']], 'adult_only' => true],
            ['items' => [[478, 200, 'x'], ['FRUTA', 0, 'fr']], 'adult_only' => true, 'seasons' => ['verao', 'primavera']],
            // Lancheira (versao infantil).
            ['items' => [[52, 25, 'c', ['m' => 'fatia']], [468, 10, 'f'], ['FRUTA', 0, 'fr']], 'kid_only' => true, 'note' => 'Lancheira: sanduíche embrulhado e fruta lavada; levar água.'],
            ['items' => [[448, 200, 'd', ['m' => 'pote']], ['FRUTA', 0, 'fr']], 'kid_only' => true, 'note' => 'Lancheira: iogurte em bolsa térmica; levar água.'],
            ['items' => [['FRUTA', 0, 'fr'], [461, 30, 'd'], [13, 15, 'c', ['n' => 'biscoito de água e sal']]], 'kid_only' => true, 'note' => 'Lancheira: queijo em pote fechado; levar água.'],
            ['items' => [['FRUTA', 0, 'fr'], [478, 200, 'x'], [99, 10, 'c']], 'kid_only' => true, 'note' => 'Lancheira: água de coco gelada; levar água.', 'seasons' => ['verao', 'primavera']],
            ['items' => [[140, 40, 'c', ['n' => 'pão de queijo assado']], ['FRUTA', 0, 'fr']], 'kid_only' => true, 'note' => 'Lancheira: pão de queijo e fruta lavada; levar água.', 'seasons' => ['outono', 'inverno']],
        ],

        'almoco' => [
            // Verao
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [410, 120, 'p', ['sub' => 377]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['verao']],
            ['items' => [[3, 100, 'c'], [567, 100, 'c'], [301, 140, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Peixe assado com limão e ervas. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao', 'primavera']],
            ['items' => [[1, 120, 'c'], [561, 100, 'c'], [377, 110, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['verao']],
            ['items' => [[91, 180, 'c', ['n' => 'cozida, em cubos']], [408, 120, 'p', ['n' => 'desfiado', 'sub' => 488]], [45, 50, 'c'], ['LEGUME', 0, 'v'], [260, 6, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada completa: misturar batata, frango, milho e legumes; servir com {SALADA}.', 'seasons' => ['verao']],
            ['items' => [[40, 60, 'c', ['n' => 'peso cru; depois de cozido rende cerca de 2,5 vezes']], [326, 90, 'p', ['sub' => 408]], [159, 60, 'x', ['n' => 'molho de tomate']], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Macarrão com molho de tomate e carne moída. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao', 'outono']],
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [318, 100, 'p', ['sub' => 410, 'kid' => [410, 90]]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Sardinha assada. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao']],
            // Outono
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [410, 120, 'p', ['sub' => 371]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['outono', 'primavera']],
            ['items' => [[129, 150, 'c'], [561, 100, 'c'], [371, 100, 'p', ['n' => 'carne de panela', 'sub' => 398]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Carne de panela com mandioca. Salada crua à vontade ({SALADA}).', 'seasons' => ['outono', 'inverno']],
            ['items' => [[3, 120, 'c'], [567, 100, 'c'], [432, 100, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Lombo assado sem a gordura aparente. Salada crua à vontade ({SALADA}).', 'seasons' => ['outono']],
            ['items' => [[91, 200, 'c', ['n' => 'em purê, com um pouco de leite']], [276, 140, 'p', ['sub' => 410]], [561, 80, 'c'], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Peixe grelhado com purê de batata. Salada crua à vontade ({SALADA}).', 'seasons' => ['outono', 'primavera']],
            ['items' => [[62, 150, 'c', ['n' => 'polenta mole']], [398, 110, 'p', ['n' => 'frango ensopado', 'sub' => 371]], [561, 80, 'c'], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Frango ensopado com polenta. Salada crua à vontade ({SALADA}).', 'seasons' => ['outono', 'inverno']],
            ['items' => [[3, 120, 'c'], [577, 100, 'c'], [326, 90, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Arroz com lentilha e carne moída refogada. Salada crua à vontade ({SALADA}).', 'seasons' => ['outono', 'inverno']],
            // Inverno
            ['items' => [[3, 120, 'c'], [567, 120, 'c'], [432, 80, 'p', ['sub' => 371]], [116, 40, 'v', ['n' => 'couve refogada']], [214, 180, 'fr', ['m' => 'unidade', 'n' => 'sobremesa']]], 'note' => 'Feijoada leve: feijão preto cozido com lombo magro, sem embutidos; acompanha couve e laranja.', 'seasons' => ['inverno']],
            ['items' => [[3, 100, 'c'], [86, 100, 'c', ['n' => 'mandioquinha cozida']], [377, 110, 'p', ['sub' => 410]], [561, 80, 'c'], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['inverno']],
            ['items' => [[40, 60, 'c', ['n' => 'peso cru; depois de cozido rende cerca de 2,5 vezes']], [408, 110, 'p', ['n' => 'desfiado', 'sub' => 326]], [159, 60, 'x', ['n' => 'molho de tomate']], ['LEGUME', 0, 'v'], [464, 5, 'd', ['n' => 'ralado por cima']]], 'note' => 'Macarrão com frango desfiado ao molho de tomate. Salada crua à vontade ({SALADA}).', 'seasons' => ['inverno', 'primavera']],
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [313, 130, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Peixe ensopado com tomate e cebola. Salada crua à vontade ({SALADA}).', 'seasons' => ['inverno']],
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [398, 110, 'p', ['n' => 'assada', 'sub' => 377]], ['LEGUME', 0, 'v'], [88, 80, 'c']], 'note' => 'Coxa de frango assada com batata-doce. Salada crua à vontade ({SALADA}).', 'seasons' => ['inverno', 'outono']],
            // Primavera
            ['items' => [[1, 120, 'c'], [561, 100, 'c'], [346, 100, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Bife grelhado. Salada crua à vontade ({SALADA}).', 'seasons' => ['primavera']],
            ['items' => [[3, 120, 'c'], [577, 100, 'c'], [488, 90, 'p', ['m' => 'unidade', 'n' => 'cozidos ou mexidos', 'sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Arroz, lentilha e ovos. Salada crua à vontade ({SALADA}).', 'seasons' => ['primavera']],
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [368, 110, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Maminha grelhada. Salada crua à vontade ({SALADA}).', 'seasons' => ['primavera', 'verao']],
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [413, 110, 'p', ['n' => 'assada, sem pele', 'sub' => 410]], [72, 80, 'v', ['n' => 'abobrinha refogada']], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Sobrecoxa assada com limão e alho, e abobrinha refogada. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao', 'primavera']],
        ],

        'lanche_tarde' => [
            ['items' => [[182, 75, 'fr', ['m' => 'unidade']], [558, 15, 'f', ['kid' => [7, 15]]]], 'note' => 'Banana amassada com amendoim (ou aveia, na versão infantil).'],
            ['items' => [[52, 50, 'c', ['m' => 'fatia']], [461, 30, 'd'], [471, 50, 'x', ['m' => 'xícara de café', 'kid' => [458, 200], 'n' => 'sem açúcar']]]],
            ['items' => [[449, 200, 'd', ['m' => 'pote', 'kid' => [448, 200]]], [7, 15, 'c', ['m' => 'colher de sopa']], ['FRUTA', 0, 'fr']]],
            ['items' => [[551, 50, 'c', ['m' => 'unidade']], [463, 20, 'd', ['m' => 'fatia']], [477, 200, 'x', ['kid' => [458, 200], 'n' => 'chá sem açúcar']]]],
            ['items' => [[457, 200, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200]]], ['FRUTA', 0, 'fr'], [7, 15, 'c', ['m' => 'colher de sopa']]], 'note' => 'Vitamina: bater o leite com a fruta e a aveia, sem açúcar.', 'seasons' => ['verao', 'primavera']],
            ['items' => [[533, 100, 'c'], [461, 30, 'd'], [457, 150, 'd', ['m' => 'copo americano', 'kid' => [458, 150], 'n' => 'com café ou puro, sem açúcar']]], 'seasons' => ['outono', 'inverno']],
            ['items' => [[53, 50, 'c', ['m' => 'unidade']], [469, 30, 'p', ['n' => 'ricota temperada']], ['FRUTA', 0, 'fr']]],
            ['items' => [[45, 100, 'c', ['n' => 'milho verde cozido']], ['FRUTA', 0, 'fr'], [457, 150, 'd', ['m' => 'copo americano', 'kid' => [458, 150]]]], 'seasons' => ['outono', 'inverno']],
            ['items' => [[63, 30, 'c', ['n' => 'torrada de pão francês']], [468, 15, 'f', ['n' => 'passar fino']], ['FRUTA', 0, 'fr'], [475, 200, 'x', ['kid' => [458, 200], 'n' => 'chá quente, sem açúcar']]], 'seasons' => ['outono', 'inverno']],
        ],

        'jantar' => [
            // Verao: leve
            ['items' => [[52, 75, 'c', ['m' => 'fatia']], [408, 90, 'p', ['n' => 'desfiado', 'sub' => 469]], [110, 40, 'v', ['n' => 'cenoura ralada']], [157, 50, 'v', ['n' => 'tomate']], [468, 15, 'f']], 'note' => 'Sanduíche natural: pão integral, frango desfiado com requeijão, cenoura, tomate e folhas.', 'seasons' => ['verao', 'primavera']],
            ['items' => [[88, 150, 'c'], [276, 150, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Peixe grelhado com batata-doce. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao', 'primavera']],
            ['items' => [[3, 100, 'c'], [484, 100, 'p', ['n' => 'omelete com tomate e cheiro-verde', 'sub' => 410]], ['LEGUME', 0, 'v'], [260, 3, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['verao', 'outono', 'primavera']],
            ['items' => [[551, 70, 'c', ['m' => 'unidade']], [408, 80, 'p', ['n' => 'recheio de frango desfiado', 'sub' => 463]], [157, 50, 'v', ['n' => 'tomate']], [449, 100, 'd', ['kid' => [448, 100], 'n' => 'iogurte como molho, com ervas']]], 'note' => 'Tapioca recheada. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao']],
            ['items' => [[3, 120, 'c'], [561, 100, 'c'], [410, 110, 'p', ['sub' => 346]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).'],
            // Outono
            ['items' => [[88, 150, 'c'], [410, 120, 'p', ['sub' => 377]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['outono']],
            ['items' => [[1, 100, 'c'], [567, 100, 'c'], [326, 80, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['outono', 'primavera']],
            // Inverno: sopas e caldos
            ['items' => [[408, 90, 'p', ['n' => 'desfiado', 'sub' => 371]], [91, 150, 'c'], [40, 25, 'c', ['n' => 'peso cru, tipo argolinha']], [109, 50, 'v'], [112, 50, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Sopa de legumes com frango: cozinhar tudo junto com cebola, alho e cheiro-verde. Não usar caldo em tablete.', 'seasons' => ['inverno', 'outono']],
            ['items' => [[371, 90, 'p', ['n' => 'em cubos', 'sub' => 408]], [86, 150, 'c', ['n' => 'mandioquinha']], [64, 80, 'v', ['n' => 'abóbora']], [116, 30, 'v', ['n' => 'couve fatiada no final']], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Sopa de carne com mandioquinha, abóbora e couve.', 'seasons' => ['inverno']],
            ['items' => [[561, 200, 'c', ['n' => 'batido, como caldo']], [326, 70, 'p', ['sub' => 408]], [116, 30, 'v', ['n' => 'couve fatiada']], [53, 25, 'c', ['m' => 'metade', 'n' => 'torrado']]], 'note' => 'Caldo de feijão com carne moída e couve.', 'seasons' => ['inverno']],
            ['items' => [[3, 90, 'c'], [408, 90, 'p', ['n' => 'desfiado', 'sub' => 398]], [109, 60, 'v'], [97, 50, 'v'], [260, 3, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Canja: arroz cozido no caldo caseiro com frango desfiado e legumes.', 'seasons' => ['inverno']],
            ['items' => [[62, 150, 'c', ['n' => 'polenta mole']], [326, 90, 'p', ['n' => 'molho de carne moída com tomate', 'sub' => 408]], [159, 50, 'x'], ['LEGUME', 0, 'v']], 'note' => 'Polenta com molho de carne.', 'seasons' => ['inverno']],
            // Novos: cuscuz, bife acebolado, atum, creme de abobora, escondidinho
            ['items' => [[533, 150, 'c', ['n' => 'no vapor, estilo nordestino']], [488, 90, 'p', ['m' => 'unidade', 'n' => 'cozidos ou mexidos', 'sub' => 410]], [157, 50, 'v', ['n' => 'tomate']], [260, 3, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Cuscuz com ovo e tomate. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao', 'outono', 'primavera']],
            ['items' => [[3, 100, 'c'], [561, 80, 'c'], [377, 100, 'p', ['n' => 'bife acebolado', 'sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Bife acebolado. Salada crua à vontade ({SALADA}).', 'seasons' => ['verao', 'primavera']],
            ['items' => [[88, 150, 'c', ['n' => 'em cubos']], [277, 70, 'p', ['n' => 'escorrer o óleo', 'sub' => 488]], [157, 50, 'v', ['n' => 'tomate']], ['LEGUME', 0, 'v'], [260, 3, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada morna: batata-doce, atum, tomate e cheiro-verde; servir com {SALADA}.', 'seasons' => ['verao']],
            ['items' => [[64, 150, 'v', ['n' => 'abóbora']], [91, 120, 'c', ['n' => 'batata']], [408, 90, 'p', ['n' => 'desfiado', 'sub' => 371]], [53, 25, 'c', ['m' => 'metade', 'n' => 'torrado']], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Creme de abóbora com frango: cozinhar a abóbora e a batata com cebola e alho, bater e juntar o frango desfiado. Não usar caldo em tablete.', 'seasons' => ['outono', 'inverno']],
            ['items' => [[129, 180, 'c', ['n' => 'em purê']], [326, 90, 'p', ['sub' => 408]], [464, 5, 'd', ['n' => 'ralado por cima']], ['LEGUME', 0, 'v'], [260, 3, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Escondidinho: carne moída refogada coberta com purê de mandioca e queijo ralado, no forno. Salada crua à vontade ({SALADA}).', 'seasons' => ['outono', 'inverno']],
            // Primavera
            ['items' => [[3, 100, 'c'], [561, 80, 'c'], [301, 140, 'p', ['sub' => 410]], ['LEGUME', 0, 'v'], [260, 4, 'f', ['m' => 'colher de chá', 'step' => 1, 'min' => 1]]], 'note' => 'Salada crua à vontade ({SALADA}).', 'seasons' => ['primavera']],
        ],

        'ceia' => [
            ['items' => [[449, 200, 'd', ['m' => 'pote', 'kid' => [448, 200]]]]],
            ['items' => [[457, 200, 'd', ['m' => 'xícara de chá', 'kid' => [458, 200], 'n' => 'morno, sem açúcar']]], 'seasons' => ['outono', 'inverno']],
            ['items' => [['FRUTA', 0, 'fr']]],
            ['items' => [[475, 200, 'x', ['n' => 'chá sem açúcar']], [13, 15, 'c', ['n' => 'biscoito de água e sal']]], 'adult_only' => true, 'seasons' => ['outono', 'inverno']],
            ['items' => [[457, 150, 'd', ['m' => 'copo americano', 'kid' => [458, 150]]], ['FRUTA', 0, 'fr']], 'kid_only' => true],
        ],
    ],

    // Refeicoes: chave, nome, horario adulto, horario infantil.
    'schedule' => [
        ['cafe', 'Café da manhã', '07:00', '07:00'],
        ['lanche_manha', 'Lanche da manhã', '10:00', '09:30'],
        ['almoco', 'Almoço', '12:30', '12:00'],
        ['lanche_tarde', 'Lanche da tarde', '16:00', '15:30'],
        ['jantar', 'Jantar', '19:30', '19:00'],
        ['ceia', 'Ceia', '21:30', '21:00'],
    ],

    // Ceia a partir destas calorias.
    'ceia_from' => ['adulto' => 1800, 'infantil' => 1300],

    // Etiquetas de alergenos/restricoes por alimento TACO.
    'tags' => [
        'leite' => [458, 457, 449, 448, 461, 463, 464, 469, 468, 484, 551, 262, 261, 140],
        'lactose' => [458, 457, 449, 448, 461, 463, 464, 469, 468, 484, 551, 262, 261, 140],
        'ovo' => [488, 484, 140],
        'gluten' => [53, 52, 48, 7, 13, 40, 63],
        'amendoim' => [558],
        'castanhas' => [588, 589],
        'peixe' => [301, 276, 313, 318, 277],
        'frutos_mar' => [284],
        'soja' => [584, 582],
    ],

    'guidance' => [
        'base' => [
            'Beber água regularmente durante o dia.',
            'Evitar refrigerantes, sucos açucarados e bebidas alcoólicas.',
            'Controlar a quantidade de óleo, azeite, manteiga e molhos prontos.',
            'Pesar os alimentos no início para aprender o tamanho das porções.',
            'Não é preciso eliminar arroz, feijão ou outros carboidratos.',
            'Manter horários regulares para as refeições.',
            'As frutas e os legumes podem ser trocados por outros da estação, na mesma quantidade.',
        ],
        'infantil' => [
            'Não oferecer adoçante nem açúcar nas bebidas da criança.',
            'Na lancheira: fruta lavada, potes fechados e iogurte em bolsa térmica. Sempre levar uma garrafinha de água.',
            'Oferecer os alimentos sem forçar: a criança pode precisar provar várias vezes até aceitar um alimento novo.',
            'Fazer as refeições à mesa, em família, sem televisão ou celular.',
            'Cortar uvas e outras frutas pequenas ao meio para crianças menores.',
        ],
        'adulto' => [
            'Comer devagar, mastigando bem, sem telas durante as refeições.',
        ],
        'verao' => [
            'Nos dias quentes, aumentar a água: levar uma garrafinha para todo lugar.',
            'Preferir a fruta inteira ao suco; água de coco e água com rodelas de limão ou hortelã também ajudam.',
        ],
        'outono' => [
            'Aproveitar abóbora, batata-doce, mandioca e as frutas da estação, que estão mais baratas.',
        ],
        'inverno' => [
            'Sopas e caldos caseiros são boas opções no frio; evitar caldos em tablete e embutidos.',
            'Chás sem açúcar ajudam a aquecer e contam como líquido do dia.',
        ],
        'primavera' => [
            'Aproveitar as folhas, o morango e as frutas da estação nas saladas e lanches.',
        ],
    ],
];
