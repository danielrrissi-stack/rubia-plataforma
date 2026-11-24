# Metas e área da criança (Fase 3a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Metas por paciente com marcação "fiz" nos últimos 7 dias, estrelas e selos calculados, área lúdica no portal (pais, criança com login próprio opcional, adulto), sigilo do adolescente, painel da equipe e 40 cartões educativos em rascunho.

**Architecture:** Novo módulo `app/Goals/` no padrão de `app/Labs/` (repositórios com `Database` + `AuditLog`, controllers finos, views PHP). Cálculo de semanas, janela de 7 dias, estrelas e selos numa classe pura (`GoalCalc`) que recebe metas e marcações já filtradas para quem está vendo; a filtragem por visibilidade (sigilo, nutricionista) fica num único método do repositório. Conta de criança = usuário papel `crianca` com `username`, vínculo próprio no paciente e um guarda global que a prende à área dela.

**Tech Stack:** PHP 8.1+ puro, MariaDB (InnoDB, utf8mb4), PHPUnit 11 (`tools/phpunit.phar`), JS sem framework em `public/assets/js/` (CSP estrita).

**Spec:** `docs/superpowers/specs/2026-11-17-metas-area-crianca-design.md` (e a geral `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`). §8a do spec traz decisões que valem como requisito.

## Global Constraints

- PHP 8.1+, `declare(strict_types=1);`; sem Composer/Node em produção; nenhuma biblioteca nova.
- CSP: `default-src 'self'; img-src 'self' data:` — nenhum `<script>` inline, `style=""`, `onclick=` (cobrado por `tests/Unit/ViewsCspTest.php`). Toda saída via `e()`. Textos em pt-BR; datas `fmt_date`.
- "Hoje" = `date('Y-m-d')` (fuso `America/Sao_Paulo`, já definido em `app/bootstrap.php`); toda lógica de data recebe `$today` por parâmetro para ser testável.
- Migração única: `database/migrations/010_goals.sql`. Seeds: `database/seeds/goal_1_templates.sql` e `database/seeds/goal_2_edu_cards.sql`, upsert **no-op** (`ON DUPLICATE KEY UPDATE id = id`), ids fixos 1..999, `AUTO_INCREMENT = 1000`.
- Frequência: `times_per_week` 1..7 (7 = todo dia). Semana = segunda a domingo. Janela de marcação = `[today - 6 dias, today]` ∩ período da meta.
- Categorias: `alimentacao`, `hidratacao`, `sono`, `atividade`, `telas`, `rotina`. Nutricionais = `alimentacao`, `hidratacao`.
- Faixas dos cartões: `3-6`, `7-10`, `11-14`, `15-19` (anos completos na data de hoje); fora de 3..19 → nenhum cartão.
- Linguagem: nunca falar de peso, aparência, "dieta", "gordo/magro", comparação com outras crianças; sem marcas comerciais.
- Permissão: seção `Policy::METAS`. Rotas da equipe: médico tudo; nutricionista só metas nutricionais de pacientes liberados (PatientScope). Família (`responsavel`, `paciente`) e `crianca`: só o portal. Papel `crianca` preso à própria área por guarda global (Task 3).
- Auditoria (`Container::audit()->record(uid, action, table, rowId, patientId, before, after, $r)`): `create`/`update`/`end`/`delete` em `patient_goals`; `check`/`uncheck` em `goal_checks`; `create`/`reset_password`/`deactivate` da conta de criança em `users`; `update` de sigilo em `patients`; `update`/`publish`/`unpublish` em `edu_cards`; leitura do painel da equipe `('view', 'patient_goals', null, patientId, null, ['section' => 'metas'])`.
- Testes: `source tools/env.sh; start_mariadb` e `php tools/phpunit.phar`. Toda rota GET nova entra em `tests/Http/SmokeAllRoutesTest.php::EXPECTED` na mesma tarefa (papéis `M, N, S, R, P`; se o teste ganhar um papel `C` para criança na Task 3, todas as linhas ganham a 6ª coluna).
- Commits em português (`feat:`, `fix:`, `docs:`, `test:`), terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Criança logada digitando URLs** (`/pacientes`, `/portal/p/{id}/crescimento`, `/portal/p/{id}/exames`, `/portal/p/{outroId}/metas`, `/catalogo-exames`, `/conta`): nunca vê nada fora da própria área — redirect para a área dela (GET) ou 403 (POST) — teste na Task 3 (`test_child_is_confined_to_own_goals_area`) e na Task 5.
2. **Responsável vendo rastro de meta sigilosa** (estrelas, selo "semana completa", contagem "2 de 3", painel): números contam só o que ele vê — teste na Task 5 (`test_guardian_counts_exclude_secret_goal`).
3. **Marcação na virada de dia/semana e fora da janela** (domingo→segunda, D-6 vs D-7, amanhã, antes do início da meta, depois do fim): aceitar só a janela; selos de semana só para semanas terminadas — testes na Task 1 e Task 5.
4. **Duplo clique / duas abas marcando o mesmo dia**: uma única marcação, sem erro para a família — teste na Task 2 (`test_check_is_idempotent`).
5. **Login com usuário vs e-mail** (maiúsculas, espaços, usuário igual a parte de um e-mail, conta de criança desativada, senha provisória): entra só com credencial válida e obriga troca da senha provisória — testes na Task 3.

