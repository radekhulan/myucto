-- MyÚčto.cz — doručení mzdového dokumentu zaměstnanci: rozšíření auditní evidence.
--
-- Dosavadní tři události (`handover`, `downloaded`, `external_notification`)
-- popisovaly výhradně to, co udělal ÚČETNÍ mimo systém: předal pásku na papíře,
-- sám si ji stáhl, nebo poslal vlastním e-mailem. Cesta k zaměstnanci uvnitř
-- aplikace neexistovala, takže ani nebylo co zaznamenat.
--
-- Zabezpečený odkaz (viz migrace 1657) přidává čtyři události, které se od těch
-- původních liší v jedné podstatné věci: aktérem NENÍ uživatel aplikace.
-- `recorded_by` u nich zůstává NULL — sloupec je nullable od migrace 1590, takže
-- se tu nic měnit nemusí, jen se poprvé využije.
--
--   secure_link_sent      — e-mail se zabezpečeným odkazem byl odeslán
--   secure_link_failed    — odeslání selhalo (worker to zapíše i po vyčerpání pokusů)
--   secure_link_revoked   — účetní odkaz zneplatnila; obsah už z něj nejde vydat
--   self_downloaded       — zaměstnanec se ověřil jednorázovým kódem a dokument stáhl
--
-- `self_downloaded` je vědomě VLASTNÍ typ, ne recyklované `downloaded`. Bez toho
-- by se v evidenci slilo „účetní si pásku otevřela v prohlížeči" a „zaměstnanec
-- si ji převzal", což je právě ten rozdíl, kvůli kterému evidence existuje.
--
-- Rozšíření ENUM je čistě aditivní: žádná existující hodnota nemizí, žádný řádek
-- se nepřepisuje (tabulka je ostatně append-only a UPDATE na ní blokuje trigger
-- `trg_payroll_document_delivery_immutable_update`).

SET NAMES utf8mb4;

ALTER TABLE payroll_document_delivery_events
  MODIFY event_type ENUM(
    'handover',
    'downloaded',
    'external_notification',
    'secure_link_sent',
    'secure_link_failed',
    'secure_link_revoked',
    'self_downloaded'
  ) NOT NULL;
