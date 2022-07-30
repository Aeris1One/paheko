---
--- Main stuff
---

CREATE TABLE IF NOT EXISTS config (
-- Configuration, key/value store
    key TEXT PRIMARY KEY NOT NULL,
    value TEXT NULL
);

CREATE TABLE IF NOT EXISTS config_users_fields (
    id INTEGER NOT NULL PRIMARY KEY,
    name TEXT NOT NULL,
    sort_order INTEGER NOT NULL,
    type TEXT NOT NULL,
    label TEXT NOT NULL,
    help TEXT NULL,
    required INTEGER NOT NULL DEFAULT 0,
    read_access INTEGER NOT NULL DEFAULT 0,
    write_access INTEGER NOT NULL DEFAULT 1,
    list_table INTEGER NOT NULL DEFAULT 0,
    options TEXT NULL,
    default_value TEXT NULL,
    system TEXT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS config_users_fields_name ON config_users_fields (name);

CREATE TABLE IF NOT EXISTS plugins
(
    id TEXT NOT NULL PRIMARY KEY,
    official INTEGER NOT NULL DEFAULT 0, -- 1 if plugin is official
    name TEXT NOT NULL,
    description TEXT NULL,
    author TEXT NULL,
    url TEXT NULL,
    version TEXT NOT NULL,
    menu INTEGER NOT NULL DEFAULT 0, -- 1 if plugin should be shown in sidebar menu
    menu_condition TEXT NULL, -- Brindille condition to know if item should be shown in menu
    config TEXT NULL
);

CREATE TABLE IF NOT EXISTS plugins_signals
-- Link between plugins and signals
(
    signal TEXT NOT NULL,
    plugin TEXT NOT NULL REFERENCES plugins (id),
    callback TEXT NOT NULL,
    PRIMARY KEY (signal, plugin)
);

CREATE TABLE IF NOT EXISTS searches
-- Saved searches
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_user INTEGER NULL REFERENCES users (id) ON DELETE CASCADE, -- If not NULL, then search will only be visible by this user
    label TEXT NOT NULL,
    created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (datetime(created) IS NOT NULL AND datetime(created) = created),
    target TEXT NOT NULL, -- "users" ou "accounting"
    type TEXT NOT NULL, -- "json" ou "sql"
    content TEXT NOT NULL
);


CREATE TABLE IF NOT EXISTS compromised_passwords_cache
-- Cache des hash de mots de passe compromis
(
    hash TEXT NOT NULL PRIMARY KEY
);

CREATE TABLE IF NOT EXISTS compromised_passwords_cache_ranges
-- Cache des préfixes de mots de passe compromis
(
    prefix TEXT NOT NULL PRIMARY KEY,
    date INTEGER NOT NULL
);

---
--- Users
---

-- CREATE TABLE users (...);
-- Organization users table, dynamically created, see config_users_fields table

CREATE TABLE IF NOT EXISTS users_categories
-- Users categories, mainly used to manage rights
(
    id INTEGER PRIMARY KEY NOT NULL,
    name TEXT NOT NULL,

    -- Permissions, 0 = no access, 1 = read-only, 2 = read-write, 9 = admin
    perm_web INTEGER NOT NULL DEFAULT 1,
    perm_documents INTEGER NOT NULL DEFAULT 1,
    perm_users INTEGER NOT NULL DEFAULT 1,
    perm_accounting INTEGER NOT NULL DEFAULT 1,

    perm_subscribe INTEGER NOT NULL DEFAULT 0,
    perm_connect INTEGER NOT NULL DEFAULT 1,
    perm_config INTEGER NOT NULL DEFAULT 0,

    hidden INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS users_categories_hidden ON users_categories (hidden);

CREATE TABLE IF NOT EXISTS users_sessions
-- Permanent sessions for logged-in users
(
    selector TEXT NOT NULL,
    hash TEXT NOT NULL,
    id_user INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    expire INT NOT NULL,

    PRIMARY KEY (selector, id_user)
);

CREATE TABLE IF NOT EXISTS logs
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_user INTEGER NULL REFERENCES users (id),
    type INTEGER NOT NULL,
    details TEXT NULL,
    created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (datetime(created) IS NOT NULL AND datetime(created) = created),
    ip_address TEXT NULL
);

