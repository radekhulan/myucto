-- GoPay Clearing XML až do 32 MB: MEDIUMBLOB unese jen 16 MB.
SET NAMES utf8mb4;

ALTER TABLE gopay_clearings
  MODIFY COLUMN file_content LONGBLOB NOT NULL;
