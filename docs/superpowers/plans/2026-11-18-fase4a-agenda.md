# Agenda (Fase 4a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Uma agenda por profissional (semana-modelo, fechamentos, horários extras, endereço), marcação de consultas só pela equipe sem choque de horário, botão de WhatsApp com aviso pronto, e-mail da véspera para cada profissional, e-mails para a família e cartões "Consultas" na ficha e no portal.

**Architecture:** Novo módulo `app/Agenda/` no padrão de `app/Diary/` e `app/Medications/`: repositórios com `Database` + `AuditLog`, controllers finos, views PHP. Uma classe pura (`SlotCalculator`) calcula os horários de um dia a partir da semana-modelo, dos fechamentos e dos extras; `AgendaAccess` é o único ponto que decide quem vê/mexe em qual agenda. Choque de horário é impedido por sobreposição de intervalos dentro de transação com trava por profissional, mais um índice único como segunda barreira. A rodada da véspera é uma classe chamada pelo `cron/run.php`.

**Tech Stack:** PHP 8.1+ puro, MariaDB (InnoDB, utf8mb4), PHPUnit 11 (`tools/phpunit.phar`), JS sem framework em `public/assets/js/` (CSP estrita).

**Spec:** `docs/superpowers/specs/2026-11-18-agenda-design.md` (e a geral `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`, Módulo 9). As §9.1–9.9 da spec são decisões tão vinculantes quanto o resto.

## Global Constraints

- PHP 8.1+, `declare(strict_types=1);`; sem Composer/Node em produção; nenhuma biblioteca nova.
- CSP: nenhum `<script>` inline, `style=""` nem `onclick=` (cobrado por `tests/Unit/ViewsCspTest.php`). Toda saída passa por `e()`. Textos em pt-BR; datas com `fmt_date`.
- "Agora" chega por parâmetro (`string $now` em `'Y-m-d H:i'`, ou `$today` `'Y-m-d'`) em toda lógica de data; os controllers passam `date(...)` (fuso America/Sao_Paulo).
- Migração única: `database/migrations/013_agenda.sql`. Sem seeds; `MigratorTest` passa a contar 13.
- Situações (ENUM, nesta ordem): `agendada`, `confirmada`, `realizada`, `faltou`, `cancelada`, `remarcada`, `reservada`. Rótulos: "Agendada", "Confirmada", "Realizada", "Faltou", "Cancelada", "Remarcada", "Reservada". **Ocupam horário:** todas menos `cancelada` e `remarcada`. **Entram em avisos/véspera:** só `agendada`, `confirmada`. Nada cria `reservada` nesta fase.
- Tipos (ENUM): `primeira` ("Primeira consulta"), `retorno` ("Retorno"). Sugestão ao marcar: `retorno` se o paciente tem consulta `realizada` com aquela profissional, senão `primeira`.
- Dias da semana: `weekday` 1..7 = `date('N')` (segunda = 1). Rótulos: "segunda-feira" … "domingo" (longos, no aviso) e "seg" … "dom" (curtos, telas).
- Horário padrão da clínica (botão "Usar horário padrão da clínica", só quando a profissional não tem faixa): seg–sex 07:30–11:30 e 12:30–20:00; sáb 07:30–12:30; duração 30.
- Duração permitida: 10–240 minutos, múltiplo de 5. Faixas: `start < end`, sem sobreposição no mesmo dia. Endereço até 200; motivo de fechamento até 120; observação da consulta até 255; motivo de cancelamento até 255.
- Textos do aviso (configuráveis em `/admin/agenda`, chaves `settings` `agenda_text_adult`, `agenda_text_child`; vazios = padrão):
  - Adulto: `Olá, {nome}! Lembrando da sua consulta com {profissional} {dia}, às {hora}. Endereço: {endereço}. Por favor, confirme sua presença respondendo esta mensagem. Até lá!`
  - Menor (< 18 anos na data da consulta, `Age::years`): `Olá! Lembrando da consulta de {nome} com {profissional} {dia}, às {hora}. Endereço: {endereço}. Por favor, confirme a presença respondendo esta mensagem. Até lá!`
  - `{nome}` = nome social, senão primeiro nome de `full_name`; `{profissional}` = `users.name`; `{hora}` = `H:i` (ex.: `07:30`); `{dia}` = `amanhã, quinta-feira, 19/11` quando a consulta é amanhã, `hoje, quinta-feira, 19/11` quando é hoje, `quinta-feira, 19/11` nos outros casos; `{endereço}` = endereço da profissional, senão `settings.rx_address`, senão o trecho `Endereço: {endereço}.` (e o espaço antes) é removido.
