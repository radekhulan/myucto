-- MyÚčto.cz — jednoduchý (párový) účetní zápis: číslo dvojice na řádku deníku.
--
-- Řádky deníku zůstávají, jak jsou. Dvojice = v rámci zápisu právě dva řádky se
-- stejným `pair_no` (jeden s účinnou stranou MD, jeden Dal, stejná částka, stejné
-- červené storno, různé účty). Hlavní kniha, předvaha, výkazy i saldo čtou dál
-- stejné řádky se stejnými součty po účtech. Invariant dvojic drží v PHP
-- JournalPairInvariant (víceřádkové pravidlo DB neumí; trigger by zdražil každý
-- zápis všech firem).
--
-- Migrace jen přidává nullable sloupce a nové tabulky, nic nedoplňuje: miliony
-- existujících řádků mají `pair_no` NULL = složený zápis, firma bez volby
-- jednoduchého zápisu nepozná změnu.
--
-- Pozn. k CHECK (pair_no > 0): na `journal_entry_lines` se záměrně nepřidává.
-- Přidání CHECK by v MariaDB ověřovalo celou (velkou, systémově verzovanou)
-- tabulku přestavbou; kladné číslo dvojice hlídá JournalPairInvariant.
--
-- Názvy objektů záměrně neobsahují slovo `pairing`: `journal_line_pairings`
-- a spol. jsou saldokontní párování okruhů a s dvojicemi zápisu nesouvisí.
--
-- Idempotence: ADD COLUMN/INDEX IF NOT EXISTS, CREATE TABLE IF NOT EXISTS,
-- MODIFY ENUM na pevný cílový seznam.

SET NAMES utf8mb4;

-- `journal_entry_lines` je system-versioned; bez tohoto pragmatu skončí ALTER
-- chybou 4119 (vzor migrace 1122/1507).
SET @@system_versioning_alter_history = 1;

-- ---------------------------------------------------------------------------
-- 1) Řádky deníku: číslo a text dvojice
-- ---------------------------------------------------------------------------
ALTER TABLE journal_entry_lines
    ADD COLUMN IF NOT EXISTS pair_no SMALLINT UNSIGNED NULL
        COMMENT 'Číslo dvojice v zápisu (jednoduchý zápis); NULL = složený zápis'
        AFTER line_no,
    ADD COLUMN IF NOT EXISTS line_text VARCHAR(255) NULL
        COMMENT 'Text řádku / dvojice (jednoduchý zápis)'
        AFTER pair_no;

-- Protějšek dvojice jedním lookupem (opis účtu, filtr protiúčtu, noční kontrola).
ALTER TABLE journal_entry_lines
    ADD INDEX IF NOT EXISTS idx_jel_supplier_entry_pair (supplier_id, entry_id, pair_no);

