# 24. Export přijatých faktur (naše PDF / ISDOC / Pohoda)

> Návod, jak předat přijaté faktury účetní nebo do jiného účetního programu:
> jednu fakturu z jejího detailu, nebo všechny za měsíc či čtvrtletí najednou.
> Pro každého, kdo přijaté doklady archivuje nebo posílá externí účetní.

## 24.1 Kdy to potřebujete

- Externí účetní chce na konci měsíce všechny přijaté faktury, nejlépe jako PDF.
- Účetní pracuje v Pohodě, Money S3, iDokladu nebo jiném programu a potřebuje
  doklady ve strukturované podobě (ISDOC nebo Pohoda XML).
- Potřebujete tabulkový přehled přijatých dokladů za období do Excelu.
- U faktury nemáte originální PDF dodavatele (doklad jste zadali ručně nebo
  přišel jen z importu) a potřebujete čitelnou kopii pro archiv.

| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po uzávěrce měsíce | Stáhnout všechny přijaté faktury za měsíc nebo čtvrtletí | `Nákup → Export`, postup v [§ 24.3](#243-krok-za-krokem-hromadny-export-za-obdobi) |
| u jednoho dokladu | Stáhnout jednu fakturu jako PDF, ISDOC nebo Pohoda XML | detail přijaté faktury, nabídka **…**, postup v [§ 24.4](#244-krok-za-krokem-export-jedne-faktury) |

## 24.2 Než začnete

- Faktury musíte mít v MyÚčtu zadané (viz [Přijaté faktury](23_Prijate_faktury.md)).
  Do exportu se dostanou doklady, jejichž zvolené datum spadá do vybraného období.
- Pro PDF ZIP je nejlepší, když mají faktury nahrané originální PDF dodavatele.
  Chybějící originál nahradí naše rekonstrukce z dat faktury.

## 24.3 Krok za krokem: hromadný export za období

1. Otevřete `Nákup → Export` (stránka **Export přijatých**).
2. V části **Formát** vyberte jednu z voleb: **PDF ZIP**, **ISDOC**, **Pohoda XML** nebo **CSV tabulka**.
3. V části **Období** přepněte mezi **Měsíc** a **Čtvrtletí**. U měsíce zvolte měsíc,
   u čtvrtletí zvolte **Čtvrtletí** (Q1 až Q4) a **Rok**. Předvyplněný je poslední uplynulý měsíc.
4. V poli **Filtrovat podle** zvolte, podle kterého data se faktura zařadí do období:
   **DUZP**, **Datum vystavení** (výchozí) nebo **Přijato dne**.
5. Klikněte na **Stáhnout**.

**Jak poznáte, že je hotovo:** prohlížeč stáhne soubor (ZIP, XML nebo CSV) pojmenovaný podle období.

> [!TIP]
> Po uzávěrce měsíce držte výchozí **Datum vystavení**. Pro podklady k DPH
> zvolte **DUZP**, pro kontrolu, kdy faktura dorazila, **Přijato dne**.

Co které volby udělají:

- **PDF ZIP** - archiv s PDF faktur. Použije se originál od dodavatele. Pokud chybí,
  doplní se naše rekonstrukce z dat faktury a její soubor je označený příponou
  `-rekonstrukce`, aby ji účetní poznala. Faktura se přeskočí jen tehdy, když selže i rekonstrukce.
- **ISDOC** - ZIP s jedním souborem ISDOC za každou fakturu.
- **Pohoda XML** - jeden společný soubor se všemi fakturami za období, určený k přímému importu do Pohody.
- **CSV tabulka** - jeden soubor s přehledem přijatých faktur za období, vhodný pro Excel (UTF-8).

## 24.4 Krok za krokem: export jedné faktury

1. Otevřete `Nákup → Přijaté faktury` a klikněte na fakturu.
2. V liště akcí otevřete nabídku **…** a zvolte **Naše PDF (rekonstrukce)**, **ISDOC XML** nebo **Pohoda XML**.
3. Soubor se stáhne.

**Jak poznáte, že je hotovo:** prohlížeč stáhne PDF nebo XML soubor s číslem faktury.

## 24.5 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| V ZIPu chybí některé faktury | Faktura nespadá do období podle zvoleného data | V poli **Filtrovat podle** zkuste jiné datum, například **Přijato dne** |
| Soubor PDF má příponu `-rekonstrukce` | Faktura nemá nahraný originál dodavatele | Nahrajte originální PDF k faktuře, nebo předejte rekonstrukci |
| Export je prázdný | Žádný doklad nemá zvolené datum ve vybraném období | Změňte období nebo pole **Filtrovat podle** |

## 24.6 Podrobnosti a pravidla

### 24.6.1 Naše PDF (rekonstrukce)

Naše PDF vzniká ze strukturovaných dat faktury. Je užitečné, když se importovala
jen metadata (z iDokladu nebo Fakturoidu přes API, ne originální PDF), když
originál není dostupný (ručně zadaná přijatá faktura) nebo když potřebujete
čitelný PDF pro účetní archiv. PDF obsahuje hlavičku s dodavatelem, položky,
součty a poznámky. V patičce je poznámka: *„Naše rekonstrukce přijaté faktury
z dat v MyÚčto.cz. Originál od dodavatele je referenční dokument."*

### 24.6.2 ISDOC XML

Export je ve standardu ISDOC 6.0.2, kompatibilním s Pohodou, Money S3, iDokladem
a dalšími. Používá se **inverze rolí**: v ISDOC přijaté faktury je *dodavatel*
původní vendor a *zákazník* je vaše firma (opak vystavené faktury).
Platební údaje obsahují evidovaný bankovní účet dodavatele a variabilní symbol.
Pokud variabilní symbol není vyplněn samostatně, odvodí se z čísla faktury dodavatele.

Číslo dokladu dodavatele je v kořenovém elementu `<ID>`. Interní číslo přijaté
faktury v MyÚčtu (například `PF2607002`) zůstává oddělené v
`<Extensions><myi:InternalDocumentNumber>` s namespace
`https://myinvoice.cz/isdoc/extensions/2026`.

### 24.6.3 Pohoda XML

Pohoda dataPack XML pro import do účetního programu Pohoda. Směr dokladu je
přijatý: element `<inv:invoice>` obsahuje typ přijatého dokladu
v `invoiceType` (`received*`). V Pohodě soubor otevřete přes
`Soubor → Datová komunikace → XML import`.

### 24.6.4 Pravidla hromadného exportu

- Období je jeden měsíc nebo celé čtvrtletí (Q1 až Q4).
- Pole **Filtrovat podle** určuje, které datum faktury rozhoduje o zařazení do
  období: DUZP, datum vystavení (výchozí) nebo datum přijetí.
- U PDF ZIP aplikace preferuje archivovaný originál (`Prijata-{vs}-{vendor}.pdf`).
  Rekonstrukce má příponu `-rekonstrukce.pdf`.

## 24.7 Související kapitoly

- [Přijaté faktury](23_Prijate_faktury.md) - odkud se doklady berou.
- [Exporty](20_Exporty.md) - export vystavených faktur (PDF ZIP, ISDOC, Pohoda).
- [Hromadný export](48_Hromadny_export.md) - kompletní měsíční balíček vystavených i přijatých faktur najednou v sekci Daně.
