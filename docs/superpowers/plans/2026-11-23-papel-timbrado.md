# Papel timbrado Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução nativa, autorizada pelo usuário). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Três papéis timbrados A4 (Receita, Pedido de Exames, Anotações) em PDF, em branco em `/admin/receita` e com dados do paciente na ficha.

**Architecture:** Novo módulo `app/Letterhead` (tipos, dados, controller, views, SVG). O `PdfRenderer` ganha folha de estilo e pasta de fontes opcionais; tudo posicionado em mm sobre um SVG A4 de fundo.

**Tech Stack:** PHP 8.3, Dompdf 3.1.6 (lib/dompdf), SVG, fonte Nunito (OFL).

**Spec:** docs/superpowers/specs/2026-11-23-papel-timbrado-design.md

## Global Constraints
- Sem migração; nada gravado além da auditoria. Uma página sempre. `Cache-Control: no-store`.
- Receita com medicamentos e atestados não mudam.
- "Clínica Crescer em Família", site `www.rubiapediatra.com.br` e aplicativo `rubia.med.br` fixos; médica = usuário logado; contato = `headSettings()`.
- Rótulo "WhatsApp". Fontes em pasta só de leitura. Nunca duas execuções do phpunit ao mesmo tempo.
- Não tocar em `evolution-chart.js`, seu teste e "o que fazer.txt".

## Review Focus
- Nome do paciente de 150+ caracteres: diminui, não cria 2ª página nem passa por cima da data.
- Endereço no limite (200) + cidade (80): continua dentro do cabeçalho.
- Cadastro sem CRM/RQE/especialidade/telefone: linha e ícone somem.
- CRM já gravado como "CRM 123" / RQE como "RQE 9": sem prefixo duplicado.
- HTML no nome ou no endereço: escapado.

## Task 1: Arte vetorial e fontes
**Files:** Create `app/Letterhead/art/fundo.svg`, `whatsapp.svg`, `local.svg`, `site.svg`; `lib/papel-fonts/` (Nunito Regular/SemiBold/ExtraBold `.ttf`+`.ufm`, `installed-fonts.json`, `OFL.txt`).
- [ ] Traçar logo, letras e frase a partir do modelo ampliado; ondas e réguas como geometria; marca-d'água = símbolo em escala com cores claras.
- [ ] Renderizar em PNG e comparar com o modelo. Commit.

## Task 2: PdfRenderer e Response
**Files:** Modify `app/Pdf/PdfRenderer.php`, `app/Core/Response.php`, `app/Core/Container.php`; Test `tests/Unit/PdfRendererTest.php`.
**Produces:** `PdfRenderer::render(string $title, string $bodyHtml, ?string $cssFile = null, ?string $fontDir = null): string`; `Response::pdf(string $bytes, string $name, bool $inline = false)`.
- [ ] Testes: sem os parâmetros nada muda; com fontDir o PDF contém `Nunito`; `inline` gera `Content-Disposition: inline`.
- [ ] Implementar, rodar, commit.

## Task 3: Módulo e botões
**Files:** Create `app/Letterhead/PaperType.php`, `LetterheadDoc.php`, `LetterheadController.php`, `views/_doc.php`, `views/_buttons.php`, `app/Pdf/papel.css`; Modify `app/routes.php`, `app/Patients/PatientController.php`, `app/Patients/views/show.php`, `app/Medications/views/rx_settings.php`; Test `tests/Unit/LetterheadDocTest.php`, `tests/Http/LetterheadTest.php`, `tests/Http/SmokeAllRoutesTest.php`.
**Produces:** `PaperType::TITLES` = `receita` "Receita", `exames` "Pedido de Exames", `anotacoes` "Anotações"; `LetterheadDoc::html(string $type, array $doctor, array $head, ?array $patient, string $today): string`; `LetterheadController::can(array $user, int $patientId): bool`.
Rotas: `GET /admin/receita/papel/{tipo}` (administrador) e `GET /pacientes/{id}/papel/{tipo}` (médico + Gate `view` MEDICAMENTOS). Tipo inválido: 404.
Auditoria só do paciente: `view` / `patients` / `{"section":"papel_timbrado","tipo":...}`.
- [ ] Testes do documento (Review Focus) e HTTP (3 tipos, 404, 403, inline, auditoria, botões antes do `<form` e depois de `cartoes-paciente`, uma página).
- [ ] Ver falhar, implementar, ver passar, suíte inteira, commit.

## Task 4: Conferência visual, manual, README
- [ ] Rasterizar o PDF com Ghostscript e comparar com o modelo, em branco, preenchido e com textos longos.
- [ ] Botões numa página real no navegador.
- [ ] Atualizar `app/Help/content/medico.php`, README, título do menu; regenerar manual da médica; commit.
