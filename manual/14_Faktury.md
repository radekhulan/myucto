# 14. Faktury - seznam a hromadné akce

> Návod, jak v seznamu vydaných faktur najít doklad, nastavit si seznam podle sebe
> a provést akci nad více fakturami najednou: vystavit, odeslat, označit za zaplacené,
> upomenout, zaúčtovat nebo exportovat do PDF. Pro každého, kdo fakturuje.

Editaci jedné faktury popisuje [15. Editor faktury](15_Faktura_editor.md), PDF a odeslání
e-mailem [16. Faktura PDF](16_Faktura_PDF.md).

## 14.1 Kdy to potřebujete

Kapitolu otevřete, když:

- potřebujete najít fakturu podle čísla, variabilního symbolu, klienta nebo popisu položky,
- zjišťujete, kdo vám dluží, nebo kdo dlužil k určitému dni,
- je začátek měsíce a chcete znovu vystavit pravidelné faktury (retainer),
- máte připravených víc konceptů a chcete je vystavit a odeslat najednou,
- chcete poslat upomínky všem, kdo jsou po splatnosti,
- účetní chce faktury zaúčtovat do deníku nebo získat jeden PDF soubor z více dokladů,
- máte po importu desítky řádků s nejistým místem plnění (OSS),
- si chcete seznam přizpůsobit (sloupce, barvy, hustota řádků).

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| 1. den měsíce | Znovu vystavit pravidelné faktury z minulého měsíce | `Prodej → Vydané faktury`, hromadná akce **Vystavit znovu**, viz [§ 14.4](#144-krok-za-krokem-hromadne-vystavit-a-odeslat-faktury-mesicni-retainer) |
| průběžně | Projít faktury po splatnosti a poslat upomínky | filtr **Po splatnosti**, viz [§ 14.5](#145-krok-za-krokem-hromadne-oznacit-za-zaplacene-nebo-upomenout) |
| před uzávěrkou | Zaúčtovat vystavené doklady do deníku | hromadná akce **Zaúčtovat**, viz [§ 14.6](#146-krok-za-krokem-hromadne-zauctovat-faktury) |
| podle potřeby | Získat jedno PDF z více faktur | hromadná akce **PDF export**, viz [§ 14.7](#147-krok-za-krokem-sloucene-pdf-z-vice-faktur) |
| po importu nebo migraci | Opravit režim OSS u více dokladů | hromadná akce **Nastavit OSS**, viz [§ 14.8](#148-krok-za-krokem-hromadne-nastaveni-oss) |

## 14.2 Než začnete

- **Faktury musí existovat.** Nové vytvoříte tlačítkem **Nová faktura** (postup v
  [kapitole 15](15_Faktura_editor.md)).
- **Zaúčtování** je dostupné jen firmám s podvojným účetnictvím. Tlačítko **Náhled** u
  zaúčtovaného dokladu a sloupce s předkontací vyžadují oprávnění ke čtení účetnictví.
- **Odeslání e-mailem** vyžaduje, aby měl klient vyplněný e-mail (jinak se faktura
  při hromadném odeslání přeskočí).
- **PDF export s podpisem** vyžaduje nastavený podpisový profil pro PDF faktur.

## 14.3 Krok za krokem: najít fakturu v seznamu

1. Otevřete `Prodej → Vydané faktury`.
2. Do pole **Hledat** napište část čísla faktury, variabilního symbolu, čísla objednávky,
   jména klienta nebo popisu položky (viz [§ 14.11.9](#14119-vyhledavani)).
3. Chcete-li výsledek zúžit, použijte filtry: stav, typ, klient, zakázka, měna, období
   nebo **Kategorie tržby** (viz [§ 14.11.4](#14114-filtry)).
4. Kliknutím na záhlaví sloupce seřadíte celý výsledek.
5. Kliknutím na číslo faktury otevřete [detail faktury](16_Faktura_PDF.md). Kliknutím na
   ikonu PDF u řádku stáhnete PDF přímo, bez otevírání detailu.

**Jak poznáte, že je hotovo:** faktura je v seznamu a barevný štítek ve sloupci **Stav**
říká, v jakém je stavu (význam stavů viz [§ 14.11.5](#14115-stavy-faktur)).

> [!TIP]
> Chcete-li rychle zjistit, kdo dluží, zapněte filtr **Po splatnosti**. Po kliknutí na
> řádek máte hned po ruce tlačítko **Odeslat upomínku**.

### 14.3.1 Zjistit, kdo dlužil k určitému dni

1. V panelu filtrů vyplňte datum do pole **Neuhrazené k datu**.
2. Seznam ukáže doklady vystavené do tohoto dne, u kterých k němu nebyla uhrazena celá
   částka.
3. Filtr zrušíte volbou **Zrušit filtr na datum**.

**Jak poznáte, že je hotovo:** v seznamu jsou i doklady, které jsou dnes zaplacené, ale k
vybranému dni ještě uhrazené nebyly. Výklad je v [§ 14.11.4](#14114-filtry).

## 14.4 Krok za krokem: hromadně vystavit a odeslat faktury (měsíční retainer)

Typický začátek měsíce:

1. Otevřete `Prodej → Vydané faktury` a nastavte filtr **Období** na minulý měsíc.
2. Zaškrtněte všechny retainerové faktury (políčko v záhlaví tabulky označí zobrazené
   doklady měsíce).
3. V liště nahoře klikněte na **Vystavit znovu (N)** a potvrďte. Vzniknou koncepty s
   posunutými měsíci v popiscích položek (`Konzultace 3/2026` se změní na `Konzultace 4/2026`).
4. Projděte koncepty a podle potřeby je upravte (hodiny navíc, sleva).
5. Koncepty znovu označte a klikněte na **Vystavit (N)**. Každý dostane číslo podle šablony
   číslování a vygeneruje se PDF.
6. Vystavené faktury označte a klikněte na **Odeslat klientovi (N)**.

**Jak poznáte, že je hotovo:** faktury mají ve sloupci **Stav** hodnotu **Odesláno**.

> [!WARNING]
> **Vystavit znovu** vždy vytvoří nové koncepty. Samo nic nevystaví. Před hromadným
> vystavením a odesláním koncepty zkontrolujte, protože se klientům odešlou všechny
> najednou i s případnou chybou (špatná částka, chybějící popis).

## 14.5 Krok za krokem: hromadně označit za zaplacené nebo upomenout

**Označit za zaplacené:**

1. V seznamu zaškrtněte vystavené, odeslané nebo upomínkované faktury.
2. Klikněte na **Označit za zaplacené (N)** a potvrďte. Použije se dnešní datum.

**Odeslat upomínky:**

1. Zapněte filtr **Po splatnosti** a zaškrtněte faktury, které chcete upomenout.
2. Klikněte na **Odeslat upomínky (N)** a potvrďte.

**Jak poznáte, že je hotovo:** u zaplacených faktur je stav **Zaplaceno**, u upomenutých
**Upomínka** a u řádku je poznámka, kolikrát a kdy byly upomenuty.

> [!TIP]
> Označení za zaplacené je ruční záloha. Faktury se primárně označují zaplacenými
> automaticky při importu bankovního výpisu (viz [29. Banka](29_Banka.md)), u hotovosti
> volbou způsobu úhrady **Hotově** s pokladnou přímo v editoru
> ([§ 15.9.2](15_Faktura_editor.md#1592-hlavicka), část Způsob úhrady a platba hotově). Částečné platby
> popisuje [§ 16.10.2](16_Faktura_PDF.md#16102-platby-a-castecne-uhrady).

## 14.6 Krok za krokem: hromadně zaúčtovat faktury

Jen pro podvojné účetnictví.

1. V seznamu zaškrtněte vystavené a dosud nezaúčtované faktury. K výběru pomůže filtr
   **Zaúčtování** s hodnotou **Nezaúčtováno**.
2. Klikněte na **Zaúčtovat (N)** a potvrďte.
3. Aplikace zaúčtuje doklady jeden po druhém. Chyba jedné faktury neblokuje ostatní.
   Na konci uvidíte souhrn, kolik se zaúčtovalo a kolik skončilo chybou.

**Jak poznáte, že je hotovo:** u dokladů je štítek zaúčtováno a filtr **Zaúčtování** s
hodnotou **Nezaúčtováno** je prázdný. Na dávku je limit 500 dokladů. Podrobnosti o
zaúčtování viz [§ 16.10.3](16_Faktura_PDF.md#16103-zauctovani-do-deniku).

## 14.7 Krok za krokem: sloučené PDF z více faktur

1. V seznamu zaškrtněte vystavené faktury nebo dobropisy (nejvýše 100 najednou).
2. Klikněte na **PDF export (N)**.
3. Chcete-li výsledek elektronicky podepsat, zaškrtněte **Elektronicky podepsat výsledné PDF**.
4. Klikněte na **Stáhnout PDF**.

**Jak poznáte, že je hotovo:** aplikace ohlásí, že je PDF export připraven, a soubor se
stáhne. Doklady jsou v pořadí, v jakém jsou v seznamu.

Sloučené PDF obsahuje pouze samotné faktury. Nepřidává uživatelské přílohy, soubory ISDOC
ani výkazy víceprací. Pro archiv jednotlivých souborů (PDF ZIP, ISDOC ZIP, Pohoda XML)
použijte `Prodej → Export` ([kapitola 20](20_Exporty.md)).

## 14.8 Krok za krokem: hromadné nastavení OSS

Po migraci nebo po importu zůstanou desítky až stovky řádků, u kterých je potřeba doplnit
nebo opravit údaje k [režimu OSS](45_OSS.md). Proklikat je po jednom není reálné, proto
má seznam hromadnou akci **Nastavit OSS (N)**.

1. V seznamu nastavte filtr **Místo plnění (OSS)** (viz [§ 14.11.4](#14114-filtry)) a
   zaškrtněte doklady.
2. Klikněte na **Nastavit OSS (N)**.
3. V dialogu **Hromadné nastavení OSS** vyplňte pole podle tabulky níže. Každé pole má
   volbu **- ponechat -**, takže lze změnit třeba jen typ plnění.
4. Klikněte na **Zobrazit náhled**. Náhled vypíše, kolik dokladů a položek se změní, u
   každého dokladu konkrétní změnu z původní hodnoty na novou, varování ze sazebníku a
   doklady, které se přeskočí, i s důvodem.
5. Souhlasíte-li, klikněte na **Provést změnu**.

<!-- cols: 30 70 -->
| Pole | Význam |
|---|---|
| **Které položky** | Jen řádky k ručnímu posouzení / jen OSS řádky bez typu sazby / všechny OSS řádky / všechny položky dokladu |
| **Režim OSS** | Zapnout OSS / Vypnout OSS (plnění je tuzemské) / Ponechat beze změny |
| **Země spotřeby** | Členský stát, do kterého plnění patří |
| **Typ sazby** | Základní / Snížená / Druhá snížená / Parkovací |
| **Typ plnění** | Zboží / Služby |
| **Označit řádky jako posouzené** | Zhasne příznak „místo plnění k ručnímu posouzení“ |

**Jak poznáte, že je hotovo:** aplikace ohlásí, u kolika dokladů je OSS nastaveno. Byly-li
některé doklady přeskočeny, ohlásí i jejich počet a důvody najdete v náhledu.

> [!WARNING]
> Náhled je povinný, bez něj změnu provést nelze. Akce nemá „provést i tak“. Na dávku je
> limit 200 dokladů.

Pravidla přeskočení a chování dávky při chybě jsou v [§ 14.11.7](#14117-hromadne-akce-prehled).

## 14.9 Krok za krokem: přizpůsobit seznam

1. **Pořadí sloupců.** Přetáhněte záhlaví sloupce myší za tečkovanou ikonu. Barevná čára
   ukáže, kam se sloupec přesune. Pořadí se ukládá do vašeho profilu pro tento seznam a
   platí ve všech vašich firmách.
2. **Zobrazené sloupce.** Otevřete nabídku **Sloupce**, zapněte nebo vypněte sloupce nebo
   vyberte hotovou sestavu (**Výchozí klient**, **Výchozí účetní**, **Výchozí komplet**).
3. **Popisky hodnot.** V nabídce **Sloupce** přepněte **Zobrazovat popisky** na Ano nebo Ne.
4. **Hustota řádků.** Přepínačem **Hustota** zvolte kompaktní nebo komfortní zobrazení.
5. **Barvy.** Otevřete nabídku **Barvy položek** (ikona palety) a nastavte podklad buněk
   vybraných sloupců.
6. **Seskupení.** Přepínačem nad tabulkou zvolte **Po měsících** nebo **Souvislý seznam**.

**Jak poznáte, že je hotovo:** seznam vypadá podle vašich voleb. Volby se ukládají
automaticky a při příštím otevření zůstanou.

Návrat k původnímu stavu:

- **Obnovit pořadí sloupců** v nabídce **Sloupce** vrátí původní pořadí a zachová vybrané
  sloupce, barvy i filtry.
- **Obnovit výchozí barvy** vrátí všechny barvy najednou. Šipka u sloupce obnoví jen jeho
  barvu. Výběr sloupců, filtry a hustota se nemění.

## 14.10 Když něco nejde

<!-- cols: 32 34 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Žádná z vybraných faktur není koncept.“ | Akce **Vystavit** funguje jen na koncepty | Odškrtněte vystavené faktury, nebo použijte jinou akci |
| „Žádná z vybraných faktur není ve stavu vystaveno/odesláno/zaplaceno.“ | Hromadné odeslání klientovi vyžaduje vystavený doklad | Nejdřív koncepty vystavte |
| „Žádná z vybraných faktur není ve stavu vystaveno/odesláno.“ | Označit za zaplacené lze jen otevřené doklady | Zkontrolujte stav ve sloupci **Stav** |
| „Žádná z vybraných faktur není po splatnosti.“ | Upomínka se posílá jen po splatnosti | Zapněte filtr **Po splatnosti** a vyberte znovu |
| „Žádná z vybraných faktur není k zaúčtování (musí být vystavená a dosud nezaúčtovaná).“ | Zaúčtovat lze jen vystavené a dosud nezaúčtované doklady | Použijte filtr **Zaúčtování → Nezaúčtováno** |
| Klient nedostal e-mail | Klient nemá vyplněný e-mail, doklad se přeskočil | Doplňte e-mail u klienta a odešlete znovu |
| U **Nastavit OSS** je doklad přeskočen | Doklad je stornovaný, uzamčený, v podaném období nebo mimo platnost registrace | Důvod je uveden v náhledu, viz [§ 14.11.7](#14117-hromadne-akce-prehled) |
| „Hromadná změna se zastavila“ | Dávka OSS spadla na jednom dokladu | Nezpracované doklady zůstaly beze změny a jsou stále vybrané. Odstraňte příčinu a spusťte akci znovu jen nad nimi |
| „Data jsou zapsaná, ale uložené PDF je staré.“ | U některých dokladů se nepodařilo zneplatnit uložené PDF | Vygenerujte PDF znovu, než doklad odešlete |
| Faktura je ve výpisu, i když je dnes zaplacená | Filtr **Neuhrazené k datu** se ptá na historický stav | Je to správně, viz [§ 14.11.4](#14114-filtry) |

## 14.11 Podrobnosti a pravidla

### 14.11.1 Sloupce seznamu

![Seznam faktur](img/08_faktury_list.webp)

Menu: `Prodej → Vydané faktury`.

<!-- cols: 24 76 -->
| Sloupec | Význam |
|---|---|
| ☐ | Políčko pro hromadnou akci |
| Číslo | Variabilní symbol, například `2605001` (formát YYMMNNN) |
| Typ | 🟦 Faktura / 🟨 Zálohová / 🟥 Dobropis / ⚫ Storno / 🧾 Daňový doklad k platbě |
| Klient | Jméno klienta (klikatelné) |
| Vystaveno | Datum vystavení |
| Splatnost | Datum splatnosti, červeně pokud je po dni splatnosti a faktura není zaplacená |
| Částka | Celková částka v měně faktury |
| Stav | Barevný štítek, viz [§ 14.11.5](#14115-stavy-faktur) |
| Akce | PDF, Detail a další |

Šipka vlevo rozbalí položky faktury přímo v seznamu. U zaúčtovaného dokladu je před
popiskem **Popis** tlačítko **Náhled**, které otevře boční panel se shrnutím dokladu a
souvisejícími úhradami, stejný jako v účetním deníku. Tlačítko vyžaduje oprávnění ke čtení
účetnictví.

### 14.11.2 Řazení, seskupení a načítání

Seznam je standardně seskupený po měsících podle DUZP, u záloh podle data vystavení.
Přepínačem nad tabulkou (**Po měsících** / **Souvislý seznam**) lze zvolit souvislý seznam bez
měsíčních skupin. Volba se ukládá pro přihlášeného uživatele.

- Kliknutí na záhlaví sloupce seřadí celý filtrovaný výsledek před stránkováním. První
  kliknutí řadí sestupně, druhé vzestupně a třetí vrátí výchozí pořadí. Křížek v záhlaví
  tabulky (**Obnovit výchozí řazení**) vrátí výchozí řazení také.
- V měsíčním pohledu se při vzestupném řazení podle DUZP zobrazí nejstarší měsíce první.
  U ostatních sloupců zůstávají měsíce od nejnovějšího a řadí se doklady uvnitř nich.
- Oddíl KH se zobrazuje podle Knihy DPH a nemá řazení v seznamu. Stejně se neřadí sloupce
  **Členění DPH** a **Řádky přiznání DPH**.
- Seznam načítá 50 faktur v jedné dávce. Při posunu dolů se u konce seznamu automaticky
  načte další stránka.
- Zaškrtávací políčko v záhlaví tabulky označí pouze zobrazené doklady tohoto měsíce.
- V souvislém seznamu zůstává záhlaví při posunu viditelné a posuvník tabulky je po ruce na
  jejím spodním okraji. V měsíčním přehledu se záhlaví s obsahem posouvá běžně.

### 14.11.3 Doplňkové sloupce, sestavy a vzhled

Tlačítkem **Sloupce** lze zapnout doplňkové sloupce:

- **Var. symbol** (platební VS, který se tiskne na PDF a do QR platby a může se lišit od
  čísla faktury), **Objednávka**, **Zakázka**, **Odesláno dne**, **Uhrazeno dne**,
  **Uhrazeno celkem**, základ daně, DPH, celková částka a oddíl kontrolního hlášení.
- **Oddíl KH** se doplňuje z Knihy DPH až po zapnutí sloupce. Doklad bez zařazení do KH má
  prázdnou hodnotu.
- **Rozpad DPH** podle sazeb a **Předkontace MD / Dal** ze zaúčtování. Účty jsou uvedeny
  všechny: nejprve nákladové a výnosové, potom ostatní rozvahové a nakonec účty DPH 343.
- **Členění DPH** a **Řádky přiznání DPH** vycházejí z Knihy DPH.
- **Stát protistrany** vychází z adresy klienta.
- **Štítky příloh** ukazují štítky navázaných dokumentů, ke kterým má uživatel přístup.
- **Poznámka dokladu** obsahuje text nad a pod položkami.
- **Poznámky deníku** ze zaúčtování faktury může zapnout uživatel s oprávněním k účetnictví.
- Firma se zapnutými dimenzemi může přidat sloupec **Dimenze** s hodnotami z hlavičky a
  položek dokladu.

Podrobnosti se načítají až po zapnutí příslušného sloupce.

**Sestavy** v nabídce **Sloupce**: **Výchozí klient** (stručný seznam), **Výchozí účetní**
(DPH, předkontace a oddíl KH) a **Výchozí komplet** (všechny dostupné údaje včetně oddílu
KH). Po výběru sestavy lze sloupce dále jednotlivě upravit.

**Vzhled:**

- Šířka sloupců se přizpůsobuje obsahu a šířce okna nebo pracovního panelu. Nevejdou-li se
  údaje na jeden řádek, zobrazí se každý doklad jako přehledný blok s popisky hodnot.
  Hlavní údaje jsou nahoře a doplňující na jemném podkladu pod nimi. Sloupce v řádku jsou
  stejně široké.
- Přepínač **Zobrazovat popisky** (Ano / Ne) je v nabídce **Sloupce**. Bez vlastní volby
  jsou popisky vypnuté v jednořádkovém zobrazení a zapnuté při rozložení na více řádků.
  Volba se ukládá do profilu uživatele pro tento seznam a platí ve všech jeho firmách. Stejný
  přepínač mají i ostatní seznamy s nabídkou **Sloupce**.
- Sestava **Výchozí klient** zachovává na desktopu jeden řádek dokladu. Při vypnutých
  popiscích se záhlaví rozloží do stejných řádků a šířek jako hodnoty pod ním. Se zapnutými
  popisky zůstává záhlaví kompaktní.
- Přepínač **Hustota** mění výšku řádků i rozestupy víceřádkových bloků. Kompaktní režim
  zobrazí více dokladů, komfortní nechává více místa kolem hodnot. Volba se ukládá pro
  uživatele a tento seznam.
- Záhlaví slouží k řazení a přetahování sloupců. Na mobilu se stejné údaje zobrazují v
  kartách faktur.
- Barvy z nabídky **Barvy položek** se ukládají automaticky pro přihlášeného uživatele, zvlášť
  pro každý seznam, a platí ve všech jeho firmách. Písmo se automaticky přepne na černé nebo
  bílé podle kontrastu, takže zůstává čitelné ve světlém i tmavém režimu.

### 14.11.4 Filtry

<!-- cols: 28 72 -->
| Filtr | Hodnoty |
|---|---|
| Stav | Koncept / Vystaveno / Odesláno / Po splatnosti / Upomínka / Zaplaceno / Částečně uhrazeno / Přeplaceno / Storno |
| Typ | Faktura / Zálohová / Dobropis / Storno |
| Klient | Výběr ze všech klientů |
| Zakázka | Závisí na vybraném klientovi |
| Měna | CZK / EUR / ... |
| Období | Rok a měsíc (volba **Celý rok** vypne filtr na měsíc), případně vlastní rozsah |
| Neuhrazené k datu | Datum. Vypíše doklady vystavené do zvoleného dne, u kterých k tomu dni nebyla uhrazena celá částka |
| Kategorie tržby | Výběr několika kategorií najednou a přepínač **Zobrazit jen vybrané** / **Skrýt vybrané** |
| Zaúčtování | Vše / Zaúčtováno / Nezaúčtováno. Jen podvojné účetnictví, viz [§ 16.10.3](16_Faktura_PDF.md#16103-zauctovani-do-deniku) |
| Místo plnění (OSS) | Místo plnění (OSS): vše / Nejisté místo plnění (OSS) / Nejisté - v OSS podání / Nejisté - v tuzemsku. Vypíše doklady s řádkem, u kterého si systém není jistý místem plnění, viz [§ 40.5](40_Fakturujeme.md#40913-zahranicni-fakturace). Filtr je vidět, i když OSS zapnuté nemáte |
| Hledat | Volný text, viz [§ 14.11.9](#14119-vyhledavani) |

Filtr **Zaúčtování** jde do adresy (sdílitelný odkaz) a do [uložených
filtrů](96_Nastaveni.md#968-krok-za-krokem-ulozene-filtry-a-zobrazeni-tabulek). Promítne se i do CSV
exportu (řádky, ale ne samostatný sloupec, export neobsahuje příznak zaúčtování).

Filtr **Neuhrazené k datu** je jiná otázka než filtr **Nezaplacené**. Ten se dívá na
**dnešní** stav dokladu, kdežto **Neuhrazené k datu** na stav **k historickému dni** (třeba
„kdo mi k 30. 6. dlužil“). Doklad zaplacený až po tomto dni se proto ve výpisu objeví
(k danému dni ještě nebyl uhrazen), i když má dnes stav **Zaplaceno**. Filtr používá stejnou
definici úhrady jako [Saldokonto](60_Saldokonto.md), takže si obě sestavy neodporují.

#### Kategorie tržby

Filtr bere **několik kategorií najednou** a přepínačem vedle něj rozhodnete, co s nimi:

<!-- cols: 34 66 -->
| Režim | Co vypíše |
|---|---|
| **Zobrazit jen vybrané** | Pouze doklady s některou z vybraných kategorií |
| **Skrýt vybrané** | Všechno ostatní, typicky „ukaž mi velké faktury bez drobných za předplatné“ |

V nabídce je i volba **Bez kategorie** pro doklady, které kategorii vyplněnou nemají. Ta je
důležitá u režimu **Skrýt vybrané**: doklady bez kategorie zůstávají ve výpisu, dokud mezi
skrytými nezaškrtnete právě **Bez kategorie**. V seznamu jsou i archivované kategorie, visí
na starých fakturách, takže bez nich by je nešlo dohledat ani skrýt.

Filtr se zapisuje do adresy i do [uložených
filtrů](96_Nastaveni.md#968-krok-za-krokem-ulozene-filtry-a-zobrazeni-tabulek) včetně režimu, takže si
pohled „prodej bez předplatného“ uložíte a příště vyvoláte jedním klikem.

#### Nejisté místo plnění (OSS)

Sporné řádky končí na **dvou různých místech** a každé se řeší jinou otázkou. Proto má
filtr tři hodnoty, ne zaškrtávátko:

<!-- cols: 38 62 -->
| Volba | Co vypíše |
|---|---|
| **Nejisté místo plnění (OSS)** | Obojí najednou, rozlišíte je podle štítku u variabilního symbolu |
| **Nejisté - v OSS podání** | Řádek se do OSS zařadil, ale s otazníkem |
| **Nejisté - v tuzemsku** | Řádek zůstal mimo OSS a vstupuje do přiznání k DPH na ř. 1 a 2 |

U dokladu v seznamu je vidět, který otazník nese: štítek **OSS ?** u variabilního symbolu
značí řádek v OSS podání, štítek **ČR ?** řádek v tuzemsku. Doklad rozpadlý mezi obojí nese
oba štítky. Souhrnná volba **Nejisté místo plnění (OSS)** zobrazí oba dílčí stavy najednou,
takže se žádný nejistý doklad neschová. Filtr se zapisuje do adresy i do uložených filtrů.

Jak oba stavy vznikají a co s každým z nich dělat, popisuje
[§ 45.5](45_OSS.md#45109-plneni-k-rucnimu-posouzeni).

### 14.11.5 Stavy faktur

<!-- cols: 24 40 36 -->
| Stav | Význam | Co lze udělat |
|---|---|---|
| 📝 **Koncept** | Rozpracovaná, neviditelná pro klienta | Editovat, smazat, vystavit |
| ✅ **Vystaveno** | Číslo přiděleno, PDF je neměnné, ale klientovi nebyla odeslána | Odeslat klientovi, zaplatit, upomínka, dobropis, storno |
| 📧 **Odesláno** | E-mail s PDF odešel klientovi | Zaplatit, upomínka |
| ⏰ **Upomínka** | Upomínkový e-mail odešel | Zaplatit, další upomínka, dobropis |
| 💰 **Zaplaceno** | Platba přišla a byla spárována | (konečný stav) |
| 🟠 **Částečně uhrazeno** | Přišla jen část peněz (evidence plateb), zbytek je dál pohledávka | Doplatit, částečná úhrada, upomínka |
| 🟣 **Přeplaceno** | Evidované platby převyšují částku k úhradě | Řeší se ručně (vratka nebo dobropis) |
| ⚫ **Storno** | Interní storno, faktura ztratila platnost | **Zrušit storno** (viz [kapitola 16](16_Faktura_PDF.md)), smazat (správce) |

> [!TIP]
> **Dobropis** není stav, ale typ dokladu (opravný daňový doklad vystavený k původní
> faktuře). Původní faktura si svůj stav ponechává.

Pokud má koncept už evidované úhrady, při vystavení se přepočte jeho platební stav.
Plně zaplacená faktura přejde do stavu **Zaplaceno** s datem poslední evidované úhrady;
částečná úhrada zůstane zohledněná v doplatku. Samotná úhrada koncept nevystaví.

> [!WARNING]
> Upravujte jen koncepty. Vystavená faktura má neměnný snapshot dodavatele, klienta a banky.
> Pro změnu je potřeba storno a nová faktura, nebo dobropis. Správce (a role s právem
> **Upravit vystavenou fakturu**) má v krajní nouzi možnost upravit vystavenou fakturu
> (akce **Upravit vystavenou**), zapíše se do auditního logu.
>
> Role s tímto právem, která není správcem, má užší hranice:
> - částky, DPH a položky zaúčtované faktury změní jen s právem **Zaúčtovat doklad**,
>   protože úprava přeúčtuje deník,
> - v období, za které je už podané přiznání k DPH, kontrolní hlášení nebo u OSS dokladu
>   OSS přiznání, nezmění částky, data ani položky vůbec; opravu podaného období řešte
>   opravným nebo dodatečným tvrzením,
> - uzavřené účetní období přebíjí jen správce.
>
> Změna **typu** vystaveného dokladu (třeba zálohová faktura na fakturu) doklad
> přečísluje: každý typ má vlastní číselnou řadu, staré číslo se vrátí do původní řady,
> pokud bylo poslední, a doklad dostane další číslo v řadě nového typu. Zálohovou fakturu,
> ke které už existuje finální faktura nebo daňový doklad k platbě, takto překlopit nejde.

### 14.11.6 Hranice „po splatnosti“

Ve výchozím nastavení je faktura ve splatnosti po celý den uvedený jako datum splatnosti.
Mezi doklady **po splatnosti** patří až následující kalendářní den, pokud zůstává
neuhrazená. Teprve tehdy se také nabízí běžná upomínka. Rozhoduje datum v časové zóně
aplikace (Europe/Prague), nikoli časové pásmo prohlížeče. Stejná hranice platí pro filtr
přijatých faktur, dashboard včetně výzvy „Pošli upomínky“ a souhrny klientů a zakázek.

Provozovatel může v `cfg.local.php` zapnout zahrnutí dnešních dokladů do označení a filtrů
„po splatnosti“ v seznamech vystavených a přijatých faktur a do souhrnů dashboardu, klientů
a zakázek:

```php
return [
    'invoices' => [
        'overdue_includes_today' => true,
    ],
];
```

Jde o položku instalační konfigurace. Pokud už `cfg.local.php` obsahuje jiné volby, doplňte
ji do existujícího pole. Alternativou je proměnná prostředí
`MYINVOICE_OVERDUE_INCLUDES_TODAY=true`. Hodnota `false` vrací výchozí hranici a chybějící
příznak znamená `false`. Po změně znovu načtěte aplikaci. Nastavení platí pro všechny firmy
v instalaci. Nemění skutečný počet dnů prodlení ani pravidla upomínek: běžnou upomínku lze
nabídnout a odeslat až následující den. Veřejný náhled faktury a pásma stáří pohledávek
nadále pracují se skutečným prodlením.

### 14.11.7 Hromadné akce - přehled

Po zaškrtnutí více faktur se nahoře objeví lišta s akcemi:

<!-- cols: 24 46 30 -->
| Akce | Funkce | Aplikuje se na |
|---|---|---|
| **Vystavit (N)** | Hromadně vystaví vybrané koncepty. Každý dostane variabilní symbol podle šablony číslování, akce je nevratná | Koncepty |
| **Vystavit znovu (N)** | Vytvoří klony jako nové koncepty s posunutým měsícem v popiscích položek (`3/2026 → 4/2026`). Žádný se nevystaví ani neodešle automaticky | Faktury libovolného stavu |
| **Odeslat klientovi (N)** | Hromadně odešle e-mail s PDF přílohou na hlavní adresu klienta a fakturační e-maily zakázky | Vystavené, neodeslané |
| **Označit za zaplacené (N)** | Ručně označí jako zaplacené dnešním datem | Vystavené / odeslané / upomínkované |
| **Odeslat upomínky (N)** | Pošle upomínkový e-mail (hromadná akce neuplatňuje ochrannou lhůtu, ta platí jen u automatiky, viz [22. Upomínky](22_Upominky.md)) | Po splatnosti, nezaplacené |
| **PDF export (N)** | Sloučí PDF vybraných vystavených dokladů do jednoho souboru, volitelně jej elektronicky podepíše nastaveným profilem | Vystavené faktury a dobropisy, maximálně 100 dokladů |
| **Zaúčtovat (N)** | Zaúčtuje vybrané do deníku jednu po druhé (chyba jedné neblokuje ostatní), na konci souhrn. Max 500 dokladů na dávku | Vystavené a dosud nezaúčtované, jen podvojné účetnictví, viz [§ 16.10.3](16_Faktura_PDF.md#16103-zauctovani-do-deniku) |
| **Nastavit OSS (N)** | Hromadně nastaví režim OSS, zemi spotřeby, typ sazby a typ plnění na položkách. Náhled je povinný. Max 200 dokladů na dávku | Doklady, které nejsou stornované, zamčené ani v podaném období |

Při hromadném odeslání můžete být dotázáni, zda se má poslat i poděkování za úhradu u již
zaplacených faktur.

#### Hromadné nastavení OSS: pravidla

Příznak OSS rozhoduje, jestli řádek jde do českého přiznání, nebo do OSS podání. Doklad,
který je stornovaný, uzamčený, pod retenčním holdem, v podaném období nebo mimo platnost
registrace, se proto celý přeskočí s uvedeným důvodem. Stejně přísně je hlídané **vypnutí
OSS** jako jeho zapnutí: zhasnutí příznaku přesouvá daň na ř. 1 českého přiznání, takže
projde jen tam, kde číselník sazeb států OSS sazbu v zemi dodavatele potvrdí.

Úplný seznam důvodů přeskočení, pravidla pro vypnutí OSS a chování dávky při chybě popisuje
[§ 45.6](45_OSS.md#456-krok-za-krokem-hromadna-uprava-oss).

### 14.11.8 Ikony stavu (legenda)

V horní liště nad seznamem jsou ikony, kliknutím se přepne filtr na daný stav:

- 🟢 počet zaplacených tento měsíc,
- 🟣 počet odeslaných (čekajících na platbu),
- 🟡 počet vystavených (neodeslaných),
- 🔴 počet po splatnosti,
- 🟠 počet upomínkovaných.

### 14.11.9 Vyhledávání

Pole **Hledat** hledá v:

- čísle faktury (začátek čísla),
- platebním variabilním symbolu, i když není zadaný zvlášť a odvozuje se z číslic čísla
  faktury (`20260001` najde fakturu `2026-0001`),
- čísle objednávky (kdekoli v textu),
- jménu klienta,
- popisu položek.

Číslo faktury, variabilní symbol i číslo objednávky hledá také společné hledání ve spodní
liště.

### 14.11.10 Pravidlo účtování z detailu faktury

V detailu faktury lze z rozbalovací nabídky akcí zvolit **Vytvořit pravidlo účtování**.
Otevře se původní formulář pravidla s předvyplněným názvem, směrem, měnou a variabilním
symbolem. Obsahuje rozsah částky od/do, prioritu, strop automatiky, účty MD/Dal a test na
historii. Pravidlo slouží pro bankovní pohyby. Párování úhrad faktur nemění ani nevytváří
další účetní zápis faktury. Pravidla podle dodavatele a textu položky se vytvářejí
nákladovou šablonou v detailu [přijaté faktury](23_Prijate_faktury.md).

### 14.11.11 Tipy

- Nepoužívejte hromadné odesílání bez kontroly. Drobné chyby v konceptech (špatná částka,
  chybějící popis) se odešlou všem klientům najednou.
- Filtr **Po splatnosti** je nejrychlejší způsob, jak zjistit, kdo dluží.
- Kliknutí na číslo faktury otevře [Detail faktury](16_Faktura_PDF.md). Kliknutí na ikonu
  PDF stáhne přímo PDF.

## 14.12 Související kapitoly

- [15. Editor faktury](15_Faktura_editor.md) - vytvoření a úprava faktury.
- [16. Faktura PDF](16_Faktura_PDF.md) - detail, PDF, odeslání, úhrady a zaúčtování.
- [17. Pravidelná fakturace](17_Pravidelne_fakturace.md) - automatické vystavování opakovaných faktur.
- [20. Exporty](20_Exporty.md) - archiv PDF, ISDOC a Pohoda XML.
- [22. Upomínky](22_Upominky.md) - automatické a ruční upomínky.
- [45. OSS](45_OSS.md) - režim OSS a ruční posouzení místa plnění.
