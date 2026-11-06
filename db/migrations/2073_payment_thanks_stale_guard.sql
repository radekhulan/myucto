-- MyÚčto — automatické poděkování za úhradu jen k čerstvé, dosud neznámé platbě.
--
-- supplier.payment_thanks_max_age_days: platba starší než N dní (pozdě nahraný
-- výpis) už automatické poděkování nevyvolá; 0 = bez omezení.
-- invoices.payment_thanks_suppressed_at: kdy smazání výpisu zrušilo bankovní
-- platbu faktury. Platba se stejným nebo starším datem, spárovaná po opětovném
-- nahrání výpisu, už automaticky neděkuje.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS payment_thanks_max_age_days SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER payment_thanks_attach_paid_pdf;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS payment_thanks_suppressed_at TIMESTAMP NULL DEFAULT NULL AFTER payment_thanks_sent_to;
