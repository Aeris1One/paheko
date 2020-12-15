CREATE TABLE IF NOT EXISTS config (
-- Configuration de Garradin
    cle TEXT PRIMARY KEY NOT NULL,
    valeur TEXT
);

CREATE TABLE IF NOT EXISTS membres_categories
-- Catégories de membres
(
    id INTEGER PRIMARY KEY NOT NULL,
    nom TEXT NOT NULL,

    droit_web INTEGER NOT NULL DEFAULT 1,
    droit_documents INTEGER NOT NULL DEFAULT 1,
    droit_membres INTEGER NOT NULL DEFAULT 1,
    droit_compta INTEGER NOT NULL DEFAULT 1,
    droit_inscription INTEGER NOT NULL DEFAULT 0,
    droit_connexion INTEGER NOT NULL DEFAULT 1,
    droit_config INTEGER NOT NULL DEFAULT 0,
    cacher INTEGER NOT NULL DEFAULT 0
);

-- Membres de l'asso
-- Table dynamique générée par l'application
-- voir Garradin\Membres\Champs.php

CREATE TABLE IF NOT EXISTS membres_sessions
-- Sessions
(
    selecteur TEXT NOT NULL,
    hash TEXT NOT NULL,
    id_membre INTEGER NOT NULL REFERENCES membres (id) ON DELETE CASCADE,
    expire INT NOT NULL,

    PRIMARY KEY (selecteur, id_membre)
);

CREATE TABLE IF NOT EXISTS services
-- Types de services (cotisations)
(
    id INTEGER PRIMARY KEY NOT NULL,

    label TEXT NOT NULL,
    description TEXT NULL,

    duration INTEGER NULL CHECK (duration IS NULL OR duration > 0), -- En jours
    start_date TEXT NULL CHECK (start_date IS NULL OR date(start_date) = start_date),
    end_date TEXT NULL CHECK (end_date IS NULL OR (date(end_date) = end_date AND date(end_date) >= date(start_date)))
);

CREATE TABLE IF NOT EXISTS services_fees
(
    id INTEGER PRIMARY KEY NOT NULL,

    label TEXT NOT NULL,
    description TEXT NULL,

    amount INTEGER NULL,
    formula TEXT NULL, -- Formule de calcul du montant de la cotisation, si cotisation dynamique (exemple : membres.revenu_imposable * 0.01)

    id_service INTEGER NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    id_account INTEGER NULL REFERENCES acc_accounts (id) ON DELETE SET NULL CHECK (id_account IS NULL OR id_year IS NOT NULL), -- NULL si le type n'est pas associé automatiquement à la compta
    id_year INTEGER NULL REFERENCES acc_years (id) ON DELETE SET NULL -- NULL si le type n'est pas associé automatiquement à la compta
);

CREATE TABLE IF NOT EXISTS services_users
-- Enregistrement des cotisations et activités
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_user INTEGER NOT NULL REFERENCES membres (id) ON DELETE CASCADE,
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
-- Rappels de devoir renouveller une cotisation
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_service INTEGER NOT NULL REFERENCES services (id) ON DELETE CASCADE,

    delay INTEGER NOT NULL, -- Délai en jours pour envoyer le rappel

    subject TEXT NOT NULL,
    body TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS services_reminders_sent
-- Enregistrement des rappels envoyés à qui et quand
(
    id INTEGER NOT NULL PRIMARY KEY,

    id_user INTEGER NOT NULL REFERENCES membres (id) ON DELETE CASCADE,
    id_service INTEGER NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    id_reminder INTEGER NOT NULL REFERENCES services_reminders (id) ON DELETE CASCADE,

    date TEXT NOT NULL DEFAULT CURRENT_DATE CHECK (date(date) IS NOT NULL AND date(date) = date)
);

CREATE UNIQUE INDEX IF NOT EXISTS srs_index ON services_reminders_sent (id_user, id_service, id_reminder, date);

CREATE INDEX IF NOT EXISTS srs_reminder ON services_reminders_sent (id_reminder);
CREATE INDEX IF NOT EXISTS srs_user ON services_reminders_sent (id_user);

--
-- COMPTA
--

CREATE TABLE IF NOT EXISTS acc_charts
-- Plans comptables : il peut y en avoir plusieurs
(
    id INTEGER NOT NULL PRIMARY KEY,
    country TEXT NOT NULL,
    code TEXT NULL, -- NULL = plan comptable créé par l'utilisateur
    label TEXT NOT NULL,
    archived INTEGER NOT NULL DEFAULT 0 -- 1 = archivé, non-modifiable
);

CREATE TABLE IF NOT EXISTS acc_accounts
-- Comptes des plans comptables
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_chart INTEGER NOT NULL REFERENCES acc_charts ON DELETE CASCADE,

    code TEXT NOT NULL, -- peut contenir des lettres, eg. 53A, 53B, etc.

    label TEXT NOT NULL,
    description TEXT NULL,

    position INTEGER NOT NULL, -- position actif/passif/charge/produit
    type INTEGER NOT NULL DEFAULT 0, -- Type de compte spécial : banque, caisse, en attente d'encaissement, etc.
    user INTEGER NOT NULL DEFAULT 1 -- 1 = fait partie du plan comptable original, 0 = a été ajouté par l'utilisateur
);

