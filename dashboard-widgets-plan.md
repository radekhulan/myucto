# Implementační plán společného registru widgetů pro / a /portal

## Stav a cíl

Společný registr, datová API, obě dashboardové plochy a editor jsou
implementované. Dokument zachycuje návrh i schválené chování včetně následných
úprav rozměrů a ovládání. Stav ověření je uvedený v oddílu 9.

Cílem je jeden registr widgetů, společná vykreslovací komponenta a dvě samostatně
nastavitelné plochy. Adresy `/` i `/portal` zůstanou zachované. Výchozí obchodní
část bude společná; `/` přidá interní widgety podle oprávnění.

Klient se všemi dostupnými klientskými právy dostane stejné obchodní widgety,
hodnoty a dostupné obchodní prokliky jako admin nad stejnou firmou, obdobím a měnou.
Osobní úpravy mohou jejich rozmístění následně změnit. Přístup k účetním,
daňovým, mzdovým a provozním funkcím se dál řídí příslušnými oprávněními
a stávajícími podmínkami aplikace.

## 1. Plochy a společná architektura

| Plocha | Výchozí účel |
|---|---|
| `/` | Pracovní přehled: obchodní údaje a podle práv také účetní úkoly, daně a provozní upozornění |
| `/portal` | Přehled firmy: obchodní výsledky, cashflow, doklady, orientační DPH a spolupráce s účetní |

Obě adresy používají stejné komponenty, datové služby, pravidla widgetů a editor.
Cesty `/` a `/portal` i stávající přesměrování klientů na `/portal` zůstávají
zachované. Klientský přístup na `/` se tím nerozšiřuje.

Podstránky `/portal/document-requests`, `/portal/purchase-invoice-submissions`,
`/portal/settings` a `/portal/mcp` nejsou součástí slučování.

| Část | Odpovědnost |
|---|---|
| Sdílený manifest | Identifikátory widgetů, překlady, podporované velikosti, nastavení a odkazy na pravidla přístupu |
| Výchozí sestavy | Verzované seznamy instancí, pořadí, šířek a výchozího nastavení |
| Backendový registr a policy | Vyhodnocení oprávnění a podmínek firmy, validace konfigurace |
| Datové služby | Výpočet povolených dat a sdílení agregací mezi widgety |
| Frontendový registr | Mapa identifikátorů na Vue komponenty |
| `DashboardBoard` | Vykreslení rozložení, načítání, prázdné a chybové stavy |
| `DashboardEditor` | Přidání, odebrání, pořadí, velikost a nastavení |
| `useDashboardBoard` | Načtení a uložení konfigurace, obnova dat a práce s aktuální firmou |

Navržené nové části:

- `web/shared/dashboard-widgets.json` — společný manifest typů widgetů.
- `web/shared/dashboard-presets.json` — výchozí sestavy.
- `web/src/dashboard/` — frontendový registr, typy a správa ploch.
- `web/src/components/dashboard/` — sdílený obal, editor a komponenty widgetů.
- `api/src/Service/Dashboard/` — backendový registr, datové služby a konfigurace.
- `api/src/Action/Dashboard/` — akce nového API.
- Pravidla oprávnění napojená na `RequestAuthorization`.

Manifest odkazuje na pojmenovaná pravidla přístupu. Jejich význam vyhodnotí
backend přes `RequestAuthorization`; frontend neudržuje druhou vlastní matici
oprávnění. Dostane katalog dostupných widgetů a schopnosti jednotlivých akcí.

Test ověří, že každý registrovaný widget má komponentu, datového poskytovatele,
platné pravidlo přístupu a podporované výchozí nastavení.

## 2. Registr a seznam widgetů

Každý typ widgetu definuje:

- stabilní identifikátor typu;
- i18n klíče názvu a popisu do nabídky „Přidat widget“;
- pojmenované pravidlo přístupu a podmínky firmy;
- minimální a maximální šířku, výchozí šířku a povolené nastavení;
- datové závislosti;
- zda lze vložit více instancí stejného typu;
- chování bez dat.

