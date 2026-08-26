-- VoZP uvádí pro přehledy zaměstnavatele specializovanou datovou schránku
-- pobočky Olomouc. Obecná centrální schránka zůstává v externím číselníku,
-- tento recipient je ale účelově určený pro mzdová podání.
-- Zdroj: https://www.vozp.cz/formulare-zamestnavatele

UPDATE submission_recipients
SET isds_box_id = 'uhff5yj',
    source_url = 'https://www.vozp.cz/formulare-zamestnavatele',
    source_note = 'Schránka pobočky Olomouc určená VoZP pro přehledy, ověřeno 2026-08-25'
WHERE supplier_id IS NULL
  AND code = 'zp_vozp_201';
