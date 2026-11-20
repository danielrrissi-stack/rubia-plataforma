# Cardápio por dias (7 ou 14) com continuação automática — design

**Data:** 2026-11-20
**Base:** dietas prontas + "Sugestão da semana" (`2026-11-20-dietas-prontas-design.md`), plano alimentar estruturado
(`2026-11-16-plano-alimentar-design.md`).
**Status:** design para aprovação.

## 1. Pedido (do usuário)

- Hoje a sugestão da semana gera **um dia só**. A família precisa ver **os próximos dias** para saber o que comprar.
- "O que eu não quero é todo dia lançar refeições manualmente": tudo é montado pelo sistema; a profissional só revisa
  e publica.
- Poder criar plano de **duas semanas**. A família vê **só os próximos 7 dias**.
- Quando o plano estiver acabando (ex.: no 10º dia de 14), o sistema **já monta mais dias para revisar e avisa** a
  profissional para conferir.
- Se ninguém revisar a tempo e os dias publicados acabarem: a família **continua vendo os últimos 7 dias publicados**,
  repetindo em ciclo (escolha do usuário; publicar sozinho foi descartado porque iria para a família sem revisão).

## 2. Objetivos, não-objetivos, anti-objetivos

- **Objetivo:** um clique gera de 7 a 14 dias com data, cada um com cardápio próprio, dentro da meta de calorias
  (±3 %) e já adaptado às restrições. A família vê hoje e os 6 dias seguintes, além da lista de compras desses dias. A
  continuação é montada sozinha e a profissional é avisada.
- **Não-objetivo:** publicar sem revisão; planos em texto livre ou de um dia só (continuam como hoje); modelos com
  vários dias.
- **Anti-objetivo:** a família ver calorias ou ficar sem cardápio; a profissional ter de digitar refeição por refeição;
  o pacote de instalação ficar pesado (o seed atual já tem 5 MB).

## 3. Abordagens consideradas

| Abordagem | Como | Troca | Decisão |
|---|---|---|---|
| A. Plano com dias | estrutura nova com dias datados, cada um com as suas refeições | mexe no editor, portal, impressão e PDF | **escolhida** |
| B. Dias como "opções" | almoço com "Segunda: …, Terça: …" | pouca mudança, mas a família lê por refeição e não por dia | descartada |

Origem dos dias:

| Abordagem | Troca | Decisão |
|---|---|---|
| Guardar 7 dias por dieta no seed | seed de ~35 MB, pesado para o phpMyAdmin e a hospedagem | descartada |
| **Montar na hora**, a partir de `cardapio.php` e da tabela de alimentos do banco | rápido (milissegundos por dia), sem crescer o pacote | **escolhida** (decisão do Claude) |

## 4. Formato do plano com dias

Novo formato v2 do `structure_json`; o v1 (um dia) continua valendo para planos e modelos existentes:

```json
{"v": 2, "start": "2026-11-23", "days": [ {"meals": [ …refeições no formato v1… ]}, … ]}
```

- O dia `i` tem a data `start + i`. Não há buraco entre os dias.
- De 1 a 31 dias por plano. Uma continuação leva os dias restantes mais os novos, ou seja, no máximo 4 + 14 = 18 dias.
- Cada dia segue os mesmos limites do v1 (refeições, opções, itens, trocas).
- As **orientações** são do plano inteiro, como hoje.
- `MealPlanResolver`, `MealPlanCalculator` e `MealPlanRepository::publish` passam a aceitar v2. A validação de "refeição
  sem itens" e "opção vazia" vale para cada dia, e a mensagem cita a data.
- Os totais gravados no plano (`total_kcal` etc.) passam a ser a **média dos dias**. Nas telas da equipe aparecem os
  totais de cada dia.

## 5. Geração dos dias

- `DietBuilder` ganha `buildDays(band, kcal, startDate, n)`. Para cada data:
  - a estação vem da semana da data, então um plano que atravessa a troca de estação muda de frutas no dia certo;
  - os blocos de cada refeição são escolhidos pela **semana ISO e pelo dia da semana** (`semana × 7 + dia`, a semana
    53 usa a 52). Dias seguidos ficam diferentes. A semana N tem o mesmo cardápio todo ano, e a mesma data dá o mesmo
    cardápio para todos os pacientes da mesma versão e calorias, o que é determinístico e testável. Assim as dietas
    prontas continuam valendo de um ano para o outro;
  - porções ajustadas com o `fit()` atual (±3 %), arredondamento para medidas caseiras, trocas "ou …";
  - restrições aplicadas dia a dia com o `DietAdapter` atual.
- `DietBuilder::fromDatabase(db)`: a mesma montagem, lendo `foods` e `food_measures` do banco, que já trazem a correção do
  leite. O `fromDataDir` continua para `tools/build_diets.php` e os testes.