-- ---------------------------------------------------------------------------
-- 2) Storno / přeúčtování jednotlivé dvojice
-- ---------------------------------------------------------------------------
-- Originál zůstává jako celek nestornovaný (reversed_by NULL); stornované dvojice
-- eviduje tahle tabulka. Smazání originálu nebo opravného zápisu (otevřené období)
-- vazbu uvolní kaskádou, nic dalšího neblokuje.
CREATE TABLE IF NOT EXISTS journal_pair_reversals (
    supplier_id INT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL COMMENT 'Zápis, jehož dvojice se stornuje / přeúčtovává',
    pair_no SMALLINT UNSIGNED NOT NULL COMMENT 'Stornovaná dvojice originálu',
    reversal_entry_id BIGINT UNSIGNED NOT NULL COMMENT 'Opravný zápis',
    reversal_pair_no SMALLINT UNSIGNED NOT NULL COMMENT 'Stornovací dvojice v opravném zápisu',
    replacement_pair_no SMALLINT UNSIGNED NULL COMMENT 'Nová dvojice v témže opravném zápisu (přeúčtování)',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, entry_id, pair_no),
    KEY idx_jpr_reversal (supplier_id, reversal_entry_id),
    KEY idx_jpr_created_by (created_by),
    CONSTRAINT fk_jpr_entry FOREIGN KEY (supplier_id, entry_id)
        REFERENCES journal_entries (supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_jpr_reversal FOREIGN KEY (supplier_id, reversal_entry_id)
        REFERENCES journal_entries (supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_jpr_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Storno a přeúčtování jednotlivých dvojic jednoduchého zápisu';

-- ---------------------------------------------------------------------------
-- 3) Běhy převodu starších zápisů na dvojice (náhled nanečisto i provedení)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS journal_pair_conversion_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    mode ENUM('preview', 'apply') NOT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    status ENUM('queued', 'running', 'done', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    counts LONGTEXT NULL COMMENT 'JSON: paired, auto, derived, composite_mn, ineligible po druzích zdroje',
    sample LONGTEXT NULL COMMENT 'JSON: prvních 100 zápisů M:N / ineligible pro proklik',
    error VARCHAR(255) NULL COMMENT 'Důvod selhání běhu',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_jpcr_supplier (supplier_id, id),
    KEY idx_jpcr_created_by (created_by),
    CONSTRAINT fk_jpcr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
    CONSTRAINT fk_jpcr_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Běhy převodu starších zápisů na dvojice';

-- ---------------------------------------------------------------------------
-- 4) Posouzení zápisu bez fyzických dvojic
-- ---------------------------------------------------------------------------
-- Mimo journal_entries záměrně: UPDATE hlavičky by u mezd narazil na trigger
-- neměnnosti a v uzavřeném období by měnil systémově verzovaný řádek.
-- derived = zobrazuje se po dvojicích za běhu, composite_mn = M:N bez pravidla,
-- ineligible = převod nesmí řádky dělit (důvod v `reason`).
CREATE TABLE IF NOT EXISTS journal_entry_pair_status (
    supplier_id INT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    status ENUM('derived', 'composite_mn', 'ineligible') NOT NULL,
    reason VARCHAR(64) NULL COMMENT 'settlement_allocation | saldo_pairing | payroll | closed_period | typed_owner | same_account | ...',
    run_id BIGINT UNSIGNED NULL COMMENT 'Běh převodu, který stav určil; NULL = zaúčtování',
    assessed_at DATETIME NOT NULL,
    PRIMARY KEY (supplier_id, entry_id),
    KEY idx_jeps_status (supplier_id, status, entry_id),
    KEY idx_jeps_run (run_id),
    CONSTRAINT fk_jeps_entry FOREIGN KEY (supplier_id, entry_id)
        REFERENCES journal_entries (supplier_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_jeps_run FOREIGN KEY (run_id)
        REFERENCES journal_pair_conversion_runs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stav zápisu bez fyzických dvojic (převod, M:N, nezpůsobilé)';

-- ---------------------------------------------------------------------------
-- 5) Šablony ručních zápisů po dvojicích
-- ---------------------------------------------------------------------------
ALTER TABLE journal_entry_template_lines
    ADD COLUMN IF NOT EXISTS pair_no SMALLINT UNSIGNED NULL
        COMMENT 'Číslo dvojice v šabloně; NULL = složená šablona'
        AFTER line_no,
    ADD COLUMN IF NOT EXISTS line_text VARCHAR(255) NULL
        COMMENT 'Text dvojice'
        AFTER label;

-- ---------------------------------------------------------------------------
-- 6) Noční kontrola: nové typy nálezů
-- ---------------------------------------------------------------------------
ALTER TABLE journal_integrity_findings
    MODIFY COLUMN finding_type ENUM(
        'orphan_entry',
        'unbalanced_entry',
        'booked_without_entry',
        'entry_without_booked',
        'amount_mismatch',
        'cancelled_with_entry',
        'pair_unbalanced',
        'pair_incomplete',
        'pair_same_account',
        'single_unpaired'
    ) NOT NULL;
