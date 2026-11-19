# Painel de evolução — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Página `/pacientes/{id}/evolucao` (só médica) com curva de peso (kg, ou escore-z do IMC abaixo de 229 meses), faixas de dose dos remédios no mesmo eixo, ritmo por dose, aviso "estabilizou" com critério por paciente, e metabolismo recolhido.

**Architecture:** Módulo novo `app/Evolution/`. Cálculos puros e testáveis (`EvolutionRate`), montagem dos pontos (`EvolutionPoints`), critério por paciente (`EvolutionSettingsRepository`, migração 015), controlador com página + JSON + POST do critério. Desenho com Chart.js já vendorizado em `public/assets/js/evolution-chart.js`.

**Tech Stack:** PHP 8.1 MVC próprio, MySQL 5.7, PHPUnit (`source tools/env.sh; php tools/phpunit.phar`), Chart.js local, node `--test` para JS puro.

**Spec:** `docs/superpowers/specs/2026-11-19-painel-evolucao-design.md`

## Global Constraints
- MySQL 5.7: nada só de MariaDB. CSP `default-src 'self'`: nenhum script inline; dados via `data-url` + fetch.
- CSS/JS na view por `asset('/assets/...')`.
- Acesso: só papel `medico` com `Gate` `view` em `Policy::ANTROPOMETRIA`, `Policy::MEDICAMENTOS` e `Policy::EXAMES`; editar critério exige também `edit` em `ANTROPOMETRIA` e `MEDICAMENTOS`. Os demais recebem 403.
- Criança = `Age::months(birth, today) < WeightGoalKind::ADULT_MONTHS` (229) → escore-z do IMC; senão kg.
- Padrões: `kg_per_week` 0.20, `pct_per_4w` 1.00, `z_per_4w` 0.050, modo adulto `kg`.
- Janela do aviso: 28 dias até a última pesagem; começa na mudança de dose mais recente se ela cair dentro; mínimo 3 pontos e 14 dias de extensão.
- Altura para escore-z: própria medida; senão a última altura dos 90 dias anteriores (com a correção deitado/em pé daquela medida); senão o ponto fica fora.
- Exames do metabolismo: códigos `glicemia`, `hba1c`, `insulina`, `triglicerides`, `hdl`, `tgp`, nessa ordem; mais cintura e pressão arterial.
- Período: `3m`, `6m` (padrão), `12m`, `tudo`.
- Textos em português; auditoria de visualização com `['section' => 'evolucao']`.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; nunca `git commit -a`; não adicionar `o que fazer.txt` nem `COLAR/`.

## Review Focus
1. Paciente sem nenhuma pesagem, ou sem remédio → página abre com mensagem, sem erro nem gráfico quebrado (teste no Task 4).
2. Duas pesagens no mesmo dia (casa e consultório) → um ponto só, o de maior id, como em `WeightGoalPoints` (teste no Task 2).
3. Dose mudou há 5 dias → "aguardando dados (nova dose há 5 dias)", nunca "estabilizou" (teste no Task 3).
4. Remédio encerrado no meio do período → faixa termina em `end_date` e não conta como mudança na janela depois do fim; a mudança de dose de um remédio já encerrado antes da janela não reinicia a janela (teste no Task 3).
5. Critério com vírgula ("0,3"), vazio ou absurdo (negativo, kg ≥ 5, % ≥ 20, z ≥ 1) → erro na página, nada salvo (teste no Task 1).

---

### Task 1: Critério por paciente (migração 015 + repositório)

**Files:**
- Create: `database/migrations/015_evolution_settings.sql`
- Create: `app/Evolution/EvolutionSettingsRepository.php`, `app/Evolution/InvalidEvolutionSettingsException.php`
- Modify: `app/Core/Container.php` (adicionar `evolutionSettings()`, padrão de `weightGoals()`)
- Test: `tests/Integration/EvolutionSettingsRepositoryTest.php`

**Interfaces:**
- Produces: `EvolutionSettingsRepository::get(int $patientId): array{adult_mode:string,kg_per_week:float,pct_per_4w:float,z_per_4w:float,saved:bool}`; `save(int $patientId, array $raw, int $by, Request $r): void` com `$raw` = `['modo'=>'kg'|'pct','kg'=>string,'pct'=>string,'z'=>string]` (strings do formulário, vírgula aceita); lança `InvalidEvolutionSettingsException` com `public array $errors` (chaves `modo|kg|pct|z`). Constantes `DEFAULTS`.