---

### Task 1: Cálculo de semanas, janela, estrelas e selos (classe pura)

**Files:**
- Create: `app/Goals/GoalCalc.php`
- Test: `tests/Unit/GoalCalcTest.php`

**Interfaces:**
- Produces (`App\Goals\GoalCalc`, estática, `final`):
  - Meta (array) = `['id' => int, 'times_per_week' => int, 'start_date' => 'Y-m-d', 'end_date' => ?'Y-m-d']`; marcações = `list<array{goal_id:int, day:'Y-m-d'}>`.
  - `weekStart(string $ymd): string` (segunda-feira da semana).
  - `canMark(array $goal, string $day, string $today): bool` — `today-6 <= day <= today` e `start_date <= day <= (end_date ?? ∞)`.
  - `activeDaysInWeek(array $goal, string $weekStart): int` — dias da semana (seg–dom) dentro do período da meta.
  - `weekStatus(array $goal, list<string> $checkDays, string $weekStart): array{done:int, target:int, applicable:bool, complete:bool}` — `target = min(times_per_week, activeDaysInWeek)`; `applicable = target > 0`; `done` = marcações dessa meta nessa semana dentro do período; `complete = applicable && done >= target`.
  - `stars(list<array> $goals, list<array> $checks): int` — marcações cujo dia está dentro do período da sua meta (metas ausentes da lista não contam).
  - `badges(list<array> $goals, list<array> $checks, string $today): list<string>` — chaves, nesta ordem: `primeira_estrela` (stars ≥ 1), `estrelas_10`, `estrelas_50`, `estrelas_100`, `estrelas_250`, `semana_completa` (alguma semana **terminada** — `weekStart + 6 < today` — com ≥ 1 meta aplicável e todas as aplicáveis completas), `sequencia_3`, `sequencia_8` (semanas terminadas consecutivas completas; semana sem meta aplicável quebra a sequência).
  - `BADGE_LABELS` = `['primeira_estrela' => 'Primeira estrela', 'estrelas_10' => '10 estrelas', 'estrelas_50' => '50 estrelas', 'estrelas_100' => '100 estrelas', 'estrelas_250' => '250 estrelas', 'semana_completa' => 'Semana completa', 'sequencia_3' => '3 semanas seguidas', 'sequencia_8' => '8 semanas seguidas']`.

- [ ] **Step 1: Escrever os testes**

