CREATE TABLE lab_tests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NULL,
    name VARCHAR(100) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    group_name VARCHAR(40) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    decimals TINYINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    reference_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_lab_tests_code (code),
    CONSTRAINT fk_lab_tests_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000;
CREATE TABLE lab_ranges (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    test_id INT UNSIGNED NOT NULL,
    sex ENUM('M','F') NULL,
    age_min_months SMALLINT UNSIGNED NOT NULL,
    age_max_months SMALLINT UNSIGNED NULL,
    borderline_high DECIMAL(10,3) NULL,
    abnormal_high DECIMAL(10,3) NULL,
    borderline_low DECIMAL(10,3) NULL,
    abnormal_low DECIMAL(10,3) NULL,
    source VARCHAR(255) NULL,
    KEY idx_lab_ranges_test (test_id),
    CONSTRAINT fk_lab_ranges_test FOREIGN KEY (test_id) REFERENCES lab_tests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000;
CREATE TABLE lab_collections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    collected_on DATE NOT NULL,
    lab_name VARCHAR(120) NULL,
    notes TEXT NULL,
    released_at DATETIME NULL,
    released_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    KEY idx_lab_collections_patient (patient_id, collected_on),
    CONSTRAINT fk_lab_collections_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_lab_collections_releaser FOREIGN KEY (released_by) REFERENCES users(id),
    CONSTRAINT fk_lab_collections_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_lab_collections_updater FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_lab_collections_deleter FOREIGN KEY (deleted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE lab_results (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    collection_id INT UNSIGNED NOT NULL,
    test_id INT UNSIGNED NOT NULL,
    value_num DECIMAL(12,4) NULL,
    value_text VARCHAR(40) NULL,
    UNIQUE KEY uq_lab_results (collection_id, test_id),
    KEY idx_lab_results_test (test_id),
    CONSTRAINT fk_lab_results_collection FOREIGN KEY (collection_id) REFERENCES lab_collections(id),
    CONSTRAINT fk_lab_results_test FOREIGN KEY (test_id) REFERENCES lab_tests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE lab_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    collection_id INT UNSIGNED NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(200) NOT NULL,
    mime VARCHAR(50) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    KEY idx_lab_attachments_collection (collection_id),
    CONSTRAINT fk_lab_attachments_collection FOREIGN KEY (collection_id) REFERENCES lab_collections(id),
    CONSTRAINT fk_lab_attachments_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT fk_lab_attachments_deleter FOREIGN KEY (deleted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
