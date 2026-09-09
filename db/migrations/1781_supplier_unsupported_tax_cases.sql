-- MyÚčto.cz — vědomé příznaky poplatníka, které aplikace neumí odvodit z účetních dat.
--
-- Daňová přiznání DPPO/DPFO se dosud stavěla s natvrdo zapsaným typem poplatníka
-- (`typ_popldpp = '1'`, tedy „ostatní") a typem přiznání (`typ_dapdpp = 'A'`, tedy
-- „za zdaňovací období"). Poplatník v likvidaci, v insolvenci, po přeměně, investiční
-- fond nebo veřejně prospěšný poplatník tak dostal zdánlivě platné přiznání, které
-- o něm tvrdilo nepravdu — a nikdo se to nedozvěděl.
--
-- Sloupce níže jsou VĚDOMÝ vstup účetní pro to, co ze samotného účetnictví poznat
-- nejde. Výchozí hodnoty odpovídají běžné obchodní korporaci / OSVČ, takže se
-- chování dnešních firem nemění. `epo_taxpayer_code` je ale záměrně NULL
-- (= neurčeno) a ne '1': detekce nepodporovaných případů rozlišuje „účetní typ
-- poplatníka VÝSLOVNĚ potvrdila" od „nikdo se na to nikdy nepodíval". U firmy, kde
-- z dat plyne podezření (NACE bankovnictví/pojišťovnictví, finanční sektor), je
-- neurčený typ blokující nález, kdežto výslovně potvrzená „1" jen varování.
--
-- Číselník `epo_taxpayer_code` je převzatý z dokumentace atributu `typ_popldpp`
-- v api/xsd/dppdp9_epo2.xsd (0/8/9 investiční pobídky, 1 ostatní, 2 nerezident,
-- 3 veřejně prospěšný, 4 investiční fond, 5 investiční společnost, 6 penzijní
-- společnost, 7 část období základní investiční fond) — viz
-- \MyInvoice\Service\Tax\Return\TaxpayerTypeCodebook.
--
-- `tax_accounting_decree` je číslo účetní vyhlášky, podle níž se sestavuje závěrka
-- (dokumentace atributu `uv_vyhl` v témže XSD). Aplikace umí jen 500/2002 Sb.;
-- příloha účetní závěrky se do přiznání zapisuje s `uv_vyhl='500'` natvrdo, takže
-- jiná hodnota musí přiznání zastavit, ne projít.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS epo_taxpayer_code VARCHAR(1) NULL
        COMMENT 'typ_popldpp DPPDP9 (0-9); NULL = účetní typ poplatníka nepotvrdila'
        AFTER taxpayer_type,
    ADD COLUMN IF NOT EXISTS tax_entity_status VARCHAR(16) NOT NULL DEFAULT 'normal'
        COMMENT 'Stav poplatníka: normal|liquidation|insolvency|transformation (mění typ_dapdpp)'
        AFTER epo_taxpayer_code,
    ADD COLUMN IF NOT EXISTS tax_entity_status_date DATE NULL
        COMMENT 'Rozhodný den stavu poplatníka (vstup do likvidace, účinnost úpadku, rozhodný den přeměny)'
        AFTER tax_entity_status,
    ADD COLUMN IF NOT EXISTS tax_accounting_decree VARCHAR(3) NOT NULL DEFAULT '500'
        COMMENT 'uv_vyhl — účetní vyhláška závěrky (500/501/502/503/504/325/410); aplikace umí jen 500'
        AFTER tax_entity_status_date,
    ADD COLUMN IF NOT EXISTS tax_investment_incentive TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Nositel příslibu investiční pobídky (§ 35a/§ 35b ZDP)'
        AFTER tax_accounting_decree,
    ADD COLUMN IF NOT EXISTS tax_atad_cfc TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Dotčen pravidly ATAD/CFC (§ 23e-23h, § 38fa ZDP)'
        AFTER tax_investment_incentive,
    ADD COLUMN IF NOT EXISTS tax_public_benefit TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Veřejně prospěšný poplatník (§ 17a ZDP)'
        AFTER tax_atad_cfc,
    ADD COLUMN IF NOT EXISTS tax_cooperating_person TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'FO rozděluje příjmy na spolupracující osobu (§ 13 ZDP)'
        AFTER tax_public_benefit,
    ADD COLUMN IF NOT EXISTS tax_foreign_income_credit TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'FO má zahraniční příjmy se zápočtem daně (§ 38f ZDP, Příloha č. 3 DPFDP7)'
        AFTER tax_cooperating_person;

-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, takže se dotčená omezení
-- nejdřív zahodí a založí znovu — jen tak je migrace opakovatelná.
ALTER TABLE supplier
    DROP CONSTRAINT IF EXISTS chk_supplier_epo_taxpayer_code,
    DROP CONSTRAINT IF EXISTS chk_supplier_tax_entity_status,
    DROP CONSTRAINT IF EXISTS chk_supplier_tax_accounting_decree;

ALTER TABLE supplier
    ADD CONSTRAINT chk_supplier_epo_taxpayer_code CHECK (
        epo_taxpayer_code IS NULL OR epo_taxpayer_code REGEXP '^[0-9]$'
    ),
    ADD CONSTRAINT chk_supplier_tax_entity_status CHECK (
        tax_entity_status IN ('normal', 'liquidation', 'insolvency', 'transformation')
    ),
    ADD CONSTRAINT chk_supplier_tax_accounting_decree CHECK (
        tax_accounting_decree IN ('500', '501', '502', '503', '504', '325', '410')
    );
