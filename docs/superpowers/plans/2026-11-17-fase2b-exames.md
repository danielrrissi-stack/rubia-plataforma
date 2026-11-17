# Exames laboratoriais (Fase 2b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Exames laboratoriais na ficha do paciente: catálogo configurável com faixas por idade/sexo, coletas com resultados e laudo anexo, classificação (normal/limítrofe/alterado) e HOMA-IR, tabela de evolução e gráfico por exame, e liberação por coleta para o portal da família com frases simples.

**Architecture:** Novo módulo `app/Labs/` no padrão de `app/Nutrition/` (repositórios com `Database` + `AuditLog`, controllers finos, views PHP, `Container` com singletons). A classificação é **calculada na exibição** por uma classe pura (`LabClassifier`), nunca gravada. Laudos são o primeiro envio de arquivos do sistema: gravados em `<storage>/uploads/exames/...` (fora da raiz pública, já incluído no backup) e servidos só por rota autenticada via `Response::file`.

**Tech Stack:** PHP 8.1+ puro, MariaDB/MySQL (InnoDB, utf8mb4), PHPUnit 11 (`tools/phpunit.phar`), Chart.js local (`public/assets/vendor/chart.umd.min.js`), JS sem framework em `public/assets/js/` (CSP estrita).

**Spec:** `docs/superpowers/specs/2026-11-17-exames-laboratoriais-design.md` (e a geral `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`).

## Global Constraints

- PHP 8.1+, `declare(strict_types=1);` em todo arquivo PHP; sem Composer/Node em produção; nenhuma biblioteca nova.
- CSP: `default-src 'self'; img-src 'self' data:` — **nenhum** `<script>` inline, `style=""`, nem `onclick=`; JS só em `public/assets/js/*.js` (cobrado por `tests/Unit/ViewsCspTest.php`).
- Toda saída em view via `e()`; números com vírgula (`fmt_num`), datas `fmt_date`; textos em português do Brasil.
- Migração única desta fase: `database/migrations/009_lab_exams.sql`. Seed: `database/seeds/lab_catalog.sql`.
- O seed **nunca sobrescreve edições da médica**: `INSERT ... ON DUPLICATE KEY UPDATE id = id` (no-op) em `lab_tests` e `lab_ranges`; ids fixos 1..99 no seed; `AUTO_INCREMENT = 1000` nas duas tabelas para cadastros próprios.
- Permissão: seção `Policy::EXAMES`. **Toda rota da equipe exige papel `medico`** via `App\Labs\Clinician::assert()` **antes** do Gate (a família ganha `view EXAMES` na Task 8; sem a checagem de papel ela abriria as telas da equipe). Ordem nas rotas com id de coleta/anexo: 404 se não existe → `Clinician::assert` → Gate → resto (mesma ordem da Fase 2a).
- Auditoria (`Container::audit()->record(uid, action, table, rowId, patientId, before, after, $r)`): leitura da página de exames da equipe = `('view', 'lab_collections', null, patientId, null, ['section' => 'exames'])`; escritas `create`/`update`/`delete`/`release`/`unrelease` em `lab_collections` com antes/depois **completos** (coleta + resultados); `upload`/`download`/`delete` em `lab_attachments`; `update`/`confirm` em `lab_tests`/`lab_ranges` (antes/depois das faixas); leitura no portal = `('view', 'lab_collections', null, patientId, null, ['section' => 'exames', 'portal' => true])`.
- Família (`responsavel`, `paciente`) vê só coletas **liberadas**, só valor + unidade + frase; nunca laudo, HOMA-IR, observação, laboratório nem gráfico. `crianca` não vê nada.
- Laudos: PDF, JPEG, PNG, detectados por `finfo` (conteúdo, não extensão), máx. **10 MB** cada (`10 * 1024 * 1024`); nome no disco aleatório (`bin2hex(random_bytes(16))` + extensão do tipo detectado); download com `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`.
- Testes: `source tools/env.sh; start_mariadb` (se o banco não estiver rodando) e `php tools/phpunit.phar`. Toda rota GET nova entra em `tests/Http/SmokeAllRoutesTest.php::EXPECTED` **na mesma tarefa** que a cria (papéis na ordem `M, N, S, R, P`; equipe = `[200, 403, 403, 403, 403]`; portal = `[302, 302, 302, 200, 200]`; ajuste os códigos ao que a rota realmente faz com os dados de fumaça e comente quando fugir do padrão).
- Commits pequenos, mensagem em português no estilo do repositório (`feat:`, `fix:`, `docs:`, `test:`), terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Responsável/paciente abrindo rotas da equipe** (`/pacientes/{id}/exames`, `/exames/{collectionId}`, download de laudo, `serie.json`, catálogo) depois que a família ganha `view EXAMES`: 403 em todas — teste na Task 8 (`test_family_gets_403_on_every_staff_exam_route`).
2. **Arquivo disfarçado** (PHP/HTML renomeado `.pdf`, PDF de 11 MB, envio acima do `post_max_size` que chega com `$_POST` vazio): recusar com mensagem clara, nada gravado em disco nem no banco; o caso `post_max_size` mostra "grande demais", não "sessão expirou" — testes na Task 4.
3. **Valor digitado de jeitos reais** ("5,7", "5.7", " 98 ", "1.234" ambíguo, "<5", "indetectável", "abc", vazio): número aceito, qualitativo vira texto sem classificação, lixo rejeitado com o nome do exame na mensagem, vazio = exame não veio — testes na Task 1 e Task 3.
4. **Criança que muda de faixa de idade** (TG aos 10 anos; 20 anos vira adulto) e **paciente fora de qualquer faixa** (< 2 anos): cada coleta classificada pela idade **na data da coleta**; sem faixa → sem marca e aviso, nunca a faixa vizinha — testes na Task 1 e Task 3 (`test_evolution_classifies_by_age_at_each_collection`).
5. **Coleta não liberada ou excluída vazando** para o portal ou para a tabela/gráfico da equipe (excluída): invisível em ambos — testes na Task 3 e Task 8.

