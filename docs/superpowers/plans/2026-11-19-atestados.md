# Atestados e declarações — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a médica emite, pela ficha do paciente, quatro tipos de atestado/declaração em PDF, gravados no prontuário, anuláveis e listados na linha do tempo.

**Architecture:** novo módulo `app/Certificates` (repositório + montador de texto puro + controller + views), mesmo motor de PDF da receita (`Container::pdf()`), migration 016 com tabela `certificates` e colunas `specialty`/`rqe` em `users`. O documento completo (cabeçalho, corpo, rodapé) é gravado em `body_html` na emissão; o PDF sempre sai desse HTML.

**Tech Stack:** PHP 8.1 MVC do projeto, MySQL 5.7 / MariaDB, Dompdf 3.1.6 (lib/dompdf), PHPUnit (`source tools/env.sh; php tools/phpunit.phar`).

**Spec:** `docs/superpowers/specs/2026-11-19-atestados-design.md`

## Global Constraints

- Só papel `medico` (`Clinician::assert($user, $r, $pid, 'certificates', 'atestados')`) **e** `Container::gate()->authorize($user, 'view'|'edit', Policy::CLINICO, $pid, $r)`.
- MySQL 5.7: nada exclusivo de MariaDB.
- Todo texto do usuário escapado com `e()` antes de entrar em `body_html`.
- CID: padrão `^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$` (maiúsculas após trim/strtoupper); CID sem a caixa de autorização → erro "Marque a autorização do paciente ou responsável para incluir o CID.".
- Afastamento: 1 a 90 dias.
- Observação: até 500 caracteres.
- Frase de autorização (só com CID): "Documento emitido mediante autorização expressa do paciente ou de seu representante legal para inclusão do diagnóstico codificado (CID)."
- Auditoria (`Container::audit()->record`) em emitir (`create`), baixar PDF (`view`, `['pdf' => true]`) e anular (`update`), entidade `certificates`, `section` = `atestados`.
- Testes rodam um phpunit por vez. Commits terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Paciente sem CPF → frase ", CPF nº …" some inteira (não sai "CPF nº ,").
2. Paciente com nome social → "Nome social (nome civil: Nome civil)".
3. Atestado de outro paciente na URL (`/pacientes/A/atestados/{id de B}`) → 404.
4. Atestado anulado → PDF com marca "ANULADO" e motivo; não pode anular duas vezes.
5. Observação com `<script>` → aparece escapada no PDF e na prévia.

---

### Task 1: Migration 016 e repositório

**Files:**
- Create: `database/migrations/016_certificates.sql`, `app/Certificates/CertificateRepository.php`, `app/Certificates/InvalidCertificateException.php`
- Modify: `app/Core/Container.php` (`certificates()`)
- Test: `tests/Integration/CertificateRepositoryTest.php`

**Interfaces:**
- Produces: tabela `certificates` (id, patient_id FK, author_id FK users, type ENUM('comparecimento','acompanhante','afastamento','aptidao'), issued_on DATE, payload JSON, body_html MEDIUMTEXT, cid VARCHAR(8) NULL, created_at, voided_at DATETIME NULL, voided_by INT UNSIGNED NULL FK, void_reason VARCHAR(200) NULL, KEY (patient_id, created_at)); `ALTER TABLE users ADD specialty VARCHAR(80) NULL, ADD rqe VARCHAR(40) NULL`.
- `CertificateRepository::create(int $patientId, int $authorId, string $type, string $issuedOn, array $payload, string $bodyHtml, ?string $cid): int`
- `find(int $id): ?array`, `forPatient(int $patientId, ?int $limit = null): array` (mais recente primeiro), `void(int $id, int $userId, string $reason): void` (lança `InvalidCertificateException` se já anulado ou motivo vazio/ >200).

- [ ] Step 1: testes `test_create_and_find`, `test_for_patient_newest_first_and_limit`, `test_void_marks_and_refuses_twice`, `test_void_requires_reason` (DbTestCase, `makeUser('medico')` + paciente via `Container::patients()`).
- [ ] Step 2: rodar → FAIL (classe inexistente).
- [ ] Step 3: migration + repositório + `Container::certificates()`.
- [ ] Step 4: rodar → PASS; suíte inteira verde (MigratorTest inclusive).
- [ ] Step 5: commit `feat: tabela e repositorio de atestados`.

### Task 2: Especialidade e RQE no cadastro da equipe

**Files:**
- Modify: `app/Users/UserController.php` (campos `especialidade` ≤80, `rqe` ≤40, opcionais, gravados em `specialty`/`rqe`, `null` quando vazios; `blank()`, `fromRow()`, `posted()`), `app/Users/UserRepository.php` (colunas permitidas), view do formulário da equipe.
- Test: teste HTTP existente da equipe (acrescentar casos) ou `tests/Http/UserSpecialtyTest.php`.

