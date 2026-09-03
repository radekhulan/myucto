-- MyÚčto.cz — jedno ŘÁDNÉ hlášení na období, vynucené schématem.
--
-- ČSSZ přijme za jedno rozhodné období právě jedno řádné podání; druhé zamítne
-- kódem 40326. `uq_payroll_submissions_regular` (migrace 1279) sice pustí na
-- JEDNU povinnost jen jedno řádné podání, jenže povinnost sama se zakládá podle
-- idempotenčního klíče, který nese přípravu a otisk snapshotu. Druhá příprava
-- za totéž období proto založí NOVOU povinnost — a s ní projde i druhé řádné
-- podání. Tenhle klíč tu díru zavírá na úrovni povinnosti.
--
-- ROZSAH JE ÚMYSLNĚ AGENDOVÝ, NE PLOŠNÝ
-- Pravidlo „jedno řádné za období" platí u agend vázaných na období, ne u agend
-- vázaných na UDÁLOST. Oznamovací povinnosti vůči zdravotní pojišťovně (HOZ)
-- se evidují jako `regular` s `period_start = den vzniku události` a jeden
-- pracovní poměr může mít v jeden den víc různých povinností (např. skončení
-- zaměstnání a změna údajů). Plošný klíč by je rozbil — a rovnou v dávkovém
-- zápisu, tedy syrovou SQL chybou. Seznam agend proto zrcadlí
-- `PayrollObligationService::UNIQUE_REGULAR_PERIOD_AGENDAS`; shodu obou stran
-- hlídá PayrollObligationRegularPeriodUniquenessTest, aby nemohly odejít
-- od sebe.
--
-- PROČ JE VE VÝRAZU I `status`
-- Zamítnuté řádné podání nemá platný kořen a podle
-- `PayrollAgendaCorrectionPolicy` po něm následuje NOVÉ řádné podání s novým
-- GUID — tedy nová povinnost za totéž období. Zamítnutí i vzdání se přenosu
-- překlopí povinnost do `manual_review`, storno do `cancelled`; obojí je proto
-- z klíče vyňaté. Zpět do klíče se povinnost dostat nemůže: ze stavů
-- `rejected` / `partially_accepted` / `correction_required` vedou podle
-- `PayrollSubmissionStateMachine` jen `superseded` (stav povinnosti nemění)
-- a `cancelled_in_time` (opět mimo klíč).
--
-- STARÁ DATA SE NEMAŽOU
-- Na instalaci, kde už duplicita vznikla, by `ADD UNIQUE` skončil chybou.
-- Řádky se proto neruší ani nepřepisují — pozdější kus duplicitní dvojice
-- dostane `regular_scope_exempt = 1` a z klíče vypadne. Příznak je jen pro
-- tuhle historii; aplikace ho nikdy nenastavuje.

SET NAMES utf8mb4;

ALTER TABLE payroll_obligations
  ADD COLUMN IF NOT EXISTS regular_scope_exempt TINYINT(1) NOT NULL DEFAULT 0
    AFTER idempotency_key_hash;

DROP TEMPORARY TABLE IF EXISTS tmp_payroll_obligation_regular_dupes;

CREATE TEMPORARY TABLE tmp_payroll_obligation_regular_dupes (
  id BIGINT UNSIGNED PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO tmp_payroll_obligation_regular_dupes (id)
SELECT ranked.id
  FROM (
       SELECT id,
              ROW_NUMBER() OVER (
                PARTITION BY supplier_id, environment, agenda_code,
                             subject_reference, period_start
                ORDER BY id
              ) AS duplicate_rank
         FROM payroll_obligations
        WHERE obligation_kind = 'regular'
          AND regular_scope_exempt = 0
          AND agenda_code IN ('JMHZ25')
          AND status NOT IN ('manual_review', 'cancelled')
       ) ranked
 WHERE ranked.duplicate_rank > 1;

UPDATE payroll_obligations obligation
  JOIN tmp_payroll_obligation_regular_dupes duplicate
    ON duplicate.id = obligation.id
   SET obligation.regular_scope_exempt = 1;

DROP TEMPORARY TABLE IF EXISTS tmp_payroll_obligation_regular_dupes;

ALTER TABLE payroll_obligations
  ADD COLUMN IF NOT EXISTS regular_period_scope_on DATE
    GENERATED ALWAYS AS (
      CASE
        WHEN obligation_kind = 'regular'
         AND regular_scope_exempt = 0
         AND agenda_code IN ('JMHZ25')
         AND status NOT IN ('manual_review', 'cancelled')
        THEN period_start
        ELSE NULL
      END
    ) STORED;

ALTER TABLE payroll_obligations
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_obligations_regular_period (
    supplier_id,
    environment,
    agenda_code,
    subject_reference,
    regular_period_scope_on
  );