```php
public function test_week_start_and_window(): void
{
    $this->assertSame('2026-11-16', GoalCalc::weekStart('2026-11-22')); // domingo → segunda anterior
    $this->assertSame('2026-11-23', GoalCalc::weekStart('2026-11-23'));
    $g = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-11-01', 'end_date' => null];
    $this->assertTrue(GoalCalc::canMark($g, '2026-11-11', '2026-11-17'));   // D-6
    $this->assertFalse(GoalCalc::canMark($g, '2026-11-10', '2026-11-17'));  // D-7
    $this->assertFalse(GoalCalc::canMark($g, '2026-11-18', '2026-11-17'));  // amanhã
    $late = ['id' => 2, 'times_per_week' => 7, 'start_date' => '2026-11-15', 'end_date' => '2026-11-16'];
    $this->assertFalse(GoalCalc::canMark($late, '2026-11-14', '2026-11-17'));
    $this->assertFalse(GoalCalc::canMark($late, '2026-11-17', '2026-11-17'));
}
public function test_week_status_mid_week_start_and_n_per_week(): void
{
    $daily = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-11-19', 'end_date' => null]; // quinta
    $s = GoalCalc::weekStatus($daily, ['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'], '2026-11-16');
    $this->assertSame(['done' => 4, 'target' => 4, 'applicable' => true, 'complete' => true], $s);
    $three = ['id' => 2, 'times_per_week' => 3, 'start_date' => '2026-11-01', 'end_date' => null];
    $this->assertFalse(GoalCalc::weekStatus($three, ['2026-11-16', '2026-11-18'], '2026-11-16')['complete']);
    $this->assertTrue(GoalCalc::weekStatus($three, ['2026-11-16', '2026-11-18', '2026-11-22'], '2026-11-16')['complete']);
}
public function test_stars_ignore_checks_outside_period(): void
{
    $g = ['id' => 1, 'times_per_week' => 7, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12'];
    $checks = [['goal_id' => 1, 'day' => '2026-11-10'], ['goal_id' => 1, 'day' => '2026-11-13'], ['goal_id' => 9, 'day' => '2026-11-10']];
    $this->assertSame(1, GoalCalc::stars([$g], $checks));
}
public function test_badges_only_for_finished_weeks_and_streaks(): void
{
    // meta diária desde 2026-10-26 (segunda); semanas 26/10, 02/11, 09/11 completas; hoje 2026-11-17 (terça)
    // → contém primeira_estrela, estrelas_10, semana_completa, sequencia_3; não contém sequencia_8
    // mesma situação com hoje = 2026-11-15 (domingo da 3ª semana) → semana 09/11 não terminada → sem sequencia_3
}
```

  (Escreva o último teste com os dados do comentário e as assertivas exatas citadas.)
- [ ] **Step 2: Rodar e ver falhar** — `php tools/phpunit.phar tests/Unit/GoalCalcTest.php`.
- [ ] **Step 3: Implementar** `GoalCalc` com as assinaturas acima (datas com `DateTimeImmutable`).
- [ ] **Step 4: Rodar e ver passar.**
- [ ] **Step 5: Commit** — `feat: calculo de semanas, estrelas e selos das metas`.

---

### Task 2: Tabelas, biblioteca de metas e repositório (com visibilidade)

**Files:**
- Create: `database/migrations/010_goals.sql`, `database/seeds/goal_1_templates.sql`
- Create: `app/Goals/GoalRepository.php`, `app/Goals/InvalidGoalException.php`
- Modify: `app/Core/Container.php` (`goals()`), `tests/Integration/MigratorTest.php` se contar migrações
- Test: `tests/Integration/GoalRepositoryTest.php`