Manifest obsahuje 24 typů: 23 datových widgetů a technický typ
`layout.divider`. Identifikátor instance se liší od identifikátoru typu.
Například pět KPI karet používá stejný typ `business.kpi`, ale jiné instance
a období. Oddělovač není datový widget a mimo editor se nezobrazuje.

V tabulce znamená **R** čtení a **W** zápis. **Základ** znamená přístup k dané
ploše a aktuální firmě; zachovává dnešní dostupnost agregátů portálu.
Nedává přístup k jednotlivým dokladům nebo interním údajům.

| ID widgetu | Obsah | Oprávnění |
|---|---|---|
| `business.kpi` | Fakturováno, náklady a rozdíl za nastavené období; lze vložit vícekrát | Základ |
| `business.monthly` | Společný měsíční graf fakturace a nákladů | Základ |
| `sales.summary` | Počty vydaných faktur, neuhrazené, po splatnosti a průměrná úhrada | `invoices R` |
| `purchases.summary` | Počty přijatých faktur, neuhrazené a po splatnosti | `purchase_invoices R` |
| `receivables.aging` | Souhrnné pohledávky podle stáří | Základ |
| `payables.aging` | Souhrnné závazky podle stáří | Základ |
| `cashflow.weekly` | Čistý výhled příjmů a výdajů na čtyři týdny | Základ |
| `cashflow.collections` | Očekávané inkaso z faktur za 30/60/90 dní | `invoices R` |
| `receivables.due` | Faktury splatné dnes, tento týden a tento měsíc | `invoices R` |
| `invoices.overdue` | Konkrétní faktury po splatnosti | `invoices R` |
| `invoices.upcoming` | Konkrétní faktury před splatností | `invoices R` |
| `clients.top` | Top odběratelé; tabulka, graf nebo obojí, volitelné umístění tabulky vlevo/vpravo/nad/pod grafem | `invoices R` + `clients R` |
| `invoices.drafts` | Rozpracované faktury a výkazy práce | `invoices R`; změny podle práv a zámku dokladu |
| `invoices.approvals` | Vydané faktury čekající na schválení | `invoices.approval R` |
| `documents.requests` | Chybějící doklady; klientský nebo interní pohled | Klient: `documents.submit R`; interní: `documents.requests R` |
| `actions.quick` | Nová faktura, přijatý doklad a kontakt | Každá akce podle vlastního `*.create W` |
| `actions.todo` | Úkoly, které uživatel může řešit | Každá položka podle cílové agendy a akce |
| `vat.current` | Orientační aktuální DPH, vstup, výstup, povinnost a termín | Základ; plátce DPH |
| `tax.deadlines` | Stručné daňové termíny dnešního portálu | Základ |
| `tax.calendar` | Rozšířený kalendář se stavem podání a plateb | `reports R` |
| `tax.net` | Projektovaný čistý příjem OSVČ | `reports R` + odpovídající typ firmy |
| `tax.advances` | Nadcházející zálohy na daň a pojistné | `reports R` |
| `accounting.status` | Doúčtování, nezaúčtovaná banka a dnešní automatizace | Každá část podle cílové agendy; podvojné účetnictví |

### Pravidla akcí a podrobností

- `actions.quick` používá samostatně `invoices.create W`,
  `purchase_invoices.create W` a `clients.create W`.
  `dashboard.write` není podmínkou obchodních akcí.
- V `accounting.status` vyžaduje doúčtování `accounting.periods.manage W`;
  přehled bankovního zaúčtování a automatizace `accounting R`.
- V `actions.todo` vyžaduje výzva k párování `bank R` + `bank.match W`;
  spuštění opakované fakturace `recurring R` + `recurring.run W`.
  Ostatní položky dostanou stejně konkrétní pravidla podle skutečných cílových akcí.
- Současný kompletní CRM feed se klientovi plošně nezpřístupní. Obchodní
  a interní položky se vyhodnotí jednotlivě před načtením jejich dat.
- Jména protistran na fakturách jsou dostupná podle práva číst fakturu.
  Proklik na kontakt vyžaduje `clients R`; názvy zakázek a jejich odkazy
  `projects R`.