- **Cardápio ampliado** (`cardapio.php`): pelo menos **7 almoços e 7 jantares por estação**, para não repetir almoço nem
  jantar dentro de 7 dias seguidos. Hoje há 5 a 6 jantares por estação. Os lanches, o café e a ceia podem repetir, mas
  não em dois dias seguidos. Os alimentos continuam fáceis de encontrar.
- A tabela `diet_library` (1.664 dietas) **continua** para a lista "Dietas prontas" e para o nome de origem:
  - `structure_json` passa a guardar só a segunda-feira da semana, para o seed não crescer;
  - as colunas de calorias e macros passam a ser a **média dos 7 dias**, então a lista e o detalhe mostram os mesmos
    números. O seed é regenerado;
  - ao abrir uma dieta pronta, a tela mostra os **7 dias da semana N** (de segunda a domingo), montados na hora;
  - "Gerar sugestão" não usa mais `pick()`: monta os dias pelo `buildDays`.

## 6. Criar a sugestão

O cartão "Sugestão da semana" da tela Plano alimentar passa a ter:
- **Duração:** 7 ou 14 dias (padrão 14);
- **Início:** data, com padrão hoje. Substitui o campo "semana", porque a semana sai da data;
- calorias, versão por idade e restrições, como hoje.

"Gerar sugestão" cria o rascunho v2 com o título "Plano alimentar – 23/11 a 06/12" e abre o editor. A regra de
"descartar o rascunho atual" continua. `source_diet_code` guarda o nome da dieta da semana do primeiro dia (ex.:
`1500kcalSemana48`).

## 7. Editor (equipe)

- Abas com a data e o dia da semana ("Seg 23/11", "Ter 24/11", …). Cada aba mostra as refeições daquele dia, editáveis
  como hoje.
- "Total do dia" é o da aba aberta, comparado à meta. Acima das abas há uma linha com as calorias de cada dia, e o dia
  fora de ±10 % da meta aparece destacado.
- Ações por dia: "Copiar de outro dia" e "Limpar dia". O número de dias e o início não mudam no editor, porque isso se
  decide ao gerar.
- O salvamento automático, a revisão e o conflito funcionam como hoje, com a estrutura v2 inteira.
- "Salvar como modelo" num plano v2 salva **o dia aberto** como modelo v1.

## 8. Portal da família

- **Janela:** hoje e os 6 dias seguintes que existirem no plano publicado. Abre no dia de hoje, com abas para os outros
  dias ("Hoje", "Amanhã", "Qua 26/11"…). Se o plano começa no futuro, a janela vai do início do plano em diante.
- **Dias acabaram** (hoje passou do último dia publicado): a família continua vendo **os últimos 7 dias publicados,
  repetindo em ciclo**, com as datas reais de hoje em diante. Nada indica à família que o plano venceu.
- **"Lista de compras"** no fim, somando os itens da opção 1 dos dias mostrados (sem as trocas "ou …"):
  - um item por alimento, com o nome amigável usado hoje (`FamilyPhrase`);
  - em **unidades** quando o alimento é contado em unidade, fatia ou pão; senão em **g ou kg**, arredondado para cima
    (múltiplos de 50 g); leite e bebidas em **litros**;
  - sem calorias e sem itens de "à vontade" sem quantidade.
- "Versão para imprimir": os mesmos 7 dias e a lista de compras.
- A regra "que dia do plano vale na data D" (o dia datado ou, depois do fim, o ciclo dos últimos 7) fica numa **função
  única**, por exemplo `PlanDays::dayFor(plan, date)`. Ela é usada pelo portal, pela impressão e pelo **diário**, que
  hoje usa `onDate()`: o diário compara o registro da família com o cardápio que ela viu naquele dia.
- Planos v1 e em texto aparecem como hoje.

## 9. Continuação automática

**Quando:** uma vez por dia, às 06:00, pela rodada do Cron que já existe (`cron/run.php`, a cada 5 min; `runIfDue`
como o backup). Para cada plano **publicado v2** de paciente ativo, a regra dispara quando faltam **4 dias ou menos**,
contando hoje (num plano de 14 dias, no 11º dia). A regra **não** dispara quando:
- o paciente já tem rascunho aberto. Nesse caso o sistema só avisa: "o plano termina em dd/mm e há um rascunho aberto";
- a continuação já foi gerada para esse plano. Uma vez só por plano: `meal_plans.continuation_draft_id`;
- o plano foi encerrado ou o paciente está inativo.

Sem meta de energia vigente, a continuação usa o mesmo nível de calorias do plano atual, que vem do `source_diet_code`.

**O que monta:** um **rascunho de continuação** com:
- os dias que ainda faltam do plano atual, de hoje em diante, copiados como estão, com as edições da profissional;
- mais **N dias novos** (N = a duração com que o plano foi criado, 7 ou 14, em `meal_plans.plan_days`), a partir do dia
  seguinte ao último. Eles usam a **meta de energia e as restrições do paciente naquele momento**, com a mesma regra de
  calorias do cartão. A versão por idade é recalculada, porque a criança pode ter feito 11 anos;
