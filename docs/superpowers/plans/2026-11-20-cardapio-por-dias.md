# Cardápio por dias (7/14) com continuação automática — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Sugestão da semana" passa a gerar 7 ou 14 dias datados. A família vê hoje e os 6 dias seguintes, com a lista
de compras, e repete os últimos 7 dias se o plano acabar. O sistema monta sozinho a continuação, como rascunho, quando
faltam 4 dias, e avisa a equipe.

**Architecture:**
- Estrutura v2 (`{v:2,start,days:[{meals}]}`) ao lado da v1.
- Regras de data em uma classe pura `App\Nutrition\PlanDays`, usada pelo portal, pelo diário, pela impressão e pela
  continuação.
- `DietBuilder::buildDays` monta os dias na hora (blocos por semana ISO × dia da semana), lendo os alimentos do banco
  com `fromDatabase`.
- Continuação em `App\Diets\PlanContinuation`, chamada pelo `cron/run.php` (uma vez por dia, a partir das 06:00) e
  por um botão.

**Tech Stack:** PHP 8.3 MVC próprio, MySQL 5.7, PHPUnit (`source tools/env.sh; start_mariadb; php tools/phpunit.phar`),
JS sem framework (CSP sem inline), node `--test` para JS puro.

**Spec:** `docs/superpowers/specs/2026-11-20-cardapio-por-dias-design.md`

## Global Constraints
- **Banco:** MySQL 5.7, sem sintaxe só de MariaDB. Migração nova: `018_meal_plan_days.sql`.
- **CSP:** nenhum `<script>` ou `style=` inline. Dados para JS via `data-*`.
- **v2:** `{"v":2,"start":"YYYY-MM-DD","days":[{"meals":[…v1…]}]}`. O dia `i` tem a data `start + i`. De 1 a 31 dias.
  Limites de cada dia iguais aos da v1. A v1 continua valendo em todo lugar (planos antigos e modelos).
- **Durações:** 7 ou 14 dias (padrão 14). Início padrão: hoje.
- **Calorias:** cada dia a ±3 % da meta (`DietBuilder::TOLERANCE`). Os totais gravados no plano são a **média dos
  dias**. No editor, o dia fora de ±10 % da meta fica destacado.
- **Família:** nunca vê calorias. A janela é de 7 dias, a partir de hoje (ou do início, se o plano for futuro). Depois
  do último dia, ciclo dos últimos 7 dias publicados, sem avisar a família.
- **Continuação:**
  - quando dispara: faltam ≤ 4 dias contando hoje (`lastDate - today ≤ 3`), uma vez por plano, a partir das 06:00;
  - o que leva: os dias restantes (de hoje em diante) mais `plan_days` dias novos, com a meta e as restrições atuais;
  - discard não regenera.
- **E-mail:** assunto `Plano alimentar de {primeiro nome} para revisar`. Vai para quem publicou o plano (ativo e com
  acesso a NUTRICAO); senão, para os médicos ativos.
- **Títulos:**
  - plano: `Plano alimentar – dd/mm a dd/mm`;
  - dieta pronta (biblioteca): `Plano alimentar – semana N (estação)`, como hoje.
- **Commits:** terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nunca `git commit -a`; nunca
  adicionar `o que fazer.txt`.
- **PHPUnit:** nunca rodar dois ao mesmo tempo, porque compartilham o `rubia_test`.
- **Branch:** `cardapio-por-dias`, criada a partir de `fase1`.

## Review Focus
1. **Plano que atravessa a virada do ano** (início em 28/12, 14 dias): datas e semanas ISO corretas, a semana 53 usa
   a 52, e as abas e o portal mostram as datas certas. Teste no Task 1 (`PlanDays`) e no Task 3 (`buildDays`).
2. **Portal antes do início e depois do fim:**
   - plano que começa amanhã: a janela começa amanhã, nunca vazia;
   - plano que acabou há 10 dias: ciclo com as datas reais de hoje em diante.
   Teste no Task 1 e no Task 7.
3. **Continuação com rascunho manual aberto:** não descarta nem sobrescreve o rascunho; só avisa. Rodar o Cron duas
   vezes no mesmo dia não duplica. Teste no Task 8.