- WhatsApp: número = só dígitos; 10–11 dígitos → prefixa `55`; 12–13 dígitos começando com `55` → igual; outro → inválido (botão não aparece; consulta mostra "telefone em formato inválido"). Link `https://wa.me/<número>?text=<rawurlencode(aviso)>`, nova aba (`target="_blank" rel="noopener"`), texto também copiado pela área de transferência via `public/assets/js/agenda-whatsapp.js` (atributo `data-text`).
- Rodada da véspera: a partir das **08:00**, uma vez por dia (chave `settings` `agenda_reminder_day` = `Y-m-d` processado), para as consultas do dia seguinte; uma transação coloca tudo na fila e grava a chave; dias perdidos não são reenviados.
- Destinatários da família: adulto → `patients.email`, senão e-mail da conta (`patients.user_id`); menor → um e-mail para cada responsável ativo com e-mail; endereços repetidos (sem diferença de maiúsculas) uma vez só; sem e-mail → nada.
- Assuntos de e-mail (exatos): "Consulta marcada — {dd/mm/aaaa} às {HH:MM}", "Consulta cancelada — {dd/mm/aaaa} às {HH:MM}", "Consulta remarcada — {novo dd/mm/aaaa} às {HH:MM}", "Lembrete: consulta amanhã às {HH:MM}", e para a profissional "Avisos das consultas de {dd/mm/aaaa}". E-mail da família nunca leva a observação interna nem o motivo de cancelamento.
- Permissões: em `Policy`, `nutricionista` ganha `view`/`edit` `AGENDA`; `paciente` e `responsavel` ganham `view` `AGENDA`; `crianca` nada. Quem vê/mexe em **qual agenda** é decidido só por `AgendaAccess` (tabela da spec §5.9). Rotas da equipe exigem papel `medico`, `nutricionista` ou `secretaria` **antes** do resto (403 auditado `denied` com `['section' => 'agenda']`). Não liberar rotas novas em `ChildArea`.
- Auditoria (`Container::audit()->record(...)`): `create`/`update`/`cancel`/`reschedule`/`status` em `appointments`; `update` em `professional_schedules` (semana-modelo, duração, endereço); `create`/`delete` em `schedule_exceptions`; `update` em `settings` (textos). Abrir agenda não audita leitura.
- Exclusões lógicas (`deleted_at`, `deleted_by`) em exceções e faixas; consulta nunca é excluída (cancela).
- Testes: `source tools/env.sh; start_mariadb` e depois `php tools/phpunit.phar`. Toda rota GET nova entra em `tests/Http/SmokeAllRoutesTest.php::EXPECTED` na mesma tarefa (6 colunas: M, N, S, R, P, C).
- Commits em português (`feat:`, `fix:`, `docs:`, `test:`), terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nunca adicionar `o que fazer.txt`.

## Review Focus

1. **Choque por mudança de duração ou clique duplo:** consulta 08:00–08:30 marcada; a profissional muda a duração para 45 (grade 07:30, 08:15…); marcar 08:15 deve ser recusado ("Este horário não está mais livre."), e duas marcações seguidas no mesmo horário deixam uma só. Testes na Task 2 (`test_overlap_after_duration_change_is_refused`, `test_same_slot_twice_keeps_one`).
2. **Nutricionista e paciente não liberado:** marcar recusado com a mensagem da spec; paciente cuja liberação foi revogada depois de marcado aparece na agenda dela como "Paciente sem liberação", sem nome nem link, e a rota da consulta responde 404 para ela. Testes nas Tasks 1 e 3.
3. **Telefone em formatos reais:** "(11) 98765-4321", "+55 11 98765-4321", "11 3456-7890", "98765-4321" (sem DDD → inválido), "" → sem botão. Teste na Task 4 (`test_phone_formats`).
4. **Rodada da véspera repetida ou fora de hora:** Cron às 07:55 não envia; às 08:00 e 08:05 envia uma vez só; sábado manda os avisos de domingo; consulta cancelada não entra; profissional sem consulta não recebe. Teste na Task 5 (`test_reminder_round_runs_once_after_eight`).
5. **Fechamento por cima de consulta marcada:** a consulta continua ocupando o horário, aparece destacada "em período fechado", aceita cancelar/remarcar, e o horário não aparece como livre para outra marcação. Testes nas Tasks 1 e 2.

---

### Task 1: Tabelas, permissões, horários das profissionais e cálculo da grade

**Files:**
- Create: `database/migrations/013_agenda.sql`, `app/Agenda/AgendaStatus.php`, `app/Agenda/InvalidAgendaException.php`, `app/Agenda/SlotCalculator.php`, `app/Agenda/ScheduleRepository.php`, `app/Agenda/AgendaAccess.php`
- Modify: `app/Access/Policy.php` (AGENDA para nutricionista/paciente/responsável; docblock), `app/Core/Container.php`, `tests/Unit/PolicyTest.php`, `tests/Integration/MigratorTest.php` (13)
- Test: `tests/Unit/SlotCalculatorTest.php`, `tests/Integration/ScheduleRepositoryTest.php`, `tests/Integration/AgendaAccessTest.php`