- Editace, upomínky, úhrady, odeslání a schvalování respektují vlastní oprávnění
  a stávající zámky konkrétních dokladů.
- Klientský pohled na chybějící doklady používá stávající klientskou policy;
  interní pohled vede na interní agendu požadavků.
- Samotná dostupnost metriky neotevírá stránky `/stats`, `/crm` ani účetní
  agendy. Nedostupný cíl se nenabízí jako odkaz.
- Klient nepotřebuje kvůli společným obchodním widgetům získat práva
  `dashboard` nebo `dashboard.portfolio`.

Název firmy, stav bez přiřazené firmy a kritická provozní upozornění zůstávají
součástí obalu stránky. Nejsou to odstranitelné widgety. Průvodce prvním
nastavením zachová vlastní současné ovládání.

## 3. Výchozí sestavy

Desktop používá dvanáctisloupcovou mřížku. Základní buňka má poměr stran 16:9
a výšku widgetu tvoří celočíselný počet řádků mřížky. Datový widget zabere
podporovaný počet sloupců a jeho výška se přizpůsobí obsahu nebo zvolenému
minimu. Mobilní zobrazení skládá widgety přirozeně do jednoho sloupce.
Manifest pro každý typ určuje minimální a maximální šířku; u datových widgetů
je možné měnit šířku po jednom sloupci až do 12. Oddělovač má pevnou šířku
12 sloupců. Následující tabulka uvádí minimum a výchozí hodnotu v aktuálním
manifestu:

| Typ | Minimum | Výchozí |
|---|---:|---:|
| `business.kpi` | 3 | 3 |
| `business.monthly` | 4 | 9 |
| `sales.summary` | 4 | 6 |
| `purchases.summary` | 4 | 6 |
| `receivables.aging` | 3 | 4 |
| `payables.aging` | 3 | 4 |
| `cashflow.weekly` | 4 | 4 |
| `cashflow.collections` | 4 | 6 |
| `receivables.due` | 4 | 6 |
| `invoices.overdue` | 4 | 6 |
| `invoices.upcoming` | 4 | 6 |
| `clients.top` | 6 | 12 |
| `invoices.drafts` | 4 | 8 |
| `invoices.approvals` | 3 | 4 |
| `documents.requests` | 3 | 4 |
| `actions.quick` | 3 | 4 |
| `actions.todo` | 3 | 4 |
| `vat.current` | 3 | 6 |
| `tax.deadlines` | 4 | 6 |
| `tax.calendar` | 4 | 6 |
| `tax.net` | 4 | 6 |
| `tax.advances` | 4 | 6 |
| `accounting.status` | 4 | 12 |
| `layout.divider` | 12 | 12 |

Týdenní cashflow se doporučuje používat od 6 sloupců, aby se tabulka pohodlně
četla. Výšku lze nastavit jako `auto`, `compact`,
`normal` nebo `tall`, pouze pokud ji daný widget podporuje. Výchozí výška všech
nových instancí i výchozích sestav je `auto` (Automatická); uložené ruční
volby se nemění.

`layout.divider` vykreslí v editoru samostatnou lištu přes celou šířku řádku;
mimo editor není vidět. Lištu lze přetáhnout i do prázdného místa některé skupiny.
Přesun do volného místa skupiny vloží oddělovač na konec cílové skupiny,
těsně před její následující oddělovač. Na desktopu další skupina začíná až pod nejvyšším
widgetem předchozí skupiny, takže rozložení nevytváří zuby kvůli nestejně
vysokým kartám. Oddělovač lze přesouvat a odstranit pouze v editoru.

### Společná obchodní sestava company-v1

Výchozí obchodní sestava obsahuje 23 datových widgetů a 9 oddělovačů
(32 položek celkem). První skupina obsahuje rychlé akce, úkoly a požadavky na
doklady; oddělovač následuje až za nimi. Sestava portálu `portal-v1` má shodný
obsah. Domovská sestava `home-v1` obsahuje 27 datových widgetů a 12 oddělovačů
(39 položek celkem), tedy navíc interní účetní a daňové widgety podle oprávnění.

