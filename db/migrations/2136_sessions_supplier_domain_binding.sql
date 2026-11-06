-- MyÚčto.cz — vazba browser session na vlastní doménu firmy.
--
-- Session vydaná domain-login výměnou na vlastní doméně platí jen na hostname
-- této domény. AuthMiddleware ji na canonical adrese ani na jiné doméně
-- nepřijme a naopak na vlastní doméně nepřijme session bez vazby. NULL znamená
-- běžnou canonical session. Cizí klíč záměrně chybí: smazaná doména nesmí
-- session odvázat (ON DELETE SET NULL by z ní udělal canonical session),
-- osiřelá vazba se už s žádným hostname neshoduje a session dožije nepoužitelná.

SET NAMES utf8mb4;

ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS supplier_domain_id BIGINT UNSIGNED NULL AFTER user_agent;
