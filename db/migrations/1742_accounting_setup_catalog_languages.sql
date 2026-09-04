-- Jazykové rozšíření katalogu asistenta nastavení účtování.

SET NAMES utf8mb4;

INSERT IGNORE INTO expense_keyword_catalog
    (catalog_version, locale, concept_key, phrase, polarity, confidence, expense_kind, target_account_code, requires_review)
SELECT catalog_version, locale, concept_key, phrase, polarity, confidence, expense_kind, target_account_code, requires_review
FROM (
    SELECT 1 AS catalog_version, 'sk' AS locale, 'small_asset' AS concept_key, 'drobny majetok' AS phrase, 'positive' AS polarity, 0.900 AS confidence, 'small_asset' AS expense_kind, NULL AS target_account_code, 0 AS requires_review
    UNION ALL SELECT 1,'sk','service','uctovne sluzby','positive',0.900,'service',NULL,0
    UNION ALL SELECT 1,'sk','insurance','poistenie','positive',0.900,'service','548',0
    UNION ALL SELECT 1,'sk','repair','udrzba','positive',0.900,'service','511',0
    UNION ALL SELECT 1,'sk','fuel','pohonne hmoty','positive',0.900,'material',NULL,0
    UNION ALL SELECT 1,'sk','asset_veto','prenajom','veto',1.000,NULL,NULL,0
    UNION ALL SELECT 1,'sk','fuel_veto','parkovanie','veto',1.000,NULL,NULL,0
    UNION ALL SELECT 1,'de','small_asset','geringwertiges wirtschaftsgut','positive',0.900,'small_asset',NULL,0
    UNION ALL SELECT 1,'de','service','dienstleistung','positive',0.850,'service',NULL,0
    UNION ALL SELECT 1,'de','insurance','versicherung','positive',0.900,'service','548',0
    UNION ALL SELECT 1,'de','repair','wartung','positive',0.900,'service','511',0
    UNION ALL SELECT 1,'de','fuel','kraftstoff','positive',0.900,'material',NULL,0
    UNION ALL SELECT 1,'de','asset_veto','miete','veto',1.000,NULL,NULL,0
    UNION ALL SELECT 1,'de','fuel_veto','parken','veto',1.000,NULL,NULL,0
    UNION ALL SELECT 1,'en','small_asset','small asset','positive',0.900,'small_asset',NULL,0
    UNION ALL SELECT 1,'en','service','consulting','positive',0.850,'service',NULL,0
    UNION ALL SELECT 1,'en','insurance','insurance premium','positive',0.900,'service','548',0
    UNION ALL SELECT 1,'en','repair','maintenance','positive',0.900,'service','511',0
    UNION ALL SELECT 1,'en','fuel','fuel','positive',0.900,'material',NULL,0
    UNION ALL SELECT 1,'en','asset_veto','rent','veto',1.000,NULL,NULL,0
    UNION ALL SELECT 1,'en','fuel_veto','parking','veto',1.000,NULL,NULL,0
) AS language_catalog;
