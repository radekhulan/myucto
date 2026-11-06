-- 2221: rozdělení kalendáře na splátkový (§ 31 ZDPH) a platební (§ 31a ZDPH)
--
-- Původní `payment_calendar` slučoval dva doklady, které se daňově chovají opačně, a do
-- evidence DPH vstupoval celou částkou v jednom období. Nové typy tisknou správné
-- náležitosti a pohledávku, daň i zápis v deníku nesou interní doklady splátek
-- (`invoices.calendar_id`): u nájmu faktura s DUZP splátky, u zálohy zálohový doklad,
-- jehož úhrada založí daňový doklad ke dni přijetí úplaty. Stejně eviduje platební
-- kalendář přijatá strana. Už vystavené `payment_calendar` se nemění.

SET NAMES utf8mb4;
SET @@system_versioning_alter_history = 1;

ALTER TABLE invoices
    MODIFY invoice_type ENUM('invoice','proforma','credit_note','cancellation','tax_document','penalty','payment_calendar','rent_calendar','advance_calendar') NOT NULL;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS calendar_id BIGINT UNSIGNED NULL
        COMMENT 'kalendář, ke kterému patří interní doklad splátky nebo daňový doklad k její úhradě'
        AFTER parent_invoice_id,
    ADD COLUMN IF NOT EXISTS calendar_contract_ref VARCHAR(190) NULL
        COMMENT 'nájemní smlouva, jejíž je splátkový kalendář součástí nebo která na něj odkazuje (§ 31 písm. b)'
        AFTER calendar_id;

CREATE INDEX IF NOT EXISTS idx_inv_supplier_calendar ON invoices (supplier_id, calendar_id);

ALTER TABLE invoices
    ADD CONSTRAINT fk_inv_calendar FOREIGN KEY IF NOT EXISTS (calendar_id) REFERENCES invoices (id) ON DELETE CASCADE;

ALTER TABLE invoice_payment_schedule
    ADD COLUMN IF NOT EXISTS tax_date DATE NULL
        COMMENT 'den uskutečnění dílčího plnění podle smlouvy (§ 21 odst. 7), jen splátkový kalendář'
        AFTER due_on,
    ADD COLUMN IF NOT EXISTS instalment_invoice_id BIGINT UNSIGNED NULL
        COMMENT 'interní doklad splátky založený při vystavení kalendáře'
        AFTER tax_date;

ALTER TABLE invoice_payment_schedule
    ADD CONSTRAINT fk_ips_instalment FOREIGN KEY IF NOT EXISTS (instalment_invoice_id) REFERENCES invoices (id) ON DELETE SET NULL;

-- Tržby CRM sčítají splátky, kalendář by je započetl podruhé.
DELIMITER //

DROP PROCEDURE IF EXISTS sp_recompute_crm_monthly_summary //

CREATE PROCEDURE sp_recompute_crm_monthly_summary(IN p_supplier_id TINYINT UNSIGNED)
BEGIN
    DECLARE v_cutoff DATE;
    SET v_cutoff = DATE_SUB(CURDATE(), INTERVAL 13 MONTH);

    DELETE FROM crm_monthly_summary
     WHERE supplier_id = p_supplier_id
       AND period_ym >= DATE_FORMAT(v_cutoff, '%Y-%m');

    -- Revenue + invoice count z vydaných (status NOT IN draft, cancelled)
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, revenue_czk, revenue_net_czk, invoice_count,
         costs, costs_net, costs_czk, costs_net_czk, purchase_count, vat_output, vat_input)
    SELECT
        i.supplier_id,
        DATE_FORMAT(i.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        SUM(COALESCE(i.total_with_vat, 0))    AS revenue,
        SUM(COALESCE(i.total_without_vat, 0)) AS revenue_net,
        SUM(COALESCE(i.total_with_vat, 0)    * COALESCE(IF(c.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_czk,
        SUM(COALESCE(i.total_without_vat, 0) * COALESCE(IF(c.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_net_czk,
        COUNT(*) AS invoice_count,
        0, 0, 0, 0, 0,
        SUM(COALESCE(i.total_with_vat, 0) - COALESCE(i.total_without_vat, 0)) AS vat_output,
        0
      FROM invoices i
 LEFT JOIN currencies c ON c.id = i.currency_id
     WHERE i.supplier_id = p_supplier_id
       AND i.status NOT IN ('draft', 'cancelled')
       AND i.issue_date >= v_cutoff
       AND i.invoice_type NOT IN ('proforma', 'rent_calendar', 'advance_calendar')  -- proformy nejsou daňový doklad, kalendář sečtou jeho splátky
  GROUP BY i.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           revenue         = VALUES(revenue),
           revenue_net     = VALUES(revenue_net),
           revenue_czk     = VALUES(revenue_czk),
           revenue_net_czk = VALUES(revenue_net_czk),
           invoice_count   = VALUES(invoice_count),
           vat_output      = VALUES(vat_output);

    -- Costs + purchase count z přijatých (status NOT IN draft, cancelled).
    -- Zálohu (advance) vyřaď, pokud je zaplacená NEBO spárovaná s finální fakturou
    -- (proti dvojímu započtení nákladu). Nezaplacená nespárovaná záloha se počítá.
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, revenue_czk, revenue_net_czk, invoice_count,
         costs, costs_net, costs_czk, costs_net_czk, purchase_count, vat_output, vat_input)
    SELECT
        pi.supplier_id,
        DATE_FORMAT(pi.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        0, 0, 0, 0, 0,
        SUM(COALESCE(pi.total_with_vat, 0))    AS costs,
        SUM(COALESCE(pi.total_without_vat, 0)) AS costs_net,
        SUM(COALESCE(pi.total_with_vat, 0)    * COALESCE(IF(c.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_czk,
        SUM(COALESCE(pi.total_without_vat, 0) * COALESCE(IF(c.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_net_czk,
        COUNT(*) AS purchase_count,
        0,
        SUM(COALESCE(pi.total_with_vat, 0) - COALESCE(pi.total_without_vat, 0)) AS vat_input
      FROM purchase_invoices pi
 LEFT JOIN currencies c ON c.id = pi.currency_id
     WHERE pi.supplier_id = p_supplier_id
       AND pi.status NOT IN ('draft', 'cancelled')
       AND pi.issue_date >= v_cutoff
       AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                AND (pi.status = 'paid'
                     OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                 WHERE adv_s.advance_purchase_invoice_id = pi.id)))
  GROUP BY pi.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           costs          = VALUES(costs),
           costs_net      = VALUES(costs_net),
           costs_czk      = VALUES(costs_czk),
           costs_net_czk  = VALUES(costs_net_czk),
           purchase_count = VALUES(purchase_count),
           vat_input      = VALUES(vat_input);
END //

DELIMITER ;