CREATE UNIQUE INDEX IF NOT EXISTS acc_accounts_codes ON acc_accounts (code, id_chart);
CREATE INDEX IF NOT EXISTS acc_accounts_type ON acc_accounts (type);
CREATE INDEX IF NOT EXISTS acc_accounts_position ON acc_accounts (position);

CREATE TABLE IF NOT EXISTS acc_years
-- Exercices
(
    id INTEGER NOT NULL PRIMARY KEY,

    label TEXT NOT NULL,

    start_date TEXT NOT NULL CHECK (date(start_date) IS NOT NULL AND date(start_date) = start_date),
    end_date TEXT NOT NULL CHECK (date(end_date) IS NOT NULL AND date(end_date) = end_date),

    closed INTEGER NOT NULL DEFAULT 0,

    id_chart INTEGER NOT NULL REFERENCES acc_charts (id)
);

CREATE INDEX IF NOT EXISTS acc_years_closed ON acc_years (closed);

CREATE TABLE IF NOT EXISTS acc_transactions
-- Opérations comptables
(
    id INTEGER PRIMARY KEY NOT NULL,

    type INTEGER NOT NULL DEFAULT 0, -- Type d'écriture, 0 = avancée (normale)
    status INTEGER NOT NULL DEFAULT 0, -- Statut (bitmask)

    label TEXT NOT NULL,
    notes TEXT NULL,
    reference TEXT NULL, -- N° de pièce comptable

    date TEXT NOT NULL DEFAULT CURRENT_DATE CHECK (date(date) IS NOT NULL AND date(date) = date),

    validated INTEGER NOT NULL DEFAULT 0, -- 1 = écriture validée, non modifiable

    hash TEXT NULL,
    prev_hash TEXT NULL,

    id_year INTEGER NOT NULL REFERENCES acc_years(id),
    id_creator INTEGER NULL REFERENCES membres(id) ON DELETE SET NULL,
    id_related INTEGER NULL REFERENCES acc_transactions(id) ON DELETE SET NULL -- écriture liée (par ex. remboursement d'une dette)
);

CREATE INDEX IF NOT EXISTS acc_transactions_year ON acc_transactions (id_year);
CREATE INDEX IF NOT EXISTS acc_transactions_date ON acc_transactions (date);
CREATE INDEX IF NOT EXISTS acc_transactions_related ON acc_transactions (id_related);
CREATE INDEX IF NOT EXISTS acc_transactions_type ON acc_transactions (type);
CREATE INDEX IF NOT EXISTS acc_transactions_status ON acc_transactions (status);

CREATE TABLE IF NOT EXISTS acc_transactions_lines
-- Lignes d'écritures d'une opération
(
    id INTEGER PRIMARY KEY NOT NULL,

    id_transaction INTEGER NOT NULL REFERENCES acc_transactions (id) ON DELETE CASCADE,
    id_account INTEGER NOT NULL REFERENCES acc_accounts (id), -- N° du compte dans le plan comptable

    credit INTEGER NOT NULL,
    debit INTEGER NOT NULL,

    reference TEXT NULL, -- Référence de paiement, eg. numéro de chèque
    label TEXT NULL,

    reconciled INTEGER NOT NULL DEFAULT 0,

    id_analytical INTEGER NULL REFERENCES acc_accounts(id) ON DELETE SET NULL,

    CONSTRAINT line_check1 CHECK ((credit * debit) = 0),
    CONSTRAINT line_check2 CHECK ((credit + debit) > 0)
);

CREATE INDEX IF NOT EXISTS acc_transactions_lines_account ON acc_transactions_lines (id_account);
CREATE INDEX IF NOT EXISTS acc_transactions_lines_analytical ON acc_transactions_lines (id_analytical);
CREATE INDEX IF NOT EXISTS acc_transactions_lines_reconciled ON acc_transactions_lines (reconciled);

CREATE TABLE IF NOT EXISTS acc_transactions_users
-- Liaison des écritures et des membres
(
    id_user INTEGER NOT NULL REFERENCES membres (id) ON DELETE CASCADE,
    id_transaction INTEGER NOT NULL REFERENCES acc_transactions (id) ON DELETE CASCADE,
    id_service_user INTEGER NULL REFERENCES services_users (id) ON DELETE SET NULL,

    PRIMARY KEY (id_user, id_transaction)
);

CREATE INDEX IF NOT EXISTS acc_transactions_users_service ON acc_transactions_users (id_service_user);

CREATE TABLE IF NOT EXISTS plugins
(
    id TEXT NOT NULL PRIMARY KEY,
    officiel INTEGER NOT NULL DEFAULT 0,
    nom TEXT NOT NULL,
    description TEXT NULL,
    auteur TEXT NULL,
    url TEXT NULL,
    version TEXT NOT NULL,
    menu INTEGER NOT NULL DEFAULT 0,
    menu_condition TEXT NULL,
    config TEXT NULL
);

CREATE TABLE IF NOT EXISTS plugins_signaux
-- Association entre plugins et signaux (hooks)
(
    signal TEXT NOT NULL,
    plugin TEXT NOT NULL REFERENCES plugins (id),
    callback TEXT NOT NULL,
    PRIMARY KEY (signal, plugin)
);

---------- FILES ----------------

CREATE TABLE IF NOT EXISTS files
-- Files metadata
(
    id INTEGER NOT NULL PRIMARY KEY,
    folder_id INTEGER NULL REFERENCES files_folders,
    name TEXT NOT NULL, -- file name (eg. image1234.jpeg)
    type TEXT NULL, -- MIME type
    image INTEGER NOT NULL DEFAULT 0, -- 1 = image reconnue
    public INTEGER NOT NULL DEFAULT 0,
    size INTEGER NOT NULL DEFAULT 0,
    hash TEXT NOT NULL, -- Hash SHA1 du contenu du fichier

    storage TEXT NULL, -- Storage medium, NULL means stored in content BLOB
    storage_path TEXT NULL,

    created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (datetime(created) IS NOT NULL AND datetime(created) = created),

    author_id INTEGER NULL REFERENCES membres (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS files_date ON files (created);
CREATE INDEX IF NOT EXISTS files_hash ON files (hash);

CREATE TABLE IF NOT EXISTS files_contents
-- Files contents (if storage backend is SQLite)
(
    id INTEGER NOT NULL PRIMARY KEY,
    hash TEXT NOT NULL,
    size INT NOT NULL,
    content BLOB NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS files_contents_hash ON files_contents (hash);

CREATE TABLE IF NOT EXISTS files_folders
(
    id INTEGER NOT NULL PRIMARY KEY,
    parent_id INTEGER NULL REFERENCES files_folders(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    system INTEGER NOT NULL DEFAULT 0,
    CHECK (parent_id IS NULL OR parent_id != id)
);

CREATE VIRTUAL TABLE IF NOT EXISTS files_search USING fts4
-- Search inside files content
(
    tokenize=unicode61, -- Available from SQLITE 3.7.13 (2012)
    id INT PRIMARY KEY NOT NULL REFERENCES files(id),
    title TEXT NULL,
    content TEXT NOT NULL -- Text content
);

CREATE TABLE IF NOT EXISTS files_links
-- This references use of a file outside of the documents module
-- One file can only be linked to one thing
(
    id INTEGER NOT NULL REFERENCES files (id) ON DELETE CASCADE,
    file_id INTEGER NULL REFERENCES files (id) ON DELETE CASCADE,
    user_id INTEGER NULL REFERENCES membres (id) ON DELETE CASCADE,
    transaction_id INTEGER NULL REFERENCES acc_transactions (id) ON DELETE CASCADE,
    config TEXT NULL REFERENCES config (cle) ON DELETE CASCADE,
    web_page_id INTEGER NULL REFERENCES web_pages (id) ON DELETE CASCADE,
    -- Make sure that only one is filled
    CHECK ((file_id IS NOT NULL) + (user_id IS NOT NULL) + (transaction_id IS NOT NULL) + (config IS NOT NULL) + (web_page_id IS NOT NULL) = 1)
);

CREATE UNIQUE INDEX IF NOT EXISTS files_links_unique ON files_links (id, file_id, user_id, transaction_id, config, web_page_id);

CREATE TABLE IF NOT EXISTS web_pages
(
    id INTEGER NOT NULL PRIMARY KEY REFERENCES files(id),
    parent_id INTEGER NULL REFERENCES web_pages(id) ON DELETE SET NULL,
    type INTEGER NOT NULL, -- 1 = Category, 2 = Page
    status INTEGER NOT NULL DEFAULT 0, -- 0 = draft, 1 = online
    uri TEXT NOT NULL,
    title TEXT NOT NULL,
    modified TEXT NULL CHECK (datetime(modified) IS NULL OR datetime(modified) = modified)
);

CREATE UNIQUE INDEX web_pages_uri ON web_pages (uri);

CREATE TRIGGER IF NOT EXISTS web_page_insert AFTER INSERT ON web_pages
    BEGIN
        UPDATE files SET public = CASE WHEN NEW.status = 1 THEN 1 ELSE 0 END WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS web_page_update AFTER UPDATE OF status ON web_pages
    BEGIN
        UPDATE files SET public = CASE WHEN NEW.status = 1 THEN 1 ELSE 0 END WHERE id = NEW.id;
    END;

-- FIXME: rename to english
CREATE TABLE IF NOT EXISTS recherches
-- Recherches enregistrées
(
    id INTEGER NOT NULL PRIMARY KEY,
    id_membre INTEGER NULL REFERENCES membres (id) ON DELETE CASCADE, -- Si non NULL, alors la recherche ne sera visible que par le membre associé
    intitule TEXT NOT NULL,
    creation TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (datetime(creation) IS NOT NULL AND datetime(creation) = creation),
    cible TEXT NOT NULL, -- "membres" ou "compta"
    type TEXT NOT NULL, -- "json" ou "sql"
    contenu TEXT NOT NULL
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