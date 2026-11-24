-- Časy odchozí fronty podání v čase aplikace (převod existujících řádků).
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Proč
-- ─────────────────────────────────────────────────────────────────────────────
-- `SubmissionOutboxRepository` zapisoval události přes `UTC_TIMESTAMP()`, kdežto
-- `created_at` (a zbytek databáze) je v čase aplikace. Řádek pak vypadal odeslaný
-- hodinu až dvě PŘED založením a den podání daňového tvrzení, který se z fronty
-- promítá do `tax_submissions.submitted_at`, četl UTC jako místní čas: odeslání
-- krátce po půlnoci posledního dne lhůty vypadalo podané včas. Kód teď zapisuje
-- přes `NOW()`; tahle migrace převede řádky zapsané předtím.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Idempotence: značka `time_basis` na řádku
-- ─────────────────────────────────────────────────────────────────────────────
-- Existující řádky dostanou `utc`, výchozí hodnota se pak přepne na `app`, takže
-- každý nový zápis je `app` bez zásahu kódu. Převádí se jen `time_basis = 'utc'`
-- a TÝMŽ příkazem se značka přepne na `app`; opakovaný běh nemá co převést.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Převod UTC → Europe/Prague výpočtem, ne CONVERT_TZ
-- ─────────────────────────────────────────────────────────────────────────────
-- `CONVERT_TZ(x, '+00:00', 'Europe/Prague')` vrátí NULL, když server nemá načtené
-- tabulky časových zón (`mysql.time_zone_name`), což je běžné v Dockeru i na
-- hostingu; UPDATE by tiše vynuloval časy. Proto pravidlo EU letního času:
-- SELČ (+2 h) platí od poslední neděle v březnu 01:00 UTC do poslední neděle
-- v říjnu 01:00 UTC, jinak SEČ (+1 h). Výpočet se ověřil proti CONVERT_TZ
-- s načtenými tabulkami po hodinách 2020–2031 bez jediné odchylky.
--
-- ─────────────────────────────────────────────────────────────────────────────
-- Co se převádí
-- ─────────────────────────────────────────────────────────────────────────────
-- Všechny sloupce, které kód plnil přes `UTC_TIMESTAMP()`. `sent_at` jen tam,
-- kde ho zapsalo odeslání kanálem nebo odesílací branou: ty zakládají v ledgeru
-- pokus s výsledkem `sent`. Ruční odeslání a odeslání doložené doručenkou nesou
-- čas z doručenky, ne UTC, a zůstávají. Stejně zůstává `sent_at`, který by po
-- převodu předběhl `delivered_at` (CHECK `chk_submission_outbox_timeline`).
-- `delivered_at` se nepřevádí: nese čas z kanálu, ne z `UTC_TIMESTAMP()`.
--
-- `tax_submissions.submitted_at` se převede jen u tvrzení, které do stavu
-- „podáno" promítla fronta (`TaxSubmissionDispatchProjection`): odkaz
-- `ISDS <dmID>` téže zprávy a `submitted_at` shodné s dosud nepřevedeným
-- `sent_at`. Ruční „Označit jako podané" tím projít nemůže. Převádí se dřív než
-- fronta, protože shoda stojí na nepřevedené hodnotě; opakovaný běh už shodu
-- nenajde.
--
-- Trigger `trg_submission_outbox_update_guard` drží `sent_at` jako jednorázový
-- zápis, proto se po dobu převodu zahodí a vytvoří znovu se shodným tělem jako
-- v migraci 1384.

SET NAMES utf8mb4;

ALTER TABLE submission_outbox
  ADD COLUMN IF NOT EXISTS time_basis ENUM('utc','app') NOT NULL DEFAULT 'utc'
    COMMENT 'utc = časy zapsané před migrací 2339 přes UTC_TIMESTAMP(); app = čas aplikace'
    AFTER updated_at;

ALTER TABLE submission_outbox ALTER COLUMN time_basis SET DEFAULT 'app';

