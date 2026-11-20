# Tabela Brasileira de Composição de Alimentos (TACO): origem

Referência: NEPA/UNICAMP. *Tabela Brasileira de Composição de Alimentos (TACO)*, 4ª edição revisada e ampliada.
Campinas: NEPA-UNICAMP, 2011. 597 alimentos, valores por 100 g de parte comestível. A reprodução é permitida
citando a fonte.

## Arquivos brutos (baixados sem alteração em 2026-11-17)
Repositório `raulfdm/taco-api` (licença MIT), commit `06e466ad2f3d76064b587808b1804564b2c40f67`, pasta `references/csv/`.

- https://raw.githubusercontent.com/raulfdm/taco-api/06e466ad2f3d76064b587808b1804564b2c40f67/references/csv/food.csv
- https://raw.githubusercontent.com/raulfdm/taco-api/06e466ad2f3d76064b587808b1804564b2c40f67/references/csv/nutrients.csv
- https://raw.githubusercontent.com/raulfdm/taco-api/06e466ad2f3d76064b587808b1804564b2c40f67/references/csv/categories.csv

Formato: `food.csv` (`id,categoryId,name`), `nutrients.csv` (`foodId,moisture,kcal,kJ,protein,lipids,cholesterol,carbohydrates,dietaryFiber,...`),
`categories.csv` (`id,name`).

```
b74d52b2762ebe76ef3b0545c0c0a4648a7d97a9f0fea19af7df41251b48e62f  food.csv
0750a00affaf3450d635a6ace0ad86d35c9a9c008da74ea0a529de36b4d40f20  nutrients.csv
c5e4199e12d2c06ab06c5ad0b4a8b56f352e5ec142afbf3c01e18d38fbcc39d2  categories.csv
```

## Normalização do autor
Nos arquivos do autor, `NA` e `*` foram convertidos em campo vazio (= não disponível) e `Tr` (traços) em `0`.
O importador mantém vazio como nulo (não vira zero) e guarda só kcal, proteína, lipídios, carboidratos e fibra alimentar.

## Conversão
`php tools/import_taco.php --sql-only` gera `database/seeds/food_1_taco.sql` (597 linhas, `INSERT ... ON DUPLICATE KEY UPDATE`,
`id` = código TACO). Sem `--sql-only` também carrega no banco. Recarregar não reativa alimentos desativados nem apaga alimentos próprios
(ids a partir de 100000).
