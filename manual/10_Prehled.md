# 10. Přehled (dashboard)

Přehled je úvodní obrazovka po přihlášení — okamžitý report, kolik jsi vystavil,
co je po splatnosti, jaký je obrat za letošní a loňský rok, a kdo jsou tví
top klienti.

![Přehled (dashboard)](img/05_dashboard.webp)

## 10.1 KPI dlaždice (horní řada)

Šířka řady se přizpůsobí počtu aktivních měn (4–6 dlaždic):

| Dlaždice | Význam |
|---|---|
| **Obrat YYYY (CZK)** | Součet všech vystavených (i nezaplacených) faktur v CZK za aktuální rok. Pod číslem je pro porovnání obrat minulého roku ve stejném období. |
| **Obrat YYYY (EUR)** | Totéž pro EUR (jen pokud máš EUR měnu aktivní v Číselnících). |
| **Vystaveno YYYY** | Počet faktur za rok (všechny stavy kromě konceptů). |
| **Po splatnosti** | Suma neuhrazených faktur, které jsou po splatnosti. Zobrazená v CZK + EUR součtu, červené barvy. Klik proklikne na filtrovaný seznam. |
| **Ø doba úhrady** | Průměrný počet dní mezi vystavením a zaplacením (jen pro letošní zaplacené faktury). |

## 10.2 Top klienti — koláč

Levý koláč ukazuje **3 největší klienty letos**, pravý **3 největší loni**.
Hover nad výsečí ukáže jméno klienta + obrat. Klik na legendu odfiltruje.

> 💡 Pokud máš přístup k více firmám, koláč ukazuje data jen pro aktuálně
> vybranou firmu. V režimu horního menu je přepínač uprostřed spodní lišty;
> v režimu levého menu je vpravo v hlavičce.

## 10.3 Stav faktur — koláč

Pravý koláč rozdělí letošní faktury podle stavu:

- 🟢 **Zaplaceno** — `paid`
- 🟣 **Odesláno** — `sent` (klientovi šel e-mail s PDF, čekáme na platbu)
- 🟡 **Vystaveno (neodesláno)** — `issued` (vystaveno, ale ještě jsme neposlali)
- 🟠 **Upomínka** — `reminded` (po splatnosti, byla odeslána upomínka)
- ⚫ **Storno / dobropis** — `cancellation` / `credit_note`

## 10.4 Obrat po měsících (line / bar chart)

Spodní dva grafy ukazují měsíční obrat (CZK a EUR samostatně) — letošní rok
plnou barvou, minulý rok prázdnou pro porovnání. Hover nad sloupcem ukáže
přesnou částku.

## 10.5 Po splatnosti + nezaplacené faktury

Pod grafy je tabulka:

- **Po splatnosti** (červené) — faktury, které jsou v stavu `sent` / `issued` /
  `reminded` a překročily splatnost. Tlačítko **Upomínka** odešle upomínací
  e-mail.
- **Nezaplacené** — faktury v stavu `sent` / `issued` / `reminded`, ještě před
  splatností.

Klik na číslo faktury otevře [Detail faktury](16_Faktura_PDF.md).

## 10.6 Desktopová navigace a rychlé vytváření

V desktopovém režimu má aplikace podobu pracovní aplikace se dvěma stálými
lištami:

- **horní lišta** — logo vede domů, za ním jsou názvy sekcí hlavního menu;
  položky sekce se otevřou po najetí myší, kliknutím nebo z klávesnice;
- **kontextová nápověda (?)** — otevře manuál rovnou na kapitole odpovídající
  aktuální obrazovce;
- **uživatelské menu** — pod jménem uživatele jsou Změna hesla, 2FA / TOTP,
  Přístupové klíče, Zámek aplikace, Klávesové zkratky a Odhlásit;
- **spodní lišta** — vlevo je společné hledání v menu, klientech a dokladech,
  uprostřed přepínač firem (jen v režimu horního menu) a vpravo jazyk,
  světlý/tmavý motiv, verze a odkazy aplikace;
- **režim levého menu** — přepínač firem se přesune do pravé části hlavičky,
  kde je od ostatních akcí oddělený svislou čárou.

