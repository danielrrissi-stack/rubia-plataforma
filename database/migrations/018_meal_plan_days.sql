ALTER TABLE meal_plans
    ADD COLUMN plan_days TINYINT UNSIGNED NULL,
    ADD COLUMN continuation_draft_id INT UNSIGNED NULL,
    ADD COLUMN auto_generated TINYINT(1) NOT NULL DEFAULT 0;