---

### Task 1: Classificação, HOMA-IR e leitura de valores (classes puras)

**Files:**
- Create: `app/Labs/LabClassifier.php`, `app/Labs/LabValue.php`
- Test: `tests/Unit/LabClassifierTest.php`, `tests/Unit/LabValueTest.php`

**Interfaces:**
- Produces:
  - Faixa (array) = `['sex' => 'M'|'F'|null, 'age_min_months' => int, 'age_max_months' => ?int /*inclusivo*/, 'borderline_high' => ?float, 'abnormal_high' => ?float, 'borderline_low' => ?float, 'abnormal_low' => ?float]`.
  - `LabClassifier::pickRange(list<array> $ranges, string $sex, int $ageMonths): ?array` — primeira faixa cujo sexo é `null` ou igual e `age_min_months <= idade <= (age_max_months ?? ∞)`.
  - `LabClassifier::classify(float $value, array $range): string` — retorna `'alterado_alto'|'limitrofe_alto'|'alterado_baixo'|'limitrofe_baixo'|'normal'`. Regra do spec §5.3 (corte pertence à categoria pior), nesta ordem: `v >= abnormal_high` → alterado_alto; `v >= borderline_high` → limitrofe_alto; `v <= abnormal_low` → alterado_baixo; `v <= borderline_low` → limitrofe_baixo; senão normal (limites nulos são ignorados).
  - `LabClassifier::ageMonths(string $birthYmd, string $onYmd): int` = meses **completos de calendário** (`$diff->y * 12 + $diff->m` de `DateTimeImmutable::diff`, datas em UTC como `Age::d`), para que o 10º aniversário dê exatamente 120. **Não** usar `Age::months()` (dias / 30,4375 dá 119,98 no aniversário de 10 anos).
  - `LabClassifier::homaIr(?float $glicemiaMgDl, ?float $insulinaUuMl): ?float` = `round(g * i / 405, 2)`, `null` se algum for nulo ou ≤ 0.
  - `LabClassifier::familyPhrase(?string $class): ?string` — `normal` → "dentro do esperado"; `limitrofe_alto` → "um pouco acima do esperado"; `alterado_alto` → "acima do esperado"; `limitrofe_baixo` → "um pouco abaixo do esperado"; `alterado_baixo` → "abaixo do esperado"; `null` → `null`.
  - `LabClassifier::MARK` = `['alterado_alto' => '▲', 'alterado_baixo' => '▼', 'limitrofe_alto' => '◆', 'limitrofe_baixo' => '◆', 'normal' => '']` e `LabClassifier::LABEL` (texto acessível: "alterado (alto)", "alterado (baixo)", "limítrofe", "limítrofe", "normal").
  - `LabValue::parse(string $raw): ?array{num: ?float, text: ?string}` — `trim`; vazio → `null` (não veio); `^\d+([.,]\d+)?$` → número (vírgula = decimal); começa com `<`, `>`, `≤`, `≥` ou é só letras/espaços (ex.: "indetectável", "negativo") e tem até 40 caracteres → texto; qualquer outra coisa → lança `\InvalidArgumentException`. "1.234" é lido como 1,234 (ponto = decimal; laudos de exames não usam separador de milhar nesses analitos).

- [ ] **Step 1: Escrever os testes**

