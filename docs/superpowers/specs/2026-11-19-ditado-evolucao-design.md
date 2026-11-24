# Ditado por voz na evolução — design curto

**Pedido (usuário, 2026-11-19):** em `/pacientes/{id}/prontuario/evolucao`, além de digitar, ditar em áudio cada um dos campos.

**Por quê:** a médica registra a evolução durante/depois da consulta; falar é mais rápido que digitar.

## Abordagens consideradas
1. **Reconhecimento de voz do navegador (Web Speech API)** — grátis, sem servidor; Chrome/Edge/Safari (não Firefox); o áudio vai ao Google/Microsoft/Apple. **Escolhida pelo usuário.**
2. Ditado do sistema/teclado (Win+H, microfone do celular) — zero código, mesma privacidade; continua disponível.
3. Transcrição paga com contrato — melhor vocabulário médico, custo por minuto e mais trabalho.

## Design (aprovado)
- Botão "🎤 Ditar" ao lado do rótulo de cada campo de `RecordController::EVOLUTION_FIELDS` (anamnese, exame físico, diagnóstico, conduta, plano terapêutico, encaminhamentos). Só a tela da evolução.
- Clicar inicia; o botão vira "■ Parar"; o texto reconhecido é acrescentado ao fim do que já está no campo (separado por espaço). Prévia do trecho ainda não confirmado em cinza abaixo do campo ("ouvindo… …").
- Um campo por vez: iniciar em outro para o anterior. Para sozinho quando o navegador encerra (silêncio) ou ao clicar "Parar".
- Idioma `pt-BR`. Pontuação falada: "vírgula" → `,`, "ponto" / "ponto final" → `.`, "dois pontos" → `:`, "ponto e vírgula" → `;`, "interrogação" → `?`, "nova linha" / "próxima linha" / "parágrafo" → quebra de linha. Primeira letra após `.`/`?`/quebra em maiúscula.
- Aviso uma vez, no topo do formulário, só quando o navegador suporta: "O ditado usa o reconhecimento de voz do navegador: o áudio vai para o Google (Chrome), a Microsoft (Edge) ou a Apple (Safari). Revise o texto antes de salvar."
- Sem suporte (Firefox) → botões e aviso não aparecem (ficam `hidden` no HTML; o JS revela).
- Microfone negado/erro → mensagem curta abaixo do campo ("Microfone bloqueado — libere o acesso nas configurações do navegador." / "Não foi possível ditar agora."); o campo continua editável.
- Nada muda no servidor: nenhum áudio gravado, nada salvo automaticamente; respeita o `maxlength` (20000) do campo.
- CSP atual (`default-src 'self'`) basta: JS em `public/assets/js/dictation.js`, sem script inline.

## Deixado ao implementador
Estilo visual do botão (reaproveitar `btn btn-quiet`), texto exato da prévia.

## Deploy
Copiar `public/` (arquivo JS e CSS novos). Sem migração.