**Interfaces:**
- Tabelas:
  - `professional_schedules`: `professional_id INT UNSIGNED PK` (FK users), `slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30`, `address VARCHAR(200) NULL`, `updated_by NULL`, `updated_at NULL`. Também é a linha de trava (`SELECT … FOR UPDATE`) da agenda.
  - `schedule_ranges`: `id`, `professional_id`, `weekday TINYINT UNSIGNED`, `start_time TIME`, `end_time TIME`, `created_by`, `created_at`, `deleted_at NULL`, `deleted_by NULL`, `KEY (professional_id, weekday)`.
  - `schedule_exceptions`: `id`, `professional_id`, `kind ENUM('fechado','extra')`, `start_date DATE`, `end_date DATE`, `start_time TIME NULL`, `end_time TIME NULL`, `reason VARCHAR(120) NULL`, `created_by`, `created_at`, `deleted_at NULL`, `deleted_by NULL`, `KEY (professional_id, start_date)`.
  - `appointments`: `id`, `professional_id`, `patient_id`, `starts_at DATETIME`, `ends_at DATETIME`, `kind ENUM('primeira','retorno')`, `status ENUM(...)` (Global Constraints) `DEFAULT 'agendada'`, `note VARCHAR(255) NULL`, `cancel_reason VARCHAR(255) NULL`, `rescheduled_from_id NULL`, `rescheduled_to_id NULL`, `status_changed_at DATETIME NULL`, `created_by`, `created_at`, `updated_by NULL`, `updated_at NULL`, `occupies TINYINT AS (IF(status IN ('cancelada','remarcada'), NULL, 1)) PERSISTENT`, `UNIQUE KEY uq_appt_slot (professional_id, starts_at, occupies)`, `KEY (patient_id, starts_at)`, `KEY (professional_id, starts_at)`, FKs.
  - `ALTER TABLE users ADD phone VARCHAR(30) NULL` (celular do responsável).
- `App\Agenda\AgendaStatus` (constantes + funções puras): `STATUSES`, `LABELS`, `OCCUPYING`, `REMINDABLE = ['agendada','confirmada']`, `KINDS`, `KIND_LABELS`, `WEEKDAY_LONG`, `WEEKDAY_SHORT`, `CLINIC_DEFAULT` (faixas padrão), `DEFAULT_MINUTES = 30`.
- `App\Agenda\InvalidAgendaException extends \RuntimeException` com `errors(): list<string>` (formato de `InvalidDiaryException`).
- `App\Agenda\SlotCalculator` (pura, estática):
  - `daySlots(string $ymd, list<array{weekday:int,start_time:string,end_time:string}> $ranges, list<array> $exceptions, int $minutes): list<array{start:string,end:string,source:string}>` — horários `H:i` da semana-modelo do dia mais os `extra` que cobrem `$ymd`, em passos de `$minutes`, último começando até `fim − minutes`; remove os que se sobrepõem a qualquer `fechado` que cubra `$ymd` (sem horário = dia inteiro). `source` = `'modelo'|'extra'`. Fechamento vence extra. Ordenados, sem duplicados.
  - `overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool` (intervalos meio-abertos).
  - `closedOn(string $ymd, string $start, string $end, list<array> $exceptions): bool`.
- `App\Agenda\ScheduleRepository(Database, AuditLog)`, `Container::schedules()`:
  - `profile(int $professionalId): array{slot_minutes:int,address:?string}` (cria a linha com padrão se faltar — `INSERT IGNORE`).
  - `ranges(int $professionalId): list<array>`; `saveWeek(int $professionalId, list<array{weekday,start_time,end_time}> $ranges, int $minutes, ?string $address, int $uid, Request $r): void` (troca as faixas: exclusão lógica das antigas + inserção; valida limites, sobreposição "As faixas de {dia} se sobrepõem.", `start < end`; audita `update` com antes/depois).
  - `applyClinicDefault(int $professionalId, int $uid, Request $r): void` (só se não houver faixa; senão lança "Esta agenda já tem horários.").
  - `exceptions(int $professionalId, string $from, string $to): list<array>`; `addClosure(int $professionalId, string $startDate, string $endDate, ?string $startTime, ?string $endTime, ?string $reason, int $uid, Request $r): int`; `addExtra(int $professionalId, string $date, string $startTime, string $endTime, int $uid, Request $r): int`; `removeException(int $id, int $uid, Request $r): void`. Datas ±2 anos de hoje, `end ≥ start`, horários ambos ou nenhum (fechamento) / obrigatórios (extra).
  - `slotsOn(int $professionalId, string $ymd): list<array>` = `SlotCalculator::daySlots` com os dados do banco.
  - `bookedInClosure(int $professionalId, string $startDate, string $endDate, ?string $startTime, ?string $endTime): int` (consultas que ocupam horário dentro do fechamento — para o aviso "há N consultas marcadas nesse período").