```php
// LabClassifierTest
private const LDL_CHILD = ['sex' => null, 'age_min_months' => 24, 'age_max_months' => 239, 'borderline_high' => 110.0, 'abnormal_high' => 130.0, 'borderline_low' => null, 'abnormal_low' => null];
private const HDL = ['sex' => null, 'age_min_months' => 24, 'age_max_months' => null, 'borderline_high' => null, 'abnormal_high' => null, 'borderline_low' => 45.0, 'abnormal_low' => 39.0];

public function test_cut_belongs_to_worse_category(): void
{
    $this->assertSame('normal', LabClassifier::classify(109.0, self::LDL_CHILD));
    $this->assertSame('limitrofe_alto', LabClassifier::classify(110.0, self::LDL_CHILD));
    $this->assertSame('limitrofe_alto', LabClassifier::classify(129.9, self::LDL_CHILD));
    $this->assertSame('alterado_alto', LabClassifier::classify(130.0, self::LDL_CHILD));
}
public function test_low_side_only(): void
{
    $this->assertSame('normal', LabClassifier::classify(46.0, self::HDL));
    $this->assertSame('limitrofe_baixo', LabClassifier::classify(45.0, self::HDL));
    $this->assertSame('limitrofe_baixo', LabClassifier::classify(40.0, self::HDL));
    $this->assertSame('alterado_baixo', LabClassifier::classify(39.0, self::HDL));
}
public function test_pick_range_by_age_and_sex(): void
{
    $tg = [
        ['sex' => null, 'age_min_months' => 24, 'age_max_months' => 119, 'borderline_high' => 75.0, 'abnormal_high' => 100.0, 'borderline_low' => null, 'abnormal_low' => null],
        ['sex' => null, 'age_min_months' => 120, 'age_max_months' => 239, 'borderline_high' => 90.0, 'abnormal_high' => 130.0, 'borderline_low' => null, 'abnormal_low' => null],
    ];
    $this->assertSame(75.0, LabClassifier::pickRange($tg, 'F', 119)['borderline_high']);
    $this->assertSame(90.0, LabClassifier::pickRange($tg, 'F', 120)['borderline_high']);
    $this->assertNull(LabClassifier::pickRange($tg, 'F', 23));    // < 2 anos: sem faixa
    $this->assertNull(LabClassifier::pickRange($tg, 'M', 240));   // adulto: sem faixa nesta lista
    $alt = [['sex' => 'M', 'age_min_months' => 24, 'age_max_months' => 239, 'borderline_high' => null, 'abnormal_high' => 27.0, 'borderline_low' => null, 'abnormal_low' => null],
            ['sex' => 'F', 'age_min_months' => 24, 'age_max_months' => 239, 'borderline_high' => null, 'abnormal_high' => 23.0, 'borderline_low' => null, 'abnormal_low' => null]];
    $this->assertSame(23.0, LabClassifier::pickRange($alt, 'F', 100)['abnormal_high']);
}
public function test_homa_ir(): void
{
    $this->assertSame(3.11, LabClassifier::homaIr(90.0, 14.0));
    $this->assertNull(LabClassifier::homaIr(90.0, null));
    $this->assertNull(LabClassifier::homaIr(0.0, 14.0));
}
public function test_age_months_and_phrases(): void
{
    $this->assertSame(119, LabClassifier::ageMonths('2016-05-10', '2026-05-09'));
    $this->assertSame(120, LabClassifier::ageMonths('2016-05-10', '2026-05-10'));
    $this->assertSame('um pouco acima do esperado', LabClassifier::familyPhrase('limitrofe_alto'));
    $this->assertNull(LabClassifier::familyPhrase(null));
}
// LabValueTest
public function test_parse(): void
{
    $this->assertNull(LabValue::parse('  '));
    $this->assertSame(['num' => 5.7, 'text' => null], LabValue::parse('5,7'));
    $this->assertSame(['num' => 5.7, 'text' => null], LabValue::parse(' 5.7 '));
    $this->assertSame(['num' => 98.0, 'text' => null], LabValue::parse('98'));
    $this->assertSame(['num' => null, 'text' => '<5'], LabValue::parse('<5'));
    $this->assertSame(['num' => null, 'text' => 'indetectável'], LabValue::parse('indetectável'));
    $this->expectException(\InvalidArgumentException::class);
    LabValue::parse('9,8,1');
}
```

- [ ] **Step 2: Rodar e ver falhar** — `php tools/phpunit.phar tests/Unit/LabClassifierTest.php tests/Unit/LabValueTest.php` → erro "Class not found".
- [ ] **Step 3: Implementar** `LabClassifier` e `LabValue` com as assinaturas acima (classes `final`, métodos estáticos).
- [ ] **Step 4: Rodar e ver passar** (mesmo comando) → OK.
- [ ] **Step 5: Commit** — `feat: classificacao de exames, HOMA-IR e leitura de valores`.

---

### Task 2: Tabelas, catálogo pré-carregado com fontes e repositório do catálogo

**Files:**
- Create: `database/migrations/009_lab_exams.sql`, `database/seeds/lab_catalog.sql`, `data/exams/SOURCES.md`
- Create: `app/Labs/LabCatalogRepository.php`
- Modify: `app/Core/Container.php` (accessor `labCatalog()`), `tests/TestSchema.php` se ele listar tabelas para limpar
- Test: `tests/Integration/LabCatalogRepositoryTest.php`

