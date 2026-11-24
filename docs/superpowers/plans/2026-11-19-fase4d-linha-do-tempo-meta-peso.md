# Linha do tempo e meta de peso — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Meta de peso por paciente (kg ou escore-z do IMC) com situação e tendência calculadas das medições e gráfico, e uma linha do tempo "desde a última visita" para a consulta.

**Architecture:** Módulo novo `app/WeightGoals/` (tabela `weight_goals`, regras de tipo por idade, cálculo puro da situação/tendência, páginas e cartão) e módulo `app/Timeline/` que só lê as tabelas existentes, respeitando o `Gate` e as regras de cada módulo. Gráfico com o Chart.js já versionado, script externo.

**Tech Stack:** PHP 8.1+ (strict_types), MVC próprio, PHPUnit 11 (`tools/phpunit.phar`), MariaDB local / MySQL 5.7 (Percona) na hospedagem, Chart.js local.

**Spec:** `docs/superpowers/specs/2026-11-19-linha-do-tempo-meta-peso-design.md`

## Global Constraints
- Rodar testes: `source tools/env.sh; start_mariadb; php tools/phpunit.phar` (saída longa → arquivo, ler o fim).
- Migração `database/migrations/014_weight_goals.sql` sem sintaxe só do MariaDB (`MigrationPortabilityTest`): coluna gerada `STORED`, nunca `PERSISTENT`.
- Corte de idade: adulto = `Age::months(birth, data) >= 229` (`GrowthController::ADULT_MONTHS`); adolescente com escolha = `>= 156` e `< 229`; abaixo de 156 só `imc_z`; adulto só `peso`. Idade medida na **data de criação** da meta.
- Meta `imc_z`: prazo com `Age::months(birth, target_date) < 229`. Meta `peso`: sem esse limite.
- Validação: prazo > hoje e ≤ hoje + 5 anos; alvo ≠ partida; `peso` 20–300 kg; `imc_z` −3 a +5. Números aceitam vírgula (`App\Support\Num`).
- Tolerância "no caminho": 1,0 kg (`peso`), 0,2 (`imc_z`); diferença exatamente igual à tolerância = no caminho. Comparar após `round(..., 3)`.
- Tendência só com **≥ 5** pontos que contam (incluindo o de partida).
- Só equipe vê meta e linha do tempo: papel `medico` ou `nutricionista` **e** o `Gate` da seção. Secretária, família e criança nunca (nem portal, nem área da criança).
- Rótulos de situação (exatos): `no_caminho` "No caminho", `a_frente` "À frente do caminho", `atras` "Atrás do caminho", `atingida` "Meta atingida", `prazo_vencido` "Prazo vencido", `aguardando` "Aguardando a primeira medição".
- Frases da tendência (exatas): "Nesse ritmo, chega ao alvo em {mm/aaaa}, antes do prazo." / "… em {mm/aaaa}, depois do prazo." / "Nesse ritmo, não se aproxima do alvo." / "Nesse ritmo, levaria mais de 5 anos." / "A tendência aparece a partir da 5ª medição (faltam {N})."
- CSP: nenhum `<script>`/`<style>` inline (ViewsCspTest); JS em `public/assets/js/`; dados por `data-*` e endpoint JSON.
- Saída sempre com `e()`; datas dd/mm/aaaa (`fmt_date`), números com vírgula (`fmt_num`).
- Auditoria: criar/trocar/encerrar meta via `AuditLog::record`; abrir a página da meta (`['section' => 'meta_peso']`) e a linha do tempo (`['section' => 'linha_do_tempo']`) registram `view`.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nunca commitar `o que fazer.txt` nem `COLAR/` — **nunca usar `git commit -a`**; `git add` só dos arquivos da tarefa.

## Review Focus
1. Medição corrigida ou apagada depois de criada a meta → some dos pontos; a partida gravada não muda (teste na Task 2).
2. Duas medições no mesmo dia → vale a de maior `id` (Task 2).
3. Criança com medição sem altura → ignorada na meta `imc_z`, contada na meta `peso` do adolescente (Task 2).
4. Nutricionista sem liberação, ou liberação revogada → 403 na meta, no JSON e na linha do tempo; nutricionista liberada nunca vê eventos de remédio, exame, evolução ou meta de hábito na linha do tempo (Tasks 3 e 4).
5. Família/responsável/paciente → nada de meta de peso no portal nem na ficha; rotas novas dão 403 (Task 3, smoke).

---

### Task 1: Tabela, regras de tipo e repositório da meta