- `App\Agenda\AgendaAccess(Database, PatientScope)`, `Container::agendaAccess()`:
  - `professionals(): list<array{id,name,role}>` (ativos, `medico`/`nutricionista`, por nome).
  - `agendasFor(array $user): list<array>` — secretária e médica admin: todas; médica/nutricionista: só a própria; outros: [].
  - `canView(array $user, int $professionalId): bool` (= pertence a `agendasFor`); `canManage` = `canView`.
  - `canEditWeek(array $user, int $professionalId): bool` — a própria (médica/nutricionista) ou médica admin.
  - `canAddClosure(array $user, int $professionalId): bool` — `canEditWeek` ou secretária.
  - `mayBookPatient(int $professionalId, int $patientId, array $user): ?string` — `null` se pode; senão a mensagem: sem acesso pelo `PatientScope` de quem marca → "Paciente não encontrado."; profissional de destino nutricionista sem `professional_access` ativo → "Peça à médica para liberar este paciente para a nutricionista.".
  - `patientVisibleOnAgenda(int $professionalId, int $patientId): bool` (nutricionista: liberação ativa; médica: sempre).
  - `canEditTexts(array $user): bool` (médica admin).

- [ ] **Step 1: Escrever os testes.**
  - `SlotCalculatorTest`: `test_clinic_default_monday` (seg 07:30–11:30 e 12:30–20:00, 30 min → 8 + 15 = 23 horários; primeiro 07:30, último da manhã 11:00, último 19:30); `test_saturday_and_sunday` (sáb 10 horários até 12:00; dom 0); `test_extra_adds_and_closure_wins` (extra domingo 09:00–10:00 → 2; fechamento domingo dia inteiro por cima → 0; fechamento 08:00–09:00 numa segunda remove 08:00 e 08:30 só); `test_duration_45_steps` (07:30, 08:15, 09:00…); `test_overlaps_half_open` (08:00–08:30 e 08:30–09:00 → false).
  - `ScheduleRepositoryTest`: `test_save_week_validates_overlap_and_order`; `test_apply_clinic_default_only_when_empty`; `test_closure_and_extra_rules` (horário só de um lado → erro; extra sem horário → erro; remover é lógico e audita); `test_booked_in_closure_counts_occupying_only` (insira `appointments` direto: uma agendada e uma cancelada no período → 1).
  - `AgendaAccessTest`: matriz de `agendasFor`/`canEditWeek`/`canAddClosure` para médica admin, médica comum, nutricionista, secretária, responsável; `test_nutritionist_booking_requires_release` (sem `professional_access` → mensagem exata; com liberação → null; revogada → `patientVisibleOnAgenda` false).
  - `PolicyTest`: `nutricionista` view/edit AGENDA; `paciente`/`responsavel` só view; `crianca` nada.
- [ ] **Step 2:** Rodar os três testes. Esperado: FAIL (classes inexistentes).
- [ ] **Step 3:** Escrever migração, classes, `Container::schedules()`/`agendaAccess()` e a mudança na `Policy`.
- [ ] **Step 4:** Rodar os testes, `MigratorTest` e a suíte inteira. Esperado: OK (ajuste só asserções de outros testes que falharem pela nova permissão AGENDA da família/nutricionista).
- [ ] **Step 5:** Commit `feat: tabelas da agenda, horarios das profissionais e calculo da grade`.

### Task 2: Consultas (repositório)

**Files:**
- Create: `app/Agenda/AppointmentRepository.php`
- Modify: `app/Core/Container.php`
- Test: `tests/Integration/AppointmentRepositoryTest.php`

