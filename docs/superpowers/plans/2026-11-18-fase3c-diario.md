# Diário de hábitos (Fase 3c) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pedidos de registro feitos pela equipe por paciente (alimentação, água, sono, atividade, tela, medidas), registros da família no portal (com foto opcional nas refeições), grade semanal para a equipe com as refeições ao lado do plano do dia, e comentários da equipe que a família lê.

**Architecture:** Novo módulo `app/Diary/` no padrão de `app/Medications/` e `app/Goals/`: repositórios com `Database` + `AuditLog`, controllers finos, views PHP. Uma única regra de visibilidade (`DiaryRequestRepository::canSee()` / `visibleTo()` / `forViewer()`) decide o que cada papel enxerga; registros, fotos, comentários, grade e contagens a reutilizam. As telas da equipe exigem papel `medico` ou `nutricionista` antes do Gate (como `GoalController::staff()`), porque a família ganha `view DIARIO` para o portal.

**Tech Stack:** PHP 8.1+ puro, MariaDB (InnoDB, utf8mb4), PHPUnit 11 (`tools/phpunit.phar`), JS sem framework em `public/assets/js/` (CSP estrita). GD é opcional.

**Spec:** `docs/superpowers/specs/2026-11-18-diario-design.md` (e a geral `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`, Módulo 6).

## Global Constraints

- PHP 8.1+, `declare(strict_types=1);`; sem Composer/Node em produção; nenhuma biblioteca nova.
- CSP: nenhum `<script>` inline, `style=""` nem `onclick=` (cobrado por `tests/Unit/ViewsCspTest.php`). Toda saída passa por `e()`. Textos em pt-BR; datas com `fmt_date`.
- "Hoje"/"agora" = `date('Y-m-d')` / `date('Y-m-d H:i')` (fuso America/Sao_Paulo); a lógica de data recebe `$today` por parâmetro.
- Migração única: `database/migrations/012_diary.sql`. Sem seeds.
- **Nada julga a alimentação:** nenhum cálculo de calorias/nutrientes a partir do diário, nenhuma nota, cor de certo/errado ou comparação automática com o plano. A comparação é só visual, lado a lado.
- Tipos (ENUM, nesta ordem): `alimentacao`, `agua`, `sono`, `atividade`, `tela`, `medidas`. Rótulos: "Alimentação", "Água", "Sono", "Atividade ou brincadeira", "Tempo de tela", "Peso e medidas". Nutricionais (nutricionista): `alimentacao`, `agua`.
- Refeições (ENUM, nesta ordem): `cafe_manha`, `lanche_manha`, `almoco`, `lanche_tarde`, `jantar`, `ceia`, `outro`. Rótulos: "Café da manhã", "Lanche da manhã", "Almoço", "Lanche da tarde", "Jantar", "Ceia", "Outro".
- Dias da semana: máscara de bits em `weekdays TINYINT UNSIGNED`, bit `N−1` para `date('N')` (segunda = 1, domingo = 64; todo dia = 127). Rótulos curtos: "seg", "ter", "qua", "qui", "sex", "sáb", "dom".
- Limites: orientação 255; o que comeu 1000; atividade 120; comentário 500. Água 0–30 copos; atividade 1–600 min; tela 0–1440 min; qualidade do sono 1–3 (1 "mal" 😟, 2 "mais ou menos" 😐, 3 "bem" 😊). Datas de pedido: no máximo 5 anos antes ou depois de hoje; `end_date ≥ start_date`.
- Um por dia: `agua`, `sono`, `tela`. Vários por dia: `alimentacao`, `atividade`. `medidas` não tem registro próprio.
- Janela da família: registrar, corrigir e apagar só para `today − 7 ≤ day ≤ today`.
- Sigilo: `secret = 1` só gravado por `medico` e paciente com menos de 18 anos hoje (`Age::years(birth_date, today) < 18`); regra R6 dos medicamentos: nunca desligado implicitamente, só a médica desmarca; o formulário manda `secret_shown=1` quando o checkbox foi exibido, e `secret` só é aplicado se `secret_shown` veio. Sigilo vale retroativamente para tudo do pedido.
- Visibilidade (único ponto, `DiaryRequestRepository::canSee(array $request, array $viewer): bool`): `medico` e `paciente` → sim; `responsavel` → `secret = 0`; `nutricionista` → `secret = 0` e tipo nutricional; demais → não. Pedido excluído → não para todos. O vínculo com o paciente (liberação da nutricionista, vínculo da família) é checado pelo Gate/PatientScope nos controllers.
- Permissões: `Policy::DIARIO = 'diario'` novo; `medico` vê e edita; `paciente` e `responsavel` só `view` (o registro da família é regra do repositório, como as marcações de metas). Nutricionista não ganha `DIARIO`: como em `GoalController::allowed()`, a médica usa `Policy::DIARIO` e a nutricionista usa `Policy::NUTRICAO` do paciente liberado. Telas da equipe: papel `medico` ou `nutricionista` checado **antes** do Gate (403 auditado `denied` com `['acao' => 'equipe', 'section' => 'diario']`). Papel `crianca`: a guarda `childarea` já redireciona; não liberar rotas novas em `ChildArea`.
- Auditoria (`Container::audit()->record(uid, action, table, rowId, patientId, before, after, $r)`): `create`/`update`/`end`/`delete` em `diary_requests`; `create`/`update`/`delete` em `diary_entries`; `create`/`update`/`delete` em `diary_comments`; leitura das telas da equipe `('view', 'diary', null, patientId, null, ['section' => 'diario'])`, e no portal com `'portal' => true`. Download de foto não é auditado.
- Exclusões sempre lógicas (`deleted_at`, `deleted_by`). Arquivo de foto nunca é apagado do disco; a rota não serve foto de registro excluído.
- Os testes não dependem da GD (o teste de redução usa `markTestSkipped` sem `extension_loaded('gd')`).
- Testes: `source tools/env.sh; start_mariadb` e depois `php tools/phpunit.phar`. Toda rota GET nova entra em `tests/Http/SmokeAllRoutesTest.php::EXPECTED` na mesma tarefa (6 colunas: M, N, S, R, P, C).
- Commits em português (`feat:`, `fix:`, `docs:`, `test:`), terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nunca adicionar `o que fazer.txt`.