- [ ] **Step 1: Migração** `evolution_settings`: `patient_id INT UNSIGNED PK` (FK patients), `adult_mode ENUM('kg','pct') NOT NULL DEFAULT 'kg'`, `kg_per_week DECIMAL(4,2)`, `pct_per_4w DECIMAL(4,2)`, `z_per_4w DECIMAL(4,3)` (todos NOT NULL), `updated_by INT UNSIGNED NOT NULL` (FK users), `updated_at DATETIME NOT NULL`. InnoDB utf8mb4. Conferir que `TestSchema::rebuild` aplica as migrações em ordem (sem mudança se já varre a pasta).

- [ ] **Step 2: Testes que falham**
```php
public function test_defaults_without_row(): void
{   $s = Container::evolutionSettings()->get((int) $this->patient['id']);
    $this->assertSame(['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05, 'saved' => false], $s); }
public function test_save_accepts_comma_and_updates(): void
{   $repo = Container::evolutionSettings(); $pid = (int) $this->patient['id'];
    $repo->save($pid, ['modo' => 'pct', 'kg' => '0,3', 'pct' => '1,5', 'z' => '0,08'], (int) $this->medico['id'], $this->req());
    $repo->save($pid, ['modo' => 'pct', 'kg' => '0,3', 'pct' => '2', 'z' => '0,08'], (int) $this->medico['id'], $this->req());
    $this->assertSame(['adult_mode' => 'pct', 'kg_per_week' => 0.3, 'pct_per_4w' => 2.0, 'z_per_4w' => 0.08, 'saved' => true], $repo->get($pid));
    $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM evolution_settings')); }
public function test_rejects_bad_values(): void  // Review Focus 5
{   foreach ([['modo' => 'x'], ['kg' => ''], ['kg' => '-0,1'], ['kg' => '5'], ['pct' => '20'], ['z' => '1']] as $bad) {
        try { Container::evolutionSettings()->save((int) $this->patient['id'], $bad + ['modo' => 'kg', 'kg' => '0,2', 'pct' => '1', 'z' => '0,05'], (int) $this->medico['id'], $this->req()); $this->fail(json_encode($bad)); }
        catch (InvalidEvolutionSettingsException $e) { $this->assertArrayHasKey(array_key_first($bad), $e->errors); } }
    $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM evolution_settings')); }
```
(Os limites: `0 < kg < 5`, `0 < pct < 20`, `0 < z < 1`. Se o helper do banco não tiver `value()`, usar o equivalente existente em `Database`.)

- [ ] **Step 3: Rodar** `php tools/phpunit.phar --filter EvolutionSettingsRepositoryTest` → FAIL (classe inexistente).
- [ ] **Step 4: Implementar.** Upsert com `INSERT ... ON DUPLICATE KEY UPDATE` (válido no MySQL 5.7). Número via `App\Support\Num` se já houver parser de vírgula; senão `str_replace(',', '.')` + `is_numeric`. Auditoria `record($by, 'update', 'evolution_settings', $pid, $pid, $before, $after, $r)` no padrão do projeto.
- [ ] **Step 5: Rodar de novo** → PASS. **Commit** `feat: criterio de estabilizacao por paciente (migracao 015)`.

### Task 2: Pontos de peso / escore-z com altura dos 90 dias

**Files:**
- Create: `app/Evolution/EvolutionPoints.php`
- Test: `tests/Integration/EvolutionPointsTest.php`

**Interfaces:**
- Consumes: `MeasurementRepository::current()` (linhas), `Container::growth()->indicators(...)`, `WeightGoalKind::ADULT_MONTHS`.
- Produces: `EvolutionPoints::isChild(array $patient, string $today): bool`; `EvolutionPoints::build(array $patient, array $measurements, bool $child): list<array{on:string,value:float,weight:float,source:string,id:int}>` — `value` = kg (adulto) ou escore-z do IMC (criança). Ordenado por data, um ponto por dia (maior id). Só medidas vigentes com peso.

