# Backlog da Fase 4a (agenda) - pendências menores

Itens que ficaram abertos depois das revisões de cada tarefa.

- Horários sobrepostos quando um horário extra cai sobre as faixas da semana.
- A grade não desenha linhas de "fechado" e "intervalo" (os fechamentos aparecem como faixa de aviso).
- Consulta de contato N+1 por linha marcada.
- `AgendaMailer::find` repete o SELECT do repositório.
- Parâmetros `$today` sem uso em `AgendaMailer` (marcada e cancelada).
- As regras de transição de estado estão duplicadas nos botões do `AgendaController`.
- `nextFree` executa até 60 chamadas de `dayView`.
- A família ainda não pode pedir consulta (o estado "reservada" fica reservado para isso).

Itens adiados na revisão final:

- Clique duplo em "Marcar": a segunda tentativa termina numa tela 422 ("Este horário não está mais livre."), embora a consulta tenha sido marcada pela primeira.
- Cancelar uma consulta que já passou (ainda "agendada"/"confirmada") manda e-mail à família sobre uma data passada.
- Em `forPatient`, as "próximas" podem incluir consulta do mesmo dia já "realizada" ou "faltou".
- O atalho para a evolução fica na tela da consulta, não no cartão de Consultas da ficha.
- O DDL da `013_agenda.sql` não é transacional: se a migração falhar no meio, o conserto é manual.
- O celular do responsável é um só para todos os pacientes dele (vale explicar no texto de ajuda depois).
- Pacientes com exclusão lógica não são filtrados nas consultas da agenda (importa para a fase 4b).
- Consulta marcada para amanhã depois da rodada das 08:00 de hoje não recebe o lembrete da véspera.
