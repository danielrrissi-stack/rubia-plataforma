CREATE TABLE evolution_settings (
    patient_id INT UNSIGNED NOT NULL PRIMARY KEY,
    adult_mode ENUM('kg','pct') NOT NULL DEFAULT 'kg',
    kg_per_week DECIMAL(4,2) NOT NULL,
    pct_per_4w DECIMAL(4,2) NOT NULL,
    z_per_4w DECIMAL(4,3) NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_evolution_settings_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_evolution_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
