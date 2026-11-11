SET NAMES utf8mb4;

-- Drobný zákazník (#310): karta klienta jen se jménem a e-mailem nebo telefonem, bez
-- adresy a IČO. Takovému odběrateli lze vystavit jen zjednodušený daňový doklad
-- (§ 30 ZDPH) do limitu 10 000 Kč s daní; běžný daňový doklad (§ 29 odst. 1 písm. c)
-- musí nést jméno a adresu odběratele, takže vystavení na takovou kartu brána odmítne.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS simplified_only TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Drobný zákazník bez adresy: jen zjednodušené daňové doklady (§ 30 ZDPH).';