UPDATE tax_submissions submission
  JOIN submission_outbox outbox
    ON outbox.supplier_id = submission.supplier_id
   AND outbox.artifact_kind = 'tax_submission'
   AND outbox.artifact_id = submission.id
   SET submission.submitted_at = outbox.sent_at + INTERVAL IF(
         outbox.sent_at >= CAST(CONCAT(YEAR(outbox.sent_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-03-31')) - 1) DAY
         AND outbox.sent_at < CAST(CONCAT(YEAR(outbox.sent_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-10-31')) - 1) DAY,
         2, 1) HOUR
 WHERE outbox.time_basis = 'utc'
   AND outbox.channel = 'isds'
   AND outbox.sent_at IS NOT NULL
   AND outbox.external_message_id IS NOT NULL
   AND submission.submission_ref = CONCAT('ISDS ', outbox.external_message_id)
   AND submission.submitted_at = outbox.sent_at
   AND EXISTS (
         SELECT 1 FROM submission_outbox_attempts attempt
          WHERE attempt.supplier_id = outbox.supplier_id
            AND attempt.outbox_id = outbox.id
            AND attempt.outcome = 'sent'
       )
   AND (
         outbox.delivered_at IS NULL
         OR outbox.sent_at + INTERVAL IF(
              outbox.sent_at >= CAST(CONCAT(YEAR(outbox.sent_at), '-03-31 01:00:00') AS DATETIME)
                - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-03-31')) - 1) DAY
              AND outbox.sent_at < CAST(CONCAT(YEAR(outbox.sent_at), '-10-31 01:00:00') AS DATETIME)
                - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-10-31')) - 1) DAY,
              2, 1) HOUR <= outbox.delivered_at
       );

DROP TRIGGER IF EXISTS trg_submission_outbox_update_guard;

UPDATE submission_outbox outbox
   SET outbox.sent_at = IF(
         outbox.sent_at IS NOT NULL
         AND EXISTS (
               SELECT 1 FROM submission_outbox_attempts attempt
                WHERE attempt.supplier_id = outbox.supplier_id
                  AND attempt.outbox_id = outbox.id
                  AND attempt.outcome = 'sent'
             )
         AND (
               outbox.delivered_at IS NULL
               OR outbox.sent_at + INTERVAL IF(
                    outbox.sent_at >= CAST(CONCAT(YEAR(outbox.sent_at), '-03-31 01:00:00') AS DATETIME)
                      - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-03-31')) - 1) DAY
                    AND outbox.sent_at < CAST(CONCAT(YEAR(outbox.sent_at), '-10-31 01:00:00') AS DATETIME)
                      - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-10-31')) - 1) DAY,
                    2, 1) HOUR <= outbox.delivered_at
             ),
         outbox.sent_at + INTERVAL IF(
           outbox.sent_at >= CAST(CONCAT(YEAR(outbox.sent_at), '-03-31 01:00:00') AS DATETIME)
             - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-03-31')) - 1) DAY
           AND outbox.sent_at < CAST(CONCAT(YEAR(outbox.sent_at), '-10-31 01:00:00') AS DATETIME)
             - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.sent_at), '-10-31')) - 1) DAY,
           2, 1) HOUR,
         outbox.sent_at
       ),
       outbox.confirmed_at = outbox.confirmed_at + INTERVAL IF(
         outbox.confirmed_at >= CAST(CONCAT(YEAR(outbox.confirmed_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.confirmed_at), '-03-31')) - 1) DAY
         AND outbox.confirmed_at < CAST(CONCAT(YEAR(outbox.confirmed_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.confirmed_at), '-10-31')) - 1) DAY,
         2, 1) HOUR,
       outbox.failed_at = outbox.failed_at + INTERVAL IF(
         outbox.failed_at >= CAST(CONCAT(YEAR(outbox.failed_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.failed_at), '-03-31')) - 1) DAY
         AND outbox.failed_at < CAST(CONCAT(YEAR(outbox.failed_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.failed_at), '-10-31')) - 1) DAY,
         2, 1) HOUR,
       outbox.accepted_at = outbox.accepted_at + INTERVAL IF(
         outbox.accepted_at >= CAST(CONCAT(YEAR(outbox.accepted_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.accepted_at), '-03-31')) - 1) DAY
         AND outbox.accepted_at < CAST(CONCAT(YEAR(outbox.accepted_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.accepted_at), '-10-31')) - 1) DAY,
         2, 1) HOUR,
       outbox.rejected_at = outbox.rejected_at + INTERVAL IF(
         outbox.rejected_at >= CAST(CONCAT(YEAR(outbox.rejected_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.rejected_at), '-03-31')) - 1) DAY
         AND outbox.rejected_at < CAST(CONCAT(YEAR(outbox.rejected_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.rejected_at), '-10-31')) - 1) DAY,
         2, 1) HOUR,
       outbox.artifact_validated_at = outbox.artifact_validated_at + INTERVAL IF(
         outbox.artifact_validated_at >= CAST(CONCAT(YEAR(outbox.artifact_validated_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.artifact_validated_at), '-03-31')) - 1) DAY
         AND outbox.artifact_validated_at < CAST(CONCAT(YEAR(outbox.artifact_validated_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.artifact_validated_at), '-10-31')) - 1) DAY,
         2, 1) HOUR,
       outbox.recipient_box_verified_at = outbox.recipient_box_verified_at + INTERVAL IF(
         outbox.recipient_box_verified_at >= CAST(CONCAT(YEAR(outbox.recipient_box_verified_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.recipient_box_verified_at), '-03-31')) - 1) DAY
         AND outbox.recipient_box_verified_at < CAST(CONCAT(YEAR(outbox.recipient_box_verified_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.recipient_box_verified_at), '-10-31')) - 1) DAY,
         2, 1) HOUR,
       outbox.receipt_attached_at = outbox.receipt_attached_at + INTERVAL IF(
         outbox.receipt_attached_at >= CAST(CONCAT(YEAR(outbox.receipt_attached_at), '-03-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.receipt_attached_at), '-03-31')) - 1) DAY
         AND outbox.receipt_attached_at < CAST(CONCAT(YEAR(outbox.receipt_attached_at), '-10-31 01:00:00') AS DATETIME)
           - INTERVAL (DAYOFWEEK(CONCAT(YEAR(outbox.receipt_attached_at), '-10-31')) - 1) DAY,
         2, 1) HOUR,
       outbox.time_basis = 'app',
       row_version = row_version + 1
 WHERE outbox.time_basis = 'utc';

-- Shodné tělo jako v 1384_submission_manual_dispatch_receipt.sql.
DELIMITER //

DROP TRIGGER IF EXISTS trg_submission_outbox_update_guard//
CREATE TRIGGER trg_submission_outbox_update_guard
BEFORE UPDATE ON submission_outbox
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.environment <=> OLD.environment)
     OR NOT (NEW.channel <=> OLD.channel)
     OR NOT (NEW.agenda_code <=> OLD.agenda_code)
     OR NOT (NEW.artifact_kind <=> OLD.artifact_kind)
     OR NOT (NEW.artifact_id <=> OLD.artifact_id)
     OR NOT (NEW.artifact_sha256 <=> OLD.artifact_sha256)
     OR NOT (NEW.idempotency_key_hash <=> OLD.idempotency_key_hash)
     OR NOT (NEW.correlation_reference <=> OLD.correlation_reference)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission outbox identity is immutable';
  END IF;

  -- Jak podání odešlo, se zpětně nepřepisuje: na tom visí, které brány se
  -- u něj vyžadovaly.
  IF NOT (NEW.dispatch_mode <=> OLD.dispatch_mode)
     AND OLD.dispatch_state NOT IN ('ready')
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'dispatch mode cannot change after the submission left the queue';
  END IF;

  -- Jednou odeslané podání zůstává odeslané: kdyby šlo vrátit na 'ready',
  -- druhé potvrzení by z něj udělalo duplicitní podání u úřadu.
  IF OLD.dispatch_state NOT IN ('ready','cancelled') AND NEW.dispatch_state = 'ready' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'dispatched submission cannot return to ready';
  END IF;

  IF OLD.sent_at IS NOT NULL AND NOT (NEW.sent_at <=> OLD.sent_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission sent_at is single-assignment';
  END IF;

  IF OLD.external_message_id IS NOT NULL
     AND NOT (NEW.external_message_id <=> OLD.external_message_id)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission external message id is single-assignment';
  END IF;

  -- Doručení je fakt o dopravě; jakmile ho známe, nemá důvod mizet.
  IF OLD.delivered_at IS NOT NULL AND NOT (NEW.delivered_at <=> OLD.delivered_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission delivered_at is single-assignment';
  END IF;

  -- ═══ Doručenka je jednorázový důkaz ═══
  -- Idempotence nahrání nestojí jen na aplikaci: druhý pokus připojit k témuž
  -- podání JINOU doručenku databáze odmítne. Opakované nahrání TÉŽE doručenky
  -- projde (hodnota se nemění), ale nic nezmění.
  IF OLD.receipt_document_id IS NOT NULL
     AND NOT (NEW.receipt_document_id <=> OLD.receipt_document_id)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission delivery receipt is single-assignment';
  END IF;

  IF OLD.receipt_matched_by IS NOT NULL
     AND NOT (NEW.receipt_matched_by <=> OLD.receipt_matched_by)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission receipt match reason is single-assignment';
  END IF;

  -- ═══ Doručenka nesmí posunout osu vyřízení ═══
  -- Kdyby jeden UPDATE zapsal doručení A ZÁROVEŇ vyřízení, byla by to přesně ta
  -- záměna, které se bráníme: „doručenka dorazila → tedy je to přijaté". Změna
  -- osy vyřízení musí přijít vlastním zápisem s vlastním důkazem.
  IF OLD.delivered_at IS NULL AND NEW.delivered_at IS NOT NULL
     AND NOT (NEW.acceptance_state <=> OLD.acceptance_state)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'delivery must not change acceptance state in the same write';
  END IF;

  -- Totéž pro připojení doručenky: zápis, který připojí doručenku a zároveň
  -- hne vyřízením, je vždycky chyba, ne provozní stav.
  IF OLD.receipt_document_id IS NULL AND NEW.receipt_document_id IS NOT NULL
     AND NOT (NEW.acceptance_state <=> OLD.acceptance_state)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'attaching a delivery receipt must not change acceptance state';
  END IF;

  IF OLD.acceptance_state <> 'unknown' AND NEW.acceptance_state <> OLD.acceptance_state THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission acceptance is single-assignment';
  END IF;

  IF NEW.row_version <> OLD.row_version + 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'submission outbox row_version must advance by one';
  END IF;
END//

DELIMITER ;