CREATE INDEX IF NOT EXISTS logs_ip ON logs (ip_address, created);
CREATE INDEX IF NOT EXISTS logs_user ON logs (id_user, created);
CREATE INDEX IF NOT EXISTS logs_created ON logs (created);

---
--- Services
---

CREATE TABLE IF NOT EXISTS services
-- Services types (French: cotisations)
(
    id INTEGER PRIMARY KEY NOT NULL,

    label TEXT NOT NULL,
    description TEXT NULL,

    duration INTEGER NULL CHECK (duration IS NULL OR duration > 0), -- En jours
    start_date TEXT NULL CHECK (start_date IS NULL OR date(start_date) = start_date),
    end_date TEXT NULL CHECK (end_date IS NULL OR (date(end_date) = end_date AND date(end_date) >= date(start_date)))
);

CREATE TABLE IF NOT EXISTS services_fees
-- Services fees
(
    id INTEGER PRIMARY KEY NOT NULL,

    label TEXT NOT NULL,
    description TEXT NULL,

    amount INTEGER NULL,
    formula TEXT NULL, -- Formula to calculate fee amount dynamically (this contains a SQL statement)

    id_service INTEGER NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    id_account INTEGER NULL REFERENCES acc_accounts (id) ON DELETE SET NULL CHECK (id_account IS NULL OR id_year IS NOT NULL), -- NULL if fee is not linked to accounting, this is reset using a trigger if the year is deleted
    id_year INTEGER NULL REFERENCES acc_years (id) ON DELETE SET NULL -- NULL if fee is not linked to accounting
);

CREATE TABLE IF NOT EXISTS services_users
-- Records of services and fees linked to users
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_user INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    id_service INTEGER NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    id_fee INTEGER NULL REFERENCES services_fees (id) ON DELETE CASCADE,

    paid INTEGER NOT NULL DEFAULT 0,
    expected_amount INTEGER NULL,

    date TEXT NOT NULL DEFAULT CURRENT_DATE CHECK (date(date) IS NOT NULL AND date(date) = date),
    expiry_date TEXT NULL CHECK (date(expiry_date) IS NULL OR date(expiry_date) = expiry_date)
);

CREATE UNIQUE INDEX IF NOT EXISTS su_unique ON services_users (id_user, id_service, date);

CREATE INDEX IF NOT EXISTS su_service ON services_users (id_service);
CREATE INDEX IF NOT EXISTS su_fee ON services_users (id_fee);
CREATE INDEX IF NOT EXISTS su_paid ON services_users (paid);
CREATE INDEX IF NOT EXISTS su_expiry ON services_users (expiry_date);

CREATE TABLE IF NOT EXISTS services_reminders
-- Reminders for service expiry
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_service INTEGER NOT NULL REFERENCES services (id) ON DELETE CASCADE,

    delay INTEGER NOT NULL, -- Delay in days before or after expiry date

    subject TEXT NOT NULL,
    body TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS services_reminders_sent
-- Records of sent reminders, to keep track
(
    id INTEGER NOT NULL PRIMARY KEY,

    id_user INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    id_service INTEGER NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    id_reminder INTEGER NOT NULL REFERENCES services_reminders (id) ON DELETE CASCADE,

    sent_date TEXT NOT NULL DEFAULT CURRENT_DATE CHECK (date(sent_date) IS NOT NULL AND date(sent_date) = sent_date),
    due_date TEXT NOT NULL CHECK (date(due_date) IS NOT NULL AND date(due_date) = due_date)
);

CREATE UNIQUE INDEX IF NOT EXISTS srs_index ON services_reminders_sent (id_user, id_service, id_reminder, due_date);

