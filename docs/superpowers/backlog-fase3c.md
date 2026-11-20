# Backlog da Fase 3c (diário de hábitos) - pendências menores

Itens que ficaram abertos depois das revisões de cada tarefa.

## Fotos
- Orientações EXIF espelhadas (2, 4, 5 e 7) são aproximadas só por rotação.
- Sem a extensão exif a orientação é ignorada em silêncio (o README já avisa que GD e exif são necessárias).
- Falta um teste de ponta a ponta do caminho GD com foto que tem orientação EXIF.
- `imagedestroy()` em `DiaryPhotoStore::resized()` não faz nada desde o PHP 8.0 e fica obsoleto no 8.5; trocar por `unset()` antes de atualizar o PHP.

## Repositórios
- forRange filtra a visibilidade no PHP, não no SQL.
- Os dados de auditoria antes/depois incluem colunas vindas de junções.
- O clean() do sono monta horários ':00' antes de a validação recusar.

## Telas da equipe

## Portal
- PortalController usa nome completo de classe em vez de use.
- A marca de foto não é preservada quando o formulário volta com erro 422.

## Testes
- Asserção de auditoria negada fraca (>= 2); o teste de 403 antes do Gate não prova a ordem; o ramo 403 de mayEdit não é testado (inalcançável).
- Fixture dos testes de fumaça com Container::reset() no meio da montagem é frágil.
- Falta teste: nutricionista comentando registro não editável.
- Faltam testes: editar/remover registro com mais de 7 dias; equipe acessando as rotas POST do diário do portal.
