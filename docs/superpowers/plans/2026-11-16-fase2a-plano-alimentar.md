# Plano alimentar (Fase 2a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plano alimentar na ficha do paciente com dois modos (Texto com colar; Estruturado com TACO e cálculo), trocas por alimento e por opção de refeição, versões (rascunho → publicado → substituído/encerrado), modelos e trechos, "Minha tabela" e visão simples no portal da família.

**Architecture:** Novo módulo `app/Nutrition/` no padrão do projeto (repositórios com `Database` + `AuditLog`, controllers finos, views PHP, `Container` com singletons). Dados de referência (TACO, medidas POF) seguem o padrão OMS/PA: arquivos-fonte em `data/`, importador em `tools/`, seed SQL em `database/seeds/` carregado pelo `SeedLoader`. O plano estruturado é guardado como JSON **já resolvido** (nome, medida, gramas e valores por 100 g de cada item), o que dá o snapshot exigido pelo design sem tabelas filhas.

**Tech Stack:** PHP 8.1+ puro, MariaDB/MySQL (InnoDB, utf8mb4), PHPUnit 11 (`tools/phpunit.phar`), JS sem framework em `public/assets/js/` (CSP estrita).

**Spec:** `docs/superpowers/specs/2026-11-16-plano-alimentar-design.md` (e a geral `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`).

## Global Constraints

- PHP 8.1+, `declare(strict_types=1);` em todo arquivo PHP; sem Composer/Node em produção; bibliotecas só versionadas no repositório (nenhuma nova é necessária).
- CSP: `default-src 'self'; img-src 'self' data:` — **nenhum** `<script>` inline, `style=""`, nem `onclick=`; JS só em `public/assets/js/*.js` (o teste `tests/Unit/ViewsCspTest.php` cobra isso nas views).
- Toda saída em view via `e()`; HTML do usuário **nunca** passa adiante (texto colado vira HTML só pelo `PlanText::toHtml`, que escapa tudo).
- Migração nova: `database/migrations/008_meal_plans.sql` (única desta fase). Seeds: `database/seeds/food_1_taco.sql` e `database/seeds/food_2_pof_measures.sql` (o nome garante a ordem alfabética: TACO antes das medidas, que têm FK).
- Seeds de alimentos usam `INSERT ... ON DUPLICATE KEY UPDATE` (nunca `REPLACE`, que apagaria linhas referenciadas e quebraria FKs). Ids da TACO = código TACO (1–597); ids de medidas POF = `taco_code * 100 + n` (n = 1..99); `AUTO_INCREMENT = 100000` em `foods` e `food_measures` para os cadastros próprios nunca colidirem.
- Auditoria: leitura de plano pela equipe = `record(uid, 'view', 'meal_plans', planId, patientId, null, ['section' => 'nutricao'], $r)`; escritas com `create`/`update`/`publish`/`end`/`discard`.
- Permissão: seção `Policy::NUTRICAO`. Rotas da equipe exigem papel `medico` ou `nutricionista` **além** do Gate (a família passa a ter `view NUTRICAO`; sem a checagem de papel ela veria rascunhos).
- Textos de interface em português do Brasil; números com vírgula (`fmt_num`); datas `fmt_date`.
- Testes: `source tools/env.sh; start_mariadb` (se o banco não estiver rodando) e `php tools/phpunit.phar`. Toda nova rota GET entra em `tests/Http/SmokeAllRoutesTest.php::EXPECTED` na mesma tarefa que a cria (o teste falha se faltar).
- Commits pequenos, mensagem em português no estilo do repositório (`feat:`, `fix:`, `docs:`, `test:`), terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Família abrindo rotas da equipe** (`/pacientes/{id}/plano`, `/planos/{planId}`, `/alimentos/busca`): deve dar 403 ou redirecionar, nunca mostrar rascunho/histórico/kcal — teste na Task 5 e na Task 7.
2. **Texto colado do Word/WhatsApp com HTML, `<script>`, `javascript:`, marcadores `` (U+F0B7) e espaços não separáveis**: deve virar texto seguro com listas reconhecidas — testes na Task 3.
3. **Dois rascunhos/vigentes ao mesmo tempo** (duplo clique em "Novo plano"/"Publicar", duas abas): o banco deve continuar com no máximo um rascunho e um vigente — teste na Task 4.
4. **Quantidade digitada com vírgula, zero, negativa ou absurda, e medida de outro alimento** no estruturado: rejeitar com mensagem clara, sem gravar — teste na Task 2.
5. **Alimento próprio editado/desativado depois de um plano publicado**: o plano publicado e o portal continuam mostrando nome/valores antigos — teste na Task 7 (`test_publish_keeps_snapshot_after_food_change`).

---

### Task 1: Base de alimentos (TACO) e tabela de dados

**Files:**
- Create: `database/migrations/008_meal_plans.sql`
- Create: `data/taco/food.csv`, `data/taco/nutrients.csv`, `data/taco/categories.csv`, `data/taco/SOURCES.md`
- Create: `app/Nutrition/TacoImporter.php`, `app/Nutrition/FoodRepository.php`, `tools/import_taco.php`
- Create (gerado): `database/seeds/food_1_taco.sql`
- Modify: `app/Core/Container.php` (accessor `foods()`), `tools/migrate.php` (carregar seeds depois de migrar), `README.md` §9 passo 4
- Test: `tests/Unit/TacoImporterTest.php`, `tests/Integration/FoodRepositoryTest.php`