## Review Focus

1. **Rastro de pedido sigiloso para o responsável ou a nutricionista:** cartão "Diário" no portal quando o único pedido é sigiloso; contagem "X de 7 dias"; foto pedida pela URL com id adivinhado; comentário; registro feito antes de o pedido virar sigiloso. Tudo invisível, e as rotas por id respondem 404. Testes nas Tasks 1, 2, 4 e 5 (`test_secret_request_leaves_no_trail`).
2. **Registro em dia não permitido:** dia em que o pedido não vale (dia da semana desmarcado, antes do início, depois do fim), dia futuro, mais de 7 dias atrás, pedido excluído, tipo `medidas`. Recusar com mensagem clara, sem gravar (portal: 422). Teste na Task 2.
3. **Duplo envio em tipo de um por dia** (dois "Salvar" de água, ou mãe e pai salvando no mesmo dia): fica um registro só, com o último valor e o último autor. Teste na Task 2 (`test_one_per_day_upserts`).
4. **Foto inválida:** PDF renomeado para `.jpg`, arquivo acima do limite, erro de upload. Mensagem clara e nada gravado (nem registro, nem arquivo). Teste na Task 2.
5. **Sono atravessando a meia-noite** (dormiu 23:00, acordou 06:30) e cochilo (13:00–15:00): os dois aceitos e mostrados como "23:00–06:30" e "13:00–15:00"; dormiu = acordou é recusado. Teste na Task 2.

---

### Task 1: Tabelas, permissão e repositório de pedidos (com visibilidade)

**Files:**
- Create: `database/migrations/012_diary.sql`, `app/Diary/DiaryType.php`, `app/Diary/InvalidDiaryException.php`, `app/Diary/DiaryRequestRepository.php`
- Modify: `app/Access/Policy.php` (seção `DIARIO`; médico vê/edita; paciente e responsável veem; atualizar o docblock), `app/Core/Container.php`, `tests/Unit/PolicyTest.php`, `tests/Integration/MigratorTest.php` (12 migrações)
- Test: `tests/Integration/DiaryRequestRepositoryTest.php`

**Interfaces:**
- Tabelas:
  - `diary_requests`: `id`, `patient_id`, `type ENUM(...)`, `weekdays TINYINT UNSIGNED`, `start_date DATE`, `end_date DATE NULL`, `guidance VARCHAR(255) NULL`, `secret TINYINT(1) DEFAULT 0`, `created_by`, `created_at`, `updated_by NULL`, `updated_at NULL`, `deleted_at NULL`, `deleted_by NULL`, `KEY (patient_id)`, FKs para `patients` e `users`.
  - `diary_entries`: `id`, `request_id`, `day DATE`, `meal ENUM(...) NULL`, `meal_time TIME NULL`, `description VARCHAR(1000) NULL`, `glasses TINYINT UNSIGNED NULL`, `slept_at TIME NULL`, `woke_at TIME NULL`, `sleep_quality TINYINT UNSIGNED NULL`, `activity VARCHAR(120) NULL`, `minutes SMALLINT UNSIGNED NULL`, `photo_path VARCHAR(255) NULL`, `photo_mime VARCHAR(30) NULL`, `photo_size INT UNSIGNED NULL`, `recorded_by`, `recorded_by_role VARCHAR(20)`, `created_at`, `updated_by NULL`, `updated_at NULL`, `deleted_at NULL`, `deleted_by NULL`, `KEY (request_id, day)`.
  - `diary_comments`: `id`, `entry_id`, `body VARCHAR(500)`, `created_by`, `created_at`, `updated_at NULL`, `deleted_at NULL`, `deleted_by NULL`, `KEY (entry_id)`.
