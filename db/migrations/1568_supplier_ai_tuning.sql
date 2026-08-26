-- MyÚčto.cz — dvě per-tenant páčky na ladění AI extrakce.
--
-- 1) ai_extraction_notes — volné poznámky firmy, které se připojí k system promptu.
--    Model nemá odkud vědět provozní zvláštnosti konkrétní firmy („dodavatel X dává
--    variabilní symbol do pole Reference", „faktury z EU jsou bez DPH"). Dosud se to
--    dalo řešit jen zásahem do kódu promptu, tedy nasazením. Text jde do promptu jako
--    ODDĚLENÁ sekce a nikdy nepřepisuje pravidla schématu — viz InvoiceExtractionPrompt.
--
-- 2) ai_effort — volba rychle/přesně. Providerům se překládá na jejich nativní knob
--    (anthropic output_config.effort, openai reasoning_effort, gemini thinkingLevel).
--
-- DEFAULT ZÁMĚRNĚ 'default' = dnešní chování. Znamená „neposílat nic navíc", takže
-- se stávajícím firmám nemění ani cena, ani latence, ani kvalita pod rukama. Kdo chce
-- jinou polohu, přepne si ji. Modely, které daný knob neumí (claude-haiku-4-5, gpt-4.x),
-- ho nedostanou ani při explicitní volbě — fail-safe, viz LlmProviderCapabilities.

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS ai_extraction_notes TEXT NULL DEFAULT NULL
      COMMENT 'volné poznámky firmy připojené k system promptu AI extrakce (oddělená sekce, nepřepisuje pravidla schématu)',
  ADD COLUMN IF NOT EXISTS ai_effort
      ENUM('default', 'fast', 'accurate')
      NOT NULL DEFAULT 'default'
      COMMENT 'míra uvažování AI: default = neposílat nic (dnešní chování), fast = levně/rychle, accurate = víc uvažování';
