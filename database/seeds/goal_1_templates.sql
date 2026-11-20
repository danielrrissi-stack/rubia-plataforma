-- Biblioteca de metas pre-carregada (ids fixos 1..999; cadastros proprios comecam em 1000).
-- Recarregar e no-op: nunca sobrescreve edicoes da medica.
INSERT INTO goal_templates (id, family_text, child_text, emoji, category, times_per_week, sort_order) VALUES
(1, 'Frutas no almoço e no jantar', 'Comi fruta hoje', '🍎', 'alimentacao', 7, 10),
(2, 'Verduras ou legumes no almoço', 'Comi legumes ou verduras', '🥦', 'alimentacao', 7, 20),
(3, 'Comer sentado à mesa, sem tela', 'Comi sentado à mesa', '🍽️', 'alimentacao', 7, 30),
(4, 'Tomar café da manhã todos os dias', 'Tomei café da manhã', '🥣', 'alimentacao', 7, 40),
(5, 'Experimentar um alimento novo', 'Provei um alimento novo', '😋', 'alimentacao', 3, 50),
(6, 'Comer devagar e mastigar bem', 'Comi devagar, mastigando bem', '🐢', 'alimentacao', 7, 60),
(7, 'Beber água nas refeições', 'Bebi água nas refeições', '💧', 'hidratacao', 7, 70),
(8, 'Beber água ao longo do dia', 'Bebi água durante o dia', '🚰', 'hidratacao', 7, 80),
(9, 'Levar a garrafinha de água para a escola', 'Levei minha garrafinha', '🧴', 'hidratacao', 5, 90),
(10, 'Trocar o suco ou refrigerante por água', 'Escolhi água em vez de suco ou refrigerante', '🥤', 'hidratacao', 5, 100),
(11, 'Dormir no horário combinado', 'Fui dormir na hora combinada', '😴', 'sono', 7, 110),
(12, 'Desligar as telas 1 hora antes de dormir', 'Desliguei as telas antes de dormir', '🌙', 'sono', 7, 120),
(13, 'Acordar no horário combinado', 'Acordei na hora certa', '⏰', 'sono', 5, 130),
(14, 'Brincar ou se exercitar por 1 hora', 'Brinquei bastante hoje', '⚽', 'atividade', 5, 140),
(15, 'Caminhar ou andar de bicicleta', 'Passeei a pé ou de bicicleta', '🚲', 'atividade', 3, 150),
(16, 'Atividade em família ao ar livre', 'Brinquei ao ar livre com a família', '🌳', 'atividade', 2, 160),
(17, 'Limitar o tempo de tela a 2 horas por dia', 'Usei as telas só um pouquinho', '📱', 'telas', 7, 170),
(18, 'Sem telas durante as refeições', 'Comi sem usar telas', '📵', 'telas', 7, 180),
(19, 'Uma pausa das telas para brincar', 'Fiz uma pausa das telas para brincar', '🧩', 'telas', 5, 190),
(20, 'Arrumar a mochila na noite anterior', 'Arrumei minha mochila', '🎒', 'rotina', 5, 200),
(21, 'Escovar os dentes após as refeições', 'Escovei os dentes', '🪥', 'rotina', 7, 210),
(22, 'Ajudar a família a preparar uma refeição', 'Ajudei a preparar a comida', '👩‍🍳', 'rotina', 2, 220)
ON DUPLICATE KEY UPDATE id = id;