- `App\Diary\DiaryType` (final, só constantes e funções puras): `TYPES`, `LABELS`, `NUTRITIONAL`, `ONE_PER_DAY = ['agua','sono','tela']`, `MEALS`, `MEAL_LABELS`, `WEEKDAY_LABELS` (1..7 → "seg".."dom"), `ALL_DAYS = 127`; `static weekdaysLabel(int $mask): string` ("todo dia" para 127; senão "seg, qua, sex").
- `App\Diary\InvalidDiaryException extends \RuntimeException` com `errors(): list<string>` (mesmo formato de `InvalidMedicationException`).
- `App\Diary\DiaryRequestRepository(Database, AuditLog)`, exposto por `Container::diaryRequests()`:
  - `create(int $patientId, array $data, array $user, string $today, Request $r): int`. `$data`: `type, weekdays (int|list<int> de 1..7), start_date, end_date, guidance, secret, secret_shown`.
  - `update(int $id, array $data, array $user, string $today, Request $r): void`. Com registros não excluídos, mudar `type` lança "O tipo não pode mudar depois que há registros.". Sigilo pela regra R6.
  - `end(int $id, int $uid, string $today, Request $r): void`: `end_date = today`; pedido futuro (`start_date > today`) é recusado ("Pedido que ainda não começou: exclua em vez de encerrar.").
  - `delete(int $id, int $uid, Request $r): void`: lógica; com registros não excluídos lança "Este pedido já tem registros; encerre em vez de excluir.".
  - `find(int $id): ?array` (inclui excluídos).
  - `appliesOn(array $request, string $ymd): bool`: `start_date ≤ ymd ≤ (end_date ?? ∞)` e bit do dia da semana marcado.
  - `canSee(array $request, array $viewer): bool` (regra da Global Constraints); `visibleTo(int $id, array $viewer): ?array`.
  - `mayEdit(array $request, array $user): bool`: `medico` sempre; `nutricionista` só tipo nutricional e `secret = 0`.
  - `forViewer(int $patientId, array $viewer, string $from, string $to): list<array>`: pedidos visíveis, não excluídos, cujo período cruza `[from, to]`, ordenados por `DiaryType::TYPES` e depois `start_date`.
  - `listForStaff(int $patientId, array $viewer, string $today): array{current: list<array>, ended: list<array>}` (vigentes e futuros / encerrados por `end_date` decrescente).
  - `mayMarkSecret(array $user, int $patientId, string $today): bool` (mesmo predicado de `PrescriptionRepository::mayMarkSecret`).
  - `hasEntries(int $id): bool`.
- Validação: `type ∈ TYPES`; nutricionista só cria/edita tipo nutricional ("Este tipo de pedido é só da médica."); `weekdays` entre 1 e 127 (nenhum dia → "Escolha pelo menos um dia da semana."); datas válidas no intervalo de ±5 anos; `end_date ≥ start_date`; `guidance` aparada, até 255. **Sobreposição:** outro pedido não excluído do mesmo paciente e tipo, com períodos que se cruzam (fim nulo = sem fim) e `weekdays & weekdays ≠ 0` → "Já existe um pedido de {rótulo} para esses dias.".

- [ ] **Step 1: Escrever os testes** em `DiaryRequestRepositoryTest` (estende `Tests\DbTestCase`; use `makeUser`/`makePatient`):
  - `test_create_and_applies_on_weekdays`: pedido `medidas` só segunda (máscara 1), início 2026-11-02 → `appliesOn` true em 2026-11-09 (segunda), false em 2026-11-10 e em 2026-11-01; auditoria `create`.
  - `test_overlap_is_rejected_but_disjoint_days_allowed`: `agua` seg–sex e depois `agua` só sábado/domingo → aceito; `agua` só quarta → recusado; mesmo tipo com período encerrado antes → aceito.
  - `test_type_locked_and_delete_blocked_with_entries` (insira um `diary_entries` direto no banco).
  - `test_end_rules`: encerrar ativo grava `end_date = today` e audita `end`; futuro recusado.
  - `test_secret_rules`: médico + 15 anos → 1; médico + 18 anos completos hoje → 0; nutricionista → 0; R6: update sem `secret_shown` mantém 1; médico com `secret_shown=1` e sem `secret` → 0.
  - `test_visibility_rules`: matriz de `canSee` para os 6 papéis × (sigiloso, tipo `sono`, tipo `agua`); excluído some para todos; `forViewer` respeita a regra.
  - `test_nutritionist_may_only_touch_nutritional_types` (`create` de `sono` lança; `mayEdit` false em `sono` e em sigiloso).
  - Em `PolicyTest`: `paciente`/`responsavel` têm `view DIARIO` e não têm `edit`; `medico` tem ambos; `nutricionista`, `secretaria` e `crianca` não têm `view`.
