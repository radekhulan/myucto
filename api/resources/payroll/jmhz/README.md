# Číselníky a datový slovník JMHZ

Tento adresář obsahuje připnuté kopie oficiálních podkladů ČSSZ/MPSV, ze kterých
se staví datový slovník, katalog kontrol, scénářová matice a číselníky JMHZ.
Každý balíček je ve vlastním verzovaném adresáři a vedle zdrojů leží
deterministický `manifest.json`, který je jediným čtecím vstupem aplikace.

Připnutý stav pro rok 2026: datový slovník 1.4.1.6, katalog kontrol 1.4.2.11,
datové scénáře 1.4.0.2 a časový registr overlay externích číselníků. Balík pro
rok 2027 je popsán v samostatné kapitole níže. Slovník připíná
**46 číselníků** — 40 vložených se 783 položkami a 6 externích referencí, které
zůstávají prázdné. Overlay k nim doplňuje **6 254 obcí (CISOB)** a
**250 zemí (CZEMALFA)**.

Registry automaticky vybírá neměnný právní balíček podle celého mzdového
období. Vyhláška č. 511/2025 Sb. pokrývá období do 31. 8. 2026, vyhláška
č. 145/2026 Sb. od 1. 9. 2026. Za ověřené se považuje jen období, jehož první
i poslední den pokrývá stejný balíček; samotná kontrola konce nebo začátku by
u nestandardního období překračujícího právní hranici nebyla dostatečná.
Původní overlay ze 13. 8. 2026 se nedistribuuje: jeho zdroje jsou bajtově
shodné s balíčkem do 31. 8. Jeho klíč a SHA-256 zůstávají v registru, takže
uložené provenience s touto identitou se dál ověřují proti období.

