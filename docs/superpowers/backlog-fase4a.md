# Backlog da Fase 4a (agenda) - pendências menores

Itens que ficaram abertos depois das revisões de cada tarefa.

- Horários sobrepostos quando um horário extra cai sobre as faixas da semana.
- O botão "Agendar" do cartão da paciente é avaliado só pela agenda padrão.
- A grade não desenha linhas de "fechado" e "intervalo" (os fechamentos aparecem como faixa de aviso).
- Consulta de contato N+1 por linha marcada.
- `AgendaMailer::find` repete o SELECT do repositório.
- Parâmetros `$today` sem uso em `AgendaMailer` (marcada e cancelada).
- As regras de transição de estado estão duplicadas nos botões do `AgendaController`.
- `nextFree` executa até 60 chamadas de `dayView`.
- A família ainda não pode pedir consulta (o estado "reservada" fica reservado para isso).