- [ ] **Step 1: Testes que falham** (criança F nascida 2016-01-01; usar `Container::measurements()->create` como em `WeightGoalPagesTest::measure`):
  - `test_adult_points_are_kg_one_per_day`: duas medidas em 2026-03-01 (equipe 90,0 e depois familia 89,5) → um ponto `['on'=>'2026-03-01','value'=>89.5,'source'=>'familia']` (Review Focus 2).
  - `test_child_weight_without_height_uses_height_from_last_90_days`: altura 130,0 em 2026-01-10; pesagem só de peso em 2026-04-09 (89 dias) entra com `value` igual ao escore-z calculado por `indicators()` com 130,0; pesagem em 2026-04-11 (91 dias) fica fora.
  - `test_child_uses_own_height_when_present`.
  - `test_is_child_boundary`: 228 meses → true; 229 → false.
- [ ] **Step 2: Rodar** `--filter EvolutionPointsTest` → FAIL.
- [ ] **Step 3: Implementar.** Percorrer medidas em ordem de data guardando a última `[on, height_cm, position]`; para criança, altura emprestada vale se `Age::days(alturaOn, on) <= 90`; z via `indicators($sex,$birth,$on,$w,$h,$position,null)['bfa']['z']`; `null` → ponto fora.
- [ ] **Step 4: PASS. Commit** `feat: pontos de evolucao com escore-z e altura dos 90 dias`.

### Task 3: Cálculo puro — faixas de dose, ritmo e aviso

**Files:**
- Create: `app/Evolution/EvolutionRate.php`
- Test: `tests/Unit/EvolutionRateTest.php`

**Interfaces:**
- Consumes: pontos do Task 2 (`on`, `value`, `weight`).
- Produces:
  - `EvolutionRate::slopePerDay(list<array{on:string,value:float}> $pts): ?array{a:float,b:float,x0:string}` — mínimos quadrados com x em dias desde o 1º ponto; `null` com < 2 pontos.
  - `EvolutionRate::segments(array $prescription, list<array> $doses, string $today): list<array{from:string,to:?string,dose:string}>` — `from = max(effective_date, start_date)`, `to` = véspera da próxima dose, ou `end_date`, ou `null` (vigente); trechos que terminam antes de `start_date` são descartados.
  - `EvolutionRate::segmentRate(list<array> $pts, array $seg, string $today, bool $child): ?float` — kg/semana de perda (adulto, positivo = perdeu) ou queda de escore-z em 4 semanas (criança); `null` se < 2 pontos ou extensão < 7 dias.
  - `EvolutionRate::plateau(list<array> $pts, list<string> $changeDates, array $settings, bool $child): array{status:string,rate:?float,unit:string,daysSinceChange:?int,from:?string,to:?string}` — `status` ∈ `sem_dados|aguardando|funcionando|estabilizou`; `unit` ∈ `kg_semana|pct_4sem|z_4sem`.
  - `EvolutionRate::plateauText(array $p): string`.

