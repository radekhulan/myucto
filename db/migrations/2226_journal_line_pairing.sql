-- MyÚčto.cz — Párovací symbol řádku deníku převzatého z jiného programu.
--
-- Saldo POHODY je skupina řádků deníku se stejným párovacím symbolem. Symbol patří
-- k řádku, ne k zápisu: u zápočtu zálohy páruje účet dokladu jiný symbol než účet zálohy.
-- Vlastní tabulka, ne sloupec řádku: journal_entry_lines je systémově verzovaná a symbol
-- účetní obsah řádku nemění. Řádek založený v MyÚčtu symbol nemá.
--
-- Re-run safe: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_line_pairing (
    supplier_id INT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL,
    pairing_symbol VARCHAR(40) NOT NULL COMMENT 'POHODA pUD.ParSym, u účtu zálohy ParSym2',
    PRIMARY KEY (supplier_id, entry_id, line_no),
    KEY ix_jlp_symbol (supplier_id, pairing_symbol),
    CONSTRAINT fk_jlp_entry FOREIGN KEY (supplier_id, entry_id) REFERENCES journal_entries (supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
