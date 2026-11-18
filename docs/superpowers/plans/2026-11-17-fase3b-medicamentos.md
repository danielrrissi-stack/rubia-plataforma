# Tratamento e medicamentos (Fase 3b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prescrições com histórico de doses, registro de doses e relatos de efeitos pela família, prescrição sigilosa para menores, receita simples para imprimir e avisos na tela inicial da médica — sem nenhum cálculo ou sugestão de dose.

**Architecture:** Novo módulo `app/Medications/` no padrão de `app/Labs/` e `app/Goals/`: repositórios com `Database` + `AuditLog`, controllers finos, views PHP. Uma única regra de visibilidade (`PrescriptionRepository::visibleTo()` / `forViewer()`) decide o que cada papel enxerga, e os repositórios de doses registradas e de efeitos a reutilizam. As telas da equipe exigem papel médico antes do Gate (como os exames), porque a família passa a ter `view MEDICAMENTOS` para o portal.

**Tech Stack:** PHP 8.1+ puro, MariaDB (InnoDB, utf8mb4), PHPUnit 11 (`tools/phpunit.phar`), JS sem framework em `public/assets/js/` (CSP estrita).

**Spec:** `docs/superpowers/specs/2026-11-17-medicamentos-design.md` (e a geral `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`, Módulo 5).

## Global Constraints

- PHP 8.1+, `declare(strict_types=1);`; sem Composer/Node em produção; nenhuma biblioteca nova.
- CSP: nenhum `<script>` inline, `style=""` nem `onclick=` (cobrado por `tests/Unit/ViewsCspTest.php`). Toda saída passa por `e()`. Textos em pt-BR; datas com `fmt_date`.
- "Hoje"/"agora" = `date('Y-m-d')` / `date('Y-m-d H:i')` (fuso America/Sao_Paulo); a lógica de data recebe `$today`/`$now` por parâmetro.
- Migração única: `database/migrations/011_medications.sql`. Sem seeds.
- **Dose e frequência são texto livre.** Nenhum código interpreta, compara, soma ou alerta sobre dose. Nenhuma tela diz "aumentou", "reduziu" nem "próxima dose sugerida".
- Vias (ENUM, nesta ordem): `oral`, `sublingual`, `subcutanea`, `intramuscular`, `topica`, `inalatoria`, `nasal`, `oftalmica`, `otologica`, `retal`, `vaginal`, `outra`. Rótulos: "Oral", "Sublingual", "Subcutânea", "Intramuscular", "Tópica", "Inalatória", "Nasal", "Oftálmica", "Otológica", "Retal", "Vaginal", "Outra". Na receita: "Uso oral", "Uso sublingual", "Uso subcutâneo", "Uso intramuscular", "Uso tópico", "Uso inalatório", "Uso nasal", "Uso oftálmico", "Uso otológico", "Uso retal", "Uso vaginal", "Outras vias".
- Limites: medicamento 120; dose 60; frequência 120; orientação 1000; motivo 200; observação da dose 200; descrição do efeito 2000; conduta 2000; observações internas 2000.
- Situação: `futura` (start_date > today), `ativa` (start_date ≤ today e (end_date nulo ou ≥ today)), `encerrada` (end_date < today).
- Sigilo: `secret = 1` só pode ser gravado por papel `medico` e só se o paciente tem menos de 18 anos na data de hoje (`Age::months(birth_date, today) < 216`); caso contrário vira 0. Responsável nunca vê prescrição sigilosa, as doses registradas dela nem os efeitos ligados a ela. `paciente` (adulto) vê tudo do próprio tratamento.
- Permissões: `Policy` dá `view MEDICAMENTOS` a `paciente` e `responsavel` (sem `edit`). Telas da equipe: só `medico`, checado **antes** do Gate com `App\Labs\Clinician::assert($user, $r, $patientId, 'prescriptions', 'medicamentos')`. Nutricionista e secretária: 403 em tudo. Papel `crianca`: a guarda `childarea` já redireciona; não liberar rotas novas em `ChildArea`.
- Auditoria (`Container::audit()->record(uid, action, table, rowId, patientId, before, after, $r)`): `create`/`update`/`end`/`delete` em `prescriptions`; `create` em `prescription_doses`; `create`/`delete` em `medication_logs`; `create`/`review` em `adverse_events`; `update` em `settings` (receita); leitura das telas da equipe `('view', 'prescriptions', id|null, patientId, null, ['section' => 'medicamentos'])`, com `'print' => true` na receita.
- Exclusões sempre lógicas (`deleted_at`, `deleted_by`).
- Testes: `source tools/env.sh; start_mariadb` e depois `php tools/phpunit.phar`. Toda rota GET nova entra em `tests/Http/SmokeAllRoutesTest.php::EXPECTED` na mesma tarefa (6 colunas: M, N, S, R, P, C).
- Commits em português (`feat:`, `fix:`, `docs:`, `test:`), terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nunca adicionar `o que fazer.txt`.