| Zdroj | Soubor | Původ | SHA-256 |
|---|---|---|---|
| Datový slovník 1.4.1.6 | `dictionary-1.4.1.6/datovy_slovnik_1.4.1.6.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/f389e547-8bc0-4470-9531-f8319ff4d11e/datovy_slovnik_1.4.1.6.xlsx) | `e794a56d3baa48dd876ad45a0deb5b1bb77c17a0cb44a3511e8ef4028be69743` |
| Katalog kontrol 1.4.2.11 | `dictionary-1.4.1.6/Katalog kontrol MH(public)_1.4.2.11.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/8aea9a31-e27a-4635-934e-6d2a32289976/Katalog%20kontrol%20MH%28public%29_1.4.2.11.xlsx) | `e5b664c135b63774b555e067c5ed83991bd919f023f3f577bcfafd030b441b62` |
| Datové scénáře 1.4.0.2 | `dictionary-1.4.1.6/datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/9fad6021-73d0-4914-80c8-609716b5697d/datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx) | `cc282115d58a3744348b500a2dcc6eec4a5899b12753ec756f01fe261fd7ff37` |
| CISOB, právní pokrytí do 31. 8. | `external-codebooks-2026-08-31/sb-2025-511-priloha-2-fragment-1093782.ttl` | stejné autoritativní bajty 511/2025 Sb.; samostatný neměnný manifest právního období | `b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44` |
| CISOB od 1. 9. 2026 | `external-codebooks-2026-09-01/sb-2026-145-priloha-2-fragment-1836642.ttl` | [e-Sbírka, vyhláška č. 145/2026 Sb., příloha č. 2](https://e-sbirka.gov.cz/sb/2026/145/2026-09-01), [OpenData fragment 1836642](https://opendata.eselpoint.gov.cz/esel-esb/právní-akt-fragment/1836642) — **ruční zdroj** | `2263cd58c4dc589e42bc48f13f30db464ffce16e611762364c73f6a1c5bbc003` |
| CZEMALFA (státy) | `external-codebooks-2026-09-01/CIS1186_CS_2026-08-26.csv` | [ČSÚ, číselník 1186](https://apl2.czso.cz/iSMS/cisdet.jsp?kodcis=1186) — **ruční zdroj**; bajtově shodný s otiskem ze 13. 8. | `940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886` |

## Balík specifikace pro rok 2027

Vedle balíku 2026 leží balík `dictionary-1.5.0.3` pro vykazovaná období od
1. 1. 2027. Jde o finální návrh MPSV (připomínkové řízení stále probíhá), stav
dokumentace k 14. 10. 2026. Klíč balíku je
`jmhz-xsd-1.5.0.3_dictionary-1.5.0.3_controls-source-1.5.0.4_manifest-v1`,
otisk manifestu slovníku
`fc244e34b74117cfc332b62d787738754562b91c61f33ee29370197967f8e5b0`.

| Zdroj | Soubor | Původ | SHA-256 |
|---|---|---|---|
| Datový slovník 1.5.0.3 | `dictionary-1.5.0.3/datovy_slovnik_1.5.0.3.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/03bb1d62-3177-4d4c-b568-7c2667522e2c/datovy_slovnik_1.5.0.3.xlsx) | `861bc297adbadd475efa68214bdf194ce1b60a14337d5cf10307cf00b12e92ca` |
| Katalog kontrol 1.5.0.4 | `dictionary-1.5.0.3/Katalog kontrol MH(public)_1.5.0.4.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/4dff6148-81d7-41bc-9042-8fd1b2144033/Katalog%20kontrol%20MH%28public%29_1.5.0.4.xlsx) | `748dd83a376540b85d5e0942af95ba6230671007c6e4269dda50db74faa88d35` |
| Datové scénáře 1.5.0.3 | `dictionary-1.5.0.3/datove_scenare_interakce_povinnosti_MH_1.5.0.3.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/516ed642-d347-4753-8561-6ba68cfa0924/datove_scenare_interakce_povinnosti_MH_1.5.0.3.xlsx) | `c4cb0704208f463417b564fd91a1884c71789b84d2966b074af33e5c41b5f45e` |
| Definice externí datové věty REGZEC 1.5.0.3 (dokumentace) | `edv-1.5.0.3/Definice externí datové věty_1.5.0.3.xlsx` | [developers.mpsv.cz](https://developers.mpsv.cz/assets/documents/bbaf3cbc-d945-40d4-baa4-9f160f449ed2/Definice%20extern%C3%AD%20datov%C3%A9%20v%C4%9Bty_1.5.0.3.xlsx) | `1b1a4c1ec0d1e49663d0b793539a67c655555dfc208e2a4ba792f07226bc79c0` |

Slovník 2027 má 454 atributů (27 nových, 15 zrušených proti 2026, mezi nimi
10267, 10292 až 10296, 10310, 10347, 10411, 10412, 10416, 10418 a 10531 až
10533), 45 číselníků (krajské GFŘ pracoviště přestaly být číselníkem; 7
externích referencí zůstává prázdných) a 597 položek. Katalog kontrol má 221
kontrol (22 nových, zrušená 109, kontrola 290 nově nepropustná) a ve sloupcích
N a O místo zdroje kontroly nese Poznámku a "Konzultace / Tbd". Scénáře 2027
přidávají interakce IN38 až IN43 (celkem 43). V sešitu scénářů zůstávají tři
atributy (10255, 10407, 10258) bez povinnosti ve dvou scénářích a v IN06;
builder je vede jako `unspecified` a jako anomálii `blank_requirement_cells`,
nikdy jako nepovinné.

Kontroly verze 2027 načítá `JmhzControlSourceCatalog::loadFor(JmhzSpecVersion::V2027)`
a vykonává tentýž evaluator s ročníkem 2027 (`JmhzScenario1ControlValidator::create(…,
JmhzSpecVersion::V2027)`). Projekce atributů čte XML ve jmenných prostorech `/2027`
přes slovník 1.5.0.3 a XML jiného ročníku odmítne kódem
`jmhz_projection_version_mismatch`. Kontrola 109 ve verzi 2027 není, 333 má
zase význam časového omezení slevy a je `not_evaluable`, kontroly 245, 325,
365 a 380 až 382 rozhoduje registr nebo počet mzdových účtáren
(`not_evaluable`). Věcně změněné kontroly (57, 78, 79, 193, 194, 244, 248,
282, 283, 304, 369) mají variantu 2027, kód obce 111111 „Mimo ČR" prochází
kontrolami 152 a 335 a hlídá ho kontrola 385. Verze 2026 se nemění.

Balík staví tytéž buildery jako balík 2026, jen s volbou `--profile=2027`
(profily a připnuté hashe jsou v `tools/jmhz-spec-package-profiles.php`):

```
php tools/JmhzDictionaryPackageBuilder.php <slovník.xlsx> <katalog.xlsx> <výstup> --profile=2027
php tools/JmhzControlCatalogPackageBuilder.php <katalog.xlsx> <výstup>/control-manifest.json --profile=2027
php tools/JmhzScenarioRequirementPackageBuilder.php <scénáře.xlsx> <výstup> --profile=2027
php tools/JmhzEdvSourcePackageBuilder.php <edv.xlsx> <výstup>
```

Zdroje musí mít v adresáři plné oficiální názvy bez předpony GUID. Bez volby
`--profile` stavějí buildery balík 2026 bajtově stejně jako dřív. EDV balíček
je jen dokumentace, aplikace z něj za běhu nic nečte; jeho manifest souhrnně
připíná list Changelog (71 záznamů verzí 1.5 až 1.5.0.3).
Postup obnovy při nové verzi podkladu je stejný jako níže: nové soubory se
nejdřív připnou v profilu (hash, verze, počty) a teprve pak se balík postaví.

## Obnova

```
pwsh -File cmd\download-jmhz-codebooks.ps1            # Windows
cmd\download-jmhz-codebooks.cmd
bash cmd/download-jmhz-codebooks.sh                   # Linux
```

Volby (platí pro všechny tři obálky):

| Volba | Význam |
|---|---|
| *(bez voleb)* | Stáhne, ověří a atomicky nainstaluje připnuté zdroje. |
| `--verify` | Jen ověří strom na disku; nic nestahuje ani nezapisuje. |
| `--candidate=ADRESÁŘ` | Stáhne kandidáta mimo připnutý strom a nic nenainstaluje. |
| `--diff=SOUBOR --report=SOUBOR` | Porovná připnutý a kandidátský `manifest.json` a zapíše strojový report změn. |

Návratový kód `0` znamená beze změny, `3` nalezenou změnu (kandidát nebo diff)
a `1` jakoukoli chybu ověření. Při chybě se na disku nemění nic.

## Co downloader vynucuje

Manifest `tools/jmhz-codebook-sources.php` připíná u každého zdroje verzi,
oficiální URL, SHA-256, přesný počet bajtů, povolený typ obsahu a očekávaný
podpis souboru. U výsledných katalogů připíná navíc verzi schématu, identitu
balíku, `manifest_sha256`, očekávané počty položek a seznam číselníků, které
musí zůstat prázdnou externí referencí.

Downloader fail-closed odmítne:

- jiný host, schéma, port nebo cestu než připnutou cestu k archivu MPSV,
  a to i po přesměrování (protokolově relativní i mimo-hostový cíl);
- obsah přes velikostní limit, s jiným `Content-Type` nebo bez očekávaného
  podpisu (`PK\x03\x04` u XLSX, čistý UTF-8 bez BOM u textových zdrojů);
- jiný SHA-256 nebo jiný počet bajtů, než je připnuto;
- katalog, jehož `manifest_sha256` nesedí na kanonický otisk vlastního
  `payload`, který má jinou identitu balíku, jinou verzi schématu, jiné počty
  položek nebo neodkazuje na připnutý základní balík;
- jakýkoli ze šesti externích číselníků, pokud přestane být prázdnou externí
  referencí — hodnoty se pro ně nikdy nedoplňují;
- soubor, který v manifestu chybí, na disku chybí nebo nesedí na hash zapsaný
  přímo v `payload.sources`.

Instalace proběhne až po ověření všech zdrojů i katalogů, a to atomickým
přejmenováním připraveného stromu; při jakékoli chybě zůstane připnutý strom
beze změny. Soubor `SHA256SUMS` je deterministický katalog obsahu celého
adresáře (bez sebe sama a bez `.md`); neobsahuje čas stažení ani lokální cesty.
Aby ho Git předával bajt po bajtu, drží kořenový `.gitattributes` konce řádků
i binární režim pro `*.xlsx`, `*.csv`, `*.ttl`, `*.json` i pro `SHA256SUMS`.

## Past: NFC vs. NFD v názvech na blob storage MPSV

Blob storage MPSV míchá klíče v obou normalizacích Unicode. Soubor
`Seznam zaměstnanců.csv` je například dostupný **jen** pod klíčem v NFD —
požadavek na `…Seznam%20zam%C4%9Bstnanc%C5%AF.csv` (NFC) vrací
`404 The specified blob does not exist`, zatímco
`…Seznam%20zame%CC%8Cstnancu%CC%8A.csv` (NFD) vrací `200`. Downloader proto
každou URL nejdřív ověří v připnuté podobě a při 404/410 zkusí druhou
normalizaci; když neexistuje ani jedna, běh fail-closed skončí. Převod nepoužívá
`ext-intl` (na cílových strojích chybí), ale uzavřenou tabulku českých znaků —
název se znakem mimo tabulku se odmítne, místo aby se tiše přeložil špatně.
Chování je zafixované fixturou `api/tests/Fixtures/Payroll/jmhz-mpsv-blob-normalization.json`,
která nese skutečně naměřené stavové kódy; testy nechodí na síť.

## Detekce změn (report)

`JmhzCodebookManifestDiff` porovná připnutý a čerstvě postavený `manifest.json`
a vypíše strojově čitelný report `jmhz-codebook-change-report.v1`: přidané,
odebrané a změněné kódy položek po číselnících (u změněných včetně `row_hash` a
obou popisků), přírůstek a úbytek celých číselníků a identitu obou stran včetně
`versions`, `snapshot_date` a `counts`. Report se **nikdy neaplikuje
automaticky** — je to podklad pro člověka. Neukládá se do databáze a za běhu
aplikace se z něj nečte.

Kandidátský `manifest.json` vzniká spuštěním
`tools/JmhzDictionaryPackageBuilder.php` (resp.
`JmhzExternalCodebookPackageBuilder.php`) nad staženými zdroji. Buildery mají
vlastní připnuté SHA-256, takže nad novou verzí podkladu vědomě selžou —
to je ta lidská brána. Při shodné verzi vyrobí bajtově identický manifest, což
je zároveň kontrola, že se generování nerozešlo se zdrojem.

## Postup při nové verzi podkladu ČSSZ/MPSV

1. `--candidate=ADRESÁŘ` stáhne nové soubory mimo připnutý strom (kód `3`).
2. Aktualizovat SHA-256 a počty bajtů v builderech a nechat je postavit
   kandidátský `manifest.json`.
3. `--diff` proti připnutému manifestu a projít report změn.
4. Teprve po odsouhlasení přepnout `tools/jmhz-codebook-sources.php` (URL,
   verze, hash, počet bajtů, `manifest_sha256`, počty položek), aktualizovat
   tuto tabulku i testy a spustit obnovu bez voleb.

Změna URL, verze, kontrolního součtu, počtu položek nebo seznamu externích
referencí musí být vědomá. Manifest ověřuje původ a integritu; nenahrazuje
verzovaná aplikační business pravidla ani odborné legislativní ověření.

## Změny kontrol ve verzi 1.4.2.11

- **Kontrola 368** (DIS, nepropustná, kód chyby 20368): počet dnů pojištění
  ELDP sekce (10356) nesmí přesáhnout interval platnosti jejího kódu (10241
  až 10242). Katalog píše rozdíl dat stejně jako u kontroly 134; interval se
  počítá včetně krajních dnů. Není to odchylka: „platnost do" je podle Pokynů
  k vyplnění MH 1.4.14 poslední den platnosti a vzorová hlášení v Příloze A
  uvádějí 1. 2. až 28. 2. s 28 dny. Kontroly 134 ani 368 proto nejsou mezi
  zdokumentovanými odchylkami.
- **Kontrola 369** (DIS, nepropustná, kód chyby 20369): sekce s kódem ELDP
  (10240) musí mít vyplněnou platnost od (10241) i do (10242).
- **Kontrola 371** (cJMHZ, propustná, informativní): chybějící údaje cizinců
  v registru zaměstnanců (10414, 10105 až 10110). Rozhoduje registr ČSSZ,
  proto je vedena jako `not_evaluable`; hláška vyzývá k doplnění přes REGZEC A3.
- **Kontrola 333**: katalog sjednotil deklarované atributy s pravidlem
  (10495 a 10016). Zdrojová anomálie `official_detail_attribute_mismatch`
  z verzí 1.4.2.9 a 1.4.2.10 zmizela a kontrola se vyhodnocuje stejně jako
  332: příznak primárního pracovněprávního vztahu je povinný kromě scénáře
  pro druh činnosti 10 a stornujícího formuláře. Název a hláška řádku dál
  mluví o časovém omezení slevy za 01 až 03/2026; vyhodnocuje se pravidlo.
- Texty kontrol 96, 164, 226, 238, 291, 336, 337 a 339 se upřesnily bez
  dopadu na lokální vyhodnocení; odůvodnění v evaluatoru jsou zarovnaná.

## Změny kontrol ve verzi 1.4.2.10

- **Kontrola 22** (DIS, nepropustná, kód chyby 20022) hlídá duplicitu GUID
  podání: idPodani použité s jiným variabilním symbolem nebo za jiné období
  a shodné řádné (R) nebo stornovací (S) podání, které už ČSSZ má. Rozhoduje
  o tom evidence ČSSZ, proto je vedena jako `not_evaluable`. Kdyby zůstala bez
  vykonávací implementace, zablokovala by jako nepokrytá každé podání.
  Protokol, jehož jedinou chybou je varianta 3 nebo 4 (shodné R nebo S už
  existuje), aplikace nebere jako zamítnutí, ale jako doklad, že originál
  podání u ČSSZ je.
- **Kontrola 290** (cJMHZ, propustná) platí nově pro jakékoli podání po
  zákonné lhůtě a porovnává slevu s posledním akceptovaným hlášením podaným
  v lhůtě i po ní. Lokálně se vyhodnotí, zda dopadá (po splatnosti pojistného
  a se slevou 10032 nad nulou); v tom případě je to varování, které je nutné
  při zmrazení výslovně potvrdit.