**Interfaces:**
- Produces:
  - Tabelas (InnoDB utf8mb4):
    - `lab_tests(id INT UNSIGNED PK AUTO_INCREMENT, code VARCHAR(40) NULL UNIQUE, name VARCHAR(100) NOT NULL, unit VARCHAR(20) NOT NULL, group_name VARCHAR(40) NOT NULL, sort_order SMALLINT NOT NULL DEFAULT 0, decimals TINYINT UNSIGNED NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, reference_confirmed TINYINT(1) NOT NULL DEFAULT 0, created_by INT UNSIGNED NULL FK users, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL) AUTO_INCREMENT=1000`
    - `lab_ranges(id INT UNSIGNED PK AUTO_INCREMENT, test_id FK lab_tests, sex ENUM('M','F') NULL, age_min_months SMALLINT UNSIGNED NOT NULL, age_max_months SMALLINT UNSIGNED NULL, borderline_high/abnormal_high/borderline_low/abnormal_low DECIMAL(10,3) NULL, source VARCHAR(255) NULL, KEY(test_id)) AUTO_INCREMENT=1000`
    - `lab_collections(id PK, patient_id FK patients, collected_on DATE NOT NULL, lab_name VARCHAR(120) NULL, notes TEXT NULL, released_at DATETIME NULL, released_by NULL FK users, created_by FK users, created_at, updated_by NULL FK, updated_at NULL, deleted_at NULL, deleted_by NULL FK, KEY(patient_id, collected_on))`
    - `lab_results(id PK, collection_id FK lab_collections, test_id FK lab_tests, value_num DECIMAL(12,4) NULL, value_text VARCHAR(40) NULL, UNIQUE(collection_id, test_id))`
    - `lab_attachments(id PK, collection_id FK lab_collections, stored_path VARCHAR(255) NOT NULL, original_name VARCHAR(200) NOT NULL, mime VARCHAR(50) NOT NULL, size_bytes INT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL, uploaded_by FK users, uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at NULL, deleted_by NULL FK, KEY(collection_id))`
  - Seed `lab_catalog.sql` — exames (id, code, name, unit, group_name, sort_order, decimals):
    `1 glicemia "Glicemia de jejum" mg/dL glicose 10 0` · `2 hba1c "Hemoglobina glicada (HbA1c)" % glicose 20 1` · `3 insulina "Insulina de jejum" µU/mL glicose 30 1` · `4 colesterol_total "Colesterol total" mg/dL lipidios 40 0` · `5 hdl "HDL-colesterol" mg/dL lipidios 50 0` · `6 ldl "LDL-colesterol" mg/dL lipidios 60 0` · `7 nao_hdl "Colesterol não-HDL" mg/dL lipidios 70 0` · `8 triglicerides "Triglicerídeos" mg/dL lipidios 80 0` · `9 tgo "TGO (AST)" U/L figado 90 0` · `10 tgp "TGP (ALT)" U/L figado 100 0`.
    Faixas de partida (o implementador **confere cada número na fonte** e corrige o seed se a fonte disser outra coisa, anotando em `SOURCES.md`; nunca inventar faixa sem fonte):
    - Crianças/adolescentes 24–239 meses (NHLBI 2011 Expert Panel; adotado pela Atualização da Diretriz Brasileira de Dislipidemias, SBC 2017): CT limítrofe ≥170, alterado ≥200; LDL 110 / 130; não-HDL 120 / 145; HDL limítrofe ≤45, alterado ≤39; TG 24–119 meses 75 / 100, 120–239 meses 90 / 130.
    - Adultos ≥240 meses (SBC 2017, valores referenciais em jejum): CT alterado ≥190; HDL alterado ≤39; TG alterado ≥150; não-HDL e LDL: conferir a diretriz — a SBC 2017 define LDL/não-HDL por categoria de risco; se não houver valor referencial único, **deixar sem faixa** e registrar o motivo.
    - Glicemia de jejum ≥24 meses (SBD/ADA): limítrofe ≥100, alterado ≥126. HbA1c ≥24 meses: limítrofe ≥5,7, alterado ≥6,5.
    - TGP 24–239 meses (NASPGHAN 2017, ALT, limite superior SAFETY): M alterado ≥27 (normal ≤26), F alterado ≥23 (normal ≤22).
    - Sem faixa: insulina, TGO (todas as idades), TGP adulto (depende do laboratório). Registrar o motivo em `SOURCES.md`.
    - Coluna `source` de cada faixa = referência curta (ex.: "NHLBI 2011 / SBC 2017"); `SOURCES.md` traz a referência completa, a URL e a conversão "< 40" → 39 (§5.3 do spec).
  - `LabCatalogRepository`:
    - `tests(bool $activeOnly = true): list<array>` ordenado por `sort_order, name`; `find(int $id): ?array`; `findByCode(string $code): ?array`.
    - `ranges(int $testId): list<array>` (floats/ints já convertidos, no formato da Task 1); `rangesByTest(): array<int, list<array>>` (todas, para classificar uma tabela inteira).
    - `saveTest(?int $id, array $data, int $uid, Request $r): int` — campos `name, unit, group_name, sort_order, decimals, active`; validação: nome e unidade obrigatórios, nome único entre ativos → `InvalidLabException` (`public array $errors`).
    - `saveRanges(int $testId, list<array> $ranges, int $uid, Request $r): void` — substitui as faixas do exame numa transação; valida: `age_min <= age_max`; sem sobreposição de idade entre faixas do mesmo sexo, e faixa `sex = null` não pode sobrepor nenhuma faixa M/F; em cada lado, limítrofe "antes" do alterado (`borderline_high < abnormal_high`, `borderline_low > abnormal_low` quando ambos existem); qualquer violação → `InvalidLabException`. Ao salvar, `reference_confirmed = 1` (editar = revisar).
    - `confirm(int $testId, int $uid, Request $r): void`.
  - `Container::labCatalog(): LabCatalogRepository`.
  - `App\Labs\InvalidLabException extends \RuntimeException` com `public array $errors`.

- [ ] **Step 1: Pesquisar e escrever `data/exams/SOURCES.md`** com cada fonte (título, ano, URL, tabela/página usada) e a tabela final de faixas que entrou no seed.
- [ ] **Step 2: Escrever o teste** (`tests/Integration/LabCatalogRepositoryTest.php`, estende `DbTestCase`):

```php
public function test_seed_has_catalog_and_child_lipid_ranges(): void
{
    $repo = Container::labCatalog();
    $this->assertSame(['glicemia','hba1c','insulina','colesterol_total','hdl','ldl','nao_hdl','triglicerides','tgo','tgp'], array_column($repo->tests(), 'code'));
    $tg = $repo->ranges($repo->findByCode('triglicerides')['id']);
    $this->assertSame(90.0, LabClassifier::pickRange($tg, 'F', 120)['borderline_high']);
    $this->assertSame([], $repo->ranges($repo->findByCode('insulina')['id']));
    $this->assertSame(0, (int) $repo->findByCode('ldl')['reference_confirmed']);
}
public function test_seed_rerun_keeps_doctor_edits(): void
{
    $repo = Container::labCatalog(); $u = $this->makeUser('medico');
    $ldl = $repo->findByCode('ldl');
    $repo->saveRanges($ldl['id'], [['sex' => null, 'age_min_months' => 24, 'age_max_months' => 239, 'borderline_high' => 100.0, 'abnormal_high' => 130.0, 'borderline_low' => null, 'abnormal_low' => null, 'source' => 'Dra.']], $u['id'], new Request('POST', '/'));
    SeedLoader::loadDir($this->db, APP_ROOT . '/database/seeds');
    $this->assertSame(100.0, $repo->ranges($ldl['id'])[0]['borderline_high']);
    $this->assertSame(1, (int) $repo->find($ldl['id'])['reference_confirmed']);
}
public function test_overlapping_ranges_rejected(): void
{
    // sex null 24–239 + sex F 100–150 do mesmo exame → InvalidLabException; nada gravado (faixas antigas intactas)
}
public function test_borderline_must_precede_abnormal(): void
{
    // borderline_high 130, abnormal_high 110 → InvalidLabException com mensagem citando o exame
}
```

  (Escreva os dois últimos com os dados descritos no comentário; assertivas: exceção lançada e `ranges()` igual ao de antes.)