## Review Focus

1. **Rastro de prescrição sigilosa para o responsável:** cartão "Tratamento" no início do portal quando a única prescrição é sigilosa; relato ligado a ela; dose registrada antes de ela virar sigilosa; id adivinhado na URL de registrar dose. Tudo deve ser invisível e responder 404. Testes nas Tasks 1, 2 e 6 (`test_guardian_never_sees_secret_prescription_trail`).
2. **Registro de dose fora do período:** prescrição futura, encerrada, excluída, com "família registra" desligado, hora futura, mais de 7 dias atrás. Recusar com mensagem clara e sem gravar. Teste na Task 2.
3. **Duplo clique em "Registrar dose":** um registro só, sem erro. Teste na Task 2 (`test_log_is_idempotent_per_minute`).
4. **Mudança de dose com data fora de ordem** (antes da última linha, antes do início, no futuro, numa prescrição encerrada): recusar. A dose atual e a dose gravada nos registros seguem a linha vigente em cada data. Testes na Task 1.
5. **Sigilo marcado para adulto ou por quem não é médico:** é gravado como 0. Testes na Task 1.

---

### Task 1: Tabelas, permissão e repositório de prescrições (com visibilidade)

**Files:**
- Create: `database/migrations/011_medications.sql`, `app/Medications/PrescriptionRepository.php`, `app/Medications/InvalidMedicationException.php`, `app/Medications/Via.php` (constantes de vias e rótulos)
- Modify: `app/Access/Policy.php` (família ganha `view MEDICAMENTOS`; atualizar o docblock), `app/Core/Container.php`, `tests/Unit/PolicyTest.php`
- Test: `tests/Integration/PrescriptionRepositoryTest.php`

**Interfaces:**
- Tabelas:
  - `prescriptions`: `id`, `patient_id`, `drug_name VARCHAR(120)`, `route ENUM(...)`, `start_date DATE`, `end_date DATE NULL`, `end_reason VARCHAR(200) NULL`, `next_review_date DATE NULL`, `family_logs TINYINT(1) DEFAULT 0`, `secret TINYINT(1) DEFAULT 0`, `notes TEXT NULL`, `created_by`, `created_at`, `updated_by NULL`, `updated_at NULL`, `deleted_at NULL`, `deleted_by NULL`, com FKs para `patients` e `users`.
  - `prescription_doses`: `id`, `prescription_id`, `effective_date DATE`, `dose VARCHAR(60)`, `frequency VARCHAR(120)`, `instructions TEXT NULL`, `reason VARCHAR(200) NULL`, `created_by`, `created_at`.
  - `medication_logs`: `id`, `prescription_id`, `given_at DATETIME` (minuto, segundos = 00), `dose VARCHAR(60)`, `note VARCHAR(200) NULL`, `recorded_by`, `recorded_by_role VARCHAR(20)`, `created_at`, `deleted_at NULL`, `deleted_by NULL`, `KEY (prescription_id, given_at)`.
  - `adverse_events`: `id`, `patient_id`, `prescription_id NULL`, `occurred_on DATE`, `description TEXT`, `source ENUM('equipe','familia')`, `severity ENUM('leve','moderada','grave') NULL`, `conduct TEXT NULL`, `reported_by`, `reported_by_role VARCHAR(20)`, `created_at`, `reviewed_by NULL`, `reviewed_at NULL`, `deleted_at NULL`, `deleted_by NULL`.