### Doporučení pro šířky

Při šířce okna 1 920 px zabere otevřené menu přibližně 240 px a hlavní oblast
přehledu má zhruba 1 600 až 1 616 px. Orientační výsledné šířky při dvanácti
sloupcích a mezerách mřížky:

| Sloupce | Přibližná šířka |
|---:|---:|
| 3 | 388 px |
| 4 | 523 px |
| 6 | 792 px |
| 8 | 1 061 px |
| 9 | 1 196 px |
| 12 | 1 600 px |

Doporučené šířky jsou 4 sloupce pro rychlé akce a Akce pro tebe, 3 pro KPI,
4 pro malé souhrny a 6 pro běžné grafy či tabulky. Kombinace tabulky a grafu
potřebuje obvykle 8 nebo 12 sloupců. `actions.quick` a `actions.todo` mají
minimum 3, maximum 12 a pro nově přidané instance výchozí šířku 4.
Tyto změny výchozích voleb nemění již uložené osobní konfigurace.

Nepovolené nebo nepoužitelné widgety se vynechají a rozložení se přeskupí.
Jejich uložená konfigurace tím nezanikne.

Opakující se částky se při převodu současných komponent sjednotí. Například
roční obrat bude v KPI a přehled vydaných nebude zbytečně opakovat stejnou
velkou kartu. Měsíční graf nahradí duplicitní prezentaci stejného trendu
v několika místech.

### Sestavy jednotlivých ploch

| Sestava | Složení |
|---|---|
| `portal-v1` | Společná sestava `company-v1` |
| `home-v1` | Společná sestava `company-v1` a navíc `accounting.status`, `tax.calendar`, `tax.net` a `tax.advances` podle oprávnění |

Interní rozšíření je součástí pracovní plochy nad společnou obchodní sestavou.
Osobní konfigurace obou ploch je nezávislá.

## 4. Editor rozložení

Editor je společný pro obě plochy. V režimu **Upravit přehled** umožňuje:

- přidat widget z katalogu dostupného aktuálnímu uživateli a firmě;
- odebrat widget;
- změnit pořadí přetažením i tlačítky pro posun;
- zvolit podporovanou šířku a dostupnou výšku;
- upravit podporované nastavení;
- uložit změny, zrušit je nebo obnovit výchozí sestavu;
- najít widget v prohledávatelném katalogu a znovu přidat odebraný typ;
- přidat, přesunout nebo odebrat oddělovač skupin; jeho lišta zabírá celou
  šířku a volné místo ve skupině slouží jako cíl přesunu před následující oddělovač.

Změny se provádějí v pracovní kopii a uloží společným potvrzením. Zrušení
obnoví původní stav. Rozměry se mění jen v režimu editace. Rohový úchyt
umožňuje měnit šířku i výšku tažením nebo klávesami; rozměry se přichytí
k podporovaným velikostem. Při náhledu má widget plovoucí editační lištu
s úchytem pro přesun a ovládáním nastavení a odebrání; lišta leží mimo měřenou
výšku obsahu, aby náhled odpovídal uložené geometrii karty. Hlavička plochy
zůstává nad widgety; název firmy a tlačítka editoru sdílejí jeden řádek.

Svislá ryska uprostřed pravé hrany mění pouze šířku a vodorovná ryska
uprostřed spodní hrany pouze výšku; druhý rozměr vždy zachová. Rohový úchyt mění obě osy a zobrazí se
jen u widgetů s oběma nastavitelnými rozměry. Vodorovný úchyt reaguje na
šipky vlevo/vpravo, svislý na šipky nahoru/dolů.

Katalog má pružnou mřížku s minimální šířkou karty 240 px, mezerou 12 px
a nejvýše šesti sloupci. Rozhoduje šířka samotného katalogu. Na displeji
1920 × 1080 s bočním menu zobrazuje šest karet po přibližně 251 px; názvy
jsou v češtině i angličtině jednořádkové, popisy mají nejvýše dva řádky. Při 1536 px jsou čtyři sloupce. Vyhledávání pracuje s aktuálními
překlady názvů a popisů bez zkracování obsahu.

