-- MyÚčto.cz — W16: počet ztěžujících vlivů u zápisu docházky (§ 117 ZP).
--
-- PROČ TO NEJDE ODVODIT
--
-- § 117 odst. 2 přiznává příplatek „za každý ztěžující vliv". Kategorie
-- `difficult_environment` v `payroll_time_entries` ale říká jen TO, ŽE ve
-- ztíženém prostředí práce probíhala — ne kolika vlivům byl zaměstnanec
-- vystaven. Bez počtu se příplatek spočítat nedá: jedna hodina může znamenat
-- jeden příplatek stejně jako čtyři a rozdíl je čtyřnásobek.
--
-- Odhadovat jedničkou by byla nejhorší varianta. Vypadala by jako výsledek,
-- u drtivé většiny pracovišť by dokonce byla správně, a u těch několika, kde
-- správně není, by tiše vyráběla nedoplatek, který nikdo neuvidí — protože
-- číslo na výplatní pásce bude.
--
-- KDE JE VÝCHOZÍ HODNOTA
--
-- Počet vlivů je většinou vlastností PRACOVIŠTĚ, ne jednotlivého dne, takže
-- výchozí hodnotu drží zásada pracovního vztahu
-- (`payroll_employment_surcharge_policies.difficult_environment_factors`,
-- migrace 1624). Tenhle sloupec je VÝJIMKA pro den, kdy se od obvyklého stavu
-- lišil — třeba mimořádná práce na jiném pracovišti. NULL tedy neznamená nulu,
-- ale „platí obvyklý stav vztahu".
--
-- Rozsah je 1 až 255; nula by znamenala „ztížené prostředí bez ztěžujícího
-- vlivu", což je protimluv, a takový zápis nemá být kategorie
-- `difficult_environment`.
--
-- IDEMPOTENCE
--
-- `ADD COLUMN IF NOT EXISTS` MariaDB umí; u CHECK se musí nejdřív zahodit,
-- protože `IF NOT EXISTS` u něj neexistuje.

SET NAMES utf8mb4;

ALTER TABLE payroll_time_entries
  ADD COLUMN IF NOT EXISTS difficulty_factor_count TINYINT UNSIGNED NULL
  AFTER break_minutes;

ALTER TABLE payroll_time_entries
  DROP CONSTRAINT IF EXISTS chk_payroll_time_entry_difficulty_factors;
ALTER TABLE payroll_time_entries
  ADD CONSTRAINT chk_payroll_time_entry_difficulty_factors
  CHECK (
    difficulty_factor_count IS NULL
    OR (category = 'difficult_environment' AND difficulty_factor_count >= 1)
  );
