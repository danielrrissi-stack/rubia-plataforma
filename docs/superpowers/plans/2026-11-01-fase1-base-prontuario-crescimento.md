# Fase 1 — Base, Prontuário e Crescimento — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar a fundação da plataforma (login por perfil com 2FA, permissões, auditoria, instalador, backup), o Módulo 1 (cadastro e prontuário versionado) e o Módulo 2 (antropometria, curvas OMS com percentil/escore Z, PA, TMB/GET), com portal da família para registrar e ver medidas.

**Architecture:** PHP 8.1+ puro, front controller `public/index.php` → `App\Core\Router` → controllers organizados por funcionalidade (`app/<Feature>/`). Views PHP com escape obrigatório. MySQL via PDO. Toda regra de acesso passa por `App\Access\Gate`; todo dado clínico é imutável (edição = nova versão) e auditado. Cálculos clínicos ficam em classes puras sem banco, cobertas por testes unitários.

**Tech Stack:** PHP 8.3 (dev) / 8.1+ (produção), MariaDB/MySQL (InnoDB, utf8mb4), PHPUnit 11 (phar, só dev), Chart.js 4 (arquivo local), qrcode.js (arquivo local, MIT), CSS próprio.

**Spec:** `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`

**Fora desta fase:** sigilo de itens do adolescente para os responsáveis e a configuração "pais veem o que o adolescente registra" dependem do login do adolescente (Fase 3, Módulo 7); nesta fase só o alerta/decisão de maioridade é construído.

**Fases seguintes (planos separados, depois desta):** Fase 2 = Módulo 3 (plano alimentar) + Módulo 4 (exames); Fase 3 = Módulos 5–7 (medicamentos, diário, metas/área da criança); Fase 4 = Módulos 8–9 (linha do tempo, PDF do prontuário, agenda, mensagens, exportação LGPD).

## Global Constraints

- Produção: hospedagem compartilhada cPanel, PHP 8.1+, MySQL/MariaDB; deploy por upload de arquivos + import SQL. **Nenhum Composer/Node em produção**; bibliotecas de terceiros ficam versionadas em `public/assets/vendor/`.
- Código só usa extensões comuns em cPanel: `pdo_mysql`, `openssl`, `zip`, `mbstring`, `json`. Nada de `exec` obrigatório.
- Tudo em pt-BR; fuso `America/Sao_Paulo`; datas exibidas `dd/mm/aaaa`; números aceitam vírgula decimal na entrada (`32,5`).
- HTTPS obrigatório (redirecionamento em `.htaccess` quando `config['force_https']`).
- Senhas com `password_hash(PASSWORD_DEFAULT)`; SQL só com prepared statements; toda saída HTML via `e()`; todo POST com token CSRF.
- Dados clínicos: nunca `UPDATE` de conteúdo nem `DELETE` físico pela aplicação; edição gera nova linha com `previous_id`, a anterior recebe `superseded_at`; exclusão é lógica com `deleted_at` + `deleted_reason`.
- Toda leitura de prontuário por profissional e toda criação/alteração clínica gera linha em `audit_log`.
- Arquivos e backups fora da pasta pública (ou em pasta com `.htaccess` `Require all denied` quando não houver alternativa).
- O sistema **nunca** sugere, calcula ou alerta escalonamento de doses de medicamentos.
- Sem criptografia campo a campo no banco. Backup criptografado com chave pública; chave privada protegida pela senha de backup da médica.
- 2FA (TOTP) obrigatório para `medico`, `nutricionista`, `secretaria`; opcional para família.
- Idade pediátrica (curvas OMS) até 228 meses (19 anos completos, fim da referência OMS 2007); a partir daí, critérios de adulto. *(interpretação do plano: a referência OMS termina aos 19,0 anos; a spec diz "0–19" / "≥ 20".)*

## Review Focus

1. **Vírgula decimal** — "32,5" em peso/altura deve ser salvo como 32.5, e "abc" rejeitado com mensagem; nunca truncado para 32. → teste em Task 2 (`NumTest`).
2. **Troca de ID na URL (IDOR)** — responsável ou nutricionista alterando `/pacientes/{id}` para um paciente não vinculado recebe 403 e a tentativa vai para a auditoria. → teste em Task 6 e Task 15.
3. **Data da medida antes do nascimento ou no futuro** — rejeitada com mensagem, nada é salvo. → teste em Task 15.
4. **Indicador fora da faixa da referência** (peso/idade > 10 anos, peso/comprimento fora de 45–110 cm, perímetro cefálico > 5 anos) — o indicador mostra "não se aplica", sem erro nem valor inventado. → teste em Task 12.
5. **Obesidade grave (Z > +3)** — escore Z calculado pelo método restrito da OMS para indicadores de peso, e valores biologicamente implausíveis sinalizados "verificar medida" em vez de esconder o ponto. → teste em Task 12.

---

## File Structure

```
app/
  bootstrap.php                 autoload PSR-4 (App\ → app/), config, fuso, helpers
  helpers.php                   e(), url(), csrf_field(), old(), fmt_date(), fmt_num()
  Core/      Config.php Database.php Migrator.php Router.php Request.php Response.php
             View.php Session.php Csrf.php HttpException.php Mailer.php App.php
  Support/   Num.php Dates.php
  Auth/      AuthService.php Totp.php Base32.php TokenService.php AuthController.php
             TwoFactorController.php views/
  Audit/     AuditLog.php
  Access/    Policy.php PatientScope.php Gate.php
  Users/     UserRepository.php UserController.php views/
  Patients/  PatientRepository.php GuardianRepository.php AccessGrantRepository.php
             ConsentService.php MajorityService.php PatientController.php
             GuardianController.php AccessGrantController.php views/
  Records/   ClinicalEntryRepository.php RecordController.php views/
  Growth/    Lms.php Age.php WhoReferenceRepository.php GrowthCalculator.php
             NutritionalStatus.php AdultAnthropometry.php BloodPressure.php
             BpReferenceRepository.php Velocity.php MeasurementRepository.php
             GrowthController.php views/
  Energy/    EnergyCalculator.php EnergyGoalRepository.php EnergyController.php views/
  Portal/    PortalController.php views/
  Dashboard/ DashboardController.php views/
  Install/   Requirements.php Installer.php InstallController.php views/
  Backup/    Dumper.php BackupService.php
  Layout/    views/layout.php views/layout_portal.php views/errors/
config/      config.example.php   (config.php e config.test.php são gerados/ignorados no git)
database/    migrations/NNN_nome.sql
data/        who/*.txt  bp/aap2017.csv
public/      index.php .htaccess manifest.json sw.js assets/css/app.css assets/js/app.js
             assets/js/growth-chart.js assets/vendor/chart.umd.min.js assets/vendor/qrcode.min.js
cron/        run.php
tools/       phpunit.phar migrate.php import_who.php import_bp.php restore.php
storage/     .htaccess uploads/ backups/ keys/ logs/   (conteúdo ignorado no git)
tests/       bootstrap.php Unit/... Integration/... Http/...
phpunit.xml  README.md  .gitignore
```

Convenções usadas por todas as tasks:
- Controllers recebem `Request` e retornam `Response`; nunca dão `echo`/`exit`. Isso permite testes HTTP sem servidor (`App::handle(Request): Response`).
- Repositórios recebem `Database` no construtor; métodos retornam arrays associativos (sem ORM).
- `tests/Integration` e `tests/Http` usam banco `rubia_test`, recriado por `tests/bootstrap.php` (drop + migrations) uma vez por execução; cada teste roda dentro de transação revertida no `tearDown` (`DbTestCase`).

---

### Task 0: Ambiente local e esqueleto do projeto

**Files:**
- Create: `.gitignore`, `phpunit.xml`, `tools/phpunit.phar`, `app/bootstrap.php`, `app/helpers.php`, `config/config.example.php`, `tests/bootstrap.php`, `tests/Unit/SmokeTest.php`, `README.md`

**Interfaces:**
- Produces: autoload `App\` → `app/`; `Config::get(string $key, mixed $default = null): mixed` (lê `config/config.php` ou `config/config.test.php` quando `APP_ENV=test`); `tests/DbTestCase.php` é criado na Task 1.

- [ ] **Step 1: Instalar PHP 8.3 e MariaDB localmente** (pedir confirmação ao usuário antes — instala software na máquina)

Run: `winget install --id PHP.PHP.8.3 -e` e `winget install --id MariaDB.Server -e`
Habilitar no `php.ini`: `pdo_mysql`, `openssl`, `zip`, `mbstring`, `fileinfo`, `sodium`. Criar bancos `rubia_dev` e `rubia_test` (utf8mb4_unicode_ci) e usuário `rubia`/senha local.
Expected: `php -v` mostra 8.3.x; `php -m` lista `pdo_mysql`, `openssl`, `zip`; `mysql -u rubia -p -e "show databases"` lista os dois bancos.

- [ ] **Step 2: Baixar PHPUnit 11 phar para `tools/phpunit.phar`** (`https://phar.phpunit.de/phpunit-11.phar`) e criar `phpunit.xml` com suítes `Unit`, `Integration`, `Http`, bootstrap `tests/bootstrap.php`, `APP_ENV=test`.