Pokud by se názvy sekcí do horní lišty nevešly, aplikace to změří a automaticky
zobrazí menu jako trvalý levý panel. Na dostatečně široké obrazovce můžeš mezi
horní a levou variantou přepnout ikonou rozložení ve spodní liště; přetékající
horní variantu nelze vynutit. Výchozí je horní menu; ručně zvolená levá
varianta se uloží do cookie tohoto prohlížeče. Obě varianty používají stejné
názvy sekcí; například **Sklad** a **Nástroje**. Na menších obrazovkách se
v hlavičce vedle loga zobrazuje celý název aplikace a hlavní nabídka se otevírá
tlačítkem **☰** přes celou šířku obrazovky. Přepínač firmy pod hlavičkou také
využívá celou dostupnou šířku. Jazyk a motiv zůstávají ve spodní liště; jazyk
přepíná jediná vlaječka na druhý dostupný jazyk.

Aktuální hlavní struktura menu je:

| Sekce | Důležité samostatné body |
|---|---|
| **Grafy** | Akce pro tebe, Přehled firmy, Zisk, Tržby, Náklady |
| **Prodej** | Vydané a pravidelné faktury, klienti, zakázky, AI import, export a import |
| **Nákup** | Přijaté faktury, AI import, dodavatelé, platební příkazy, drobný majetek, export a import |
| **Peníze** | Bankovní účty a Pokladna |
| **Dokumenty** | Dokumenty a Kniha jízd |
| **Sklad** | Skladové karty, příjemky a výdejky, E-shop, inventury a sestavy |
| **Daně** | Daňové výkazy, Daňový optimalizátor a samostatný **Hromadný export** |
| **Účetnictví** | **Přehled firem**, Účetní deník, Automat, K doúčtování, účetní výkazy, kontroly, mzdy a majetek |
| **Nástroje** | Šablony, Účtový rozvrh, Zápočty, Aktivace a doúčtování, Inventarizace účtů, výkazy kapitálu, Spojené osoby, Uzávěrka, Účetní nastavení a jako poslední **EPO podání a archív** |
| **Firma** | Nastavení firmy, integrace, AI nastavení, branding, kategorie, API tokeny a chybějící doklady |
| **Systém** | Sazby a číselníky, samostatné **Daňové konstanty**, dodavatelé, uživatelé, role, e-maily, log, plánované úlohy, aktualizace a licenční položky |

Nové doklady a záznamy zakládáš z tlačítka **+** vpravo v horní liště.
Rozbalovací menu nabízí:

- **Vydaná faktura** — otevře [Editor faktury](15_Faktura_editor.md), prázdný koncept
- **Zálohová faktura** — editor rovnou v režimu proforma
- **Pravidelná fakturace** — nová [šablona](17_Pravidelne_fakturace.md)
- **Klient** — modal pro založení klienta (s ARES lookupem)
- **Dodavatel** — nový dodavatel (firma)
- **Přijatá faktura** — nová [přijatá faktura](23_Prijate_faktury.md)
- **Nový účetní zápis** — ruční zápis do účetního deníku, pokud k němu máš oprávnění

Stejné zkratky najdeš jako nenápadné **„+"** u příslušné položky v popup menu
sekce (objeví se po najetí myší). Rychlé vytváření je dostupné jen pro
uživatele s právem zápisu.

### 10.6.1 Klávesové zkratky

Pátá položka uživatelského menu **Klávesové zkratky** umožňuje nastavit
kombinaci pro každý viditelný bod menu, položky **Přidat / Nová** i hledání ve
spodní liště. Nastavení je uložené u uživatelského účtu, takže se přenáší mezi
prohlížeči a není společné s ostatními uživateli. Stejnou obrazovku najdeš jako
pátou záložku v **Profilu → Klávesové zkratky**; na mobilu ji vybereš z nabídky
záložek pod nadpisem Profil.

Výchozí kombinace jsou **Alt+Q** pro hledání, **Alt+1** vydaná faktura,
**Alt+2** přijatá faktura, **Alt+3** klient, **Alt+4** dodavatel,
**Alt+5** účetní zápis, **Alt+6** pravidelná fakturace a **Alt+7** přehled
firem. Poslední zkratka se nabízí jen uživatelům s přístupem k více firmám.
Kolizní nebo prohlížečem vyhrazenou kombinaci nelze uložit.

### 10.6.2 Více panelů pracovního prostoru

Na široké desktopové obrazovce můžeš v horní liště zvolit rozložení s jedním,
dvěma nebo třemi stejně širokými panely. Dva panely jsou dostupné od šířky
pracovního prostoru 1 100 px, tři od 1 600 px. Na užším okně se aplikace
automaticky vrátí k jednomu panelu. Přepínač rozložení je v horní liště.
V režimu dvou nebo tří panelů můžeš jejich šířku změnit tažením svislého
předělu myší nebo klávesami šipka vlevo a vpravo po zaměření předělu. Obsah
stránky se přitom responzivně přeskupuje podle skutečné šířky svého panelu,
nikoli podle šířky celého okna prohlížeče.

