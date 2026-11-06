# EET 2.0 — připnuté podklady rozhraní

Soubory jsou veřejné podklady Finanční správy a I.CA. Kód je čte jen z tohoto
adresáře, za běhu se nic nestahuje. Při nové verzi rozhraní je nahraďte a
aktualizujte otisky níže.

| Soubor | Původní URL | SHA-256 |
| --- | --- | --- |
| `EETXMLSchema.xsd` (verze 4.1) | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EETXMLSchema.xsd | `83597d08520cbfab7e51145df9a765d5ca22e2f9a38644a468d695657c7a032a` |
| `EETServiceSOAP.wsdl` (verze 4.1) | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EETServiceSOAP.wsdl | `df149c0bef4ac18c2a6e82116b6eb9bb17d29c055cb0821ac6d3885ec90faeb4` |
| `trust/ica-root-ca-rsa-05-2022.pem` | https://www.ica.cz/sites/default/files/download/2025/rca22_rsa.pem | `f72dc3232df2c9598f60c1bbc82f4bcc5acc80af73a60ed6eb3f6bd67ab473e2` |
| `trust/ica-public-ca-rsa-06-2022.pem` | https://www.ica.cz/sites/default/files/download/2025/pca22_rsa.pem | `db03fb10906d8cc357ef2d68f2f130d6a5381de3ca9a8cbf45216350ca695116` |

## Dokumentace Finanční správy

Dokumenty, podle kterých je implementace psaná. V repozitáři nejsou, jen se na
ně odkazuje; otisk patří ke stažené kopii, aby šlo poznat tichou změnu obsahu.

| Dokument | Verze a datum | URL | SHA-256 PDF |
| --- | --- | --- | --- |
| Popis položek datové zprávy s příklady jejich vyplnění | 1.0, 5. 10. 2026 | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EET_popis_polozek_datove_zpravy.pdf | `de09d084688e871c639b2813ed30ba77e838667f16ec94604b6a3f4df2c3576f` |
| Formát a struktura údajů o evidované tržbě a popis datového rozhraní | 1.2, 25. 8. 2026 | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EET_popis_rozhrani_v1.2.pdf | `c986e85686927e1e153aee0f33090fd6fda80d09b44e564d8b344dde729b1a1e` |
| Neprodukční prostředí (playground), přístupové a provozní informace | 1.1, 30. 6. 2026 | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EET_pristupove_provozni_informace_playgr-3.pdf | `f29c73d3bc84be8d073344dc7053466e9fa143792cbee2bc997f289d67329880` |
| Produkční prostředí, přístupové a provozní informace | 1.1, 10. 7. 2026 | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EET_pristupove_provozni_informace_produk-2.pdf | `377770fd2511c2406bfbd2f647b4863883243860048818d24956c3de57bbfc88` |

V komentářích kódu se „metodikou“ myslí první dokument (popis položek), „popisem
rozhraní“ druhý. Z metodiky vychází hlavně pravidla vzdálených a kontaktních
plateb, kreditu (`urceno_cerp_zuct`, `cerp_zuct`), storna a opravy a evidence
v pověření (situace 4 a 5, `EetDelegation`).

Ověřeno 26. 10. 2026 proti stránce https://eet.gov.cz/cs/pro-vyvojare/dokumenty-ke-stazeni:
nejnovější je popis rozhraní 1.2, XSD a WSDL 4.1 (otisky shodné s tabulkou výše),
přístupové a provozní informace 1.1, certifikáty autorit `ca/` beze změny
a vzorové zprávy `CZ*.eet.v4.req.xml` shodné s `api/tests/Fixtures/eet/`. Proti
verzi 1.0 přinesl popis rozhraní jen upřesnění hlavičky `Content-Type`
(MyÚčto posílá `text/xml; charset=utf-8`) a podporu CORS pro prohlížečové klienty
(serverového odesílání se netýká). Kódy chyb a varování, URL prostředí, limit
12 kB a formáty položek odpovídají kódu.

## Důvěra v podpis potvrzení

Potvrzovací zprávy produkce i playgroundu podepisuje správce daně certifikátem
vydaným `I.CA Public CA/RSA 06/2022` (dokumenty „Přístupové a provozní
informace“, kap. 3.3). Řetěz se ověřuje jen proti těmto dvěma certifikátům, ne
proti systémovému úložišti.

Otisky certifikátů (SHA-256 nad DER):

- `I.CA Root CA/RSA 05/2022`: `d279c01a12e8dd9a6230e459faa447ceb336998477338c2ee4135c96737418eb`
  (shodný otisk je i v úložišti důvěryhodných certifikátů Mozilla)
- `I.CA Public CA/RSA 06/2022`: `df5baf6d7e1a7d14e9911c5b8c676ec6ebcad9354a74f4ac7314e133e07a94de`
  (podepsaný kořenem výše, platný do 17. 6. 2032)

## Certifikační autority pokladních certifikátů (`ca/`)

Pokladní certifikát nahraný firmou musí vést k jedné z těchto autorit; podle
autority se určí prostředí (Playground / produkce). Řetězec přibalený v souboru
`.p12` se za důvěryhodný nebere.

| Soubor | Původní URL | SHA-256 souboru | SHA-256 certifikátu (DER) |
| --- | --- | --- | --- |
| `ca/playground-root.pem` | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/ca_eet-root_cert-playground.crt | `c0257db7f2d9dde3f9413e904814a330cbd2efa41f2ab4ecd8f91f7c5f73f3b6` | `61c1e3c7229e9d3289f56d5293db2d7909b849ffce871b5ea01c690a7c604619` |
| `ca/playground-sub.pem` | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/ca_eet-sub_cert-playground.crt | `28182077cb77338070e209fe9e950481d01ecbc181dfc76ecd7985bd2e0f9046` | `464ffc1fc4810caef379310c857b163be1ca3288ebbe3a5de3413e24d60627ca` |
| `ca/production-root.pem` | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/ca_eet-root_prod.pem | `462afa5e88c894ec414690baef1440419478b31c8524d5de5237a20ce305ec8b` | `639554d87815d6893eca4419ec25e8c919984a3d89e92f144cecaeb611197a2f` |
| `ca/production-sub.pem` | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/ca_eet-sub_prod.pem | `fd0ae27da503f70c99b85affe3c85640cfbc8569b39aef15252c415c30215800` | `9f54d1fe6df6869d3565e15b86c4eb2d9b93b558420ea1a046f1bc85bc884ae0` |

Názvy souborů začínají prostředím (`playground-*`, `production-*`); podle toho
je čte `EetTrustStore`. Novou autoritu přidejte se stejnou předponou.