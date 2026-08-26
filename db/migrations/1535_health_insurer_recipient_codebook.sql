-- Úplný oficiální číselník zdravotních pojišťoven pro ISDS. Systémový záznam
-- je výchozí; firma jej může překrýt vlastním záznamem se stejným kódem.
-- Zdroj: https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny

ALTER TABLE submission_recipients
  ADD COLUMN IF NOT EXISTS business_id VARCHAR(8) NULL
    COMMENT 'IČ instituce z oficiálního číselníku' AFTER name,
  ADD COLUMN IF NOT EXISTS address VARCHAR(500) NULL
    COMMENT 'Sídlo instituce z oficiálního číselníku' AFTER business_id;

ALTER TABLE submission_recipients
  ADD CONSTRAINT IF NOT EXISTS chk_submission_recipients_business_id
    CHECK (business_id IS NULL OR business_id REGEXP '^[0-9]{8}$');

INSERT INTO submission_recipients
  (supplier_id, code, name, business_id, address, kind, isds_box_id,
   source_url, source_note, is_active)
SELECT seed.supplier_id, seed.code, seed.name, seed.business_id, seed.address,
       seed.kind, seed.isds_box_id, seed.source_url, seed.source_note, 1
FROM (
  SELECT NULL AS supplier_id, 'zp_vzp_111' AS code,
         'Všeobecná zdravotní pojišťovna České republiky' AS name,
         '41197518' AS business_id,
         'Orlická 2020/4, Vinohrady, 130 00 Praha 3' AS address,
         'health_insurer' AS kind, 'i48ae3q' AS isds_box_id,
         'https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny' AS source_url,
         'Oficiální číselník zdravotních pojišťoven VZP, ověřeno 2026-08-25' AS source_note
  UNION ALL SELECT NULL, 'zp_vozp_201',
         'Vojenská zdravotní pojišťovna České republiky', '47114975',
         'Drahobejlova 1404/4, 190 00 Praha 9', 'health_insurer', 'uhff5yj',
         'https://www.vozp.cz/formulare-zamestnavatele',
         'Schránka pobočky Olomouc určená VoZP pro přehledy, ověřeno 2026-08-25'
  UNION ALL SELECT NULL, 'zp_cpzp_205',
         'Česká průmyslová zdravotní pojišťovna', '47672234',
         'Jeremenkova 161/11, Vítkovice, 703 00 Ostrava', 'health_insurer', 'mk5ab8i',
         'https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny',
         'Oficiální číselník zdravotních pojišťoven VZP, ověřeno 2026-08-25'
  UNION ALL SELECT NULL, 'zp_ozp_207',
         'Oborová zdravotní pojišťovna zaměstnanců bank, pojišťoven a stavebnictví',
         '47114321', 'Roškotova 1225/1, 140 00 Praha 4',
         'health_insurer', 'q9iadw9',
         'https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny',
         'Oficiální číselník zdravotních pojišťoven VZP, ověřeno 2026-08-25'
  UNION ALL SELECT NULL, 'zp_zpskoda_209',
         'Zaměstnanecká pojišťovna Škoda', '46354182',
         'Husova 302, 293 01 Mladá Boleslav', 'health_insurer', '5kpadkp',
         'https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny',
         'Oficiální číselník zdravotních pojišťoven VZP, ověřeno 2026-08-25'
  UNION ALL SELECT NULL, 'zp_zpmvcr_211',
         'Zdravotní pojišťovna ministerstva vnitra České republiky', '47114304',
         'Vinohradská 2577/178, Vinohrady, 130 00 Praha 3',
         'health_insurer', '9swaix3',
         'https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny',
         'Oficiální číselník zdravotních pojišťoven VZP, ověřeno 2026-08-25'
  UNION ALL SELECT NULL, 'zp_rbp_213',
         'RBP, zdravotní pojišťovna', '47673036',
         'Michálkovická 967/108, Slezská Ostrava, 710 00 Ostrava',
         'health_insurer', 'edyadmh',
         'https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny',
         'Oficiální číselník zdravotních pojišťoven VZP, ověřeno 2026-08-25'
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM submission_recipients recipient
   WHERE recipient.supplier_id IS NULL AND recipient.code = seed.code
);

UPDATE submission_recipients recipient
JOIN (
  SELECT 'zp_vzp_111' AS code, 'Všeobecná zdravotní pojišťovna České republiky' AS name,
         '41197518' AS business_id, 'Orlická 2020/4, Vinohrady, 130 00 Praha 3' AS address,
         'i48ae3q' AS isds_box_id
  UNION ALL SELECT 'zp_vozp_201', 'Vojenská zdravotní pojišťovna České republiky',
         '47114975', 'Drahobejlova 1404/4, 190 00 Praha 9', 'uhff5yj'
  UNION ALL SELECT 'zp_cpzp_205', 'Česká průmyslová zdravotní pojišťovna',
         '47672234', 'Jeremenkova 161/11, Vítkovice, 703 00 Ostrava', 'mk5ab8i'
  UNION ALL SELECT 'zp_ozp_207',
         'Oborová zdravotní pojišťovna zaměstnanců bank, pojišťoven a stavebnictví',
         '47114321', 'Roškotova 1225/1, 140 00 Praha 4', 'q9iadw9'
  UNION ALL SELECT 'zp_zpskoda_209', 'Zaměstnanecká pojišťovna Škoda',
         '46354182', 'Husova 302, 293 01 Mladá Boleslav', '5kpadkp'
  UNION ALL SELECT 'zp_zpmvcr_211',
         'Zdravotní pojišťovna ministerstva vnitra České republiky',
         '47114304', 'Vinohradská 2577/178, Vinohrady, 130 00 Praha 3', '9swaix3'
  UNION ALL SELECT 'zp_rbp_213', 'RBP, zdravotní pojišťovna',
         '47673036', 'Michálkovická 967/108, Slezská Ostrava, 710 00 Ostrava', 'edyadmh'
) canonical ON canonical.code = recipient.code
SET recipient.name = canonical.name,
    recipient.business_id = canonical.business_id,
    recipient.address = canonical.address,
    recipient.kind = 'health_insurer',
    recipient.isds_box_id = canonical.isds_box_id,
    recipient.source_url = 'https://www.vzp.cz/poskytovatele/ciselniky/zdravotni-pojistovny',
    recipient.source_note = 'Oficiální číselník zdravotních pojišťoven VZP, ověřeno 2026-08-25',
    recipient.is_active = 1
WHERE recipient.supplier_id IS NULL;