4. **Editar um plano v2 publicado** ("Editar (novo rascunho a partir deste)" e "Cópia do plano vigente"): `draftFrom`
   mantém a v2, `start` e `plan_days`. Teste no Task 2.
5. **Nutricionista sem liberação:** não vê o cartão do painel nem recebe o e-mail daquele paciente. Teste no Task 9
   e no Task 8.

---

### Task 1: `PlanDays` — regras de data (puro)

**Files:**
- Create: `app/Nutrition/PlanDays.php`
- Test: `tests/Unit/PlanDaysTest.php`

**Interfaces — Produces:**
- `PlanDays::isMulti(?array $structure): bool`: `v === 2`.
- `PlanDays::days(array $structure): list<array{date:?string, meals:array}>`. A v1 vira um único dia com `date = null`.
- `PlanDays::lastDate(array $structure): ?string`: null na v1.
- `PlanDays::indexFor(array $structure, string $ymd): ?int`: índice do dia que vale em `$ymd`.
  - antes do início: null;
  - dentro do plano: o próprio índice;
  - depois do fim: ciclo dos últimos `min(7, n)` dias, ou seja `n - k + ((diasDepoisDoFim - 1) mod k)`, com
    `k = min(7, n)`;
  - v1: sempre 0.
- `PlanDays::window(array $structure, string $today, int $size = 7): list<array{date:string, index:int}>`: começa em
  `max(today, start)`; usa `indexFor` em cada data. Na v1: `[]`, e quem chama mostra o plano como hoje.
- `PlanDays::dayStructure(array $structure, int $index): array`: a v1 `{v:1,meals}` daquele dia, para o
  `_plan_body`, a calculadora e "salvar como modelo".
- `PlanDays::remainingFrom(array $structure, string $today): int`: dias de `today` até `lastDate`, inclusive; 0 se
  já passou.

- [ ] **Step 1: Write the failing tests**

```php
// plano de 14 dias com start 2026-11-23
test_index_inside_before_and_after_end(): indexFor('2026-11-23')=0, ('2026-12-06')=13, ('2026-11-22')=null,
    ('2026-12-07')=7, ('2026-12-13')=13, ('2026-12-14')=7        // ciclo dos ultimos 7
test_short_plan_cycles_all_days(): 3 dias, start 2026-11-23: indexFor('2026-11-26')=0, ('2026-11-28')=2, ('2026-11-29')=0
test_window_starts_today_or_at_start(): window(p,'2026-11-20') datas 2026-11-23..2026-11-29;
    window(p,'2026-12-04') = [04/12→11, 05/12→12, 06/12→13, 07/12→7, 08/12→8, 09/12→9, 10/12→10]
test_year_boundary(): start 2026-12-28, 14 dias: lastDate = '2027-01-10'; window(p,'2027-01-01')[0]['index'] = 4
test_v1_is_single_day(): isMulti=false; days(v1) = [[date=>null, meals=>…]]; indexFor(v1, qualquer)=0; window(v1,…)=[]
test_remaining(): remainingFrom(p,'2026-12-03')=4; ('2026-12-06')=1; ('2026-12-07')=0
```

- [ ] **Step 2:** Run `php tools/phpunit.phar --filter PlanDaysTest`. Expected: FAIL (class not found).
- [ ] **Step 3:** Implement `PlanDays` with `DateTimeImmutable`. No database access.
- [ ] **Step 4:** Run the same command. Expected: PASS.
- [ ] **Step 5:** Commit `feat: PlanDays (dias datados, janela e ciclo)`.

### Task 2: v2 no resolver, calculadora, publicação, cópia + migração 018

**Files:**
- Create: `database/migrations/018_meal_plan_days.sql`
- Modify:
  - `app/Nutrition/MealPlanResolver.php` (`resolve`)
  - `app/Nutrition/MealPlanCalculator.php`
  - `app/Nutrition/MealPlanRepository.php` (`createDraft`, `draftFrom`, `publish`, `discard`, `decode`)
- Test:
  - `tests/Unit/MealPlanResolverTest.php` (existing; add tests)
  - `tests/Integration/MealPlanRepositoryTest.php` (existing, or create it)
  - `tests/Integration/MigratorTest.php` (18 migrations)