- [ ] **Step 3: Escrever `tests/Unit/SmokeTest.php`** com `test_autoload_and_config(): assertSame('America/Sao_Paulo', date_default_timezone_get()); assertTrue(class_exists(\App\Core\Config::class));`

- [ ] **Step 4: Rodar e ver falhar** — Run: `php tools/phpunit.phar --testsuite Unit` — Expected: FAIL (classe inexistente).

- [ ] **Step 5: Implementar `app/bootstrap.php`, `app/Core/Config.php`, `config/config.example.php`** (chaves: `db.host`, `db.name`, `db.user`, `db.pass`, `base_url`, `storage_path`, `force_https`, `mail.from`, `mail.from_name`, `session.staff_idle_minutes`=30, `session.family_idle_minutes`=120). `.gitignore`: `config/config.php`, `config/config.test.php`, `storage/*` exceto `.htaccess` e `.gitkeep`.

- [ ] **Step 6: Rodar** — Expected: PASS.

- [ ] **Step 7: Commit** — `git add -A && git commit -m "chore: esqueleto do projeto e ambiente de testes"`

---

### Task 1: Banco de dados, migrações e schema da Fase 1

**Files:**
- Create: `app/Core/Database.php`, `app/Core/Migrator.php`, `tools/migrate.php`, `tests/DbTestCase.php`, `tests/Integration/MigratorTest.php`, `database/migrations/001_users_auth.sql`, `002_audit_mail_settings.sql`, `003_patients.sql`, `004_clinical_entries.sql`, `005_measurements.sql`, `006_references.sql`, `007_energy_goals.sql`

**Interfaces:**
- Produces:
  - `Database::__construct(array $cfg)`; `pdo(): PDO`; `run(string $sql, array $params = []): PDOStatement`; `one(string $sql, array $p = []): ?array`; `all(string $sql, array $p = []): array`; `insert(string $table, array $row): int`; `transaction(callable $fn): mixed`.
  - `Migrator::__construct(Database $db, string $dir)`; `pending(): array`; `migrate(): array` (nomes aplicados; tabela `schema_migrations(name, applied_at)`).
  - `DbTestCase` (extends `TestCase`): `$this->db`, transação em `setUp`/rollback em `tearDown`; helpers `makeUser(string $role, array $over = []): array`, `makePatient(array $over = []): array`.

Schema (colunas exatas; todas as tabelas InnoDB utf8mb4; `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`):
- `users`: id, name, email UNIQUE, password_hash NULL, role ENUM('medico','nutricionista','secretaria','paciente','responsavel','crianca'), is_admin TINYINT DEFAULT 0, professional_register VARCHAR(30) NULL (CRM/CRN), totp_secret VARCHAR(64) NULL, totp_enabled TINYINT DEFAULT 0, failed_logins INT DEFAULT 0, locked_until DATETIME NULL, active TINYINT DEFAULT 1, last_login_at DATETIME NULL, created_at.
- `user_tokens`: id, user_id, purpose ENUM('reset','invite'), token_hash CHAR(64), expires_at, used_at NULL, created_at.
- `audit_log`: id BIGINT, user_id NULL, action VARCHAR(30), entity VARCHAR(50), entity_id BIGINT NULL, patient_id NULL, before_json JSON NULL, after_json JSON NULL, ip VARCHAR(45), user_agent VARCHAR(255), created_at; índices (patient_id, created_at), (user_id, created_at).
- `mail_queue`: id, to_email, subject, body_html, attempts INT DEFAULT 0, sent_at NULL, last_error TEXT NULL, created_at.
- `settings`: `key` VARCHAR(64) PK, value TEXT.
- `patients`: id, user_id NULL UNIQUE (login do adulto), full_name, social_name NULL, birth_date DATE, sex ENUM('M','F') (rótulo "sexo biológico — usado nas curvas"), cpf VARCHAR(14) NULL, cns VARCHAR(20) NULL, phone, email NULL, address TEXT NULL, responsible_professional_id (users.id), admin_notes TEXT NULL, created_by, created_at, updated_at NULL, deleted_at NULL.
- `guardian_links`: id, patient_id, user_id, relationship VARCHAR(30), active TINYINT DEFAULT 1, created_by, created_at, ended_at NULL, ended_by NULL.
- `professional_access`: id, patient_id, professional_id, granted_by, granted_at, revoked_at NULL, revoked_by NULL.
- `majority_decisions`: id, patient_id, decision ENUM('encerrar','transferir','manter'), notes TEXT NULL, decided_by, decided_at.
- `consent_terms`: id, version VARCHAR(10) UNIQUE, body_html MEDIUMTEXT, active TINYINT, created_at. `consents`: id, user_id, patient_id NULL, term_version, accepted_at, ip.
- `clinical_entries`: id, patient_id, kind ENUM('historico_familiar','gestacional','antecedentes','alergias','comorbidades','medicamentos_em_uso','tratamentos_anteriores','evolucao'), payload JSON, cid10 VARCHAR(10) NULL, encounter_date DATE NULL, version INT DEFAULT 1, previous_id NULL, superseded_at NULL, deleted_at NULL, deleted_reason VARCHAR(255) NULL, created_by, created_at.
- `measurements`: id, patient_id, measured_on DATE, weight_kg DECIMAL(6,3) NULL, height_cm DECIMAL(5,1) NULL, position ENUM('deitado','em_pe') NULL, waist_cm DECIMAL(5,1) NULL, hip_cm DECIMAL(5,1) NULL, head_cm DECIMAL(4,1) NULL, systolic SMALLINT NULL, diastolic SMALLINT NULL, source ENUM('equipe','familia'), retroactive TINYINT DEFAULT 0, previous_id NULL, superseded_at NULL, deleted_at NULL, deleted_reason NULL, created_by, created_at.
- `who_lms`: indicator ENUM('bfa','wfa','hfa','wfl','wfh','hcfa'), sex TINYINT (1=M, 2=F), x DECIMAL(7,2), source ENUM('who2006','who2007'), l DOUBLE, m DOUBLE, s DOUBLE; PK (indicator, sex, source, x).
- `bp_reference`: sex TINYINT, age_years TINYINT (1–17), height_pct TINYINT (5,10,25,50,75,90,95), bp_pct TINYINT (50,90,95), systolic SMALLINT, diastolic SMALLINT; PK (sex, age_years, height_pct, bp_pct).
- `energy_goals`: id, patient_id, formula ENUM('mifflin','harris_benedict','fao_oms_1985'), activity_factor DECIMAL(4,3), weight_kg, height_cm, age_years DECIMAL(5,2), tmb_kcal INT, get_kcal INT, notes TEXT NULL, superseded_at NULL, created_by, created_at.