Kliknutím do panelu jej aktivuješ. Barevná horní hrana ukazuje, do kterého
panelu se otevře další položka hlavního menu, výsledek globálního hledání,
příkaz z palety nebo rychlá akce **+**. Aktivní panel lze rychle přepnout také
klávesami **Ctrl+Alt+1**, **Ctrl+Alt+2** a **Ctrl+Alt+3**. Kombinace
**Shift+Alt+1**, **Shift+Alt+2** a **Shift+Alt+3** nastaví přímo počet panelů.
Odkazy a tlačítka uvnitř otevřené stránky zůstávají v témže panelu. Každý panel má vlastní tlačítka **Zpět** a
**Vpřed**; tlačítka prohlížeče ovládají první panel, jehož adresa je vidět
v adresním řádku. Tlačítko **×** zavře nejprve pouze obsah daného panelu;
samotný panel zůstane prázdný a připravený pro další stránku. Další kliknutí na
**×** v prázdném vedlejším panelu zmenší počet panelů. Neuložené změny v
zavřeném obsahu se nezachovají. První panel se místo prázdné stránky vrátí na
Přehled, aby jeho obsah zůstal shodný s adresou prohlížeče. Interní odkaz nebo
navigovatelný řádek označený
šestibodovým úchytem lze také přetáhnout myší a pustit do panelu, ve kterém jej
chceš otevřít.

Zvýšení počtu panelů zachová jejich otevřený obsah, přidá nový prázdný panel
napravo a aktivuje první prázdný vedlejší panel pro následující volbu z menu.
Snížení počtu odstraní panely zprava; obsah ponechaných panelů se
nemění. Neuložené změny v odstraněném panelu se nezachovají. Po obnovení celé
záložky se vždy otevře bezpečný jednopanelový režim na adrese prvního panelu.

Všechny panely používají stejnou přihlášenou relaci a jednu aktivní firmu.
Přepnutí firmy proto změní kontext celého pracovního prostoru. Prakticky lze
například ponechat seznam přijatých faktur v prvním panelu, otevřít detail
dokladu ve druhém a účetní deník ve třetím. **Ctrl+klik** na interní odkaz
uvnitř panelu jej otevře v prvním prázdném panelu napravo a tento panel
aktivuje. Po zaplnění všech panelů napravo se další odkaz otevře v
bezprostředním panelu **+1**. Ctrl+klik v posledním panelu, Cmd+klik a
prostřední tlačítko myši zachovávají běžné otevření nové záložky prohlížeče.

## 10.7 Aktualizace dat

Statistiky se nepočítají v reálném čase — používají agregační cache
(`project_revenue_cache`, `client_revenue_cache`), která se přepočítá pokaždé,
když vystavíš / zrušíš / označíš zaplacenou fakturu. Pokud někdy zjistíš, že
čísla nesedí (např. po manuální úpravě v DB), spusť z CLI:

```bash
php api/bin/recompute-stats.php
```

> 🛈 Sample data (vygenerovaná během setup wizardu) automaticky přepočítají
> stats hned po dokončení — nemusíš nic dělat.

## 10.8 Daňový kalendář

Widget **Daňový kalendář** (pod dlaždicemi, vedle nadcházejících záloh) shrnuje
blížící se daňové termíny aktuálního dodavatele do jednoho seznamu:

- **DPH přiznání** a **Kontrolní hlášení** — dle periodicity dodavatele
  (měsíčně / čtvrtletně, viz [§ 36](36_Vykazy_DPH.md)).