- [ ] **Step 1: Testes que falham** (pontos gerados por helper `p(int $day, float $v)` a partir de 2026-01-01, `weight` = `v` no adulto):
```php
public function test_slope(): void { $r = EvolutionRate::slopePerDay([$this->p(0,100), $this->p(7,99), $this->p(14,98)]); $this->assertEqualsWithDelta(-1/7, $r['b'], 1e-9); }
public function test_kg_mode_working_and_stabilized(): void
{   $s = ['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05];
    $working = array_map(fn ($d) => $this->p($d, 100 - $d * 0.05), [0, 7, 14, 21, 28]);   // 0,35 kg/sem
    $r = EvolutionRate::plateau($working, ['2025-12-01'], $s, false);
    $this->assertSame('funcionando', $r['status']); $this->assertEqualsWithDelta(0.35, $r['rate'], 1e-9); $this->assertSame('kg_semana', $r['unit']);
    $flat = array_map(fn ($d) => $this->p($d, 100 - $d * 0.02), [0, 7, 14, 21, 28]);     // 0,14 kg/sem
    $this->assertSame('estabilizou', EvolutionRate::plateau($flat, [], $s, false)['status']);
    $gain = array_map(fn ($d) => $this->p($d, 100 + $d * 0.01), [0, 7, 14, 21, 28]);
    $this->assertSame('estabilizou', EvolutionRate::plateau($gain, [], $s, false)['status']); }
public function test_pct_mode_uses_fitted_start_weight(): void
{   // 100 kg perdendo 0,03 kg/dia: 0,84 kg em 28 dias = 0,84% < 1% -> estabilizou; com pct 0,5 -> funcionando
    $pts = array_map(fn ($d) => $this->p($d, 100 - $d * 0.03), [0, 7, 14, 21, 28]);
    $s = ['adult_mode' => 'pct', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05];
    $r = EvolutionRate::plateau($pts, [], $s, false);
    $this->assertSame('estabilizou', $r['status']); $this->assertEqualsWithDelta(0.84, $r['rate'], 1e-9); $this->assertSame('pct_4sem', $r['unit']);
    $this->assertSame('funcionando', EvolutionRate::plateau($pts, [], ['pct_per_4w' => 0.5] + $s, false)['status']); }
public function test_child_z_mode(): void
{   $s = ['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05];
    $pts = array_map(fn ($d) => $this->p($d, 2.5 - $d * 0.001), [0, 7, 14, 21, 28]);    // 0,028 em 28 dias
    $r = EvolutionRate::plateau($pts, [], $s, true);
    $this->assertSame('estabilizou', $r['status']); $this->assertSame('z_4sem', $r['unit']); $this->assertEqualsWithDelta(0.028, $r['rate'], 1e-9); }
public function test_recent_dose_change_waits(): void  // Review Focus 3
{   $pts = array_map(fn ($d) => $this->p($d, 100), [0, 7, 14, 21, 28]);                 // ultimo ponto 2026-01-29
    $r = EvolutionRate::plateau($pts, ['2026-01-24'], ['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05], false);
    $this->assertSame('aguardando', $r['status']); $this->assertSame(5, $r['daysSinceChange']);
    $this->assertSame('Aguardando dados: nova dose há 5 dias.', EvolutionRate::plateauText($r)); }
public function test_too_few_points_and_no_points(): void
{   $s = ['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05];
    $this->assertSame('aguardando', EvolutionRate::plateau([$this->p(0, 100), $this->p(20, 99)], [], $s, false)['status']);
    $this->assertSame('sem_dados', EvolutionRate::plateau([], [], $s, false)['status']); }
public function test_segments_follow_doses_and_end_date(): void  // Review Focus 4
{   $rx = ['start_date' => '2026-01-01', 'end_date' => '2026-03-15'];
    $doses = [['effective_date' => '2026-01-01', 'dose' => '2,5 mg'], ['effective_date' => '2026-02-01', 'dose' => '5 mg']];
    $this->assertSame([['from' => '2026-01-01', 'to' => '2026-01-31', 'dose' => '2,5 mg'], ['from' => '2026-02-01', 'to' => '2026-03-15', 'dose' => '5 mg']],
        EvolutionRate::segments($rx, $doses, '2026-04-01')); }
public function test_texts(): void
{   $this->assertSame('Perda de peso estabilizou nesta dose (0,14 kg/semana nas últimas 4 semanas).', EvolutionRate::plateauText(['status' => 'estabilizou', 'rate' => 0.14, 'unit' => 'kg_semana', 'daysSinceChange' => null]));
    $this->assertSame('Ainda perdendo peso: 0,35 kg/semana nas últimas 4 semanas.', EvolutionRate::plateauText(['status' => 'funcionando', 'rate' => 0.35, 'unit' => 'kg_semana', 'daysSinceChange' => null]));
    $this->assertSame('Sem pesagens para avaliar.', EvolutionRate::plateauText(['status' => 'sem_dados', 'rate' => null, 'unit' => 'kg_semana', 'daysSinceChange' => null])); }
```
Textos para as outras unidades: `pct_4sem` → `"0,84% do peso em 4 semanas"`, `z_4sem` → `"queda de 0,028 no escore-z em 4 semanas"` (3 casas), nos mesmos moldes ("Perda de peso estabilizou nesta dose (…)." / "Ainda perdendo peso: … ."). `aguardando` sem mudança recente: `"Aguardando dados: são precisas 3 pesagens em pelo menos 14 dias."`. Taxa negativa (ganho) mostrada como está (ex.: "-0,07 kg/semana").

- [ ] **Step 2: Rodar** `--filter EvolutionRateTest` → FAIL.
- [ ] **Step 3: Implementar.** Janela: `last` = data do último ponto; `start = last - 28 dias`; `change` = maior data em `$changeDates` com `start < change <= last`; se existir, `start = change` e `daysSinceChange = Age::days(change, last)`. Pontos em `[start, last]`; `< 3` pontos ou `Age::days(primeiro, last) < 14` → `aguardando`. Taxa: kg → `-b*7`; pct → `(-b*28) / (a + b*0) * 100` com `a` = valor ajustado no 1º ponto da janela; z → `-b*28`. Compara `rate < limiar` → `estabilizou`. Arredondar `rate` para 2 casas (kg, pct) ou 3 (z) só no texto, não no retorno.
- [ ] **Step 4: PASS. Commit** `feat: ritmo por dose e aviso de estabilizacao`.