**Interfaces:**
- Consumes: `PlanDays` (Task 1).
- Produces:
  - Migration columns: `meal_plans.plan_days TINYINT UNSIGNED NULL`, `meal_plans.continuation_draft_id INT UNSIGNED
    NULL`, `meal_plans.auto_generated TINYINT(1) NOT NULL DEFAULT 0`. Add with `ALTER TABLE … ADD COLUMN`; no FK on
    `continuation_draft_id`, to avoid a circular FK.
  - `MealPlanResolver::resolve(array $input, bool $allowEmptyMeals = false): array`:
    - with `v === 2`, it validates `start` (valid `Y-m-d`) and `1 ≤ count(days) ≤ 31`;
    - each day goes through the current meal validation, and its errors are prefixed with `Dia dd/mm (seg): `;
    - output: `{v:2,start,days:[{meals}]}`;
    - without `v`, or with `v=1`: exactly as today.
  - `MealPlanCalculator::plan(array $structure): array{days:list<totals>, average:totals}`. On v1 it is
    `days=[day()]` and `average=day()`. `day()` stays single-day.
  - `MealPlanRepository::createDraft(..., ?string $sourceDietCode = null, ?int $planDays = null, bool $auto = false)`.
  - `publish`:
    - validates each day (messages with the date);
    - writes `MealPlanCalculator::plan()['average']`;
    - only when publishing a continuation (`source_plan_id` = the current published plan and that plan's
      `continuation_draft_id` = this draft): copies `plan_days` from the source if it is null.
  - `draftFrom` copies `plan_days` and keeps the v2 as is.
  - `discard` does not touch `continuation_draft_id`: discarding means "do not continue".

- [ ] **Step 1: Write the failing tests**

```php
test_resolve_v2_keeps_days_and_start()            // 2 dias validos -> v=2, start igual, 2 days
test_resolve_v2_rejects_bad_start_and_too_many_days() // start '2026-13-01' e 32 dias -> InvalidPlanException
test_resolve_v2_error_names_the_date()            // dia 2 com item sem alimento -> erro comeca com 'Dia 24/11 (ter): '
test_plan_average(): dias de 1000 e 1200 kcal -> average.kcal = 1100.0, days[1].kcal = 1200.0
test_publish_v2_stores_average_and_draftFrom_keeps_v2(): após publish total_kcal = média;
    draftFrom -> structure.v = 2, start igual, plan_days igual
test_publish_v2_empty_day_fails_with_date()       // dia sem itens -> RuntimeException contendo '24/11'
```

- [ ] **Step 2:** Run with `--filter 'MealPlanResolverTest|MealPlanRepositoryTest|MigratorTest'`. Expected: FAIL.
- [ ] **Step 3:** Implement the migration and the changes listed above.
- [ ] **Step 4:** Run the same filter. Expected: PASS. Then run the full suite, alone. Expected: everything green
  except the 1 existing skip.
- [ ] **Step 5:** Commit `feat: estrutura v2 (dias datados) no plano alimentar`.

### Task 3: Cardápio ampliado + `DietBuilder::buildDays` + `fromDatabase` + seed

**Files:**
- Modify:
  - `data/dietas/cardapio.php`: new lunch and dinner blocks, and dinners for kids;
  - `app/Diets/DietBuilder.php`;
  - `app/Diets/DietSeed.php`: no change in format;
  - `tools/build_diets.php`;
  - `database/seeds/food_4_diet_library.sql`: regenerate it.
- Test: `tests/Unit/DietBuilderTest.php`

**Interfaces — Produces:**
- `DietBuilder::buildDay(string $band, string $ymd, int $kcal): array{meals:list, totals:array, season:string}`.
  - The day index is `t = seasonIndex(week) * 7 + weekday`, where `week = weekOf($ymd)` and `weekday` = 0 (Monday)
    to 6 (Sunday).
  - Block choice: `pool[(t + mi) % count(pool)]` for lunch and dinner, and `pool[(t * (2*mi+1) + mi) % count]` for
    the other meals.
  - Fruits, vegetables and salads use `t` instead of `i`.
  - The ceia rule, `fit()`, rounding and the "ou …" swaps stay as they are today.