- [ ] **Step 3: Rodar e ver falhar** — `php tools/phpunit.phar tests/Integration/LabCatalogRepositoryTest.php`.
- [ ] **Step 4: Implementar** migração, seed (ids fixos, no-op upsert), repositório, exceção e accessor. Confirmar que `DbTestCase`/`TestSchema` aplica a migração 009 e carrega o seed (seguir o que já faz com `food_*.sql`).
- [ ] **Step 5: Rodar e ver passar**; rodar a suíte inteira (`php tools/phpunit.phar`) → OK.
- [ ] **Step 6: Commit** — `feat: tabelas de exames e catalogo com faixas de referencia`.

---

### Task 3: Repositório de coletas (resultados, liberação, evolução, série)

**Files:**
- Create: `app/Labs/LabRepository.php`
- Modify: `app/Core/Container.php` (accessor `labs()`)
- Test: `tests/Integration/LabRepositoryTest.php`

**Interfaces:**
- Consumes: Task 1 (`LabClassifier`, `LabValue`), Task 2 (`LabCatalogRepository`, tabelas, `InvalidLabException`).
- Produces (`LabRepository`):
  - `create(int $patientId, array $data, array $values, int $uid, Request $r): int` — `$data = ['collected_on' => 'Y-m-d', 'lab_name' => ?string, 'notes' => ?string]`; `$values = [testId => string digitada]`. Valida: data válida, não futura, não anterior ao nascimento; cada valor via `LabValue::parse` (erro → mensagem `"<nome do exame>: valor inválido."`); exame inexistente/inativo → erro; **ao menos um resultado** (`"Informe pelo menos um resultado."`). Tudo numa transação; auditoria `create` com after = coleta + resultados.
  - `update(int $collectionId, array $data, array $values, int $uid, Request $r): void` — mesma validação; resultados substituídos (apaga e insere na transação); auditoria `update` com before/after completos. Coleta excluída → `InvalidLabException`.
  - `delete(int $collectionId, int $uid, Request $r): void` (lógica), `release(...)`, `unrelease(...)` — cada uma auditada.
  - `find(int $id): ?array` (inclui excluída, com chave `results` = `list<{test_id, value_num, value_text}>`).
  - `collections(int $patientId, bool $releasedOnly = false): list<array>` — não excluídas, `collected_on DESC, id DESC`, cada uma com `results` e `attachment_count`.
  - `evolution(int $patientId, ?int $limit = 6): array` — `['collections' => list<{id, collected_on, released}>, 'total' => int, 'rows' => list<{test, cells: array<collectionId, {value: string, class: ?string, no_range: bool}>}>, 'homa' => ?{cells: array<collectionId, {value: string}>}]`. Linhas só de exames com algum resultado nas coletas mostradas, na ordem do catálogo; `value` formatado com `fmt_num(valor, decimals)` ou o texto; `class` pela faixa escolhida com sexo do paciente e `ageMonths(birth_date, collected_on)`; `no_range = true` quando o valor é numérico e não há faixa. `homa` só se alguma coleta tiver glicemia e insulina **e** as unidades dos exames `glicemia`/`insulina` forem `mg/dL`/`µU/mL`.
  - `series(int $patientId, int $testId): array{test: array, points: list<{date: string, value: float, class: ?string}>, band: ?array{low: ?float, high: ?float}}` — só valores numéricos de coletas não excluídas, ordem cronológica; `band` = `['low' => borderline_low ?? abnormal_low, 'high' => borderline_high ?? abnormal_high]` da faixa **se a mesma faixa vale para todos os pontos**, senão `null`.
  - `familyView(int $patientId): list<array{collected_on: string, items: list<{name: string, value: string, unit: string, phrase: ?string}>}>` — só liberadas e não excluídas; sem HOMA-IR, laboratório, observação.
  - `Container::labs(): LabRepository`.

- [ ] **Step 1: Escrever os testes** (dados: paciente F nascida `2016-05-10`, médico; exames do seed):

```php
public function test_create_validates_and_audits(): void
{
    // valores ['glicemia' => '98', 'hba1c' => '5,7', 'insulina' => '', 'ldl' => 'abc'] → InvalidLabException com "LDL-colesterol: valor inválido."; nenhuma linha em lab_collections
    // data futura → erro; data antes do nascimento → erro; todos vazios → "Informe pelo menos um resultado."
    // válido → 1 coleta, 2 resultados (insulina vazia não grava); audit_log tem 'create' com after contendo os 2 resultados
}
public function test_evolution_classifies_by_age_at_each_collection(): void
{
    // TG 95 em 2026-05-09 (119 meses) → 'limitrofe_alto' (faixa 75/100); TG 95 em 2026-05-10 (120 meses) → 'limitrofe_alto' (faixa 90/130)
    // TG 80 em 2026-05-09 → 'limitrofe_alto'; TG 80 em 2026-05-10 → 'normal'
    // paciente nascido 2025-01-01 com LDL 150 em 2026-06-01 → class null, no_range true
}
public function test_homa_only_with_both_values_in_same_collection(): void
{
    // coleta A: glicemia 90 + insulina 14 → homa '3,11'; coleta B: só glicemia → célula ausente
}
public function test_deleted_and_unreleased_hidden_where_required(): void
{
    // coleta excluída não aparece em collections/evolution/series; não liberada não aparece em familyView; liberada aparece com phrase 'acima do esperado' para LDL 140 (criança)
}
public function test_series_band_null_when_range_changes(): void
{
    // TG em 2026-05-09 e 2026-05-10 → band null; dois pontos aos 11 anos → band ['low' => null, 'high' => 90.0]
}
```

  (Escreva cada teste com os dados do comentário e assertivas exatas sobre os valores citados.)
