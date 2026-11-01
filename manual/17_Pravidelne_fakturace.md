# 17. Pravidelné fakturace

> Návod, jak nastavit šablonu, ze které se faktury vytvářejí samy v pravidelných
> intervalech (paušál, hosting, předplatné, retainer), a jak ji spravovat. Pro
> každého, kdo fakturuje stejnému klientovi opakovaně.

## 17.1 Kdy to potřebujete

- Fakturujete pravidelný měsíční, čtvrtletní, pololetní nebo roční paušál.
- Položky a částky jsou stejné (posun měsíce v popisech řeší jeden přepínač nebo
  placeholdery období).
- Fakturujete tomu stejnému klientovi opakovaně.
- Máte fixní paušál a během měsíce k němu přibývají nepravidelné vícepráce.
- Chcete zjistit, proč se faktura ze šablony nevytvořila.

Pro **jednorázové znovuvystavení** stávající faktury (např. z faktury 5/2026
udělat fakturu 6/2026) slouží klasický **klon faktury** na detailu faktury, ne
pravidelná šablona.

## 17.2 Než začnete

1. **Klient** vytvořený v `Prodej → Klienti`, viz [kapitola 18](18_Klienti.md).
   Pro fakturaci na zakázku také zakázka (viz [kapitola 19](19_Zakazky.md)).
