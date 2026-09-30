CREATE DATABASE IF NOT EXISTS esg_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE esg_system;

CREATE TABLE organizations (
    org_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    org_code VARCHAR(50) NOT NULL UNIQUE,
    org_name VARCHAR(100) NOT NULL,
    boundary_type ENUM('operational','financial','equity') NOT NULL DEFAULT 'operational',
    base_year SMALLINT UNSIGNED NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES organizations(org_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE roles (
    role_id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(40) NOT NULL UNIQUE,
    role_name VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NULL,
    role_id SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    status TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ghg_sources (
    source_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    scope ENUM('scope1','scope2','scope3') NOT NULL,
    iso_category TINYINT UNSIGNED NOT NULL DEFAULT 1,
    source_type VARCHAR(50) NOT NULL,
    source_name VARCHAR(100) NOT NULL,
    activity_unit VARCHAR(20) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source_org_scope (org_id, scope),
    CONSTRAINT chk_iso_category CHECK (iso_category BETWEEN 1 AND 6),
    FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE emission_factors (
    factor_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    factor_code VARCHAR(50) NOT NULL UNIQUE,
    factor_name VARCHAR(120) NOT NULL,
    activity_unit VARCHAR(20) NOT NULL,
    co2_factor DECIMAL(14,6) NOT NULL DEFAULT 0,
    ch4_factor DECIMAL(14,6) NOT NULL DEFAULT 0,
    n2o_factor DECIMAL(14,6) NOT NULL DEFAULT 0,
    ch4_gwp DECIMAL(10,4) NOT NULL DEFAULT 28,
    n2o_gwp DECIMAL(10,4) NOT NULL DEFAULT 265,
    source_agency VARCHAR(150) NOT NULL,
    version_label VARCHAR(50) NULL,
    evidence_path VARCHAR(255) NULL,
    is_custom TINYINT(1) NOT NULL DEFAULT 0,
    effective_start_date DATE NOT NULL,
    effective_end_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_factor_dates (effective_start_date, effective_end_date),
    CONSTRAINT chk_factor_dates CHECK (effective_end_date >= effective_start_date)
) ENGINE=InnoDB;

CREATE TABLE activity_data (
    data_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id INT UNSIGNED NOT NULL,
    factor_id INT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    usage_amount DECIMAL(16,4) NOT NULL DEFAULT 0,
    calculated_tco2e DECIMAL(16,6) NOT NULL DEFAULT 0,
    status ENUM('draft','pending_review','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft',
    anomaly_flag TINYINT(1) NOT NULL DEFAULT 0,
    anomaly_deviation_pct DECIMAL(8,2) NULL,
    anomaly_note VARCHAR(500) NULL,
    rejection_reason VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    locked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_activity_period (source_id, period_year, period_month),
    INDEX idx_activity_status (status, period_year, period_month),
    CONSTRAINT chk_activity_month CHECK (period_month BETWEEN 1 AND 12),
    FOREIGN KEY (source_id) REFERENCES ghg_sources(source_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (factor_id) REFERENCES emission_factors(factor_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE workflow_tasks (
    task_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data_id BIGINT UNSIGNED NOT NULL,
    action ENUM('submit','approve','reject') NOT NULL,
    from_status VARCHAR(30) NOT NULL,
    to_status VARCHAR(30) NOT NULL,
    comment VARCHAR(500) NULL,
    actor_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workflow_data (data_id, created_at),
    FOREIGN KEY (data_id) REFERENCES activity_data(data_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attachments (
    attachment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(36) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (data_id) REFERENCES activity_data(data_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE social_metrics (
    metric_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    metric_year SMALLINT UNSIGNED NOT NULL,
    metric_code VARCHAR(60) NOT NULL,
    metric_name VARCHAR(120) NOT NULL,
    category ENUM('diversity','safety','training','supply_chain') NOT NULL,
    value DECIMAL(18,4) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    note VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_social_metric (org_id, metric_year, metric_code),
    FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE governance_records (
    record_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    record_year SMALLINT UNSIGNED NOT NULL,
    category ENUM('board','ethics','anti_corruption','training','whistleblower') NOT NULL,
    metric_name VARCHAR(150) NOT NULL,
    metric_value VARCHAR(150) NOT NULL,
    note VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE materiality_topics (
    topic_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    topic_year SMALLINT UNSIGNED NOT NULL,
    topic_name VARCHAR(120) NOT NULL,
    stakeholder_score DECIMAL(8,4) NOT NULL,
    business_impact_score DECIMAL(8,4) NOT NULL,
    weight DECIMAL(8,4) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_materiality_topic (topic_year, topic_name)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    table_name VARCHAR(80) NOT NULL,
    record_id VARCHAR(80) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_lookup (table_name, record_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_limit (email, ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE api_keys (
    api_key_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    key_hash CHAR(64) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO roles (role_code, role_name) VALUES
('ROLE_SUPER_ADMIN', '系統最高管理員'),
('ROLE_ESG_COMMITTEE', 'ESG 推動委員會'),
('ROLE_DEPT_REVIEWER', '廠區/部門審核主管'),
('ROLE_DATA_OPERATOR', '數據填報人員'),
('ROLE_AUDITOR', '外部查證稽核員');

INSERT INTO organizations (org_code, org_name, boundary_type, base_year) VALUES
('HQ', '集團總部', 'operational', 2022),
('FACTORY-TY1', '桃園觀音一廠', 'operational', 2022),
('FACTORY-KH1', '高雄一廠', 'operational', 2022);

UPDATE organizations child
JOIN organizations parent ON parent.org_code = 'HQ'
SET child.parent_id = parent.org_id
WHERE child.org_code IN ('FACTORY-TY1', 'FACTORY-KH1');

INSERT INTO emission_factors
(factor_code, factor_name, activity_unit, co2_factor, ch4_factor, n2o_factor, source_agency, version_label, effective_start_date, effective_end_date)
VALUES
('ELEC-TW-DEMO-2025', '外購電力示範係數', 'kWh', 0.474000, 0, 0, '示範資料－上線前請以能源署公告值覆核', 'DEMO-2025', '2025-01-01', '2025-12-31'),
('DIESEL-DEMO-2025', '固定燃燒柴油示範係數', 'L', 2.606000, 0.000100, 0.000100, '示範資料－上線前請以環境部係數庫覆核', 'DEMO-2025', '2025-01-01', '2025-12-31'),
('ELEC-TW-DEMO-2026', '外購電力示範係數', 'kWh', 0.474000, 0, 0, '示範資料－待 2026 官方係數公告後更新', 'DEMO-2026', '2026-01-01', '2026-12-31'),
('DIESEL-DEMO-2026', '固定燃燒柴油示範係數', 'L', 2.606000, 0.000100, 0.000100, '示範資料－待 2026 官方係數公告後更新', 'DEMO-2026', '2026-01-01', '2026-12-31');
