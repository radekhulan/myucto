SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Převod z Money S3: otisk zdrojového záznamu (skupina řádků deníku, doklad) v době, kdy ho
-- převod naposledy zapsal. Opakovaný převod podle něj pozná, že záznam změnilo Money (otisk
-- zdroje se liší), a že ho mezitím neupravil uživatel v MyÚčtu (záznam pořád odpovídá otisku).
-- Starší řádky mapy otisk nemají (NULL) a doplní se při dalším převodu.

ALTER TABLE money_s3_import_map ADD COLUMN IF NOT EXISTS fingerprint CHAR(64) NULL DEFAULT NULL AFTER target_id;

-- Zásah uživatele na přijatém dokladu: majetek zařazený z položky dokladu (kontrola po indexu).
CREATE INDEX IF NOT EXISTS idx_assets_purchase_invoice_item ON assets (purchase_invoice_item_id);