- [ ] **Step 2: Rodar e ver falhar** — `php tools/phpunit.phar tests/Integration/LabRepositoryTest.php`.
- [ ] **Step 3: Implementar** `LabRepository` e o accessor. Leitura de paciente via `Container::patients()->find()` (sexo e nascimento).
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: repositorio de coletas de exames com evolucao e serie`.

---

### Task 4: Laudos — armazenamento seguro, download e limite de envio

**Files:**
- Create: `app/Labs/AttachmentStore.php`
- Modify: `app/Core/Router.php` (middleware `csrf`), `app/Core/App.php` (mensagem do 413), `app/Core/Response.php` só se precisar de `header()` encadeado (já existe)
- Test: `tests/Integration/AttachmentStoreTest.php`, `tests/Unit/CsrfTest.php` (caso novo) ou `tests/Http/AppTest.php`

**Interfaces:**
- Produces (`AttachmentStore`, construído com `Database`, `AuditLog` e a pasta `<storage>/uploads`):
  - `store(int $collectionId, int $patientId, array $file, int $uid, Request $r): int` — `$file` no formato de `$_FILES['x']` de um arquivo. Recusa (lança `InvalidLabException` com mensagem em português) quando: `error !== UPLOAD_ERR_OK` (para `UPLOAD_ERR_INI_SIZE`/`FORM_SIZE`: "O arquivo <nome> é maior que o permitido (máx. 10 MB)."), `size > 10 MB`, ou tipo `finfo(FILEINFO_MIME_TYPE)` fora de `application/pdf`, `image/jpeg`, `image/png` ("Envie o laudo em PDF, JPG ou PNG."). Grava em `exames/AAAA/MM/<32 hex>.<pdf|jpg|png>` (cria pastas 0750); usa `move_uploaded_file` quando `is_uploaded_file`, senão `rename` (para testes, protegido por um parâmetro de construtor `bool $allowNonUploaded = false`). Guarda `original_name` (só o basename, até 200 caracteres), mime, tamanho, sha256. Auditoria `upload`.
  - `storeMany(int $collectionId, int $patientId, array $filesField, int $uid, Request $r): list<int>` — normaliza o formato multi-arquivo do PHP (`name[]`); ignora entradas `UPLOAD_ERR_NO_FILE`; valida **todos** antes de gravar qualquer um.
  - `find(int $id): ?array` (com `patient_id` via join), `absolutePath(array $att): string` (garante que fica dentro da pasta de uploads — `realpath` e prefixo), `delete(int $id, int $uid, Request $r): void` (lógica; arquivo fica).
  - `downloadResponse(array $att): Response` — `Response::file(path, mime, original_name)` + `Cache-Control: private, no-store`.
  - `Container::labAttachments(): AttachmentStore`.
- Middleware `csrf`: antes de `Csrf::check`, se método POST, `$r->post === []`, `$r->files === []` e `CONTENT_LENGTH` > `post_max_size` (convertido de "8M" etc.), lança `HttpException(413)`; `App` mapeia 413 → "O envio é maior que o limite do servidor (máx. X MB). Envie arquivos menores." (X = `post_max_size` em MB).

- [ ] **Step 1: Escrever os testes**

```php
public function test_rejects_disguised_file(): void
{
    // arquivo temporário com "<?php echo 1;" chamado laudo.pdf → InvalidLabException "Envie o laudo em PDF, JPG ou PNG."; nenhum arquivo novo na pasta; 0 linhas em lab_attachments
}
public function test_rejects_over_10mb(): void { /* size 10*1024*1024+1 (arquivo real com cabeçalho %PDF-) → exceção com "máx. 10 MB" */ }
public function test_store_many_is_all_or_nothing(): void { /* 1 PDF válido + 1 falso → exceção; nada gravado */ }
public function test_stores_pdf_outside_public_and_downloads(): void
{
    // PDF mínimo válido ("%PDF-1.4\n%%EOF") → linha com mime application/pdf, sha256 correto, stored_path começando com 'exames/' e terminando em '.pdf'
    // downloadResponse: headers Content-Type application/pdf, Content-Disposition attachment; filename="laudo março.pdf", Cache-Control 'private, no-store', X-Content-Type-Options nosniff
}
public function test_absolute_path_cannot_escape_uploads(): void { /* stored_path '../config/config.php' → exceção */ }
// Http/AppTest: POST com post=[] files=[] e server CONTENT_LENGTH maior que post_max_size → status 413 e corpo contém "maior que o limite"
```

- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** `AttachmentStore`, accessor, middleware e mensagem 413.
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: armazenamento seguro de laudos e limite de envio`.

---

### Task 5: Telas da equipe — exames da paciente, nova/editar coleta, anexos e liberação