- [ ] **Step 2:** Rodar `php tools/phpunit.phar tests/Integration/DiaryRequestRepositoryTest.php`. Esperado: FAIL (classe inexistente).
- [ ] **Step 3:** Escrever a migração, as classes, `Container::diaryRequests()` e a mudança na `Policy`.
- [ ] **Step 4:** Rodar os testes acima, `MigratorTest` e depois a suíte inteira. Esperado: tudo OK (ajuste só asserções de outros testes que falharem porque a família passou a ver `DIARIO`).
- [ ] **Step 5:** Commit `feat: tabelas e repositorio de pedidos do diario com sigilo e visibilidade`.

### Task 2: Registros, fotos e comentários (repositórios)

**Files:**
- Create: `app/Diary/DiaryEntryRepository.php`, `app/Diary/DiaryPhotoStore.php`, `app/Diary/DiaryCommentRepository.php`, `app/Diary/DiaryFormat.php`
- Modify: `app/Core/Container.php`
- Test: `tests/Integration/DiaryEntryRepositoryTest.php`, `tests/Integration/DiaryCommentRepositoryTest.php`, `tests/Unit/DiaryFormatTest.php`, `tests/Unit/DiaryPhotoStoreTest.php`

**Interfaces:**
- Consumes: Task 1 (`find`, `appliesOn`, `canSee`, `DiaryType`).
- `App\Diary\DiaryPhotoStore(string $uploadsDir, bool $allowNonUploaded = false)`, exposto por `Container::diaryPhotos()` com a mesma pasta de uploads de `AttachmentStore`:
  - `store(array $file): array{path:string, mime:string, size:int}`: valida erro de upload, tamanho (≤ `AttachmentStore::MAX_BYTES` e ≤ `upload_max_filesize`; mensagem com `AttachmentStore::limitMegabytes`) e tipo pelo conteúdo (`finfo`): `image/jpeg`, `image/png`, `image/webp` ("Envie uma foto em JPEG, PNG ou WebP."). Com `extension_loaded('gd')`, reduz para no máximo 1280 px no maior lado (não amplia) e regrava em JPEG qualidade 82 (mime vira `image/jpeg`); sem GD, move o original. Caminho relativo `diario/AAAA/MM/{32 hex}.{ext}`, permissão 0640. Lança `InvalidDiaryException`.
  - `discard(string $path): void` (apaga o arquivo recém-gravado quando a gravação no banco falhar).
  - `response(array $entry): Response`: serve inline com `Content-Type` do registro, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`; arquivo ausente → 404.
- `App\Diary\DiaryEntryRepository(Database, AuditLog, DiaryRequestRepository, DiaryPhotoStore)`, exposto por `Container::diaryEntries()`:
  - `record(int $requestId, string $day, array $data, ?array $photo, array $user, string $today, Request $r): int`. Só `paciente`/`responsavel` com `canSee`; pedido não excluído, `appliesOn(day)`, janela de 7 dias, tipo ≠ `medidas`. Em `ONE_PER_DAY`, se já existe registro não excluído do pedido no dia, atualiza esse registro (audita `update`, `updated_by`, `recorded_by` passa a ser quem salvou, `recorded_by_role` idem) e devolve o id dele; a checagem e a escrita ficam numa transação com `SELECT ... FOR UPDATE` na linha do pedido.
  - `update(int $entryId, array $data, ?array $photo, bool $removePhoto, array $user, string $today, Request $r): void`. Janela de 7 dias sobre o `day` do registro; vários por dia → só o autor (`recorded_by`); um por dia → qualquer família que `canSee`. Não exige `appliesOn`. Foto nova substitui a anterior (o arquivo antigo fica no disco).
  - `remove(int $entryId, array $user, string $today, Request $r): void` (mesmas regras de `update`; lógica).
  - `find(int $entryId): ?array` (com `patient_id`, `type` e `secret` do pedido); `visibleTo(int $entryId, array $viewer): ?array` (registro e pedido não excluídos e `canSee`).
  - `forRange(int $patientId, array $viewer, string $from, string $to): list<array>`: registros visíveis não excluídos, por `day`, depois `meal` na ordem de `MEALS` e `meal_time`, depois `id`; cada um com `recorder_name`.
  - Campos por tipo (`$data`): `alimentacao` → `meal` (obrigatório), `meal_time` (opcional, HH:MM), `description` (obrigatório, até 1000); `agua` → `glasses`; `sono` → `slept_at`, `woke_at` (obrigatórios, HH:MM, diferentes: "O horário de acordar deve ser diferente do de dormir."), `sleep_quality`; `atividade` → `activity` (obrigatório), `minutes`; `tela` → `minutes`. Foto só em `alimentacao`. Campos de outros tipos são ignorados. Mensagens em pt-BR, ex.: "Este dia não está entre os pedidos da equipe.", "Só dá para anotar até 7 dias atrás.", "Não dá para anotar dias futuros.".
- `App\Diary\DiaryCommentRepository(Database, AuditLog, DiaryEntryRepository, DiaryRequestRepository)`, exposto por `Container::diaryComments()`:
  - `add(int $entryId, string $body, array $user, Request $r): int` (só `medico`/`nutricionista` que `canSee` e com `mayEdit` no pedido para a nutricionista; corpo aparado, 1..500).
  - `update(int $commentId, string $body, array $user, Request $r): void` e `delete(int $commentId, array $user, Request $r): void` (só a autora).
  - `forEntries(list<int> $entryIds): array<int, list<array>>` (não excluídos, por `created_at`, com `author_name`).
- `App\Diary\DiaryFormat` (funções puras): `minutes(int $m): string` ("45 min", "1h", "1h20"); `cell(string $type, list<array> $entries, bool $measured): ?string` ("6 copos"/"1 copo"; "23:00–06:30 😊"; "1h20 de atividade" somando os minutos; "1h20 de tela"; "3 refeições"/"1 refeição"; `medidas` → "pesou" quando `$measured`); `sleepRange(string $slept, string $woke): string`.

- [ ] **Step 1: Escrever os testes**:
  - `DiaryEntryRepositoryTest`: `test_record_food_with_several_per_day`; `test_one_per_day_upserts` (responsável A grava 5 copos, B grava 6 → um registro, 6 copos, `recorded_by = B`); `test_rejects_day_not_asked_future_too_old_deleted_request_and_medidas` (cada caso lança e nada é gravado); `test_sleep_across_midnight_and_nap_and_equal_times`; `test_update_and_remove_rules` (vários por dia: outro membro não altera; um por dia: outro membro altera; fora da janela recusa; auditoria `update`/`delete`); `test_secret_request_leaves_no_trail` (registro feito antes de virar sigiloso some de `visibleTo`/`forRange` para responsável e nutricionista; continua para médico e paciente); `test_invalid_photo_saves_nothing` (PDF com nome `.jpg` → exceção, nenhuma linha e nenhum arquivo novo em `diario/`).
  - `DiaryPhotoStoreTest`: `test_rejects_pdf_disguised_and_oversize_and_upload_error`; `test_keeps_original_without_gd` (pule se GD carregada); `test_resizes_with_gd` (pule sem GD: PNG 3000×1000 vira JPEG 1280×427).
  - `DiaryCommentRepositoryTest`: `test_staff_comments_and_family_reads`; `test_nutritionist_cannot_comment_on_sleep_or_secret`; `test_only_author_edits_or_deletes`.
  - `DiaryFormatTest`: `minutes(45) === '45 min'`, `minutes(60) === '1h'`, `minutes(80) === '1h20'`; os textos de `cell` de cada tipo, inclusive singular.
- [ ] **Step 2:** Rodar os quatro testes. Esperado: FAIL.
- [ ] **Step 3:** Implementar as classes e os métodos no `Container`.
- [ ] **Step 4:** Rodar os quatro testes e `DiaryRequestRepositoryTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: registros do diario com foto opcional e comentarios da equipe`.

### Task 3: Telas da equipe — pedidos e cartão da ficha

**Files:**
- Create: `app/Diary/DiaryController.php`, `app/Diary/views/request_form.php`
- Modify: `app/routes.php`, `app/Patients/PatientController.php` (cartão) e `app/Patients/views/show.php` (cartão "Diário" depois de "Tratamento"), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/DiaryRequestPagesTest.php`