**Interfaces:**
- Produces:
  - Tabelas (todas em `008_meal_plans.sql`, InnoDB utf8mb4):
    - `foods(id INT UNSIGNED PK AUTO_INCREMENT, source ENUM('taco','proprio') NOT NULL, taco_code SMALLINT UNSIGNED NULL UNIQUE, name VARCHAR(200) NOT NULL, name_search VARCHAR(200) NOT NULL KEY, group_name VARCHAR(100) NULL, kcal DECIMAL(7,1) NULL, protein_g DECIMAL(6,1) NULL, carb_g DECIMAL(6,1) NULL, fat_g DECIMAL(6,1) NULL, fiber_g DECIMAL(6,1) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_by INT UNSIGNED NULL FK users, created_at, updated_at DATETIME NULL) AUTO_INCREMENT=100000`
    - `food_measures(id INT UNSIGNED PK AUTO_INCREMENT, food_id FK foods, source ENUM('pof','proprio'), name VARCHAR(100), name_plural VARCHAR(100) NULL, grams DECIMAL(7,1) NOT NULL, active TINYINT(1) DEFAULT 1, created_by NULL FK users, created_at, KEY(food_id)) AUTO_INCREMENT=100000`
    - `meal_plans(id PK, patient_id FK patients, mode ENUM('texto','estruturado'), title VARCHAR(150), status ENUM('rascunho','publicado','substituido','encerrado'), body_text MEDIUMTEXT NULL, guidance_text MEDIUMTEXT NULL, structure_json JSON NULL, total_kcal DECIMAL(7,1) NULL, total_protein_g / total_carb_g / total_fat_g / total_fiber_g DECIMAL(6,1) NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, source_template_id INT UNSIGNED NULL, source_plan_id INT UNSIGNED NULL FK meal_plans, created_by FK users, created_at, updated_by NULL FK users, updated_at NULL, last_audited_at NULL, published_by NULL FK, published_at NULL, superseded_at NULL, ended_by NULL FK, ended_at NULL, ended_reason VARCHAR(255) NULL, discarded_at NULL, KEY(patient_id, status))`
    - `meal_plan_templates(id PK, title VARCHAR(150), mode ENUM('texto','estruturado'), body_text MEDIUMTEXT NULL, guidance_text MEDIUMTEXT NULL, structure_json JSON NULL, created_by FK users, created_at, updated_at NULL, deleted_at NULL)`
    - `text_snippets(id PK, title VARCHAR(100), body TEXT NOT NULL, created_by FK users, created_at, updated_at NULL, deleted_at NULL)`
  - `TacoImporter::parse(string $foodCsv, string $nutrientsCsv, string $categoriesCsv): list<array{taco_code:int,name:string,group_name:string,kcal:?float,protein_g:?float,carb_g:?float,fat_g:?float,fiber_g:?float}>` (recebe **caminhos**).
  - `TacoImporter::seedSql(list $rows): string` — lotes de até 100 linhas, cada comando terminando em `;\n`, `ON DUPLICATE KEY UPDATE` de nome/grupo/nutrientes (não de `active`), `id = taco_code`, `source = 'taco'`.
  - `FoodRepository::normalize(string $s): string` — minúsculas, sem acento, espaços colapsados (`"Pão, Trigo,  FRANCÊS"` → `"pao, trigo, frances"`).
  - `FoodRepository::find(int $id): ?array` (inclui inativos), `FoodRepository::search(string $q, int $limit = 20): list<array>` (só ativos; cada termo da busca precisa aparecer em `name_search`; TACO e próprios juntos, próprios primeiro; cada resultado com chave `measures` = medidas ativas), `FoodRepository::measures(int $foodId): list<array>`, `FoodRepository::findMeasure(int $id): ?array`.
  - `Container::foods(): FoodRepository`.

- [ ] **Step 1: Baixar os arquivos-fonte (sem alterar) para `data/taco/`**

  Repositório `raulfdm/taco-api` (MIT), commit `06e466ad2f3d76064b587808b1804564b2c40f67`, pasta `references/csv/`: `food.csv` (`id,categoryId,name`), `nutrients.csv` (`foodId,moisture,kcal,kJ,protein,lipids,cholesterol,carbohydrates,dietaryFiber,...`), `categories.csv` (`id,name`). Ex.: `gh api "repos/raulfdm/taco-api/contents/references/csv/food.csv?ref=06e466ad..." -H "Accept: application/vnd.github.raw" > data/taco/food.csv`. Escrever `data/taco/SOURCES.md` no estilo de `data/bp/SOURCES.md`: TACO 4ª edição revisada e ampliada (NEPA/UNICAMP, 2011; reprodução permitida citando a fonte), URLs exatas com o commit, regra de normalização do autor (`NA` e `*` → vazio = não disponível; `Tr` → 0) e o `sha256sum` de cada arquivo.

- [ ] **Step 2: Escrever o teste do importador** (`tests/Unit/TacoImporterTest.php`)

```php
public function test_parses_real_files(): void
{
    $rows = TacoImporter::parse(APP_ROOT.'/data/taco/food.csv', APP_ROOT.'/data/taco/nutrients.csv', APP_ROOT.'/data/taco/categories.csv');
    $this->assertCount(597, $rows);
    $by = array_column($rows, null, 'taco_code');
    $this->assertSame('Pão, trigo, francês', $by[53]['name']);
    $this->assertSame('Cereais e derivados', $by[53]['group_name']);
    $this->assertSame([300.0, 8.0, 58.6, 3.1, 2.3], [$by[53]['kcal'], $by[53]['protein_g'], $by[53]['carb_g'], $by[53]['fat_g'], $by[53]['fiber_g']]);
    $this->assertSame([76.0, 4.8, 13.6, 0.5, 8.5], [$by[561]['kcal'], $by[561]['protein_g'], $by[561]['carb_g'], $by[561]['fat_g'], $by[561]['fiber_g']]);
    $this->assertNull($by[259]['protein_g']);   // NA na TACO (óleo): fica nulo, não zero
    $this->assertSame(884.0, $by[259]['kcal']);
}

public function test_seed_sql_is_upsert_in_batches(): void
{
    $rows = $this->rows();   // helper privado que chama parse() com os três caminhos do teste anterior
    $sql = TacoImporter::seedSql($rows);
    $this->assertStringNotContainsString('REPLACE', $sql);
    $this->assertSame(6, substr_count($sql, 'ON DUPLICATE KEY UPDATE'));   // 597 linhas / lotes de 100
    $this->assertStringContainsString("(53,'taco',53,'Pão, trigo, francês','pao, trigo, frances','Cereais e derivados',300.0,8.0,58.6,3.1,2.3)", $sql);
    $this->assertStringNotContainsString('active=', str_replace(' ', '', $sql));
}
```

- [ ] **Step 3: Rodar e ver falhar** — `php tools/phpunit.phar --filter TacoImporterTest` → erro "Class App\Nutrition\TacoImporter not found".

- [ ] **Step 4: Implementar `TacoImporter`** — `fgetcsv`; tolerar espaço no fim da linha e campo vazio final; vazio → `null`; aspas simples escapadas no SQL (`'` → `''`) e barra invertida. Colunas da seed: `(id, source, taco_code, name, name_search, group_name, kcal, protein_g, carb_g, fat_g, fiber_g)`; `name_search` via `FoodRepository::normalize`. Criar `tools/import_taco.php` no molde de `tools/import_bp.php` (gera o seed e, sem `--sql-only`, carrega com `SeedLoader::loadFile`). Rodar `php tools/import_taco.php --sql-only` para gerar `database/seeds/food_1_taco.sql`.

- [ ] **Step 5: Escrever `008_meal_plans.sql`** com as cinco tabelas acima (um comando por `;` de fim de linha, como as migrações existentes).

- [ ] **Step 6: Teste do repositório** (`tests/Integration/FoodRepositoryTest.php`, estende `Tests\DbTestCase`)

```php
public function test_search_is_accent_and_word_insensitive(): void
{
    $names = array_column(Container::foods()->search('pao frances'), 'name');
    $this->assertContains('Pão, trigo, francês', $names);
    $this->assertContains('Torrada, pão francês', $names);
    $this->assertSame([], Container::foods()->search('p'));             // menos de 2 letras: nada
}
public function test_inactive_hidden_from_search_but_found_by_id(): void
{
    $this->db->run('UPDATE foods SET active = 0 WHERE id = 53');
    $this->assertNotContains(53, array_map('intval', array_column(Container::foods()->search('pao trigo frances'), 'id')));
    $this->assertSame('Pão, trigo, francês', Container::foods()->find(53)['name']);
}
public function test_own_food_ids_start_at_100000(): void
{
    $id = $this->db->insert('foods', ['source' => 'proprio', 'name' => 'Bolo da vó', 'name_search' => 'bolo da vo', 'kcal' => 350]);
    $this->assertGreaterThanOrEqual(100000, $id);
}
public function test_seed_reload_keeps_own_foods_and_deactivation(): void
{
    $this->db->run('UPDATE foods SET active = 0 WHERE id = 53');
    SeedLoader::loadFile($this->db, APP_ROOT . '/database/seeds/food_1_taco.sql');
    $this->assertSame(0, (int) Container::foods()->find(53)['active']);
}
```
  (Atenção: o último teste roda dentro da transação do `DbTestCase`; os seeds só têm DML, então está ok.)

