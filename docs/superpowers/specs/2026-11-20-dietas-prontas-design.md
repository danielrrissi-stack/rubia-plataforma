# Dietas prontas + "Sugestão da semana" — design

**Data:** 2026-11-20
**Base:** plano alimentar (Fase 2a, `2026-11-16-plano-alimentar-design.md`): modo estruturado, TACO/POF, rascunho → publicar.
**Status:** design para aprovação.

## 1. Pedido (do usuário)

- Banco de dietas prontas geradas pelo Claude, no estilo do exemplo enviado (refeições com horário, porções em gramas/medidas
  caseiras, orientações gerais).
- Uma dieta por **semana do ano (52)**, com frutas e legumes **da estação** e preparações de **calor ou frio**.
- Para cada semana, dietas de **100 em 100 kcal**, de crianças de **3 anos** até adultos.
- Cada dieta com **nome próprio**, ex.: `1500kcalSemana5`.
- Botão **"Sugestão da semana"** conforme a idade do paciente: a médica ou a nutricionista avalia, ajusta e publica usando o
  sistema que já existe. A equipe vê as calorias; o paciente **não**.
- **Leite sem caloria** na tabela: pesquisar e corrigir.
- Cardápios variados (podem repetir ao longo do ano), sem alimentos difíceis de encontrar.
- **Alergias/intolerâncias** (lactose ou outro alimento): verificar em cada paciente e adaptar a sugestão.

Respostas do usuário: "opção 1 e opção 3" para as faixas. Leitura adotada: **duas versões por idade (opção 1)**, e a versão
adolescente/adulto cobre **a faixa inteira da opção 3** (1.000 a 3.000 kcal). Calorias: vêm da meta de energia, editáveis.

## 2. Faixas e nomes

| Versão | Idade | Calorias | Nome | Quantidade |
|---|---|---|---|---|
| Infantil | 3 a 10 anos | 1.000 a 2.000 (11 níveis) | `1500kcalSemana5Infantil` | 52 × 11 = 572 |
| Adolescente/adulto | 11 anos ou mais | 1.000 a 3.000 (21 níveis) | `1500kcalSemana5` | 52 × 21 = 1.092 |

Total: **1.664 dietas**. Paciente com menos de 3 anos não recebe sugestão (o botão explica).

Diferenças da versão infantil: leite no lugar de café/chá, sem adoçante, lanche da manhã que vai na lancheira,
porções de carne menores e mais laticínios, ceia com leite.

## 3. Estações e semanas

Semana = semana ISO do ano (a 53ª usa a 52). Estações do hemisfério sul:

| Semanas | Estação | Clima | Características |
|---|---|---|---|
| 1–12 e 52 | Verão | calor | manga, abacaxi, melancia, melão, uva, pêssego; saladas cruas, pratos leves, peixe |
| 13–25 | Outono | ameno | caqui, maçã, goiaba, abacate, tangerina; abóbora, batata-doce, mandioca, chuchu |
| 26–38 | Inverno | frio | laranja, mexerica, morango, kiwi, pera; sopas, caldos, mingau de aveia, couve, brócolis |
| 39–51 | Primavera | ameno/quente | morango, abacaxi, mamão, melão, manga; folhas, beterraba, vagem, abobrinha |

Banana, mamão e maçã entram o ano todo.

## 4. Como as dietas são geradas

- `data/dietas/cardapio.php`, escrito à mão, guarda **blocos de refeição** por estação: cafés, lanches, almoços, jantares e
  ceias, com alimentos da TACO e porções de referência. Cada item tem um **papel**: carboidrato, proteína, gordura, laticínio,
  fruta, verdura/legume ou fixo.
- `tools/build_diets.php` monta a semana N juntando blocos da estação com passos diferentes por refeição, o que dá 52
  cardápios distintos. Depois ajusta as porções a cada nível de calorias:
  - verduras e legumes ficam fixos; frutas variam pouco; carboidrato, proteína, laticínio e gordura escalam;
  - arredonda para medidas caseiras (fatia, unidade, colher, concha) quando a tabela POF tem a medida, senão para múltiplos de
    5 g ou 10 g;
  - corrige a diferença no carboidrato principal até ficar a **±3 % da meta** (o teste confere as 1.664 dietas);
  - ceia só a partir de 1.800 kcal (adulto) ou 1.300 kcal (infantil).
- Cada fruta e cada proteína do almoço e do jantar ganham uma **troca** ("ou …") de calorias equivalentes, com outro alimento
  da estação.
