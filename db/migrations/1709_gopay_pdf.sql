-- MyÚčto.cz - volitelná PDF příloha k GoPay XML vyúčtování.

SET NAMES utf8mb4;

ALTER TABLE gopay_clearings
  ADD COLUMN IF NOT EXISTS pdf_content MEDIUMBLOB NULL AFTER file_content,
  ADD COLUMN IF NOT EXISTS pdf_name VARCHAR(255) NULL AFTER pdf_content,
  ADD COLUMN IF NOT EXISTS pdf_hash CHAR(64) NULL AFTER pdf_name,
  ADD COLUMN IF NOT EXISTS pdf_size_bytes INT UNSIGNED NULL AFTER pdf_hash,
  ADD COLUMN IF NOT EXISTS pdf_uploaded_at TIMESTAMP NULL AFTER pdf_size_bytes;