- [ ] **Step 7: Implementar `FoodRepository` e `Container::foods()`**; rodar `php tools/phpunit.phar --filter "TacoImporterTest|FoodRepositoryTest"` → PASS.

- [ ] **Step 8: `tools/migrate.php` passa a carregar `database/seeds` depois das migrações** (`SeedLoader::loadDir`, imprimindo os arquivos carregados). Atualizar README §9 passo 4: "rode `php .../tools/migrate.php` (aplica tabelas novas e recarrega os dados de referência) ou, sem terminal, importe no phpMyAdmin os arquivos novos de `database/migrations/` **e depois** os de `database/seeds/`".

- [ ] **Step 9: Suíte completa** — `php tools/phpunit.phar` → OK (o `TestSchema` já carrega a migração e os seeds novos).

- [ ] **Step 10: Commit** — `feat: base de alimentos TACO e tabelas do plano alimentar`

---

### Task 2: Medidas caseiras (POF/IBGE), cálculo e validação do plano estruturado

**Files:**
- Create: `data/pof/medidas.csv`, `data/pof/SOURCES.md`, `app/Nutrition/PofMeasureImporter.php`, `tools/import_pof.php`, `database/seeds/food_2_pof_measures.sql` (gerado)
- Create: `app/Nutrition/MealPlanCalculator.php`, `app/Nutrition/MealPlanResolver.php`, `app/Nutrition/InvalidPlanException.php`, `app/Nutrition/FamilyPhrase.php`
- Test: `tests/Unit/MealPlanCalculatorTest.php`, `tests/Unit/FamilyPhraseTest.php`, `tests/Integration/MealPlanResolverTest.php`, `tests/Unit/PofMeasureImporterTest.php`

**Interfaces:**
- Consumes: `FoodRepository::find`, `FoodRepository::findMeasure` (Task 1).
- Produces:
  - **Estrutura resolvida** (é o que vai em `meal_plans.structure_json` e `meal_plan_templates.structure_json`):
    ```
    {"v":1,"meals":[{"name":"Café da manhã","time":"07:00","note":"","options":[
      {"items":[{"food_id":53,"food_name":"Pão, trigo, francês","qty":1.0,"measure_id":5301,
                 "measure_name":"unidade","measure_plural":"unidades","grams":50.0,
                 "per100":{"kcal":300.0,"protein":8.0,"carb":58.6,"fat":3.1,"fiber":2.3},
                 "note":"","subs":[ {mesmas chaves, sem "note" e "subs"} ]}]}]}]}
    ```
    `measure_id = null` ⇒ `qty` em gramas e `grams = qty`. Valores `per100` nulos são permitidos.
  - `MealPlanResolver::__construct(FoodRepository $foods)`; `resolve(array $input, bool $allowEmptyMeals = false): array` — `$input` tem a mesma forma mas só `name, time, note, options[].items[].{food_id, qty, measure_id, note, subs[].{food_id, qty, measure_id}}`; `qty` aceita string com vírgula (`Num::parse`). Lança `InvalidPlanException` (com `public array $errors`, mensagens em pt-BR) quando: 0 ou >12 refeições; nome vazio ou >60; horário fora de `HH:MM` (vazio é ok); opção sem itens (exceto quando `$allowEmptyMeals`, usado nos rascunhos, ou quando a refeição tem nota); >5 opções; >30 itens por opção; >10 substitutos; `qty` ≤ 0 ou > 5000 ou não numérica; alimento inexistente; medida inexistente ou de outro alimento. Mensagem cita a refeição e a posição: `"Almoço, opção 1, item 2: informe uma quantidade maior que zero."`
  - `MealPlanCalculator` (estático, sem banco): `item(array $resolvedItem): array{kcal:float,protein:float,carb:float,fat:float,fiber:float,missing:bool}`; `option(array $option): array{...sem missing...}` (soma dos itens; substitutos não entram); `day(array $structure): array` (soma da **Opção 1** de cada refeição); `macroPercents(array $totals): array{protein:float,carb:float,fat:float}` (1 casa, base = prot×4 + carb×4 + gord×9; tudo 0 se a base for 0). Sem arredondar internamente.
  - `FamilyPhrase::item(array $resolvedItem): string` e `FamilyPhrase::qty(float $q): string`.
  - `data/pof/medidas.csv`: `taco_code,medida,medida_plural,gramas,pagina_pof` → seed em `food_measures` com `id = taco_code*100 + n`, `source='pof'`, `ON DUPLICATE KEY UPDATE` de nome/plural/gramas.

- [ ] **Step 1: Transcrever `data/pof/medidas.csv`** a partir da publicação oficial IBGE, *Pesquisa de Orçamentos Familiares 2008–2009: Tabela de Medidas Referidas para os Alimentos Consumidos no Brasil* (`https://biblioteca.ibge.gov.br/visualizacao/livros/liv50000.pdf`). Cobrir pelo menos estes alimentos TACO (os mais usados em planos), com as medidas que a POF traz para o alimento equivalente: arroz cozido (3), feijão carioca cozido (561), pão francês (53), pão de forma, leite integral, iogurte natural, queijo minas frescal, ovo cozido, frango grelhado/cozido, carne bovina cozida, banana prata (182), maçã, laranja, mamão, alface, tomate, cenoura, batata cozida, macarrão cozido, aveia, tapioca, manteiga/margarina, azeite/óleo, açúcar, café (≥ 25 alimentos; incluir `pagina_pof` de cada linha). **Não inventar gramagens**: se a publicação não estiver acessível, parar e relatar `BLOCKED` em vez de preencher de memória. `SOURCES.md` com URL, data de acesso, `sha256sum` do PDF usado e a regra de casamento POF→TACO.

- [ ] **Step 2: Testes do importador POF** — `parseCsv` lê o arquivo real; todo `taco_code` existe em `data/taco/food.csv`; `gramas > 0`; `seedSql` gera `id = taco_code*100 + n` sequencial por alimento e não contém `REPLACE`. Gerar `database/seeds/food_2_pof_measures.sql` com `php tools/import_pof.php --sql-only`.

- [ ] **Step 3: Testes do calculador** (`tests/Unit/MealPlanCalculatorTest.php`), com itens montados à mão (sem banco):