- `App\Medications\Via` (final, só constantes): `ROUTES` (lista das vias na ordem), `LABELS` (via → rótulo), `RX_HEADINGS` (via → "Uso oral"…).
- `App\Medications\InvalidMedicationException extends \RuntimeException` com `errors(): list<string>` (mesmo formato de `InvalidGoalException`).
- `App\Medications\PrescriptionRepository(Database, AuditLog)`, exposto por `Container::prescriptions()`:
  - `create(int $patientId, array $data, array $user, string $today, Request $r): int`. `$data` traz `drug_name, route, start_date, end_date, next_review_date, family_logs, secret, notes, dose, frequency, instructions`. Cria a prescrição e a primeira linha de dose com `effective_date = start_date`.
  - `update(int $id, array $data, array $user, string $today, Request $r): void`. Altera só os campos da prescrição (as linhas de dose não mudam). Recusa `start_date` posterior à primeira linha de dose já existente, a não ser que só exista a primeira linha (nesse caso ela acompanha o novo início).
  - `changeDose(int $id, array $data, int $uid, string $today, Request $r): int`. `$data` traz `effective_date, dose, frequency, instructions, reason`. Regras: prescrição não encerrada e não excluída; `effective_date ≥ última effective_date`, `≥ start_date` e `≤ max(today, start_date)`.
  - `end(int $id, string $endDate, ?string $reason, int $uid, string $today, Request $r): void`. Exige `start_date ≤ endDate ≤ today` (`endDate = today` aceito). Prescrição futura não pode ser encerrada; exclua-a.
  - `delete(int $id, int $uid, Request $r): void` (lógica).
  - `find(int $id): ?array` (inclui excluídas); `doses(int $id): list<array>` (crescente por `effective_date, id`, com `author_name`).
  - `doseOn(int $id, string $ymd): ?array`: linha vigente na data (maior `effective_date ≤ ymd`, desempate por id).
  - `status(array $p, string $today): string`: `'futura'|'ativa'|'encerrada'`.
  - `visibleTo(int $id, array $viewer): ?array`. Retorna a prescrição se não excluída e visível ao papel (`medico` e `paciente`: sim; `responsavel`: só `secret = 0`; demais: não); `null` caso contrário.
  - `forViewer(int $patientId, array $viewer, string $today): list<array>`. Mesma regra, não excluídas. Cada item ganha `current` (= `doseOn(today)`, ou a primeira linha se a prescrição for futura) e `status`. Ordem: ativas e futuras por `start_date`, depois encerradas por `end_date` decrescente.
  - `drugNames(): list<string>`: nomes distintos já usados (não excluídas), em ordem alfabética.
- Validação: obrigatórios `drug_name, route ∈ Via::ROUTES, start_date, dose, frequency`. Os limites da Global Constraints valem para todos os campos, e o texto é aparado com `trim`. `end_date ≥ start_date`; `next_review_date` é data válida. Para `start_date`, `end_date` e `next_review_date`, aceitar no máximo 5 anos antes ou depois de hoje. Mensagens em pt-BR, ex.: "Informe o medicamento.", "A data da nova dose deve ser igual ou posterior à última alteração.", "Não é possível registrar dose futura além do início da prescrição.".

- [ ] **Step 1: Escrever os testes** em `PrescriptionRepositoryTest` (estende `Tests\DbTestCase`; use `makeUser`/`makePatient`):
  - `test_create_stores_first_dose_and_audits` (linha com `effective_date = start_date`; auditoria `create` em `prescriptions`).
  - `test_change_dose_appends_row_and_dose_on_follows_dates`. Ex.: início 2026-10-01 "2,5 mg"; alteração 2026-11-01 "5 mg". `doseOn('2026-10-31')['dose'] === '2,5 mg'`, `doseOn('2026-11-01')['dose'] === '5 mg'`, `doses()` tem 2 linhas e auditoria `create` em `prescription_doses`.
  - `test_change_dose_rejects_out_of_order_future_and_ended`: data antes da última linha, antes do início, depois de hoje (com início passado) e prescrição encerrada lançam `InvalidMedicationException`. Data = início futuro é aceita.
  - `test_secret_only_for_minor_by_doctor`: médico + paciente de 15 anos → `secret = 1`; médico + paciente de 18 anos completos hoje → 0.
  - `test_visibility_rules`: responsável não vê sigilosa (`visibleTo` null, ausente de `forViewer`); `paciente` e `medico` veem; `nutricionista`, `secretaria` e `crianca` recebem null e lista vazia; excluída some para todos.
  - `test_status_and_ordering` (futura/ativa/encerrada e a ordem de `forViewer`).
  - `test_end_and_delete_are_audited` (`end` com data > hoje é recusado).
  - Em `PolicyTest`: `paciente`/`responsavel` têm `view MEDICAMENTOS` e não têm `edit`; `nutricionista`, `secretaria` e `crianca` não têm `view`.
