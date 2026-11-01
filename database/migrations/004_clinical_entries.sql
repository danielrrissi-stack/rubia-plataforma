CREATE TABLE clinical_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    kind ENUM('historico_familiar','gestacional','antecedentes','alergias','comorbidades','medicamentos_em_uso','tratamentos_anteriores','evolucao') NOT NULL,
    payload JSON NOT NULL,
    cid10 VARCHAR(10) NULL,
    encounter_date DATE NULL,
    version INT NOT NULL DEFAULT 1,
    previous_id INT UNSIGNED NULL,
    superseded_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_clinical_patient (patient_id, kind),
    CONSTRAINT fk_clinical_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_clinical_prev FOREIGN KEY (previous_id) REFERENCES clinical_entries(id),
    CONSTRAINT fk_clinical_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