```php
private function pao(float $grams): array { return ['grams' => $grams, 'per100' => ['kcal' => 300.0, 'protein' => 8.0, 'carb' => 58.6, 'fat' => 3.1, 'fiber' => 2.3]]; }

public function test_item_scales_per_100g(): void
{
    $r = MealPlanCalculator::item($this->pao(50));
    $this->assertEqualsWithDelta(150.0, $r['kcal'], 1e-9);
    $this->assertEqualsWithDelta(4.0, $r['protein'], 1e-9);
    $this->assertEqualsWithDelta(29.3, $r['carb'], 1e-9);
    $this->assertEqualsWithDelta(1.55, $r['fat'], 1e-9);
    $this->assertEqualsWithDelta(1.15, $r['fiber'], 1e-9);
    $this->assertFalse($r['missing']);
}
public function test_null_nutrient_counts_zero_and_flags_missing(): void
{
    $r = MealPlanCalculator::item(['grams' => 10, 'per100' => ['kcal' => 884.0, 'protein' => null, 'carb' => null, 'fat' => 100.0, 'fiber' => null]]);
    $this->assertEqualsWithDelta(88.4, $r['kcal'], 1e-9);
    $this->assertSame(0.0, $r['protein']);
    $this->assertTrue($r['missing']);
}
public function test_day_uses_option_one_and_ignores_substitutes(): void
{
    $sub = $this->pao(500);
    $structure = ['meals' => [
        ['options' => [['items' => [$this->pao(50) + ['subs' => [$sub]]]], ['items' => [$this->pao(100)]]]],
        ['options' => [['items' => [$this->pao(50)]]]],
    ]];
    $this->assertEqualsWithDelta(300.0, MealPlanCalculator::day($structure)['kcal'], 1e-9);
}
public function test_macro_percents(): void
{
    $p = MealPlanCalculator::macroPercents(['kcal' => 150, 'protein' => 4.0, 'carb' => 29.3, 'fat' => 1.55, 'fiber' => 1.15]);
    $this->assertSame(['protein' => 10.9, 'carb' => 79.6, 'fat' => 9.5], $p);
    $this->assertSame(['protein' => 0.0, 'carb' => 0.0, 'fat' => 0.0], MealPlanCalculator::macroPercents(['kcal' => 0, 'protein' => 0, 'carb' => 0, 'fat' => 0, 'fiber' => 0]));
}
```

- [ ] **Step 4: Testes da frase da família** (`tests/Unit/FamilyPhraseTest.php`):
  - `qty(1.0)` → `"1"`, `qty(1.5)` → `"1,5"`, `qty(0.25)` → `"0,25"`, `qty(2.0)` → `"2"`.
  - gramas: `['qty' => 50, 'measure_id' => null, 'grams' => 50, 'food_name' => 'Arroz, tipo 1, cozido', 'note' => '']` → `"50 g de Arroz, tipo 1, cozido"`.
  - 1 medida: `qty 1, measure_name "colher de sopa cheia"` → `"1 colher de sopa cheia de Arroz, tipo 1, cozido"`.
  - plural: `qty 2, measure_plural "colheres de sopa cheias"` → `"2 colheres de sopa cheias de …"`.
  - sem plural: `qty 2, measure_plural null` → `"2 × colher de sopa cheia de …"`.
  - nota: `note "sem açúcar"` → sufixo `" (sem açúcar)"`.

- [ ] **Step 5: Testes do resolvedor** (`tests/Integration/MealPlanResolverTest.php`, banco com seeds):
  - entrada com pão francês (53) `qty "1"` + medida POF `5301` resolve para `food_name 'Pão, trigo, francês'`, `grams` = gramas da medida no CSV, `per100.kcal 300.0`.
  - `qty "1,5"` com `measure_id null` → `grams 1.5`.
  - `qty "0"` → `InvalidPlanException` com mensagem contendo `"Café da manhã, opção 1, item 1"` e `"maior que zero"`; nada é lançado como `TypeError`.
  - `measure_id` de outro alimento (ex.: medida de 561 no item 53) → erro `"medida não pertence ao alimento"`.
  - `food_id 999999` → erro `"alimento não encontrado"`.
  - 13 refeições → erro `"no máximo 12 refeições"`; horário `"7h"` → erro `"horário no formato HH:MM"`.
  - alimento inativo existente → resolve normalmente (planos antigos podem citar alimento desativado).

- [ ] **Step 6: Rodar e ver falhar**, depois **implementar** `PofMeasureImporter`, `MealPlanCalculator`, `FamilyPhrase`, `InvalidPlanException`, `MealPlanResolver` (sem `Container` novo: o resolvedor é criado com `new MealPlanResolver(Container::foods())`).

- [ ] **Step 7: Rodar** `php tools/phpunit.phar --filter "Pof|MealPlanCalculator|FamilyPhrase|MealPlanResolver"` → PASS; depois a suíte inteira → OK.

- [ ] **Step 8: Commit** — `feat: medidas caseiras POF, cálculo e validação do plano estruturado`

---

### Task 3: Texto colado → HTML seguro

**Files:**
- Create: `app/Nutrition/PlanText.php`
- Test: `tests/Unit/PlanTextTest.php`

**Interfaces:**
- Produces: `PlanText::normalizePaste(string $raw): string` e `PlanText::toHtml(string $text): string` (texto já normalizado → HTML; nunca devolve HTML vindo da entrada).

Regras (do design §5.3), aplicadas linha a linha depois de `e()`:
- `normalizePaste`: `\r\n`/`\r` → `\n`; tab e NBSP (U+00A0) → espaço; remover U+200B/U+200C/U+200D/U+FEFF; marcador Word U+F0B7 e `▪ ◦ ‣ ●` no início da linha → `• `; remover espaços no fim das linhas; 3+ quebras seguidas → 2; `trim` geral.
- `toHtml`: linha vazia separa blocos. Título = linha que termina em `:` (≤ 80 caracteres) **ou** que tem ≥ 3 letras e é toda maiúscula → `<h3>` sem o `:` final. Item = `^\s*([-*•·–]|\d{1,2}[.)])\s+(.+)$` → `<li>`; itens seguidos formam `<ul>` (marcador) ou `<ol>` (numerados). Demais linhas seguidas viram um `<p>` com `<br>` entre elas. Negrito `**x**` (na mesma linha, não guloso) → `<strong>x</strong>`. Nenhum link é criado.

- [ ] **Step 1: Testes** (`tests/Unit/PlanTextTest.php`):

```php
public function test_word_paste_becomes_list_and_titles(): void
{
    $raw = "CAFÉ DA MANHÃ\r\n\t1 pão francês\r\n• 1 copo de leite\u{00A0}\r\n\r\n\r\n\r\nAlmoço:\r\n1) arroz\r\n2) feijão";
    $html = PlanText::toHtml(PlanText::normalizePaste($raw));
    $this->assertSame('<h3>CAFÉ DA MANHÃ</h3><ul><li>1 pão francês</li><li>1 copo de leite</li></ul><h3>Almoço</h3><ol><li>arroz</li><li>feijão</li></ol>', $html);
}
public function test_paragraph_lines_and_bold(): void
{
    $this->assertSame('<p>Beber <strong>2 litros</strong> de água<br>por dia</p>', PlanText::toHtml("Beber **2 litros** de água\npor dia"));
}
public function test_never_outputs_user_html(): void
{
    $html = PlanText::toHtml(PlanText::normalizePaste("<script>alert(1)</script>\n<b onclick=x>oi</b>\n[a](javascript:alert(1))\n**<img src=x onerror=y>**"));
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('<b ', $html);
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringNotContainsString('href', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
}
public function test_short_caps_word_is_not_title(): void
{
    $this->assertSame('<p>OK</p>', PlanText::toHtml('OK'));   // < 3 letras
}
```

