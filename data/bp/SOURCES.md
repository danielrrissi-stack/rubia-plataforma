# Limiares de pressão arterial pediátrica (AAP 2017): origem

Referência publicada: Flynn JT, Kaelber DC, Baker-Smith CM, et al. Clinical Practice Guideline for Screening and
Management of High Blood Pressure in Children and Adolescents. *Pediatrics* 2017;140(3):e20171904.
Tabela 4 (meninos) e Tabela 5 (meninas): PA por idade (1 a 17 anos) e percentil de altura (5, 10, 25, 50, 75, 90, 95),
percentis de PA 50, 90, 95 e 95 + 12 mmHg. Guardamos só 50/90/95; o P95 + 12 é calculado no código.

## Arquivos brutos (baixados sem alteração em 2026-11-01)
Pacote R `pedbp` (Peter E. DeWitt), repositório `dewittpe/pedbp`, commit `ea16fdae7e9e20b92d711b62dafb0975278cfb27`
(branch main, 2026-03-23). Os dois arquivos foram adicionados/alterados pela última vez no commit
`035fdd44262e9f97d153ed39542ff1fa36d73502` (2024-03-22). O script `data-raw/flynn2017.R` do pacote lê esses
arquivos para gerar o conjunto `flynn2017`.

- https://raw.githubusercontent.com/dewittpe/pedbp/ea16fdae7e9e20b92d711b62dafb0975278cfb27/data-raw/flynn2017_bp_boys.dat
- https://raw.githubusercontent.com/dewittpe/pedbp/ea16fdae7e9e20b92d711b62dafb0975278cfb27/data-raw/flynn2017_bp_girls.dat

Formato: separado por espaço; colunas `age bp_%`, depois 7 sistólicas e 7 diastólicas (percentis de altura 5..95).

```
4a0aceeaf8db2a97b1cce3a68aed75013616ee1c74b85f441d4566f94f448a1e  flynn2017_bp_boys.dat
ad38fe10d73e6965c0ad147711d8298654cda3a9bd2d6de1185b67f2955b591e  flynn2017_bp_girls.dat
580cb083831f6b645a5327035e4f5b525ab509fab9afaf6394a31a596d6a9722  aap2017.csv (gerado)
```

## Conversão
`php tools/import_bp.php --from-dat` gera `aap2017.csv` (colunas `sex,age,height_pct,bp_pct,systolic,diastolic`;
sex 1 = masculino, 2 = feminino; 2 × 17 × 7 × 3 = 714 linhas) e `database/seeds/bp_reference.sql`.
Sem `--from-dat` regenera só o seed a partir do CSV. O teste `BpReferenceTest::testCsvMatchesRawSourceFiles`
garante que o CSV corresponde exatamente aos arquivos brutos.

## Conferência independente (2026-11-01)
Comparadas com as tabelas reproduzidas no Manual MSD/Merck (adaptadas de Flynn et al. 2017), 24 linhas completas
(14 valores cada, 336 valores), todas idênticas:
- Meninos, idades 1, 8, 12 e 17, percentis de PA 50/90/95:
  https://www.msdmanuals.com/professional/multimedia/table/blood-pressure-bp-percentile-levels-for-boys-by-age-and-height-measured-and-percentile
- Meninas, idades 1, 6, 10 e 15, percentis de PA 50/90/95:
  https://www.merckmanuals.com/professional/multimedia/table/blood-pressure-bp-percentile-levels-for-girls-by-age-and-height-measured-and-percentile

## Observação
A AAP usa percentis de altura do CDC; este sistema usa o percentil de altura para idade da OMS (hfa) para manter
uma única referência de crescimento (decisão do plano; o relatório informa isso em rodapé).