### Task 4: Controlador, rotas, página e JSON

**Files:**
- Create: `app/Evolution/EvolutionController.php`, `app/Evolution/EvolutionAccess.php`, `app/Evolution/views/index.php`
- Modify: `app/routes.php` (perto da linha 158), `app/Timeline/TimelinePeriod.php` (`monthsBack` → `public static`)
- Test: `tests/Http/EvolutionPagesTest.php` (padrão de `tests/Http/WeightGoalPagesTest.php`: `get`, `post`, `loginAs`)

**Interfaces:**
- Consumes: Tasks 1–3; `PrescriptionRepository::forViewer($pid, $user, $today)` e `doses($id)`; `LabCatalogRepository::findByCode()` + `LabRepository::series($pid, $testId)`; `BloodPressure::classifyAdult/classifyChild` (como em `GrowthController::describe`, linhas ~385–395); `WeightGoalRepository::active()`.
- Produces:
  - Rotas: `GET /pacientes/{id}/evolucao` → `index`; `GET /pacientes/{id}/evolucao.json` → `data`; `POST /pacientes/{id}/evolucao/criterio` → `saveSettings` (CSRF; sucesso redireciona para `/pacientes/{id}/evolucao` com flash `Critério salvo.`; erro re-renderiza com mensagens).
  - `EvolutionAccess::canView(array $user, int $pid): bool`, `canEdit(...)`, `authorize(array $user, string $action, int $pid, Request $r): void` (403 para quem não é `medico`; senão `Gate::authorize` nas três seções).
  - JSON (`?periodo=` como na página):
```
{ "child": bool, "unit": "kg"|"z", "from": ymd|null, "to": ymd,
  "points": [[ymd, value, "equipe"|"familia"]],
  "path": null | [[ymd, v], [ymd, v]],            // meta ativa do mesmo tipo (peso↔adulto, imc_z↔criança)
  "meds": [{ "name": str, "segments": [{ "from": ymd, "to": ymd|null, "dose": str, "rate": float|null }] }],
  "plateau": { "status": str, "text": str },
  "metabolism": [{ "key": "cintura"|"pa"|<código>, "label": str, "unit": str,
                   "points": [[ymd, value, class|null]], "points2": [[ymd, value]]|null }] }   // points2 = diastólica
```
  `class` de exame = valor de `LabClassifier` (`normal`, `limitrofe_*`, `alterado_*`); da PA: `normal` (Normal, PA ótima, PA normal), `limitrofe` (PA elevada, Pré-hipertensão), `alterado` (HA …), `null` (Não se aplica / Altura necessária). Séries sem ponto no período são omitidas. Pontos e faixas cortados ao período; o aviso usa todos os pontos (não só o período).
  - Página: título "Evolução — <nome>", seletor de período (links `?periodo=`), parágrafo do aviso (`plateau.text`, classe `selo-alerta` quando `estabilizou`), `<div id="evolution-chart" data-url="…/evolucao.json?periodo=…">` com `<canvas>` e `[data-role=msg]`, tabela `.tabela` "Ritmo por dose" (remédio, dose, de, até, ritmo), `<details id="evolucao-metabolismo"><summary>Metabolismo</summary><div id="evolution-metabolism"></div></details>`, e formulário do critério (só `canEdit`): rádio `modo` kg/pct (só adulto), campos `kg`, `pct`, `z` com `inputmode="decimal"`, explicando "Padrão: 0,2 kg/semana · 1% em 4 semanas · 0,05 de escore-z em 4 semanas". Scripts: `asset('/assets/vendor/chart.umd.min.js')` e `asset('/assets/js/evolution-chart.js')`.
  - Mudanças de dose para o aviso: `effective_date` de todas as doses + `start_date` de cada prescrição, descartando as posteriores ao `end_date` da própria prescrição.