- `DietBuilder::buildDays(string $band, int $kcal, string $startYmd, int $n): array{code:string, title:string,
  guidance:string, structure:array /*v2*/, totals:list}`.
  - `code = code($kcal, weekOf($startYmd), $band)`;
  - `title = 'Plano alimentar – dd/mm a dd/mm'`;
  - guidance by the season of the first day.
- `build($band, $week, $kcal)`, for the library:
  - `structure` = the Monday of week N, built with `buildDay` on a reference date for week N (same algorithm, so
    `t` depends only on week and weekday);
  - `totals` = **average of the 7 days**.
- `DietBuilder::fromDatabase(\App\Core\Database $db): self`. It reads:
  - `foods` where `source='taco'`, keyed by `taco_code`, with kcal/protein_g/carb_g/fat_g/fiber_g;
  - `food_measures`, keyed by food and name, with `id` and `grams`.
  Same constructor format as `fromDataDir`.
- Required in `cardapio.php`, for each band and season:
  - lunch and dinner pools with **≥ 8 blocks**;
  - every other pool with ≥ 2 blocks and a size that is **not a multiple of 7**, otherwise the week repeats.
  - Foods must be easy to find, from TACO; reuse existing codes whenever possible.

- [ ] **Step 1: Write the failing tests** (in `DietBuilderTest`)

```php
test_pool_sizes_per_band_and_season()      // regra acima para cada banda x estacao x refeicao
test_build_days_fourteen_within_tolerance() // buildDays('adulto',1800,'2026-11-23',14): 14 dias, cada um a ±3%
test_no_lunch_or_dinner_repeat_in_7_days()  // para cada banda, 2026-03-23..2026-06-21 (outono): blocos de almoco
                                            // e de jantar distintos em toda janela de 7 dias seguidos
test_consecutive_days_differ()              // cafe, lanches e ceia: dia d != dia d+1 (mesma estacao)
test_season_changes_on_the_right_day()      // buildDays inicio 2026-03-16 (semana 12 -> 13): dia da semana 13 usa frutas de outono
test_same_date_same_menu()                  // dois buildDay iguais -> mesma estrutura
test_year_boundary_days()                   // inicio 2026-12-28, 14 dias: datas ate 2027-01-10, sem excecao
test_library_totals_are_7_day_average()     // build('adulto',30,2000)['totals']['kcal'] == media dos 7 buildDay da semana 30
```

  Keep the existing tests. `test_counts_names_and_tolerance` stays (average and Monday within ±3 %). Remove
  `test_weeks_differ_within_a_season` only if it no longer applies; otherwise adapt it to the Monday.
- [ ] **Step 2:** Run `--filter DietBuilderTest`. Expected: FAIL.
- [ ] **Step 3:** Write the new blocks in `cardapio.php` (Brazilian dishes, everyday foods, sensible portions), then
  implement `buildDay`, `buildDays`, `fromDatabase` and the new `build()`.
- [ ] **Step 4:** Run `php tools/build_diets.php --sql-only`, then `--filter 'DietBuilderTest|DietSuggestionTest'`.
  Expected: PASS, including `test_seed_file_is_up_to_date`. The seed must stay at about 5 MB. Report the actual size.
- [ ] **Step 5:** Commit `feat: dietas prontas por dia (semana x dia da semana) e cardapio ampliado`.

### Task 4: Sugestão gera 7/14 dias

**Files:**
- Modify:
  - `app/Diets/DietController.php` (`suggest`, `patientCard`, `show`);
  - `app/Diets/views/_patient_cards.php`;
  - `app/Diets/views/show.php`;
  - `app/Diets/DietAdapter.php`, only if a helper for v2 is needed.
- Test: `tests/Http/DietSuggestionTest.php`

**Interfaces:**
- Consumes:
  - `DietBuilder::fromDatabase`, `buildDays` (Task 3);
  - `createDraft(..., $sourceDietCode, $planDays)` (Task 2);
  - `PlanDays` (Task 1).
