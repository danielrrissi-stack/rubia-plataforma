CREATE TABLE energy_goals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    formula ENUM('mifflin','harris_benedict','fao_oms_1985') NOT NULL,
    activity_factor DECIMAL(4,3) NOT NULL,
    weight_kg DECIMAL(6,3) NOT NULL,
    height_cm DECIMAL(5,1) NOT NULL,
    age_years DECIMAL(5,2) NOT NULL,
    tmb_kcal INT NOT NULL,
    get_kcal INT NOT NULL,
    notes TEXT NULL,
    superseded_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_energy_patient (patient_id),
    CONSTRAINT fk_energy_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_energy_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
