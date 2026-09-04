-- Bezpečnostní výjimky vícejazyčného katalogu asistenta.

SET NAMES utf8mb4;

INSERT IGNORE INTO expense_keyword_catalog
    (catalog_version, locale, concept_key, phrase, polarity, confidence, expense_kind, target_account_code, requires_review)
VALUES
    (1,'sk','asset_veto','prenajom','veto',1.000,NULL,NULL,0),
    (1,'sk','fuel_veto','parkovanie','veto',1.000,NULL,NULL,0),
    (1,'de','asset_veto','miete','veto',1.000,NULL,NULL,0),
    (1,'de','fuel_veto','parken','veto',1.000,NULL,NULL,0),
    (1,'en','asset_veto','rent','veto',1.000,NULL,NULL,0),
    (1,'en','fuel_veto','parking','veto',1.000,NULL,NULL,0);