- [ ] **Step 1: Teste `MigratorTest::test_migrates_all_and_is_idempotent`** — banco vazio: `migrate()` retorna 7 nomes; segunda chamada retorna `[]`; `SHOW TABLES` contém `users`, `audit_log`, `clinical_entries`, `measurements`, `who_lms`, `energy_goals`.
- [ ] **Step 2: Rodar e ver falhar** — `php tools/phpunit.phar tests/Integration/MigratorTest.php` → FAIL.
- [ ] **Step 3: Implementar `Database`, `Migrator`, arquivos SQL, `tools/migrate.php`, `tests/DbTestCase.php`, `tests/bootstrap.php`** (dropa e recria `rubia_test`, roda migrações). Migrator divide arquivos por `;` em fim de linha (sem procedures/triggers).
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: banco, migrações e schema da fase 1`

---

### Task 2: HTTP núcleo — Router, Request/Response, View, Session, CSRF, helpers, Num/Dates

**Files:**
- Create: `app/Core/{App,Router,Request,Response,View,Session,Csrf,HttpException}.php`, `app/Support/{Num,Dates}.php`, `app/Layout/views/{layout.php,layout_portal.php,errors/403.php,errors/404.php,errors/500.php}`, `public/index.php`, `public/.htaccess`, `storage/.htaccess`, `public/assets/css/app.css`
- Test: `tests/Unit/NumTest.php`, `tests/Unit/DatesTest.php`, `tests/Unit/RouterTest.php`, `tests/Unit/CsrfTest.php`

**Interfaces:**
- Produces:
  - `Request::__construct(string $method, string $path, array $query = [], array $post = [], array $files = [], array $server = [])`; `static fromGlobals(): Request`; `input(string $k, mixed $d = null)`; `method(): string`; `path(): string`; `ip(): string`; `userAgent(): string`.
  - `Response::html(string $body, int $status = 200)`, `::redirect(string $to)`, `::json(array $data, int $status = 200)`, `::file(string $path, string $mime, string $downloadName = null)`; `send(): void`; props `status`, `headers`, `body`.
  - `Router::get|post(string $pattern, array|callable $handler, array $middleware = [])` com padrões `/pacientes/{id:\d+}`; `dispatch(Request): Response`; params em `$request->params['id']`.
  - `App::handle(Request): Response` — converte `HttpException(403|404)` em páginas de erro; outras exceções → 500 com log em `storage/logs/app.log` (sem detalhes na tela).
  - `View::render(string $template, array $data = [], string $layout = 'Layout/views/layout.php'): string` (template relativo a `app/`).
  - `Session::start(array $cfg)`, `get/set/forget/flash/regenerate()`; cookies `HttpOnly`, `Secure` quando HTTPS, `SameSite=Lax`.
  - `Csrf::token(): string`, `Csrf::check(Request): void` (lança `HttpException(419)`); middleware `csrf` aplicado a todo POST.
  - helpers: `e(?string): string`, `url(string $path): string`, `csrf_field(): string`, `fmt_date(?string $ymd): string` ("dd/mm/aaaa"), `fmt_num(?float, int $dec = 1): string` (vírgula).
  - `Num::parse(?string $raw): ?float` — aceita `"32,5"`, `"32.5"`, `" 1.234,5 "`→1234.5; vazio→null; inválido lança `\InvalidArgumentException`.
  - `Dates::parseBr(string $raw): ?string` — aceita `dd/mm/aaaa` ou `aaaa-mm-dd`, retorna `Y-m-d`; inválido lança `\InvalidArgumentException`.

- [ ] **Step 1: Testes**
  - `NumTest`: `parse("32,5")===32.5`; `parse("32.5")===32.5`; `parse("1.234,5")===1234.5`; `parse("")===null`; `parse("abc")` lança `InvalidArgumentException`; `parse("12,3,4")` lança.
  - `DatesTest`: `parseBr("05/03/2019")==="2019-03-05"`; `parseBr("2019-03-05")==="2019-03-05"`; `parseBr("31/02/2020")` lança.
  - `RouterTest`: rota `/pacientes/{id:\d+}` casa `/pacientes/12` com `params['id']==='12'`; `/pacientes/abc` → `HttpException` 404; método errado → 405.
  - `CsrfTest`: POST sem token → `HttpException` com código 419; com `Csrf::token()` correto passa.
- [ ] **Step 2: Rodar e ver falhar** — `php tools/phpunit.phar --testsuite Unit` → FAIL.
- [ ] **Step 3: Implementar** os arquivos acima. `public/index.php` define `$APP_ROOT = dirname(__DIR__);` numa única linha marcada `// AJUSTE NO DEPLOY` e chama `App::handle(Request::fromGlobals())->send()`. `public/.htaccess`: rewrite para `index.php`, redirect HTTPS, cabeçalhos `X-Content-Type-Options nosniff`, `X-Frame-Options DENY`, `Referrer-Policy same-origin`, `Content-Security-Policy default-src 'self'; img-src 'self' data:`. `storage/.htaccess`: `Require all denied`. Layout responsivo (mobile-first) — usar o skill `frontend-design:frontend-design` para a identidade visual: paleta neutra suave, painel clínico denso, portal acolhedor (spec §8).
- [ ] **Step 4: Rodar** → PASS. Conferir manualmente: `php -S localhost:8000 -t public` abre página 404 estilizada.
- [ ] **Step 5: Commit** — `feat: núcleo HTTP, sessão, CSRF, helpers e layout`

---

### Task 3: Auditoria e fila de e-mails

**Files:**
- Create: `app/Audit/AuditLog.php`, `app/Core/Mailer.php`, `cron/run.php`
- Test: `tests/Integration/AuditLogTest.php`, `tests/Integration/MailerTest.php`

**Interfaces:**
- Produces:
  - `AuditLog::__construct(Database $db)`; `record(?int $userId, string $action, string $entity, ?int $entityId, ?int $patientId = null, ?array $before = null, ?array $after = null, ?Request $req = null): void`. Ações usadas no sistema: `login`, `login_failed`, `logout`, `view`, `create`, `update`, `delete`, `denied`, `grant`, `revoke`, `config`, `export`, `majority_decision`, `consent`. Só faz INSERT; a classe não tem métodos de alteração. `forPatient(int $patientId, int $limit = 200): array`.
  - `Mailer::__construct(Database $db, array $cfg)`; `queue(string $to, string $subject, string $html): int`; `sendPending(int $max = 20): int` (usa `mail()` com `From` de config; até 5 tentativas; grava `last_error`). Em `APP_ENV=test`, `sendPending` usa transporte em memória (`Mailer::$sent`).
  - `cron/run.php`: a cada execução chama `Mailer::sendPending()` (a Task 9 acrescenta `BackupService::runIfDue()`). Uma única linha de Cron no cPanel a cada 5 min.

- [ ] **Step 1: Testes** — `AuditLogTest::test_record_stores_before_after_and_ip` (grava e lê com `forPatient`, JSON decodificado igual); `MailerTest::test_queue_and_send_marks_sent` (1 enfileirado → `sendPending()` retorna 1, `sent_at` preenchido; segunda chamada retorna 0).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: trilha de auditoria e fila de e-mails`

---

### Task 4: Autenticação — login, bloqueio, sessão ociosa, convite e recuperação de senha

**Files:**
- Create: `app/Auth/{AuthService,TokenService,AuthController}.php`, `app/Auth/views/{login,forgot,reset}.php`, `app/Users/UserRepository.php`
- Test: `tests/Integration/AuthServiceTest.php`, `tests/Integration/TokenServiceTest.php`, `tests/Http/LoginFlowTest.php`

**Interfaces:**
- Consumes: `Database`, `AuditLog::record`, `Mailer::queue`, `Session`.
- Produces:
  - `UserRepository`: `find(int $id): ?array`, `findByEmail(string $email): ?array`, `create(array $data): int`, `setPassword(int $id, string $plain): void`, `update(int $id, array $fields): void`, `listStaff(): array`.
  - `AuthService::__construct(Database, UserRepository, AuditLog, Session)`; `attempt(string $email, string $password, Request $r): LoginResult` — `LoginResult` com `ok bool`, `needsTotp bool`, `error ?string`. Regras: 5 falhas seguidas → `locked_until = agora + 15 min`; conta inativa ou bloqueada → erro genérico "E-mail ou senha inválidos." (bloqueio: "Conta bloqueada temporariamente. Tente novamente em 15 minutos."); sucesso zera `failed_logins`, `Session::regenerate()`.
  - `AuthService::user(): ?array` (usuário logado, aplica timeout ocioso: staff 30 min, família 120 min); `logout()`; `requireLogin` middleware `auth`.
  - `TokenService`: `issue(int $userId, string $purpose, int $ttlMinutes): string` (token = `bin2hex(random_bytes(32))`, guarda `hash('sha256')`); `consume(string $token, string $purpose): ?int` (userId, uso único, respeita expiração). TTL: reset 60 min, invite 72 h.
  - Rota provisória `GET /` (middleware `auth`) com página simples "Bem-vindo" — substituída pelo Dashboard na Task 11.
  - Rotas: `GET/POST /login`, `POST /logout`, `GET/POST /senha/esqueci`, `GET/POST /senha/definir/{token}` (serve convite e reset; senha mínima 10 caracteres). "Esqueci a senha" sempre responde a mesma mensagem (não revela se o e-mail existe).

- [ ] **Step 1: Testes**
  - `AuthServiceTest`: senha correta → `ok`; senha errada 5× → 6ª tentativa com senha correta retorna erro de bloqueio; usuário `active=0` → erro genérico; cada falha gera `audit_log.action='login_failed'`.
  - `TokenServiceTest`: `consume` do token emitido retorna o userId; segunda vez retorna null; token expirado retorna null; propósito errado retorna null.
  - `LoginFlowTest` (via `App::handle`): POST `/login` com credenciais válidas → redirect `/`; GET `/` sem login → redirect `/login`; POST `/senha/esqueci` com e-mail inexistente e existente → mesma mensagem, só o existente enfileira e-mail.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: login, bloqueio, convite e recuperação de senha`

---

### Task 5: Verificação em duas etapas (TOTP)

**Files:**
- Create: `app/Auth/{Totp,Base32,TwoFactorController}.php`, `app/Auth/views/{totp_setup,totp_verify}.php`, `public/assets/vendor/qrcode.min.js` (davidshimjs/qrcodejs, MIT)
- Test: `tests/Unit/TotpTest.php`, `tests/Unit/Base32Test.php`, `tests/Http/TwoFactorFlowTest.php`

**Interfaces:**
- Produces:
  - `Base32::encode(string $bin): string`, `decode(string $b32): string`.
  - `Totp::generateSecret(): string` (20 bytes, Base32); `code(string $secretB32, int $timestamp, int $digits = 6): string` (SHA1, passo 30 s); `verify(string $secretB32, string $code, int $timestamp, int $window = 1): bool`; `uri(string $secret, string $email): string` (`otpauth://totp/Dra%20Rubia:{email}?secret=…&issuer=Dra%20Rubia`).
  - Fluxo: após senha correta, se `totp_enabled` → `/2fa` pede código; staff sem `totp_enabled` é forçado a `/2fa/configurar` antes de acessar qualquer outra página (middleware `twofactor`). Família pode ativar em "Minha conta".