CREATE INDEX IF NOT EXISTS srs_reminder ON services_reminders_sent (id_reminder);
CREATE INDEX IF NOT EXISTS srs_user ON services_reminders_sent (id_user);

--
-- Accounting
--

CREATE TABLE IF NOT EXISTS acc_charts
-- Accounting charts (plans comptables)
(
    id INTEGER NOT NULL PRIMARY KEY,
    country TEXT NOT NULL,
    code TEXT NULL, -- the code is NULL if the chart is user-created or imported
    label TEXT NOT NULL,
    archived INTEGER NOT NULL DEFAULT 0 -- 1 = archived, cannot be changed
);

CREATE TABLE IF NOT EXISTS acc_accounts
-- Accounts of the charts (comptes)
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_chart INTEGER NOT NULL REFERENCES acc_charts ON DELETE CASCADE,

    code TEXT NOT NULL, -- can contain numbers and letters, eg. 53A, 53B...

    label TEXT NOT NULL,
    description TEXT NULL,

    position INTEGER NOT NULL, -- position in the balance sheet (position actif/passif/charge/produit)
    type INTEGER NOT NULL DEFAULT 0, -- type (category) of favourite account: bank, cash, third party, etc.
    user INTEGER NOT NULL DEFAULT 1 -- 0 = is part of the original chart, 0 = has been added by the user
);

CREATE UNIQUE INDEX IF NOT EXISTS acc_accounts_codes ON acc_accounts (code, id_chart);
CREATE INDEX IF NOT EXISTS acc_accounts_type ON acc_accounts (type);
CREATE INDEX IF NOT EXISTS acc_accounts_position ON acc_accounts (position);

-- Balance des comptes par exercice
CREATE VIEW IF NOT EXISTS acc_accounts_balances
AS
    SELECT id_year, id, label, code, type, debit, credit,
        CASE -- 3 = dynamic asset or liability depending on balance
            WHEN position = 3 AND (debit - credit) > 0 THEN 1 -- 1 = Asset (actif) comptes fournisseurs, tiers créditeurs
            WHEN position = 3 THEN 2 -- 2 = Liability (passif), comptes clients, tiers débiteurs
            ELSE position
        END AS position,
        CASE
            WHEN position IN (1, 4) -- 1 = asset, 4 = expense
                OR (position = 3 AND (debit - credit) > 0)
            THEN
                debit - credit
            ELSE
                credit - debit
        END AS balance,
        CASE WHEN debit - credit > 0 THEN 1 ELSE 0 END AS is_debt
    FROM (
        SELECT t.id_year, a.id, a.label, a.code, a.position, a.type,
            SUM(l.credit) AS credit,
            SUM(l.debit) AS debit
        FROM acc_accounts a
        INNER JOIN acc_transactions_lines l ON l.id_account = a.id
        INNER JOIN acc_transactions t ON t.id = l.id_transaction
        GROUP BY t.id_year, a.id
    );

CREATE TABLE IF NOT EXISTS acc_years
-- Years (exercices)
(
    id INTEGER NOT NULL PRIMARY KEY,

    label TEXT NOT NULL,

    start_date TEXT NOT NULL CHECK (date(start_date) IS NOT NULL AND date(start_date) = start_date),
    end_date TEXT NOT NULL CHECK (date(end_date) IS NOT NULL AND date(end_date) = end_date),

    closed INTEGER NOT NULL DEFAULT 0, -- 0 = open, 1 = closed

    id_chart INTEGER NOT NULL REFERENCES acc_charts (id)
);

CREATE INDEX IF NOT EXISTS acc_years_closed ON acc_years (closed);

-- Make sure id_account is reset when a year is deleted
CREATE TRIGGER IF NOT EXISTS acc_years_delete BEFORE DELETE ON acc_years BEGIN
    UPDATE services_fees SET id_account = NULL, id_year = NULL WHERE id_year = OLD.id;
END;

