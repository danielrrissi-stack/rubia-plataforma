CREATE TABLE patients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    social_name VARCHAR(150) NULL,
    birth_date DATE NOT NULL,
    sex ENUM('M','F') NOT NULL COMMENT 'sexo biologico - usado nas curvas',
    cpf VARCHAR(14) NULL,
    cns VARCHAR(20) NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    email VARCHAR(190) NULL,
    address TEXT NULL,
    responsible_professional_id INT UNSIGNED NOT NULL,
    admin_notes TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_patients_user (user_id),
    KEY idx_patients_name (full_name),
    CONSTRAINT fk_patients_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_patients_resp FOREIGN KEY (responsible_professional_id) REFERENCES users(id),
    CONSTRAINT fk_patients_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE guardian_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    relationship VARCHAR(30) NOT NULL,
    active TINYINT NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    ended_by INT UNSIGNED NULL,
    KEY idx_guardian_patient (patient_id),
    KEY idx_guardian_user (user_id),
    CONSTRAINT fk_guardian_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_guardian_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_guardian_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_guardian_ender FOREIGN KEY (ended_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE professional_access (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    professional_id INT UNSIGNED NOT NULL,
    granted_by INT UNSIGNED NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    revoked_by INT UNSIGNED NULL,
    KEY idx_access_patient (patient_id),
    KEY idx_access_prof (professional_id),
    CONSTRAINT fk_access_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_access_prof FOREIGN KEY (professional_id) REFERENCES users(id),
    CONSTRAINT fk_access_granter FOREIGN KEY (granted_by) REFERENCES users(id),
    CONSTRAINT fk_access_revoker FOREIGN KEY (revoked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE majority_decisions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    decision ENUM('encerrar','transferir','manter') NOT NULL,
    notes TEXT NULL,
    decided_by INT UNSIGNED NOT NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_majority_patient (patient_id),
    CONSTRAINT fk_majority_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_majority_user FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consent_terms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(10) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    active TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_consent_terms_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NULL,
    term_version VARCHAR(10) NOT NULL,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    KEY idx_consents_user (user_id),
    CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_consents_patient FOREIGN KEY (patient_id) REFERENCES patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