- [ ] Step 1: teste: POST de edição com especialidade "Pediatra" e RQE "107605" grava; 81 caracteres → 422 com "A especialidade deve ter até 80 caracteres."; RQE 41 → "O RQE deve ter até 40 caracteres.".
- [ ] Step 2: FAIL. Step 3: implementar. Step 4: PASS + suíte. Step 5: commit `feat: especialidade e RQE no cadastro da equipe`.

### Task 3: Montador do texto (`CertificateText`)

**Files:**
- Create: `app/Certificates/CertificateType.php` (constantes e títulos), `app/Certificates/CertificateText.php`, `app/Certificates/views/_doc.php`
- Test: `tests/Unit/CertificateTextTest.php`

**Interfaces:**
- `CertificateType::TITLES` = `['comparecimento' => 'Declaração de comparecimento', 'acompanhante' => 'Declaração de acompanhante', 'afastamento' => 'Atestado médico', 'aptidao' => 'Atestado de aptidão para atividade física']` (impresso em maiúsculas no PDF).
- `CertificateText::validate(string $type, array $in, string $today): array{0: array $clean, 1: list<string> $errors}` — campos de entrada: `data` (Y-m-d, ≤ hoje, obrigatória), `inicio`/`fim` (H:i, obrigatórios em comparecimento e acompanhante, fim > início), `dias` (afastamento, inteiro 1–90), `a_partir` (afastamento, Y-m-d ≥ `data`, padrão `data`), `acompanhante_nome` (obrigatório ≤150), `acompanhante_cpf` (opcional, 11 dígitos após remover pontuação; impresso `000.000.000-00`), `cid` + `cid_autorizado` (não em aptidão), `observacao` ≤500.
- `CertificateText::numberWords(int $n): string` — 1–90 por extenso ("um", "dois", …, "vinte e um", "noventa").
- `CertificateText::document(string $type, array $clean, array $patient, array $doctor, array $head, string $today): string` — renderiza `_doc.php` e devolve o HTML completo gravado em `body_html`. `$doctor` = linha de `users` (name, specialty, professional_register, rqe); `$head` = settings da receita (`rx_city`, `rx_phone`, `rx_address`).

Texto fixo (copiar exatamente; `[ ]` = trecho omitido quando vazio; artigo pelo `sex` do paciente: F → "a paciente"/"examinada", M → "o paciente"/"examinado"; DATA = dd/mm/aaaa; "nesta data, DATA" quando `data` = hoje, senão "em DATA"):

- comparecimento: "Declaro, para os devidos fins, que **o paciente NOME**[, CPF nº **CPF**], esteve em atendimento médico nesta data, **DATA**, no período das **08h30 às 09h15**." + "Esta declaração comprova o comparecimento ao atendimento médico, não constituindo recomendação de afastamento das atividades por período adicional."
- acompanhante: "Declaro, para os devidos fins, que **ACOMPANHANTE**[, CPF nº **CPF**], esteve presente como acompanhante d**o paciente NOME**[, CPF nº **CPF**], em atendimento médico nesta data, **DATA**, no período das **…**." + "Esta declaração comprova o acompanhamento ao atendimento médico, não constituindo recomendação de afastamento das atividades por período adicional."
- afastamento: "Atesto, para os devidos fins, que **o paciente NOME**[, CPF nº **CPF**], esteve em atendimento médico nesta data, **DATA**, e necessita de **N (extenso) dia(s)** de afastamento de suas atividades a partir de **DATA_INICIO**." ("1 (um) dia", "3 (três) dias").
- aptidão: "Atesto, para os devidos fins, que **o paciente NOME**[, CPF nº **CPF**], foi examinado nesta data, **DATA**, e não apresenta, até o momento, contraindicação clínica para a prática de atividades físicas."
- depois do corpo: "**CID-10:** CÓDIGO" (se CID), parágrafo da observação (nl2br), frase de autorização (se CID).
- cabeçalho: NOME DA MÉDICA (maiúsculas); "Especialidade | CRM | RQE" (itens vazios fora; registro impresso como está; RQE prefixado "RQE " se não começar com "RQE").
- rodapé: "Cidade, 19 de novembro de 2026." (`PrescriptionPrintController::longDate($today)`; sem cidade, só a data), linha, nome, "CRM | RQE", "Assinatura e carimbo", "Telefone: …", "Endereço: …" (omitidos se vazios).

