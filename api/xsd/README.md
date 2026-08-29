# XSD schémata

Commitnutá veřejná schémata pro **automatickou XSD validaci** vygenerovaného XML:

1. **EPO MFČR** výkazy (DPH/KH/SH/DPFO/DPPO) — validace daňových podání.
2. **ISDOC 6.0.2** (`isdoc-invoice-6.0.2.xsd`) — validace exportu faktur; ověřuje
   ji unit test `tests/Unit/Service/Export/IsdocExporterSchemaTest`.
3. **JMHZ a navazující věty ČSSZ/MPSV** — verzované balíčky v `jmhz/`, včetně
   lokálních závislostí a kontrolních součtů oficiálních archivů.
4. **Jednotná datová věta zdravotních pojišťoven** — `zp/2025-v8/`, viz níže.

Aktuální verze jsou v repo — clone má funkční validaci bez setup kroku. Re-stáhnout
přes `bash cmd/download-xsd.sh` nebo `cmd\download-xsd.cmd` (při novém ročníku MFČR,
příp. nové verzi ISDOC).

## Zdroj ČSSZ/MPSV — JMHZ

Jednotné měsíční hlášení zaměstnavatelů a související registrační věty používají
vlastní schémata a komunikační kanály ČSSZ, nikoli EPO finanční správy. Připnuté
verze, vstupní XSD, oficiální URL a SHA-256 archivů jsou v
[`jmhz/README.md`](jmhz/README.md).

Aktualizace celé sady:

```powershell
pwsh -File cmd\download-jmhz-xsd.ps1
cmd\download-jmhz-xsd.cmd
```

```bash
bash cmd/download-jmhz-xsd.sh
```

Obecný `download-xsd` s argumentem `jmhz` používá stejné wrappery. Downloader
přijímá jen HTTPS archivy z připnuté cesty `developers.mpsv.cz/assets/documents`,
ověřuje i každý redirect, SHA-256 celého ZIPu, bezpečnost cest, počet XSD,
očekávaná vstupní schémata a úplnost lokálních `include`/`import`. Až poté
atomicky nahradí verzované adresáře; při jakékoli odchylce zůstane původní sada
beze změny.

## Zdroj zdravotních pojišťoven — jednotná datová věta (`zp/2025-v8/`)

Od **1. 1. 2026** je hromadné oznámení zaměstnavatele (HOZ) i přehled o platbě
zaměstnavatele (PPZ) společné pro všech **sedm** pojišťoven. Autorem obou schémat
je VZP (namespace `xmlns.vzp.cz`), revize **08** z 8. 12. 2025.

| Dokument | Soubor | SHA-256 |
| --- | --- | --- |
| HOZ | `hromadneOznameniZamestnavatele_2025_v8.xsd` | `67b19d3f70b27f30b7f26b46da79a75f53c89a7c6cf04adc81111558826959d9` |
| PPZ | `prehledPlatbyZamestnavatele_2025_v8.xsd` | `fee3c66233bc3c2bd78e283b76918759be9b6a701003c8bffeb6db91e311cba1` |

Otisky jsou zároveň připnuté v `HealthInsuranceSchemaCatalog`; soubor s jiným
otiskem katalog neuzná a validace skončí `zp_schema_bundle_missing`. Instalace
z lokálně staženého adresáře: `pwsh -File cmd\install-zp-xsd.ps1 <adresář>`.

**Společné je schéma, ne kanál.** Transportní obálku žádná ze sedmi pojišťoven
veřejně nedokládá, takže se nic neodesílá automaticky — viz
`HealthInsurerChannelCatalog`.

## Zdroje EPO MFČR

📋 **Seznam schémat (popis struktury):**
https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_seznam.faces

📥 **Přímé URL k XSD souborům** (formát: `https://adisspr.mfcr.cz/adis/jepo/schema/{form}_epo2.xsd`):

| Filename | Formulář | URL |
|---|---|---|
| `dphdp3.xsd` | DPH přiznání DPHDP3 | https://adisspr.mfcr.cz/adis/jepo/schema/dphdp3_epo2.xsd |
| `dphkh1.xsd` | Kontrolní hlášení DPHKH1 | https://adisspr.mfcr.cz/adis/jepo/schema/dphkh1_epo2.xsd |
| `dphshv.xsd` | Souhrnné hlášení DPHSHV | https://adisspr.mfcr.cz/adis/jepo/schema/dphshv_epo2.xsd |
| `dpfdp5.xsd` | Daň z příjmů FO DPFDP5 | https://adisspr.mfcr.cz/adis/jepo/schema/dpfdp5_epo2.xsd |
| `dppdp9.xsd` | Daň z příjmů PO DPPDP9 | https://adisspr.mfcr.cz/adis/jepo/schema/dppdp9_epo2.xsd |
| `dpzvd6.xsd` | Vyúčtování daně ze závislé činnosti DPZVD6 | https://adisspr.mfcr.cz/adis/jepo/schema/dpzvd6_epo2.xsd |
| `dpsvd2.xsd` | Vyúčtování daně vybírané srážkou DPSVD2 | https://adisspr.mfcr.cz/adis/jepo/schema/dpsvd2_epo2.xsd |
| `dpzmb1.xsd` | Žádost o poukázání měsíčního daňového bonusu DPZMB1 (§ 35d odst. 5) | https://adisspr.mfcr.cz/adis/jepo/schema/dpzmb1_epo2.xsd |
| `dpzdb1.xsd` | Žádost o poukázání doplatku na bonusu DPZDB1 (§ 35d odst. 9) | https://adisspr.mfcr.cz/adis/jepo/schema/dpzdb1_epo2.xsd |
| `dpshl1.xsd` | Oznámení o příjmech plynoucích do zahraničí DPSHL1 (§ 38da) | https://adisspr.mfcr.cz/adis/jepo/schema/dpshl1_epo2.xsd |
| `dpszd1.xsd` | Hlášení o srážce zajištění daně DPSZD1 (§ 38e) | https://adisspr.mfcr.cz/adis/jepo/schema/dpszd1_epo2.xsd |