Editor i widgety použijí existující design tokeny, sémantická tlačítka s ikonami,
zalomitelné toolbary a české i anglické překlady.

## 5. Ukládání vlastní konfigurace

Využije se existující tabulka `user_preferences` s klíči:

- `dashboard.home`
- `dashboard.portal`

Konfigurace patří přihlášenému uživateli a ploše. První verze neukládá
samostatné rozložení pro každou firmu. Při přepnutí firmy se přepočítá dostupnost
widgetů a načtou její data.

### Formát

```json
{
  "schemaVersion": 1,
  "presetVersion": 1,
  "widgets": [
    {
      "id": "kpi-ytd",
      "type": "business.kpi",
      "span": 3,
      "height": "auto",
      "options": {
        "period": "ytd",
        "currency": "all"
      }
    },
    {
      "id": "monthly",
      "type": "business.monthly",
      "span": 9,
      "height": "normal",
      "options": {
        "months": 12,
        "currency": "auto"
      }
    }
  ]
}
```

Pořadí určuje pole `widgets`. Konfigurace může obsahovat nejvýše 64 instancí,
z toho 32 datových widgetů a 32 oddělovačů; její velikost je omezena na 16 KiB.
Obsahuje identifikátory, šířky, podporovanou výšku a nastavení; neobsahuje
částky, výsledky dotazů, oprávnění ani identifikátory konkrétních firem.

### Validace a souběh

- Známé typy widgetů a unikátní ID instancí.
- Nejvýše 32 datových widgetů a 32 oddělovačů, celkem nejvýše 64 položek.
- Pouze velikosti a nastavení povolené registrem.
- Nejvýše 16 KiB konfigurace a hloubka odpovídající typovanému schématu.
- Vlastní validátor konfigurace; současnou obecnou validaci preferencí
  nezmírňovat plošně.
- Identitu vlastníka určuje relace, nikdy `user_id` z požadavku.
- Uložení používá revizi a podmíněný zápis. Konflikt mezi okny nesmí tiše
  přepsat novější konfiguraci.
- Známý widget skrytý aktuálními právy zůstane uložený. Editor tento záznam
  zachová i při ukládání změn ostatních widgetů.

Úprava vlastní plochy nevyžaduje zápis do účetních dat. Dostupná bude i uživateli,
který smí data jen číst. Přístup k ukládání proto nebude navázaný na
`dashboard.write` nebo obecný `profile.write`.

## 6. Verzování, výchozí stav a migrace

| Stav | Výsledné chování |
|---|---|
| Preference neexistuje | Použije se aktuální výchozí sestava plochy filtrovaná podle práv a firmy |
| První vlastní uložení | Uloží se úplný upravený seznam instancí |
| Existuje vlastní sestava | Použije se vlastní konfigurace |
| Přibude nový widget | Nabídne se v katalogu, sám se nepřidá do vlastní sestavy |
| Uživatel ztratí právo nebo přepne firmu | Nedostupný widget se skryje, jeho konfigurace zůstane zachovaná |
| Obnovení výchozího stavu | Konfigurace rozložení se nastaví na `null` a použije se aktuální výchozí sestava; zůstane revizní tombstone, aby se neobnovila stará revize |
| Změní se formát konfigurace | Provede se explicitní převod podle `schemaVersion` |

`schemaVersion` řídí formát konfigurace. `presetVersion` označuje verzi výchozí
sestavy, ze které vlastní rozložení vzniklo. Změna výchozí sestavy nepřepisuje
osobní úpravy.

Výchozí sestavy se při nasazení nekopírují každému uživateli do databáze.
Pro tento návrh není potřeba nová SQL migrace. Pokud by se při implementaci
ukázala nutná změna schématu, musí následovat běžný postup repozitáře
s novou idempotentní migrací a aktualizací otisku struktury.

## 7. Nové API a oprávnění