- [ ] **Step 1: Testes**
  - `Base32Test`: `encode("12345678901234567890")==="GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"`; roundtrip.
  - `TotpTest` (vetores RFC 6238, SHA1, segredo ASCII "12345678901234567890"): `code($s, 59, 8)==="94287082"`; `code($s, 1111111109, 8)==="07081804"`; `code($s, 1111111109)==="081804"`; `verify` aceita código da janela anterior e rejeita de 2 janelas atrás.
  - `TwoFactorFlowTest`: secretária logada sem 2FA acessando `/` → redirect `/2fa/configurar`; responsável sem 2FA acessando `/` → **não** é redirecionado para `/2fa/configurar`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.** A página de configuração mostra QR (qrcode.js local) e o segredo em texto.
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: verificação em duas etapas TOTP`

---

### Task 6: Permissões — Policy, PatientScope, Gate

**Files:**
- Create: `app/Access/{Policy,PatientScope,Gate}.php`
- Test: `tests/Unit/PolicyTest.php`, `tests/Integration/PatientScopeTest.php`, `tests/Integration/GateTest.php`

**Interfaces:**
- Consumes: `AuditLog::record`.
- Produces:
  - Seções (constantes em `Policy`): `CADASTRO_BASICO` (nome, idade, sexo), `CADASTRO` (contatos, documentos, responsáveis), `AGENDA`, `ANTROPOMETRIA` (medidas, curvas, PA), `ENERGIA` (TMB/GET), `NUTRICAO` (plano, diário, metas nutricionais — fases seguintes), `HISTORICO` (familiar, gestacional, antecedentes, alergias, comorbidades, medicamentos em uso, tratamentos anteriores), `CLINICO` (evoluções, diagnósticos, condutas), `EXAMES`, `MEDICAMENTOS`, `METAS`, `AUDITORIA`, `USUARIOS`.
  - `Policy::allows(array $user, string $action /* 'view'|'edit' */, string $section): bool`. Matriz:

    | Papel | view | edit |
    |---|---|---|
    | medico | todas exceto AUDITORIA/USUARIOS (só com `is_admin`) | idem |
    | nutricionista | CADASTRO_BASICO, ANTROPOMETRIA, ENERGIA, NUTRICAO | ANTROPOMETRIA, ENERGIA, NUTRICAO |
    | secretaria | CADASTRO_BASICO, CADASTRO, AGENDA | CADASTRO, AGENDA |
    | paciente / responsavel | CADASTRO_BASICO, ANTROPOMETRIA | ANTROPOMETRIA (apenas criar medidas com `source='familia'`) |
    | crianca | nenhuma nesta fase | nenhuma |
    | `is_admin=1` (qualquer papel) | + AUDITORIA, USUARIOS | + USUARIOS |

  - `PatientScope::__construct(Database)`; `canAccess(array $user, int $patientId): bool` — medico/secretaria: paciente existe e `deleted_at IS NULL`; nutricionista: `professional_access` ativo (`revoked_at IS NULL`); paciente: `patients.user_id = user.id`; responsavel: `guardian_links` ativo; crianca: false. `accessiblePatientIds(array $user): ?array` (null = todos).
  - `Gate::__construct(Policy, PatientScope, AuditLog)`; `authorize(array $user, string $action, string $section, ?int $patientId, Request $r): void` — lança `HttpException(403)` e grava `audit_log.action='denied'` com `entity=$section`, `patient_id`. `check(...)`: bool sem lançar (para mostrar/ocultar menus).

- [ ] **Step 1: Testes**
  - `PolicyTest`: nutricionista `view CLINICO` false, `view EXAMES` false, `view MEDICAMENTOS` false, `edit ENERGIA` true; secretaria `view ANTROPOMETRIA` false, `edit CADASTRO` true; responsavel `view HISTORICO` false; medico sem admin `view AUDITORIA` false, com admin true.
  - `PatientScopeTest`: nutricionista sem concessão → false; com concessão → true; após revogação → false; responsável vinculado ao paciente A → true para A, false para B (IDOR); link com `active=0` → false; paciente adulto → só o próprio.
  - `GateTest`: negação lança 403 e cria 1 linha `denied` com `patient_id` correto.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: matriz de permissões por perfil e escopo de pacientes`

---

### Task 7: Instalador e verificação de requisitos

**Files:**
- Create: `app/Install/{Requirements,Installer,InstallController}.php`, `app/Install/views/{requirements,form,done}.php`
- Test: `tests/Unit/RequirementsTest.php`, `tests/Integration/InstallerTest.php`

**Interfaces:**
- Consumes: `Migrator`, `UserRepository`, `BackupService::generateKeys` (Task 9 — nesta task, Installer chama `BackupKeys::generate(string $passphrase, string $dir): array` definido aqui em `app/Backup/BackupKeys.php` e reutilizado na Task 9).
- Produces:
  - `Requirements::check(): array` — lista de `['item'=>string,'ok'=>bool,'required'=>bool,'detail'=>string]` para: PHP ≥ 8.1, extensões `pdo_mysql`/`openssl`/`zip`/`mbstring`/`json`, `storage_path` gravável, pasta fora da raiz pública disponível (informativo), `exec`+`mysqldump` disponíveis (informativo), `upload_max_filesize` (informativo), HTTPS ativo (informativo).
  - `Installer::run(array $input): void` — valida, grava `config/config.php` (permissão 0640), roda migrações, cria usuário `role='medico', is_admin=1` com CRM, gera par de chaves de backup (RSA 4096): pública em `storage/keys/backup_public.pem`, privada exportada com a senha de backup em `storage/keys/backup_private.pem` e oferecida para download na tela final; insere `consent_terms` v1.0 com texto-padrão LGPD (editável depois); grava `settings.installed_at`.
  - `BackupKeys::generate(string $passphrase, string $dir): array` → `['public'=>path,'private'=>path]`.
  - Rotas `/instalar` só funcionam se `config/config.php` não existir; depois retornam 404.
  - Escolha do `storage_path`: tenta `dirname($APP_ROOT_PUBLIC)/rubia_storage` (fora de `public_html`); se não for gravável, usa `APP_ROOT/storage` (protegida por `.htaccess`) e mostra aviso.
  - Senha de backup: mínimo 12 caracteres, digitada duas vezes; aviso em destaque: "Guarde esta senha fora do sistema. Sem ela não é possível restaurar backups."

- [ ] **Step 1: Testes** — `RequirementsTest::test_reports_php_version_item` (item PHP presente, `ok` true no ambiente de teste); `InstallerTest::test_install_creates_admin_keys_and_term` (usa `rubia_test` vazio e diretório temporário: usuário admin existe, os dois PEM existem, chave privada **não** abre sem a senha — `openssl_pkey_get_private($pem)` false — e abre com a senha; `consent_terms` tem v1.0 ativa); segunda execução lança `RuntimeException("já instalado")`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: instalador web com verificação de requisitos e chaves de backup`

---

### Task 8: Gestão de usuários da equipe (admin)

**Files:**
- Create: `app/Users/UserController.php`, `app/Users/views/{index,form}.php`
- Test: `tests/Http/UserAdminTest.php`

**Interfaces:**
- Consumes: `Gate` (seção `USUARIOS`), `UserRepository`, `TokenService::issue(…,'invite',4320)`, `Mailer::queue`, `AuditLog`.
- Produces: rotas `GET /equipe`, `GET/POST /equipe/novo`, `GET/POST /equipe/{id}/editar`, `POST /equipe/{id}/desativar`. Criação de staff (medico, nutricionista, secretaria) envia e-mail de convite com link `/senha/definir/{token}`. Desativar não apaga (só `active=0`); usuário não pode desativar a si mesmo. Cada ação → `audit_log` (`create`/`update` em `users`, sem `password_hash`/`totp_secret` no JSON).

- [ ] **Step 1: Teste** — admin cria nutricionista → usuário existe, 1 e-mail na fila contendo `/senha/definir/`; secretária acessando `/equipe` → 403; admin desativando a si mesmo → erro de validação; JSON de auditoria não contém `password_hash`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: gestão de usuários da equipe`

---

### Task 9: Backup criptografado e restauração

**Files:**
- Create: `app/Backup/{Dumper,BackupService}.php`, `tools/restore.php`
- Modify: `app/Backup/BackupKeys.php` (da Task 7), `cron/run.php`
- Test: `tests/Integration/BackupServiceTest.php`

**Interfaces:**
- Consumes: `Database`, `Config`, chaves da Task 7.
- Produces:
  - `Dumper::__construct(Database)`; `dump(string $file): void` — PHP puro: `SHOW CREATE TABLE` + `INSERT` em lotes de 500 linhas, `SET FOREIGN_KEY_CHECKS=0` no topo.
  - `BackupService::__construct(Database, Dumper, array $cfg)`; `create(): string` (caminho do `.rbk`) — zip com `database.sql` + `storage/uploads/`, selado com `openssl_seal` (chave pública, `aes-256-cbc`); arquivo `.rbk` = cabeçalho JSON de 1 linha `{"v":1,"ek":base64,"iv":base64,"created":ISO}` + `\n` + dados cifrados. `runIfDue(): ?string` — cria se o último backup tiver mais de 24 h (`settings.last_backup_at`); apaga `.rbk` com mais de 30 dias. `restoreToZip(string $rbk, string $privatePem, string $passphrase, string $outZip): void` (usa `openssl_open`; senha errada → `RuntimeException("Senha de backup incorreta")`).
  - `tools/restore.php <arquivo.rbk> <backup_private.pem> <saida.zip>` pede a senha no terminal.
  - Página do admin "Backups" (lista com data/tamanho e botão de download) — rota `GET /admin/backups`, `GET /admin/backups/{nome}` (só admin, auditado como `export`).