**Files:**
- Create: `database/migrations/014_weight_goals.sql`, `app/WeightGoals/WeightGoalKind.php`, `app/WeightGoals/WeightGoalRepository.php`, `app/WeightGoals/InvalidWeightGoalException.php`
- Modify: `app/Core/Container.php` (`weightGoals()`), `app/Growth/GrowthCalculator.php` (`bmiForZ`)
- Test: `tests/Unit/WeightGoalKindTest.php`, `tests/Integration/WeightGoalRepositoryTest.php`, `tests/Integration/GrowthCalculatorTest.php` (+1)

**Interfaces — Produces:**
- Tabela `weight_goals` como na spec §4.2, mais `active_flag TINYINT AS (IF(ended_at IS NULL, 1, NULL)) STORED` e `UNIQUE KEY uq_weight_goals_active (patient_id, active_flag)`; FKs para `patients` e `users`.
- `WeightGoalKind::allowed(string $birth, string $onYmd): list<string>` → `['imc_z']` | `['imc_z','peso']` | `['peso']`.
- `WeightGoalKind::zDeadlineLimit(string $birth): string` — último dia com `Age::months < 229`.
- `WeightGoalRepository::create(int $patientId, array $d, array $patient, string $today, int $uid, Request $r): int` — `$d` = `kind, start_on, start_value, target_value, target_date, notes`; valida (Global Constraints) e lança `InvalidWeightGoalException(list<string>)`; numa `Database::transaction` encerra a ativa com `end_reason='substituida'` e insere a nova. Audita as duas.
- `end(int $goalId, int $uid, Request $r): void` → `end_reason='encerrada'`; meta já encerrada → exceção.
- `active(int $patientId): ?array`, `find(int $id): ?array`, `history(int $patientId): list<array>` (todas, mais recente primeiro).
- `GrowthCalculator::bmiForZ(string $sex, string $birth, string $onYmd, float $z): ?float` — IMC que dá esse z (`Lms::valueAt` sobre o LMS `bfa`, mesma escolha de fonte de `indicators`); `null` fora da faixa da OMS.

- [ ] **Step 1: Testes que falham**
  - `WeightGoalKindTest`: nascido `2016-01-10` em `2026-11-19` → `['imc_z']`; nascido `2013-03-01` → `['imc_z','peso']`; nascido `2000-01-01` → `['peso']`; `zDeadlineLimit('2010-01-15')` devolve uma data `d` com `Age::months('2010-01-15', d) === 228` e `Age::months(..., d + 1 dia) === 229`.
  - `WeightGoalRepositoryTest::test_create_then_replace_keeps_history`: duas criações → 2 linhas, a 1ª com `end_reason='substituida'` e `ended_at` preenchido; `active()` = a 2ª.
  - `test_end`: `end()` → `active()` null, `end_reason='encerrada'`; `end()` de novo lança exceção.
  - `test_rejects_kind_not_allowed_for_age`: `imc_z` em adulto e `peso` em criança de 10 anos → exceção.
  - `test_rejects_invalid_values`: prazo hoje; prazo > 5 anos; alvo = partida; peso 15; z 6 → uma mensagem cada.
  - `test_rejects_z_deadline_after_limit`: criança de 18 anos e 10 meses, `imc_z` com prazo 6 meses depois → exceção; mesma criança com `peso` → aceita.
  - `test_db_allows_only_one_active`: dois `INSERT` diretos ativos para o mesmo paciente → o 2º falha (PDOException).
  - `GrowthCalculatorTest::test_bmi_for_z_round_trips`: menino nascido `2016-05-10`, em `2026-05-10`, `bmiForZ(..., 1.0)` = IMC cujo `indicators()` devolve `bfa.z` ≈ 1,00 (±0,01).
- [ ] **Step 2:** rodar e ver falhar.
- [ ] **Step 3:** implementar migração, classes e `Container::weightGoals()`.
- [ ] **Step 4:** suíte inteira verde (inclui `MigrationPortabilityTest`).
- [ ] **Step 5:** commit `feat: tabela e regras da meta de peso`.

### Task 2: Pontos, situação e tendência

**Files:**
- Create: `app/WeightGoals/WeightGoalPoints.php`, `app/WeightGoals/WeightGoalProgress.php`
- Test: `tests/Unit/WeightGoalProgressTest.php`, `tests/Integration/WeightGoalPointsTest.php`