| Endpoint | Účel |
|---|---|
| `GET /api/dashboard-widgets/catalog?board=portal` | Dostupné widgety, velikosti, nastavení a výchozí sestava |
| `POST /api/dashboard-widgets/query` | Hromadné načtení dat vybraných instancí; pouze čtení |
| `GET /api/user/dashboard-layouts/{board}` | Vlastní konfigurace, nebo informace o použití výchozí |
| `PUT /api/user/dashboard-layouts/{board}` | Validace a uložení vlastní konfigurace |
| `DELETE /api/user/dashboard-layouts/{board}` | Obnovení výchozí sestavy |

Povolené hodnoty `board` jsou `home` a `portal`. Backend ověří přístup k ploše,
aktuální firmě a každému požadovanému widgetu. Výběr widgetu v požadavku
nebo uložené konfiguraci není oprávněním k jeho datům.

Routy pro vlastní rozložení budou úzce vymezené pro přihlášenou webovou relaci
a vlastní konfiguraci. Obecný preferenční endpoint nebude tyto klíče umožňovat
přepisovat bez dashboardové validace.

Nové routy jsou session-only. Nerozšiřují veřejný subset ani oprávnění API
a MCP tokenů. Test musí ověřit také odmítnutí tokenů přes verzovaný prefix.

Stávající endpointy zachovají kontrakty:

- `/api/dashboard/summary` — používá jej také stránka Tržby a veřejné API.
- `/api/portal/summary` — zůstane čistě agregovaný včetně stávajícího invariantu
  bez jmen protistran a čísel dokladů.

Změna společné prezentace tedy neznamená plošné otevření starého dashboardového
nebo CRM API klientům. Dotčené veřejné kontrakty musí zůstat v souladu
s OpenAPI; případná jejich změna vyžaduje současnou aktualizaci dokumentace API.

## 8. Datové služby, výkon a obnova

Hromadný požadavek vyhodnotí práva znovu, seskupí závislosti a společné agregace
spočítá jednou. Pět KPI widgetů nevyvolá pět stejných sad dotazů.
Nepovolený widget se nespočítá ani nevrátí.

- Vyčlenit potřebné agregace do volatelných služeb, které mohou používat
  nové widgety i stávající endpointy.
- Nepočítat celý původní dashboard jen proto, aby se většina výsledku zahodila.
- Konkrétní seznamy omezit počtem řádků; celkové počty počítat nezávisle
  na tomto limitu a nabídnout povolený proklik do seznamu.
- Nepřidávat N+1 dotazy. Změněné dotazy nad rostoucími tabulkami ověřit
  přes `EXPLAIN` a respektovat indexy a omezení na firmu.
- Zachovat význam metrik: čtyřtýdenní čisté cashflow není totéž jako inkaso
  za 30/60/90 dní.
- Obchodní cashflow musí mít stejný význam pro obě role. Účetní, daňové
  a mzdové rozšíření musí zůstat samostatně rozlišené.
- Cache zahrne firmu, parametry a relevantní oprávnění; osobní úkoly také
  uživatele. Změna práv nesmí zpřístupnit dříve uloženou širší odpověď.
- Na frontendu použít `useWindowRefresh`, aktivaci panelu a změnu firmy.
  Opožděná odpověď předchozí firmy se zahodí.
- Nepřidávat globální sledování DOMu nebo průběžné přeměřování celé aplikace.

Při vyčleňování se zachovají současné výpočty a sdílené daňové služby.
Případná nalezená chyba ve sdílené účetní nebo daňové vrstvě patří do samostatné
opravy s regresním testem prokazatelně padajícím bez opravy, podle pravidel
repozitáře.

## 9. Etapy a navazující PR

### Etapa 1 — registr, výchozí sestavy a ukládání konfigurace

**Stav:** Dokončeno a ověřeno jednotkovými, integračními a HTTP testy.
Manifest, výchozí sestavy, validace, uživatelské preference, revize,
souběžný zápis i reset mají regresní pokrytí.

**Rozsah:**

- manifest widgetů, pojmenovaná pravidla dostupnosti a frontendová mapa komponent;
- výchozí sestavy `company-v1`, `portal-v1` a `home-v1`;
- typy konfigurace, backendový validátor a pravidla verzování;
- úzké API pro vlastní konfiguraci nad `user_preferences`;
- revize konfigurace, podmíněný zápis a reset.