- [ ] **Step 1: Teste** — com tabela de exemplo preenchida: `create()` gera `.rbk` sem o texto `INSERT INTO` legível no conteúdo bruto; `restoreToZip` com senha correta gera zip cujo `database.sql` contém `INSERT INTO`; senha errada lança a exceção; `runIfDue()` duas vezes seguidas cria só 1 arquivo; arquivo com `mtime` de 31 dias atrás é removido.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: backup diário criptografado e ferramenta de restauração`

---

### Task 10: Pacientes, responsáveis, contas da família e consentimento

**Files:**
- Create: `app/Patients/{PatientRepository,GuardianRepository,ConsentService,PatientController,GuardianController}.php`, `app/Patients/views/{index,form,show,guardians,consent}.php`
- Test: `tests/Integration/PatientRepositoryTest.php`, `tests/Integration/ConsentServiceTest.php`, `tests/Http/PatientCrudTest.php`

**Interfaces:**
- Consumes: `Gate`, `PatientScope::accessiblePatientIds`, `UserRepository`, `TokenService`, `Mailer`, `AuditLog`, `Dates::parseBr`.
- Produces:
  - `PatientRepository`: `create(array $d, int $byUserId): int`, `update(int $id, array $d, int $byUserId): void` (cadastro não é dado clínico: UPDATE permitido, com auditoria before/after), `find(int $id): ?array`, `search(string $q, ?array $onlyIds, int $limit = 50): array` (nome/CPF), `ageInDays(array $patient, string $onDate): int`, `isPediatric(array $p, string $onDate): bool` (idade < 228 meses — usa `Age`, Task 12; até lá, `Age::months()` é criado aqui em `app/Growth/Age.php` com `days(string $birth, string $on): int` e `months(string $birth, string $on): float` = dias / 30.4375).
  - `GuardianRepository`: `link(int $patientId, int $userId, string $relationship, int $by): int`, `unlink(int $linkId, int $by): void` (`active=0`, `ended_at`), `forPatient(int $pid): array`, `patientsForGuardian(int $uid): array`.
  - Fluxos: cadastrar paciente (secretária ou médico; data de nascimento não pode ser futura; `responsible_professional_id` padrão = médico admin); "Dar acesso ao paciente adulto" (cria usuário `paciente` com e-mail + convite); "Adicionar responsável" (busca por e-mail existente para reaproveitar o mesmo login de pais com vários filhos, senão cria usuário `responsavel` + convite).
  - `ConsentService`: `pendingFor(array $user): array` — termos ativos não aceitos, um por paciente vinculado para `responsavel` (LGPD art. 14), um para `paciente`; `accept(int $userId, ?int $patientId, string $version, Request $r): void` (auditado `consent`). Middleware `consent`: família com pendência só acessa `/consentimento` e `/logout`.
  - Lista de pacientes mostra só o permitido: nutricionista vê só concedidos, com nome/idade/sexo (`CADASTRO_BASICO`).

- [ ] **Step 1: Testes**
  - `PatientRepositoryTest`: `search` com `onlyIds=[A]` não retorna B; `update` gera auditoria com before/after.
  - `ConsentServiceTest`: responsável com 2 filhos → 2 pendências; após aceitar uma → 1.
  - `PatientCrudTest`: secretária cadastra paciente com data `05/03/2019` → salvo `2019-03-05`; data futura → erro "Data de nascimento não pode ser futura"; nutricionista abrindo `/pacientes/{id}` de paciente não concedido → 403; responsável sem consentimento acessando `/` → redirect `/consentimento`; responsável já existente adicionado a segundo filho → mesmo `user_id`, sem novo usuário.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: cadastro de pacientes, responsáveis e consentimento LGPD`

---

### Task 11: Liberação para nutricionista e alerta de maioridade

**Files:**
- Create: `app/Patients/{AccessGrantRepository,MajorityService,AccessGrantController}.php`, `app/Patients/views/{access,majority}.php`, `app/Dashboard/DashboardController.php`, `app/Dashboard/views/index.php`
- Test: `tests/Integration/AccessGrantRepositoryTest.php`, `tests/Integration/MajorityServiceTest.php`, `tests/Http/DashboardTest.php`

**Interfaces:**
- Consumes: `Gate`, `AuditLog`, `GuardianRepository::unlink`, `Age::months`.
- Produces:
  - `AccessGrantRepository`: `grant(int $patientId, int $professionalId, int $by): int` (idempotente se já ativo), `revoke(int $grantId, int $by): void`, `activeFor(int $patientId): array`. Só médico concede/revoga (`edit CLINICO`). Auditoria `grant`/`revoke`.
  - `MajorityService`: `pending(string $today): array` — pacientes com idade ≥ 18 anos completos, com algum `guardian_links.active=1` e sem linha em `majority_decisions`; `decide(int $patientId, string $decision, ?string $notes, int $by): void` — `encerrar`: desativa todos os vínculos; `transferir`: desativa vínculos e exige que o paciente tenha (ou receba agora) login próprio (`paciente`), enviando convite; `manter`: só registra. Auditoria `majority_decision`.
  - Dashboard `/` (staff): médico vê "Decisões de maioridade pendentes" + pacientes recentes; secretária vê pacientes recentes; nutricionista vê seus pacientes liberados. Família é redirecionada para `/portal`.