- [ ] **Step 2:** Rodar `php tools/phpunit.phar tests/Integration/PrescriptionRepositoryTest.php`. Esperado: FAIL (classe inexistente).
- [ ] **Step 3:** Escrever a migração, as classes, `Container::prescriptions()` e a mudança na `Policy`.
- [ ] **Step 4:** Rodar os dois testes acima e depois a suíte inteira. Esperado: tudo OK (com a família vendo `MEDICAMENTOS`, as asserções de outros testes sobre essa seção podem mudar; ajuste só as que falharem por esse motivo).
- [ ] **Step 5:** Commit `feat: tabelas e repositorio de prescricoes com historico de doses e sigilo`.

### Task 2: Doses registradas pela família e efeitos adversos (repositórios)

**Files:**
- Create: `app/Medications/MedicationLogRepository.php`, `app/Medications/AdverseEventRepository.php`
- Modify: `app/Core/Container.php`
- Test: `tests/Integration/MedicationLogRepositoryTest.php`, `tests/Integration/AdverseEventRepositoryTest.php`

**Interfaces:**
- Consumes: `PrescriptionRepository::visibleTo`, `doseOn`, `status`, `InvalidMedicationException` (Task 1).
- `MedicationLogRepository(Database, AuditLog, PrescriptionRepository)`, exposto por `Container::medicationLogs()`:
  - `record(int $prescriptionId, string $givenAt /* 'Y-m-d H:i' */, ?string $note, array $user, string $now, Request $r): void`. Regras: papel `paciente` ou `responsavel`; prescrição visível a ele (senão "Medicamento não encontrado."); `family_logs = 1`; `now − 7 dias ≤ givenAt ≤ now`; a data de `givenAt` dentro de `[start_date, end_date ?? ∞]`. A dose gravada vem de `doseOn(data)`. Se já existe registro não excluído do mesmo usuário, mesma prescrição e mesmo `given_at`, nada é gravado e não há erro.
  - `remove(int $logId, array $user, string $now, Request $r): void`. Só quem registrou, só registro não excluído com `given_at ≥ now − 7 dias`, só se a prescrição ainda é visível a ele. Caso contrário, "Registro não encontrado.".
  - `forPrescription(int $prescriptionId, array $viewer, string $since): list<array>`. Registros não excluídos com `given_at ≥ since`, em ordem decrescente, com `recorder_name`. Vazio se a prescrição não for visível ao viewer.
- `AdverseEventRepository(Database, AuditLog, PrescriptionRepository)`, exposto por `Container::adverseEvents()`:
  - `report(int $patientId, array $data, array $user, string $today, Request $r): int`. Família (`paciente`/`responsavel`): `source = 'familia'`; `occurred_on` entre `today − 90 dias` e `today`; `description` obrigatória; `prescription_id` opcional, mas, se vier, tem que ser visível ao usuário e do mesmo paciente; `severity` e `conduct` são ignorados. Médico: `source = 'equipe'`, aceita `severity` e `conduct`, e o relato já nasce revisado (`reviewed_by`/`reviewed_at` preenchidos).
  - `review(int $eventId, ?string $severity, ?string $conduct, int $uid, Request $r): void`. Só relato da família ainda não revisado; ação de auditoria `review`.
  - `forViewer(int $patientId, array $viewer): list<array>`. Médico: todos os relatos não excluídos, com `drug_name` e `reporter_name`. `paciente`: os da família. `responsavel`: os da família, exceto os ligados a prescrição sigilosa. Demais papéis: vazio. Ordem: `occurred_on` decrescente, depois `id` decrescente.
  - `unreviewed(?array $patientIds): list<array>`. Relatos da família sem revisão, de pacientes não excluídos (`null` = todos), do mais antigo para o mais novo, com `patient_name`.

