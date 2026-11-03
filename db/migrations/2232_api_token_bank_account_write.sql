-- Volitelná schopnost tokenu: změnit bankovní spojení firmy na měnovém účtu.
--
-- Měnové účty (`currencies`) nesou číslo účtu, kód banky, IBAN a BIC, které se
-- tisknou na faktury a do QR platby. API token je proto smí jen číst (viz
-- ApiScopeMiddleware::BEARER_READ_ONLY) a na tom se nic nemění.
--
-- Tenhle příznak otevírá JEDINOU úzkou cestu: `PUT /api/v1/settings/currencies/{id}`
-- a v ní výhradně bankovní pole. Token navíc musí mít scope `read_write` a patřit
-- správci firmy, každá změna se zapíše do auditu a pošle se o ní e-mail.
--
-- ⚠️ Výchozí hodnota je 0 a musí jí zůstat. Existující tokeny tím nic nezískají
-- a nový token schopnost dostane jen tehdy, když ji člověk při vytváření výslovně
-- zaškrtne — a to už je chráněné relací, heslem a MFA.

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS allow_bank_account_write TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'token smí měnit bankovní spojení měnových účtů firmy'
    AFTER allow_payroll_submission_docs;