- [ ] **Step 1: Testes que falham**
  - `test_doctor_sees_page_and_json`: adulto com 5 pesagens semanais e tirzepatida (2 doses) → 200; HTML contém `id="evolution-chart"`, `Ritmo por dose`, `Tirzepatida`, `evolution-chart.js?v=`; JSON tem `unit` `kg`, 2 segmentos, `plateau.status` em `['funcionando','estabilizou']`; auditoria com `section` `evolucao`.
  - `test_child_json_uses_z`.
  - `test_empty_patient_renders`: sem pesagem e sem remédio → 200, texto `Sem pesagens para avaliar.`; JSON `points` `[]`, `meds` `[]` (Review Focus 1).
  - `test_metabolism_only_series_with_data`: cintura + triglicerídeos lançados → `metabolism` com chaves `['cintura','triglicerides']`, na ordem global.
  - `test_period_filter`: `?periodo=3m` corta pontos com mais de 3 meses; inválido cai em `6m`.
  - `test_others_forbidden`: nutricionista, secretária e responsável → 403 na página, no JSON e no POST.
  - `test_save_settings`: POST válido → redirect + flash; POST com `kg=9` → 200 com a mensagem de erro e nada salvo.
- [ ] **Step 2: Rodar** `--filter EvolutionPagesTest` → FAIL.
- [ ] **Step 3: Implementar** (controlador fino: monta dados com Tasks 1–3, página via `View::render('Evolution/views/index.php', …, 'Layout/views/layout.php')`).
- [ ] **Step 4: PASS e suíte inteira verde. Commit** `feat: pagina e json do painel de evolucao`.

### Task 5: Gráfico, estilos, links e README

**Files:**
- Create: `public/assets/js/evolution-chart.js`
- Modify: `public/assets/css/app.css`, `app/Patients/views/show.php` (cartões Tratamento e Meta de peso via `_card.php`), `app/WeightGoals/views/_card.php`, `app/Timeline/views/index.php`, `app/Patients/PatientController.php` (ou onde `show` monta `$canX`, passar `$canEvolucao = EvolutionAccess::canView(...)`), `README.md`
- Test: `tests/js/evolution-chart.test.js`, acréscimos em `tests/Http/EvolutionPagesTest.php`

**Interfaces:**
- Consumes: JSON do Task 4.
- Produces: `evolution-chart.js` no padrão IIFE de `dictation.js`, exportando para node `{ bandRows }`: `bandRows(meds, fromDay, toDay)` → por remédio, lista de `{x0, x1, label}` em dias (UTC, como `x()` de `weight-goal-chart.js`), `to` nulo vira `toDay`, trechos recortados a `[fromDay, toDay]`, trechos totalmente fora descartados.

- [ ] **Step 1: Testes que falham**
  - JS: `bandRows` recorta trecho que começa antes do período; trecho vigente (`to` null) vai até `toDay`; trecho fora some.
  - PHP: `test_links_for_doctor_only` — página do paciente da médica contém `href=".../evolucao"`; da nutricionista não.
- [ ] **Step 2: Rodar** `node --test "tests/js/*.test.js"` e `--filter EvolutionPagesTest` → FAIL.
- [ ] **Step 3: Implementar.**
  - Gráfico de peso (Chart.js scatter com `showLine`, eixo x em dias com rótulo dd/mm/aaaa, como `weight-goal-chart.js`): pontos `familia` com `pointStyle: 'circle'` vazio (`backgroundColor: 'transparent'`), `equipe` cheios; caminho da meta tracejado quando `path`.
  - Faixas de dose: um segundo `<canvas>` abaixo, gráfico de barras horizontais flutuantes (`indexAxis: 'y'`, dados `[x0, x1]`), uma linha por remédio, rótulo da dose em cada barra, **mesmos `min`/`max` do eixo x** do gráfico de peso.
  - Metabolismo: ao abrir o `<details>` pela 1ª vez, um gráfico pequeno por série com os mesmos `min`/`max`; cor do ponto por `class` (normal = salvia, limitrofe = âmbar, alterado = `--alerta`); PA com duas linhas (máx/mín).
  - CSS: `.evolucao-graf { position: relative; height: 320px }`, faixas `height` proporcional ao nº de remédios, `.evolucao-mini { height: 140px }`.
  - Links: botão `Evolução` (classe `btn btn-quiet`) nos cartões Tratamento e Meta de peso e na Linha do tempo, só com `canView`.
  - README: seção curta "Painel de evolução" e item de deploy: migração `015_evolution_settings.sql` (`tools/migrate.php` ou phpMyAdmin), copiar `public/`, refazer a linha `$APP_ROOT`.
- [ ] **Step 4: Rodar JS e PHPUnit inteiros → verdes. Commit** `feat: graficos do painel de evolucao e links`.
