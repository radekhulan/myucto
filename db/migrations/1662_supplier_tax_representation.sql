-- MyÚčto.cz — evidence zastoupení daňovým poradcem/advokátem (§ 29 odst. 2 daňového
-- řádu) a promítnutí do přiznání DPPO/DPFO (dan_por/pln_moc + zast_* v EPO XSD).
--
-- PROČ TO DOSUD CHYBĚLO: DppoXmlBuilder/DpfoXmlBuilder plnily `dan_por`/`pln_moc`
-- natvrdo 'N', protože appka zastoupení vůbec neevidovala. U zastoupené firmy (a
-- zastoupení je běžné — má ho většina firem) je to věcně nepravdivý údaj a navíc
-- schází identifikace poradce, kterou by EPO u 'A' vyžadovalo.
--
-- DATOVANÁ POLOŽKA, NE plochý sloupec na `supplier` — stejné zdůvodnění jako
-- u plátcovství DPH (migrace 1120, `supplier_vat_status_history`, VH-01):
--   * zastoupení se v čase mění (nástup/výpověď plné moci) a přiznání za starý
--     rok musí nést stav PLATNÝ K TEHDEJŠÍMU DATU, ne dnešní — jinak by se
--     zpětně generované/rekonstruované XML za rok N lišilo podle toho, kdy se
--     generuje, což u archivovaného podání nesmí platit;
--   * jde o tentýž typ atributu (bool + pár doprovodných polí platných „od")
--     jako plátcovství DPH, takže sdílíme ověřený vzor čtení "k datu" místo
--     vymýšlení nového.
--
-- MINIMÁLNÍ rozsah oproti VH-01 zastoupení: BEZ retro-guardu proti uzamčeným
-- účetním obdobím / už podaným přiznáním (VatStatusGuard). Plátcovství DPH
-- retroaktivně mění základ, ze kterého vznikly deníkové zápisy a odpočty —
-- zastoupení daňovým poradcem nic neúčtuje, ovlivňuje jen dva atributy XML
-- exportu (a volitelně lhůtu podání), takže dopad špatně zadaného data je
-- omezený na přegenerování dosud nearchivovaného XML. Přidat guard je snadné
-- později, kdyby praxe ukázala potřebu.
--
-- Firma BEZ jakéhokoli řádku historie = nezastoupena (žádná baseline řádka
-- se neediduje, na rozdíl od VH-01 — čtecí služba defaultuje na `represented=0`,
-- což je přesně dnešní chování 'N', jen bez nutnosti seedovat existující firmy).
--
-- Evidenční číslo poradce (`representative_ev_number`, seznam KDP ČR / ČAK)
-- se sbírá VŽDY, když je firma zastoupena — u fyzické osoby poradce XSD chce
-- „datum narození NEBO evidenční číslo"; datum narození třetí osoby (poradce)
-- záměrně nesbíráme (zbytečná citlivá PII o osobě, která není subjektem
-- evidence), evidenční číslo samo XSD podmínku splní.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_tax_representation_history (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                   INT UNSIGNED NOT NULL,
  effective_from                DATE NOT NULL COMMENT 'od kdy tento stav platí (point-in-time anchor)',
  represented                   TINYINT(1) NOT NULL DEFAULT 0,
  representative_type           ENUM('F','P') NULL COMMENT 'F = fyzická osoba (daňový poradce/advokát) → zast_kod 4b, P = právnická osoba vykonávající daňové poradenství → zast_kod 4c',
  representative_first_name     VARCHAR(20) NULL COMMENT 'zast_jmeno (typ F), XSD maxLength 20',
  representative_last_name      VARCHAR(36) NULL COMMENT 'zast_prijmeni (typ F), XSD maxLength 36',
  representative_company_name   VARCHAR(255) NULL COMMENT 'zast_nazev (typ P), XSD maxLength 255',
  representative_ico            VARCHAR(10) NULL COMMENT 'zast_ic (typ P) — IČO poradenské společnosti, XSD pattern [0-9]{1,10}',
  representative_ev_number      VARCHAR(36) NULL COMMENT 'zast_ev_cislo — evidenční číslo v seznamu KDP ČR/ČAK, XSD maxLength 36',
  power_of_attorney_granted_on  DATE NULL COMMENT 'datum udělení plné moci (informativní/audit — v EPO XSD není samostatný atribut)',
  note                          VARCHAR(255) NULL,
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by                    BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_supplier_tax_representation (supplier_id, effective_from),
  KEY idx_supplier_tax_representation_date (supplier_id, effective_from, id),
  CONSTRAINT fk_supplier_tax_representation_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_tax_representation_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_supplier_tax_representation_identity CHECK (
    represented = 0
    OR (
      representative_type IS NOT NULL
      AND representative_ev_number IS NOT NULL
      AND (
        (representative_type = 'F' AND representative_first_name IS NOT NULL AND representative_last_name IS NOT NULL)
        OR (representative_type = 'P' AND representative_company_name IS NOT NULL)
      )
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
