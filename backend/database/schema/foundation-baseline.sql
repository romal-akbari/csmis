-- Compatibility baseline for the previously imported schema.
-- Versioned by the foundation migration. Existing rows are preserved.
CREATE TABLE IF NOT EXISTS currencies (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           CHAR(3)          NOT NULL UNIQUE,          -- 'USD', 'AFN'
  name           VARCHAR(50)      NOT NULL,
  symbol         VARCHAR(8)       NOT NULL,
  decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_default     BOOLEAN          NOT NULL DEFAULT FALSE,
  is_active      BOOLEAN          NOT NULL DEFAULT TRUE,
  created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exchange_rates (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  base_currency_id  INT UNSIGNED  NOT NULL,
  quote_currency_id INT UNSIGNED  NOT NULL,
  rate              DECIMAL(15,6) NOT NULL,                -- 1 base = rate quote
  effective_date    DATE          NOT NULL,
  created_by        BIGINT UNSIGNED NULL,                  -- FK added after users
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rate_day (base_currency_id, quote_currency_id, effective_date),
  CONSTRAINT fk_fx_base  FOREIGN KEY (base_currency_id)  REFERENCES currencies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fx_quote FOREIGN KEY (quote_currency_id) REFERENCES currencies(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS branches (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL UNIQUE,
  name        VARCHAR(120) NOT NULL,
  location    VARCHAR(255) NULL,
  phone       VARCHAR(30)  NULL,
  is_main     BOOLEAN      NOT NULL DEFAULT FALSE,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  deleted_at  TIMESTAMP NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employees (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  phone        VARCHAR(30)  NULL,
  email        VARCHAR(150) NULL,
  position     VARCHAR(100) NULL,
  branch_id    INT UNSIGNED NULL,
  joining_date DATE         NULL,
  status       ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
  deleted_at   TIMESTAMP NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_emp_branch FOREIGN KEY (branch_id)
    REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL UNIQUE,   -- Administrator, Branch Manager, ...
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(80)  NOT NULL UNIQUE,   -- e.g. 'sales.create', 'stock.transfer'
  module      VARCHAR(40)  NOT NULL,          -- customers, carpets, stock, sales, ...
  action      VARCHAR(30)  NOT NULL,          -- view, create, update, delete, export
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id INT UNSIGNED    NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_branches (
  user_id   BIGINT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED    NOT NULL,
  PRIMARY KEY (user_id, branch_id),
  CONSTRAINT fk_ub_user   FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ub_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS personal_access_tokens (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tokenable_type VARCHAR(80)  NOT NULL,
  tokenable_id   BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(255) NOT NULL,
  token          VARCHAR(64)  NOT NULL UNIQUE,
  abilities      TEXT NULL,
  last_used_at   TIMESTAMP NULL,
  expires_at     TIMESTAMP NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  INDEX idx_pat_tokenable (tokenable_type, tokenable_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(150) NOT NULL,
  phone             VARCHAR(30)  NULL,
  address           VARCHAR(255) NULL,
  customer_type     ENUM('retail','wholesale','vip','other') NOT NULL DEFAULT 'retail',
  registration_date DATE         NOT NULL DEFAULT (CURRENT_DATE),
  notes             TEXT NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  deleted_at        TIMESTAMP NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customers_phone (phone),
  INDEX idx_customers_type (customer_type),
  CONSTRAINT fk_cust_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS carpet_categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  name_fa     VARCHAR(100) NULL,
  description VARCHAR(255) NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_category_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS washing_facilities (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(150) NOT NULL,
  location          VARCHAR(255) NULL,
  contact_name      VARCHAR(150) NULL,
  phone             VARCHAR(30)  NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  deleted_at        TIMESTAMP NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS accounts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id       INT UNSIGNED NULL,
  name            VARCHAR(120) NOT NULL,
  account_type    ENUM('cash','bank','mobile_money','other') NOT NULL DEFAULT 'cash',
  currency_id     INT UNSIGNED NOT NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  current_balance DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'maintained by app against ledger',
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  deleted_at      TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_acc_branch   FOREIGN KEY (branch_id)   REFERENCES branches(id)   ON DELETE SET NULL,
  CONSTRAINT fk_acc_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS account_transactions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id       BIGINT UNSIGNED NOT NULL,
  direction        ENUM('in','out') NOT NULL,
  amount           DECIMAL(14,2) NOT NULL,
  currency_id      INT UNSIGNED NOT NULL,
  exchange_rate    DECIMAL(15,6) NULL COMMENT 'FX snapshot at transaction time (§13)',
  ref_type         ENUM('sale','customer_payment','weaver_payment',
                        'purchase','expense','other_income','transfer','adjustment')
                   NOT NULL,
  ref_id           BIGINT UNSIGNED NULL COMMENT 'polymorphic reference',
  transaction_date DATE NOT NULL,
  description      VARCHAR(255) NULL,
  created_by       BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_acctx_account (account_id, transaction_date),
  INDEX idx_acctx_ref (ref_type, ref_id),
  CONSTRAINT fk_acctx_account  FOREIGN KEY (account_id)  REFERENCES accounts(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_acctx_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_acctx_creator  FOREIGN KEY (created_by)  REFERENCES users(id)      ON DELETE SET NULL,
  CONSTRAINT chk_acctx_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expense_categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  name_fa    VARCHAR(100) NULL,
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_expense_cat (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NULL,
  action     ENUM('create','update','delete','login','logout','export') NOT NULL,
  table_name VARCHAR(60)  NOT NULL,
  record_id  BIGINT UNSIGNED NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_table (table_name, record_id),
  INDEX idx_audit_user (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  `key`      VARCHAR(80)  NOT NULL PRIMARY KEY,
  value      VARCHAR(255) NOT NULL,
  type       ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string',
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