**Interfaces:**
- Consumes: Task 1. Siga `GoalController` (`staff()`, `allowed()` com `Policy::DIARIO` para médica e `Policy::NUTRICAO` para nutricionista, `deny()` com `section 'diario'`) e `PrescriptionController` (formulário, `secret_shown`).
- Rotas (`$authMw`):
  - `GET|POST /pacientes/{id}/diario/pedidos/novo`: formulário com tipo (select só com os tipos que o usuário pode criar), dias da semana (7 checkboxes `weekdays[]` com valores 1..7 e um atalho "Todo dia" — o atalho é JS em arquivo próprio em `public/assets/js/`, e sem JS o usuário marca os 7), início (padrão hoje), fim, orientação, sigiloso (só quando `mayMarkSecret` ou já sigiloso). Sucesso → `/pacientes/{id}/diario` com "Pedido criado.". Erro → 422 com as mensagens.
  - `GET|POST /diario/pedidos/{requestId}/editar`: mesmo formulário; com registros, o select de tipo aparece desabilitado e o servidor recusa a mudança. Pedido não visível ou sem `mayEdit` → 404 (não visível) ou 403 (visível sem permissão).
  - `POST /diario/pedidos/{requestId}/encerrar` e `POST /diario/pedidos/{requestId}/excluir` (com `confirm-submit.js`, como nas prescrições): redirecionam para `/pacientes/{id}/diario` com a mensagem ou o erro.
