-- Výzvu lze evidovat i ručně bez vazby na přijatou datovou zprávu.
-- Privacy guard se uplatní jen tehdy, když vazba na inbox skutečně existuje.

DROP TRIGGER IF EXISTS trg_submission_defect_notice_inbox_privacy;
DELIMITER $$
CREATE TRIGGER trg_submission_defect_notice_inbox_privacy
BEFORE INSERT ON submission_defect_notices
FOR EACH ROW
BEGIN
  DECLARE inbox_state VARCHAR(16);
  IF NEW.inbox_message_id IS NOT NULL THEN
    SET inbox_state = (
      SELECT local_content_state
        FROM submission_inbox_messages
       WHERE supplier_id = NEW.supplier_id AND id = NEW.inbox_message_id
    );
    IF inbox_state IS NULL OR inbox_state <> 'available' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Příchozí zprávu během mazání nelze zaevidovat jako výzvu.';
    END IF;
  END IF;
END$$
DELIMITER ;