**Interfaces — Consumes:** `MeasurementRepository::current()`, `GrowthCalculator::indicators()`, `AdultAnthropometry::bmi()`.
**Produces:**
- Ponto: `array{on:string, value:float, bmi:?float, z:?float, weight:float, height:?float, source:string, id:int}` (`value` = kg na meta `peso`, z do IMC na `imc_z`).
- `WeightGoalPoints::build(string $kind, array $patient, list<array> $measurements, ?string $fromYmd): list<point>` — só medidas vigentes com `measured_on >= $fromYmd` (null = todas), ordem por data; `peso` exige peso; `imc_z` exige peso + altura **e** z calculável; mesmo dia → maior `id`.
- `WeightGoalPoints::starting(string $kind, array $patient, list<array> $measurements): ?point` — o último ponto de `build(..., null)`.
- `WeightGoalProgress::compute(array $goal, list<point> $points, string $today): array{status:string, last:?array, expected:?float, diff:?float, reachedOn:?string, trend:?array{a:float,b:float,kind:string,date:?string,onTime:?bool}, trendMissing:int}`
  - `expected(d)` = reta de (`start_on`,`start_value`) a (`target_date`,`target_value`), em dias; depois do prazo = alvo. Direção `dir` = sinal de alvo − partida.
  - Ordem de decisão sobre o **último** ponto: atingido (`dir*(v − alvo) >= 0`) → `atingida`; hoje > prazo → `prazo_vencido`; nenhum ponto com `on > start_on` → `aguardando`; `|v − e| <= tol` → `no_caminho`; `dir*(v − e) > 0` → `a_frente`; senão `atras`. `reachedOn` = data do 1º ponto que atingiu.
  - Tendência (≥ 5 pontos): mínimos quadrados `value = a + b·x`, x = dias desde `start_on`. `dir*b <= 0` → `nao_aproxima`; cruzamento além de hoje + 5 anos → `mais_de_5_anos`; senão `chega` com `date` e `onTime = date <= target_date`. Com status `atingida`, `trend.kind` continua calculado, mas a tela não mostra a frase. `trendMissing = max(0, 5 − n)`.
- `WeightGoalProgress::LABELS` e `::trendText(array $progress): ?string` com as frases exatas da Global Constraints.

- [ ] **Step 1: Testes que falham** (meta base: `peso`, partida `2026-01-01` 100,0, alvo 90,0, prazo `2026-04-11` = dia 100; hoje `2026-02-20`)
  - ponto dia 50 com 95,8 → `no_caminho`, `expected` 95,0; 96,0 → `no_caminho` (borda); 96,2 → `atras`; 93,9 → `a_frente`.
  - só o ponto de partida → `aguardando`; hoje `2026-05-01`, último 92 → `prazo_vencido`; último 89,5 → `atingida`, `reachedOn` = data dele.
  - meta de ganho (partida 50, alvo 55): 52,0 no dia 50 com esperado 52,5 → `no_caminho`; 51,0 → `atras`.
  - tendência: pontos dias 0,10,20,30,40 = 100,99,98,97,96 → `b` −0,1, `kind` `chega`, `date` `2026-04-11`, `onTime` true, texto "Nesse ritmo, chega ao alvo em 04/2026, antes do prazo."; 4 pontos → `trend` null, `trendMissing` 1, texto "(faltam 1)"; valores iguais → `nao_aproxima`; inclinação −0,001 → `mais_de_5_anos`.
  - `imc_z`: tolerância 0,2 (z 1,85 com esperado 2,0 → `no_caminho`; 2,25 → `atras` numa meta de 2,5 → 1,5).
  - `WeightGoalPointsTest` (Integration, medidas criadas pelo `MeasurementRepository`): medida corrigida conta só a versão nova; medida apagada não conta; duas no mesmo dia → a de maior id; criança com medida sem altura → fora da `imc_z`, dentro da `peso`; `value` da `imc_z` = `indicators()['bfa']['z']`; medida da família tem `source` `familia`.
- [ ] **Step 2:** rodar e ver falhar.
- [ ] **Step 3:** implementar.
- [ ] **Step 4:** suíte inteira verde.
- [ ] **Step 5:** commit `feat: situacao e tendencia da meta de peso`.

### Task 3: Telas da meta de peso

