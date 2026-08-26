-- MyUcto.cz - presny pripnuty ciselnik vazeb REGZEL kodFU/kodPracovisteFU.
-- Zdroj: ciselnik pracovist GFR pouzity oficialnimi pokyny REGZELDOPL25.

SET NAMES utf8mb4;

ALTER TABLE payroll_regzel_employer_profiles
  DROP CONSTRAINT IF EXISTS chk_payroll_regzel_tax_office_codes;
ALTER TABLE payroll_regzel_employer_profiles
  ADD CONSTRAINT chk_payroll_regzel_tax_office_codes CHECK (
    (tax_office_code IS NULL AND tax_office_workplace_code IS NULL)
    OR COALESCE(CASE tax_office_code
      WHEN '2000' THEN tax_office_workplace_code IN (
        '2001','2002','2003','2004','2005','2006','2007','2008','2009',
        '2010','2011','2012'
      )
      WHEN '2100' THEN tax_office_workplace_code IN (
        '2101','2102','2103','2104','2105','2106','2109','2110','2111',
        '2112','2113','2114','2115','2118','2119','2120','2121','2122',
        '2124','2125'
      )
      WHEN '2200' THEN tax_office_workplace_code IN (
        '2201','2203','2205','2208','2209','2211','2212'
      )
      WHEN '2300' THEN tax_office_workplace_code IN (
        '2301','2302','2303','2305','2308','2312','2313'
      )
      WHEN '2400' THEN tax_office_workplace_code IN ('2401','2403','2407')
      WHEN '2500' THEN tax_office_workplace_code IN (
        '2501','2503','2504','2505','2507','2509','2510','2512','2513',
        '2514','2515'
      )
      WHEN '2600' THEN tax_office_workplace_code IN (
        '2601','2602','2604','2607','2609'
      )
      WHEN '2700' THEN tax_office_workplace_code IN (
        '2701','2707','2709','2712','2713'
      )
      WHEN '2800' THEN tax_office_workplace_code IN (
        '2801','2804','2808','2809','2810','2811'
      )
      WHEN '2900' THEN tax_office_workplace_code IN (
        '2901','2903','2910','2912','2913','2914'
      )
      WHEN '3000' THEN tax_office_workplace_code IN (
        '3001','3002','3003','3004','3005','3006','3007','3008','3010',
        '3011','3013','3018','3019','3020'
      )
      WHEN '3100' THEN tax_office_workplace_code IN (
        '3101','3102','3103','3106','3107','3108','3109','3110'
      )
      WHEN '3200' THEN tax_office_workplace_code IN (
        '3201','3202','3203','3205','3207','3210','3212','3213','3214',
        '3215','3216','3218'
      )
      WHEN '3300' THEN tax_office_workplace_code IN (
        '3301','3304','3306','3307','3308','3309','3310','3312'
      )
      WHEN '4000' THEN tax_office_workplace_code IS NULL
      ELSE FALSE
    END, FALSE)
  );