CREATE TABLE IF NOT EXISTS acc_transactions
-- Transactions (écritures comptables)
(
    id INTEGER PRIMARY KEY NOT NULL,

    type INTEGER NOT NULL DEFAULT 0, -- Transaction type, zero is advanced
    status INTEGER NOT NULL DEFAULT 0, -- Status (bitmask)

    label TEXT NOT NULL,
    notes TEXT NULL,
    reference TEXT NULL, -- N° de pièce comptable

    date TEXT NOT NULL DEFAULT CURRENT_DATE CHECK (date(date) IS NOT NULL AND date(date) = date),

    validated INTEGER NOT NULL DEFAULT 0, -- 1 means transaction is locked

    hash TEXT NULL,
    prev_hash TEXT NULL,

    id_year INTEGER NOT NULL REFERENCES acc_years(id),
    id_creator INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    id_related INTEGER NULL REFERENCES acc_transactions(id) ON DELETE SET NULL -- linked transaction (eg. payment of a debt)
);

CREATE INDEX IF NOT EXISTS acc_transactions_year ON acc_transactions (id_year);
CREATE INDEX IF NOT EXISTS acc_transactions_date ON acc_transactions (date);
CREATE INDEX IF NOT EXISTS acc_transactions_related ON acc_transactions (id_related);
CREATE INDEX IF NOT EXISTS acc_transactions_type ON acc_transactions (type, id_year);
CREATE INDEX IF NOT EXISTS acc_transactions_status ON acc_transactions (status);

CREATE TABLE IF NOT EXISTS acc_transactions_lines
-- Transactions lines (lignes des écritures)
(
    id INTEGER PRIMARY KEY NOT NULL,

    id_transaction INTEGER NOT NULL REFERENCES acc_transactions (id) ON DELETE CASCADE,
    id_account INTEGER NOT NULL REFERENCES acc_accounts (id),

    credit INTEGER NOT NULL,
    debit INTEGER NOT NULL,

    reference TEXT NULL, -- Usually a payment reference (par exemple numéro de chèque)
    label TEXT NULL,

    reconciled INTEGER NOT NULL DEFAULT 0,

    id_analytical INTEGER NULL REFERENCES acc_accounts(id) ON DELETE SET NULL,

    CONSTRAINT line_check1 CHECK ((credit * debit) = 0),
    CONSTRAINT line_check2 CHECK ((credit + debit) > 0)
);

CREATE INDEX IF NOT EXISTS acc_transactions_lines_transaction ON acc_transactions_lines (id_transaction);
CREATE INDEX IF NOT EXISTS acc_transactions_lines_account ON acc_transactions_lines (id_account);
CREATE INDEX IF NOT EXISTS acc_transactions_lines_analytical ON acc_transactions_lines (id_analytical);
CREATE INDEX IF NOT EXISTS acc_transactions_lines_reconciled ON acc_transactions_lines (reconciled);

CREATE TABLE IF NOT EXISTS acc_transactions_users
-- Linking transactions and users
(
    id_user INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    id_transaction INTEGER NOT NULL REFERENCES acc_transactions (id) ON DELETE CASCADE,
    id_service_user INTEGER NULL REFERENCES services_users (id) ON DELETE SET NULL,

    PRIMARY KEY (id_user, id_transaction)
);

CREATE INDEX IF NOT EXISTS acc_transactions_users_service ON acc_transactions_users (id_service_user);