- [ ] **Step 1: Testes**
  - `AccessGrantRepositoryTest`: grant duplicado não cria segunda linha; revoke define `revoked_at` e `PatientScope::canAccess` passa a false.
  - `MajorityServiceTest`: paciente que faz 18 anos em `today` aparece; um dia antes não aparece; após `decide('encerrar')` some da lista e vínculos ficam `active=0`; `manter` mantém vínculos e some da lista.
  - `DashboardTest`: médico vê o bloco de maioridade; secretária não vê.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: liberação para nutricionista e alerta de maioridade`

---

### Task 12: Motor de crescimento OMS (LMS, escore Z, percentil, classificação)

**Files:**
- Create: `data/who/*.txt`, `tools/import_who.php`, `app/Growth/{Lms,WhoReferenceRepository,GrowthCalculator,NutritionalStatus}.php`
- Modify: `app/Growth/Age.php`
- Test: `tests/Unit/LmsTest.php`, `tests/Unit/NutritionalStatusTest.php`, `tests/Integration/WhoReferenceTest.php`, `tests/Integration/GrowthCalculatorTest.php`

**Fontes de dados (baixar e versionar em `data/who/`):**
- OMS 2006 (0–5 anos), repositório oficial `github.com/WorldHealthOrganization/anthro`, pasta `data-raw/growthstandards/`: `bmianthro.txt`, `weianthro.txt`, `lenanthro.txt`, `hcanthro.txt`, `wflanthro.txt`, `wfhanthro.txt` (colunas `sex, age|length|height, l, m, s[, loh]`; idade em dias 0–1856).
- OMS 2007 (5–19 anos), repositório oficial `github.com/WorldHealthOrganization/anthroplus`, pasta `data-raw/`: `bfawho2007.txt`, `hfawho2007.txt`, `wfawho2007.txt` (idade em meses 61–228; peso/idade só até 120).
- Se os repositórios não estiverem acessíveis, usar as tabelas LMS publicadas em `who.int/tools/child-growth-standards` e `who.int/tools/growth-reference-data-for-5to19-years` e registrar a origem no README.

**Interfaces:**
- Produces:
  - `Lms::z(float $x, float $l, float $m, float $s): float` — `L≠0: ((x/m)^L − 1)/(L·s)`; `L=0: ln(x/m)/s`.
  - `Lms::zRestricted(float $x, float $l, float $m, float $s): float` — método OMS para indicadores de peso:
    ```
    z = Lms::z(...)
    se z > 3:  sd3 = m(1+l·s·3)^(1/l); sd23 = sd3 − m(1+l·s·2)^(1/l); z = 3 + (x − sd3)/sd23
    se z < −3: sd3n = m(1−l·s·3)^(1/l); sd23n = m(1−l·s·2)^(1/l) − sd3n; z = −3 + (x − sd3n)/sd23n
    ```
  - `Lms::valueAt(float $z, float $l, float $m, float $s): float` — inverso (para desenhar curvas).
  - `Lms::percentile(float $z): float` — Φ(z)·100 (aproximação de Abramowitz–Stegun 26.2.17 ou erf), 1 casa decimal.
  - `WhoReferenceRepository::lms(string $indicator, string $sex /* 'M'|'F' */, float $x, string $source): ?array{l,m,s}` — interpolação linear entre os dois `x` vizinhos; fora da faixa → null. `range(string $indicator, string $sex, string $source): array{min,max}`.
  - `GrowthCalculator::__construct(WhoReferenceRepository)`; `indicators(string $sex, string $birth, string $measuredOn, ?float $weightKg, ?float $heightCm, ?string $position, ?float $headCm): array` → mapa `'bfa'|'wfa'|'hfa'|'wflh'|'hcfa'` → `null` (não se aplica) ou `['z'=>float,'percentile'=>float,'value'=>float,'status'=>string,'implausible'=>bool,'source'=>string]`. Regras:
    - idade em dias = `Age::days`; `< 1857` dias → OMS 2006 por dia; senão OMS 2007 por mês (`Age::months`), até 228 meses; acima → todos null (adulto).
    - correção de posição: < 731 dias medido `em_pe` → altura + 0,7; ≥ 731 dias medido `deitado` → altura − 0,7.
    - IMC = peso / (altura_m²) com a altura corrigida.
    - `wflh`: < 731 dias usa `wfl` por comprimento (45–110 cm); 731–1856 dias usa `wfh` por altura (65–120 cm); acima de 5 anos null.
    - `wfa` acima de 120 meses → null; `hcfa` acima de 1856 dias → null.
    - `zRestricted` para `bfa`, `wfa`, `wflh`; `z` simples para `hfa`, `hcfa`.
    - implausível (flags OMS): `hfa` z < −6 ou > 6; `wfa` < −6 ou > 5; `bfa` e `wflh` < −5 ou > 5; `hcfa` < −5 ou > 5.
  - `NutritionalStatus::classify(string $indicator, float $z, int $ageDays): string` — limites (`<` e `≤` exatamente assim):
    - `bfa` e `wflh`, 0–5 anos (< 1857 dias): z < −3 "Magreza acentuada"; −3 ≤ z < −2 "Magreza"; −2 ≤ z ≤ +1 "Eutrofia"; +1 < z ≤ +2 "Risco de sobrepeso"; +2 < z ≤ +3 "Sobrepeso"; z > +3 "Obesidade".
    - `bfa`, 5–19 anos: z < −3 "Magreza acentuada"; −3 ≤ z < −2 "Magreza"; −2 ≤ z ≤ +1 "Eutrofia"; +1 < z ≤ +2 "Sobrepeso"; +2 < z ≤ +3 "Obesidade"; z > +3 "Obesidade grave".
    - `hfa`: z < −3 "Muito baixa estatura para a idade"; −3 ≤ z < −2 "Baixa estatura para a idade"; z ≥ −2 "Estatura adequada para a idade".
    - `wfa`: z < −3 "Muito baixo peso para a idade"; −3 ≤ z < −2 "Baixo peso para a idade"; −2 ≤ z ≤ +2 "Peso adequado para a idade"; z > +2 "Peso elevado para a idade".
    - `hcfa`: z < −2 "Abaixo do esperado"; −2 ≤ z ≤ +2 "Adequado"; z > +2 "Acima do esperado".

- [ ] **Step 1: Testes unitários**
  - `LmsTest`: `z(11, 1, 10, 0.1) == 1.0` (delta 1e-9); `z(10, 0, 10, 0.1) == 0.0`; `z(x, l, m, s)` para `x = valueAt(2.0, -1.6, 17.0, 0.12)` volta 2.0; `zRestricted` com L=−1.6, M=17, S=0.12 e `x = valueAt(3,…) + 2*(valueAt(3,…) − valueAt(2,…))` → 5.0 (delta 1e-9), enquanto `z` simples dá valor diferente de 5.0; `percentile(0)==50.0`; `percentile(1.881)` ≈ 97.0 (delta 0.1); `percentile(-1.036)` ≈ 15.0 (delta 0.1).
  - `NutritionalStatusTest`: fronteiras exatas — `bfa` 5–19 anos: z=1.0 "Eutrofia", 1.0001 "Sobrepeso", 2.0 "Sobrepeso", 3.0 "Obesidade", 3.01 "Obesidade grave"; `bfa` 0–5 anos z=3.01 "Obesidade", 1.5 "Risco de sobrepeso"; `hfa` z=−2.0 "Estatura adequada para a idade", −2.01 "Baixa estatura para a idade".
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar `Lms`, `NutritionalStatus`.** Rodar unitários → PASS.
- [ ] **Step 4: Baixar os arquivos OMS, implementar `tools/import_who.php`** (lê os `.txt`, converte sexo 1/2, grava `who_lms` em lote; idempotente com `REPLACE`). Gera também `database/seeds/who_lms.sql` (para importar pelo phpMyAdmin em produção).
- [ ] **Step 5: Testes de integração**
  - `WhoReferenceTest` (após importar no `rubia_test`): `lms('wfa','M',0,'who2006')` → M=3.3464, L=0.3487, S=0.14602 (delta 1e-4); `lms('wfa','F',0,'who2006')['m']` = 3.2322; `lms('hfa','M',0,'who2006')['m']` = 49.8842; `lms('hfa','F',0,'who2006')['m']` = 49.1477; contagem de linhas `bfa` 2006 por sexo = 1857; `bfa` 2007 por sexo = 168 (61–228); `lms('wfa','M',121,'who2007')` = null; interpolação: o `m` em x=10.5 fica estritamente entre os de 10 e 11.
  - `GrowthCalculatorTest`: menino com medida igual à mediana (`M` da tabela) → z≈0 e percentil 50; menino de 8 anos com IMC bem acima de +3 → `status` "Obesidade grave" e z vindo de `zRestricted`; menino de 11 anos → `wfa` null e `bfa` preenchido; menina de 3 anos medida `deitado` → altura usada = medida − 0,7; criança de 1 ano com comprimento 40 cm → `wflh` null (fora de 45–110); IMC z = 6 → `implausible` true; paciente com 230 meses → todos null.
- [ ] **Step 6: Implementar `WhoReferenceRepository`, `GrowthCalculator`.** Rodar → PASS.
- [ ] **Step 7: Commit** — `feat: motor de crescimento OMS com escore Z, percentil e classificação`

---

### Task 13: Adulto (IMC, cintura, RCQ) e pressão arterial

**Files:**
- Create: `app/Growth/{AdultAnthropometry,BloodPressure,BpReferenceRepository}.php`, `data/bp/aap2017.csv`, `tools/import_bp.php`
- Test: `tests/Unit/AdultAnthropometryTest.php`, `tests/Unit/BloodPressureTest.php`, `tests/Integration/BpReferenceTest.php`

**Interfaces:**
- Consumes: `GrowthCalculator` (percentil de altura para idade, `hfa`).
- Produces:
  - `AdultAnthropometry::bmi(float $kg, float $cm): float` (1 casa); `bmiClass(float $bmi): string` — < 18,5 "Baixo peso"; 18,5–24,9 "Eutrofia"; 25–29,9 "Sobrepeso"; 30–34,9 "Obesidade grau I"; 35–39,9 "Obesidade grau II"; ≥ 40 "Obesidade grau III" (comparação com o valor arredondado a 1 casa). `waistRisk(string $sex, float $cm): string` — M: < 94 "Sem risco aumentado", 94–101,9 "Risco aumentado", ≥ 102 "Risco muito aumentado"; F: < 80 / 80–87,9 / ≥ 88. `whr(float $waist, float $hip): float` (2 casas) e `whrRisk(string $sex, float $whr): string` — risco se M > 0,90 / F > 0,85.
  - `BloodPressure::classifyAdult(int $sys, int $dia): string` (Diretriz Brasileira de HA 2020; usa a pior das duas): sys < 120 e dia < 80 "PA ótima"; 120–129 / 80–84 "PA normal"; 130–139 / 85–89 "Pré-hipertensão"; 140–159 / 90–99 "HA estágio 1"; 160–179 / 100–109 "HA estágio 2"; ≥ 180 / ≥ 110 "HA estágio 3".
  - `BloodPressure::classifyChild(string $sex, int $ageYears, float $heightPercentile, int $sys, int $dia, BpReferenceRepository $ref): string` (AAP 2017):
    - 1–12 anos: usa o percentil de altura da tabela mais próximo (5,10,25,50,75,90,95) e os limiares P90, P95; "Normal" < P90; "PA elevada" ≥ P90 ou ≥ 120/80 (o menor) e < P95; "HA estágio 1" ≥ P95 até < P95+12 mmHg, ou 130/80 a 139/89 (o menor); "HA estágio 2" ≥ P95+12 mmHg ou ≥ 140/90 (o menor). Sistólica e diastólica classificadas separadamente; prevalece a pior.
    - 13–17 anos (e 18 em diante → `classifyAdult`): "Normal" < 120/< 80; "PA elevada" 120–129/< 80; "HA estágio 1" 130–139 ou 80–89; "HA estágio 2" ≥ 140 ou ≥ 90.
    - < 1 ano → `"Não se aplica"`.
  - O percentil de altura vem da `hfa` OMS (decisão do plano: a AAP usa percentis do CDC; o plano usa a OMS para manter uma única referência; texto de rodapé no relatório deixa isso claro).
  - `BpReferenceRepository::thresholds(string $sex, int $age, int $heightPct): array{sys90,sys95,dia90,dia95}`.
  - `data/bp/aap2017.csv`: transcrição das Tabelas 4 (meninos) e 5 (meninas) de Flynn JT et al., *Pediatrics* 2017;140(3):e20171904 — colunas `sex,age,height_pct,bp_pct,systolic,diastolic`, idades 1–17, percentis de PA 50/90/95 (o P95+12 é calculado). O implementador confere cada linha contra a tabela publicada; o README cita a fonte.

- [ ] **Step 1: Testes**
  - `AdultAnthropometryTest`: `bmi(80,175)==26.1`; `bmiClass(24.9)` "Eutrofia", `bmiClass(25.0)` "Sobrepeso", `bmiClass(40.0)` "Obesidade grau III"; `waistRisk('F',80)` "Risco aumentado"; `waistRisk('M',93.9)` "Sem risco aumentado"; `whrRisk('M',0.91)` risco, `whrRisk('F',0.85)` sem risco.
  - `BloodPressureTest` (com repositório falso de limiares fixos sys90=110, sys95=114, dia90=70, dia95=74): criança de 8 anos 105/65 "Normal"; 111/65 "PA elevada"; 115/65 "HA estágio 1"; 127/65 "HA estágio 2" (≥ 114+12); 15 anos 125/75 "PA elevada", 135/75 "HA estágio 1", 120/90 "HA estágio 2"; adulto 135/80 "Pré-hipertensão", 150/95 "HA estágio 1", 118/112 "HA estágio 3"; criança de 8 anos com limiar P90 de 125 e PA 121/60 → "PA elevada" (regra "≥ 120/80, o menor").
  - `BpReferenceTest`: após importar, existem 2 × 17 × 7 × 3 = 714 linhas; `thresholds` devolve sys95 > sys90.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar e importar o CSV.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: classificação adulta e de pressão arterial (adulto e AAP 2017)`

