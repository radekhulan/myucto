-- MyÚčto.cz — třetí volba „nechat na ručním vystavení" (issue #39).
--
-- Někomu automaticky zakládané koncepty v seznamu dokladů překážejí a chce
-- rozhodnout sám; obě ruční akce (Vystavit fakturu k záloze / Vystavit daňový
-- doklad) v aplikaci existují, takže tenhle režim nenechá žádnou díru.
--
-- POZOR: mlčky se přijatá platba ztratit nesmí. § 28 ZDPH dává na vystavení
-- daňového dokladu k přijaté platbě 15 dnů ode dne přijetí úplaty a bez konceptu
-- v seznamu není nic, co by na to upozornilo. Proto tenhle režim CHODÍ V PÁRU
-- s položkou v denním přehledu úkolů ({@see CrmAggregationService::actionItems},
-- typ `proforma_awaiting_document`) — bez ní by šlo o tichou past.

ALTER TABLE supplier
  MODIFY COLUMN proforma_payment_document
      ENUM('final_on_full_payment', 'always_tax_document', 'manual')
      NOT NULL DEFAULT 'final_on_full_payment'
      COMMENT 'doklad po úhradě proformy: final_on_full_payment = doplacená proforma zakládá vyúčtovací fakturu (rychlý prodej), always_tax_document = vždy daňový doklad k přijaté platbě (zakázková výroba, § 20a), manual = nezakládat nic a připomenout v úkolech';