- Produces:
  - `DietController::buildSuggestion(int $pid, string $band, int $kcal, string $startYmd, int $n): array{structure:array
    /*v2 resolvido*/, title:string, guidance:string, code:string, changes:list<string>, warnings:list<string>}`.
    It builds, adapts each day with `DietAdapter` (merging changes and warnings without repeats) and resolves.
    Task 8 reuses it.
  - Form fields: `dias` (7|14), `inicio` (Y-m-d, from today−7 to today+60), `kcal`, `versao`. The `semana` field goes
    away.
  - `patientCard` returns `start` (today) and `days` (14) instead of `week`.
  - `/dietas-prontas/{code}` shows the 7 days of week N, from Monday to Sunday. Use any date in week N of the current
    year; the menu depends only on week × weekday.

- [ ] **Step 1: Write the failing tests**

```php
testSuggestCreatesFourteenDatedDays(): post dias=14, inicio=hoje -> draft v2, 14 days, start=hoje,
    plan_days=14, title 'Plano alimentar – dd/mm a dd/mm', sem 'kcal' no JSON
testSevenDaysOption(): dias=7 -> 7 days
testRestrictionsAppliedOnEveryDay(): restricao 'leite' -> nenhum food_id 457/458 em nenhum dia
testInvalidDurationOrStart(): dias=10 ou inicio='x' -> flash erro, sem rascunho
testLibraryShowListsSevenDays(): GET /dietas-prontas/1500kcalSemana5 contem 'Segunda' ... 'Domingo'
```

  Update the existing tests that post `semana`.
- [ ] **Step 2:** Run `--filter DietSuggestionTest`. Expected: FAIL.
- [ ] **Step 3:** Implement. `suggest` no longer uses `pick()`; it keeps the rule "descartar o rascunho atual".
- [ ] **Step 4:** Run `--filter 'DietSuggestionTest|SmokeAllRoutesTest'`. Expected: PASS.
- [ ] **Step 5:** Commit `feat: sugestao gera 7 ou 14 dias datados`.

### Task 5: Editor com abas por dia

**Files:**
- Modify:
  - `public/assets/js/meal-plan-editor.js`;
  - `app/Nutrition/views/edit_structured.php`;
  - `app/Nutrition/MealPlanController.php` (`editorData`, `totalsPayload`);
  - `public/assets/css/app.css`.
- Create:
  - `public/assets/js/plan-days.js`: pure functions, for node;
  - `tests/js/plan-days.test.js`.
- Test: `tests/Http/MealPlanStructuredTest.php`

**Interfaces — Produces:**
- `editorData`: for v2 it returns `{v:2, start, days:[{date, label:'Seg 23/11', meals:[…]}], foods}`; for v1, as
  today.
- `totalsPayload`:
  - v2: `{totals:{days:[{day, options}]}, percents:[…], average}`;
  - v1: as today.
- `plan-days.js` exports, with UMD-lite (`module.exports` when it exists, `window.PlanDaysJs` otherwise):
  - `dayLabel(startYmd, i)`;
  - `offTarget(kcal, meta)`: `|kcal − meta| > 0.10 × meta`.
- Editor:
  - `meals` points to `days[cur].meals`;
  - tab bar (`role="tablist"`) with a kcal summary per day (`pe-dia-fora` class when `offTarget`);
  - "Copiar de outro dia" (a `<select>` + button) and "Limpar dia" (confirmation through `confirm-submit`-style
    markup, never `alert`);
  - `payload()` sends `{v:2,start,days}`;
  - autosave and conflict handling unchanged.
  - The "Total do dia" heading becomes "Total do dia (Seg 23/11)".
  - On v1: no tabs, exactly as today.

- [ ] **Step 1: Write the failing tests**

```js
// tests/js/plan-days.test.js
dayLabel('2026-11-23', 0) === 'Seg 23/11'; dayLabel('2026-12-28', 5) === 'Sáb 02/01'
offTarget(1650,1500) === false; offTarget(1651,1500) === true
```
```php
testEditorDataV2HasDayLabels(); testSaveV2RoundTrip()   // POST estrutura v2 -> 200, totals.days com 2 itens
```

- [ ] **Step 2:** Run `node --test tests/js/plan-days.test.js` and `--filter MealPlanStructuredTest`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the same tests (PASS). Then a manual check in the dev app using the `run` skill: open a 14-day
  draft, switch tabs, edit, autosave, publish. Report what you saw.
- [ ] **Step 5:** Commit `feat: editor do plano com abas por dia`.

### Task 6: Telas da equipe, impressão, PDF e modelo

