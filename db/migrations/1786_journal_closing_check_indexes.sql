-- Pokrývající indexy pro zůstatkové kontroly účetní uzávěrky (ClosingService::buildChecks).
--
-- Kontroly agregují Σ(MD − D) nad journal_entry_lines dvěma směry: buď od ÚČTU
-- (accountBalance, bsBalances, accountsOnUnusualSide, clearingAccountsWithBalance),
-- nebo od ZÁPISU (booked / bank_credit CTE v paid*OpenSaldo, realizedFxUnbooked).
-- Oběma chyběl `side` a `amount` v indexu, takže se ke každému řádku dohledával
-- řádek v clusteru — u 1 M řádků deníku ~1 M náhodných lookupů na kontrolu.
--
-- NAMĚŘENO (MariaDB 11.8.9, buffer pool 8 GB, celý dataset v RAM; min z 3–6 běhů):
--
--   zátěžová DB 1 M řádků deníku / 320 tis. zápisů      před      po
--     accountBalance ×16                              362 ms    99 ms
--     bsBalances                                     3901 ms  1295 ms
--     accountsOnUnusualSide                          3625 ms  1308 ms
--     paidInvoicesOpenSaldo                          4571 ms  1501 ms
--     paidPurchasesOpenSaldo                         5016 ms  1603 ms
--     realizedFxUnbooked                             4302 ms  1552 ms
--     součet sledovaných kontrol                    25702 ms  8996 ms  (−65 %)
--
--   podmnožina 120 tis. řádků deníku / 38 tis. zápisů   před      po
--     součet sledovaných kontrol                     2876 ms  1038 ms  (−64 %)
--
-- Obě velikosti měřeny záměrně: plán optimalizátoru se s objemem dat překlápí a
-- index, který na milionu řádků pomůže, umí na stovce tisíc uškodit (viz níže).
--
-- ZAMÍTNUTO — a proč (měřeno stejnou metodikou, rozhodovalo se podle POČTU
-- prohlédnutých řádků z Handler_read_*, ne podle času; ten na tomto stroji kolísá
-- i o desítky procent):
--
--   journal_entries (supplier_id, entry_date, posted_at)
--     Beze změny plánu — prohlédnutých řádků 25 747 839 → 25 746 835 (0,004 %).
--     EXPLAIN je před i po totožný: kontroly jdou od účtu přes journal_entry_lines
--     a do journal_entries sahají eq_ref na PRIMARY, kde už rychleji než po primárním
--     klíči nelze. Dřívější měření, které tomuhle indexu přičítalo −30 % a regresi
--     foreignCurrencyFootprintMissing na 864 ms, byl šum stroje: při min z 8 běhů
--     dává ta kontrola 124 ms s indexem i 129 ms bez něj, se shodným plánem.
--
--   journal_entries (supplier_id, source_type, entry_date, posted_at, source_id)
--     Beze změny plánu — prohlédnutých řádků 25 437 991 → 25 437 991 (bit shodně).
--     Optimalizátor ho nevybere, protože `reversed_by` v něm není a řádek se stejně
--     musí dohledat.
--
--   journal_entries (supplier_id, source_type, entry_date, posted_at, reversed_by, source_id)
--     Doplněné `reversed_by` už pokrývající je a `booked` CTE se překlopí z plného
--     scanu (319 599 řádků) na range (64 335), jenže na paid*OpenSaldo to ubere jen
--     ~4 % a ZATO na milionu řádků rozbije settledButUnpaidInvoices: prohlédnutých
--     řádků 80 920 → 799 632 a čas 23 ms → 263 ms. Přesně ta nestabilita plánu podle
--     objemu dat, kvůli které se měří na dvou velikostech — na 120 tis. řádcích se
--     regrese neprojeví vůbec.
--
-- POZOR na zápisovou cenu: journal_entry_lines je horké místo (každé zaúčtování
-- píše 2+ řádky) a tyhle dva indexy jsou široké. Proto jsou v migraci jen ony dva
-- a ne pět — samotný ix_jel_closing_by_account je většina zisku u kontrol od účtu,
-- ix_jel_closing_by_entry většina zisku u CTE v paid*OpenSaldo. Jeden bez druhého
-- ale nestačí: se samotným ix_jel_closing_by_entry se accountBalance ×16 na malém
-- datasetu propadne ze 44 ms na 1653 ms (32 tis. → 6,1 M prohlédnutých řádků),
-- protože optimalizátor přestane používat idx_jel_supplier_account. Ty dva se drží
-- v páru.
--
-- Cena za místo (1 M řádků): 45 MB na index, tedy +90 MB proti 64 MB dat.
--
-- ZÁMĚRNĚ SE NEODSTRAŇUJE nic starého, i když by to místo vrátilo: oba nové indexy
-- mají existující index jako PŘESNÝ prefix — idx_jel_supplier_account (31 MB) je
-- prefixem ix_jel_closing_by_account, idx_jel_supplier_entry (26 MB) prefixem
-- ix_jel_closing_by_entry — takže by se daly zahodit (vzor migrace 0146) a ušetřit
-- 57 MB. Neděláme to: oba ty indexy zároveň nesou FK (fk_jel_account_supplier,
-- fk_jel_entry_supplier) a hlavně plány nad touto tabulkou jsou prokazatelně labilní
-- podle objemu dat (viz zamítnuté indexy výše), takže zúžení nabídky indexů se musí
-- proměřit na obou velikostech zvlášť. Neproměřeno = neděláno.
--
-- Idempotence: ADD INDEX IF NOT EXISTS (MariaDB umí; CREATE INDEX IF NOT EXISTS ne).

ALTER TABLE journal_entry_lines
  ADD INDEX IF NOT EXISTS ix_jel_closing_by_account
    (supplier_id, account_id, entry_id, side, amount);

ALTER TABLE journal_entry_lines
  ADD INDEX IF NOT EXISTS ix_jel_closing_by_entry
    (supplier_id, entry_id, account_id, side, amount);
