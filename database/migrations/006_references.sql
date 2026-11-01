CREATE TABLE who_lms (
    indicator ENUM('bfa','wfa','hfa','wfl','wfh','hcfa') NOT NULL,
    sex TINYINT NOT NULL COMMENT '1=M, 2=F',
    x DECIMAL(7,2) NOT NULL,
    source ENUM('who2006','who2007') NOT NULL,
    l DOUBLE NOT NULL,
    m DOUBLE NOT NULL,
    s DOUBLE NOT NULL,
    PRIMARY KEY (indicator, sex, source, x)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bp_reference (
    sex TINYINT NOT NULL COMMENT '1=M, 2=F',
    age_years TINYINT NOT NULL,
    height_pct TINYINT NOT NULL,
    bp_pct TINYINT NOT NULL,
    systolic SMALLINT NOT NULL,
    diastolic SMALLINT NOT NULL,
    PRIMARY KEY (sex, age_years, height_pct, bp_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