**Interfaces:**
- Consumes: `GoalCalc` (Task 1).
- Produces:
  - Tabelas (InnoDB utf8mb4):
    - `goal_templates(id PK AI=1000, family_text VARCHAR(160) NOT NULL, child_text VARCHAR(160) NULL, emoji VARCHAR(16) NOT NULL, category ENUM('alimentacao','hidratacao','sono','atividade','telas','rotina') NOT NULL, times_per_week TINYINT UNSIGNED NOT NULL DEFAULT 7, active TINYINT(1) NOT NULL DEFAULT 1, sort_order SMALLINT NOT NULL DEFAULT 0)`
    - `patient_goals(id PK AI, patient_id FK, template_id NULL FK goal_templates, family_text, child_text NULL, emoji, category ENUM(...), times_per_week TINYINT UNSIGNED NOT NULL, start_date DATE NOT NULL, end_date DATE NULL, secret TINYINT(1) NOT NULL DEFAULT 0, created_by FK users, created_at, updated_by NULL, updated_at NULL, deleted_at NULL, deleted_by NULL, KEY(patient_id))`
    - `goal_checks(id PK AI, goal_id FK patient_goals, day DATE NOT NULL, checked_by FK users, checked_by_role VARCHAR(20) NOT NULL, created_at, UNIQUE(goal_id, day))`
    - `edu_cards(id PK AI=1000, title VARCHAR(120) NOT NULL, body TEXT NOT NULL, emoji VARCHAR(16) NOT NULL, bands SET('3-6','7-10','11-14','15-19') NOT NULL, status ENUM('rascunho','publicado') NOT NULL DEFAULT 'rascunho', approved_by NULL FK users, approved_at NULL, updated_by NULL, updated_at NULL, sort_order SMALLINT NOT NULL DEFAULT 0)` (preenchida na Task 6)
    - `patients`: `child_user_id INT UNSIGNED NULL UNIQUE FK users`, `guardians_see_child_checks TINYINT(1) NOT NULL DEFAULT 1`
    - `users`: `username VARCHAR(40) NULL UNIQUE`, `email` passa a `NULL` (unicidade mantida), `must_change_password TINYINT(1) NOT NULL DEFAULT 0` (usados na Task 3)
  - Seed `goal_1_templates.sql`: ~20 metas ativas, ≥ 3 por categoria, com `family_text`, `child_text` lúdico em 1ª pessoa ("Bebi água nas refeições"), emoji e `times_per_week` sugerido; respeitar a regra de linguagem.
  - `App\Goals\GoalRepository`:
    - `templates(bool $activeOnly = true): list<array>`; `findTemplate(int): ?array`.
    - `create(int $patientId, array $data, int $uid, Request $r): int` — `$data = family_text, child_text, emoji, category, times_per_week, start_date, end_date, secret, template_id`. Valida: texto 3..160, categoria válida, 1 ≤ `times_per_week` ≤ 7, `end_date` ≥ `start_date`, `secret` só se o paciente tem `child_user_id` → `InvalidGoalException` (`public array $errors`, mensagens pt-BR).
    - `update(int $goalId, array $data, int $uid, Request $r): void` (mesma validação), `end(int $goalId, string $endDate, int $uid, Request $r): void`, `delete(int $goalId, int $uid, Request $r): void` (lógica).
    - `find(int $goalId): ?array` (com `patient_id`).
    - `forViewer(int $patientId, array $viewer, string $from, string $to): array{goals: list<array>, checks: list<array{goal_id:int, day:string, checked_by:int, checked_by_role:string}>}` — **única** regra de visibilidade: metas não excluídas que se sobrepõem a `[from, to]`; `medico`, `crianca`, `paciente` → todas; `nutricionista` → só categorias nutricionais; `responsavel` → sem `secret`; e se `patients.guardians_see_child_checks = 0`, sem as marcações cujo `checked_by` é o `child_user_id`. `from`/`to` limitam as marcações.
    - `allForViewer(int $patientId, array $viewer): array{goals, checks}` (desde sempre, para estrelas/selos).
    - `check(int $goalId, string $day, array $user, string $today, Request $r): void` / `uncheck(...)` — exigem `GoalCalc::canMark` e a meta visível ao usuário (`forViewer`); `check` idempotente (`INSERT IGNORE` ou equivalente); auditados.
  - `Container::goals(): GoalRepository`.

