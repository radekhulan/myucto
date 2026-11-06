# JMHZ a navazující datové věty ČSSZ/MPSV

Tento adresář obsahuje připnuté kopie oficiálních XSD pro jednotné měsíční
hlášení zaměstnavatelů a související registrační datové věty. Každý balíček je
ve vlastním verzovaném adresáři, aby se stejně pojmenované závislosti
`baseTypes2.xsd` nemíchaly mezi různými formuláři.

Balíček JMHZ nově veze vedle vnějších schémat i vnitřní (`interni_xsd`) a HTML
dokumentaci. Připíná se proto jen podstrom `xsd_1_4_3_6/externi_xsd`, který
manifest uvádí jako `xsd_root`; uložený tvar tím zůstává plochý jako dřív.
Vnitřní schémata se odkazují přes `../externi_xsd/…`, aplikace proti nim nic
nevaliduje a kontrola cest je záměrně nepřipouští.

Staženo a znovu ověřeno dne 8. 9. 2026 z
[vývojářské dokumentace MPSV](https://developers.mpsv.cz/api-list/jednotne-mesicni-hlaseni-zamestnavatelu/documentation/4589f5c6-30e8-4e2b-b341-fe8481ad4e70).

| Balíček | Vstupní XSD | Oficiální archiv | SHA-256 archivu |
|---|---|---|---|
| JMHZ 1.4.3.6 | `jmhz-1.4.3.6/jmhzPodani.xsd` | [JMHZ_podání_1.4.3.6.zip](https://developers.mpsv.cz/assets/documents/a0ca7983-9aed-40cc-aa96-8a97c3641a88/JMHZ_pod%C3%A1n%C3%AD_1.4.3.6.zip) | `79a08fc60b2cb7753a772100463a1f80259437ab99cfcee8d2e06061e220e879` |
| REGZEC 1.4.0.4 | `regzec-1.4.0.4/REGZEC25.xsd` | [REGZEC25_ver_1.4.0.4.zip](https://developers.mpsv.cz/assets/documents/1929cebf-fc5e-41e9-8319-97248cb22e8e/REGZEC25_ver_1.4.0.4.zip) | `0d0396fd857a6602b01a3ecf234fe02da96f00f316eea34de6e67b06e4cc2b1f` |
| PREZEC 1.2 | `prezec-1.2/PREZEC26 1.2.xsd` | [PREZEC26_ver_1.2.zip](https://developers.mpsv.cz/assets/documents/893169c6-1c40-4555-a5b4-a5621d80d98c/PREZEC26_ver_1.2.zip) | `dda370c1f24ebbef1462c305b526e61fdebd6c280e97624aad8e8a6426216224` |
| REGZELDOPL 1.2 | `regzeldopl-1.2/REGZELDOPL25.xsd` | [REGZELDOPL25_v1_2.zip](https://developers.mpsv.cz/assets/documents/eddd6a43-f713-43c8-91e3-eceb9b1a796f/REGZELDOPL25_v1_2.zip) | `6f0eb190573336d3250130206a34d84fa228c7bc9fec2f0dd9176cb29e120dd3` |
| DZMH 1.1 | `dzmh-1.1/DZMH25.xsd` | [DZMH25_xsd v1.1.zip](https://developers.mpsv.cz/assets/documents/85fb9c97-b3f4-40d9-98dc-1d171e21f84c/DZMH25_xsd%20v1.1.zip) | `1e89ec55b56b3e00f3f6a066e92bf3e39d29b05a5e2f0f8c7be95ead65111d06` |
| OREZAM/ZREZAM 1.0 | `orezam-zrezam-1.0/OREZAM26.xsd`, `orezam-zrezam-1.0/ZREZAM26.xsd` | [OREZAM a ZREZAM xsd.zip](https://developers.mpsv.cz/assets/documents/22f9953d-f0db-4578-afd4-e17ed98e0df2/OREZAM%20a%20ZREZAM%20xsd.zip) | `9a153012035ac821a30bd9f5e437ea4b92b662ae058fefa319d6972dcd6c43dc` |

## Balíčky pro rok 2027

MPSV provozuje verze 2026 a 2027 souběžně a volí se podle vykazovaného období
(od 1. 1. 2027), ne podle data odeslání. Balíčky 2027 proto leží vedle balíčků
2026 a žádný se nepřepisuje. Jde o finální návrh MPSV (připomínkové řízení
stále probíhá), stav dokumentace k 14. 10. 2026.

| Balíček | Vstupní XSD | Jmenný prostor | `version` schématu | Oficiální archiv | SHA-256 archivu |
|---|---|---|---|---|---|
| JMHZ 1.5.0.3 | `jmhz-1.5.0.3/jmhzPodani.xsd` | `http://schemas.cssz.cz/JMHZ/podani/2027` (formuláře `.../JMHZ/form/2027`, typy `.../JMHZ/simpleTypes/2027`) | `1.5.0.3` | [JMHZ_podani_1.5.0.3.zip](https://developers.mpsv.cz/assets/documents/99dab4c2-7b7c-4422-bf9e-b5cb8192f426/JMHZ_podani_1.5.0.3.zip) | `e8fe64bb6bea0e12e921995efd2014f8e80360d6df9e3cb00189bc4a87763941` |
| REGZEC27 1.5 | `regzec-1.5/REGZEC27_1_5.xsd` | `http://schemas.cssz.cz/REGZEC/2027` | `1.5.0.0` | [REGZEC27_podaní_1.5.zip](https://developers.mpsv.cz/assets/documents/c3045862-665b-4a15-bc2f-9eac5b68ab96/REGZEC27_podan%C3%AD_1.5.zip) | `37a69516ac8eeb5b18f07da94355e6d6cd2b6b0967526ff10733df4a8c8068ae` |
| PREZEC 1.5 | `prezec-1.5/PREZEC26_1_5.xsd` | `http://schemas.cssz.cz/PREZEC/2026` | `1.5.0.0` | [PREZEC26_podaní_1_5.zip](https://developers.mpsv.cz/assets/documents/0dcadaa1-dd30-4a8a-b95a-8a262787a007/PREZEC26_podani%CC%81_1_5.zip) | `dc509815450ea60c4763fe354e3f423e0d3c133694e54c2fcb7430cff64af3ef` |
| DZMH 1.5 | `dzmh-1.5/DZMH25_1_5.xsd` | `http://schemas.cssz.cz/JMHZ/dotazNaStav/2025` | `1.5.0.0` | [DZMH25_podani_1.5.zip](https://developers.mpsv.cz/assets/documents/d6178272-fa28-4891-b95d-f324385f6c3d/DZMH25_podani_1.5.zip) | `7ebe93ea3898e88d5097ae5a9b6ef10521c27bfa87aea90b519f8ab9d4971e23` |
| ZREZAM27 1.5 | `zrezam-1.5/ZREZAM27_1_5.xsd` | `http://schemas.cssz.cz/ZREZAM/2027` | `1.5.0.0` | [ZREZAM_podani_1.5.zip](https://developers.mpsv.cz/assets/documents/6f8b270d-6669-4776-a6ae-2a6d43584b24/ZREZAM_podani_1.5.zip) | `23320535b04b83a7b992456f18ffb7ff800967da5288587e8b9d932cede1e114` |

Balíček JMHZ 1.5.0.3 veze schémata ještě jednou zabalená (vnitřní archiv
`JMHZ_podani_1.5.0.3_xsd.zip` vedle HTML dokumentace), proto manifest uvádí
`inner_archive`. Součástí je nový `simpleTypes.xsd` (jmenný prostor
`.../JMHZ/simpleTypes/2027`). Každý balíček má vlastní `baseTypes2.xsd`
(JMHZ 1.5.0.3 jiný než REGZEC, PREZEC, DZMH a ZREZAM), proto se verzované
adresáře nesdílejí. Metadata Finderu (`__MACOSX/`, `._*.xsd`) se nepřipínají. PREZEC a DZMH
zůstávají na jmenných prostorech `/2026`, resp. `/2025`; MPSV je publikuje
i pod názvy `PREZEC27_podani_1.5.zip` a `DZMH27_podani_1.5.zip` se shodnými
schématy (bajtově stejné XSD, jiný název souboru).

Aktualizace se provádí přes `pwsh -File cmd\download-jmhz-xsd.ps1` nebo
`cmd\download-jmhz-xsd.cmd` na Windows a přes
`bash cmd/download-jmhz-xsd.sh` na Linuxu. Nadále lze použít i obecný
`download-xsd` s argumentem `jmhz`.

Manifest `tools/jmhz-xsd-packages.php` připíná verzi, oficiální URL, SHA-256
archivu, přesný počet XSD a vstupní schémata. Downloader fail-closed odmítne
jiný host nebo cestu (včetně redirectu), příliš velký či ne-ZIP obsah, chybný
hash, neočekávaný počet souborů, chybějící entry point, nevalidní XSD a síťovou,
chybějící nebo adresář opouštějící závislost. Instalace proběhne atomicky až po
ověření všech balíčků. Soubor `SHA256SUMS` je deterministický katalog
všech uložených XSD včetně zachovaných starších verzí. Neobsahuje čas stažení
ani lokální cesty; cílený test kontroluje úplnost seznamu i každý hash.

Protože `SHA256SUMS` připíná uložená schémata bajt po bajtu, musí je Git
předávat beze změny — kořenový `.gitattributes` proto drží `*.xsd -text`. Bez
toho by Windows checkout dostal CRLF, Linux LF a manifest by seděl vždy jen na
jedné platformě. Hashe generuje downloader z rozbaleného archivu, takže se
připínají skutečné bajty ČSSZ/MPSV, ne lokálně znormalizovaná kopie.

Stejným způsobem jsou připnuté číselníky a datový slovník; jejich manifest,
downloader a postup obnovy popisuje `api/resources/payroll/jmhz/README.md`.

Změna URL, verze, kontrolního součtu, počtu souborů nebo entry pointu musí být
vědomá a musí ji doprovodit aktualizace tohoto manifestu, této tabulky a testů.
XSD ověřuje syntaxi a strukturu; nenahrazuje verzovaná aplikační business
pravidla ani odborné legislativní ověření.
