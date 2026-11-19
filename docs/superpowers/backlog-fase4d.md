# Pendências da Fase 4d (linha do tempo e meta de peso)

Encontradas na revisão final de 2026-11-19 e deixadas para depois (nenhuma mostra dado a quem não pode ver).

- **Tendência com data passada:** se o paciente atingiu o alvo e depois voltou a subir, a reta pode cruzar o alvo antes de hoje e a frase dizer "chega ao alvo em {mês passado}". Tratar cruzamento ≤ hoje como "não se aproxima" ou outra frase (`WeightGoalProgress::trend`).
- **Peso equivalente (meta em escore-z):** usa a idade de hoje e a altura sem a correção deitado/em pé; a spec pede idade e altura da última medição (diferença pequena; maior em bebês).
- **Link da consulta na linha do tempo:** aponta para `/agenda/consultas/{id}`, que dá 404 para quem não gerencia aquela agenda (médica não administradora vendo consulta de outra profissional). Usar a mesma checagem do cartão Consultas (`canManageExisting`) e mostrar a situação com `AgendaStatus::label`.
- **Consultas ao banco:** a linha do tempo e a página da meta fazem muitas consultas pequenas (doses por prescrição, escore-z por medição, uma avaliação por meta antiga). Sem impacto no tamanho atual da clínica; otimizar carregando as medidas uma vez e as doses com `IN (...)`.
- **Duplo clique em "Salvar meta":** dois envios simultâneos podem dar erro 500 (índice de meta ativa única). Usar `SELECT … FOR UPDATE` ou mostrar "a meta mudou, recarregue".
- **Período de 3/12 meses:** `modify('-3 months')` em 31/05 começa em 03/03 em vez de 28/02 (alguns dias a menos).
- **Testes faltando:** liberação revogada (403 na meta, no JSON e na linha do tempo) e papéis paciente/responsável/criança em `/linha-do-tempo` (o smoke cobre os códigos, mas não há teste dedicado).
