ALTER TABLE users
    ADD COLUMN specialty VARCHAR(80) NULL,
    ADD COLUMN rqe VARCHAR(40) NULL;

CREATE TABLE certificates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    type ENUM('comparecimento','acompanhante','afastamento','aptidao') NOT NULL,
    issued_on DATE NOT NULL,
    payload JSON NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    cid VARCHAR(8) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at DATETIME NULL,
    voided_by INT UNSIGNED NULL,
    void_reason VARCHAR(200) NULL,
    KEY idx_certificates_patient (patient_id, created_at),
    CONSTRAINT fk_certificates_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
    CONSTRAINT fk_certificates_author FOREIGN KEY (author_id) REFERENCES users(id),
    CONSTRAINT fk_certificates_voider FOREIGN KEY (voided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
