ALTER TABLE users
    MODIFY email VARCHAR(190) NULL,
    ADD COLUMN username VARCHAR(40) NULL AFTER email,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
    ADD UNIQUE KEY uq_users_username (username);
ALTER TABLE patients
    ADD COLUMN child_user_id INT UNSIGNED NULL,
    ADD COLUMN guardians_see_child_checks TINYINT(1) NOT NULL DEFAULT 1,
    ADD UNIQUE KEY uq_patients_child_user (child_user_id),
    ADD CONSTRAINT fk_patients_child_user FOREIGN KEY (child_user_id) REFERENCES users(id);
CREATE TABLE goal_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_text VARCHAR(160) NOT NULL,
    child_text VARCHAR(160) NULL,
    emoji VARCHAR(16) NOT NULL,
    category ENUM('alimentacao','hidratacao','sono','atividade','telas','rotina') NOT NULL,
    times_per_week TINYINT UNSIGNED NOT NULL DEFAULT 7,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000;
CREATE TABLE patient_goals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NULL,
    family_text VARCHAR(160) NOT NULL,
    child_text VARCHAR(160) NULL,
    emoji VARCHAR(16) NOT NULL,
    category ENUM('alimentacao','hidratacao','sono','atividade','telas','rotina') NOT NULL,
    times_per_week TINYINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    secret TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    KEY idx_patient_goals_patient (patient_id),
    CONSTRAINT fk_patient_goals_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_patient_goals_template FOREIGN KEY (template_id) REFERENCES goal_templates(id),
    CONSTRAINT fk_patient_goals_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_patient_goals_updater FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_patient_goals_deleter FOREIGN KEY (deleted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE goal_checks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    goal_id INT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    checked_by INT UNSIGNED NOT NULL,
    checked_by_role VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_goal_checks (goal_id, day),
    CONSTRAINT fk_goal_checks_goal FOREIGN KEY (goal_id) REFERENCES patient_goals(id),
    CONSTRAINT fk_goal_checks_user FOREIGN KEY (checked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE edu_cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    bands SET('3-6','7-10','11-14','15-19') NOT NULL,
    status ENUM('rascunho','publicado') NOT NULL DEFAULT 'rascunho',
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_edu_cards_approver FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_edu_cards_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000;