- [ ] **Step 1: Escrever os testes**:
  - Doses registradas: `test_records_current_dose_snapshot`, que registra num dia anterior à mudança de dose e grava a dose antiga; `test_rejects_outside_window_and_period` (futura, encerrada, `family_logs = 0`, hora futura, 8 dias atrás, prescrição excluída); `test_log_is_idempotent_per_minute`; `test_only_author_removes_within_window`; `test_staff_roles_cannot_record`.
  - `test_guardian_never_sees_secret_prescription_trail`: com a prescrição sigilosa, o responsável não registra dose (exceção), não vê os registros feitos antes de ela virar sigilosa e não vê o relato ligado a ela. O médico vê tudo.
  - Efeitos: `test_family_report_limits_and_ignores_severity`; `test_doctor_report_is_born_reviewed`; `test_review_marks_and_audits`, incluindo recusa na segunda revisão; `test_unreviewed_lists_oldest_first`; `test_family_cannot_link_other_patients_prescription`.
- [ ] **Step 2:** Rodar os testes. Esperado: FAIL.
- [ ] **Step 3:** Implementar as duas classes e os métodos no `Container`.
- [ ] **Step 4:** Rodar os dois testes. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: registro de doses pela familia e relatos de efeitos adversos`.

### Task 3: Telas da equipe — tratamento, prescrição, dose, encerrar, excluir

**Files:**
- Create: `app/Medications/PrescriptionController.php`; views em `app/Medications/views/` (`index.php`, `form.php`, `show.php`, `dose_form.php`)
- Modify: `app/routes.php`, `app/Labs/Clinician.php` (`assert` ganha `string $entity = 'lab_collections', string $section = 'exames'`), `app/Patients/PatientController.php` e `app/Patients/views/show.php` (cartão "Tratamento"), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/PrescriptionPagesTest.php`

**Interfaces:**
- Consumes: Task 1 (`Container::prescriptions()`); Task 2 (`Container::medicationLogs()->forPrescription`, `Container::adverseEvents()->forViewer`).
- Rotas (todas `$authMw`; papel médico antes do Gate `view|edit MEDICAMENTOS`):
  - `GET /pacientes/{id}/tratamento` (`index`): prescrições ativas e futuras; encerradas dentro de `<details>`; relatos de efeito; botões "Nova prescrição", "Registrar efeito" (`/pacientes/{id}/efeitos/novo`, Task 4) e "Receita" (`/pacientes/{id}/receita`, Task 5).
  - `GET|POST /pacientes/{id}/tratamento/nova` (`createForm`/`create`): o formulário tem `<datalist>` com `drugNames()`; a caixa "Sigilosa" só aparece se o paciente tem menos de 18 anos. Erro de validação devolve 422 com os valores preenchidos.
  - `GET /prescricoes/{prescriptionId}` (`show`): dados, histórico de doses (data, dose, frequência, orientação, motivo, profissional), registros da família dos últimos 90 dias e efeitos ligados.
  - `GET|POST /prescricoes/{prescriptionId}/editar` (`editForm`/`update`).
  - `GET|POST /prescricoes/{prescriptionId}/dose` (`doseForm`/`changeDose`): o formulário já vem com a dose, a frequência e a orientação atuais, e a data padrão é hoje.
  - `POST /prescricoes/{prescriptionId}/encerrar` (data padrão hoje e motivo).
  - `POST /prescricoes/{prescriptionId}/excluir`: confirmação no formulário, com o mesmo mecanismo de confirmação da exclusão de metas (`data-confirm` + JS já existente).
  - Prescrição inexistente ou excluída: 404 depois da checagem de papel. Após POST bem-sucedido, redirecionar para `/prescricoes/{id}`; após excluir, para `/pacientes/{id}/tratamento`.
- Ficha do paciente: cartão "Tratamento" só para médico, com até 5 prescrições ativas ("nome — dose atual"), a "Próxima avaliação: dd/mm/aaaa" mais próxima entre as ativas e "N relatos da família para ver" quando N > 0. Link "Ver tratamento".
- Smoke (`M, N, S, R, P, C`): `/pacientes/{id}/tratamento`, `/pacientes/{id}/tratamento/nova`, `/prescricoes/{prescriptionId}`, `/prescricoes/{prescriptionId}/editar` e `/prescricoes/{prescriptionId}/dose` ficam `[200, 403, 403, 403, 403, 302]`. Criar no fixture do smoke uma prescrição para preencher `{prescriptionId}`.