**Podmínky dokončení:**

- každý widget má platnou definici, pravidlo a datové závislosti;
- každá instance výchozí sestavy projde validací;
- cizí konfiguraci nelze číst ani měnit;
- uživatel pouze pro čtení může upravit vlastní rozložení;
- neplatné ID, velikost, nastavení, duplicitní instance a překročení limitu
  vracejí validační chybu;
- reset a převody verzí mají testy.

### Etapa 2 — data widgetů a oprávnění

**Stav:** Implementováno. Cílené jednotkové, integrační a HTTP testy
ověřují shodu obchodních dat, izolaci, oprávnění i původní kontrakty.
Před odesláním změny se vyhodnotí také širší účetní a daňová auditní brána.

**Rozsah:**

- vyčlenění potřebných agregací do společných služeb;
- katalog dostupný podle efektivních práv aktuální firmy;
- hromadné načítání dat a sdílení závislostí;
- oprávnění jednotlivých podčástí a akcí;
- cache a zachování kontraktů původních endpointů.

**Podmínky dokončení:**

- shodné obchodní metriky admina a maximálně oprávněného klienta;
- odebrání práva odstraní podrobná data přímo z odpovědi;
- nepovolené poskytovatele dat požadavek nespouští;
- izolace firem, uživatelů a variant cache;
- žádné plošné otevření CRM feedu, interních agend nebo tokenového přístupu;
- regresní testy původních endpointů a invariantu agregovaného portálu.

### Etapa 3 — společné vykreslení obou ploch

**Stav:** Dokončeno. Obě stránky používají sdílenou komponentu a zachovávají
své dosavadní cesty a přesměrování. Ověřené jsou obnova dat, zahození
opožděných odpovědí, přepnutí firmy a zachování skrytých widgetů.
Responzivní zobrazení i světlý a tmavý režim byly zkontrolované v prohlížeči.

**Rozsah:**

- `DashboardBoard`, stav načítání, prázdné a chybové stavy;
- převod obsahu obou stránek na sdílené widgety;
- použití výchozí nebo vlastní konfigurace;
- obnova dat, přepínání firmy a aktivace panelů;
- zachování adres, klientských domén, přihlašovacích návratů a stavů bez firmy.

**Podmínky dokončení:**

- obě adresy používají stejné obchodní komponenty;
- stejné instance a parametry vykreslí stejné obchodní hodnoty;
- žádné prokliky do nepovolených agend;
- žádné přesměrovací smyčky ani poškození klientského přihlášení;
- funkční mobilní zobrazení a dark mode;
- zachované oddělené chování technického správce a úvodního založení firmy.

### Etapa 4 — editor, ovládání a dokumentace

**Stav:** Dokončeno. Editor zahrnuje vyhledávání, rozměry, přesouvání,
oddělovače, uložení, zrušení a reset. Rysky na hranách mění jednotlivé osy,
rohový úchyt obě. Klávesové i pointer ovládání a konflikty mají testy.
Katalog byl změřený v češtině i angličtině při šířkách 320 až 2560 px.
Aktualizované jsou kapitoly 9 a 10 manuálu i vygenerované HTML.

**Rozsah:**

- katalog pro přidání widgetu;
- odebrání, pořadí, podporované velikosti a nastavení;
- pracovní kopie, uložení, zrušení a obnovení výchozího stavu;
- obsluha konfliktu při souběžném uložení;
- klávesové a mobilní ovládání;
- české a anglické texty a aktualizace manuálu.

**Podmínky dokončení:**

- editor je společný pro `/` i `/portal`;
- zrušení editace neukládá změny;
- uložená konfigurace přežije reload, změnu firmy a aktualizaci výchozí sestavy;
- ukládání zachová známé widgety dočasně skryté právy nebo podmínkami firmy;
- nová verze výchozí sestavy nepřepíše vlastní rozložení;
- uživatel může obnovit aktuální výchozí sestavu;
- proběhne vizuální kontrola, odpovídající testy a produkční build.

