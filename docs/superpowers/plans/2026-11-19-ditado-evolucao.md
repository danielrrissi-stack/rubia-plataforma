# Ditado na evolução — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** botão "🎤 Ditar" por campo da evolução usando o reconhecimento de voz do navegador.

**Architecture:** HTML da evolução ganha botões/aviso `hidden` com `data-dictate="<campo>"`; `public/assets/js/dictation.js` detecta `SpeechRecognition || webkitSpeechRecognition`, revela os controles e acrescenta o texto ao `textarea`. A lógica de texto (pontuação falada, maiúsculas, junção) é uma função pura `DictationText.apply(current, spoken)` exportada para teste em Node.

**Tech Stack:** PHP 8.1 views, JS puro (sem módulos), testes PHPUnit + `node --test` (só no desenvolvimento).

**Spec:** docs/superpowers/specs/2026-11-19-ditado-evolucao-design.md

## Global Constraints
- Sem script inline (CSP `default-src 'self'`); JS em `public/assets/js/`.
- Idioma `pt-BR`; copy exata do aviso e mensagens como na spec.
- Nada enviado ao servidor além do POST normal do formulário.

## Review Focus
- Texto já existente no campo nunca é apagado nem duplicado (resultados finais repetidos do Chrome em `continuous`).
- Iniciar ditado em outro campo para o anterior sem perder o que já entrou.
- `maxlength` 20000 respeitado ao acrescentar.
- Firefox/sem suporte: página idêntica à de hoje (controles ocultos).
- Microfone negado: mensagem e botão volta a "🎤 Ditar".

---

### Task 1: Controles no formulário (PHP)
**Files:** Modify `app/Records/views/evolution_form.php`; Test `tests/Http/RecordPagesTest.php`
- [ ] Teste `testEvolutionFormHasHiddenDictationControls`: GET da evolução como médica → contém `data-dictate="anamnese"` … para os 6 campos, `id="ditado-aviso"` com `hidden`, o texto do aviso da spec, e `<script src="/assets/js/dictation.js">`.
- [ ] Rodar → FAIL. Implementar. Rodar → PASS. Commit.

### Task 2: `dictation.js` + CSS
**Files:** Create `public/assets/js/dictation.js`, `tests/js/dictation.test.js`; Modify `public/assets/css/app.css`
- [ ] Testes Node (`node --test "tests/js/*.test.js"`): `apply('', 'paciente refere dor vírgula melhora ponto')` → `'Paciente refere dor, melhora.'`; `apply('Texto.', 'nova linha conduta mantida')` → `'Texto.\nConduta mantida'`; `apply('abc', 'def')` → `'abc def'`; `apply('a', 'ponto e vírgula b interrogação')` → `'a; b?'`; `apply('x', 'dois pontos y')` → `'x: y'`.
- [ ] Rodar → FAIL. Implementar `DictationText.apply` e o controlador do navegador (um reconhecedor por vez; só `isFinal` acrescenta; prévia `interim`; erros `not-allowed`/`service-not-allowed` → "Microfone bloqueado — libere o acesso nas configurações do navegador.", demais → "Não foi possível ditar agora."; corta em `maxlength`; dispara `input`). Rodar → PASS. Commit.

### Task 3: README (deploy: copiar public/) e suíte completa
- [ ] Nota no README; `php tools/phpunit.phar` e `node --test "tests/js/*.test.js"` verdes; commit.