- [ ] **Step 1: Testes** (`tests/Integration/GoalRepositoryTest.php`): `test_create_validates_and_audits` (texto vazio, categoria inválida, `times_per_week` 0 e 8, fim antes do início, `secret` sem conta de criança → erros; válido → linha + audit `create`), `test_check_is_idempotent` (dois `check` no mesmo dia → 1 linha, sem exceção), `test_check_outside_window_or_period_rejected`, `test_for_viewer_visibility` (nutricionista só alimentação/hidratação; responsável sem secreta; com `guardians_see_child_checks = 0` o responsável não recebe marcações da conta de criança mas recebe as próprias), `test_guardian_cannot_check_secret_goal`, `test_seed_templates_cover_all_categories`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** migração, seed, repositório, exceção, accessor.
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: tabelas e repositorio de metas com visibilidade`.

---

### Task 3: Conta da criança (usuário, guarda global, criação pela médica, 18 anos)

**Files:**
- Modify: `app/Auth/AuthService.php`, `app/Auth/AuthController.php`, `app/Auth/views/login.php`, `app/Users/UserRepository.php`, `app/middleware.php`, `app/routes.php`, `app/Access/PatientScope.php`, `app/Access/Policy.php`, `app/Patients/MajorityService.php` (+ sua view de decisão), `app/Dashboard/DashboardController.php`, `app/Growth/GrowthController.php`, `app/Patients/PatientController.php`, `app/Portal/PortalController.php`
- Create: `app/Goals/ChildAccountController.php`, `app/Goals/views/child_account.php`, `app/Auth/views/change_password_forced.php` (ou equivalente)
- Modify: `tests/Http/SmokeAllRoutesTest.php` (papel `C`)
- Test: `tests/Http/ChildAccountTest.php`, `tests/Integration/PatientScopeTest.php`, `tests/Http/LoginFlowTest.php`

**Interfaces:**
- Consumes: colunas da Task 2 (`users.username`, `users.must_change_password`, `patients.child_user_id`).
- Produces:
  - `UserRepository::findByLogin(string $login): ?array` — com `@` → e-mail normalizado; senão → `username` em minúsculas, sem espaços. `UserRepository::isValidUsername(string): bool` (`^[a-z0-9.]{3,40}$`, sem `@`).
  - `AuthService::attempt(string $login, ...)` usa `findByLogin`; mesma mensagem genérica e bloqueio. Login: campo `email` vira texto com rótulo "E-mail ou usuário".
  - `must_change_password = 1` → após o login (qualquer papel), todo GET vai para `/senha/trocar` até trocar (POST `/senha/trocar` com senha nova ≥ 10 e confirmação); logout liberado.
  - `PatientScope`: caso `crianca` → paciente com `child_user_id = uid`; `accessiblePatientIds` idem.
  - `Policy`: `crianca` → `view [METAS]`, `edit []`; `paciente`/`responsavel` ganham `view METAS`.
  - Middleware global `childarea` (adicionado a `$authMw` em `app/routes.php`): papel `crianca` só passa por rotas cujo caminho começa com `/portal/p/{seuPacienteId}/metas`, `/portal/p/{seuPacienteId}/cartoes/`, `/senha/trocar`, `/portal/conta` (troca de senha) e POST `/logout`; GET em outra rota → redirect para `/portal/p/{id}/metas`; outros métodos → 403. `/` e `/portal` do papel `crianca` → redirect para a área dele.
  - Controllers que listam `crianca` como família (Dashboard, Growth, Patients, Portal) deixam de incluí-lo.
  - Ficha da paciente (seção Acesso, só médica): `GET|POST /pacientes/{id}/acesso-crianca` — criar (nome de usuário + senha provisória gerada e mostrada **uma vez** na tela; `must_change_password = 1`; `email` nulo), redefinir senha (nova provisória), desativar/reativar; recusa criar se o paciente tem `user_id` (conta `paciente`) ou já tem `child_user_id`; opção **"Responsáveis veem as marcações feitas pelo próprio paciente"** (`guardians_see_child_checks`), visível quando há conta de criança. Tudo auditado.
  - `MajorityService::decide(..., 'transferir', ...)`: se o paciente tem `child_user_id`, converte esse usuário em `paciente` (`role`, `email` informado, `patients.user_id = child_user_id`, `child_user_id = NULL`), preservando marcações.

- [ ] **Step 1: Testes** — `test_login_with_username_and_email` (usuário em maiúsculas/espaços normalizado; e-mail continua funcionando; senha errada → mensagem genérica; conta desativada → recusada), `test_temporary_password_forces_change`, `test_doctor_creates_child_account_once` (2ª tentativa recusada; paciente com conta `paciente` recusado; senha mostrada uma vez; audit), `test_child_is_confined_to_own_goals_area` (GET `/`, `/pacientes`, `/portal`, `/portal/p/{id}`, `/portal/p/{id}/crescimento`, `/portal/p/{id}/alimentacao`, `/portal/p/{id}/exames`, `/catalogo-exames`, `/portal/p/{outroId}/metas` → redirect para a própria área ou 403; POST fora da lista → 403), `test_patient_scope_child` (PatientScopeTest), `test_majority_transfer_converts_child_account`. Smoke: adicionar papel `C` (crianca com conta vinculada ao paciente de fumaça) e preencher a 6ª coluna de **todas** as rotas.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: conta propria da crianca com login por usuario`.

---

### Task 4: Telas da equipe — metas da paciente e painel

**Files:**
- Create: `app/Goals/GoalController.php`, `app/Goals/views/index.php` (gerenciar + grade de 8 semanas), `app/Goals/views/form.php`
- Modify: `app/routes.php`, `app/Patients/PatientController.php` + `app/Patients/views/show.php` (cartão **Metas**), `public/assets/css/*.css`, `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/GoalPagesTest.php`

