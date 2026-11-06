SET NAMES utf8mb4;
-- Prodej dluhového cenného papíru a storno navázané úhrady: nová operace 'sale'
-- a nárok 'sale' na řádek tržby 653. Omezení z migrace 2052 nesla automatická jména.
ALTER TABLE accounting_securities_debt_events DROP CONSTRAINT IF EXISTS CONSTRAINT_1;
ALTER TABLE accounting_securities_debt_events DROP CONSTRAINT IF EXISTS chk_securities_debt_event_operation;
ALTER TABLE accounting_securities_debt_events ADD CONSTRAINT chk_securities_debt_event_operation
 CHECK(operation IN ('accrual','amortisation','valuation','coupon_receipt','principal_redemption','sale','reverse'));
ALTER TABLE accounting_securities_debt_claims DROP CONSTRAINT IF EXISTS CONSTRAINT_1;
ALTER TABLE accounting_securities_debt_claims DROP CONSTRAINT IF EXISTS chk_securities_debt_claim_component;
ALTER TABLE accounting_securities_debt_claims ADD CONSTRAINT chk_securities_debt_claim_component
 CHECK(component IN ('acquisition','coupon_receipt','principal_redemption','sale','fx_carry'));
