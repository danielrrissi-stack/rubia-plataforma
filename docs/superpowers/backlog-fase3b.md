# Backlog da Fase 3b (tratamento e medicamentos) - pendências menores

Itens que ficaram abertos depois das revisões de cada tarefa.

## Prescrições (repositório)
- create() não roda em transação: se a inserção da dose falhar, sobra uma prescrição órfã.
- update() que limpa a data de término deixa o motivo de encerramento antigo; a data de término alterada por update é auditada como atualização e não é conferida com a última linha de dose.
- end() recusa prescrição já encerrada; as mensagens da tela ficaram para a Tarefa 3.
- Prescrição futura não pode ser encerrada (só excluída), conforme a regra da especificação.
- Editar o início/término pode deixar registros de dose da família fora do período; um término passado feito por edição é auditado como atualização e não como encerramento.
- A primeira linha de dose que acompanha um novo início (update) não gera entrada de auditoria em prescription_doses.
- Eventos ligados a uma prescrição excluída ainda mostram o nome do medicamento.

## Doses e relatos
- review() não tem a guarda "AND reviewed_by IS NULL" contra corrida; reviewed_at mistura date() do PHP com NOW() do banco.
- A checagem de dose duplicada é consulta seguida de inserção (sem índice único; as sessões em arquivo do PHP serializam as requisições de um mesmo usuário, o que mascara a corrida); o valor padrão '' da dose nunca é usado.
- Faltam testes: limite exato de 7 dias e relato da médica com mais de 90 dias.
- O relato oferece só prescrições não futuras na lista, mas o POST aceita prescrições futuras (inconsistente).

## Tela inicial da médica
- unreviewed() corta a lista no PHP (sem LIMIT no SQL); o Gate de authPatient roda antes do 404 (inofensivo).
- Faltam testes: prescrição de outro paciente em efeitos/novo, paciente ou prescrição excluídos no painel, gravidade inválida no "Ciente".

## Receita
- Faltam testes: primeira dose de prescrição futura e receita de prescrição sigilosa impressa pela médica.
- longDate não valida a entrada (só tem um chamador, seguro).

## Portal
- Falta teste no portal para dose registrada antes de a prescrição virar sigilosa (coberto só no repositório).
- PortalTreatmentController tem a terceira cópia de guarda do portal, open e firstName.
- Alinhamento das colunas em tests/Http/SmokeAllRoutesTest.php (linha /portal/conta).
- A família não consegue remover registros de uma prescrição encerrada ontem (fora da janela de remoção do portal).
- O início do portal calcula as prescrições visíveis duas vezes (PortalController::treatment chamado duas vezes).
- O teste de sigilo após os 18 anos (PortalTreatmentTest) não confere o status 200 das páginas antes de checar que o texto não aparece.
- Depois que a médica desmarca o sigilo de um paciente já adulto, não dá para marcar de novo (regra: só menores). Vale uma linha no README.