---

### Task 14: Taxa metabólica basal e gasto energético total

**Files:**
- Create: `app/Energy/{EnergyCalculator,EnergyGoalRepository,EnergyController}.php`, `app/Energy/views/index.php`
- Test: `tests/Unit/EnergyCalculatorTest.php`, `tests/Http/EnergyGoalTest.php`

**Interfaces:**
- Consumes: `Gate` (`ENERGIA`), `MeasurementRepository::latest` (Task 15 — se a Task 15 ainda não existir, o formulário aceita peso/altura digitados; a ordem 14→15 é aceitável), `AuditLog`.
- Produces:
  - `EnergyCalculator::mifflin(string $sex, float $kg, float $cm, float $ageYears): float` — M: 10·kg + 6,25·cm − 5·idade + 5; F: … − 161.
  - `EnergyCalculator::harrisBenedict(string $sex, float $kg, float $cm, float $ageYears): float` — M: 66,473 + 13,7516·kg + 5,0033·cm − 6,755·idade; F: 655,0955 + 9,5634·kg + 1,8496·cm − 4,6756·idade.
  - `EnergyCalculator::faoOms1985(string $sex, float $kg, float $ageYears): float` — por faixa (idade em anos, limite inferior inclusivo):
    - M: 0–3: 60,9·kg − 54; 3–10: 22,7·kg + 495; 10–18: 17,5·kg + 651; 18–30: 15,3·kg + 679; 30–60: 11,6·kg + 879; ≥ 60: 13,5·kg + 487.
    - F: 0–3: 61,0·kg − 51; 3–10: 22,5·kg + 499; 10–18: 12,2·kg + 746; 18–30: 14,7·kg + 496; 30–60: 8,7·kg + 829; ≥ 60: 10,5·kg + 596.
  - `EnergyCalculator::all(string $sex, float $kg, float $cm, float $ageYears): array` → `['mifflin'=>?int,'harris_benedict'=>?int,'fao_oms_1985'=>int]` (arredondado); Mifflin e Harris-Benedict retornam null abaixo de 18 anos (não validadas para crianças) e a tela explica isso.
  - `get = round(tmb × activity_factor)`; fatores pré-definidos na tela: 1,2 sedentário; 1,375 leve; 1,55 moderado; 1,725 intenso; 1,9 muito intenso; também aceita valor livre entre 1,0 e 2,5.
  - `EnergyGoalRepository::save(array $goal, int $by): int` (marca a meta anterior com `superseded_at`), `current(int $patientId): ?array`, `history(int $pid): array`.
  - Rotas: `GET /pacientes/{id}/energia`, `POST /pacientes/{id}/energia`. A tela mostra o resultado de todas as fórmulas lado a lado; a profissional escolhe uma e o fator, e salva como meta energética (usada pelo plano alimentar na Fase 2).