**Files:**
- Create: `app/WeightGoals/WeightGoalAccess.php`, `app/WeightGoals/WeightGoalController.php`, `app/WeightGoals/views/index.php`, `app/WeightGoals/views/_card.php`, `public/assets/js/weight-goal-chart.js`
- Modify: `app/routes.php`, `app/Patients/PatientController.php` (`weightGoalCard`), `app/Patients/views/show.php` (incluir `_card.php`), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/WeightGoalPagesTest.php`

**Interfaces — Consumes:** Tasks 1–2.
**Produces:**
- `WeightGoalAccess::canView(array $user, int $patientId): bool` (papel `medico|nutricionista` + `Gate view ANTROPOMETRIA`) e `canEdit` (mesmo + `edit ANTROPOMETRIA`). Único ponto da regra.
- Rotas (`$authMw`): `GET /pacientes/{id:\d+}/meta-peso` (`index`), `GET /pacientes/{id:\d+}/meta-peso.json` (`data`), `POST /pacientes/{id:\d+}/meta-peso` (`store`, define ou troca), `POST /pacientes/{id:\d+}/meta-peso/encerrar` (`end`). Sem acesso → 403.
- `WeightGoalController::card(array $user, int $patientId, string $today): ?array{goal:?array, progress:?array, canEdit:bool, equivalentKg:?float}` — null se não pode ver.
- JSON: `{kind, unit:"kg"|"z", points:[[ymd, value, source]], path:[[start_on,start_value],[target_date,target_value]], target, trend:null|[[ymd,v],[ymd,v]] (do 1º ponto ao prazo), status, statusLabel}`.
- Formulário: `tipo` (rádio, só se `allowed()` tiver 2 opções), `alvo`, `prazo`, `notas`. A partida vem de `WeightGoalPoints::starting()`; sem ponto → mensagem "Registre uma medição (peso{ e altura}) antes de definir a meta." e formulário oculto.
- Página: cartão da meta (alvo, prazo, último valor, IMC — e z do IMC na meta `peso` de adolescente —, selo de situação, frase da tendência, "≈ X kg com a altura atual" na `imc_z` = `bmiForZ(alvo, hoje) × altura²` da última medida com altura), `<div id="weight-goal-chart" data-url>` + `<canvas>` + texto alternativo com a situação, tabela dos pontos (data, valor, IMC/z, diferença do esperado, selo "família"), botão "Encerrar meta" (form POST, `confirm-submit.js`), histórico.
- Chart (`weight-goal-chart.js`): pontos (linha sólida), caminho tracejado, linha horizontal do alvo, tendência em outra cor só quando `trend` não é null; eixo y "Peso (kg)" ou "Escore-z do IMC".

- [ ] **Step 1: Testes que falham**
  - médica define meta de adulto (`alvo` "82,5", prazo +6 meses) → 302, linha em `weight_goals` com `start_value` = peso da última medida; página mostra "82,5" e o selo.
  - adolescente de 15 anos: a página mostra o rádio de tipo; criança de 10 anos não mostra; POST `tipo=peso` para a de 10 anos → erro na página, nada gravado.
  - troca de meta → a anterior aparece no histórico como "substituída"; encerrar → cartão "Nenhuma meta de peso".
  - sem medição → mensagem e sem formulário.
  - JSON: `points` e `path` com os valores esperados; `trend` null com 4 pontos e preenchido com 5.
  - nutricionista liberada: 200 e pode definir; sem liberação: 403 em página, JSON e POST; secretária 403; responsável 403; portal do responsável (`/portal/p/{id}` e `/portal/p/{id}/crescimento`) não contém "Meta de peso".
  - ficha: cartão aparece para médica e nutricionista, não para secretária.
  - auditoria: abrir a página grava `view` com `section` `meta_peso`.
  - `SmokeAllRoutesTest`: `'/pacientes/{id}/meta-peso' => [200, 200, 403, 403, 403, 302]` e `'/pacientes/{id}/meta-peso.json' => [200, 200, 403, 403, 403, 302]`.
- [ ] **Step 2:** rodar e ver falhar.
- [ ] **Step 3:** implementar.
- [ ] **Step 4:** suíte inteira verde (inclui ViewsCspTest).
- [ ] **Step 5:** commit `feat: telas da meta de peso`.

### Task 4: Linha do tempo + instalação

**Files:**
- Create: `app/Timeline/TimelinePeriod.php`, `app/Timeline/TimelineBuilder.php`, `app/Timeline/TimelineController.php`, `app/Timeline/views/index.php`
- Modify: `app/routes.php`, `app/Patients/views/show.php` (botão "Linha do tempo" no topo, só médica/nutricionista), `tests/Http/SmokeAllRoutesTest.php`, `README.md` (seção "Linha do tempo e meta de peso" + atualização da 4d: migração 014, copiar `public/` e refazer a linha `$APP_ROOT` do `index.php`)
- Test: `tests/Integration/TimelinePeriodTest.php`, `tests/Integration/TimelineBuilderTest.php`, `tests/Http/TimelinePagesTest.php`

**Interfaces — Consumes:** repositórios existentes (`appointments`, `clinicalEntries()->evolutions`, `labs()->collections`, `prescriptions`, `adverseEvents`, `measurements()->current`, `mealPlans`, `goals`), Tasks 1–2.
**Produces:**
- `TimelinePeriod::resolve(int $patientId, string $choice, string $today): array{from:?string, ref:?string, label:string, days:?int}` — `choice` ∈ `visita` (padrão, inválido cai nele) | `3m` | `12m` | `tudo`. `visita`: data de `starts_at` da última consulta `realizada` com `starts_at < agora` (qualquer profissional); senão `encounter_date` da última evolução; senão `tudo` com rótulo "Sem visita anterior — mostrando tudo".
- `TimelineBuilder::events(array $user, int $patientId, ?string $from, string $today, int $limit = 200): array{events: list<array{date:string, type:string, label:string, text:string, link:string, badge:?string}>, truncated:bool}` — `date >= $from`. Tipos e rótulos: `meta_peso` "Meta de peso", `medicao` "Medição", `exame` "Exame", `remedio` "Remédio", `evento_adverso` "Evento adverso", `plano` "Plano alimentar", `meta` "Meta", `evolucao` "Evolução", `consulta` "Consulta"; ordem: data desc, e no mesmo dia essa ordem de tipos (consulta por último).
  - Cada fonte só entra se a seção de origem passa (`Gate` + regra do módulo, spec §5.3): consultas `realizada|faltou` (nutricionista: só as dela, como `AgendaCards::forStaff`); evolução, exame, remédio, evento adverso e meta de hábito só com `Clinician::is` + seção; plano `publicado|substituido|encerrado` pela data de `published_at`; medição com peso, IMC (adulto) ou z do IMC (criança), PA, "−3,1 kg" de diferença para a medida anterior com peso, selo `família`; meta de peso: definida (`created_at`), substituída/encerrada (`ended_at`), atingida (`reachedOn`), só se `WeightGoalAccess::canView`.
  - Remédio: início (`start_date`, "Início: {nome} {dose}"), troca (cada dose depois da 1ª, "{nome}: {dose anterior} → {nova}"), fim (`end_date <= hoje`, "{nome} encerrado"); prescrições apagadas não entram.
- Rota `GET /pacientes/{id:\d+}/linha-do-tempo?periodo=` (`index`), 403 fora de médica/nutricionista com `Gate view CADASTRO_BASICO`; abas de período como links; cabeçalho "Desde a última visita ({dd/mm/aaaa}) — {N} dias"; cartão da meta de peso no topo (reusa `_card.php`); vazio → "Nada registrado neste período."; `truncated` → "Mostrando os 200 eventos mais recentes."

- [ ] **Step 1: Testes que falham**
  - `TimelinePeriodTest`: consulta realizada 10 dias atrás + uma cancelada ontem → `from` = a realizada; sem consulta e com evolução → data da evolução; nada → `from` null; `3m` → hoje − 3 meses; `xyz` → igual a `visita`.
  - `TimelineBuilderTest` (paciente com uma fonte de cada tipo no período e uma fora dele): médica vê os 9 tipos e nada antes de `from`; nutricionista liberada vê só `medicao`, `plano`, `consulta` (só as dela) e `meta_peso`; dose trocada gera "500 mg → 850 mg"; prescrição apagada não aparece; medida apagada não aparece; ordem no mesmo dia com consulta por último; `limit 3` → 3 eventos e `truncated` true.
  - `TimelinePagesTest`: médica 200 com o cabeçalho do período e link de cada evento; `?periodo=tudo` mostra o evento antigo; secretária 403; nutricionista sem liberação 403; auditoria `view` `linha_do_tempo`; ficha mostra o botão para médica e não para secretária.
  - `SmokeAllRoutesTest`: `'/pacientes/{id}/linha-do-tempo' => [200, 200, 403, 403, 403, 302]`.
- [ ] **Step 2:** rodar e ver falhar.
- [ ] **Step 3:** implementar; escrever o README.
- [ ] **Step 4:** suíte inteira verde; `php tools/build_release.php` e conferir `unzip -l dist/rubia-release-*.zip | grep -E "014_weight_goals|weight-goal-chart"`.
- [ ] **Step 5:** commit `feat: linha do tempo do paciente`.
