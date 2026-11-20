# Pendências da Fase 4d (linha do tempo e meta de peso)

Encontradas na revisão final de 2026-11-19. Todas resolvidas em 2026-11-19 (branch `fase4d-pendencias`):

- **Tendência com data passada:** quando a reta cruza o alvo antes de hoje, a frase passa a ser "Pela média das medições já estaria no alvo, mas a última medição ainda não chegou lá." (`ja_deveria`).
- **Peso equivalente (meta em escore-z):** usa a data e a altura corrigida (deitado/em pé) da última medição com altura.
- **Link da consulta na linha do tempo:** só aparece quando a pessoa gerencia aquela agenda (`canManageExisting`); a situação usa `AgendaStatus::label` ("Realizada", "Faltou").
- **Consultas ao banco:** tabelas da OMS carregadas uma vez por requisição; doses numa consulta só; medidas carregadas uma vez na linha do tempo e na página da meta. Testes conferem que o número de consultas não cresce com os registros.
- **Duplo clique em "Salvar meta":** o salvamento trava a linha do paciente e a mesma meta enviada de novo não cria outra.
- **Período de 3/12 meses:** 31/05 − 3 meses = 28/02 (último dia do mês quando o dia não existe).
- **Testes que faltavam:** liberação revogada (403 na meta, no JSON e na linha do tempo) e contas paciente/responsável/criança na linha do tempo.

Também: `PortalExamsTest` deixou de falhar por acaso (o token aleatório da página podia conter "140").