> **Pozn.:** soubor zde **musí mít jméno bez `_epo2` suffixu** (např. `dphdp3.xsd`, ne
> `dphdp3_epo2.xsd`). XmlSchemaValidator hledá `storage/xsd/{form_code}.xsd`.

## Zdroj ČSSZ — přehled OSVČ (sociální pojištění)

Roční **Přehled o příjmech a výdajích OSVČ** se podává elektronicky (datová schránka /
ePortál ČSSZ) ve **vlastním XML** ČSSZ — jiné schéma i kanál než EPO finanční správy.
Slouží pro e-podání pojistného OSVČ (fáze DP v2). Namespace `http://schemas.cssz.cz/OSVC2025`.

📋 **Definice e-Podání OSVČ (pro vývojáře, sekce „Datová věta"):**
https://www.cssz.gov.cz/definice-e-podani-osvc

📥 **Přímé URL (ročník 2025 — dokument-ID se mění per ročník!):**

| Filename | Popis | URL |
|---|---|---|
| `osvc25.xsd` | Přehled OSVČ 2025 (XSD) | https://www.cssz.gov.cz/documents/20143/3201321/OSVC25.xsd/5d467add-4c11-0e56-4d54-d455b56c15c9 |
| — | Popis datové věty (PDF) | https://www.cssz.gov.cz/documents/20143/3201321/DV_OSVC25.pdf/cd3fe989-b5e3-1dcf-bfab-895d22f937ff |
| — | Vzorové XML (příklad věty) | https://www.cssz.gov.cz/documents/20143/3201321/OSVC25+vzor.xml/e2a3c68f-e8f1-f701-ede0-30dabb8c4b35 |

Stáhni přes `bash cmd/download-xsd.sh osvc25` nebo `cmd\download-xsd.cmd osvc25`.
Cílový soubor **musí** být `osvc25.xsd` (form_code = `osvc25`, který hledá XmlSchemaValidator).

> **⚠️ Nezaměňovat s PVPOJ.** ČSSZ má dvě různá schémata: **OSVC** = roční *přehled OSVČ*
> (příjmy a výdaje, tohle používáme), zatímco **PVPOJ** = měsíční *přehled o výši pojistného
> zaměstnavatele* (mzdy). Pro OSVČ přehled je správné jen `OSVC{YY}`.
>
> **Zdravotní pojišťovny (VZP/ZP):** nemají jednotné veřejné XSD jako ČSSZ — každá ZP má
> vlastní portál (VZP: Portál ZP). Roční přehled OSVČ pro ZP se zatím generuje jako **PDF
> pomůcka** (`InsuranceSummaryPdfRenderer`); XML se doplní, až bude k dispozici stabilní
> schéma konkrétní ZP.

## Zdroj ISDOC

📋 **Aktuální verze standardu (odkazy MV ČR):**
https://mv.gov.cz/isdoc/clanek/aktualni-verze.aspx

📥 **Přímé URL k XSD:**

| Filename | Standard | URL |
|---|---|---|
| `isdoc-invoice-6.0.2.xsd` | ISDOC 6.0.2 (faktura) | https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd |

> **Pozor — XSD vs. business rules:** schéma ISDOC 6.0.2 neobsahuje žádný `<xs:assert>`
> a `*Curr` elementy (cizoměnové částky) jsou `minOccurs="0"`. XSD validace tedy ověří
> jen strukturu, pořadí a typy — **ne** pravidla jako „doklad v cizí měně musí nést
> `LineExtensionAmountCurr`". `<UnitPrice>` je dle standardu vždy v `LocalCurrencyCode`
> (CZK); `*Curr` sourozenci nesou hodnoty v `ForeignCurrencyCode`.

## Bez schémat

Pokud zde nejsou XSD soubory, validation je **skip** — XML se generuje a archivuje
normálně, jen v `tax_submissions` table bude `validation_status = 'skipped'`.

## S nahranými schématy

`XmlSchemaValidator::validate()` automaticky najde schema podle `form_code` a:
- **passed** — XML je validní
- **failed** — XML porušuje schema (chyby v `validation_errors` JSON)

Validation errors se zobrazí v UI `/reports/submissions` u daného záznamu.

## Proč nejsou v repo?

- Licence MFČR (schémata jsou public, ale ne tříděno do public repo)
- Velikost (každé schema má 50-500 KB s dependencies)
- Verze se mění (typically per rok) — manual update by admin

## Update workflow

Když MFČR vydá novou verzi (typicky leden):
1. Stáhni nové XSD přes `bash cmd/download-xsd.sh` (Linux/macOS) nebo `cmd\download-xsd.cmd` (Windows)
2. Soubory se přepíšou v `api/xsd/`
3. Commit → push (každý ročník je samostatný commit, ať je v historii viditelný)
4. Spusť re-validation existing archived submissions přes UI `/reports/submissions`