- [ ] **Step 2: Ver falhar → implementar → ver passar** (`php tools/phpunit.phar --filter PlanTextTest`).

- [ ] **Step 3: Commit** — `feat: conversor seguro de texto colado do plano alimentar`

---

### Task 4: Repositório do plano (ciclo de vida, snapshot, auditoria)

**Files:**
- Create: `app/Nutrition/MealPlanRepository.php`
- Modify: `app/Core/Container.php` (`mealPlans()`)
- Test: `tests/Integration/MealPlanRepositoryTest.php`

**Interfaces:**
- Consumes: `MealPlanCalculator::day` (Task 2), `AuditLog::record`.
- Produces (`MealPlanRepository::__construct(Database $db, AuditLog $audit)`; `$data` = `['title' => string, 'body_text' => ?string, 'guidance_text' => ?string, 'structure' => ?array /* resolvida */]`):
  - `createDraft(int $patientId, string $mode, array $data, int $by, ?Request $r = null, ?int $sourceTemplateId = null, ?int $sourcePlanId = null): int` — lança `\RuntimeException('Já existe um rascunho para este paciente.')` se houver rascunho não descartado.
  - `draftFrom(int $publishedPlanId, int $by, ?Request $r = null): int` — copia título/textos/estrutura do plano publicado (`source_plan_id`); mesma regra de rascunho único.
  - `saveDraft(int $planId, array $data, int $by, ?Request $r = null, bool $auto = false, ?int $baseRevision = null): array{revision:int,conflict:bool}` — só em `rascunho`; `revision + 1`; `conflict = $baseRevision !== null && $baseRevision !== revisão anterior` (salva mesmo assim, como no design); auditoria `update` quando `!$auto`, ou quando `$auto` e `last_audited_at` é nulo ou tem mais de 10 minutos.
  - `publish(int $planId, int $by, ?Request $r = null): void` — exige `rascunho` com título e (texto não vazio **ou** estrutura com ≥ 1 refeição, e cada refeição com ≥ 1 item na Opção 1 ou com nota — senão `RuntimeException('Refeição sem itens: {nome}.')`); grava `total_*` a partir de `MealPlanCalculator::day` (estruturado) ou nulos (texto); o vigente anterior vira `substituido` com `superseded_at`.
  - `end(int $planId, string $reason, int $by, ?Request $r = null): void` — exige `publicado` e motivo com 3–255 caracteres (após `trim`); grava `encerrado`, `ended_*`.
  - `discard(int $planId, int $by, ?Request $r = null): void` — só `rascunho`; marca `discarded_at`.
  - `find(int $id): ?array`, `draft(int $patientId): ?array`, `current(int $patientId): ?array` (status `publicado`), `history(int $patientId): list<array>` (todos exceto rascunhos descartados, mais novo primeiro, com `author`/`publisher`/`ender` = nomes). `structure_json` volta decodificado na chave `structure`.
  - Regras de concorrência: `createDraft`, `draftFrom` e `publish` rodam em `transaction` com `SELECT id FROM patients WHERE id = ? FOR UPDATE` antes de checar/alterar, garantindo no máximo um rascunho e um publicado por paciente.
  - Erros de estado → `\RuntimeException` com mensagem em pt-BR (o controller mostra como flash).

- [ ] **Step 1: Testes** (`tests/Integration/MealPlanRepositoryTest.php`), usando `makePatient`/`makeUser` e uma estrutura resolvida montada à mão com `per100`:
  - `test_publish_supersedes_previous_and_freezes_totals`: publica A, cria e publica B → A `substituido` com `superseded_at`, B `publicado`, `total_kcal` de B = `MealPlanCalculator::day(...)['kcal']` arredondado a 1 casa; `current()` devolve B.
  - `test_single_draft_rule`: segundo `createDraft` lança `RuntimeException`; depois de `discard` permite de novo.
  - `test_published_is_immutable`: `saveDraft` num publicado lança `RuntimeException`; o registro não muda.
  - `test_end_requires_reason_and_hides_current`: `end($id, '  ', ...)` lança; `end($id, 'Paciente errado', ...)` → `current()` nulo, `history()` contém o plano com `status 'encerrado'` e `ended_reason`.
  - `test_draft_from_copies_published`: `draftFrom` copia título/estrutura, `source_plan_id` correto, original intocado.
  - `test_conflict_flag`: `saveDraft(..., baseRevision: 1)` duas vezes → segunda volta `conflict true` e `revision 3`.
  - `test_autosave_audits_at_most_every_10_minutes`: três `saveDraft(auto: true)` seguidos → 1 linha `update` em `audit_log` para o plano; após `UPDATE meal_plans SET last_audited_at = NOW() - INTERVAL 11 MINUTE`, mais um auto → 2 linhas.
  - `test_publish_rejects_empty`: rascunho texto sem corpo → `RuntimeException` com "vazio"; rascunho estruturado com uma refeição sem itens e sem nota → "Refeição sem itens: Almoço."

- [ ] **Step 2: Ver falhar → implementar → ver passar** (`--filter MealPlanRepositoryTest`); suíte inteira OK.

- [ ] **Step 3: Commit** — `feat: ciclo de vida do plano alimentar (rascunho, publicar, encerrar)`

---

### Task 5: Telas da equipe — seção "Plano alimentar" e modo Texto

