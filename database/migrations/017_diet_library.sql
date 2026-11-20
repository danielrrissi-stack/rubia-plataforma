CREATE TABLE diet_library (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    band ENUM('infantil','adulto') NOT NULL,
    week TINYINT UNSIGNED NOT NULL,
    season ENUM('verao','outono','inverno','primavera') NOT NULL,
    kcal_target SMALLINT UNSIGNED NOT NULL,
    kcal DECIMAL(7,1) NOT NULL,
    protein_g DECIMAL(6,1) NOT NULL,
    carb_g DECIMAL(6,1) NOT NULL,
    fat_g DECIMAL(6,1) NOT NULL,
    fiber_g DECIMAL(6,1) NOT NULL,
    title VARCHAR(150) NOT NULL,
    guidance_text TEXT NOT NULL,
    structure_json MEDIUMTEXT NOT NULL,
    UNIQUE KEY uq_diet_library_code (code),
    KEY idx_diet_library_pick (band, week, kcal_target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE patient_food_restrictions (
    patient_id INT UNSIGNED NOT NULL PRIMARY KEY,
    items VARCHAR(255) NOT NULL DEFAULT '',
    other_text VARCHAR(500) NULL,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_food_restr_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_food_restr_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE meal_plans
    ADD COLUMN source_diet_code VARCHAR(40) NULL;
