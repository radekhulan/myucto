-- MyÚčto.cz — MZ-16-W05: přesné názvy auditních událostí doručení.

SET NAMES utf8mb4;

ALTER TABLE payroll_document_delivery_events
  MODIFY event_type ENUM(
    'handover','viewed','email_notification','downloaded','external_notification'
  ) NOT NULL;

UPDATE payroll_document_delivery_events
   SET event_type = CASE event_type
     WHEN 'viewed' THEN 'downloaded'
     WHEN 'email_notification' THEN 'external_notification'
     ELSE event_type
   END
 WHERE event_type IN ('viewed', 'email_notification');

ALTER TABLE payroll_document_delivery_events
  MODIFY event_type ENUM('handover','downloaded','external_notification') NOT NULL;
