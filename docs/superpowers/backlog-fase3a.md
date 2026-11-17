# Backlog da Fase 3a (metas e área da criança) - pendências menores

Observação: a revisão final pode ainda corrigir parte destes itens.

## Marcações
- O teste da janela de 7 dias não isola D-6/D-7 pelo repositório.
- markable() faz uma consulta redundante.
- Teste com semana fixa no código (hard-coded).

## Conta da criança
- PRIORIDADE: a sessão antiga da criança sobrevive à redefinição de senha pela médica e permite /senha/trocar sem a senha atual.
- isValidUsername: o `$` aceita \n no final (usar \z).
- Nomes de usuário só com pontos são aceitos.
- Maioridade: encerrar deixa a conta da criança vinculada; conta desativada continua inativa.
- ChildArea faz uma consulta extra por requisição.
- O nome de usuário remove todos os espaços.

## Metas (tela da equipe)
- Marcação da grade duplicada em index e show.
- Excluir não pede confirmação.
- Meta com término hoje aparece em "Encerradas".
- Variável $today não usada em um closure.
- Cartão mostra 3 semanas + atual, e a página 8 + atual.
- O preenchimento por ?modelo= usa findTemplate sem checar ativo/categoria.

## CSS
- Regra global de [hidden] no CSS.
