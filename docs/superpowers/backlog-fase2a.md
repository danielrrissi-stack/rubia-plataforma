# Pendências menores da Fase 2a (não bloqueiam produção)

Lista registrada pelas revisões por tarefa durante a Fase 2a (plano alimentar), já com a triagem da revisão final da branch.
O que foi corrigido na branch saiu da lista (veja "Corrigido na branch" no fim). Tudo o que está abaixo **pode esperar**.

## Pode esperar

### Task 1 (base de alimentos)

- Busca exige 2 letras somando todos os termos (`"a b"` já busca); poderia exigir 2 letras em algum termo.
- Sem teste unitário para a conversão da TACO `Tr` → 0 e `*`/`NA` → nulo.
- `tools/migrate.php` abre uma segunda conexão `Database` só para os seeds.

### Task 2 (resolvedor e frases)

- Mais de 5 opções também mostra o erro enganoso "inclua ao menos uma opção".
- `FamilyPhrase` com quantidade minúscula (0,001) mostra "0"; o resolvedor aceita quantidades muito pequenas.
- `MealPlanResolver` guarda `$errors` na instância (não reentrante).
- `PofMeasureImporter` usa `number_format(1)`: gramas com mais de 1 casa são arredondadas.
- Dados: aproximações POF dos alimentos 561 e 551 — a nutricionista deve conferir (o README avisa).

### Task 3 (texto do plano)

- Uma linha única de 2 MB perde o conteúdo (retrocesso da regex de negrito); `•` sozinho vira `<li>•</li>`.
- Lacunas de teste: `**` sem par, troca `ul`→`ol`, exclusão de título na regra R5, texto vazio. A regex de item roda 3 vezes por linha.

### Task 4 (repositório)

- `publish`: aviso teórico quando o `SELECT ... FOR UPDATE` não acha a linha.

### Task 5 (editor de texto)

- "Novo plano" com "Descartar e começar outro": descarte e criação não são uma transação única (a origem já é validada antes do descarte).
- Pré-visualização: respostas fora de ordem podem mostrar uma prévia antiga por um instante.
- Os botões "Título de refeição", "Item" e "Negrito" usam `setRangeText`, que zera o Ctrl+Z do navegador.
- `routes.php`: espaços/ordem dos `use`.

### Task 6 (portal)

- Planos em texto podem conter calorias digitadas pelo autor (o README avisa).
- `currentPlan` carrega o histórico só para achar o nome de quem publicou.
- Asserção solta em `'ou '`; sem teste de criança nas rotas de alimentação.

### Task 7 (editor estruturado)

- Resultado da busca substitui a entrada do catálogo no navegador e perde uma medida desativada usada no plano (tela ≠ salvo até recarregar).
- `/alimentos/busca` exige só equipe, sem checar a permissão NUTRICAO no Gate.
- Plural das medidas não é usado nos rótulos do seletor; `aria-label` não é atualizado ao renomear refeição.
- Alimento excluído de verdade do banco (não só desativado) deixa o rascunho preso em 422.

### Task 8 (Minha tabela)

- `UPDATE`s sem `AND source = 'proprio'` (a checagem é feita antes, em PHP); guarda de array no campo `ativo`.
- Linhas de auditoria sem teste para medida/updateOwn/setActive; sem teste Http de edição de medida POF; `colspan` 4 vs 5.

### Task 9 (modelos e trechos)

- Sem teste de `fromPlan` com plano estruturado; teste do editor de modelo estruturado só verifica 200.
- `TemplateRepository::clean` não aplica `mb_scrub` (o controller já aplica).
- "Salvar como modelo" aparece também em rascunhos.
- `routes.php`: espaço perdido.

## Corrigido na branch

Docblock do `SeedLoader`; `measure_id` não numérico; "Array to string"; UTF-8 inválido no texto; trava do `draftFrom`;
SQL de modelo no controller; indentação do `catch` do descarte; e, na revisão final: título "TÍTULO" do botão de título de refeição,
troca de alimento mantendo a quantidade em outra unidade, busca editada mantendo o alimento antigo, linhas sem alimento descartadas
em silêncio, cliques ignorados durante o salvamento automático e falta de tempo limite, impressão sem totais mostrando kcal,
aviso "valor não disponível na TACO", revisão antiga após publicação recusada, opção extra vazia publicada, "Opção N" com uma única
opção, colagem personalizada (Ctrl+Z), origem inválida descartando o rascunho, ordem das medidas, erros sem rolagem até a caixa,
auditoria a cada salvamento automático de modelo, notas do README (salvamento automático, `public/`, `schema_migrations`).