**Interfaces:**
- Consumes: `ScheduleRepository::slotsOn/profile`, `AgendaAccess::mayBookPatient/canManage`, `AgendaStatus`.
- `App\Agenda\AppointmentRepository(Database, AuditLog, ScheduleRepository, AgendaAccess)`, `Container::appointments()`:
  - `book(int $professionalId, int $patientId, string $startsAt /* 'Y-m-d H:i' */, string $kind, ?string $note, array $user, string $now, Request $r, ?int $rescheduledFrom = null): int` — em transação: `profile()` (garante linha) e `SELECT … FROM professional_schedules WHERE professional_id = ? FOR UPDATE`; recusa (lança `InvalidAgendaException`) se: `!canManage` ("Você não pode marcar nesta agenda."), `mayBookPatient` ≠ null (a mensagem), `startsAt ≤ now` ("Escolha um horário a partir de agora."), `startsAt` não é início de um horário de `slotsOn` ("Este horário não está aberto na agenda."), sobrepõe consulta que ocupa horário ("Este horário não está mais livre."), `kind` inválido, nota > 255. `ends_at = starts_at + slot_minutes` vigente. Violação do índice único também vira "Este horário não está mais livre.". Audita `create`. Devolve o id. **Não envia e-mail** (Task 5 envolve).
  - `find(int $id): ?array` (com `patient_name`, `social_name`, `birth_date`, `professional_name`, `professional_role`).
  - `setStatus(int $id, string $status, array $user, string $now, Request $r): void` — `confirmada` a partir de `agendada` (antes do fim da consulta); `realizada`/`faltou` a partir de `agendada`/`confirmada` só quando `date(starts_at) ≤ today`; voltar para `agendada` a partir de `realizada`/`faltou` só quando `today == date(starts_at)`; qualquer outra transição → "Esta mudança de situação não é permitida."; grava `status_changed_at`; audita `status` com antes/depois.
  - `cancel(int $id, string $reason, array $user, string $now, Request $r): void` — só `agendada`/`confirmada`; motivo obrigatório 1–255 ("Informe o motivo do cancelamento."); audita `cancel`.
  - `reschedule(int $id, int $newProfessionalId, string $newStartsAt, array $user, string $now, Request $r): int` — numa transação: valida a antiga (só `agendada`/`confirmada`), `book(...)` no destino com o mesmo paciente, tipo e nota e `rescheduledFrom`, antiga vira `remarcada` com `rescheduled_to_id`; trava as duas agendas em ordem de id para não travar em círculo; audita `reschedule`. Devolve o novo id.
  - `forDay(int $professionalId, string $ymd): list<array>` e `forRange(int $professionalId, string $from, string $to): list<array>` (todas as situações, por `starts_at`).
  - `forPatient(int $patientId, ?int $onlyProfessionalId, string $now): array{upcoming: list<array>, past: list<array>}` (futuras que ocupam horário por data crescente; passadas — inclusive canceladas — as últimas 5, decrescentes).
  - `suggestedKind(int $professionalId, int $patientId): string`.
  - `remindable(string $ymd): list<array>` (consultas `agendada`/`confirmada` do dia, com dados do paciente e da profissional, por profissional e hora).
  - `dayView(int $professionalId, string $ymd, string $now): list<array{start:string,end:string,state:string,appointment:?array,flags:list<string>}>` — junta `slotsOn` e `forDay`: `state` ∈ `livre`, `marcado`, `passado` (horário livre já passado); consultas fora da grade atual aparecem na posição da hora com `flags` `fora_do_horario` e/ou `em_fechamento`; canceladas/remarcadas aparecem riscadas sem ocupar.

- [ ] **Step 1: Escrever os testes** (`AppointmentRepositoryTest`, `DbTestCase`, `$now` fixo `2026-11-16 09:00` — segunda — e semana-modelo padrão):
  - `test_book_only_open_future_slots` (passado, horário fechado, 08:10 fora do passo → mensagens exatas; 10:00 → ok, `ends_at` 10:30, auditoria `create`).
  - `test_same_slot_twice_keeps_one`.
  - `test_overlap_after_duration_change_is_refused` (Review Focus 1).
  - `test_closure_over_booked_appointment_keeps_it` (Review Focus 5: fechamento depois de marcar → `dayView` mostra `em_fechamento`, o horário não fica livre, `cancel` funciona e então o horário fica fechado, não livre).
  - `test_status_transitions` (cada transição permitida e uma proibida de cada tipo, com `$now` na véspera e no dia).
  - `test_cancel_and_reschedule` (motivo obrigatório; remarcar para outra profissional nutricionista sem liberação → recusa e nada muda; com liberação → antiga `remarcada` com `rescheduled_to_id`, nova com `rescheduled_from_id`, horário antigo livre).
  - `test_suggested_kind_and_for_patient`.
  - `test_remindable_only_agendada_and_confirmada`.
- [ ] **Step 2:** Rodar. Esperado: FAIL.
- [ ] **Step 3:** Implementar `AppointmentRepository` e `Container::appointments()`.
- [ ] **Step 4:** Rodar o teste e a suíte. Esperado: OK.
- [ ] **Step 5:** Commit `feat: consultas da agenda sem choque de horario, situacoes e remarcacao`.

### Task 3: Telas da equipe — agenda, marcar, consulta e "Meus horários"

**Files:**
- Create: `app/Agenda/AgendaController.php`, `app/Agenda/ScheduleController.php`, `app/Agenda/views/day.php`, `app/Agenda/views/week.php`, `app/Agenda/views/book.php`, `app/Agenda/views/appointment.php`, `app/Agenda/views/reschedule.php`, `app/Agenda/views/schedule.php`
- Modify: `app/routes.php`, `app/Layout/views/layout.php` (menu "Agenda" para `medico`/`nutricionista`/`secretaria`), `public/assets/css/app.css`, `tests/Http/SmokeAllRoutesTest.php` (fixture `appointment` e rotas novas)
- Test: `tests/Http/AgendaPagesTest.php`, `tests/Http/SchedulePagesTest.php`

