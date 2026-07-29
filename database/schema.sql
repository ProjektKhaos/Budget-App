CREATE TABLE IF NOT EXISTS documents (
    owner_username VARCHAR(120) NOT NULL,
    document_name ENUM('budget', 'investments', 'fund_snapshots', 'appearance') NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_username, document_name),
    CONSTRAINT documents_payload_json CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_secrets (
    owner_username VARCHAR(120) NOT NULL,
    secret_name VARCHAR(80) NOT NULL,
    nonce VARBINARY(64) NOT NULL,
    ciphertext BLOB NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_username, secret_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_key CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    INDEX login_attempts_client_time (client_key, attempted_at),
    INDEX login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