- [ ] **Step 1: Escrever os testes** em `PrescriptionPagesTest` (padrão de `tests/Http/LabPagesTest.php`):
  - `test_doctor_creates_prescription_and_changes_dose`: a página mostra as duas doses e o motivo.
  - `test_validation_keeps_values` (422).
  - `test_secret_checkbox_only_for_minor`.
  - `test_nutritionist_secretary_and_family_get_403`: as telas da equipe dão 403 para responsável e paciente, e a auditoria registra `denied`.
  - `test_end_and_delete_flow`: a excluída some do índice e `GET /prescricoes/{id}` dá 404.
  - `test_patient_card_shows_treatment_only_for_doctor`.
  - `test_pages_never_show_dose_suggestions`: o HTML de `show` não contém "aument", "reduz" nem "sugest".
- [ ] **Step 2:** Rodar `php tools/phpunit.phar tests/Http/PrescriptionPagesTest.php`. Esperado: FAIL.
- [ ] **Step 3:** Implementar controller, views, rotas, cartão da ficha e linhas do smoke.
- [ ] **Step 4:** Rodar `PrescriptionPagesTest`, `SmokeAllRoutesTest`, `ViewsCspTest` e `LabPagesTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: telas da equipe para prescricoes e historico de doses`.

### Task 4: Efeitos adversos na equipe e avisos na tela inicial da médica

**Files:**
- Create: `app/Medications/AdverseEventController.php`, `app/Medications/views/event_form.php`
- Modify: `app/routes.php`, `app/Medications/views/index.php` (botão "Ciente" nos relatos da família), `app/Dashboard/DashboardController.php` + `app/Dashboard/views/index.php`, `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/AdverseEventPagesTest.php`

**Interfaces:**
- Consumes: `Container::adverseEvents()` (Task 2), `Container::prescriptions()` (Task 1).
- Rotas (`$authMw`, papel médico antes do Gate `edit MEDICAMENTOS`):
  - `GET|POST /pacientes/{id}/efeitos/novo`: data, descrição, medicamento (select com as prescrições não excluídas do paciente, opcional), gravidade e conduta. Redireciona para `/pacientes/{id}/tratamento`.
  - `POST /efeitos/{eventId}/ciente`: gravidade e conduta opcionais. Relato inexistente ou já revisado dá 404.
- Tela inicial (só papel médico):
  - Seção "Relatos da família para ver" (`unreviewed(null)`, no máximo 10): data, paciente, início da descrição (até 80 caracteres) e link para `/pacientes/{id}/tratamento`.
  - Seção "Reavaliações": prescrições ativas não excluídas com `next_review_date ≤ today + 7`, em ordem crescente, no máximo 10, mostrando "vencida" quando `< today`.
  - As duas seções só aparecem quando há algum item. Criar `PrescriptionRepository::reviewsDue(string $today, int $days = 7): list<array>` (com `patient_name`, `drug_name`, `next_review_date`).
- Smoke: `/pacientes/{id}/efeitos/novo` fica `[200, 403, 403, 403, 403, 302]`.

- [ ] **Step 1: Escrever os testes**:
  - `test_doctor_registers_event_and_reviews_family_report`, incluindo a auditoria `review`.
  - `test_review_twice_is_404`.
  - `test_dashboard_lists_unreviewed_and_due_reviews`: relato some depois do "ciente"; reavaliação vencida aparece com "vencida"; a de 10 dias no futuro não aparece.
  - `test_dashboard_sections_hidden_for_nutritionist`.
  - `test_other_roles_get_403`.
- [ ] **Step 2:** Rodar os testes. Esperado: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar `AdverseEventPagesTest`, `DashboardTest`, `SmokeAllRoutesTest` e `ViewsCspTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: efeitos adversos na equipe e avisos de relatos e reavaliacoes`.

### Task 5: Receita simples para imprimir e cabeçalho configurável

