-- Catalogo de exames laboratoriais e faixas de referencia pre-carregadas (ver data/exams/SOURCES.md).
-- Ids fixos 1..99 (cadastros proprios comecam em 1000). Recarregar e no-op: nunca sobrescreve edicoes da medica.
-- As faixas de um exame so entram enquanto ele nao foi revisado (reference_confirmed = 0): depois que a medica
-- salva ou confirma as faixas, recarregar o seed nao recria faixa que ela apagou.
INSERT INTO lab_tests (id, code, name, unit, group_name, sort_order, decimals) VALUES
(1, 'glicemia', 'Glicemia de jejum', 'mg/dL', 'glicose', 10, 0),
(2, 'hba1c', 'Hemoglobina glicada (HbA1c)', '%', 'glicose', 20, 1),
(3, 'insulina', 'Insulina de jejum', 'µU/mL', 'glicose', 30, 1),
(4, 'colesterol_total', 'Colesterol total', 'mg/dL', 'lipidios', 40, 0),
(5, 'hdl', 'HDL-colesterol', 'mg/dL', 'lipidios', 50, 0),
(6, 'ldl', 'LDL-colesterol', 'mg/dL', 'lipidios', 60, 0),
(7, 'nao_hdl', 'Colesterol não-HDL', 'mg/dL', 'lipidios', 70, 0),
(8, 'triglicerides', 'Triglicerídeos', 'mg/dL', 'lipidios', 80, 0),
(9, 'tgo', 'TGO (AST)', 'U/L', 'figado', 90, 0),
(10, 'tgp', 'TGP (ALT)', 'U/L', 'figado', 100, 0)
ON DUPLICATE KEY UPDATE id = id;

-- Criancas e adolescentes, 2 a 19 anos (24 a 239 meses): NHLBI 2011, Tabela de cortes aceitavel/limitrofe/alto
-- (valores desejaveis iguais aos da SBC 2017 / Consenso 2016, Tabela II).
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 1, 4, NULL, 24, 239, 170, 200, NULL, NULL, 'NHLBI 2011 / SBC 2017' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 4 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 2, 6, NULL, 24, 239, 110, 130, NULL, NULL, 'NHLBI 2011 / SBC 2017' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 6 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 3, 7, NULL, 24, 239, 120, 145, NULL, NULL, 'NHLBI 2011' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 7 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
-- HDL: limitrofe 40-45, baixo "< 40" gravado como 39 (0 casas decimais).
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 4, 5, NULL, 24, 239, NULL, NULL, 45, 39, 'NHLBI 2011 / SBC 2017' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 5 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 5, 8, NULL, 24, 119, 75, 100, NULL, NULL, 'NHLBI 2011 / SBC 2017' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 8 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 6, 8, NULL, 120, 239, 90, 130, NULL, NULL, 'NHLBI 2011 / SBC 2017' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 8 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;

-- Adultos, 20 anos ou mais (240 meses em diante): SBC 2017 / Consenso 2016, Tabela I, valores desejaveis em jejum.
-- So ha o corte desejavel/nao desejavel (sem limitrofe). LDL e nao-HDL dependem da categoria de risco: sem faixa.
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 7, 4, NULL, 240, NULL, NULL, 190, NULL, NULL, 'SBC 2017 / Consenso 2016' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 4 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
-- HDL desejavel "> 40": 40 ja nao e desejavel, gravado como 40.
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 8, 5, NULL, 240, NULL, NULL, NULL, NULL, 40, 'SBC 2017 / Consenso 2016' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 5 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 9, 8, NULL, 240, NULL, NULL, 150, NULL, NULL, 'SBC 2017 / Consenso 2016' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 8 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;

-- Glicemia de jejum e HbA1c, 2 anos ou mais: ADA (Standards of Care) / SBD, pre-diabetes e diabetes.
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 10, 1, NULL, 24, NULL, 100, 126, NULL, NULL, 'ADA 2025 / SBD' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 1 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 11, 2, NULL, 24, NULL, 5.7, 6.5, NULL, NULL, 'ADA 2025 / SBD' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 2 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;

-- TGP (ALT), 2 a 19 anos: NASPGHAN 2017, limite superior biologico do estudo SAFETY (M 26, F 22 U/L).
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 12, 10, 'M', 24, 239, NULL, 27, NULL, NULL, 'NASPGHAN 2017 (SAFETY)' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 10 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO lab_ranges (id, test_id, sex, age_min_months, age_max_months, borderline_high, abnormal_high, borderline_low, abnormal_low, source)
SELECT 13, 10, 'F', 24, 239, NULL, 23, NULL, NULL, 'NASPGHAN 2017 (SAFETY)' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lab_tests WHERE id = 10 AND reference_confirmed = 1)
ON DUPLICATE KEY UPDATE id = id;