PR se posílají postupně nebo nad sebou, protože etapy pracují se společnými
soubory a kontrakty.

## 10. Ověření a dokumentace při implementaci

### Akceptační scénáře

1. Admin a klient se všemi dostupnými klientskými právy mají nad stejnými
   syntetickými daty shodnou obchodní část.
2. Odebrání každého jednotlivého práva odstraní příslušné detaily a akce
   v JSON, katalogu i UI; základní agregáty se zachovají podle výše uvedené policy.
3. Čtenář může číst povolené widgety a upravovat vlastní rozložení, ale nemůže
   provádět obchodní zápisy.
4. Zámky zaúčtovaných dokladů a uzavřených období zůstávají účinné.
5. Přepnutí mezi dvěma firmami s odlišnými rolemi nepřenese data ani širší práva.
6. Klientská doména poskytuje stejnou povolenou obchodní část jako hlavní doména.
7. Cache nevrátí širší odpověď po ztrátě práv nebo odpověď jiné firmy či uživatele.
8. Nová konfigurace, vlastní konfigurace, reset, konflikt uložení a převod verze
   mají pokrytí.
9. Nový widget se nabídne v katalogu, ale sám nezmění vlastní sestavu.
10. Mobilní a klávesové ovládání funguje bez nutnosti přetažení.
11. Obnova panelu a přepnutí firmy zahodí neaktuální odpovědi.
12. Původní API kontrakty a jejich oprávnění se nezmění; nové session-only
    endpointy odmítnou API a MCP tokeny.

### Kontroly

- Cílené backendové testy registru, validátoru, konfigurací, oprávnění,
  izolace firem, cache a původních endpointů.
- Frontendové testy skládání plochy, editoru, velikostí, obnovy a rout.
- Parita manifestu, backendových pravidel a frontendových komponent.
- Testy nového chování musí před jeho implementací nebo bez příslušné změny
  prokazatelně selhat.
- Po změnách `web/src` spustit `npm run build`; typy aplikace i testů ověřit
  odpovídající typovou kontrolou.
- Při zásahu do účetní nebo daňové vrstvy postupovat podle auditních pravidel
  v `AGENTS.md`.
- Pokud se změní veřejné API, ověřit OpenAPI striktním parsováním bez duplicitních
  klíčů a bez neplatných odkazů.
- Kapitoly 9 a 10 popisují výsledné uživatelské chování. Po jejich změně
  vygenerovat pouze HTML přes `php tools/generateManualHtml.php`;
  generované soubory necommitovat.

## 11. Výchozí zdroje v repozitáři

- [Pravidla repozitáře](AGENTS.md)
- [Současný hlavní přehled](web/src/pages/Dashboard.vue)
- [Současný portál](web/src/pages/portal/PortalDashboard.vue)
- [Existující komponenty dashboardu](web/src/components/dashboard)
- [Souhrn hlavního přehledu](api/src/Action/Dashboard/SummaryAction.php)
- [Agregovaný souhrn portálu](api/src/Action/Portal/PortalSummaryAction.php)
- [CRM agregace a úkoly](api/src/Service/Crm/CrmAggregationService.php)
- [Autorizační rozhodnutí](api/src/Security/RequestAuthorization.php)
- [Katalog oprávnění](api/src/Security/PermissionCatalog.php)
- [Mapa oprávnění API](api/src/Security/RoutePermissionMap.php)
- [Současné uživatelské preference](api/src/Action/UserSettings/UserPreferenceAction.php)
- [Frontendové API preferencí](web/src/api/preferences.ts)
- [Obnova dat oken](web/src/composables/useWindowRefresh.ts)
- [Sdílená pravidla klientských rout](web/shared/client-route-policy.json)
- [Frontendové routování](web/src/router/index.ts)
- [Test invariantů portálového souhrnu](api/tests/Integration/PortalSummaryTest.php)
- [Manuál klientského portálu](manual/09_Klientsky_portal.md)
- [Manuál hlavního přehledu](manual/10_Prehled.md)