- [ ] Step 1: testes: `test_number_words` (1, 2, 16, 21, 30, 45, 90); `test_cid_without_consent_is_error`; `test_bad_cid_format`; `test_days_out_of_range` (0, 91, "abc"); `test_end_before_start`; `test_future_date_rejected`; `test_comparecimento_text` (contém o parágrafo exato com nome e CPF formatado); `test_no_cpf_omits_clause` (não contém "CPF nº"); `test_social_name` ("Lia (nome civil: Lia Souza)"); `test_cid_lines_only_with_cid`; `test_observation_escaped` (`<script>` → `&lt;script&gt;`); `test_afastamento_plural` ("1 (um) dia de", "3 (três) dias de"); `test_header_footer` (Pediatra | CRM-SP 113058 | RQE 107605; "Campinas, 19 de novembro de 2026.").
- [ ] Step 2: FAIL. Step 3: implementar. Step 4: PASS + suíte. Step 5: commit `feat: textos dos atestados`.

### Task 4: Telas, PDF, anulação e cartão na ficha

**Files:**
- Create: `app/Certificates/CertificateController.php`, `app/Certificates/views/{index,form,show}.php`
- Modify: `app/routes.php`, `app/Patients/PatientController.php` + `app/Patients/views/show.php` (cartão "Atestados": 5 últimos, "Novo atestado", "Ver todos"; só quando `Clinician::is` e `gate->check(view CLINICO)`), `app/Pdf/pdf.css` (`.atestado*`, marca `.atestado-anulado`), `public/assets/css/app.css` (prévia), `tests/Http/SmokeAllRoutesTest.php`
- Test: `tests/Http/CertificatePagesTest.php`

**Rotas** (todas `$authMw`):
- `GET /pacientes/{id:\d+}/atestados` (lista), `GET /pacientes/{id:\d+}/atestados/novo?tipo=` (sem tipo válido: 4 botões; com tipo: formulário; acompanhante pré-preenche o primeiro responsável vinculado), `POST /pacientes/{id:\d+}/atestados` (valida → 422 com erros e valores; ok → grava e redireciona a `/pacientes/{id}/atestados/{certId}` com flash "Atestado emitido."), `GET /pacientes/{id:\d+}/atestados/{certId:\d+}` (prévia + "Baixar PDF" + formulário "Anular" com motivo), `GET …/{certId:\d+}/pdf` (`Response::pdf`, nome `PdfFilename::make('Atestado', nome, issued_on)`; anulado → corpo com faixa "ANULADO — motivo" no topo), `POST …/{certId:\d+}/anular`.
- Atestado de outro paciente → 404.

- [ ] Step 1: testes: médica emite comparecimento → 302 e linha gravada com `body_html` contendo o nome; erro de CID → 422 e nada gravado; nutricionista/secretária → 403 em GET e POST; atestado de outro paciente → 404; PDF começa com `%PDF`; anular grava motivo e a prévia mostra "Anulado"; segunda anulação → 422; cartão aparece na ficha para a médica e não para a nutricionista.
- [ ] Step 2: FAIL. Step 3: implementar; na Smoke acrescentar `'certId' => (string) $this->fx['certificate']` (atestado do paciente de M) e as linhas `'/pacientes/{id}/atestados' => [200, 403, 403, 403, 403, 302]`, idem `/novo`, `/{certId}`, `/{certId}/pdf`.
- [ ] Step 4: PASS + suíte. Step 5: commit `feat: emissao de atestados em PDF`.

### Task 5: Linha do tempo

**Files:** Modify `app/Timeline/TimelineBuilder.php` (tipo `'atestado' => 'Atestado'` em `TYPES`, depois de `'evolucao'`; evento com `issued_on`, texto "Atestado emitido: <título>", link `/pacientes/{id}/atestados/{certId}`, badge "anulado" se anulado; só médica com `view CLINICO`). Test: `tests/Integration/TimelineBuilderTest.php`.

- [ ] Steps: teste (médica vê o evento, nutricionista não; anulado tem badge) → FAIL → implementar → PASS + suíte → commit `feat: atestados na linha do tempo`.

### Task 6: Manual e README

**Files:** `app/Help/content/medico.php` (seção `atestados` logo após `receita`: passos, dica sobre o CID e a anulação, imagens `$img('atestado-novo', '/pacientes/{adulto}/atestados/novo?tipo=comparecimento', …)` e `$img('atestado', '/pacientes/{adulto}/atestados/{atestado}', …)`), `tools/demo/DemoSeed.php` (especialidade/RQE fictícios da médica demo e um atestado de comparecimento do adulto, id `atestado` em ids.json), README (seção de atestados + item de deploy 17: migration 016, copiar public/ e refazer a linha do index.php).

- [ ] Step 1: HelpManualTest / DemoSeedTest acrescentados (seção existe, id `atestado` no retorno do seed) → FAIL.
- [ ] Step 2: implementar; `start_demo_db`, reseed, servidor demo, `node tools/manual_shots.mjs --only medica`, `php tools/manual_pdf.php`; conferir orçamento ≤ 8 MB (HelpAssetsTest).
- [ ] Step 3: PASS + suíte + `node --test "tests/js/*.test.js"`; desligar banco e servidor demo. Commit `docs: atestados no manual e no README`.
