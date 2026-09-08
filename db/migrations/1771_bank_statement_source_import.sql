-- Zdroj bankovního výpisu: doplnění hodnoty `import` pro migrace z jiného systému.
--
-- Migrační můstek (Money S3 a další) naveze bankovní pohyby z cizí evidence.
-- Žádná dosavadní hodnota to nepopisuje pravdivě:
--   'gpc'          … výpis nahraný jako GPC soubor — u migrace žádný soubor není
--                    a `StatementImporter` navíc rekonstruovaný GPC odmítá,
--                    protože to není bankou potvrzený výpis,
--   'bank_api'     … má behaviorální dopad: `BankApiMonthlyStatements` podle něj
--                    hledá účty s aktivním napojením na banku,
--   'pdf'          … rozparsovaný PDF výpis, taky neplatí.
--
-- Hodnota je čistě additivní, existující řádky se nemění.

ALTER TABLE `bank_statements`
    MODIFY COLUMN `source` enum('gpc','email_notice','pdf','idoklad','bank_api','import')
        NOT NULL DEFAULT 'gpc'
        COMMENT 'gpc/pdf = nahraný soubor, email_notice = agregát avíz, idoklad/bank_api = strojový feed, import = migrace z jiného účetního systému';
