-- Volitelná schopnost tokenu: vynucená úprava vystavené vydané faktury
-- (`PUT /api/v1/invoices/{id}?force=1`, issue #355).
--
-- Bez ní je vynucená úprava vystaveného dokladu jen pro přihlášenou relaci
-- (`SessionOnlyAccountingAct`). Token se schopností ji smí provést, ale
-- přísněji než správce v relaci: uzavřené účetní období ani podané DPH, KH,
-- SH nebo OSS nepřebije, nepřečísluje doklad a nesáhne na fakturu s úhradou.
--
-- ⚠️ Výchozí hodnota je 0 a musí jí zůstat. Schopnost jde zapnout jen při
-- vytvoření tokenu vázaného na firmu se zápisem, a to jen správcem firmy nebo
-- rolí s právem upravit vystavenou fakturu. Dodatečně ji zapnout nejde.

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS allow_issued_invoice_edit TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'token smí vynuceně upravit vystavenou vydanou fakturu'
    AFTER allow_payroll_submission_docs;