**Files:**
- Modify:
  - `app/Nutrition/views/_plan_body.php`;
  - `app/Nutrition/views/show.php`;
  - `app/Nutrition/views/print.php`;
  - `app/Nutrition/views/_plan_doc.php`;
  - `app/Nutrition/TemplateController.php` (save-as-template);
  - `app/Diary/DiaryController.php`;
  - `app/Diary/views/week.php`.
- Test:
  - `tests/Http/MealPlanStructuredTest.php`;
  - `tests/Http/MealPlanPdfTest.php`;
  - `tests/Http/TemplateTest.php`;
  - `tests/Http/DiaryWeekPagesTest.php`.

**Interfaces:**
- Consumes: `PlanDays`, `MealPlanCalculator::plan`.
- Produces:
  - `_plan_body.php` takes an optional `$dayIndexes` (list<int>) and `$dayDates` (list<string>).
    - v2: one `<section class="plano-dia" id="dia-YYYY-MM-DD">` per day, with an `<h2>` "Segunda, 23/11". Without
      `$dayIndexes`, it shows every day.
    - Staff only: kcal of the day.
    - v1: as today.
  - `show`: every day, with "hoje" marked; per-day and average totals.
  - Save-as-template on v2: a `dia` field (0..n-1) whose day goes into the template as v1.
  - Staff print and PDF: every day.
  - Diary: for date D, a v2 plan uses `PlanDays::indexFor` and shows only that day.

- [ ] **Step 1: Write the failing tests**

```php
testShowV2ListsEveryDayWithKcal()            // 14 cabecalhos "plano-dia", kcal por dia
testPdfV2HasAllDaysAndNoKcal()               // PDF da familia: sem 'kcal'
testSaveTemplateFromV2Day()                  // dia=3 -> modelo v1 com as refeicoes do dia 3
testDiaryShowsTheDayOfThePlan()              // registro de alimentacao em D -> so o dia D do plano v2
testDiaryAfterEndShowsCycledDay()            // D = lastDate+1 -> dia index n-7
```

- [ ] **Step 2:** Run `--filter 'MealPlanStructuredTest|MealPlanPdfTest|TemplateTest|DiaryWeekPagesTest'`.
  Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit `feat: plano por dias nas telas da equipe, impressao, PDF, modelo e diario`.

### Task 7: Portal da família — janela de 7 dias e lista de compras

**Files:**
- Create:
  - `app/Nutrition/ShoppingList.php`;
  - `tests/Unit/ShoppingListTest.php`.
- Modify:
  - `app/Portal/PortalController.php` (`food`, `foodPrint`);
  - `app/Portal/views/food.php`;
  - `app/Portal/views/food_print.php`;
  - `public/assets/css/app.css`.
- Test: `tests/Http/PortalFoodTest.php`

**Interfaces — Produces:**
- `ShoppingList::build(list<array> $dayStructures /*v1 cada*/): list<array{name:string, text:string}>`. Only option 1
  of each meal, no swaps. It groups by `food_id`.
  - unit-type measure (name starting with `unidade`, `fatia`, `pão`): text "N unidades/fatias", rounded up;
  - otherwise, food whose name starts with `Leite` or that is in the `bebidas` group: litres, rounded up to 0.5 L
    ("1,5 L");
  - otherwise grams: below 1000 g, rounded up to the next 50 g ("350 g"); from 1000 g on, kg with one decimal place
    ("1,2 kg").
  - The name uses `FamilyPhrase`, without the quantity. Sorted by name.
- Portal `food`:
  - v2: `PlanDays::window($structure, today)` → nav with in-page links (`#dia-…`: "Hoje", "Amanhã", "Qua 26/11");
  - the 7 days through `_plan_body` with `$family = true`;
  - then "Lista de compras".
  - v1 and text: as today.
  - `foodPrint`: the same 7 days and the list.

- [ ] **Step 1: Write the failing tests**