- título "Plano alimentar – dd/mm a dd/mm", as orientações do plano atual e `source_plan_id` apontando o plano atual.

**Rascunho de continuação descartado:** o sistema entende que é de propósito e **não** gera outra sozinho. O cartão do
painel continua e mostra "termina em dd/mm, sem continuação", com o botão **"Gerar continuação agora"**, que faz a
mesma montagem na hora. Esse botão também aparece na tela Plano alimentar do paciente quando faltam 4 dias ou menos.

**Publicação atrasada:** os dias novos não são empurrados. Se a continuação for publicada depois de algum dia novo já
ter passado, a família vê de hoje em diante. Os dias que passaram ela já tinha visto no ciclo.

Ao publicar a continuação, ela **substitui** o plano atual pelo fluxo normal. Como carrega os dias restantes, a família
não perde nenhum dia. Os dias que já passaram até a publicação ficam no plano, mas não aparecem para a família.

**Aviso:**
- e-mail, pela fila que já existe, para **quem publicou** o plano atual, se ainda estiver ativo e tiver acesso ao
  paciente; senão, para os médicos ativos. Assunto: "Plano alimentar de {primeiro nome} para revisar". Corpo: até quando
  vai o plano, link para o rascunho. Sem dados clínicos além do nome;
- cartão **"Planos para revisar"** no painel inicial, visível para quem tem acesso a Nutrição do paciente (a nutricionista
  só vê os pacientes liberados):
  - continuação pendente: "termina em dd/mm";
  - **vermelho** quando os dias publicados já acabaram: "família vendo repetição desde dd/mm";
  - rascunho aberto com plano terminando, no caso em que a regra não disparou.

## 10. Telas da equipe

- Plano publicado (`show`): todos os dias com data e as calorias de cada dia. O dia de hoje aparece marcado.
- "Dietas prontas" (`show`): os 7 dias da semana N.
- Impressão e PDF da equipe: todos os dias do plano; a lista de compras fica opcional.
- Linha do tempo e auditoria: a continuação gerada pelo sistema é registrada como criada por "sistema", com
  `created_by` = quem publicou o plano atual e `meta.auto = true`, porque `created_by` não aceita nulo.

## 11. Dados (migração `018_meal_plan_days.sql`)

Somente sintaxe compatível com MySQL 5.7:
- `meal_plans.plan_days TINYINT UNSIGNED NULL`: duração escolhida, 7 ou 14; nulo nos planos v1;
- `meal_plans.continuation_draft_id INT UNSIGNED NULL`: rascunho gerado a partir deste plano;
- `meal_plans.auto_generated TINYINT(1) NOT NULL DEFAULT 0`;
- a rodada diária guarda o "último dia rodado" do mesmo jeito que o backup e o lembrete da véspera.

## 12. Fora do escopo

Planos em texto, modelos com vários dias, lista de compras editável, publicação automática, aviso para a família.

## 13. Testes

- `buildDays`: 14 dias, todos a ±3 %; almoço e jantar não se repetem em 7 dias seguidos; a estação muda no dia certo;
  a mesma data dá o mesmo cardápio; restrições aplicadas em todos os dias.
- Resolver, calculadora e publish com v2: validação com a data na mensagem e totais pela média.
- Janela do portal: começo, meio, plano no futuro e **ciclo dos últimos 7 dias** depois do fim. A família não vê
  calorias nem dias fora da janela.
- Lista de compras: soma, unidades, kg e litros, sem trocas.
- Continuação: dispara com 4 dias restantes e não antes; não duplica; respeita rascunho aberto; copia os dias
  restantes; usa a meta e as restrições atuais; e-mail para quem publicou; cartão do painel com as permissões da
  nutricionista; publicar a continuação não cria buraco.
- Editor: abas, total do dia aberto, salvar e o conflito com v2. Teste de fumaça das rotas e manual atualizados.

## 14. Manual

Atualizar as seções "Plano alimentar" e "Dietas prontas" (médica e nutricionista) e explicar o aviso de continuação.
Regenerar os PDFs da médica e da nutricionista.

## 15. Decisões do Claude (para conferir)

- Montar os dias na hora, sem crescer o seed.
- Duração padrão de 14 dias, início padrão hoje.
- Gatilho com 4 dias restantes, rodada às 06:00.
- Continuação = dias restantes + N dias novos, publicada pelo fluxo normal.
- Totais do plano pela média dos dias; destaque para o dia fora de ±10 % no editor.
- Lista de compras só com a opção 1, sem trocas, arredondada para cima.
- E-mail para quem publicou (ou para os médicos, se essa pessoa não puder mais).
- "Salvar como modelo" num plano com dias salva o dia aberto.
- Cardápio pela semana ISO e pelo dia da semana, para valer igual todo ano; a dieta pronta mostra a média dos 7 dias.
- Rascunho de continuação descartado não volta sozinho: fica o botão "Gerar continuação agora".
- Continuação publicada com atraso não empurra as datas.
- O diário segue o mesmo dia que a família viu, incluindo o ciclo.
