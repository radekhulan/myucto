-- Výkazy práce na úvodní stránce (rozpracované koncepty vystavených faktur k doplnění)
-- jako volba firmy v Nastavení → Fakturace a e-maily. Výchozí zapnuto, jako dosud.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS dashboard_work_reports TINYINT(1) NOT NULL DEFAULT 1;