**Files:**
- Create: `app/Medications/PrescriptionPrintController.php`, `app/Medications/views/rx_choose.php`, `app/Medications/views/rx_print.php`, `app/Medications/views/rx_settings.php`
- Modify: `app/routes.php`, `public/assets/css/app.css` (estilo de impressão da receita, reaproveitando `body.impressao`), `app/Layout/views/layout.php` (item "Receita (cabeçalho)" no menu de administração, só `is_admin`), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/PrescriptionPrintTest.php`

**Interfaces:**
- Consumes: `PrescriptionRepository::forViewer`, `Via::RX_HEADINGS` (Task 1).
- Rotas:
  - `GET /pacientes/{id}/receita` (médico antes do Gate `view MEDICAMENTOS`): checkboxes `p[]` com as prescrições ativas e futuras, todas marcadas, num formulário GET para `/pacientes/{id}/receita/imprimir`.
  - `GET /pacientes/{id}/receita/imprimir?p[]=..`: só ids de prescrições não excluídas, não encerradas e do próprio paciente; os demais são ignorados em silêncio. Sem nenhum id válido, redireciona para `/pacientes/{id}/receita`. Renderiza sem layout (`View::render(..., null)`), como `Nutrition/views/print.php`.
  - `GET|POST /admin/receita` (só `is_admin = 1`; os outros recebem 403): campos `rx_clinic_name` (até 120), `rx_address` (até 200), `rx_phone` (até 40), `rx_city` (até 80), gravados em `settings` com upsert. Auditoria `update` em `settings` (`before`/`after` com as 4 chaves).
- Conteúdo da impressão:
  - Cabeçalho com nome do consultório, endereço e telefone; linhas vazias são omitidas.
  - Título "Receituário" e "Paciente: {nome social ou completo}".
  - Os grupos seguem a ordem de `Via::ROUTES`, cada um com o título de `RX_HEADINGS`. Os itens são numerados em sequência na receita inteira: "1. {drug_name} — {dose}", depois a frequência e, embaixo, a orientação.
  - Rodapé "{rx_city ou vazio}, {data de hoje por extenso: 17 de novembro de 2026}", linha de assinatura, "{users.name}" e "CRM {professional_register}" (omitido se vazio).
  - Usa a dose vigente hoje (para prescrição futura, a primeira). Nunca mostra `notes`, motivo nem autor.
  - Auditoria `('view', 'prescriptions', null, patientId, null, ['section' => 'medicamentos', 'print' => true, 'ids' => [...]])`.
- Smoke: `/pacientes/{id}/receita` e `/pacientes/{id}/receita/imprimir` ficam `[200|302, 403, 403, 403, 403, 302]`. Sem `p[]`, `imprimir` redireciona (302 para M). `/admin/receita` fica `[200, 403, 403, 403, 403, 302]` (o médico do fixture é admin; confira no fixture).

- [ ] **Step 1: Escrever os testes**:
  - `test_print_groups_by_route_numbers_items_and_shows_header`: duas orais e uma subcutânea; "Uso oral" vem antes de "Uso subcutâneo"; a numeração vai de 1 a 3; aparecem o CRM e a cidade.
  - `test_print_hides_internal_notes_and_ignores_foreign_or_ended_ids`.
  - `test_settings_admin_only_and_audited`.
  - `test_print_is_audited`.
  - `test_print_page_passes_csp`: coberto pelo `ViewsCspTest`; rodá-lo.
- [ ] **Step 2:** Rodar os testes. Esperado: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar `PrescriptionPrintTest`, `SmokeAllRoutesTest` e `ViewsCspTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: receita simples para imprimir com cabecalho configuravel`.

### Task 6: Tratamento no portal da família

**Files:**
- Create: `app/Medications/PortalTreatmentController.php`, `app/Portal/views/treatment.php`, `app/Portal/views/event_form.php`
- Modify: `app/routes.php`, `app/Portal/PortalController.php` (`home`: `hasTreatment`) e `app/Portal/views/home.php` (cartão "Tratamento"), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/PortalTreatmentTest.php`

