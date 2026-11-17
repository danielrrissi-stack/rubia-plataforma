# Backlog da Fase 3a (metas e área da criança) - pendências menores

Itens que ficaram abertos depois da revisão final e da rodada de correções.

## Marcações
- O teste da janela de 7 dias não isola D-6/D-7 pelo repositório.
- markable() ainda faz duas consultas (meta visível + marcações do dia).
- Teste com semana fixa no código (hard-coded).
- GoalCalc: faltam testes dos limites inclusivos (primeiro e último dia da meta, D-6 exato).

## Conta da criança
- Maioridade: "encerrar" deixa a conta da criança vinculada ao paciente (sem uso, mas ativa).
- ChildArea faz uma consulta extra por requisição.
- O nome de usuário remove todos os espaços (em vez de recusar).

## Metas (tela da equipe)
- Marcação da grade duplicada em index e show.
- Meta com término hoje aparece em "Encerradas".
- Variável $today não usada em um closure.
- Cartão mostra 3 semanas + atual, e a página 8 + atual.

## Cartões educativos
- EduCardRepository faz um UPDATE extra logo depois do INSERT.
- PortalGoalController::card() percorre os cartões sem parar no encontrado (loop sem break).
- Faltam testes: paciente sem data de nascimento (nenhum cartão) e publicar cartão inexistente (404).

## CSS
- Regra global de [hidden] no CSS.