- Ficha do paciente (só para médica e nutricionista): cartão "Diário" com os rótulos dos pedidos vigentes visíveis e "{filled} de {asked} dias nos últimos 7" (Task 4 fornece a contagem; nesta tarefa mostre só os rótulos e o link "Abrir diário" para `/pacientes/{id}/diario`). Sem pedidos visíveis: "Nenhum pedido de registro." e o link "Pedir registros".
- `GET /pacientes/{id}/diario` nesta tarefa pode mostrar só a lista de pedidos (vigentes/futuros e encerrados recolhidos em `<details>`) com "Novo pedido", "Editar", "Encerrar"; a grade entra na Task 4.
- Smoke: `/pacientes/{id}/diario` e `/pacientes/{id}/diario/pedidos/novo` → `[200, 200, 403, 403, 403, 302]`; `/diario/pedidos/{diaryRequestId}/editar` → `[200, 200, 403, 403, 403, 302]` com a fixture `diaryRequest` (tipo `agua`, não sigiloso, no paciente liberado para a nutricionista).

- [ ] **Step 1: Escrever os testes** em `DiaryRequestPagesTest` (padrão de `tests/Http/PrescriptionPagesTest.php`):
  - `test_doctor_creates_edits_ends_request` (auditoria e redirecionamentos).
  - `test_nutritionist_sees_only_nutritional_types_in_form_and_list` (o select não oferece `sono`; um pedido `sono` não aparece na lista dela; editar o `sono` dá 404).
  - `test_family_and_secretary_get_403_before_gate` (auditoria `denied` com `section 'diario'`).
  - `test_validation_errors_return_422` (nenhum dia marcado; sobreposição).
  - `test_secret_checkbox_only_for_doctor_and_minor`.
  - `test_patient_card_lists_requests`.
- [ ] **Step 2:** Rodar `php tools/phpunit.phar tests/Http/DiaryRequestPagesTest.php`. Esperado: FAIL.
- [ ] **Step 3:** Implementar controller, view, rotas, cartão e linhas do smoke.
- [ ] **Step 4:** Rodar `DiaryRequestPagesTest`, `SmokeAllRoutesTest`, `ViewsCspTest` e `PrescriptionPagesTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: pedidos de registro do diario nas telas da equipe`.

### Task 4: Grade semanal, refeições ao lado do plano, comentários e fotos na equipe