**Interfaces:**
- Consumes: Tasks 1 e 2. O controller abre o paciente pelo mesmo caminho do portal: Gate `view MEDICAMENTOS` com o `id` da URL e redireciono do `guard()` do portal. Siga `PortalGoalController` como modelo.
- Rotas (`$authMw`):
  - `GET /portal/p/{id}/tratamento`: para cada prescrição visível ativa ou futura, mostra nome, via (rótulo), "Dose: {dose}", "Como usar: {frequência}", orientação, "Desde dd/mm/aaaa" (ou "Começa em dd/mm/aaaa"), "Próxima avaliação com a Dra. Rubia: dd/mm/aaaa" e, se `family_logs = 1` e a prescrição está ativa, o formulário "Registrar dose" (data e hora, com padrão agora, e observação). Abaixo, as doses dos últimos 7 dias (`forPrescription`), com botão "Remover" nas do próprio usuário. Encerradas nos últimos 30 dias aparecem como "{nome} — encerrado em dd/mm/aaaa". Depois vem a seção "Efeitos e intercorrências", com a lista e o link "Contar um efeito ou intercorrência".
  - `POST /portal/p/{id}/tratamento/{prescriptionId}/dose`: campos `data` (Y-m-d) e `hora` (H:i). Sucesso redireciona para `/portal/p/{id}/tratamento` com a mensagem "Dose registrada.". Erro de validação mostra a página com a mensagem, código 422. Prescrição não visível ou de outro paciente dá 404.
  - `POST /portal/p/{id}/tratamento/doses/{logId}/remover`: redireciona; registro que não é dele dá 404.
  - `GET|POST /portal/p/{id}/tratamento/relato`: data (padrão hoje), descrição e medicamento (select opcional com as visíveis). Aviso fixo: "Este relato não é visto na hora. Em caso de urgência, procure atendimento ou um pronto-socorro.". Sucesso redireciona com a mensagem "Relato enviado. A Dra. Rubia verá na próxima vez que abrir o sistema.".
  - A família nunca vê `notes`, `reason`, `end_reason`, gravidade, conduta nem autor da prescrição.
- Início do portal: o cartão "Tratamento" ("Veja os medicamentos e registre as doses.") só aparece se `forViewer` traz alguma prescrição ativa ou futura.
- Smoke: `/portal/p/{id}/tratamento` e `/portal/p/{id}/tratamento/relato` ficam `[403, 403, 403, 200, 200, 302]` para as colunas `M, N, S, R, P, C`. Antes, confira o que o smoke espera hoje das rotas `/portal/p/{id}/...` para médico, nutricionista e secretária e use o mesmo comportamento (o `guard()` do portal pode redirecionar a equipe).

- [ ] **Step 1: Escrever os testes**:
  - `test_guardian_sees_treatment_and_records_dose`.
  - `test_guardian_never_sees_secret_prescription_trail`: o cartão do início some quando a única prescrição é sigilosa; o POST de dose nela dá 404; o relato ligado a ela não aparece na lista do responsável.
  - `test_portal_hides_internal_fields`: as observações internas, o motivo da dose e o motivo do encerramento não aparecem no HTML.
  - `test_record_outside_window_shows_error_422`.
  - `test_remove_own_log_only`.
  - `test_report_effect_shows_urgency_notice_and_appears_for_doctor`.
  - `test_child_account_is_redirected_from_treatment`.
  - `test_adult_patient_sees_former_secret`.
- [ ] **Step 2:** Rodar os testes. Esperado: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar `PortalTreatmentTest`, `PortalTest`, `SmokeAllRoutesTest` e `ViewsCspTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: tratamento no portal com registro de doses e relato de efeitos`.

### Task 7: Documentação e pacote

**Files:**
- Modify: `README.md` (nova seção "Tratamento e medicamentos" depois de "Metas e área da criança"; no §9, item 9 com a migração `011_medications.sql`, o `INSERT INTO schema_migrations (name) VALUES ('011_medications.sql');` para quem usa phpMyAdmin, extração dentro de `rubia_app`, cópia do **conteúdo** de `public/` para `public_html/rubia.med.br/` porque o CSS mudou, `$APP_ROOT` mantido e o aviso de configurar o cabeçalho em `/admin/receita`; corrigir o `/home/USUARIO` do item 6 para `/home2/USUARIO`), `docs/superpowers/backlog-fase3b.md` (criar com os pontos menores adiados)
- Test: suíte inteira

- [ ] **Step 1:** Escrever a seção do README em linguagem simples para a médica. Ela cobre: criar a prescrição; alterar a dose (cada mudança fica no histórico); encerrar; sigilosa (só menores); família registra doses (ligar na prescrição); relatos e "ciente"; receita (configurar o cabeçalho antes; imprimir ou salvar em PDF pelo navegador; assinar à mão; controle especial não é coberto). Incluir também que o sistema nunca sugere dose.
- [ ] **Step 2:** Rodar a suíte inteira, `php tools/phpunit.phar`. Esperado: OK.
- [ ] **Step 3:** Rodar `php tools/build_release.php` e conferir que o zip contém `database/migrations/011_medications.sql` e `app/Medications/`. Não fazer commit do zip.
- [ ] **Step 4:** Commit `docs: tratamento e medicamentos no README e atualizacao de versao`.
