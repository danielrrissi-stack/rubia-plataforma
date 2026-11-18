# Backlog da Fase 3c (diário de hábitos) - pendências menores

Itens que ficaram abertos depois das revisões de cada tarefa.

## Fotos
- Falta um limite de dimensões antes de abrir a imagem no GD (proteção contra imagem gigante que cabe no limite de bytes).
- Orientações EXIF espelhadas (2, 4, 5 e 7) são aproximadas só por rotação.
- Sem a extensão exif a orientação é ignorada em silêncio (o README já avisa que GD e exif são necessárias).
- Os testes de foto são pulados quando o GD está carregado; torná-los independentes do GD.
- Falta um teste de ponta a ponta do caminho GD com foto que tem orientação EXIF.
- Os testes de fumaça acumulam um PNG de 1 pixel por execução em storage/uploads/diario.

## Repositórios
- forRange filtra a visibilidade no PHP, não no SQL.
- Os dados de auditoria antes/depois incluem colunas vindas de junções.
- O clean() do sono monta horários ':00' antes de a validação recusar.

## Telas da equipe
- O botão "Excluir" aparece em pedidos que já têm registros (o repositório recusa e mostra aviso).
- O cartão da ficha esconde pedidos futuros.
- A grade mostra o perfil cru (medico/paciente) em "Anotado por"; mapear para rótulos.
- may_comment fica falso para registro cujo pedido saiu do conjunto visível da semana; usar mayEdit do pedido do registro.

## Portal
- PortalController usa nome completo de classe em vez de use.
- Dia ilegível no POST vira hoje.
- A marca de foto não é preservada quando o formulário volta com erro 422.

## Testes
- Asserção de auditoria negada fraca (>= 2); o teste de 403 antes do Gate não prova a ordem; o ramo 403 de mayEdit não é testado (inalcançável).
- Fixture dos testes de fumaça com Container::reset() no meio da montagem é frágil.
- Falta teste: nutricionista comentando registro não editável.
- Faltam testes: editar/remover registro com mais de 7 dias; equipe acessando as rotas POST do diário do portal.
