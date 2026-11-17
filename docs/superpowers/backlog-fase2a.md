# Pendências menores da Fase 2a (não bloqueiam produção)

Lista registrada pelas revisões por tarefa durante a Fase 2a (plano alimentar). A revisão final da branch inteira fará a triagem: o que for corrigido sai daqui, o resto fica como 'pode esperar'.

## Task 2

- minor (deferred): MealPlanResolver int() turns non-numeric measure_id into null → silently grams (carry to T7: controller/resolver must reject non-empty non-integer measure_id with "medida inválida").
- minor (deferred): >5 options also adds misleading "inclua ao menos uma opção" error.
- minor (deferred): (string) casts on array input raise "Array to string" warning (carry to T7 with is_scalar guards).
- minor (deferred): FamilyPhrase qty(0.001) → "0"; resolver allows tiny qty. Resolver $errors instance state non-reentrant. PofMeasureImporter number_format(1) rounds >1 decimal grams.
- data note: 561 and 551 POF approximations — README (T10) flags for nutritionist review.

## Task 3

- minor (deferred): invalid UTF-8 makes normalizePaste return "" (carry to T5: scrub input with mb_scrub before normalize/save); 2MB single line drops content (bold regex backtrack); lone • → <li>•</li>; test gaps (unbalanced **, ul→ol switch, R5 title exclusion, empty string); item regex matched 3x.

## Task 4

- minor (deferred): draftFrom checks source status outside lock; title length/mode not validated in repo (controller must validate: T5).
- minor (deferred): publish null FOR UPDATE row theoretical warning.

## Task 5

- minor (deferred): create discard+create not atomic; stale revisao after failed publish → false conflict; preview out-of-order responses; setRangeText kills undo; routes.php whitespace/use order; template SQL in controller (T9).
- minor (deferred): misaligned indentation in discard catch block.

## Task 6

- minor (deferred): text plans may contain kcal typed by author (UI hint); currentPlan loads history for publisher name; loose 'ou ' assertion; no crianca test on food routes.

## Task 8

- minor (deferred): UPDATEs lack AND source='proprio'; ativo array guard; audit rows untested for measure/updateOwn/setActive; no Http test for POF measure edit; colspan 4 vs 5.

## Task 9

- minor (deferred): template autosave audits every tick (bloat); no fromPlan-structured test, no template audit test; routes.php lost space; TemplateRepository::clean doesn't scrub; structured template editor test only checks 200; "Salvar como modelo" shown on drafts.