- **Souhrnné hlášení** — jen pokud má firma za předchozí měsíc EU B2B plnění.
- **Zálohy na daň a pojistné** — z [Daně z příjmů § Zálohy na daň a pojistné](38_Dan_z_prijmu.md#384-zalohy-na-dan-a-pojistne),
  s částkou a stavem *naplánováno* / *zaplaceno*.
- **Roční přiznání DPFO/DPPO** — standardní termíny (papírově 1. 4.,
  elektronicky začátkem května, posunuto z 1. 5. na nejbližší pracovní den).
  OSVČ v paušálním režimu se nezobrazuje (nepodává DPFO).

Každá položka nese odznak **Podáno** / **Nepodáno**, odvozený z toho, zda pro
dané období existuje archivované podání (menu **Nástroje → EPO podání a archív**) —
generování EPO XML se tam ukládá automaticky. U záloh odznak místo toho
ukazuje **Zaplaceno** / **Splatné**. Klik na položku otevře příslušný výkaz.

## 10.9 Vzhled — jazyk, světlý a tmavý režim

Ve spodní liště jsou vedle sebe jediná přepínací vlaječka jazyka a ikona motivu
— slunce/měsíc podle aktuálního režimu. Vlaječka ukazuje jazyk, na který se
kliknutím přepneš; druhé samostatné tlačítko pro aktuální jazyk se nezobrazuje.
Ikona motivu přepne mezi **světlým** a **tmavým** tématem.

Dokud v prohlížeči není uložená žádná volba (první návštěva, žádná cookie),
aplikace se řídí nastavením operačního systému / prohlížeče
(`prefers-color-scheme`). Jakmile ikonu poprvé použiješ, volba se uloží do
prohlížeče (per zařízení) a platí napříč celou aplikací včetně grafů — dokud
ji znovu nezměníš.

## 10.10 Akce pro tebe

Úplně nahoře na Přehledu (nad KPI dlaždicemi) je widget **⚡ Akce pro tebe** —
průběžně skládaná fronta věcí, které čekají na tvůj zásah, s odznakem počtu.
Vidí ho každý, kdo smí zapisovat (readonly roli je skrytý úplně).

Widget kombinuje víc typů položek, každá se zobrazí jen když má co hlásit:

| Položka | Kdy se objeví | Vede na |
|---|---|---|
| Pošli upomínky | Faktury po splatnosti bez odeslané upomínky | `Faktury` (filtr po splatnosti) |
| Spáruj platby z banky | Nespárované bankovní transakce | [Banka](28_Banka.md) |
| Vystav pravidelné faktury | Splatné pravidelné faktury čekají na vygenerování | [Pravidelné faktury](17_Pravidelne_fakturace.md) |
| Zaplať dodavatelům | Přijaté faktury po splatnosti | `Přijaté faktury` (filtr po splatnosti) |
| Zkontroluj koncepty přijatých faktur | Rozpracované koncepty PF | [Přijaté faktury](23_Prijate_faktury.md) (filtr koncept) |
| **Zaúčtuj doklady** | Jen podvojné účetnictví — viz [§ 10.10.1](#10101-zauctuj-doklady) | Filtrovaný seznam FV/PF/banka |
| **Zkontroluj integritu deníku** | Jen podvojné účetnictví — viz [§ 10.10.2](#10102-zkontroluj-integritu-deniku) | [Účetní deník](45_Ucetni_denik.md) |
| Termín DPH / KH | Blíží se nebo uplynul termín podání | [Výkazy DPH](36_Vykazy_DPH.md) |
| Souhrnné hlášení za uplynulý měsíc | Termín SH | [Souhrnné hlášení](39_Souhrnne_hlaseni.md) |
| Kontaktuj neaktivní klienty | Klienti bez aktivity delší dobu (churn risk) | [Zisk](11_Zisk.md) |
| **Odešli měsíční hlášení** | Připravené měsíční hlášení (JMHZ) čeká na odeslání ČSSZ, viz [§ 10.10.3](#10103-odesli-mesicni-hlaseni) | [Podání a hlášení](68_Podani_a_hlaseni.md) |

Každá položka má menu se **skrytím** (na den / týden / natrvalo / historicky) —
pokud si něco odklikneš, dole se objeví odkaz **„Obnovit skrytá (N)"**, kterým
skryté položky zase vrátíš.

### 10.10.1 Zaúčtuj doklady

Zobrazí se jen firmám v režimu **podvojné účetnictví** (daňová evidence
`booked_at` nepoužívá, takže se jí tahle položka netýká). Sečte tři zdroje
nezaúčtovaných dokladů a u každého ukáže samostatný klikatelný štítek s
počtem:

- **Vydané** — vydané faktury/dobropisy/daňové doklady k platbě s
  `booked_at IS NULL` (mimo koncepty a stornované) → filtr
  `/invoices?booked=0` (viz [§ 14.1.1](14_Faktury.md#1411-filtry-vlevo)).
- **Přijaté** — přijaté faktury s `booked_at IS NULL` (mimo koncepty a
  stornované) → `/purchase-invoices?booked=0` (viz
  [§ 23.3.3](23_Prijate_faktury.md#2334-filtr-a-tlacitko-zauctovat)).
- **Banka** — nevyřízené návrhy zaúčtování bankovních transakcí → [Banka](28_Banka.md).

Zálohové (proforma) faktury se do počtu záměrně nepočítají — nejsou daňový
doklad, `booked_at` u nich zůstává trvale prázdné, takže by jen uměle nafukovaly
číslo. Klik na hlavní řádek otevře první neprázdný zdroj, klik na konkrétní
štítek rovnou jeho filtrovaný seznam. Jak samotné zaúčtování z detailu dokladu
funguje, popisují kapitoly [Faktury](14_Faktury.md) a
[Přijaté faktury](23_Prijate_faktury.md).

### 10.10.2 Zkontroluj integritu deníku

Taky jen podvojné účetnictví. Na rozdíl od ostatních položek nepočítá nic
naživo — čte poslední uložený běh **nočního kontrolního jobu** (viz
[§ 45.10 Kontrola integrity deníku](45_Ucetni_denik.md#4510-kontrola-integrity-deniku-nocni-job)),
aby dotaz na dashboard zůstal levný. Pokud job našel nesrovnalost mezi doklady
a deníkem, položka se zobrazí se závažností **vysoká** a počtem nálezů v
popisku. Klik — na hlavním řádku i na kterémkoli štítku rozpadu — vede vždy na
[Účetní deník](45_Ucetni_denik.md); appka nemá samostatnou stránku s výpisem
jednotlivých nálezů; ty najdeš jen přes CLI (viz § 45.10).

### 10.10.3 Odešli měsíční hlášení

Objeví se, jakmile je měsíční hlášení zaměstnavatele (JMHZ) **připravené
k odeslání** na ČSSZ a nikdo je neodeslal. Má závažnost **vysokou** a v popisku
počet takových podání; klik vede na obrazovku mzdových podání.

Odeslání zůstává na výslovném potvrzení člověka a je to tak správně: je to
právní úkon přičitatelný zaměstnavateli a poslední okamžik, kdy si někdo může
všimnout, že je něco špatně. Automatické odeslání by tenhle okamžik vzalo.
Zapomenout na něj ale znamená propásnout lhůtu do 20. dne následujícího měsíce,
a to je chyba, která se sama ničím neprojeví. Proto hlášení visí mezi úkoly
tak dlouho, dokud je skutečně neodešleš.

Položka **zmizí odesláním, ne přijetím** - na protokol z ČSSZ se nečeká, ten
sleduješ dál v [Podání a hlášení](68_Podani_a_hlaseni.md). Nabízí se jen ostré
prostředí a jen kanál ČSSZ: testovací podání nikdo podávat nemusí a podání na
portál zdravotní pojišťovny aplikace odeslat neumí, protože žádná ze sedmi
pojišťoven nemá zveřejněné strojové rozhraní. Vyzývat k úkonu, který se odsud
udělat nedá, by bylo horší než mlčet.

## 10.11 Průvodce prvním nastavením

Dokud v systému nejsou žádné doklady ani klienti, Přehled místo prázdných grafů
ukáže **průvodce prvním nastavením** — rozcestník po místech, která je potřeba
vyplnit, než začneš fakturovat. Vidí ho každý, kdo smí zapisovat.

| Krok | Vede na |
|---|---|
| Údaje o firmě | [Nastavení → Údaje firmy](92_Nastaveni.md) (`/admin/settings?tab=company`) |
| Daně a účetnictví | Nastavení → Daně a účetnictví (`/admin/settings?tab=accounting`) |
| Bankovní účty | [Banka → Účty](28_Banka.md) |
| Vzhled faktur a logo | [Brandingové profily](92_Nastaveni.md#9212-brandingove-profily) |
| Přidat další firmy | Jen licence na víc firem, dokud v ní zbývá místo — Systém → Dodavatelé (`/admin/suppliers`) |
| Číselné řady a doklady | Nastavení → Fakturace (`/admin/settings?tab=documents`) |
| Číselné řady deníku | Jen podvojné účetnictví — [Účetní nástroje](88_Ucetni_nastroje.md) |
| Avíza plateb z e-mailů | [Banka → Bankovní avíza z e-mailu](28_Banka.md) (`/bank?tab=email`) |
| Datová schránka | [Firma → Datová schránka](92_Nastaveni.md#9217-datova-schranka) (`/admin/databox`) |
| Uživatelé a role | [Uživatelé](92_Nastaveni.md#922-uzivatele) |
| První klient / první faktura | [Klienti](18_Klienti.md) · [Faktury](14_Faktury.md) |

Kroky se **odškrtávají ručně** — aplikace nic nekontroluje a odškrtnutí nemá
žádný vliv na chování, je to tvoje poznámka. Tlačítkem **Skrýt průvodce** zmizí
a zůstane po něm jen řádek s odkazem **Zobrazit průvodce**, kterým ho kdykoli
vrátíš. Stav (odškrtnuté kroky i skrytí) se ukládá per uživatel.