**Files:**
- Create: `app/Labs/Clinician.php`, `app/Labs/LabController.php`
- Create: `app/Labs/views/index.php` (tabela de evolução + lista de coletas), `app/Labs/views/form.php` (nova/editar), `app/Labs/views/show.php` (coleta: resultados com marca, anexos, liberar/recolher, editar, excluir)
- Modify: `app/routes.php`, `app/Patients/PatientController.php` + `app/Patients/views/show.php` (cartão **Exames**), `public/assets/css/*.css` (marcas e tabela), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/LabPagesTest.php`

**Interfaces:**
- Consumes: Tasks 2–4.
- Produces:
  - `Clinician::assert(array $user, Request $r, ?int $patientId = null): void` — passa se `role === 'medico'`; senão audita `denied` (como `Nutrition\Staff::assert`) e lança `HttpException(403)`. `Clinician::is(array $user): bool`.
  - Rotas (todas `$authMw`; POST com csrf como as demais):
    - `GET /pacientes/{id}/exames` → `index`; `GET|POST /pacientes/{id}/exames/nova` → `createForm`/`create`
    - `GET /exames/{collectionId}` → `show`; `GET|POST /exames/{collectionId}/editar` → `editForm`/`update`
    - `POST /exames/{collectionId}/excluir`, `/liberar`, `/recolher`, `/anexos` (envio adicional)
    - `GET /exames/anexos/{attachmentId}` → `download`; `POST /exames/anexos/{attachmentId}/excluir`
  - Formulário: campos `coletado_em` (date), `laboratorio`, `observacao`, `valor[<testId>]`, `laudos[]` (`multiple`, `accept="application/pdf,image/jpeg,image/png"`), `enctype="multipart/form-data"`. Exames ativos agrupados por `group_name` (rótulos: glicose → "Glicemia e insulina", lipidios → "Lipídios", figado → "Fígado", outros → "Outros"); unidade ao lado de cada campo.
  - Criar: grava a coleta (Task 3) e **depois** os laudos (Task 4) na mesma transação lógica: se os laudos falharem, a coleta não fica gravada (envolver ambos em `Database::transaction`; arquivos já movidos são apagados no rollback). Em erro de validação: re-renderiza com valores preenchidos e o aviso "Escolha os arquivos do laudo de novo." quando havia arquivos.
  - Cartão **Exames** na ficha (só `Clinician::is`): data da última coleta e quantos valores alterados nela; botão "Ver exames".
  - `index` mostra até 6 coletas com link "ver todas" (`?todas=1` → `evolution($id, null)`); célula = valor + marca + `<span class="sr-only">` com `LabClassifier::LABEL`; "sem faixa para esta idade" como `title` + texto acessível; linha HOMA-IR com o rótulo "HOMA-IR (calculado)".

- [ ] **Step 1: Escrever os testes** (`tests/Http/LabPagesTest.php`, no estilo de `tests/Http/MealPlanTextTest.php`):

```php
public function test_doctor_creates_collection_and_sees_evolution(): void { /* POST nova com glicemia 98, ldl 140 → redirect para /exames/{id}; GET /pacientes/{id}/exames contém '140' e '▲' e 'LDL-colesterol' */ }
public function test_validation_keeps_values_and_asks_files_again(): void { /* ldl 'abc' + laudo enviado → 200 com 'LDL-colesterol: valor inválido.', value="98" no campo da glicemia e 'Escolha os arquivos do laudo de novo.'; 0 coletas */ }
public function test_nutritionist_and_secretary_get_403(): void { /* GET /pacientes/{id}/exames e /exames/{id} → 403 para nutricionista com acesso concedido e para secretária */ }
public function test_release_unrelease_and_delete_are_audited(): void { /* POST liberar → released_at preenchido + audit 'release'; recolher → null; excluir → some do index */ }
public function test_download_requires_doctor(): void { /* médico → 200 com Content-Disposition; anexo excluído → 404 */ }
public function test_patient_card_shows_exams_only_for_doctor(): void { /* ficha: médico vê 'cartao-exames'; nutricionista não */ }
```

  (Escreva cada um com os dados do comentário; assertivas sobre status, textos e linhas no banco citados.)
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** controller, views, rotas, cartão, CSS; adicionar as rotas GET novas em `SmokeAllRoutesTest::EXPECTED` (equipe `[200, 403, 403, 403, 403]`; download pode precisar de dados de fumaça com anexo — se não houver, `404` para todos e comentário).
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK (inclui `ViewsCspTest`).
- [ ] **Step 5: Commit** — `feat: telas de exames da equipe com laudos e liberacao`.

---

### Task 6: Gráfico por exame

**Files:**
- Create: `public/assets/js/lab-chart.js`
- Modify: `app/Labs/LabController.php` (`series` JSON), `app/Labs/views/index.php` (nome do exame vira botão que abre o gráfico; `<div id="lab-chart" data-url=...>` com `<canvas>`), `app/routes.php`, `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/LabPagesTest.php` (casos novos)

**Interfaces:**
- Consumes: `LabRepository::series()` (Task 3).
- Produces: `GET /pacientes/{id}/exames/serie.json?exame=<testId>` → `Response::json(['test' => ['name','unit','decimals'], 'points' => [...], 'band' => ...])`; 404 para exame inexistente; mesma autorização das telas da equipe (Clinician + Gate view EXAMES). `lab-chart.js`: Chart.js line chart, eixo x por data (`dd/mm/aaaa`), faixa sombreada entre `band.low` e `band.high` quando `band` não é nulo (dataset de área), senão pontos coloridos por `class` (alterado = `#b3261e`, limítrofe = `#b06000`, normal = cor padrão) — mesmas cores do `growth-chart.js`; texto alternativo: lista dos pontos num `<details>` abaixo do gráfico (acessibilidade e impressão).

- [ ] **Step 1: Testes** — `test_series_json_shape` (2 coletas com LDL → `points` com 2 itens em ordem cronológica, `band.high` 110.0 para criança de 8 anos), `test_series_json_403_for_nutritionist`, `test_series_json_404_unknown_test`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** rota, ação e JS (sem inline; script carregado com `<script src>` como em `app/Growth/views/index.php:160-161`).
- [ ] **Step 4: Rodar e ver passar**; conferir no navegador com `php -S` (gráfico desenha, sem erro de CSP no console).
- [ ] **Step 5: Commit** — `feat: grafico historico por exame`.