```php
// ShoppingListTest
test_sums_units_across_days(): banana 1 unidade em 3 dias -> '3 unidades'
test_grams_round_up_to_50_and_kg(): 3 x 120 g frango -> '400 g'; 9 x 140 g -> '1,3 kg'
test_milk_in_litres(): 5 x 200 g leite -> '1 L'; 7 x 200 g -> '1,5 L'
test_subs_and_other_options_ignored()
// PortalFoodTest
testPortalShowsOnlySevenDaysFromToday()     // plano 14 dias iniciado ha 3 dias: 7 secoes, a primeira = hoje
testPortalFuturePlanStartsAtStart()
testPortalAfterEndCyclesLastSeven()         // plano acabou ha 10 dias: 7 secoes com datas de hoje em diante
testPortalHasShoppingListAndNoKcal()
```

- [ ] **Step 2:** Run `--filter 'ShoppingListTest|PortalFoodTest'`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit `feat: portal mostra 7 dias e lista de compras`.

### Task 8: Continuação automática (Cron + botão + e-mail)

**Files:**
- Create:
  - `app/Diets/PlanContinuation.php`;
  - `tests/Integration/PlanContinuationTest.php`.
- Modify:
  - `cron/run.php`: a new try/catch block, the same pattern as the backup;
  - `app/Core/Container.php` (`planContinuation()`);
  - `app/routes.php`: `POST /planos/{planId}/continuacao` → `DietController::continueNow`;
  - `app/Diets/DietController.php`;
  - `app/Nutrition/views/index.php`: the "Gerar continuação agora" button when `remainingFrom ≤ 4` and there is no
    draft;
  - `README.md`: mention it in the Cron section.
- Test:
  - `tests/Integration/PlanContinuationTest.php`;
  - `tests/Http/DietSuggestionTest.php`, for the button.

**Interfaces:**
- Consumes:
  - `DietController::buildSuggestion` (Task 4); move it to a service if it is cleaner, keeping the signature;
  - `PlanDays`, `MealPlanRepository`, `Mailer::queue`.
- Produces:
  - `PlanContinuation::runIfDue(string $now /*'Y-m-d H:i'*/): int`:
    - before 06:00: 0;
    - lock and key `settings.plan_continuation_day`, the same pattern as `ReminderRound`;
    - returns how many drafts were created.
  - `PlanContinuation::due(string $today): list<array>`: published v2 plans whose patient has no `deleted_at`,
    `remainingFrom ≤ 4`, and `continuation_draft_id IS NULL`.
  - `PlanContinuation::generate(int $planId, int $by, bool $auto, string $today): int`: id of the draft.
    - Days: the current plan from `today` to `lastDate`, plus `plan_days` (fallback 14) new days from `lastDate + 1`.
    - Band: by today's age.
    - kcal: rule of `patientCard`; without a goal, the plan's `kcal_target`, taken from the number in
      `source_diet_code` (`/^(\d+)kcal/`), fallback `lo + 500`.
    - `source_plan_id` = the plan, `auto_generated` = `$auto`, guidance from the current plan.
    - It sets `continuation_draft_id` on the current plan and audits `meta.auto`.
    - If the patient already has an open draft: throws `\RuntimeException('Já existe um rascunho aberto.')`.
  - In the round:
    - `$by` = the plan's `published_by`, `$auto = true`;
    - patient with an open draft: no draft; it queues the e-mail "o plano termina em dd/mm e há um rascunho aberto",
      once per plan; mark it with `continuation_draft_id = 0`;
    - otherwise: `generate` + e-mail.
  - The dashboard (Task 9) treats `0` as "has an open draft".
  - E-mail recipients: see Global Constraints. The HTML has the first name, the end date and the link
    `url('/planos/{id}/editar')` (absolute base from `Config::get('app.url')`, as in `AgendaMailer`). No clinical data.
  - `DietController::continueNow` (POST, NUTRICAO edit): `generate($planId, $user, false, today)` → redirect to the
    editor.
    - It works even when `continuation_draft_id` is filled but that draft was discarded or is 0.
    - When the plan's continuation draft is still open, it redirects to it.

- [ ] **Step 1: Write the failing tests**

