# 78. Majetek

**Cesta: `Účetnictví → Majetek`**

Modul vede evidenci **dlouhodobého
hmotného a nehmotného majetku** — karty s inventárními čísly, výpočet **daňových
odpisů** (§26–33 zákona o daních z příjmů) i **účetních odpisů** (ČÚS 013),
technická zhodnocení a vyřazení. Je dostupný jen v režimu **podvojného
účetnictví** (u daňové evidence se pořízení majetku eviduje jinak, viz
[Daňová evidence](90_Danova_evidence.md)).

> [!NOTE]
> Sazby a koeficienty daňových odpisů (§30–32 ZDP) i podmínky mimořádných
> odpisů bezemisních vozidel (§30a) jsou v systému napevno zakódované podle
> aktuálního znění zákona — modul sám nehlídá budoucí legislativní změny.
> Zkontroluj vždy aktuální stav se svou účetní, zejména u hraničních částek
> (80 000 Kč, limit 2 000 000 Kč u vozidel M1).

## 78.1 Seznam majetku

Stránka **Majetek** zobrazuje všechny karty firmy v tabulce se sloupci
**inventární číslo**, **název**, **účet**, **datum zařazení**, **vstupní cena**
(u navýšené o technická zhodnocení je pod částkou drobná poznámka „vč. TZ"),
**daňová zůstatková cena**, **účetní zůstatková cena**, **stav** a volitelně
(přes výběr sloupců) **způsob odpisu**, **odpisová skupina** a **datum
vyřazení**. Sloupce si zapneš/vypneš přes ikonu výběru sloupců, hustotu řádků
přepínačem hustoty; obojí se pamatuje jako u ostatních seznamů v aplikaci.

Filtrovat lze podle **stavu** (Koncept / V užívání / Vyřazeno) a fulltextem
podle **inventárního čísla nebo názvu**. Filtry si můžeš uložit jako výchozí
pohled (stejný mechanismus uložených filtrů jako jinde v aplikaci). Klik na
řádek otevře [detail karty](#783-detail-karty-a-plan-odpisu).

Nad tabulkou najdeš tlačítka:

- **Export** / **Import** — hromadný přenos karet přes Excel, viz
  [§ 78.7](#787-import-a-export-excelem).
- **Zaúčtovat odpisy** — hromadné roční zaúčtování, viz
  [§ 78.6](#786-hromadne-zauctovani-odpisu-roku).
- **Z přijaté faktury** — založení nové karty z přijaté faktury označené jako
  dlouhodobý majetek.
- **Nový majetek** — ruční založení karty.

### 78.1.1 Založení z přijaté faktury

Tlačítko **Z přijaté faktury** otevře seznam přijatých faktur s příznakem
**dlouhodobý majetek**, které ještě nemají svou kartu (faktury, které kartu
už mají, jsou označené štítkem „Už má kartu"). U každé vidíš doklad,
dodavatele, DUZP a základ bez DPH. Výběrem řádku se otevře editor nové karty
s **předvyplněnou vstupní cenou** (základ bez DPH, případně cena s DPH, pokud
u dokladu není nárok na odpočet), **datem pořízení** (DUZP, případně datum
vystavení) a **názvem** (popis položky nebo dodavatel + číslo dokladu).
Karta zůstává provázaná s fakturou — v editoru i detailu je odkaz zpět na
přijatou fakturu.

## 78.2 Založení a úprava karty

Editor karty (**Nový majetek** / úprava konceptu) je rozdělený do sekcí:

**Identifikace** — **inventární číslo** (povinné, doporučený tvar např.
`M-000001`), **název** (povinné), volitelný **popis** a **druh majetku**
(Hmotný majetek / Nehmotný majetek).

**Účty** — **majetkový účet** (01x/02x/03x dle osnovy firmy), **účet oprávek**
(07x/08x; prázdná volba = neodpisovaný majetek, např. pozemky §27) a **účet
pořízení** (041/042). Při výběru majetkového účtu se účet oprávek i účet
pořízení **předvyplní automaticky** podle zavedené mapy syntetických účtů
(např. 022 → 082 → 042, 013 → 073 → 041); ruční úprava je možná.

**Pořízení** — **vstupní cena** (§29, povinná, kladná) a **datum pořízení**.
Pokud je karta založená z faktury, zobrazí se i odkaz na zdrojový doklad.
U movitého hmotného majetku s daňovým odpisem a vstupní cenou do 80 000 Kč
se zobrazí upozornění, že nejde o hmotný majetek pro daňové odpisy dle §26/2.

**Daňové odpisy** — **metoda**:

| Druh majetku | Dostupné metody |
|---|---|
| Hmotný majetek | Rovnoměrné §31, Zrychlené §32, Mimořádné §30a (bezemisní vozidlo), Neodpisuje se |
| Nehmotný majetek | Daňový odpis = účetní (DNM, §24/2/v), Neodpisuje se |

U rovnoměrné a zrychlené metody je povinná **odpisová skupina 1–6** (doba
odpisování 3/5/10/20/30/50 let) a volitelně **zvýšení odpisu v 1. roce**
(§31/1 písm. b–d: +10 %/+15 %/+20 %) — dostupné jen pro skupiny 1–3 a jen
pro **prvního odpisovatele**; u osobního automobilu kategorie M1 zvýšení
podle §31 odst. 5 použít nelze. U hmotného majetku se dál zaškrtává **první
odpisovatel**, a pokud jde o vozidlo, i **vozidlo kategorie M1** (limit
§30e — uplatněný odpis nad 2 000 000 Kč vstupní ceny se poměrně krátí),
**výjimka z limitu** (sanitní/pohřební vozidlo, koncese) a **bezemisní
vozidlo**. Pokud je vozidlo M1 bez výjimky a vstupní cena přesahuje
2 000 000 Kč, editor na to upozorní.

Metoda **Mimořádné §30a** je povolená jen pro **bezemisní vozidlo pořízené
prvním odpisovatelem mezi 1. 1. 2024 a 31. 12. 2028** — jinak editor uložení
odmítne s vysvětlením podmínek.

**Účetní odpisy** — sekce se zobrazí jen u odpisovaného majetku (má vybraný
účet oprávek): **účetní doba použitelnosti v měsících** (povinná, ≥ 1) a
**účetní zbytková hodnota** (musí být ≥ 0 a menší než vstupní cena). Účetní
odpisový plán je čistě **prospektivní** — datum zařazení do užívání zatím
nemusí být známé, plán se dopočítá až podle skutečného zařazení.

**Historický majetek** — zaškrtávátko se nabízí jen při zakládání nové karty.
Umožňuje rovnou založit kartu ve stavu **V užívání** s historií — zadáš
**datum zařazení**, kolik **let bylo daňově odepsáno** a **kolik Kč**, kolik
**měsíců bylo účetně odepsáno** a **kolik Kč**. Po uložení už tato pole
nejde editovat (zamčeno, viz níže). Počáteční daňové oprávky nesmějí
přesáhnout vstupní cenu; počáteční účetní oprávky spolu s účetní zbytkovou
hodnotou rovněž nesmějí vstupní cenu překročit. Stejná kontrola platí při
importu karet.

### 78.2.1 Zámky po zařazení a potvrzení

Karta se v čase postupně **uzamyká**, aby nešlo měnit už uplatněné odpisy
zpětně:

- Po **prvním potvrzeném daňovém odpisu** (potvrzený rok přes zaúčtování nebo
  vyřazení) se uzamknou **daňové parametry** (metoda, skupina, zvýšení,
  vlastnosti vozidla) i **vstupní cena**.
- Po **zařazení do užívání** (karta opustí stav Koncept) se uzamknou
  **pořizovací údaje** (vstupní cena, datum pořízení) — bez ohledu na to,
  zda už byl potvrzený odpis.

Zamčená pole jsou v editoru neaktivní s vysvětlující bublinou. Účetní parametry
(životnost, zbytková hodnota) zamčené nejsou — jejich změna se promítne
prospektivně do dalších měsíců plánu.

## 78.3 Detail karty a plán odpisů

Detail karty ukazuje hlavičku (název, stav, inventární číslo, druh, metoda a
skupina, trojice účtů), **souhrnné dlaždice** (vstupní cena, zvýšená vstupní
cena po TZ, kumulované oprávky, daňová a účetní zůstatková cena), základní
údaje (datum pořízení, zařazení, účetní životnost, odkaz na fakturu) a —
pokud existují — tabulku **technických zhodnocení** s celkovým součtem.

Klíčová část detailu je **Plán odpisů** se záložkami **Daňové** / **Účetní**
(záložka se nabízí, jen pokud majetek daný druh odpisu vůbec má). Tabulka po
řádcích (rok) ukazuje **zůstatkovou cenu na počátku**, **odpis** (u daňových,
kde se liší uplatněná částka od stanovené kvůli §30e, i sloupec
**Uplatněno**), **zůstatkovou cenu na konci** a **stav řádku**:

- **Plán** — rok je jen dopočítaný „na papíře", zatím nic nezaúčtováno ani
  nepotvrzeno.
- **Potvrzeno** (daňová záložka) / **Zaúčtováno** (účetní záložka) — rok už
  má reálný záznam v databázi (u účetní záložky i zápis v deníku).

U zaúčtovaného roku je dostupná akce **Smazat zaúčtování odpisu**. Smazání
odstraní zápis z deníku, účetní odpis i potvrzený daňový odpis stejného roku,
aby šel rok opravit a zaúčtovat znovu. Existující přerušení daňového odpisu
zůstane zachováno. Mazat lze pouze poslední potvrzený rok, v otevřeném období,
mimo uzamčenou část účetnictví a dokud majetek není vyřazený.

Řádky mohou nést značky **½** (půlodpis podle §26/7 v roce vyřazení) a
**⏸** (přerušení §26/8, viz [§ 78.5](#785-preruseni-danoveho-odpisu)).
U mimořádných odpisů (§30a) a u účetních odpisů se dá řádek roku **rozkliknout**
— rozbalí se **měsíční rozpis** částek. Pod tabulkou je poznámka o znění
zákona, ke kterému jsou sazby v systému vedené.

> [!TIP]
> Plán se počítá **on-the-fly** — minulé roky vycházejí z potvrzených/
> zaúčtovaných záznamů, budoucí roky se dopočítávají podle aktuálních
> parametrů karty. Změníš-li parametr, který ovlivňuje výpočet (např. přidáš
> technické zhodnocení), plán pro nepotvrzené roky se přepočítá okamžitě.

## 78.4 Zařazení do užívání, technické zhodnocení, vyřazení

Akce dostupné v hlavičce detailu se mění podle stavu karty.

### 78.4.1 Zařazení do užívání

U karty ve stavu **Koncept** tlačítko **Zařadit do užívání** otevře dialog s
**datem zařazení** (nesmí předcházet datu pořízení) a zaškrtávátkem
**Zaúčtovat zařazení (02x / 04x)**. Při zaškrtnutí systém zaúčtuje MD
majetkový účet / D účet pořízení ve výši vstupní ceny (navýšené o technická
zhodnocení dokončená do data zařazení). Pokud zaškrtávátko vypneš, zápis se
neprovede — hodí se pro **historický majetek**, kde zůstatky na účtech 02x
přicházejí počátečními stavy; pokud by přitom datum spadalo do otevřeného
období, systém na to upozorní varováním.

Kartu lze **smazat** nejen ve stavu Koncept, ale také po chybném zařazení do
užívání. U karty v užívání se spolu s kartou atomicky smaže i účetní zápis
zařazení, takže ji lze založit znovu se správným datem. Smazání je možné jen
bez potvrzených nebo zaúčtovaných odpisů a bez technických zhodnocení; případné
odpisy je potřeba nejdřív odstranit odzadu a přerušení zrušit. Zápis zařazení
musí být v otevřeném období a mimo uzamčenou část účetnictví. Vyřazenou kartu
je nutné nejdřív vrátit z vyřazení.

### 78.4.2 Technické zhodnocení (§33)

U karty **V užívání** tlačítko **Technické zhodnocení** otevře formulář s
**datem dokončení** (nesmí předcházet datu zařazení do užívání), **částkou**
a volitelným **popisem**. Přidané TZ se objeví v tabulce technických
zhodnocení v detailu a promítne se do vstupní ceny i do plánu odpisů obou
druhů od příslušného roku/měsíce dál. Pokud součet TZ za daný rok nepřesáhne
80 000 Kč, systém upozorní, že nejde o povinné technické zhodnocení dle §33
a lze zvážit jednorázový náklad.

Samotné **zaúčtování** TZ na účty 02x/042 se dělá **ručním zápisem v deníku**
— karta pořizovací cenu a odpisy promítne automaticky, ale účetní zápis
pořízení TZ modul negeneruje. TZ nejde přidat u majetku odpisovaného
mimořádnými odpisy §30a (TZ u něj vstupní cenu nezvyšuje — pro takové
zhodnocení založ samostatnou kartu, odpisovanou ve skupině 2). Smazat lze jen
TZ z roku, který ještě nemá potvrzený daňový odpis.

> [!NOTE]
> **Chronologie navazujících let.** Přidání i smazání technického zhodnocení navíc
> kontroluje, jestli už neexistuje **potvrzený daňový nebo účetní odpis pozdějšího
> roku** — pokud ano, zásah do dřívějšího roku systém odmítne, protože by zpětně
> změnil odpisovou základnu, se kterou už pozdější potvrzený rok počítal. V takovém
> případě je nejdřív potřeba vrátit potvrzení pozdějších let.

### 78.4.3 Vyřazení a vrácení vyřazení

Tlačítko **Vyřadit** (u karty V užívání) otevře dialog s **typem vyřazení**
(Prodej / Likvidace / Dar / Manko a škoda), **datem vyřazení** (nesmí
předcházet datu zařazení) a u prodeje volitelnou **prodejní cenou bez DPH**
(jen evidenční údaj na kartě).

Při potvrzení systém v jedné transakci:

1. dopočítá a zaúčtuje **účetní odpis roku vyřazení** až do měsíce vyřazení
   včetně,
2. potvrdí **daňový odpis roku vyřazení** (typicky půlodpis §26/7, u §30a
   poslední měsíc před vyřazením),
3. zaúčtuje **vyřazovací zápis**: u odpisovaného majetku nejdřív doodepsání
   zůstatkové ceny (náklad podle typu vyřazení — 541 prodej, 551 likvidace,
   543 dar, 549 manko a škoda — proti oprávkám), pak vyřazení v (zvýšené)
   pořizovací ceně z oprávek proti majetkovému účtu; u neodpisovaného majetku
   (§27) jde jen o jeden pár v plné pořizovací ceně,
4. nastaví kartu do stavu **Vyřazeno** s datem, typem a případnou prodejní
   cenou.

Vyřazení současně hlídá chronologii odpisů: odmítne datum v roce, po kterém
už existuje potvrzený daňový nebo účetní odpis, a nedovolí přeskočit
bezprostředně předchozí nepotvrzený daňový rok. Nejdřív je potřeba pozdější
roky vrátit, případně chybějící předchozí rok zaúčtovat nebo přerušit.

> [!WARNING]
> Karta majetku **neúčtuje tržbu z prodeje**. U typu Prodej systém jen
> připomene, že výnos (účet 641 + DPH) je potřeba zaúčtovat samostatnou
> **vydanou fakturou** — vyřazovací zápis řeší jen odúčtování majetku
> a jeho oprávek, ne inkaso od kupujícího.

> [!TIP]
> Prodej jde spustit i **z opačné strany, přímo z faktury**, a ušetřit si ruční
> vyřazování. V editoru vydané faktury zaškrtni **Prodej majetku**, u položky vyber
> kartu z našeptávače a fakturu vystav. Řádek pak zaúčtuje výnos na **641** (u
> drobného majetku na 642) a systém sám provede vyřazení typu Prodej se všemi
> kroky popsanými výše, včetně vazby karty na doklad. Faktura navíc dostane
> klasifikaci DPH **1m/2m** — prodej dlouhodobého majetku se podle §76 odst. 4
> ZDPH nezapočítává do koeficientu. Storno faktury vyřazení vrátí (jen dokud je
> období otevřené, viz níže).

> [!WARNING]
> U **darování** majetku, u kterého byl uplatněn odpočet DPH, systém upozorní
> na nutnost ověřit odvod DPH z ceny obvyklé (§13 odst. 4 písm. a) a §36
> odst. 6 písm. a) ZDPH). U **likvidace, manka nebo škody** připomene doložení
> způsobu vyřazení a případné vyrovnání či úpravu odpočtu (§77 a §78e ZDPH).
> Karta sama interní daňový doklad nevytváří.

Vyřazenou kartu lze **Vrátit vyřazení** — dostupné, jen dokud je **účetní
období data vyřazení stále otevřené** (§35 zákona o účetnictví). Vrácení
stornuje vyřazovací zápis i účetní odpis roku vyřazení, smaže jím vytvořené
řádky roku vyřazení a vrátí kartu do stavu V užívání. Dříve zvolená pauza
daňového odpisu podle §26 odst. 8 zůstává zachována; vrácení se potvrzuje
přes dialogové okno, protože jde o nevratnou operaci v rámci daného běhu.

## 78.5 Přerušení daňového odpisu

U karty s **rovnoměrnou nebo zrychlenou** daňovou metodou nabízí detail
tlačítko **Přerušit odpis roku** — otevře výběr roku a po potvrzení se daný
rok označí jako **přerušený** podle §26/8 ZDP: daňový odpis za rok je nulový,
ale zůstatková cena se nemění a v dalších letech se pokračuje, jako by k
přerušení nedošlo (roky odpisování se jen posunou). V plánu odpisů se
přerušený rok označí značkou **⏸**; tlačítkem **Zrušit přerušení** u daného
řádku (dostupné, dokud je karta V užívání) se přerušení zase odvolá.

> [!NOTE]
> Přerušit lze jen rok, po kterém **není potvrzený žádný pozdější** daňový odpis —
> u zrychlené metody (§32) by přerušení zpětně posunulo odpisové schéma a znehodnotilo
> výši odpisu už potvrzeného pozdějšího roku. Pokud takový pozdější rok existuje,
> systém přerušení odmítne a je potřeba nejdřív vrátit potvrzení pozdějších let, teprve
> pak přerušit rok dřívější.

## 78.6 Hromadné zaúčtování odpisů roku

Tlačítko **Zaúčtovat odpisy** na seznamu majetku otevře dialog s výběrem
**ukončeného zdaňovacího období (roku)**. Probíhající ani budoucí období
zaúčtovat nelze. Po spuštění systém projde **všechny karty
V užívání** (i vyřazené v daném roce) a pro každou:

- zaúčtuje **účetní odpis roku** (MD nákladový účet z kontace
  `depreciation.booking`, typicky 551 / D účet oprávek z karty) a zapíše
  odpovídající řádek do [Účetního deníku](45_Ucetni_denik.md) se zdrojem
  „odpis" — přes [PostingService](45_Ucetni_denik.md) se stejnou
  idempotencí jako ostatní automatické zápisy modulu Účetnictví,
- potvrdí **daňový odpis roku** (bez zápisu do deníku — daňový odpis je čistě
  evidenční údaj pro přiznání k dani z příjmů).

Roky s aktivním přerušením §26/8 se u daňové části přeskočí (existující
pauza zůstává zachována). Operace je **hromadná a idempotentní** — opakované
spuštění pro stejný rok přepíše zápisy in-place, dokud je dané účetní období
otevřené. Po doběhnutí dialog ukáže počet **zaúčtovaných** a **přeskočených**
karet; pokud u některé karty zaúčtování selže (např. kvůli uzavřenému
období), operace pro ostatní karty pokračuje dál a chyba se jen připočítá do
souhrnu — nezastaví celý běh.

Kromě uzavřeného období může zaúčtování jednotlivé karty selhat i na
**chronologii** — pokud majetek ještě má nenulovou daňovou zůstatkovou cenu a
bezprostředně předchozí zdaňovací rok u něj nemá potvrzený ani přerušený daňový
odpis (typicky se nějaký rok omylem přeskočil), systém odpis roku pro tuto kartu
odmítne, dokud předchozí rok nedoplníš nebo nepotvrdíš — i tahle chyba se jen
připočítá do souhrnu, ostatní karty se zaúčtují normálně.

> [!TIP]
> Zaúčtování odpisů spouštěj typicky **jednou ročně** před uzávěrkou období
> (viz [Uzávěrka](87_Uzaverka.md)) — po uzavření období už
> zápis pro daný rok zaúčtovat nejde.

## 78.7 Import a export Excelem

Karty majetku lze **hromadně exportovat** (tlačítko **Export** na seznamu) do
Excelu — soubor obsahuje aktuální stav všech karet firmy podle zadaných
sloupců. Stejným formátem (tlačítko **Import**) lze karty **hromadně
založit nebo aktualizovat** — otevře se stejný importní dialog jako u
ostatních číselníků aplikace (např. [Klienti](18_Klienti.md)), se zobrazením
řádků k importu, případných chyb validace u konkrétních řádků a shrnutím
založených/aktualizovaných karet po dokončení.

> [!TIP]
> Import je praktický při přechodu z jiného účetního systému — historický
> majetek založíš rovnou ve stavu V užívání s vyplněnými poli „daňově/účetně
> odepsáno dosud" ([§ 78.2](#782-zalozeni-a-uprava-karty)), bez nutnosti
> ručně procházet každou kartu editorem.

## 78.8 Omezení a tipy

- Modul Majetek je dostupný jen v režimu **podvojného účetnictví** — v daňové
  evidenci se dlouhodobý majetek eviduje jinak.
- **Sazby a koeficienty** daňových odpisů jsou v systému pevně dané aktuálním
  zněním zákona (poznámka pod plánem odpisů uvádí, ke kterému datu) — při
  legislativní změně je potřeba prostudovat aktuální stav se svou účetní.
- **Vstupní cenu a daňové parametry** po prvním potvrzeném odpisu, resp.
  pořizovací údaje po zařazení do užívání, už nejde měnit — před zaúčtováním
  prvního odpisu zkontroluj kartu pečlivě.
- **Tržba z prodeje majetku se neúčtuje z karty** — vždy je potřeba vystavit
  vydanou fakturu s výnosem 641 (+ DPH). API při vyřazení podporuje její evidenční
  vazbu na kartu, ale současný webový dialog výběr faktury nenabízí; účetní výnos
  v každém případě vzniká z faktury, ne z karty.
- **Technické zhodnocení u §30a (bezemisní vozidla)** vstupní cenu nezvyšuje
  — pro TZ u takového vozidla založ novou kartu.
- **Vrácení vyřazení** funguje, jen dokud je účetní období data vyřazení
  otevřené — po uzávěrce (viz [Uzávěrka](87_Uzaverka.md)) už
  vyřazení vrátit nejde.
- Pro čtenáře bez oprávnění k zápisu (readonly) jsou všechny akce karty
  (založení, editace, zařazení, TZ, vyřazení, zaúčtování) nedostupné — mají
  jen prohlížení seznamu, detailu a exportu.

> [!TIP]
> Než poprvé spustíš hromadné **Zaúčtování odpisů**, projdi si plán odpisů u
> pár karet v záložce Daňové/Účetní — snadno tak odhalíš špatně zadanou
> odpisovou skupinu nebo životnost dřív, než se promítne do zaúčtovaných
> částek.

Samostatná operativní evidence věcí účtovaných přímo do nákladů je popsána v
kapitole [Drobný majetek](27_Drobny_majetek.md). Nezaměňuj ji s kartami
dlouhodobého majetku v této kapitole: drobný majetek nemá daňový ani účetní
odpisový plán.