**Interfaces:**
- Consumes: Tasks 1–2.
- Rotas (todas com o middleware de equipe autenticada já usado nas rotas de staff; papel checado antes):
  - `GET /agenda?prof={id}&data={Y-m-d}&vista=dia|semana` — padrão: primeira agenda de `agendasFor` (a própria, para profissional), hoje, dia. Seletor de profissional só quando `agendasFor` > 1. Navegação ◀ ▶ por dia/semana. `prof` sem permissão → 403 auditado.
  - `GET /agenda/marcar?prof&inicio={Y-m-d H:i}` (formulário: busca de paciente por nome entre os que `mayBookPatient` aceita — até 20 resultados, `?q=`; tipo pré-selecionado por `suggestedKind`; observação) e `POST /agenda/marcar`. Também `GET /pacientes/{id}/agendar?prof` mostra os próximos 10 horários livres (próximos 60 dias) dessa profissional para o paciente.
  - `GET /agenda/consultas/{apptId}` (detalhes e ações: confirmar, realizada, faltou, desfazer, cancelar com motivo, remarcar, abrir ficha; para médica e consulta realizada, atalho "registrar evolução" para a tela de evolução existente); `POST /agenda/consultas/{apptId}/situacao`, `POST /agenda/consultas/{apptId}/cancelar`; `GET|POST /agenda/consultas/{apptId}/remarcar` (escolhe profissional permitida e data, lista horários livres).
  - `GET|POST /agenda/horarios?prof={id}` ("Meus horários": faixas por dia, duração, endereço de atendimento, botão "Usar horário padrão da clínica" quando vazio; lista de fechamentos/extras dos próximos 12 meses com remover; formulários de novo fechamento e novo extra; após criar fechamento com consultas dentro, mensagem "Há N consultas marcadas nesse período. Elas continuam na agenda para você remarcar."). Secretária vê a página só com fechamentos (sem editar semana). `POST /agenda/horarios/fechamento`, `POST /agenda/horarios/extra`, `POST /agenda/horarios/excecao/{id}/remover`, `POST /agenda/horarios/padrao`.
- Consulta de nutricionista para paciente sem liberação: na agenda dela aparece "Paciente sem liberação" (sem nome, sem link, sem WhatsApp); `GET /agenda/consultas/{id}` → 404 para ela (Review Focus 2).
- Na grade, o botão de WhatsApp é um **espaço reservado** (`<!-- whatsapp -->` ou parcial vazio) preenchido na Task 4.

- [ ] **Step 1: Escrever os testes.**
  - `AgendaPagesTest`: `test_secretary_books_on_doctor_agenda` (GET agenda com seletor; POST marcar → 302 para a agenda do dia; consulta aparece com nome); `test_professional_sees_only_own_agenda` (médica comum com `prof` de outra → 403; nutricionista sem seletor); `test_book_errors_show_422` (horário ocupado/fechado/passado → 422 com mensagem e nada gravado); `test_status_cancel_reschedule_flow`; `test_nutritionist_unreleased_patient_hidden` (Review Focus 2); `test_family_and_child_get_403_or_redirect`.
  - `SchedulePagesTest`: `test_apply_default_and_edit_week`; `test_secretary_adds_closure_but_cannot_edit_week` (POST da semana → 403); `test_closure_warns_about_booked`; `test_admin_edits_other_professional_week`.
  - `SmokeAllRoutesTest`: novas rotas GET com códigos esperados para M N S R P C (família e criança: 403/302 conforme o padrão das outras rotas de equipe; nutricionista em `/agenda/consultas/{apptId}` de consulta com a médica → 404).
- [ ] **Step 2:** Rodar. Esperado: FAIL.
- [ ] **Step 3:** Implementar controllers, views, rotas, menu e CSS.
- [ ] **Step 4:** Rodar os testes, `ViewsCspTest` e a suíte. Esperado: OK.
- [ ] **Step 5:** Commit `feat: telas da agenda, marcacao e horarios das profissionais`.

### Task 4: Texto do aviso, WhatsApp, textos configuráveis e celular do responsável