**Files:**
- Create: `app/Nutrition/MealPlanController.php`, `app/Nutrition/Staff.php`, `app/Nutrition/views/index.php`, `app/Nutrition/views/show.php`, `app/Nutrition/views/edit_text.php`, `app/Nutrition/views/print.php`, `app/Nutrition/views/_plan_body.php`
- Create: `public/assets/js/plan-text-editor.js`, `public/assets/js/print-button.js`
- Modify: `app/routes.php`, `app/Patients/views/show.php` (cartão "Plano alimentar" ao lado de Energia, se `canPlano`), `app/Patients/PatientController.php` (`canPlano` = gate view NUTRICAO **e** papel da equipe), `public/assets/css/app.css` (pré-visualização, `@media print`), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/MealPlanTextTest.php`

**Interfaces:**
- Consumes: `MealPlanRepository` (Task 4), `PlanText` (Task 3), `Container::energyGoals()->current()`.
- Produces:
  - `Staff::assert(array $user, Request $r): void` — se o papel não for `medico`/`nutricionista`, audita `denied` (como o `Gate`) e lança `HttpException(403)`. Usado por todos os controllers da Fase 2a.
  - Rotas (todas com `$authMw`):
    - `GET /pacientes/{id}/plano` → `index` (vigente, rascunho, histórico; formulário "Novo plano": modo `texto|estruturado`, origem `branco|vigente|modelo` + `modelo_id`); `POST /pacientes/{id}/plano/novo` → `create` (se já há rascunho: página pergunta "Continuar o rascunho" ou "Descartar e começar outro" — este último envia `descartar_rascunho=1`).
    - `GET /planos/{planId}` → `show` (qualquer status; audita `view`); `GET /planos/{planId}/imprimir` (`?totais=1` inclui o quadro de totais).
    - `GET /planos/{planId}/editar` → editor conforme o modo (só rascunho; senão redireciona para `show`); `POST /planos/{planId}/editar` → `save` (modo texto: formulário comum, campos `titulo`, `corpo`, `orientacoes`, `revisao`; modo estruturado: Task 7).
    - `POST /planos/previa` → JSON `{"html": PlanText::toHtml(PlanText::normalizePaste(texto))}` (exige edit NUTRICAO, sem paciente).
    - `POST /planos/{planId}/publicar`, `POST /planos/{planId}/encerrar` (campo `motivo`), `POST /planos/{planId}/descartar`, `POST /planos/{planId}/novo-rascunho`.
  - Toda ação por `planId` carrega o plano (404 se não existir), chama `Staff::assert` e `gate()->authorize($user, 'view'|'edit', Policy::NUTRICAO, $plan['patient_id'], $r)`.
  - Partial `_plan_body.php` (`$plan`, `$family` bool): modo texto → `PlanText::toHtml(body_text)`; estruturado → refeições/opções/itens com `FamilyPhrase::item` e "ou …" para substitutos; orientações via `PlanText::toHtml`. Com `$family = false` mostra também kcal por opção (estruturado). Reutilizado na Task 6.
  - `print-button.js`: botões `[data-print]` chamam `window.print()`. `plan-text-editor.js`: botões "Título de refeição"/"Item"/"Negrito" inserem `\nTÍTULO:\n`, `\n• `, `**…**` na posição do cursor; ao colar, deixa o navegador colar texto puro (`paste` → `clipboardData.getData('text/plain')`); pré-visualização via `fetch('/planos/previa')` com 400 ms de espera após digitar; botão "Inserir trecho" fica para a Task 10 (deixar `data-snippets-url` vazio agora).

- [ ] **Step 1: Testes Http** (`tests/Http/MealPlanTextTest.php`, no molde de `tests/Http/EnergyGoalTest.php`; nutricionista com liberação e médica):
  - `test_create_edit_publish_text_plan`: POST `/pacientes/{id}/plano/novo` (`modo=texto`, `origem=branco`) → 302 para `/planos/{n}/editar`; POST editar com `corpo` contendo `"CAFÉ DA MANHÃ\n• 1 pão"` → 302; POST publicar → 302; GET `/pacientes/{id}/plano` mostra "Plano vigente" e `<h3>CAFÉ DA MANHÃ</h3>`; `audit_log` tem `publish` para o plano.
  - `test_end_plan_with_reason`: publicar, POST encerrar com `motivo=''` → 200 com "Informe o motivo"; com `motivo='Paciente errado'` → 302 e `current()` nulo.
  - `test_second_new_plan_asks_to_continue_or_discard`: com rascunho existente, POST novo → 200 com "Continuar o rascunho"; com `descartar_rascunho=1` → novo rascunho e o antigo com `discarded_at`.
  - `test_preview_escapes_html`: POST `/planos/previa` com `texto="<script>x</script>"` → JSON cujo `html` contém `&lt;script&gt;`.
  - `test_family_and_secretary_blocked`: responsável vinculado e secretária → GET `/pacientes/{id}/plano` e `/planos/{planId}` dão 403 (o responsável **tem** `view NUTRICAO` depois da Task 6; o `Staff::assert` é que bloqueia — o teste já fica valendo).
  - `test_nutritionist_without_grant_403`: nutricionista sem liberação → 403 em `/pacientes/{id}/plano`.
- [ ] **Step 2: Ver falhar → implementar controller, views, JS, CSS, rotas → ver passar.** Views sem estilo/JS inline (o `ViewsCspTest` cobre).
- [ ] **Step 3: SmokeAllRoutes** — adicionar `planId` ao `concretePath` (fixture: um plano texto publicado do paciente da criança, criado em `buildFixtures` com `Container::mealPlans()`) e as linhas (ordem M N S R P):
  `'/pacientes/{id}/plano' => [200, 200, 403, 403, 403]`, `'/planos/{planId}' => [200, 200, 403, 403, 403]`, `'/planos/{planId}/imprimir' => [200, 200, 403, 403, 403]`, `'/planos/{planId}/editar' => [302, 302, 403, 403, 403]` (publicado redireciona para `show`). Para P (paciente adulto), usar o mesmo plano da criança: 403 também.
- [ ] **Step 4: Suíte inteira** → OK. Conferir no navegador (skill `run`, se disponível) que a pré-visualização atualiza e que "Imprimir" abre o diálogo.
- [ ] **Step 5: Commit** — `feat: plano alimentar em modo texto na ficha do paciente`

---

### Task 6: Portal da família — "Alimentação"

**Files:**
- Modify: `app/Access/Policy.php` (`NUTRICAO` em `view` de `paciente` e `responsavel`; atualizar o comentário da classe), `app/Portal/PortalController.php` (`food`, `foodPrint`; `open()` ganha o parâmetro `string $section = Policy::ANTROPOMETRIA`), `app/Portal/views/home.php` (cartão "Alimentação"), `app/routes.php`, `tests/Unit/PolicyTest.php`, `tests/Http/SmokeAllRoutesTest.php`
- Create: `app/Portal/views/food.php`, `app/Portal/views/food_print.php`
- Test: `tests/Http/PortalFoodTest.php`

**Interfaces:**
- Consumes: `MealPlanRepository::current` (Task 4), `_plan_body.php` com `$family = true` (Task 5).
- Produces: `GET /portal/p/{id}/alimentacao` e `GET /portal/p/{id}/alimentacao/imprimir`. Sem vigente: "Nenhum plano alimentar no momento." Página de impressão: nome "Dra. Rubia", primeiro nome do paciente, "Publicado em dd/mm/aaaa por {profissional}", botão `[data-print]`.

- [ ] **Step 1: Testes** (`tests/Http/PortalFoodTest.php`, responsável vinculado com termo aceito — copiar o preparo de `tests/Http/PortalTest.php`):
  - `test_family_sees_current_plan_in_simple_words`: plano estruturado publicado com pão francês 1 unidade + substituto → página contém `"1 unidade de Pão, trigo, francês"` e `"ou "`; **não** contém `"kcal"`, `"Proteína"`, nem o nome do rascunho.
  - `test_draft_and_ended_not_shown`: só rascunho → "Nenhum plano alimentar no momento."; publicado e depois encerrado → mesma frase.
  - `test_other_family_forbidden`: responsável de outra criança → 403.
  - `PolicyTest`: `paciente`/`responsavel` passam a ter `view NUTRICAO` e continuam **sem** `edit NUTRICAO`.
- [ ] **Step 2: Ver falhar → implementar → ver passar.**
- [ ] **Step 3: SmokeAllRoutes** — `'/portal/p/{id}/alimentacao' => [302, 302, 302, 200, 200]`, `'/portal/p/{id}/alimentacao/imprimir' => [302, 302, 302, 200, 200]`; conferir que as linhas da Task 5 seguem 403 para R e P.
- [ ] **Step 4: Suíte inteira** → OK.
- [ ] **Step 5: Commit** — `feat: plano alimentar vigente no portal da família`

---

### Task 7: Modo Estruturado — editor com busca na TACO e totais em tempo real

**Files:**
- Create: `app/Nutrition/FoodController.php` (só a busca nesta tarefa), `app/Nutrition/views/edit_structured.php`, `public/assets/js/meal-plan-editor.js`
- Modify: `app/Nutrition/MealPlanController.php` (`save` para estruturado; `create` com refeições sugeridas), `app/routes.php`, `public/assets/css/app.css`, `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/MealPlanStructuredTest.php`

**Interfaces:**
- Consumes: `MealPlanResolver`, `MealPlanCalculator`, `FoodRepository::search` (Tasks 1–2), `MealPlanRepository::saveDraft` (Task 4).
- Produces:
  - `GET /alimentos/busca?q=` → JSON `{"items":[{"id","name","group","source","per100":{kcal,protein,carb,fat,fiber},"measures":[{"id","name","plural","grams"}]}]}` (`Staff::assert`; até 20; `q` < 2 letras → lista vazia).
  - `POST /planos/{planId}/editar` no modo estruturado: campos `titulo`, `orientacoes`, `estrutura` (string JSON na forma de entrada do resolvedor), `revisao`, `auto` (`1` no salvamento automático). Responde JSON: sucesso `{"ok":true,"revision":n,"conflict":bool,"totals":{dia, por opção [refeição][opção]},"percents":{...}}`; validação `422 {"ok":false,"errors":[...]}` sem gravar.
  - Rascunho estruturado em branco nasce com as refeições sugeridas do design: Café da manhã, Lanche da manhã, Almoço, Lanche da tarde, Jantar, Ceia, sem itens. Salvar rascunho usa `resolve($input, allowEmptyMeals: true)`; a publicação recusa refeição vazia (regra do `publish`, Task 4).
  - Editor (`edit_structured.php` + `meal-plan-editor.js`): dados iniciais em `data-plano` (JSON escapado com `e()`), `data-meta-kcal` (GET vigente ou vazio), `data-busca-url`, `data-salvar-url`; refeições com nome/horário/nota, setas ↑↓, remover; "Adicionar opção"; item = busca (≥ 2 letras, 300 ms), quantidade, seletor "g" + medidas do alimento, nota, "+ substituto"; quadro de totais fixo (kcal, P/C/G/fibra, % macros, meta e diferença, barra) recalculado no navegador com as mesmas fórmulas de `MealPlanCalculator`; total por opção; ao lado de cada substituto, "x kcal (item: y kcal)"; salvar no botão e a cada 30 s se houver mudança; aviso "Este rascunho foi alterado em outra janela" quando `conflict`; erros 422 listados no topo. Sem meta: aviso com link para `/pacientes/{id}/energia`.

- [ ] **Step 1: Testes** (`tests/Http/MealPlanStructuredTest.php`):
  - `test_search_returns_measures`: GET `/alimentos/busca?q=pao%20frances` (médica) → JSON com item `id 53`, `per100.kcal 300` e `measures` não vazia.
  - `test_search_forbidden_for_family`: responsável → 403.
  - `test_new_structured_draft_has_suggested_meals`: POST novo `modo=estruturado` → `structure.meals` com os 6 nomes, na ordem.
  - `test_save_returns_totals_and_persists_snapshot`: POST editar com 1 refeição/1 item (53, `qty "1"`, medida POF do pão) → JSON `ok`, `totals.day.kcal` = `300 * gramas/100`; `structure_json` gravado tem `food_name` e `per100`.
  - `test_invalid_quantity_422_and_nothing_saved`: `qty "-1"` → 422, `errors[0]` contém "maior que zero"; `revision` não mudou.
  - `test_publish_rejects_empty_meal`: rascunho com refeição sem item e sem nota → publicar mostra "Refeição sem itens".
  - `test_publish_keeps_snapshot_after_food_change`: publicar com alimento próprio (inserido direto em `foods`), depois `UPDATE foods SET name='Outro', kcal=1` → GET `/planos/{id}` ainda mostra o nome antigo e `total_kcal` antigo. *(cobre o Review Focus 5)*
- [ ] **Step 2: Ver falhar → implementar → ver passar.**
- [ ] **Step 3: SmokeAllRoutes** — `'/alimentos/busca' => [200, 200, 403, 403, 403]`.
- [ ] **Step 4: Conferência manual no navegador** (skill `run`): montar café da manhã com 2 opções e um substituto; totais mudam ao digitar; recarregar a página mantém o que foi salvo; CSP sem erros no console.
- [ ] **Step 5: Suíte inteira** → OK.
- [ ] **Step 6: Commit** — `feat: plano alimentar estruturado com busca TACO e totais`

---

### Task 8: "Minha tabela" — alimentos e medidas próprios

**Files:**
- Modify: `app/Nutrition/FoodController.php`, `app/Nutrition/FoodRepository.php`, `app/routes.php`, `app/Layout/views/layout.php` (item de menu "Alimentos" para quem tem `edit NUTRICAO`), `tests/Http/SmokeAllRoutesTest.php`
- Create: `app/Nutrition/views/foods_index.php`, `app/Nutrition/views/food_form.php`
- Test: `tests/Integration/FoodRepositoryTest.php` (acréscimos), `tests/Http/FoodAdminTest.php`

**Interfaces:**
- Produces:
  - `FoodRepository::createOwn(array $f, int $by, ?Request $r = null): int` (`$f`: `name` 2–200, `group_name` opcional, `kcal` obrigatório 0–900, `protein_g`/`carb_g`/`fat_g`/`fiber_g` opcionais 0–100, todos por 100 g), `updateOwn(int $id, array $f, int $by, ?Request $r = null): void` (só `source='proprio'`; TACO → `RuntimeException`), `setActive(int $id, bool $on, int $by, ?Request $r = null): void` (só próprios), `addMeasure(int $foodId, array $m, int $by, ?Request $r = null): int` (`name` 2–100, `name_plural` opcional, `grams` 0,1–2000; vale para qualquer alimento), `updateMeasure(int $id, array $m, int $by, ?Request $r = null): void` e `setMeasureActive(...)` (só `source='proprio'`; medidas POF são somente leitura). Todas auditam (`entity` `foods` / `food_measures`, `patient_id` nulo).
  - Rotas: `GET /alimentos` (lista + busca, filtro "só meus"), `GET|POST /alimentos/novo`, `GET|POST /alimentos/{foodId}/editar` (TACO: só mostra valores e permite adicionar medida), `POST /alimentos/{foodId}/medidas`, `POST /alimentos/medidas/{measureId}/editar`, `POST /alimentos/{foodId}/ativo`. Ver: `Staff::assert`; escrever: `gate()->authorize($user, 'edit', Policy::NUTRICAO, null, $r)`.
  - Receitas (design §5.6): o formulário de alimento próprio aceita "Receita" como `group_name` sugerido e explica na ajuda que os valores são por 100 g.
- [ ] **Step 1: Testes** — integração: `createOwn` gera id ≥ 100000 e `name_search` normalizado; `updateOwn(53, ...)` lança; `addMeasure(53, ...)` funciona e aparece em `search('pao frances')`; `updateMeasure` de medida POF lança. Http: médica cria "Bolo de banana da casa" (kcal `"250,5"`) → aparece na busca JSON com `source 'proprio'`; kcal `"abc"` → 200 com mensagem; secretária → 403; responsável → 403.
- [ ] **Step 2: Ver falhar → implementar → ver passar.**
- [ ] **Step 3: SmokeAllRoutes** — `foodId` = 53 nas fixtures: `'/alimentos' => [200, 200, 403, 403, 403]`, `'/alimentos/novo' => [200, 200, 403, 403, 403]`, `'/alimentos/{foodId}/editar' => [200, 200, 403, 403, 403]`.
- [ ] **Step 4: Suíte inteira** → OK.
- [ ] **Step 5: Commit** — `feat: alimentos e medidas caseiras próprios (Minha tabela)`

---

### Task 9: Modelos de plano e trechos de texto

**Files:**
- Create: `app/Nutrition/TemplateRepository.php`, `app/Nutrition/SnippetRepository.php`, `app/Nutrition/TemplateController.php`, views `app/Nutrition/views/templates_index.php`, `template_form.php`, `snippets_index.php`, `snippet_form.php`
- Modify: `app/Core/Container.php` (`mealPlanTemplates()`, `textSnippets()`), `app/Nutrition/MealPlanController.php` (origem `modelo`; ação "Salvar como modelo"), `app/Nutrition/views/edit_text.php` e `edit_structured.php` (reuso como editor de modelo; botão "Inserir trecho"), `public/assets/js/plan-text-editor.js` (lista de trechos), `app/Layout/views/layout.php` (menu "Modelos"), `app/routes.php`, `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Integration/TemplateRepositoryTest.php`, `tests/Http/TemplateTest.php`

**Interfaces:**
- Consumes: `MealPlanRepository::createDraft` com `$sourceTemplateId` (Task 4); `MealPlanResolver::resolve` (Task 2) para **re-resolver** a estrutura do modelo ao criar o rascunho (valores atuais da tabela; o modelo guarda a forma resolvida, mas o rascunho recalcula).
- Produces:
  - `TemplateRepository::create(array $t, int $by, ?Request $r = null): int` (`$t`: `title` 2–150, `mode`, `body_text`, `guidance_text`, `structure`), `fromPlan(int $planId, string $title, int $by, ?Request $r = null): int`, `update(int $id, array $t, array $user, ?Request $r = null): void` e `delete(int $id, array $user, ?Request $r = null): void` (lógico) — só o autor ou `is_admin = 1`, senão `RuntimeException('Somente quem criou o modelo ou a administradora pode alterá-lo.')`; `find(int $id): ?array`; `all(): list<array>` (não excluídos, por título).
  - `SnippetRepository` com `create(string $title, string $body, int $by, ...)`, `update(int $id, string $title, string $body, array $user, ...)`, `delete(int $id, array $user, ...)`, `find`, `all()` — mesma regra de autoria.
  - Rotas: `GET /modelos-plano`, `GET /modelos-plano/novo?modo=texto|estruturado`, `POST /modelos-plano/novo`, `GET|POST /modelos-plano/{templateId}/editar`, `POST /modelos-plano/{templateId}/excluir`, `POST /planos/{planId}/salvar-como-modelo` (campo `titulo`), `GET /trechos`, `GET|POST /trechos/novo`, `GET|POST /trechos/{snippetId}/editar`, `POST /trechos/{snippetId}/excluir`, `GET /trechos.json` → `{"items":[{"id","title","body"}]}`. Todas com `Staff::assert`; escrita com `edit NUTRICAO`.
  - Editor de modelo = mesmas views de edição com `$context = ['kind' => 'template', 'saveUrl' => ..., 'backUrl' => ...]` (sem botões publicar/encerrar). No editor de modelo estruturado, sem paciente: sem meta de energia (quadro mostra só os totais).
- [ ] **Step 1: Testes**
  - integração: `fromPlan` copia modo/textos/estrutura; alterar o modelo depois não muda o plano de origem nem um rascunho criado a partir dele; nutricionista B não edita modelo da nutricionista A (lança), admin edita; `delete` some de `all()`.
  - Http: criar rascunho com `origem=modelo&modelo_id=X` → rascunho com o conteúdo do modelo e `source_template_id = X`; em modelo estruturado com alimento hoje desativado, o rascunho é criado mesmo assim (resolvedor aceita inativo); "Salvar como modelo" de um publicado → aparece em `/modelos-plano`; `/trechos.json` lista o trecho criado; responsável → 403 em `/modelos-plano` e `/trechos.json`.
- [ ] **Step 2: Ver falhar → implementar → ver passar.**
- [ ] **Step 3: SmokeAllRoutes** — fixtures `templateId` e `snippetId` (um de cada, criados pela médica): `'/modelos-plano' => [200,200,403,403,403]`, `'/modelos-plano/novo' => [200,200,403,403,403]`, `'/modelos-plano/{templateId}/editar' => [200,403,403,403,403]` (a nutricionista não é a autora: 403 pela regra de autoria), `'/trechos' => [200,200,403,403,403]`, `'/trechos/novo' => [200,200,403,403,403]`, `'/trechos/{snippetId}/editar' => [200,403,403,403,403]`, `'/trechos.json' => [200,200,403,403,403]`.
- [ ] **Step 4: Conferência manual** (skill `run`): no modo texto, "Inserir trecho" insere o corpo na posição do cursor e a pré-visualização atualiza.
- [ ] **Step 5: Suíte inteira** → OK.
- [ ] **Step 6: Commit** — `feat: modelos de plano alimentar e trechos de texto`

---

### Task 10: Documentação e pacote

**Files:**
- Modify: `README.md` (nova seção "Plano alimentar": como criar, colar texto e convenções `TÍTULO:`/`•`/`**negrito**`, estruturado, trocas, publicar/encerrar, modelos, trechos, Minha tabela, o que a família vê; §9 atualização: rodar `tools/migrate.php` ou importar `008_meal_plans.sql` e depois `food_1_taco.sql` e `food_2_pof_measures.sql` no phpMyAdmin, **nessa ordem**; caminho da parte pública em domínio adicional: `public_html/rubia.med.br/`), `docs/superpowers/backlog-fase1.md` → criar `docs/superpowers/backlog-fase2a.md` com as pendências menores registradas pelas revisões
- [ ] **Step 1: Atualizar README e backlog.**
- [ ] **Step 2: `php tools/build_release.php`** e conferir com `unzip -l` que o pacote contém `database/migrations/008_meal_plans.sql` e os dois seeds novos, e que **não** contém `config/config.php`. (`data/taco/` e `data/pof/` podem ir junto: são dados públicos de referência.)
- [ ] **Step 3: Suíte inteira** → OK.
- [ ] **Step 4: Commit** — `docs: plano alimentar no README e atualização de versão`