- As orientações gerais seguem o exemplo: água, evitar refrigerante e suco açucarado, controlar o óleo e pesar no início. Há
  dicas de calor ou de frio e, na versão infantil, dicas de lancheira. **O texto não cita calorias**, porque a família lê.
- Saída: seed `database/seeds/diet_1_library.sql`, um upsert por nome, carregado pelo instalador e pelo `tools/migrate.php`.

## 5. Dados

Migração `017_diet_library.sql`:
- `diet_library` (nome único, versão infantil|adulto, semana, estação, kcal alvo, kcal calculada, macros, título, orientações,
  `structure_json` no formato de entrada do editor estruturado).
- `patient_food_restrictions` (paciente, lista de restrições marcadas, texto "outros alimentos a evitar", quem/quando), com
  auditoria.
- `meal_plans.source_diet_code` (nome da dieta pronta de origem, mostrado só para a equipe).

## 6. Leite sem caloria (correção da TACO)

Na TACO 4ª ed., os itens 457 "Leite, de vaca, desnatado, UHT" e 458 "Leite, de vaca, integral" vêm **sem nenhum valor**. Novo
arquivo `data/taco/overrides.csv`, aplicado pelo importador e documentado em `data/taco/SOURCES.md`, com valores da Tabela de
Composição Nutricional dos Alimentos Consumidos no Brasil (IBGE, POF 2008–2009) por 100 g:
- integral: 60 kcal, proteína 3,2 g, carboidrato 4,5 g, gordura 3,3 g, fibra 0;
- desnatado: 34 kcal, proteína 3,4 g, carboidrato 5,0 g, gordura 0,1 g, fibra 0.

Em instalações existentes, reaplicar o seed atualiza esses dois alimentos e não mexe em nada próprio.

## 7. Restrições alimentares do paciente

- Novo cartão **"Restrições e alergias alimentares"** na tela Plano alimentar (quem edita nutrição pode alterar):
  intolerância à lactose, alergia à proteína do leite de vaca, ovo, glúten, amendoim, castanhas, peixe, frutos do mar, soja,
  mais o campo livre "outros alimentos a evitar".
- Na tela da sugestão, a médica (que vê o prontuário) também vê o texto de **Alergias** do histórico, para conferir e marcar.
  A nutricionista não vê o prontuário (regra atual mantida) e trabalha só com as restrições marcadas.
- Adaptação automática ao gerar a sugestão (cada alimento das dietas tem etiquetas de alérgenos):
  - **lactose**: leite, iogurte e queijo continuam, com a observação "sem lactose" (as calorias são praticamente iguais);
  - **proteína do leite**: leite e iogurte → bebida de soja; queijo → ovo (ou frango, se houver restrição a ovo);
  - **ovo** → queijo minas frescal (ou frango); **glúten**: pão → tapioca ou cuscuz, macarrão → arroz, aveia → flocos de milho,
    biscoito → polvilho;
  - **amendoim/castanhas** → abacate; **peixe/frutos do mar** → frango grelhado; **soja** → leite ou frango;
  - a porção do substituto é recalculada para ter as mesmas calorias; trocas ("ou …") com alimento restrito são removidas.
- **Outros alimentos a evitar** (texto livre): o sistema procura cada palavra nos nomes dos alimentos da sugestão e mostra um
  **aviso** com o que encontrou, para a profissional trocar à mão. Não troca sozinho.
- O rascunho gerado lista as adaptações feitas e os avisos.

## 8. Telas

- **Plano alimentar do paciente**: cartão "Sugestão da semana" com a versão por idade, as calorias (já vêm da meta vigente
  arredondada à centena, dentro da faixa da versão, e podem ser trocadas), a semana (atual, com a estação; pode ser trocada) e
  as restrições. "Gerar sugestão" cria um **rascunho estruturado** com título "Plano alimentar – semana N (estação)", sem
  calorias, e abre o editor de sempre (totais × meta), onde a profissional avalia, ajusta e publica. Se já existir rascunho,
  é preciso marcar "descartar o rascunho atual".
- **Dietas prontas** (`/dietas-prontas`, equipe): filtros por versão, semana e calorias; a visualização mostra refeições,
  porções, kcal por refeição e macros.
- Portal da família: nada muda. Vê o plano publicado sem calorias, como hoje.

## 9. Testes

Todas as 1.664 dietas dentro de ±3 %, com nomes únicos, refeições válidas para o resolvedor e ids de alimentos existentes.
Também: mapa semana → estação, cada tipo de restrição e a remoção de trocas restritas, aviso do texto livre, permissões
(nutricionista sem liberação, família não vê nada novo), criação do rascunho e override do leite no importador.