**Interfaces:**
- Consumes: `GoalRepository` (Task 2), `GoalCalc` (Task 1).
- Produces: **Biblioteca de metas** (menu, médica edita todas; nutricionista só categorias nutricionais): `GET /metas-modelo`, `GET|POST /metas-modelo/novo`, `GET|POST /metas-modelo/{templateId}/editar` (textos, emoji, categoria, frequência sugerida, ativo — desativar em vez de apagar; repositório ganha `saveTemplate(?int $id, array $data, int $uid, Request $r): int` com a mesma validação de textos/categoria/frequência). Também `GET /pacientes/{id}/metas` (lista ativas/encerradas + grade 8 semanas terminadas + semana atual: por meta e semana "done/target" com ✓ quando completa; meta secreta marcada "sigilosa"); `GET|POST /pacientes/{id}/metas/nova` (escolher da biblioteca — preenche os campos — ou texto livre); `GET|POST /metas/{goalId}/editar`; `POST /metas/{goalId}/encerrar` (fim = hoje); `POST /metas/{goalId}/excluir`. Autorização: médico; nutricionista só metas nutricionais de paciente liberado (criar/editar só nessas categorias; categoria não editável por ela); secretária/família/criança 403. Ordem: 404 → papel/Gate → regra de categoria. Cartão **Metas** na ficha: metas visíveis ao usuário com cumprimento das últimas 4 semanas e (só médica) total de estrelas e último registro da família. Opção "sigilosa" só aparece se o paciente tem conta de criança.