---

### Task 7: Catálogo de exames (editar exames e faixas, confirmar)

**Files:**
- Create: `app/Labs/LabCatalogController.php`, `app/Labs/views/catalog_index.php`, `app/Labs/views/catalog_form.php`
- Modify: `app/routes.php`, `app/Layout/views/layout.php` (item de menu **Exames** → `/catalogo-exames`, só para `medico`), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/LabCatalogTest.php`

**Interfaces:**
- Consumes: `LabCatalogRepository` (Task 2).
- Produces: `GET /catalogo-exames` (lista com nome, unidade, grupo, ativo e selo **"a confirmar"** quando `reference_confirmed = 0`), `GET|POST /catalogo-exames/novo`, `GET|POST /catalogo-exames/{testId}/editar` (dados do exame + tabela de faixas editável: linhas com sexo [Ambos/M/F], idade de/até em **anos e meses** convertidos para meses, os quatro limites e fonte; "+ faixa"; remover linha), `POST /catalogo-exames/{testId}/confirmar`. Só `medico` (Clinician + Gate edit EXAMES). Desativar em vez de apagar. O formulário mostra a regra em uma linha de ajuda: "O valor igual ao limite já conta como limítrofe/alterado."
  - Linhas de faixa adicionadas/removidas por `public/assets/js/lab-ranges.js` (sem inline), com o formulário funcionando sem JS para editar as linhas existentes + 1 linha vazia.

- [ ] **Step 1: Testes** — `test_doctor_edits_ranges_and_confirms` (POST com faixa LDL 24–239 meses 100/130 → `ranges()` atualizado, `reference_confirmed = 1`, audit `update` com before/after), `test_overlap_shows_error_and_keeps_old_ranges`, `test_new_test_appears_in_collection_form` (cria "Vitamina D" ng/mL grupo outros → aparece em `/pacientes/{id}/exames/nova` sob "Outros"), `test_catalog_403_for_nutritionist_and_family`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** controller, views, JS, rotas, menu, smoke.
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: catalogo de exames com faixas editaveis`.

---

### Task 8: Portal da família — "Exames" liberados

**Files:**
- Modify: `app/Access/Policy.php` (`responsavel` e `paciente`: `view` ganha `self::EXAMES`; `crianca` inalterado), `tests/Unit/PolicyTest.php`
- Modify: `app/Portal/PortalController.php` (ação `exams`), `app/routes.php`, `app/Portal/views/home.php` (link **Exames** só se houver coleta liberada)
- Create: `app/Portal/views/exams.php`
- Modify: `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/PortalExamsTest.php`

**Interfaces:**
- Consumes: `LabRepository::familyView()` (Task 3).
- Produces: `GET /portal/p/{id}/exames` (via `PortalController::open($r, 'view', Policy::EXAMES)`); cada coleta liberada: "Exames de dd/mm/aaaa", lista "Nome: valor unidade — frase" (frase omitida quando nula). Sem coleta liberada: "Ainda não há exames liberados pela Dra. Rubia." Auditoria de leitura com `'portal' => true`.

- [ ] **Step 1: Testes**

```php
public function test_family_sees_only_released_with_phrases(): void { /* coleta liberada LDL 140 + glicemia 90 + insulina 14; outra não liberada com HDL 30 → página contém 'acima do esperado', 'dentro do esperado', não contém 'HOMA', '30', laboratório nem observação nem 'laudo' */ }
public function test_child_role_sees_nothing(): void { /* crianca → mesma resposta de hoje para seções sem permissão (403 ou página de indisponível — conferir o comportamento de /portal/p/{id}/alimentacao para crianca e repetir) */ }
public function test_family_gets_403_on_every_staff_exam_route(): void
{
    // responsável e paciente com acesso à paciente: GET /pacientes/{id}/exames, /pacientes/{id}/exames/nova, /exames/{cid}, /exames/{cid}/editar,
    // /exames/anexos/{aid}, /pacientes/{id}/exames/serie.json?exame=6, /catalogo-exames → 403; POST /exames/{cid}/liberar → 403
}
// PolicyTest: responsavel/paciente view EXAMES true, edit false; crianca false; nutricionista e secretaria false
```

- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** política, ação, view, link, rota e smoke (`[302, 302, 302, 200, 200]`).
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: exames liberados no portal da familia`.

---

### Task 9: Documentação e pacote

**Files:**
- Modify: `README.md` (seção "Exames laboratoriais": como registrar coleta, anexar laudo, liberar, revisar as faixas "a confirmar" no catálogo; §9 com a atualização desta versão: migração `009_lab_exams.sql` e seed `lab_catalog.sql` — ou `php tools/migrate.php`; o `INSERT INTO schema_migrations (name) VALUES ('009_lab_exams.sql');` para quem usar o phpMyAdmin; **extrair o zip em `rubia_app`, nunca na pasta do site**; cópia de `public/` obrigatória nesta versão; `upload_max_filesize`/`post_max_size` ≥ 10M no "Select PHP Version" do cPanel)
- Create: `docs/superpowers/backlog-fase2b.md` (pendências menores que surgirem nas revisões)
- [ ] **Step 1:** Escrever as seções; conferir os comandos citados contra os arquivos reais.
- [ ] **Step 2:** `php tools/phpunit.phar` → OK; `php tools/build_release.php` → pacote gerado, e `unzip -l` lista `database/migrations/009_lab_exams.sql`, `database/seeds/lab_catalog.sql` e `public/assets/js/lab-chart.js`.
- [ ] **Step 3: Commit** — `docs: exames laboratoriais no README e atualizacao de versao`.