2. **Číselná řada** dodavatele, ze které se přidělují čísla faktur.
3. **Funkční odesílání e-mailů**, pokud chcete faktury odesílat automaticky.
4. **Zapnuté generování cronem** v `Firma → Nastavení`, záložka **Fakturace**, volba **Generovat
   pravidelné fakturace cronem** (výchozí je zapnuto). Při vypnutí se všechny
   šablony dodavatele zastaví, viz [§ 17.8.12](#17812-kill-switch-nastaveni-muj-dodavatel).
5. **Běžící cron** `cron-generate-recurring-invoices.php` (zajišťuje provozovatel,
   viz [§ 17.8.11](#17811-cron)).

## 17.3 Krok za krokem: Vytvořit šablonu

1. Otevřete `Prodej → Pravidelné fakturace` a klikněte na **Nová šablona**. Nebo
   otevřete detail existující faktury a klikněte na **Vytvořit šablonu z této
   faktury** (předvyplní klienta, položky, měnu, jazyk i způsob úhrady).
2. Vyplňte **Název** (např. „Hosting Acme s.r.o.“) a vyberte **Klienta**, případně **Zakázku**.
3. V sekci **Periodicita** zvolte **Periodicitu** (Měsíčně, Čtvrtletně, Pololetně,
   Ročně), **Den v měsíci** (nebo zaškrtněte **Poslední den měsíce**) a **Datum
   prvního vystavení**. Volitelně vyplňte **Datum ukončení**.
4. V sekci **Faktura** nastavte typ dokladu, měnu, jazyk, způsob úhrady, splatnost
   a u plátce DPH režim **DUZP**.
5. Přidejte **Položky**: popis, množství, cena, sazba DPH. Měsíc v popisu můžete
   nechat měnit automaticky (viz [§ 17.8.5](#1785-placeholdery-obdobi)).
6. V sekci **Automatizace** rozhodněte, zda se faktura po vygenerování **rovnou
   vystaví** a zda se **odešle klientovi e-mailem**.
7. Uložte. Šablona se naplánuje na zadané datum prvního vystavení.

**Jak poznáte, že je hotovo:** šablona je v seznamu ve stavu **Aktivní** a má
vyplněné **Příští vystavení**. V den vystavení se v části **Vygenerované faktury**
na detailu šablony objeví první faktura.

> [!TIP]
> Výchozí nastavení (vystavit i odeslat zapnuto, koncept až při vystavení) dává
> plně automatickou fakturaci. Pro první šablonu zapněte nejdřív jen vytváření a
> první fakturu zkontrolujte ručně přes **Vygenerovat koncept**.

## 17.4 Krok za krokem: Paušál s vícepracemi (otevřený koncept)

Řeší fakturaci typu fixní SLA plus nepravidelné vícepráce. Podmínkou je měsíční
periodicita a zapnuté **Po vygenerování rovnou vystavit**.

1. V šabloně nastavte periodicitu **Měsíčně**, zaškrtněte **Poslední den měsíce**
   (nebo zvolte den), zapněte **Po vygenerování rovnou vystavit** a případně i odeslání.
2. V sekci **Automatizace** nastavte **Kdy vytvořit koncept** na **Na začátku
   období (pro průběžný výkaz)**.
3. Volitelně vyplňte **Připomenout dní před vystavením** (kolik dní předem vám
   přijde připomínka, 0 = neposílat).
4. Uložte. Prvního dne fakturovaného měsíce vznikne koncept s fixními položkami.
5. Během měsíce otevřete koncept (`Prodej → Vydané faktury`, stav koncept) a doplňujte
   vícepráce do výkazu práce, viz [editor faktury](15_Faktura_editor.md#1596-vykaz-vicepraci).
6. Nic dalšího nedělejte. Den po konci období se koncept sám uzavře, přepočte
   včetně víceprací, vystaví a odešle. Hned poté se otevře koncept na další měsíc.

**Jak poznáte, že je hotovo:** vystavená faktura nese datum vystavení i DUZP konce
období a obsahuje paušál i vícepráce. V seznamu faktur je už rozpracovaný koncept
dalšího měsíce.

> [!TIP]
> Koncept můžete během měsíce vystavit i ručně. Cron to pozná, v den vystavení už nic
> nevytvoří a jen posune rozvrh na další měsíc.

## 17.5 Krok za krokem: Vygenerovat fakturu ručně

1. Otevřete `Prodej → Pravidelné fakturace` a v řádku šablony (nebo na jejím detailu)
   klikněte na **Vygenerovat teď**, nebo na **Vygenerovat koncept**.
2. U **Vygenerovat teď** zvolte **Datum vystavení** (výchozí je dnes). Při budoucím
   datu se zobrazí žluté varování, že datum vystavení má daňově odpovídat reálnému datu.
3. U šablony v režimu **Až při vystavení** rozhodněte o volbě **Nahradit plánovaný
   termín a posunout plán o jeden interval** (výchozí zapnuto). Dialog ukazuje
   výsledný příští termín.
4. Potvrďte tlačítkem **Vygenerovat**, případně **Vytvořit koncept**.

**Jak poznáte, že je hotovo:** zobrazí se hláška o vygenerované faktuře (u odeslané
i s příjemci), případně o vytvořeném konceptu, a faktura je v části **Vygenerované faktury**.

> [!WARNING]
> Vypnutím volby posunu plánu vznikne **mimořádná faktura** a plán zůstane
> beze změny. Automatika pak v plánovaném termínu vytvoří další fakturu. Počítejte
> s tím, aby klient nedostal fakturu dvakrát.

## 17.6 Krok za krokem: Pozastavit, obnovit a opravit termín

**Pozastavení a obnovení:**

1. V seznamu nebo na detailu šablony klikněte na **Pozastavit**, potvrďte.
   Automatika šablonu přeskakuje (ruční **Vygenerovat teď** dál funguje).
2. Pro pokračování klikněte na **Obnovit**. Zobrazí se datum příští faktury.

**Oprava příštího termínu:**

1. Na detailu šablony otevřete menu **…** a zvolte **Změnit příští vygenerování…**.
2. V poli **Nové datum příštího vygenerování** nejprve datum změňte (předvyplněné je
   aktuální).
3. Zaškrtněte potvrzení, že jste zkontrolovali existující faktury.
4. Klikněte na **Změnit termín**.

**Jak poznáte, že je hotovo:** u šablony je nový **Příští vystavení** a zobrazí se
hláška „Termín příštího vygenerování byl změněn.“

> [!WARNING]
> Změna termínu může přeskočit období nebo způsobit opakované vyfakturování.
> Samotné zaškrtnutí potvrzení datum neobnoví, musíte ho nejdřív změnit.

**Úprava a smazání.** Šablonu změníte tlačítkem **Upravit**, smažete tlačítkem
**Smazat**. Dosud vygenerované faktury zůstanou, jen ztratí vazbu na šablonu.

## 17.7 Když něco nejde

| Co vidíte | Proč | Co udělat |
|---|---|---|
| Červený banner **Poslední automatické generování selhalo** na detailu šablony, odznak **Generování selhalo** v seznamu | Poslední cronové generování selhalo, typicky kvůli vypršelé sazbě DPH nebo nekladné částce. | Opravte příčinu v šabloně (vyberte aktuální sazbu DPH, upravte částku) a klikněte na **Vygenerovat teď**. Po úspěšném generování banner zmizí. |
| Šablona je ve stavu **Vypršela** | Příští termín překročil datum ukončení. Cron ani ruční spuštění ji nespustí. | Prodlužte **Datum ukončení** v šabloně a klikněte na **Obnovit**. |
| Šablona je **Pozastavená** | Někdo ji pozastavil, nebo se ukončená šablona po změně termínu přepnula na pozastavenou. | Klikněte na **Obnovit**. |
| Žádné šablony se nevytvářejí | V `Firma → Nastavení`, záložce **Fakturace**, je vypnuté **Generovat pravidelné fakturace cronem**, nebo neběží cron. | Zapněte volbu, ověřte cron (viz [§ 17.8.11](#17811-cron)). |
| Generování zastaví hláška o změně ceny v ceníku | U ceníkové položky je politika **Při změně vyžadovat kontrolu** a zdrojová cena, jednotka, DPH nebo zdroj ceny se změnily. | V editoru šablony klikněte na **Převzít aktuální údaje**. |
| Položka má štítek **Položka je archivovaná** nebo **Ceníkovou cenu nelze určit** | Ceníková položka byla archivována. | Položku obnovte, nahraďte, nebo ji v šabloně převeďte na **Ručně zadaná položka**. |
| Šablona ukazuje na vypršelou sazbu DPH | Stát změnil sazbu (např. 21 % na 22 %). Vznikl nový řádek sazby a starý má konec platnosti. | Ve šabloně vyberte aktuální sazbu. Doklad se starou sazbou se nikdy nevystaví tiše. |
| Nelze uložit šablonu v režimu **Na začátku období** | Režim je jen pro měsíční periodicitu a vyžaduje automatické vystavení. | Nastavte periodicitu **Měsíčně** a zapněte **Po vygenerování rovnou vystavit**. |
| **Automatické odeslání vyžaduje automatické vystavení.** | Odeslat nelze koncept. | Zapněte nejdřív **Po vygenerování rovnou vystavit**. |
| **Šablona musí mít alespoň jednu položku.** | Šablona nemá žádnou položku. | Přidejte položku. |
| Nepodařilo se změnit příští termín | Nové datum je stejné jako aktuální, před dneškem, po konci platnosti, nebo už pro něj existuje faktura této šablony. U režimu **Na začátku období** je otevřený koncept aktuálního období. | Zadejte jiné datum, případně nejdřív vyřešte otevřený koncept. |
| U položky v režimu OSS chybí stát spotřeby | Bez státu se řádek uloží jako tuzemský. | Vyplňte stát spotřeby. |
| Po skončení registrace do OSS je řádek na faktuře tuzemský a označený k ručnímu posouzení | Uložené OSS rozhodnutí platí jen při platné registraci k datu plnění. | Řádek posuďte ručně, viz [§ 17.8.4](#1784-polozky). |

## 17.8 Podrobnosti a pravidla

### 17.8.1 Sekce Periodicita

- **Periodicita** - Měsíčně, Čtvrtletně, Pololetně, Ročně.
- **Den v měsíci** - 1 až 28 (28 je nejvyšší možná hodnota, omezení kvůli únoru).
- **Poslední den měsíce** - je-li zaškrtnuto, den v měsíci se ignoruje a faktura se
  vystaví vždy poslední den měsíce (28, 29, 30 nebo 31 podle délky měsíce). Hodí se
  pro „vždy poslední den čtvrtletí“.
- **Datum prvního vystavení** - kdy má vyjít první faktura. Po uložení se šablona
  naplánuje rovnou na tento den.
- **Datum ukončení** (volitelné) - po jeho překročení se šablona automaticky
  pozastaví (stav **Vypršela**) a cron ji přeskakuje.

### 17.8.2 Sekce Faktura

Hodinové položky podporují dobu ve formátu `H:MM` a sazbu až na šest desetinných
míst. Doba i sazba se přenášejí do každé vytvořené faktury. Podrobnosti zadávání
času jsou v [editoru faktury](15_Faktura_editor.md#1596-vykaz-vicepraci).

Tato sekce nastavuje metadata, která se zkopírují na každou vygenerovanou fakturu:

- **Typ dokladu** - Faktura nebo Zálohová faktura (proforma).
- **Měna** - určuje bankovní spojení a kurz ČNB (u měn jiných než CZK).
- **Jazyk** - čeština nebo angličtina (jazyk PDF i e-mailu).
- **Vizuální identita** - brandingový profil, který se použije na všech fakturách
  vytvořených ze šablony. Bez výběru se použije **Výchozí identita dodavatele**.
- **Způsob úhrady** - stejné volby jako v editoru faktury (např. Bankovní převod,
  Platební karta, Hotově, Jiný způsob). QR platba se tiskne jen u bankovního převodu.
  U platby kartou a v hotovosti se v PDF ani v e-mailu nezobrazí ani bankovní spojení.
- **Splatnost** - počet dní od vystavení, případně kalendářní měsíce. Volba **Podle
  zákazníka** převezme jednotku z karty zákazníka (a ta případně z výchozího
  nastavení firmy). Kalendářní měsíc: 31. 1. se změní na 28. 2., ne pevných 30 dnů.
- **Sleva z celé faktury** - procentuální sleva (0 až 100 %), kterou zdědí každá
  vygenerovaná faktura. Na faktuře se projeví jako záporná položka „Sleva X %“
  (po sazbách DPH), viz [§ 15.9.4](15_Faktura_editor.md#1594-sumar-vpravo).
- **Kategorie tržby** - pevná kategorie tržby pro všechny faktury z této šablony
  (typicky domény, hosting, licence, paušály). Bez výběru (**dle zakázky / zákazníka**)
  se při generování použije výchozí kategorie zakázky, případně zákazníka, tedy
  hodnota platná **v okamžiku vystavení**. Pevná kategorie šablony naproti tomu drží
  zařazení stabilní i při pozdější změně těchto výchozích hodnot. Kategorie se na
  fakturu ukládá jako snapshot, změna šablony už vygenerované faktury nemění.
- **Ceny s DPH / bez DPH** - režim, ve kterém jsou zadané ceny položek šablony. Režim
  „s DPH“ (brutto) počítá daň shora koeficientem a propisuje se na každou
  vygenerovanou fakturu, viz [§ 15.9.2](15_Faktura_editor.md#1592-hlavicka).
- **DUZP** (plátci DPH) - režim, kterým se počítá datum uskutečnění zdanitelného
  plnění z data vystavení:
    - **Stejné jako datum vystavení** (výchozí) - DUZP = vystavení.
    - **Poslední den předchozího měsíce** - typický český scénář „fakturuji 1. 6.
      za květnové služby“. Faktura má vystavení 1. 6. 2026, ale DUZP 31. 5. 2026.
      Měsíc v popisech položek se synchronizuje k DUZP, takže „Hosting 05/2026“
      zůstane „05/2026“, i když je faktura vystavena 1. 6.

### 17.8.3 Sekce Poznámky a variabilní symbol

Stejná dvě pole jako u běžné faktury: **Poznámka nad položkami** a **Poznámka pod
položkami**. Text se přenáší na každou vygenerovanou fakturu beze změny (tiskne se
nad, resp. pod tabulkou položek). Hodí se na opakované informace typu období
poskytované služby, podmínky pronájmu nebo sdělení pro zákazníka. Obě pole
podporují **placeholdery období** (viz [§ 17.8.5](#1785-placeholdery-obdobi)),
které se vyhodnotí při každém generování vůči DUZP (u proformy vůči datu
vystavení). Např. „Vyúčtování za období {BOM} - {EOM}“ se na faktuře propíše jako
konkrétní rozsah měsíce.

Pod poznámkami je pole **Platební variabilní symbol**. Vyplněný VS dostane každá
vygenerovaná faktura, takže zákazník může platit trvalým příkazem pod stále stejným
symbolem. Číslo faktury se dál přiděluje z číselné řady. Prázdné pole znamená, že se
VS odvodí z čísla faktury (viz [§ 15.9.2](15_Faktura_editor.md#1592-hlavicka)). Jak se takové
platby párují, popisuje [§ 29.2](29_Banka.md).

### 17.8.4 Položky

Položky šablony se kopírují na každou vygenerovanou fakturu (popis, množství,
cena za jednotku, sazba DPH). Sazba se bere podle vybrané sazby ze šablony.

Tlačítkem **+ Textový řádek** vložíte mezi položky řádek jen s textem. Na vygenerovanou
fakturu přejde na stejné místo jako textový řádek, placeholdery období se v něm doplní
stejně jako v popisu položky. Do částek ani DPH nevstupuje, viz
[§ 15.9.3](15_Faktura_editor.md#1593-polozky). Šablona jen z textových řádků fakturu
nevygeneruje.

Šipka na konci řádku otevře účet položky a volitelné **členění DPH** - kód, podle kterého se plnění
dostane na správný řádek přiznání a do kontrolního či souhrnného hlášení. Výchozí
volba **Automaticky podle sazby** ho nechá odvodit při každém generování ze sazby a
měrné jednotky, což stačí u drtivé většiny šablon. Vyplňte ho tam, kde odvození
nemůže uspět - typicky **dodání zboží do jiného členského státu** (kód `20`) u
řádku s jednotkou „ks“, který by se jinak odvodil jako služba (`22`, ř. 21 a kód
plnění 3 v souhrnném hlášení místo ř. 20 a kódu 0). Zvolený kód se přenese na každou
vygenerovanou fakturu. U neplátce DPH a u řádku vykazovaného v režimu OSS se volba
neuplatní, protože tam kód do českého přiznání nepatří.

Řádek může být zadaný ručně, nebo napojený na **ceníkovou položku**. U napojené
položky zvolíte také zdroj popisu a cenovou politiku. Napojení na jednoduchý ceník
je dostupné jen bez aktivního skladu nebo e-shopu. Po zapnutí skladu se napojené
řádky přestanou přeceňovat z ceníku a generování pokračuje z posledního uloženého
snapshotu. Při následném uložení šablony se takový řádek převede na běžnou ruční
položku.

| Politika | Chování |
|---|---|
| **Pevná cena ze šablony** | Při výběru se uloží cena, jednotka, DPH a případný kurz. Pozdější změna ceníku ani zákaznické ceny šablonu nepřecení. |
| **Vždy aktuální cena** | Při každém generování se znovu použije aktuální zákaznická nebo obecná cena. Chybějící měna se při povoleném přepočtu vypočte kurzem k DUZP, u proformy k datu vystavení. |
| **Při změně vyžadovat kontrolu** | Změna zdrojové ceny, jednotky, DPH nebo zdroje ceny zastaví generování. V editoru použijte **Převzít aktuální údaje**. Samotný pohyb kurzu kontrolu nevyžaduje. |

Volba **Popis z ceníku** přebírá aktuální ceníkový popis podle zvolené politiky.
Volba **Vlastní popis šablony** dovolí text upravit nezávisle. Placeholdery období
fungují v obou případech až při vytvoření konkrétní faktury.

Archivovaná položka může dál sloužit pevnému snapshotu. Politiky používající
aktuální údaje skončí s chybou, dokud položku neobnovíte, nenahradíte nebo
nepřevedete na ruční položku. Změnu měny nebo režimu s/bez DPH nelze u pevného
snapshotu uložit bez jeho výslovného obnovení.

> [!WARNING]
> **Změna sazby DPH státem.** Sazba je v šabloně přišpendlená na konkrétní řádek
> číselníku. Když se sazba změní (např. 21 % na 22 %), vznikne v číselníku nový
> řádek a starý dostane konec platnosti. Šablona pak ukazuje na vypršelou sazbu,
> generování se **zastaví s jasnou chybou** (banner v [§ 17.7](#177-kdyz-neco-nejde))
> a vy ve šabloně vyberete aktuální sazbu. Tím se nikdy tiše nevystaví doklad se
> starou sazbou. Totéž hlídá klonování faktury.

**Neplátce DPH.** Je-li dodavatel neplátce, pravidelná fakturace se chová stejně
jako jednorázové vystavení: výběr sazby DPH se v šabloně skryje a každá
vygenerovaná faktura je bez DPH (0 %, Osvobozeno). Pokud šablona obsahuje nominální
sazbu, generátor ji při vystavení sám sjednotí na 0 %.

#### Režim OSS na položce šablony

Má-li firma [zapnutý režim OSS](45_OSS.md#453-krok-za-krokem-zapnuti-oss-a-prvni-nastaveni),
je u každého řádku šablony zaškrtávátko **OSS**. Po zaškrtnutí se pod řádkem otevře
proužek se **státem spotřeby**, **typem sazby** a **typem plnění**, přesně jako na
řádku faktury. Sazba DPH pak nabízí i sazby cizích států, aby OSS řádek mohl nést
sazbu státu spotřeby; tuzemský řádek zůstává u českých sazeb.

Co šablona záměrně nemá: kurz, přepočtené částky ani „opravu období“. To jsou
vlastnosti konkrétního dokladu k jeho datu plnění a dopočítá je až generátor.

- **Stát spotřeby je povinný.** Bez něj se řádek uloží jako tuzemský - položku s OSS
  a bez země by cron při každém běhu vyrobil neplatnou. Formulář to zachytí ještě
  před uložením.
- **Prázdný typ sazby** je legitimní stav. Při generování se ho systém pokusí doplnit
  z číselníku, ale jen tehdy, když odvození mluví o **témže státu spotřeby**. Jinak
  řádek do OSS přiznání nepůjde.

> [!WARNING]
> Šablona žije roky, registrace do OSS ne. Uložené OSS rozhodnutí má při generování
> přednost před automatickým odvozením, ale **jen pokud má firma k datu plnění
> vygenerované faktury platnou registraci do OSS**. Jakmile registrace skončí (nebo
> se režim vypne), řádek se vystaví jako **tuzemský** a povinně dostane příznak **k
> ručnímu posouzení** - přeřazení proti rozhodnutí člověka nesmí být tiché. Bez
> toho by řádek nespadl do žádného přiznání: z OSS podání by ho vyřadila platnost
> registrace, z tuzemského přiznání OSS příznak. Podrobně
> [§ 45.4.5](45_OSS.md#rozdily-mezi-kanaly).

### 17.8.5 Placeholdery období

Do popisu položky (a do poznámek nad a pod položkami šablony) lze vložit tokeny,
které se při **každém vygenerování** faktury nahradí podle **DUZP** (u proformy
podle data vystavení). Šablona se nikdy nemění, do faktury jde vyhodnocený text.
Inline přehled je přímo v editoru šablony (rozbalovací nápověda **Placeholdery
období v popisech položek** nad položkami). Příklady pro DUZP 15. 5. 2026:

| Token | Výsledek | Poznámka |
|---|---|---|
| `{YYYY}`, `{YY}` | 2026, 26 | rok; posun po letech: `{YYYY+1}` → 2027, `{YY-1}` → 25; rok měsíce posunutého o N měsíců: `{YYYY+8M}` → 2027 (patří k `{MMMM+8}`, `{M+8}`) |
| `{M}`, `{MM}` | 5, 05 | měsíc; posun po **měsících** vč. přetečení roku: `{MM+8}` → 01 |
| `{MMMM}` | květen | název měsíce **podle jazyka dokladu** (čeština, angličtina); `{MMMM+1}` → červen |
| `{Q}` | 2 | čtvrtletí 1-4; posun po čtvrtletích: `{Q+1}` → 3 |
| `{D}`, `{DD}` | 15, 15 | den; posun po dnech: `{D+14}` → 29 |
| `{DATE}` | 15. 5. 2026 | celé referenční datum, formát podle jazyka dokladu (angličtina: May 15, 2026) |
| `{DATE+1Y-1D}` | 14. 5. 2027 | datová aritmetika - kombinace `±N` jednotek `D`/`M`/`Y`, zleva doprava |
| `{BOM}`, `{EOM}` | 1. 5. 2026, 31. 5. 2026 | začátek a konec měsíce (celé datum); posun po měsících: `{EOM+1}` → 30. 6. 2026, `{EOM-1}` → 30. 4. 2026 |

Typický příklad (prodloužení domény na rok):

```text
Prodloužení domény example.cz na období {DATE} - {DATE+1Y-1D}
→ Prodloužení domény example.cz na období 15. 5. 2026 - 14. 5. 2027
```

Další ukázky: `sezóna {YY}/{YY+1}` → „sezóna 26/27“, `servis {Q}Q/{YYYY}` → „servis
2Q/2026“, `úklid za {MMMM} {YYYY}` → „úklid za květen 2026“, `služby za období {BOM} -
{EOM}` → „služby za období 1. 5. 2026 - 31. 5. 2026“, `nájem na měsíc {MMMM+1}
{YYYY+1M}` → „nájem na měsíc červen 2026“ (v prosinci „leden“ s následujícím rokem).

> [!TIP]
> **Přetečení měsíce je ošetřené.** Posun po měsících a letech v `{DATE±…}` se
> ořezává na poslední den cílového měsíce (jako MySQL `DATE_ADD`): 31. 1.
> `{DATE+1M}` → **28. 2.** (ne 3. 3., jak by dalo holé PHP), 29. 2. 2028 `{DATE+1Y}`
> → 28. 2. 2029. Posun po dnech (`{DATE+30D}`) zůstává exaktní. Měsíční tokeny
> (`{M}`, `{MMMM}`, `{EOM}`…) jsou kotvené na měsíc, takže přetečení u nich nehrozí
> vůbec (31. 1. `{M+1}` → 2).

Tokeny se píší **velkými písmeny**. Cokoli nerozpoznaného (`{foo}`, `{yyyy}`,
obyčejné závorky v textu) zůstává beze změny, takže existující šablony fungují
dál a nic není potřeba escapovat. Placeholdery fungují nezávisle na volbě
**Synchronizovat měsíc**, lze je kombinovat (obojí míří na stejné referenční datum).

### 17.8.6 Sekce Automatizace

- **Synchronizovat měsíc v popiscích položek s DUZP** - je-li v popisu vzorec `M/YYYY`
  (např. „Hosting 03/2026“), automaticky se nahradí měsícem a rokem z DUZP generované
  faktury, případně z data vystavení u proform, které DUZP nemají. Synchronizace je
  idempotentní: popis „Hosting 03/2026“ vygeneruje „Hosting 05/2026“, spadá-li DUZP do
  5/2026, a „Hosting 06/2026“, spadá-li do 6/2026, bez kumulativního driftu. Detektor
  zvládá `M/YYYY`, `YYYY-MM`, `M.YYYY`, `M-YYYY` a varianty; plná data typu
  `2026-05-15` chrání před změnou. Přednostně používejte placeholdery období
  (explicitnější, `{MM}/{YYYY}`, umí víc: roky, čtvrtletí, celá data); synchronizace
  se hodí pro šablony s prostým `M/YYYY` v textu.
- **Po vygenerování rovnou vystavit (přidělit číslo faktury)** - cron rovnou přidělí
  číslo z číselné řady dodavatele a zafixuje snapshoty klienta, dodavatele a
  bankovního spojení (stav Vystaveno). Pokud volbu vypnete, vygeneruje se jen koncept
  a vy ho musíte ručně zkontrolovat a vystavit.
- **Po vystavení rovnou odeslat klientovi e-mailem** - automatické odeslání PDF a
  e-mailu na klienta a fakturační e-maily zakázky. Vyžaduje předchozí volbu (koncept
  odeslat nelze).
- **Kdy vytvořit koncept** - viz [§ 17.8.7](#1787-otevreny-koncept-prubezny-vykaz-vicepraci).
- **Připomenout dní před vystavením** - jen v režimu „Na začátku období“: počet dní
  předem, kdy vám přijde e-mailová připomínka doplnit vícepráce (0 = neposílat).

Výchozí nastavení šablony má obojí (vystavit i odeslat) zapnuté a režim konceptu
**Až při vystavení**, tedy plně automatickou pravidelnou fakturaci.

### 17.8.7 Otevřený koncept (průběžný výkaz víceprací)

Řeší fakturaci typu fixní SLA plus nepravidelné vícepráce: část faktury je stálý
paušál, ke kterému během měsíce přibývá proměnný seznam víceprací.

Přepínač **Kdy vytvořit koncept** má dvě hodnoty:

- **Až při vystavení** (výchozí) - faktura vznikne až v den vystavení a podle
  automatizace se rovnou vystaví.
- **Na začátku období** - cron vytvoří **koncept** faktury (s fixními položkami ze
  šablony) **1. den fakturovaného měsíce**. Koncept pak celý měsíc zůstává ve stavu
  koncept a vy do něj průběžně píšete **vícepráce přes výkaz práce** (výkaz je
  editovatelný jen u konceptu). **Den po** plánovaném termínu vystavení (typicky 1.
  den dalšího měsíce) cron koncept automaticky **uzavře, přepočítá včetně
  víceprací, vystaví a odešle**. Uzávěrka se posune o den za konec období schválně,
  aby se do faktury stihla započítat i práce z posledního dne období.

Datum vystavení i DUZP konceptu jsou od začátku nastavené na **plánovaný konec
období** (plánovaný termín plus zvolený režim DUZP) a při vystavení se nemění.
Faktura nese datum konce období, i když fyzicky vznikla o den později.

**Podmínky režimu Na začátku období:**

- jen pro **měsíční** periodicitu,
- vyžaduje zapnuté **Po vygenerování rovnou vystavit** (koncept se na konci období
  uzavře sám).

**Typický scénář (fakturace za červen, vystavení a DUZP ke konci měsíce):**

1. Šablona: měsíčně, **Poslední den měsíce**, DUZP **Stejné jako datum vystavení**,
   režim konceptu **Na začátku období**, vystavit i odeslat zapnuto.
2. **1. 6.** cron otevře koncept s fixním SLA řádkem (datum vystavení i DUZP = 30. 6.).
3. **Během června** doplňujete vícepráce do výkazu práce na tom konceptu.
4. **29. 6.** (1 den předem) vám přijde e-mailová připomínka; **30. 6.** zůstává
   koncept otevřený, takže do něj stihnete zapsat i práci z posledního dne.
5. **1. 7.** cron v jednom běhu nejdřív **uzavře červnový koncept** - vystaví (SLA a
   vícepráce) a odešle klientovi, faktura nese datum vystavení i DUZP **30. 6.** - a
   hned poté **otevře nový koncept na červenec** (datum vystavení i DUZP 31. 7.), takže
   do něj můžete zase celý měsíc psát.

**Zakázka se schvalováním výkazu zákazníkem:** automatické uzavření konceptu
schválení výkazu nekontroluje. Cron koncept na konci období vystaví (a podle
nastavení odešle) i tehdy, když zakázka vyžaduje schválení a výkaz schválen nebyl;
blokaci „Zakázka vyžaduje schválení výkazu zákazníkem“ hlídá jen ruční tlačítko
**Vystavit** a ruční vystavení tedy bez schválení neprojde. Chcete-li schválení
zachovat, nepoužívejte u takové zakázky pravidelnou fakturaci s automatickým
vystavením; zvolte režim **Až při vystavení** bez volby **Po vygenerování rovnou
vystavit** a výkaz nechte schválit v běžném postupu (viz
[§ 19.5](19_Zakazky.md#195-krok-za-krokem-schvalovani-vykazu-zakaznikem)).

Pokud koncept během měsíce vystavíte ručně, cron to pozná a v den vystavení už nic
nevytvoří, jen posune rozvrh na další měsíc.

### 17.8.8 Lifecycle šablony

Šablona má tři stavy:

- **Aktivní** - cron ji každý den kontroluje; jakmile je příští termín dnešní nebo
  starší, vygeneruje fakturu a posune termín o jeden cyklus.
- **Pozastavená** - cron ji přeskakuje (ruční **Vygenerovat teď** dál funguje).
- **Vypršela** - příští termín překročil datum ukončení; cron i aplikace ji odmítají
  spustit, dokud datum ukončení nezvýšíte.

V seznamu a na detailu šablony jsou tlačítka **Pozastavit / Obnovit**, **Vygenerovat
teď** a **Vygenerovat koncept** (jednorázové ruční spuštění, užitečné pro testování
i pro ruční vytvoření dokladu mimo rozvrh). Pole **Hledat** nad seznamem prochází
název šablony, název a e-mail klienta, text položek a pevný variabilní symbol
šablony. Kombinuje se s filtrem stavu i řazením a dotaz zůstane zachovaný i po
návratu z detailu šablony. Seznam lze řadit podle data vystavení, zákazníka (A-Z) a
částky (CZK, sestupně).

- **Vygenerovat teď** respektuje nastavení šablony: při zapnutém automatickém
  vystavení fakturu rovnou vystaví (a případně odešle). Otevře okno s výběrem data
  (výchozí dnešní). U budoucího data upozorní žlutým varováním, že daňově by datum
  vystavení mělo odpovídat reálnému datu vystavení.
- **Vygenerovat koncept** vytvoří **koncept** i u šablony s automatickým vystavením
  (nevystaví, neodešle), ručně ho pak zkontrolujete a vystavíte. U režimu **Na
  začátku období** vytvoří přesně ten koncept, který by jinak otevřel cron 1. dne
  (idempotentně, k plánovanému datu, bez posunu rozvrhu), takže se datum nevybírá a
  varování o budoucím datu se nezobrazuje (budoucí DUZP je tu záměr, koncept se
  edituje celý měsíc).

Při selhání automatického generování se poslední chyba uloží a zobrazí jako červený
banner **Poslední automatické generování selhalo** na detailu šablony a odznak
**Generování selhalo** v seznamu. Po úspěšném (ručním i cronovém) vygenerování banner zmizí.

### 17.8.9 Ruční generování a plán

U režimu **Až při vystavení** dialog nabízí volbu **Nahradit plánovaný termín a
posunout plán o jeden interval** (výchozí zapnuto). Další termín se počítá
z plánovaného data, nikoli z data ručně vytvořené faktury. Například roční šablona
s termínem 1. 2. 2027 přejde na 1. 2. 2028 i při ručním vystavení v září 2026.
Vypnutím volby vytvoříte **mimořádnou fakturu** a plán zůstane na 1. 2. 2027. Dialog
před potvrzením ukazuje výsledný příští termín. Pokud je další termín po konci
platnosti šablony, dialog upozorní, že šablona bude ukončena.

Režim **Na začátku období** dál pracuje s plánovaným konceptem; mimořádné generování
bez posunu plánu v něm není dostupné.

### 17.8.10 Oprava příštího termínu

Dialog **Změnit příští vygenerování…** ukáže aktuální datum, nové datum a upozornění
na přeskočení nebo opakované vyfakturování. Pole nového data je předvyplněné
aktuálním příštím termínem: nejprve ho změňte a potom potvrďte kontrolu existujících
faktur zaškrtnutím. Samotné zaškrtnutí datum neobnovuje. Při stejném nebo
neplatném datu dialog zobrazí důvod, proč změnu nelze uložit. Původní faktury, datum
prvního vystavení a historie zůstanou zachované. Další cykly se odvozují od nového
termínu podle intervalu a pravidla dne v šabloně.

Datum musí být nejdříve dnešní a v rozsahu platnosti šablony. Pro cílové datum nesmí
existovat faktura této šablony. U režimu **Na začátku období** nejprve vyřešte
otevřený koncept aktuálního období. Aktivní šablona zůstane aktivní, pozastavená
pozastavená; ukončená se přepne na pozastavenou a její generování je třeba
samostatně obnovit. Dnešní termín aktivní šablony může zpracovat nejbližší běh
automatiky.

### 17.8.11 Cron

Skript `api/bin/cron-generate-recurring-invoices.php` spouštějte **jednou denně**:

```cron
0 6 * * * cd /var/www/myucto.cz && php api/bin/cron-generate-recurring-invoices.php
```

Pro testy se hodí `--dry-run` (vypíše, co by se vygenerovalo, ale nic nevytvoří).

Cron v jednom běhu zvládá tři fáze:

1. **Otevření konceptu** - u šablon v režimu **Na začátku období**, kde už začalo
   fakturované období, vytvoří koncept (idempotentně, jednou za období).
2. **Vystavení** - u šablon po příštím termínu vystaví (režim **Na začátku období**
   uzavře otevřený koncept **den po** konci období; ostatní režimy vygenerují a
   vystaví přímo v termínu). V režimu **Na začátku období** cron hned po uzávěrce
   **rovnou otevře koncept dalšího období**, pokud už začalo, takže 1. den měsíce
   proběhne „uzavři minulé, otevři nové“ v jednom běhu.
3. **Připomínka** - pošle e-mailové připomínky k otevřeným konceptům, kterým se
   blíží vystavení (viz **Připomenout dní před vystavením**).

**Catch-up:** pokud cron několik dní nešel, generuje jen **jednu** fakturu za cyklus
a posune termín o jeden krok, zbytek backlogu se doplní postupně další dny. Tím se
zabrání tomu, aby po výpadku cron vygeneroval naráz 30 faktur za poslední měsíc.

### 17.8.12 Kill-switch (Nastavení, Můj dodavatel)

V `Firma → Nastavení`, záložce **Fakturace**, je přepínač **Generovat pravidelné fakturace cronem**.
Pokud je vypnutý, cron tohoto dodavatele úplně přeskočí a všechny šablony se
zastaví, dokud ho zase nezapnete. Ruční tlačítko **Vygenerovat teď** funguje nezávisle.

### 17.8.13 Vazba na vygenerované faktury

Každá faktura vytvořená šablonou si pamatuje, z které šablony pochází. V detailu
faktury se zobrazí štítek **Pravidelná** s odkazem **Otevřít šablonu**. Na detailu
šablony je část **Vygenerované faktury** s počtem, částkami a součtem (více měn
přepočtených na CZK dnešním kurzem ČNB). Když šablonu smažete, vygenerované faktury
zůstanou platné, jen se vazba vyčistí.

### 17.8.14 Activity log

Zaznamenává se vše:

- vytvoření, úprava a smazání šablony,
- pozastavení a obnovení,
- otevření konceptu cronem na začátku období,
- vygenerování faktury cronem nebo tlačítkem **Vygenerovat teď** (s údaji o faktuře,
  příštím termínu, automatickém vystavení a odeslání, příjemcích),
- odeslání připomínky k otevřenému konceptu,
- souhrn jednoho běhu cronu (počet otevřených, vygenerovaných, vystavených,
  odeslaných, připomínek a chyb).

### 17.8.15 REST API

Pravidelné fakturace mají vlastní REST endpointy pod `/api/recurring/*`:

| Endpoint | Akce |
| --- | --- |
| `GET    /api/recurring` | seznam (filtry: `client_id`, `status`, `q`) |
| `POST   /api/recurring` | vytvořit šablonu |
| `GET    /api/recurring/{id}` | detail |
| `PUT    /api/recurring/{id}` | úprava |
| `DELETE /api/recurring/{id}` | smazat |
| `POST   /api/recurring/{id}/pause` | pozastavit |
| `POST   /api/recurring/{id}/resume` | obnovit |
| `POST   /api/recurring/{id}/run-now` | ruční spuštění (volitelně `issue_date`) |

Podrobná schémata najdete v aplikačních rozhraních [`/api/reference`](/api/reference)
(Redoc) nebo [`/api/docs`](/api/docs) (Swagger UI, Try it out).

## 17.9 Související kapitoly

- [14. Faktury](14_Faktury.md) - seznam vystavených faktur
- [15. Editor faktury](15_Faktura_editor.md) - položky, DPH, výkaz víceprací
- [16. Faktura - PDF a odeslání](16_Faktura_PDF.md) - odeslání a web faktura
- [18. Klienti](18_Klienti.md) - klient a jeho kontakty
- [19. Zakázky](19_Zakazky.md) - fakturace na zakázku
- [45. OSS](45_OSS.md) - režim One Stop Shop