**Files:**
- Create: `app/Agenda/ReminderText.php`, `app/Agenda/Phone.php`, `app/Agenda/AgendaSettingsController.php`, `app/Agenda/views/settings.php`, `app/Agenda/views/_whatsapp.php`, `public/assets/js/agenda-whatsapp.js`
- Modify: `app/Agenda/views/day.php`, `week.php`, `appointment.php` (incluir `_whatsapp.php`), `app/Patients/GuardianController.php` e suas views (campo "Celular" opcional, até 30, no vincular e um formulário "Editar celular" por responsável na lista; grava em `users.phone`; audita `update` em `users` com antes/depois só do telefone), `app/routes.php`, `app/Layout/views/layout.php` (menu "Agenda (avisos)" → `/admin/agenda` para médica admin), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Unit/ReminderTextTest.php`, `tests/Unit/PhoneTest.php`, `tests/Http/AgendaWhatsappTest.php`

**Interfaces:**
- `App\Agenda\Phone::wa(string $raw): ?string` (regra das Global Constraints).
- `App\Agenda\ReminderText(Database)`, `Container::reminderText()`:
  - `templates(): array{adult:string,child:string}` (settings ou padrão).
  - `render(array $appointment, string $today): string` — `$appointment` como `AppointmentRepository::find`; escolhe adulto/menor pela idade na data da consulta; placeholders e regra do endereço das Global Constraints.
  - `static dayPhrase(string $apptYmd, string $today): string` ("amanhã, quinta-feira, 19/11" / "hoje, …" / "quinta-feira, 19/11").
  - `contacts(array $appointment): list<array{label:string,wa:?string,raw:string}>` — "WhatsApp — paciente" (telefone da ficha) e "WhatsApp — {nome}" para cada responsável ativo com `users.phone`; `wa` null quando inválido; sem telefone nenhum → lista vazia.
- `GET|POST /admin/agenda` (médica admin; outros 403): dois campos de texto (até 1000) com os placeholders explicados e "Restaurar texto padrão"; salva em `settings` (vazio = padrão); audita.
- `_whatsapp.php`: para consulta `agendada`/`confirmada` e paciente visível na agenda, um `<a class="btn-whatsapp" href="https://wa.me/..." target="_blank" rel="noopener" data-text="...">` por contato válido; "telefone em formato inválido" para os inválidos; nada quando sem contato. `agenda-whatsapp.js`: no clique, `navigator.clipboard.writeText(data-text)` (falha silenciosa) e deixa o link abrir; mostra "Mensagem copiada" ao lado por 3 s.

- [ ] **Step 1: Escrever os testes.**
  - `PhoneTest::test_phone_formats` (Review Focus 3: "(11) 98765-4321" → "5511987654321"; "+55 11 98765-4321" → "5511987654321"; "11 3456-7890" → "551134567890"; "98765-4321" → null; "" → null).
  - `ReminderTextTest`: `test_adult_default_text_exact` (consulta amanhã com "Dra. Rubia Teste", 19/11/2026 quinta, 07:30, endereço "Rua A, 10" → exatamente `Olá, Ana! Lembrando da sua consulta com Dra. Rubia Teste amanhã, quinta-feira, 19/11, às 07:30. Endereço: Rua A, 10. Por favor, confirme sua presença respondendo esta mensagem. Até lá!`); `test_child_text_and_social_name`; `test_address_fallback_and_removal` (endereço da profissional > `rx_address` > trecho removido, sem espaço duplo); `test_day_phrase` (hoje/amanhã/outro); `test_custom_template_from_settings`.
  - `AgendaWhatsappTest`: botões para paciente e responsável com celular, `href` com `wa.me/55…?text=` e `data-text` igual ao `render`; inválido mostra o aviso; cancelada não mostra botão; `/admin/agenda` salva, 403 para secretária; celular do responsável salvo e auditado.
- [ ] **Step 2:** Rodar. Esperado: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar os testes, `ViewsCspTest` e a suíte. Esperado: OK.
- [ ] **Step 5:** Commit `feat: aviso de consulta com botao de whatsapp e textos configuraveis`.

### Task 5: E-mails para a família e rodada da véspera

**Files:**
- Create: `app/Agenda/AgendaMailer.php`, `app/Agenda/ReminderRound.php`
- Modify: `app/Agenda/AgendaController.php` (após `book`/`cancel`/`reschedule` bem-sucedidos, chamar `AgendaMailer`), `cron/run.php` (novo bloco `try` independente, como o do backup), `app/Core/Container.php`
- Test: `tests/Integration/AgendaMailerTest.php`, `tests/Integration/ReminderRoundTest.php`

**Interfaces:**
- `App\Agenda\AgendaMailer(Database, Mailer, ReminderText)`, `Container::agendaMailer()`:
  - `familyRecipients(array $appointment): list<string>` (regra das Global Constraints).
  - `booked(int $id, string $today): void`, `cancelled(int $id, string $today): void`, `rescheduled(int $oldId, int $newId, string $today): void` — põem na fila um e-mail por destinatário, com os assuntos exatos; corpo HTML simples escapado: marcada/remarcada → "Sua consulta com {profissional} está marcada para {dia da semana}, {dd/mm/aaaa}, às {HH:MM}." + endereço quando houver; cancelada → "A consulta com {profissional} de {dia da semana}, {dd/mm/aaaa}, às {HH:MM} foi cancelada. Para marcar outro horário, fale com a clínica."; para menor, "a consulta de {nome}". Nunca a observação nem o motivo.
- `App\Agenda\ReminderRound(Database, AppointmentRepository, ReminderText, AgendaMailer, Mailer)`, `Container::reminderRound()`:
  - `runIfDue(string $now): int` — se `H:i < 08:00` ou `settings.agenda_reminder_day == date(now)` → 0. Senão, numa transação: para amanhã, `remindable(amanhã)`; por profissional (com e-mail), um e-mail "Avisos das consultas de {dd/mm/aaaa}" com, por consulta em ordem de hora, a hora, o nome do paciente, os números (`contacts`, formato legível) e o texto `ReminderText::render(appt, hoje)` em `<pre>` para copiar; por consulta, o lembrete da família "Lembrete: consulta amanhã às {HH:MM}" com o mesmo texto do aviso; grava `agenda_reminder_day`; devolve quantos e-mails foram para a fila. Sem consultas → só grava a chave.
  - Em `cron/run.php`: novo bloco `try` independente logo **depois** do envio da fila, instanciando as classes com o `$db` e o `Mailer` do próprio cron (sem `Container`), chamando `runIfDue(date('Y-m-d H:i'))`; os e-mails colocados na fila saem na passada seguinte (5 min).

- [ ] **Step 1: Escrever os testes.**
  - `AgendaMailerTest`: `test_adult_gets_ficha_email_else_account`; `test_minor_each_guardian_once` (dois responsáveis, um sem e-mail, e-mail repetido com maiúsculas → 1 envio por endereço); `test_subjects_exact_and_no_internal_note` (nota "pagar na recepção" e motivo não aparecem no corpo); `test_controller_queues_on_book_cancel_reschedule` (pelos endpoints da Task 3).
  - `ReminderRoundTest::test_reminder_round_runs_once_after_eight` (Review Focus 4: 07:55 → 0; 08:00 → profissional + família na fila; 08:05 → 0; consulta cancelada fora; profissional sem consulta não recebe); `test_saturday_sends_sunday`; `test_professional_email_contains_text_and_numbers` (corpo contém o `render` exato e "5511987654321").
- [ ] **Step 2:** Rodar. Esperado: FAIL.
- [ ] **Step 3:** Implementar e ligar no controller e no cron.
- [ ] **Step 4:** Rodar os testes e a suíte. Esperado: OK. Rodar também `php cron/run.php` localmente uma vez (com o ambiente de dev) e conferir saída 0.
- [ ] **Step 5:** Commit `feat: e-mails da agenda para a familia e avisos da vespera para a profissional`.

### Task 6: Cartões "Consultas" na ficha e no portal

**Files:**
- Create: `app/Agenda/views/_patient_card.php`
- Modify: `app/Patients/PatientController.php` + `app/Patients/views/show.php` (cartão "Consultas" quando `Gate::check(user,'view',AGENDA,patientId)`: próximas e últimas 5 — médica e secretária com todas as profissionais; nutricionista só as dela; botão "Agendar" → `/pacientes/{id}/agendar` para quem pode marcar), `app/Portal/PortalController.php` + `app/Portal/views/home.php` (cartão "Consultas" para paciente e responsável ativo: data, hora, profissional, situação — próximas e últimas 5; sem ações; nada para a conta da criança)
- Test: `tests/Http/AgendaCardsTest.php`

**Interfaces:**
- Consumes: `AppointmentRepository::forPatient`, `AgendaStatus::LABELS`.
- O cartão do portal mostra só situação legível ("Agendada", "Confirmada", "Realizada", "Faltou", "Cancelada", "Remarcada") e nunca `note`/`cancel_reason`.

- [ ] **Step 1: Escrever os testes** (`AgendaCardsTest`): `test_staff_card_by_role` (nutricionista vê só as dela; secretária vê todas; botão "Agendar"); `test_portal_card_for_family` (responsável vê; nota e motivo ausentes no HTML; outra família não vê); `test_child_account_has_no_card`.
- [ ] **Step 2:** Rodar. Esperado: FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar os testes e a suíte. Esperado: OK.
- [ ] **Step 5:** Commit `feat: cartoes de consultas na ficha e no portal`.

### Task 7: Documentação e pacote

**Files:**
- Modify: `README.md` (seção "Agenda": horários de cada profissional, botão de padrão, fechamentos, WhatsApp, e-mail da véspera às 08:00, textos em /admin/agenda; §9 novo item de atualização: backup, migração `013_agenda.sql` por `migrate.php` ou phpMyAdmin + `INSERT INTO schema_migrations (name) VALUES ('013_agenda.sql');`, extrair em `rubia_app`, copiar o conteúdo de `public/` para `public_html/rubia.med.br/` e **depois** refazer a linha `$APP_ROOT = '/home2/quali498/rubia_app'; // AJUSTE NO DEPLOY`, conferir que o Cron de 5 minutos existe, cada profissional entra em "Meus horários" e clica "Usar horário padrão da clínica" ou monta os dela, preencher endereço), versão em `app/` se houver constante de versão (seguir o que a fase 3c fez), `docs/superpowers/backlog-fase4a.md` (criado vazio com cabeçalho, para os pontos adiados da revisão)
- Test: nenhum novo

- [ ] **Step 1:** Escrever a documentação.
- [ ] **Step 2:** Rodar a suíte inteira. Esperado: OK.
- [ ] **Step 3:** Rodar `php tools/build_release.php` e conferir que o pacote contém `database/migrations/013_agenda.sql` e `public/assets/js/agenda-whatsapp.js`.
- [ ] **Step 4:** Commit `docs: agenda no README e atualizacao de versao`.