- [ ] **Step 1: Testes**
  - `EnergyCalculatorTest`: `mifflin('M',80,180,30)==1780.0`; `mifflin('F',60,165,25)==1345.25`; `harrisBenedict('M',80,180,30)` ≈ 1864.545 (delta 0.001); `harrisBenedict('F',60,165,25)` ≈ 1417.194 (delta 0.001); `faoOms1985('M',40,9.5)==1403.0` (22,7·40+495); `faoOms1985('M',40,10.0)==1351.0` (faixa 10–18 inclusiva: 17,5·40+651); `faoOms1985('F',50,14)==1356.0`; `all('F',30,130,9)['mifflin']===null`.
  - `EnergyGoalTest`: nutricionista com paciente liberado salva meta (formula `fao_oms_1985`, fator 1,55) → `get_kcal` = round(tmb·1,55) e a meta anterior recebe `superseded_at`; fator `"3"` → erro de validação; secretária → 403.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: cálculo de TMB/GET e meta energética`


---

### Task 15: Medidas — registro, versões, curvas e velocidade (painel clínico)

**Files:**
- Create: `app/Growth/{MeasurementRepository,Velocity,GrowthController}.php`, `app/Growth/views/{index,form,history}.php`, `public/assets/js/growth-chart.js`, `public/assets/vendor/chart.umd.min.js`
- Test: `tests/Integration/MeasurementRepositoryTest.php`, `tests/Unit/VelocityTest.php`, `tests/Http/GrowthPagesTest.php`

**Interfaces:**
- Consumes: `Gate` (`ANTROPOMETRIA`), `GrowthCalculator::indicators`, `AdultAnthropometry`, `BloodPressure`, `Num::parse`, `Dates::parseBr`, `AuditLog`, `WhoReferenceRepository`, `Lms::valueAt`.
- Produces:
  - `MeasurementRepository`: `create(int $patientId, array $d, string $source, int $by): int`; `correct(int $id, array $d, int $by): int` (nova linha com `previous_id`, antiga recebe `superseded_at`; retorna novo id); `remove(int $id, string $reason, int $by): void` (lógico, motivo obrigatório); `current(int $patientId): array` (não substituídas nem removidas, ordem `measured_on`); `latest(int $pid): ?array`; `versions(int $id): array`.
  - Validação: `measured_on` não pode ser antes do nascimento nem depois de hoje; ao menos um campo de medida preenchido; faixas de sanidade — peso 0,3–350 kg, altura 30–250 cm, cintura/quadril 20–250 cm, PC 20–70 cm, PA sistólica 50–260 e diastólica 30–160, e sistólica > diastólica. `retroactive=1` automático quando `measured_on` é mais de 7 dias antes da data de registro.
  - `Velocity::heightVelocity(array $measurements): array` — para pares consecutivos com altura e intervalo ≥ 90 dias: `['from'=>Y-m-d,'to'=>Y-m-d,'cm_per_year'=>float(1 casa)]`; intervalos < 90 dias são ignorados.
  - Rotas: `GET /pacientes/{id}/crescimento` (tabela de medidas com indicadores, classificações e PA classificada; selo "família" e "retroativo"; velocidade), `GET/POST /pacientes/{id}/medidas/nova`, `GET/POST /medidas/{id}/corrigir`, `POST /medidas/{id}/remover`, `GET /medidas/{id}/versoes`, `GET /pacientes/{id}/curvas.json?indicador=bfa|wfa|hfa|wflh|hcfa&tipo=z|percentil`.
  - `curvas.json` retorna `{"reference":{"lines":[{"label":"+2","points":[[x,y],…]},…]},"patient":[[x,y,"dd/mm/aaaa"],…],"x_unit":"meses"|"cm"}` — linhas Z −3…+3, ou percentis P3, P15, P50, P85, P97 (z = −1,881; −1,036; 0; 1,036; 1,881); x em meses (ou cm para `wflh`), faixa da idade do paciente ± 12 meses limitada à referência. Para adultos, retorna só a série de peso e IMC no tempo.
  - Abrir `/pacientes/{id}/crescimento` por profissional gera `audit_log.action='view'`.

- [ ] **Step 1: Testes**
  - `MeasurementRepositoryTest`: `correct` → `current()` mostra só o novo valor; `versions()` retorna as duas; `remove` sem motivo lança; removida some de `current()`; nenhuma linha é apagada fisicamente (`COUNT(*)` não diminui).
  - `VelocityTest`: alturas 100,0 (2024-01-01) e 106,0 (2025-01-01) → 6,0 cm/ano (delta 0,05); par com 60 dias de intervalo ignorado.
  - `GrowthPagesTest`: POST de medida com peso `"32,5"` → salvo 32.500; data antes do nascimento → erro "A data da medida não pode ser anterior ao nascimento", nada salvo; data futura → erro; sistólica ≤ diastólica → erro; nutricionista sem concessão em `/pacientes/{id}/curvas.json` → 403 (IDOR); `curvas.json?indicador=bfa` para criança de 8 anos tem 7 linhas e a do `"0"` passa pela mediana `M` da tabela na idade do paciente; para paciente de 11 anos, `indicador=wfa` → `{"reference":null,"message":"Não se aplica para esta idade"}` com status 200.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.** `growth-chart.js` desenha as linhas de referência finas e os pontos do paciente em destaque (Chart.js local), alternando Z ↔ percentil e indicador por abas. Ponto implausível com marcador de alerta e tooltip "verificar medida".
- [ ] **Step 4: Rodar** → PASS. Conferir manualmente no navegador as curvas de uma criança fictícia de 8 anos com 4 medidas.
- [ ] **Step 5: Commit** — `feat: medidas versionadas, curvas OMS e velocidade de crescimento`

---

### Task 16: Prontuário — histórico e evoluções versionados

**Files:**
- Create: `app/Records/{ClinicalEntryRepository,RecordController}.php`, `app/Records/views/{index,history_form,evolution_form,versions}.php`
- Test: `tests/Integration/ClinicalEntryRepositoryTest.php`, `tests/Http/RecordPagesTest.php`

**Interfaces:**
- Consumes: `Gate` (`HISTORICO`, `CLINICO`), `AuditLog`, `PatientRepository::isPediatric`.
- Produces:
  - `ClinicalEntryRepository`: `add(int $patientId, string $kind, array $payload, ?string $cid10, ?string $encounterDate, int $by): int`; `revise(int $id, array $payload, ?string $cid10, int $by): int` (nova versão = `version+1`, `previous_id`, antiga `superseded_at`); `remove(int $id, string $reason, int $by)`; `currentByKind(int $pid, string $kind): array`; `evolutions(int $pid): array` (mais recentes primeiro); `versions(int $id): array`.
  - Campos do `payload` por `kind`:
    - `historico_familiar`: `condicoes` (lista de `{condicao, parentesco}`; condições sugeridas: obesidade, diabetes tipo 2, hipertensão, dislipidemia, doença cardiovascular precoce, apneia do sono, transtorno alimentar), `observacoes`.
    - `gestacional` (só pediátrico): `peso_nascer_g`, `comprimento_nascer_cm`, `idade_gestacional_semanas`, `tipo_parto` (normal/cesárea), `aleitamento_materno_meses`, `diabetes_gestacional` (bool), `intercorrencias`.
    - `antecedentes`, `alergias`, `comorbidades`, `medicamentos_em_uso`, `tratamentos_anteriores`: `texto`.
    - `evolucao`: `anamnese`, `exame_fisico`, `diagnostico`, `conduta`, `plano_terapeutico`, `encaminhamentos`, mais `cid10` (texto livre validado pelo formato `^[A-Z][0-9]{2}(\.[0-9]{1,2})?$`, opcional) e `encounter_date`.
  - Cada registro mostra autor (nome + CRM/CRN) e data/hora. Botões "Corrigir" (cria versão) e "Ver versões".
  - Rotas: `GET /pacientes/{id}/prontuario`, `GET/POST /pacientes/{id}/prontuario/{kind}`, `GET/POST /prontuario/{entryId}/revisar`, `POST /prontuario/{entryId}/remover`, `GET /prontuario/{entryId}/versoes`. Toda abertura → `audit_log.action='view'`; toda gravação → `create`/`update`/`delete` com before/after.
  - Página-resumo do paciente (`/pacientes/{id}`) agrega cartões conforme permissões: Cadastro, Prontuário (só médico), Crescimento, Energia; para pacientes ≥ 228 meses o cartão Crescimento mostra IMC adulto/cintura/RCQ.

- [ ] **Step 1: Testes**
  - `ClinicalEntryRepositoryTest`: `revise` → `currentByKind` retorna só a versão 2 com o novo texto; `versions` retorna 1 e 2 com autores; nada apagado fisicamente; `remove` exige motivo.
  - `RecordPagesTest`: médico salva evolução com CID `E66.0` → ok; CID `XYZ` → erro de validação; nutricionista com concessão acessando `/pacientes/{id}/prontuario` → 403 e linha `denied`; secretária → 403; abertura pelo médico cria `audit_log` `view`; formulário `gestacional` para paciente adulto → 404.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar** → PASS.
- [ ] **Step 5: Commit** — `feat: prontuário com histórico e evoluções versionados`

---

### Task 17: Portal da família (paciente adulto e responsável)

**Files:**
- Create: `app/Portal/PortalController.php`, `app/Portal/views/{home,choose_patient,growth,measure_form,account}.php`, `public/manifest.json`, `public/sw.js`, ícones em `public/assets/icons/`
- Test: `tests/Http/PortalTest.php`

**Interfaces:**
- Consumes: `Gate`, `PatientScope`, `GuardianRepository::patientsForGuardian`, `MeasurementRepository::create(…,'familia',…)`, `GrowthCalculator`, rota `curvas.json` (Task 15), `ConsentService` (middleware), `TwoFactorController` (ativação opcional em "Minha conta").
- Produces:
  - `GET /portal` — responsável com mais de um filho escolhe o paciente (`/portal/p/{id}`); paciente adulto vai direto. Tela inicial segue o conceito da spec: cabeçalho "Olá! Bem-vindo ao acompanhamento", cartões. Nesta fase só **Crescimento** fica ativo; os demais (Alimentação, Atividades, Tratamento, Exames, Consultas) **não aparecem** até seus módulos existirem.
  - `GET /portal/p/{id}/crescimento` — gráfico em linguagem simples: crianças veem a curva de IMC por idade com faixas coloridas (sem a palavra "escore Z"; legenda "faixa esperada para a idade"); adultos veem peso e IMC no tempo. Mostra a última classificação escrita de forma acolhedora (texto curto; nada de diagnóstico).
  - `GET/POST /portal/p/{id}/medidas/nova` — peso, altura, cintura (com dicas "como medir"); salva com `source='familia'`; mesma validação da Task 15.
  - `GET /portal/conta` — trocar senha, ativar 2FA.
  - PWA: `manifest.json` (nome "Dra. Rubia — Acompanhamento", `start_url: /portal`, `display: standalone`), `sw.js` só faz cache dos assets estáticos (nenhum dado clínico em cache).

- [ ] **Step 1: Teste `PortalTest`**: responsável com 2 filhos em `/portal` vê a escolha dos dois; acessando `/portal/p/{idDeOutraCriança}/crescimento` → 403 e `denied` na auditoria; registra peso `"28,4"` → medida com `source='familia'`, `weight_kg=28.4`; página de crescimento do portal não contém as strings "Evolução", "Diagnóstico" nem "escore Z"; paciente adulto logado em `/portal` vai direto para o próprio painel; `sw.js` não lista nenhuma rota `/portal/p/`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar.** Usar o skill `frontend-design:frontend-design` para o portal (acolhedor, mobile-first, textos simples).
- [ ] **Step 4: Rodar** → PASS. Conferir no navegador em largura de celular (375 px).
- [ ] **Step 5: Commit** — `feat: portal da família com crescimento e registro de medidas`

---

### Task 18: Deploy no cPanel, README e verificação final

**Files:**
- Create: `README.md` (completar), `tools/build_release.php`
- Test: `tests/Http/SmokeAllRoutesTest.php`

**Interfaces:**
- Consumes: tudo.
- Produces:
  - `tools/build_release.php` gera `dist/rubia-release-YYYYMMDD.zip` sem `tests/`, `tools/phpunit.phar`, `config/config.php`, `storage/*` (mantém `.htaccess`), `docs/`, `.git`.
  - README em pt-BR com: requisitos; passo a passo cPanel — criar banco e usuário MySQL; enviar o zip para `/home/USUARIO/rubia_app/`; copiar o conteúdo de `public/` para `public_html/` e ajustar a linha `// AJUSTE NO DEPLOY` de `public_html/index.php` para `'/home/USUARIO/rubia_app'`; ativar SSL (AutoSSL); abrir `https://rubia.med.br/instalar`; importar `database/seeds/who_lms.sql` e o seed de PA pelo phpMyAdmin se o instalador indicar; configurar o Cron `*/5 * * * * php /home/USUARIO/rubia_app/cron/run.php`; baixar e guardar `backup_private.pem` e a senha de backup; como restaurar (`tools/restore.php`); limites legais (spec §9) em linguagem simples; fontes dos dados (OMS 2006/2007, AAP 2017, fórmulas de TMB).
  - `SmokeAllRoutesTest`: para cada perfil (médico admin, nutricionista, secretária, responsável, paciente), percorre as rotas GET da fase e verifica que nenhuma retorna 500 e que as respostas 200/403 batem com a matriz da Task 6.

- [ ] **Step 1: Escrever `SmokeAllRoutesTest`.**
- [ ] **Step 2: Rodar a suíte completa** — `php tools/phpunit.phar` → todos PASS (registrar a saída).
- [ ] **Step 3: Implementar `build_release.php` e completar o README.**
- [ ] **Step 4: Ensaio de instalação limpa local**: banco vazio `rubia_dev`, servidor `php -S localhost:8000 -t public`, percorrer `/instalar` → login do médico → configurar 2FA → criar secretária e nutricionista → cadastrar criança de 8 anos e responsável → 3 medidas → liberar para nutricionista → login do responsável (aceitar termo, registrar peso, ver curva) → rodar `php cron/run.php` e restaurar o backup gerado com `tools/restore.php`. Anotar qualquer falha e corrigir antes de prosseguir.
- [ ] **Step 5: Commit** — `chore: pacote de release, README de deploy e teste de fumaça`