```php
test_not_before_6am_and_once_per_day()       // '06:00' cria 1; segunda chamada no mesmo dia -> 0
test_triggers_with_4_days_left_not_5()       // lastDate = hoje+3 dispara; hoje+4 nao
test_draft_has_remaining_plus_new_days()     // plano 14 dias, hoje = dia 11: rascunho com 4 + 14 = 18 dias,
                                             // start = hoje, dias 0..3 iguais aos do plano, plan_days = 14
test_uses_current_goal_and_restrictions()    // meta muda para 2000 e restricao 'ovo' -> dias novos ~2000 kcal, sem ovo
test_open_manual_draft_is_kept_and_mailed()  // rascunho manual intacto, 1 e-mail, sem novo rascunho; rodada seguinte nao reenvia
test_discarded_continuation_not_regenerated()// descarta -> due() nao traz o plano
test_mail_goes_to_publisher_or_doctors()     // publicadora inativa -> medicos ativos
test_publishing_continuation_has_no_gap()    // publica: portal hoje = dia 0 do rascunho
// DietSuggestionTest
testContinueNowButton()                      // POST /planos/{id}/continuacao -> redirect editor, rascunho v2
```

- [ ] **Step 2:** Run `--filter 'PlanContinuationTest|DietSuggestionTest'`. Expected: FAIL.
- [ ] **Step 3:** Implement. In `cron/run.php`, call `runIfDue(date('Y-m-d H:i'))` in its own try/catch, writing to
  STDERR `[cron] continuacao: …`.
- [ ] **Step 4:** Run the same filter. Expected: PASS. Then run `php cron/run.php` once in dev. Expected: exit 0.
- [ ] **Step 5:** Commit `feat: continuacao automatica do plano por dias com aviso por e-mail`.

### Task 9: Cartão "Planos para revisar" no painel

**Files:**
- Modify:
  - `app/Dashboard/DashboardController.php`;
  - `app/Dashboard/views/index.php`;
  - `app/Diets/PlanContinuation.php`: add `reviewList`.
- Test: `tests/Http/DashboardTest.php`

**Interfaces — Produces:**
- `PlanContinuation::reviewList(array $user, string $today): list<array{patient_id:int, name:string,
  kind:'pendente'|'vencido'|'rascunho_aberto'|'sem_continuacao', end:string, url:string}>`.
- It includes only patients that pass `Gate::check($user,'view',Policy::NUTRICAO,$pid)`. It considers published v2
  plans with `remainingFrom ≤ 4`, or already ended.
  - `vencido` (red, `selo-alerta`): the published days are over and no continuation has been published.
    Text: "família vendo repetição desde dd/mm".
  - `pendente`: the continuation draft is open. Text: "termina em dd/mm".
  - `sem_continuacao`: the continuation was discarded. Text: "termina em dd/mm, sem continuação", with the
    "Gerar continuação agora" button.
  - `rascunho_aberto`: `continuation_draft_id = 0`.
- The card does not show up when the list is empty.

- [ ] **Step 1: Write the failing tests**

```php
testReviewCardShowsPendingAndExpired()       // um pendente e um vencido (selo-alerta + 'família vendo repetição')
testNutritionistSeesOnlyGrantedPatients()
testNoCardWhenNothingToReview()
```

- [ ] **Step 2:** Run `--filter DashboardTest`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit `feat: cartao Planos para revisar no painel`.

### Task 10: Manual, README e verificação final

**Files:**
- Modify:
  - `app/Help/content/_clinica.php` (section `plano`);
  - `app/Help/content/_modelos.php` (section `dietas-prontas`);
  - `README.md` §9: deploy step (migration 018, seeds, Cron);
  - `tests/Http/HelpManualTest.php`, if the section order changes;
  - `public/assets/manual/pdf/manual-medica.pdf` and `manual-nutricionista.pdf`: regenerate them with
    `tools/manual_pdf.php`, and revert the other PDFs if they change.

The manual text must explain these steps in plain language:
- duration 7/14 and start date;
- the day tabs;
- what the family sees (7 days, shopping list);
- the automatic continuation, the e-mail and the card;
- repetition when nobody reviews;
- "Gerar continuação agora".
It never mentions calories for the family.

- [ ] **Step 1:** Update the texts and `HelpManualTest`.
- [ ] **Step 2:** Run the full suite **alone**. Expected: all green (1 existing skip). Run
  `node --test tests/js`. Expected: PASS.
- [ ] **Step 3:** Regenerate the two PDFs.
- [ ] **Step 4:** Commit `docs: manual e README do plano por dias e continuacao`.
