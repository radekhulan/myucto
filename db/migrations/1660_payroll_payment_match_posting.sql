-- MyÚčto.cz — Ú-16: spárovaná platba si nese svůj účetní protizápis.
--
-- Doplněk k migraci 1659 (nový `journal_entries.source_type = 'payroll_payment'`).
--
-- ── Proč VLASTNÍ tabulka, a ne sloupce na `payroll_payment_matches` ──────────
-- Platební kniha je APPEND-ONLY a hlídá to databáze:
-- `trg_payroll_payment_match_immutable_update` (migrace 1269, zpřesněná v 1559)
-- odmítne UPDATE nad `payroll_payment_matches`. Je to správně — spárování platby
-- je účetní událost, ne mutovatelný stav, a storno se dělá dalším řádkem, ne
-- přepisem. Výsledek zaúčtování je ale něco jiného: vzniká AŽ po vložení
-- spárování (protizápis potřebuje `actual_payment_date` a důkazy, které dopočítá
-- databáze) a u historických plateb nevznikne nikdy. Patří proto vedle, do
-- vlastního 1:1 satelitu, ne do neměnného řádku.
--
-- Sloupce:
--   * `journal_entry_id` — účetní zápis pohybu. Idempotenci sám o sobě NEDRŽÍ
--     (tu drží `uq_je_supplier_source` nad
--     `('payroll_payment', payroll_payment_matches.id)` z migrace 1007); je to
--     čtecí zkratka, aby se nemuselo do deníku chodit dotazem.
--   * `posting_status` — jak pohyb v deníku skončil:
--       `posted`           protizápis založily MZDY (`source_type='payroll_payment'`),
--       `posted_elsewhere` pohyb už zaúčtoval bankovní modul nebo pokladní
--                          doklad (`source_type` `bank` / `cash`) — mzdy do toho
--                          nesahají a jen si vazbu poznamenají,
--       `skipped`          zaúčtovat nešlo (viz `posting_skipped_reason`),
--       `not_applicable`   firma vede daňovou evidenci, deník neexistuje.
--   * `posting_skipped_reason` — strojový důvod přeskočení. Text pro člověka
--     skládá frontend z i18n, tady je jen kód.
--
-- Rozlišení `posted` vs. `posted_elsewhere` je jádro věci, ne kosmetika.
-- `private/Mzdy/04-UCETNI-MUSTEK.md` říká výslovně, že „peněžní účty se
-- v předpisu mzdy nepoužijí; úhradu účtuje banka nebo pokladní doklad" — a je
-- to tak správně: pokladní doklad se BEZ zaúčtování vůbec nestane platebním
-- důkazem (mzdy vyžadují `status='posted'`) a bankovní detektor odvodů na
-- předčíslí 0710 účtuje 336/221 sám. Mzdy proto NEJSOU druhým účtovacím
-- kanálem; doplňují jen díru, kterou nikdo jiný nepokrývá — nespárovaný
-- bankovní pohyb, na který nesedlo žádné pravidlo.
--
-- ⚠ ZÁMĚRNĚ BEZ dopočtu pro STARÉ platby: spárování z doby před nasazením tady
-- řádek prostě mít nebude, tedy „o zaúčtování se nikdy nepokusilo". Doúčtovat
-- je zpětně by znamenalo zapsat do deníku pohyby v obdobích, která už můžou být
-- uzavřená a odsouhlasená — a u firmy, která si úhrady dosud účtovala ručně
-- nebo bankovním pravidlem, by vznikl DUPLICITNÍ zápis. Historii proto necháváme
-- být; nový režim platí od nasazení dál.
--
-- IDEMPOTENCE: `CREATE TABLE IF NOT EXISTS`. CHECKy jsou součástí definice,
-- takže se při opakovaném běhu neřeší zvlášť.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_payment_match_postings (
  supplier_id            INT UNSIGNED NOT NULL,
  match_id               BIGINT UNSIGNED NOT NULL,
  journal_entry_id       BIGINT UNSIGNED NULL
                         COMMENT 'Účetní zápis pohybu; NULL u skipped a not_applicable',
  posting_status         ENUM('posted','posted_elsewhere','skipped','not_applicable') NOT NULL,
  posting_skipped_reason VARCHAR(64) NULL
                         COMMENT 'Strojový důvod, proč protizápis nevznikl',
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, match_id),
  -- Index NENÍ unikátní, a to ZÁMĚRNĚ. Jeden bankovní pohyb smí vypořádat víc
  -- alokací najednou (`uq_payroll_payment_match_bank_event` z migrace 1269 je na
  -- (supplier_id, allocation_id, bank_transaction_id, event_kind), ne na pohybu),
  -- takže na TÝŽ cizí zápis `source_type='bank'` může ukazovat několik řádků se
  -- stavem `posted_elsewhere`. Unikátnost VLASTNÍHO mzdového protizápisu drží
  -- `uq_je_supplier_source` nad ('payroll_payment', match.id) — tam je 1:1
  -- a vymýšlet ji tu podruhé by jen zakázalo legitimní stav.
  KEY idx_payroll_payment_match_posting_journal (supplier_id, journal_entry_id),
  KEY idx_payroll_payment_match_posting_status (supplier_id, posting_status),

  CONSTRAINT fk_payroll_payment_match_posting_match
    FOREIGN KEY (supplier_id, match_id)
    REFERENCES payroll_payment_matches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_match_posting_journal
    FOREIGN KEY (supplier_id, journal_entry_id)
    REFERENCES journal_entries (supplier_id, id) ON DELETE RESTRICT,

  -- Zápis je v deníku PRÁVĚ TEHDY, když to stav tvrdí. Bez téhle vazby by šlo
  -- uložit „zaúčtováno" bez zápisu (a naopak), a saldo mzdových účtů by pak
  -- lhalo přesně o ty částky, kvůli kterým celý protizápis vzniká.
  CONSTRAINT chk_payroll_payment_match_posting CHECK (
    (posting_status IN ('posted','posted_elsewhere') AND journal_entry_id IS NOT NULL)
    OR (posting_status IN ('skipped','not_applicable') AND journal_entry_id IS NULL)
  ),
  -- Důvod smí být vyplněný jen u přeskočení — jinak by se „zaúčtováno" tvářilo,
  -- že zároveň zaúčtováno není.
  CONSTRAINT chk_payroll_payment_match_posting_reason CHECK (
    posting_skipped_reason IS NULL OR posting_status = 'skipped'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
  COMMENT='Ú-16 — výsledek zaúčtování spárované mzdové platby (1:1 satelit neměnné platební knihy)';
