-- Samostatné označení uloženého avíza jako přečteného, nezávislé na párování.
-- Existující účty ponechávají dosavadní chování; volba se zapíná výslovně.
ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS mark_seen_after_import TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Označit e-mail jako přečtený po uložení bankovního avíza'
    AFTER ingest_notices;