**Files:**
- Create: `app/Diary/DiaryWeek.php`, `app/Diary/views/week.php`
- Modify: `app/Diary/DiaryController.php`, `app/Nutrition/MealPlanRepository.php` (`onDate`), `app/routes.php`, `app/Patients/PatientController.php` + `show.php` (contagem no cartão), `public/assets/css/app.css` (grade com rolagem própria), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/DiaryWeekPagesTest.php`, `tests/Integration/DiaryWeekTest.php`, `tests/Integration/MealPlanRepositoryTest.php` (caso novo)

**Interfaces:**
- Consumes: Tasks 1–3; `_plan_body.php` (com `$family = false`) para mostrar o plano.
- `MealPlanRepository::onDate(int $patientId, string $ymd): ?array`: plano com `status IN ('publicado','substituido','encerrado')`, `DATE(published_at) ≤ ymd`, `superseded_at` nulo ou `DATE(superseded_at) > ymd`, `ended_at` nulo ou `DATE(ended_at) > ymd`; o de `published_at` mais recente; decodificado como `current()`.
- `App\Diary\DiaryWeek(DiaryRequestRepository, DiaryEntryRepository, Database)`, exposto por `Container::diaryWeek()`:
  - `static monday(string $ymd): string` (segunda da semana da data; data inválida → segunda da semana de hoje é responsabilidade do controller).
  - `grid(int $patientId, array $viewer, string $monday): array{days: list<string>, rows: list<array{request: array, cells: array<string, array{asked: bool, entries: list<array>, label: ?string}>}>}`. Linha por pedido visível cujo período cruza a semana. `label` = `DiaryFormat::cell(...)`; `medidas` olha `measurements` com `source = 'familia'`, `measured_on = dia` e `deleted_at IS NULL`. Célula com registro mostra o rótulo mesmo se `asked = false`.
  - `lastSevenDays(int $patientId, array $viewer, string $today): array{filled: int, asked: int}`: dias de `today−6` a `today`; `asked` = dias com algum pedido visível que vale; `filled` = desses, os dias em que **todos** os pedidos que valem têm registro (ou medida, em `medidas`).
- `GET /pacientes/{id}/diario?semana=AAAA-MM-DD` (inválida/ausente → semana atual): título "Semana de dd/mm a dd/mm", links "Semana anterior"/"Próxima semana"; tabela da grade (cabeçalho "seg 09/11"…; célula com rótulo, "—" quando pedido sem registro, vazia quando não pedido) dentro de um contêiner com rolagem horizontal própria; depois, dia a dia (só dias com registros), os registros completos com quem anotou, comentários e o formulário "Comentar" (para quem pode). Nos dias com alimentação, duas colunas: "Anotado pela família" e "Plano alimentar" (`onDate`; sem plano: "Sem plano alimentar publicado neste dia."). Abaixo, a lista de pedidos da Task 3. Leitura auditada.
- `POST /diario/registros/{entryId}/comentar`, `POST /diario/comentarios/{commentId}/editar`, `POST /diario/comentarios/{commentId}/excluir`: redirecionam para `/pacientes/{id}/diario?semana={segunda do registro}#dia-{AAAA-MM-DD}`. Registro não visível → 404.
- `GET /diario/registros/{entryId}/foto`: equipe que `canSee` e passa no Gate do paciente → `DiaryPhotoStore::response`; senão 404 (não visível) ou 403 (papel). Registro excluído ou sem foto → 404.
- Cartão da ficha: acrescenta "{filled} de {asked} dias nos últimos 7" (omitido quando `asked = 0`).
- Smoke: `/diario/registros/{diaryEntryId}/foto` → `[200, 200, 403, 403, 403, 302]` (fixture `diaryEntry`: alimentação com foto PNG pequena no pedido `alimentacao` da fixture, criado nesta tarefa).

- [ ] **Step 1: Escrever os testes**:
  - `DiaryWeekTest`: `test_grid_cells_asked_empty_and_filled` (pedido só segunda: terça vazia, segunda sem registro "—"); `test_entry_outside_new_days_still_shows`; `test_last_seven_days_counts_only_fully_filled_days`; `test_counts_respect_viewer` (nutricionista não conta `sono`; responsável não conta sigiloso); `test_medidas_cell_uses_family_measurement`.
  - `MealPlanRepositoryTest::test_on_date_returns_plan_valid_that_day` (plano A publicado 01/11 e substituído por B em 10/11 → 05/11 dá A, 10/11 dá B; antes de 01/11, null).
  - `DiaryWeekPagesTest`: `test_week_page_shows_grid_and_plan_side_by_side` (estruturado mostra nome da refeição do plano; texto mostra o texto); `test_invalid_week_param_falls_back_to_current`; `test_comment_flow_and_redirect_anchor`; `test_photo_route_visibility` (médica 200; nutricionista 200 em alimentação; responsável 403; registro excluído 404; registro de pedido sigiloso para nutricionista 404); `test_page_never_shows_calories_from_diary` (o HTML da coluna "Anotado pela família" não contém "kcal").
- [ ] **Step 2:** Rodar os testes. Esperado: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar os testes acima, `DiaryRequestPagesTest`, `SmokeAllRoutesTest` e `ViewsCspTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: grade semanal do diario com plano do dia e comentarios`.

### Task 5: Diário no portal da família