CREATE TABLE IF NOT EXISTS api_credentials
(
    id INTEGER NOT NULL PRIMARY KEY,
    label TEXT NOT NULL,
    key TEXT NOT NULL,
    secret TEXT NOT NULL,
    created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_use TEXT NULL,
    access_level INT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS api_credentials_key ON api_credentials (key);

---------- FILES ----------------

CREATE TABLE IF NOT EXISTS files
-- Files metadata
(
    id INTEGER NOT NULL PRIMARY KEY,
    path TEXT NOT NULL,
    parent TEXT NOT NULL,
    name TEXT NOT NULL, -- File name
    type INTEGER NOT NULL, -- File type, 1 = file, 2 = directory
    mime TEXT NULL,
    size INT NULL,
    modified TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (ddatetime(modified) IS NOT NULL AND atetime(modified) = modified),
    image INT NOT NULL DEFAULT 0,

    CHECK (type = 2 OR (mime IS NOT NULL AND size IS NOT NULL))
);

-- Unique index as this is used to make up a file path
CREATE UNIQUE INDEX IF NOT EXISTS files_unique ON files (path);
CREATE INDEX IF NOT EXISTS files_parent ON files (parent);
CREATE INDEX IF NOT EXISTS files_name ON files (name);
CREATE INDEX IF NOT EXISTS files_modified ON files (modified);

CREATE TABLE IF NOT EXISTS files_contents
-- Files contents (empty if using another storage backend)
(
    id INTEGER NOT NULL PRIMARY KEY REFERENCES files(id) ON DELETE CASCADE,
    compressed INT NOT NULL DEFAULT 0,
    content BLOB NOT NULL
);

CREATE VIRTUAL TABLE IF NOT EXISTS files_search USING fts4
-- Search inside files content
(
    tokenize=unicode61, -- Available from SQLITE 3.7.13 (2012)
    path TEXT NOT NULL,
    title TEXT NULL,
    content TEXT NOT NULL, -- Text content
    notindexed=path
);

CREATE TABLE IF NOT EXISTS web_pages
(
    id INTEGER NOT NULL PRIMARY KEY,
    parent TEXT NOT NULL, -- Parent path, empty = web root
    path TEXT NOT NULL, -- Full page directory name
    uri TEXT NOT NULL, -- Page identifier
    file_path TEXT NOT NULL, -- Full file path for contents
    type INTEGER NOT NULL, -- 1 = Category, 2 = Page
    status TEXT NOT NULL,
    format TEXT NOT NULL,
    published TEXT NOT NULL CHECK (datetime(published) IS NOT NULL AND datetime(published) = published),
    modified TEXT NOT NULL CHECK (datetime(modified) IS NOT NULL AND datetime(modified) = modified),
    title TEXT NOT NULL,
    content TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS web_pages_path ON web_pages (path);
CREATE UNIQUE INDEX IF NOT EXISTS web_pages_uri ON web_pages (uri);
CREATE UNIQUE INDEX IF NOT EXISTS web_pages_file_path ON web_pages (file_path);
CREATE INDEX IF NOT EXISTS web_pages_parent ON web_pages (parent);
CREATE INDEX IF NOT EXISTS web_pages_published ON web_pages (published);
CREATE INDEX IF NOT EXISTS web_pages_title ON web_pages (title);

CREATE TABLE IF NOT EXISTS emails (
-- List of emails addresses
-- We are not storing actual email addresses here for privacy reasons
-- So that we can keep the record (for opt-out reasons) even when the
-- email address has been removed from the users table
    id INTEGER NOT NULL PRIMARY KEY,
    hash TEXT NOT NULL,
    verified INTEGER NOT NULL DEFAULT 0,
    optout INTEGER NOT NULL DEFAULT 0,
    invalid INTEGER NOT NULL DEFAULT 0,
    fail_count INTEGER NOT NULL DEFAULT 0,
    sent_count INTEGER NOT NULL DEFAULT 0,
    fail_log TEXT NULL,
    last_sent TEXT NULL,
    added TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS emails_hash ON emails (hash);

CREATE TABLE IF NOT EXISTS emails_queue (
-- List of emails waiting to be sent
    id INTEGER NOT NULL PRIMARY KEY,
    sender TEXT NULL,
    recipient TEXT NOT NULL,
    recipient_hash TEXT NOT NULL,
    subject TEXT NOT NULL,
    content TEXT NOT NULL,
    content_html TEXT NULL,
    sending INTEGER NOT NULL DEFAULT 0, -- Will be changed to 1 when the queue run will start
    sending_started TEXT NULL, -- Will be filled with the datetime when the email sending was started
    context INTEGER NOT NULL
);