- [ ] **Step 1: Testes** — `test_template_library_edit_and_deactivate` (desativada some da escolha ao criar meta), `test_doctor_creates_goal_from_template_and_sees_grid`, `test_nutritionist_only_nutrition_goals` (vê/cria hidratação; não vê sono; 403 ao editar meta de sono; não muda categoria), `test_secret_option_requires_child_account`, `test_end_and_delete_goal_audited`, `test_family_and_secretary_get_403`, `test_patient_card_shows_goals`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** (rotas GET novas no smoke com as 6 colunas).
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: metas da paciente para a equipe com painel semanal`.

---

### Task 5: Área de metas no portal (pais, criança, adulto)

**Files:**
- Create: `app/Goals/PortalGoalController.php` (ou ações no `PortalController`, seguindo o padrão `open()`), `app/Portal/views/goals.php`, `public/assets/js/goal-check.js`
- Modify: `app/routes.php`, `app/Portal/views/home.php` (item "Metas de <nome>"), `public/assets/css/*.css`, `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/PortalGoalsTest.php`

**Interfaces:**
- Consumes: `GoalRepository::forViewer/allForViewer/check/uncheck`, `GoalCalc`.
- Produces: `GET /portal/p/{id}/metas?semana=Y-m-d` (semana atual por padrão; semanas anteriores só leitura), `POST /portal/p/{id}/metas/{goalId}/marcar` e `/desmarcar` (campo `dia`; resposta: redirect de volta para a mesma semana; com `Accept: application/json` → `{ok, stars}` para o JS). Tela: saudação com primeiro nome (criança/pais: "Oi, Ana!"; adulto: "Suas metas"), total de estrelas, semana com 7 botões por meta (dias fora da janela/período desabilitados), "N de M" para metas não diárias, selos (`GoalCalc::BADGE_LABELS`), espaço "Para você ler" (preenchido na Task 6). Texto da meta: `child_text ?? family_text` para criança e pais; `family_text` para adulto. Botões grandes, `aria-pressed`, rótulo acessível "Marcar <meta> em <dia da semana dd/mm>". Funciona sem JS (cada botão é um `<form method="post">`); `goal-check.js` só evita o recarregamento.

- [ ] **Step 1: Testes** — `test_guardian_marks_and_unmarks_within_window` (D-6 ok; D-7 → recusado com mensagem; amanhã recusado), `test_guardian_counts_exclude_secret_goal` (estrelas e "semana completa" do responsável não contam a secreta; o adolescente vê a secreta e a conta), `test_guardians_dont_see_child_checks_when_disabled`, `test_child_sees_only_own_area_texts` (texto lúdico, sem itens de outras seções no menu), `test_adult_patient_sees_family_texts`, `test_other_patient_403`, `test_double_submit_single_check`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** (smoke `[302,302,302,200,200,200]` ajustado e comentado conforme o comportamento real por papel).
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK; `node --check public/assets/js/goal-check.js`.
- [ ] **Step 5: Commit** — `feat: area de metas no portal com estrelas e selos`.

---

### Task 6: Cartões educativos (40 rascunhos, revisão e exibição)

**Files:**
- Create: `database/seeds/goal_2_edu_cards.sql`, `app/Goals/EduCardRepository.php`, `app/Goals/EduCardController.php`, `app/Goals/views/cards_index.php`, `app/Goals/views/card_form.php`
- Modify: `app/routes.php`, `app/Layout/views/layout.php` (menu **Cartões educativos**, só médica), `app/Portal/views/goals.php` ("Para você ler"), `app/Core/Container.php` (`eduCards()`), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Integration/EduCardRepositoryTest.php`, `tests/Http/EduCardTest.php`

**Interfaces:**
- Produces:
  - Seed com **exatamente 40** cartões `status = 'rascunho'`, ids 1..40: pelo menos 8 por faixa (um cartão pode ter mais de uma faixa), temas água, frutas e verduras, lanches, sono, telas, brincar/movimento, rotina de refeições, fome de verdade/emoções; título ≤ 60 caracteres, texto ≤ 600, linguagem adequada à faixa (3–6: frases curtíssimas para os pais lerem junto; 15–19: tom de adolescente, sem infantilizar); regra de linguagem das Global Constraints.
  - `EduCardRepository`: `all(): list`, `find(int)`, `save(?int $id, array $data, int $uid, Request $r): int` (título 3..60, texto 10..600, ≥ 1 faixa válida, emoji obrigatório), `publish(int $id, int $uid, Request $r)` (grava `approved_by/at`), `unpublish(...)`, `publishedForAge(int $years): list` (só publicados cuja faixa contém a idade; idade < 3 ou > 19 → `[]`), `bandFor(int $years): ?string`.
  - Rotas (só médica): `GET /cartoes`, `GET|POST /cartoes/novo`, `GET|POST /cartoes/{cardId}/editar`, `POST /cartoes/{cardId}/publicar`, `/despublicar`. Portal: `GET /portal/p/{id}/cartoes/{cardId}` (via `open()` com `Policy::METAS`; 404 se o cartão não está publicado ou a faixa não serve à idade de hoje do paciente); a criança acessa (guarda da Task 3 já libera `/portal/p/{id}/cartoes/`).
  - Na área de metas: até 6 cartões publicados da faixa, ordem `sort_order`.

- [ ] **Step 1: Testes** — `test_seed_has_40_drafts_covering_bands` (40 linhas, todas `rascunho`, cada faixa com ≥ 8, nenhum texto contém "peso", "gord", "magr", "dieta", "obes"), `test_drafts_never_reach_portal`, `test_published_by_age_band` (idade 2 → nenhum; 7 → faixa 7-10; 19 → 15-19; 20 → nenhum), `test_publish_audited`, `test_only_doctor_manages_cards`, `test_child_reads_published_card_of_own_band_only`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Escrever os 40 cartões e implementar.**
- [ ] **Step 4: Rodar e ver passar**; suíte inteira OK.
- [ ] **Step 5: Commit** — `feat: cartoes educativos com 40 rascunhos para revisao`.

---

### Task 7: Documentação e pacote

**Files:**
- Modify: `README.md` (seção "Metas e área da criança": criar metas, biblioteca, conta da criança — usuário e senha provisória, sigilo, cartões em rascunho e como publicar; §9 com a atualização desta versão: migração `010_goals.sql` e seeds `goal_1_templates.sql`, `goal_2_edu_cards.sql` — ou `php tools/migrate.php`; `INSERT INTO schema_migrations (name) VALUES ('010_goals.sql');` para quem usar o phpMyAdmin; extrair em `rubia_app`; cópia do **conteúdo** de `public/` obrigatória nesta versão)
- Create: `docs/superpowers/backlog-fase3a.md`
- [ ] **Step 1:** Escrever; conferir nomes de menus, botões e rotas citados contra o código.
- [ ] **Step 2:** `php tools/phpunit.phar` OK; `php tools/build_release.php` e `unzip -l` lista `010_goals.sql`, os dois seeds e `goal-check.js`.
- [ ] **Step 3: Commit** — `docs: metas e area da crianca no README e atualizacao de versao`.