**Files:**
- Create: `app/Diary/PortalDiaryController.php`, `app/Portal/views/diary.php`, `app/Portal/views/diary_entry_form.php`
- Modify: `app/routes.php`, `app/Portal/PortalController.php` (`home`: `hasDiary`) e `app/Portal/views/home.php` (cartão "Diário"), `public/assets/css/app.css` se preciso, `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/PortalDiaryTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2 e 4 (`DiaryFormat`). Siga `PortalTreatmentController` (abertura do paciente: Gate `view DIARIO` com o `id` da URL, redirecionamento do `guard()` do portal, `FAMILY`/`STAFF`).
- Rotas (`$authMw`):
  - `GET /portal/p/{id}/diario?dia=AAAA-MM-DD` (fora da janela/inválido → hoje): título "O que a Dra. Rubia pediu para anotar"; seletor de dia (hoje e os 7 anteriores, como links "hoje", "ontem", "seg 09/11"…); para cada pedido visível que vale no dia: rótulo, orientação da equipe, registros do dia (com comentários: "Comentário de {nome} em dd/mm: …") e o botão "Anotar refeição"/"Anotar atividade" (vários por dia) ou "Anotar"/"Corrigir" (um por dia). Pedido `medidas`: "Pesar hoje" com link para `/portal/p/{id}/medidas/nova` e "Peso anotado ✔" se já há medida da família no dia. Sem pedidos no dia: "Nada para anotar neste dia.". Leitura auditada com `'portal' => true`.
  - `GET|POST /portal/p/{id}/diario/{requestId}/anotar?dia=…`: formulário do tipo (`enctype="multipart/form-data"` em alimentação; ajuda "Fotografe só o prato."; sono com "Dormiu às", "Acordou às" e "Como dormiu?" em 3 rádios com carinhas). Em um por dia já registrado, o formulário vem preenchido. Sucesso → `/portal/p/{id}/diario?dia=…` com "Anotado.". Erro → 422 com as mensagens. Pedido não visível ou de outro paciente → 404.
  - `GET|POST /portal/p/{id}/diario/registros/{entryId}/editar` (com "Remover foto" em alimentação) e `POST /portal/p/{id}/diario/registros/{entryId}/remover` (com `confirm-submit.js`). Sem permissão pela regra da Task 2 → 404.
  - `GET /portal/p/{id}/diario/fotos/{entryId}`: registro visível do paciente da URL → foto; senão 404.
- Início do portal: cartão "Diário" ("Anote o que a equipe pediu.") só quando há pedido visível que vale em algum dia entre `today−7` e `today`.
- Smoke: `/portal/p/{id}/diario`, `/portal/p/{id}/diario/{diaryRequestId}/anotar`, `/portal/p/{id}/diario/registros/{diaryEntryId}/editar` e `/portal/p/{id}/diario/fotos/{diaryEntryId}` → mesma linha de `/portal/p/{id}/tratamento` hoje (`[302, 302, 302, 200, 200, 302]`; confira o arquivo antes). Ajuste a fixture `diaryEntry` para que o responsável `R` seja o autor e o dia esteja dentro da janela, ou documente no comentário da linha se o editar responder diferente para `P`.

- [ ] **Step 1: Escrever os testes** em `PortalDiaryTest`:
  - `test_guardian_records_water_and_corrects` (um registro só depois de corrigir).
  - `test_guardian_records_meal_with_photo_and_sees_it` (foto PNG pequena; rota da foto 200 para o responsável).
  - `test_secret_request_leaves_no_trail` (cartão do início some quando o único pedido é sigiloso; `anotar` e foto pelo id dão 404; comentário não aparece).
  - `test_day_outside_window_or_not_asked_returns_422`.
  - `test_only_author_edits_meal_but_anyone_corrects_water`.
  - `test_comment_appears_for_family`.
  - `test_medidas_request_links_to_measure_form`.
  - `test_child_account_is_redirected_from_diary`.
  - `test_adult_patient_sees_secret_request`.
- [ ] **Step 2:** Rodar os testes. Esperado: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar `PortalDiaryTest`, `PortalTest`, `SmokeAllRoutesTest` e `ViewsCspTest`. Esperado: PASS.
- [ ] **Step 5:** Commit `feat: diario no portal com registros, fotos e comentarios da equipe`.

### Task 6: Documentação e pacote

**Files:**
- Modify: `README.md` (nova seção "Diário de hábitos" depois de "Tratamento e medicamentos"; no §9, item 10 com: backup do banco; a migração `012_diary.sql` e, para quem usa phpMyAdmin, `INSERT INTO schema_migrations (name) VALUES ('012_diary.sql');`; extração dentro de `rubia_app`; cópia do **conteúdo** de `public/` para `public_html/rubia.med.br/` porque CSS/JS mudaram; **depois da cópia, refazer a linha `// AJUSTE NO DEPLOY` do `index.php`** com `$APP_ROOT = '/home2/USUARIO/rubia_app';`, porque a cópia substitui o arquivo; nota de que, se a hospedagem tiver a extensão GD (cPanel → "Selecionar versão do PHP" → extensões), as fotos ficam menores), `docs/superpowers/backlog-fase3c.md` (criar com os pontos menores adiados)
- Test: suíte inteira

- [ ] **Step 1:** Escrever a seção do README em linguagem simples para a médica: criar um pedido (tipo, dias, orientação); a nutricionista só pede alimentação e água; sigiloso (só menores, só a médica); a grade da semana e o que "—" significa; refeições ao lado do plano; comentar; a família anota hoje e até 7 dias atrás; fotos (só do prato); "pesar toda segunda" usa a tela de medidas; o diário não calcula nem julga nada.
- [ ] **Step 2:** Rodar a suíte inteira, `php tools/phpunit.phar`. Esperado: OK.
- [ ] **Step 3:** Rodar `php tools/build_release.php` e conferir que o zip contém `database/migrations/012_diary.sql` e `app/Diary/`. Não fazer commit do zip.
- [ ] **Step 4:** Commit `docs: diario de habitos no README e atualizacao de versao`.
