-- MyÚčto.cz — postoupené výživné a úplata za postupované pohledávky výživného.
--
-- § 279 odst. 2 písm. a) o. s. ř. řadí mezi přednostní pohledávky „pohledávky
-- výživného včetně pohledávek výživného, které byly postoupeny, a pohledávky
-- na úhradu úplaty za postupované pohledávky výživného". § 280 odst. 2 jim
-- uvnitř druhé třetiny dává vlastní místo v pořadí: nejprve výživné, poté
-- úplata za postupované pohledávky výživného, poté postoupené výživné, poté
-- náhradní výživné a teprve pak ostatní přednostní pohledávky.
--
-- Číselník je do 8/2026 neznal, takže obě skupiny spadly do `other_priority`
-- a uspokojovaly se až za náhradním výživným a podle pořadí doručení, tedy
-- o dvě skupiny níž, než kam patří (nález E-17 auditu exekučních srážek, E-07).
--
-- Existující řádky se nepřeklápějí: `other_priority` je u nich zaznamenaná
-- klasifikace ověřená účetní (`priority_classification_verified`) a přeřadit ji
-- smí jen člověk, který doklad viděl. Nová hodnota je od teď k dispozici.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_claims
  MODIFY COLUMN category ENUM(
    'current_maintenance','maintenance_arrears',
    'assigned_maintenance_consideration','assigned_maintenance',
    'substitute_maintenance','other_priority','non_priority'
  ) NOT NULL;

-- Poměrné dělení uvnitř skupiny podle § 280 odst. 3 o. s. ř. potřebuje kladnou
-- váhu běžného výživného i u nových kategorií.
ALTER TABLE payroll_enforcement_claims
  DROP CONSTRAINT IF EXISTS chk_payroll_enforcement_claim_maintenance_weight;

ALTER TABLE payroll_enforcement_claims
  ADD CONSTRAINT chk_payroll_enforcement_claim_maintenance_weight
    CHECK (
      category NOT IN (
        'current_maintenance','maintenance_arrears',
        'assigned_maintenance_consideration','assigned_maintenance',
        'substitute_maintenance'
      )
      OR maintenance_weight_minor_units > 0
    );
