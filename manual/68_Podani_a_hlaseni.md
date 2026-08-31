# Podání a hlášení

## 68.1 Účel

Agenda připravuje vybraná mzdová hlášení, provádí formální kontroly a vede uživatele přes ručně spuštěné odeslání. Pokrývá zejména podporované toky ČSSZ a zdravotních pojišťoven; vytvořený soubor ani datová zpráva nejsou samy o sobě potvrzením věcného přijetí.

## 68.2 Předpoklady a oprávnění

Je nutné oprávnění `payroll.submissions`, způsobilý uzavřený běh nebo schválená revize, úplné identifikátory a správně oddělené TEST/produkční prostředí. Pro ČSSZ TEST použijte pouze testovací profil a certifikát v určeném bezpečném úložišti. ISDS musí být nastaveno pro správnou firmu a prostředí.

## 68.3 Krokový postup

1. Otevřete **Mzdy → Podání a hlášení**, vyberte typ, období a schválenou revizi.
2. Spusťte náhled nebo kontrolní přípravu a odstraňte blokující chyby. Řádné JMHZ vzniká ze způsobilé běžné revize. Opravu nebo storno již připraveného JMHZ založte z jeho historie řízenou akcí; nejde o ruční nepodporovaný scénář.
3. Vytvořte výstup. U zdravotního PPZ i HOZ aplikace podle pojišťovny připraví podporovaný XML nebo vytěžitelný PDF; XDP není odesílaný formulář. Kde je doložený úřední tiskopis, vyplní se rovnou on, jinak vznikne vlastní čitelná sestava s uvedeným důvodem. Životní události, které datová věta nepokrývá, dokončete ručně na oficiálním kanálu.
4. Pro ISDS stiskněte **Odeslat přes ISDS**. Aplikace vytvoří záznam v outboxu a provede předběžnou kontrolu, ale zprávu sama neodešle.
5. Otevřete koncept na oficiálním rozhraní ISDS, přihlaste se metodou, kterou ISDS v daném prostředí skutečně nabídne, zkontrolujte adresáta a přílohy a odeslání výslovně potvrďte.
6. Alternativně použijte podporovaný profil VREP pro ČSSZ. Přihlašovací a certifikační údaje zadávejte jen do určených polí, nikdy do poznámek.
7. Inbox načítejte pouze ručně. Před načtením vždy potvrďte, že rozumíte tomu, že přístup ke zprávě může způsobit její doručení. Potom přiřaďte odpověď k podání a ověřte věcný výsledek.

## 68.4 Stavy

Návrh čeká na doplnění, připravené podání prošlo lokální kontrolou, outbox čeká na uživatelskou akci a koncept čeká na potvrzení v ISDS. Odesláno popisuje transport. Doručeno dokládá doručení datové zprávy, nikoli přijetí obsahu institucí. Přijato, odmítnuto nebo vyžaduje opravu určete až z doručenky, odpovědi či stavu cílového systému.

## 68.5 Kontroly a bezpečnost

Odesílací brána přesměruje uživatele na oficiální rozhraní ISDS. Podle nabídky
konkrétního účtu tam lze použít například jméno a heslo, heslo aplikace
s bezpečnostním klíčem eGovernmentu, Mobilní klíč eGovernmentu, SMS,
uživatelský certifikát nebo Identitu občana. MyÚčto údaje z této přihlašovací
stránky nevidí a zpráva odejde až po výslovném schválení konceptu uživatelem.

**Brána je jednosměrná.** Umí vložit koncept k odeslání, ale schránku číst
neumí — ke stažení zpráv by potřebovala přihlášení, které vzniká jen tím, že se
uživatel sám přihlásí v perimetru ISDS. Doručenku odeslaného podání proto
stáhněte v datové schránce a nahrajte ji k podání ručně; do té doby zůstane
u podání jako neověřená.

Datovou schránkou z mezd chodí přehledy a hlášení zdravotním pojišťovnám,
měsíční hlášení zaměstnavatele ČSSZ a součinnost exekutorům. **Daňová podání
jdou přes EPO**, ne datovkou; podání odeslané datovkou nedostane potvrzení
s podacím číslem, jen dodejku.

Vedle brány umí MyÚčto odeslat datovou zprávu i přímo, ale výhradně v **živé
relaci, kterou uživatel právě sám schválil** Mobilním klíčem eGovernmentu nebo
SMS kódem. Systémový certifikát firmy ani uložené heslo odesílání neotevírají a
vypršelá relace se sama neobnovuje. Podrobnosti a chování při chybě popisuje
kapitola
[Datová schránka](93_Datova_schranka.md#9342-odeslani-primo-z-aplikace-v-relaci-mobilniho-klice).

Ruční načtení inboxu v **Firma → Datová schránka** má v aplikaci čtyři volby:

- **Mobilní klíč eGovernmentu** — jméno, komunikační kód (heslo aplikace)
  a potvrzení konkrétní relace v klíči;
- **jméno a heslo** — pouze pro jeden synchronní požadavek;
- **SMS** — zahájení jménem a heslem a následné dokončení jednorázovým SMS
  kódem;
- **firemní certifikát** — šifrovaně uložený pouze u právě zvolené firmy.

Technická dostupnost metody není tvrzením o její právní vhodnosti pro konkrétní
organizaci. Heslo a SMS kód se trvale neukládají; uložený profil Mobilního
klíče je oddělen podle firmy, uživatele a prostředí a lze jej odstranit.
Každé přihlášení, vytvoření konceptu, odeslání a načtení inboxu musí vědomě
spustit uživatel. Před načtením inboxu navíc vždy výslovně potvrdí, že rozumí
možnému účinku doručení a spuštění lhůt.

## 68.6 Časté chyby

- Považování XML, PDF nebo záznamu outboxu za odeslané podání.
- Záměna testovacího certifikátu či adresáta za produkční.
- Odeslání řádného JMHZ bez schválené běžné revize nebo založení opravy či storna bez vazby na způsobilé předchozí podání.
- Považování doručenky za věcné přijetí bez kontroly odpovědi.
- Automatické nebo neuvážené otevření inboxu bez potvrzení možného účinku doručení.
- Uložení hesla, SMS kódu či privátního klíče do poznámky nebo evidence podání.

## 68.7 Návaznosti

Identifikátory nastavte v [Nastavení mezd](73_Nastaveni_mezd.md). Firemní přístupy, ruční inbox a odchozí zprávy popisuje kapitola [Datová schránka](93_Datova_schranka.md), globální registraci pro odesílání správcem systému pak [Odesílací brána ISDS](94_Odesilaci_brana_ISDS.md). Zdrojová data pocházejí z [mzdového běhu](63_Mzdove_behy.md); kontrolní soubory a doručenky uchovávejte podle [retenčních lhůt](76_Retencni_lhuty.md).



## 68.8 Podrobný tok podání

V **Mzdy → Nastavení mezd → Podání** nejprve potvrď evidenční profil pro
REGZEL. Zadej čtyřmístný kód finančního úřadu `kodFU` z číselníku finanční
správy a čtyřmístný kód jeho územního pracoviště `kodPracovisteFU`. Kód
pracoviště smí zůstat prázdný jen u Specializovaného finančního úřadu
(`kodFU` 4000). Nejde o
tříčíselný kód EPO, například 451; aplikace tyto dva číselníky záměrně
neslučuje. Pokud správce daně firmě přidělil vlastní číslo plátce (VČP),
zadej jeho devět číslic začínajících `6`. VČP není registrační číslo
zaměstnavatele ani desetimístný variabilní symbol ČSSZ; bez skutečného
přidělení zůstává prázdné. Samostatně se eviduje, zda je zaměstnavatel sociálním podnikem,
agenturou práce nebo zaměstnavatelem na chráněném trhu práce. Potvrzení se
vztahuje i na nezaškrtnuté hodnoty; při každém uložení je proto nutné znovu
výslovně potvrdit, že byly ověřeny kódy, případné VČP i všechny tři příznaky.

V **Mzdy → Podání a hlášení** lze připravit doplňující údaje zaměstnavatele
`REGZELDOPL25` podle lokálně připnutého oficiálního XSD. Vyber produkční nebo testovací prostředí a
konkrétní aktivní mzdovou účtárnu. Prostředí jsou striktně oddělená:
test vyžaduje fiktivní desetimístný variabilní symbol začínající `999`,
zatímco produkce jej odmítne. Před každou přípravou XML znovu potvrď aktuálnost
prostředí, účtárny, identifikátorů i evidenčních příznaků.

Příprava vytvoří neměnný šifrovaný snapshot a XML ověří proti lokálně
připnutému oficiálnímu XSD. Historie se filtruje podle právě vybraného
prostředí a XML lze znovu stáhnout. Při stažení aplikace ověří šifrovaný zdroj,
tenant, prostředí, XSD i kryptografický otisk výsledného XML.

Tato funkce XML pouze připraví a stáhne. Neodesílá je a neoznačuje registraci
za přijatou. Prvotní registrace zaměstnavatele, přidání nebo ukončení účtárny
a opravné scénáře nejsou bez odpovídajícího oficiálního XSD dostupné.

Záložky **JMHZ** a **Zdravotní pojišťovny** zobrazují za vybraný měsíc skutečný
přehled evidovaných povinností, termínů, kanálů a posledních stavů podání.
Produkční a testovací prostředí zůstávají oddělená. Přehled je pouze
kontrolní; samotný řádek povinnosti ani stažení náhledu nikdy neznamená, že bylo
podání odesláno nebo přijato. Běžné měsíční JMHZ má navíc řízené odeslání přes
ISDS nebo VREP a stav **Přijato** získá teprve z ověřeného protokolu ČSSZ.

Záložka **JMHZ** ukazuje všechny povinnosti vůči ČSSZ, tedy vedle měsíčního
hlášení i registrace zaměstnance a zaměstnavatele, evidenční list důchodového
pojištění a oznámení o zaměstnání osoby pobírající starobní důchod.

### 68.8.1 Registrace zaměstnance PREZEC a REGZEC

Test registrace zaměstnance čte identitu z osobní karty účinnou přesně k datu
nástupu pracovního vztahu. V **Mzdy → Zaměstnanci → Úplná osobní evidence a
historie → Identita a adresy** rozbal u příslušné verze jména část **Údaje pro
registraci zaměstnance** a doplň datum a místo narození, stát narození, státní
občanství, pohlaví a případné tituly. Občanství rozhoduje také o tom, zda lze
použít omezenou předregistraci PREZEC, nebo je potřeba úplná registrace REGZEC.
Náhled i následné zmrazení používají stejný historický zdroj a stejné kontroly;
pozdější změna osobní karty už nemění dříve zmrazené podání.

Úplnou registraci REGZEC s akcí A1 aplikace nepřipraví ani neodešle, dokud
nemá zmrazený povinný druh činnosti a úplnou datovou sadu odpovídající varianty
OST, 10 nebo SPEC. Navazující akce A5 až A8 jsou dostupné pouze pro variantu
OST; u variant 10 a SPEC je aplikace odmítne ještě před schválením události.

Úplný podklad zadáte na kartě pracovního vztahu v části **Registrace vztahu na
ČSSZ → Autoritativní profil REGZEC A1**, tlačítkem **Doplnit profil**. Profil
obsahuje rozhodné datum a druh činnosti, trvalou adresu, variantní údaje
pracovního místa a podle situace také daňovou rezidenci, zdravotní pojišťovnu,
vzdělání, důchodové skutečnosti a údaje cizince. Vyplňujte pouze údaje doložené
personálními podklady. Server před uložením zkontroluje variantu OST, 10 nebo
SPEC a všechny její povinné vazby; neúplný profil neuloží. Každé úspěšné uložení
vytvoří novou šifrovanou verzi, starší verzi nepřepisuje. Náhled a podání pak
zmrazí přesné ID verze i její otisk, takže pozdější oprava profilu už hotové
podání nezmění.

Profil se vyplňuje **formulářem rozděleným do sekcí** (trvalý pobyt, adresa
pobytu v ČR, kontaktní adresa, daňová rezidence, pracovní vztah, zdravotní
pojištění, důchod, zahraniční legislativa, doklad totožnosti, přístup na trh
práce a přílohy). Které sekce se zobrazí, určuje varianta podání a občanství:
u varianty 10 odpadá daňová rezidence, zdravotní pojišťovna i doplňující
skutečnosti, u varianty OST naopak přibývá kontaktní adresa, důchod, zahraniční
legislativa a vzdělání, a u cizince navíc doklad totožnosti a přístup na trh
práce. Variantu aplikace odvodí z druhu činnosti a bližšího určení vztahu a
napíše ji nad formulář; ručně se nevolí. Úplný JSON zůstal dostupný jako
read-only náhled **Zobrazit, co odesíláme**.

Server **předvyplní, co o osobě a vztahu ví k datu nástupu**, a u každé takové
hodnoty napíše drobným písmem, odkud pochází: z adres osoby, ze zákonné evidence,
z identifikátorů, z identity, z pracovního oprávnění, nebo ze sjednaných
podmínek vztahu. Účetní tedy hodnoty **potvrzuje, nepřepisuje**. Dvě odvození
stojí za zapamatování: adresa bydliště ve státě rezidence se převezme z trvalé
adresy jen tehdy, když se země shodují, a zdravotní pojišťovna se předvyplní jen
tehdy, je-li v evidenci označená jako ověřená. Jinak se obojí hlásí jako
chybějící.

Chybějící údaje se hlásí konkrétně, nikoli domýšlejí. Nahoře je souhrn **Co
aplikace o osobě nevede** a u každého dotčeného pole je místo zdroje žlutá
poznámka, **která rovnou říká, kde se údaj doplňuje** - třeba na kartě osoby
v Adresách nebo v Zákonné evidenci, případně na kartě vztahu. Údaje, které
aplikace nevede vůbec (číslo popisné zvlášť, typ a číslo dokladu totožnosti,
typ zahraničního daňového identifikátoru, postavení zaměstnance, režim práce,
vzdělání, průkaz osoby se zdravotním postižením, důchodové údaje a povolení
k práci), o sobě řeknou právě to a vyžádají si ruční opis z personálního
podkladu. Průkaz OZP pro registr přitom není totéž co sleva ZTP/P z daňových
nároků; aplikace je vědomě nezaměňuje.

> ⚠️ Pozor: **uložení profilu nikdy nezapíše nic do karty osoby ani do karty
> pracovního vztahu.** Profil je snímek k datu registrace, ne editor kmenových
> dat. Změníte-li tedy v profilu například adresu, opravili jste podklad
> k registraci, nikoli evidenci osoby - tu je potřeba opravit zvlášť. Z téhož
> důvodu se snímek sám neaktualizuje, když se kmenová data později změní;
> rozdíl se jen ukáže v bloku **Snímek se rozešel s kmenovými daty** s výpisem
> „ve snímku X, v kmenových datech Y". Ukládá se jedním tlačítkem **Uložit
> ověřenou verzi** za celý profil; opakované uložení beze změny novou verzi
> nezaloží. Tlačítkem **Vrátit návrh z kmenových dat** se formulář vrátí
> k předvyplněnému stavu.

Při ukončovací akci REGZEC A2 aplikace prověří také všechna dotčená období od
ledna 2026 do měsíce skončení. Pokud byla mzda za některý měsíc opravena,
vyžaduje aktuální schválenou opravnou revizi, skutečně dokončený přenos jejího
JMHZ, shodnou korelaci důvěryhodné doručenky a přijatý výsledek daného vztahu.
Chybějící, čekající nebo odmítnutý měsíc přípravu A2 zablokuje a uvede konkrétní
období. Při přípravě se celý plán pod zámkem znovu ověří a uloží se jeho
neměnný otisk; pozdější historie se nepřepisuje.

Samostatná záložka **ZP — oznámení** řeší oznamovací povinnost vůči zdravotní
pojišťovně, tedy hlášení nástupů, skončení a dalších skutečností v osmidenní
lhůtě. Je to jiná povinnost než měsíční přehled o platbě pojistného, a proto
má vlastní záložku; podrobnosti jsou v oddílu
[Podání zdravotním pojišťovnám](#6811-podani-zdravotnim-pojistovnam).

#### Odeslání registrace přes VREP

Po přípravě registrace zůstává na kartě pracovního vztahu přesně zmrazené XML.
Vyberte **Test** nebo **Produkci** ještě před přípravou a pak stiskněte
**Odeslat do testu** nebo **Odeslat do produkce**. Každé stisknutí založí jeden
doložitelný pokus; aplikace jej sama neopakuje ani se sama neptá na stav.

Po převzetí bránou klikněte ručně na **Zjistit výsledek**. Potvrzení o převzetí
není přijetí registrace — rozhoduje až protokol ČSSZ. Až je protokol načtený,
stiskněte **Uzavřít**, aby se dokončila transakce u brány. Neuzavírejte přenos
během čekání na protokol, jinak by nebylo možné výsledek bezpečně načíst.
Testovací a produkční pokusy jsou oddělené; pracovní vztah není přihlášený,
dokud přijetí nepotvrdí ČSSZ.

Záložka **Ostatní** je záchytná. Zobrazí evidované povinnosti, jejichž agendu
aplikace nezná — typicky zadané ručně nebo importované. Nic se
tak neztratí z dohledu; přípravu ani odeslání pro ně aplikace nenabízí.

U každého termínu se samostatně zobrazuje jeho aktuální fáze: okno ještě není
otevřené, otevřeno, blíží se termín, termín je dnes, po termínu, čeká se na
výsledek, splněno nebo je nutný zásah. Samotný stav **Odesláno** není důkazem
splnění; po termínu zůstane povinnost zvýrazněná, dokud nepřijde důvěryhodné
přijetí. Odmítnutí, částečné přijetí nebo čekání na ztotožnění se vždy ukáže
jako stav vyžadující zásah. Pravidelný termín JMHZ je 20. den následujícího
měsíce; připadne-li na sobotu, neděli nebo český svátek, aplikace jej posune
na nejbližší následující pracovní den.

Má-li povinnost připravené podání, tlačítko **Detail** zobrazí jeho bezpečný
provozní rozpad: stav a kanál, jednotlivé části, metadata archivovaných
artefaktů, kontroly a problémy a přijaté dodejky. Obsah šifrovaných XML ani
citlivé podrobnosti validačních chyb se do tohoto přehledu neposílají.
Rozlišuj zejména stav **Odesláno** od **Přijato** — přijetí se smí zobrazit
jen na základě důvěryhodně ověřeného protokolu. Tlačítkem **Stáhnout**
u artefaktu získáš přesně archivovaný XML, ZIP, PDF, JSON nebo jiný podklad;
každé stažení používá krátkodobé jednorázové oprávnění.

U schválených běhů může záložka JMHZ nabídnout také **Kontrolní náhled
PVPOJ**. Zobrazuje vyměřovací základ, pojistné k úhradě, počet zahrnutých osob
a identifikaci připnutého XSD; stejný deterministický kontrolní JSON lze stáhnout.
Náhled vznikne pouze tehdy, když souhlasí neměnný vstup revize, vypočtené
sociální pojištění a vztahové i osobní součty. Bankovní účet ČSSZ ani připravený
platební závazek nejsou podmínkou hlášení: platba je navazující samostatný tok
a její chybějící účet nesmí blokovat zákonné podání.
Viditelné označení **Pouze kontrolní náhled** znamená, že nejde o úplné XML
JMHZ, připravené podání ani důkaz odeslání nebo přijetí.

Panel **Test měsíčního hlášení JMHZ** postaví z ověřené přípravy úplné XML
běžného měsíčního hlášení a projde s ním trojí kontrolu: sestavitelnost
dokumentu, shodu s připnutým schématem a katalog kontrol ČSSZ. Nic se
neodesílá ani neukládá jako podání.

Nálezy z katalogu se dělí podle dopadu, ne podle závažnosti textu.
**Nepropustná vada** by způsobila neúčinnost podání a vyvolala výzvu
k opravnému hlášení. **Propustná vada** podání nezneplatní, ale úřady dostanou
chybná data. **Nevykonaná nepropustná kontrola** znamená mezeru na naší straně,
ne chybu v datech. U každého nálezu je kód chyby v podobě, v jaké ho vrátí ČSSZ,
a u nálezu vázaného na konkrétního zaměstnance i pořadí jeho součásti.

Výsledek proto rozlišuje tři stavy: dokument nejde postavit, XML vzniklo
a prošlo schématem, ale katalog kontrol není celý vykonaný, a konečně podání
připravené k odeslání. Prostřední stav je varovný, ne zelený. Část kontrol
rozhoduje až ČSSZ proti svému registru — ty se nikdy nevykazují jako splněné,
jen se počítají zvlášť. Panel zároveň ukazuje lhůtu pro podání za vykazované
období, včetně posunu na nejbližší pracovní den.

Panel **Zmrazení a odeslání JMHZ** navazuje až na schválenou revizi, úplné
právní evidence a úspěšné kontroly. Pro každou registraci u OSSZ pracuje se
samostatnou povinností a variabilním symbolem. Povinnost i zákonnou lhůtu při
prvním zmrazení založí automaticky z ověřené revize; účetní ji nemusí předem
vytvářet v jiné agendě. Před odesláním neměnně uloží
přesné XML a jeho otisk; další kliknutí proto nevytvoří jiné podání pod stejnou
identitou. Ostré podání je zablokované do začátku zákonné lhůty, testovací
prostředí lze použít k bezpečnému testu celého toku.

- **Odeslat přes ISDS** připraví datovou zprávu pro doloženou schránku ČSSZ.
  Je-li aktivní odesílací brána, MyÚčto před přesměrováním vysvětlí přihlášení
  a pošle uživatele přímo do ISDS. Přihlašovací údaje aplikace nevidí ani
  neukládá a zpráva odejde až po schválení konceptu uživatelem v ISDS.
  Konkrétní nabídku metod určuje ISDS a nastavení účtu; může zahrnovat jméno
  a heslo, heslo aplikace s bezpečnostním klíčem eGovernmentu nebo Mobilní klíč
  eGovernmentu. Není-li brána aktivní, připravená zpráva zůstane v odchozí
  frontě pro ruční odeslání a doplnění ID zprávy a doručenky.
- **Odeslat přes VREP** předá stejné zmrazené podání bráně ČSSZ. Výsledek,
  protokol a případné chyby se sledují na záložce **Stav odeslání**. Převzetí
  transportem ještě není přijetí podání.

Před každým odesláním aplikace znovu ověří, že odesílat vůbec lze: podání musí
být ve stavu **připraveno**, musí souhlasit prostředí i kanál a **druh podání
musí odpovídat agendě**, do které míří. Neodpovídající kombinaci odmítne ještě
před tím, než cokoli opustí aplikaci. Zopakované odeslání téhož podání se
stejným klíčem projde i tehdy, když už je odeslané — nevznikne z něj druhá
datová věta. Odesílá se vždy přesně to XML, které bylo zmrazeno; jinou podobu
podání do transportu vložit nelze.

Podaří-li se odeslání, ale nepovede se zapsat jeho evidence, aplikace to
**nehlásí jako nepodáno**. Odeslání proběhlo, a tvrdit opak by účetní svedlo
k druhému podání; místo toho vznikne provozní nález, který je vidět
v provozním přehledu mezd.

Odpovědi ani doručenky z datové schránky se nikdy nestahují automaticky.
Načtení příchozích zpráv vyvolá uživatel samostatným tlačítkem v
**Firma → Datová schránka** a před síťovým voláním potvrdí upozornění, že
vyzvednutí může založit doručení a spustit zákonné lhůty. Pro toto jediné
načtení si zvolí firemní certifikát, jednorázové jméno a heslo, SMS, nebo
Mobilní klíč: jméno a komunikační kód (heslo aplikace) a potvrzení konkrétní
relace v klíči. Heslo a SMS kód se trvale neukládají; profil Mobilního klíče
lze volitelně uložit šifrovaně a později odstranit.

Pro běžný profil JMHZ se u každé schválené revize samostatně potvrzuje pět
právních skutečností: evidované srážky ze mzdy, slevu zaměstnance pro sezónní
práci, specifickou právní skutečnost, podporu zaměstnávání osob se zdravotním
postižením a hlubinné hornictví. Potvrzuje se **za každý pracovní vztah zvlášť**,
takže revize s víc lidmi (a každá revize přes dvě mzdové účtárny) má tolik
potvrzení, kolik má vztahů; panel ukazuje, kolik vztahů ještě na potvrzení čeká
a kterých se to týká. Každou odpověď **Ne** je nutné zaškrtnout výslovně; nic se
nepředvyplňuje ani neodvozuje z chybějících dat. Aplikace současně ověří, že
schválená revize neobsahuje známý rozpor, například aktivní exekuci, insolvenci,
dohodu o srážkách nebo skutečně sraženou částku. Potvrzení se uloží jako neměnný
šifrovaný důkaz svázaný s přesnou revizí a přesným pracovním vztahem. Vztah bez
potvrzení zůstává adresným nálezem přípravy — je z něj vidět, komu evidence
chybí. Pokud některá skutečnost nastala, tento první běžný profil ji nepodporuje
a přípravu uzavře bez falešného výchozího **Ne**.

Záložka **Inbox** shrnuje napříč agendami a prostředím vše, co aktuálně
vyžaduje pozornost: blížící se nebo prošlou lhůtu, odmítnuté podání, čekání
na ztotožnění nebo jiný vzdálený problém. Odznak u záložky ukazuje počet
otevřených položek. Jde o čistě odvozený přehled — potvrzení ani odložení
nikdy nemění stav povinnosti ani podání, jen připomínku samotnou. Jednou
dosažená naléhavost (blíží se → dnes → po lhůtě) se u položky už nikdy
nesníží, ani když se zdánlivě zmírní. Položku lze **potvrdit** (beze změny
zmizí z pozornosti, zůstane ale vidět jako vyřízená) nebo **odložit** na
zvolený termín s povinně vyplněným důvodem; po uplynutí termínu se znovu
vrátí mezi otevřené. Jakmile podání skutečně dojde k výsledku (přijato,
zrušeno v termínu), položka automaticky zmizí jako vyřešená.

### 68.8.2 Hlášení změn do registru pojištěnců (A3)

Změní-li se u přihlášené osoby nebo u jejího pracovního vztahu údaj, který
zaměstnavatel do registru pojištěnců hlásí, má na jeho ohlášení **osm
kalendářních dnů** (§ 19 odst. 5 zákona č. 323/2025 Sb.). Dřív na to nic
neupozorňovalo. Nově aplikace změnu sama najde a nabídne hotový návrh
ke schválení.

**Kde se návrhy objeví.** Na kartě pracovního vztahu v části **Registrace vztahu
na ČSSZ** v žlutém bloku **Změny k ohlášení**. Blok se zobrazí jen tehdy, když
je co hlásit. U každého návrhu je, o kterou povinnost jde, termín, věta
**Změnilo se: …** se seznamem dotčených skupin údajů a odkaz na právní pramen.
Konkrétní staré a nové hodnoty se u citlivých údajů (rodné číslo, evidenční
a variabilní číslo pojištěnce, daňový identifikátor, číslo dokladu totožnosti)
nikdy nezobrazují ani neukládají; u nich se hlásí pouze to, že se změnily.

**Kdy detekce běží.** Vždy, když otevřete registrační kartu člověka nebo
přepnete prostředí, a hromadně za celou firmu při otevření přehledu termínů.
Lhůta tedy vzniká, i když kartu vůbec neotevřete. Nic se nespouští při samotném
uložení údaje, takže po opravě karty se návrh objeví až při nejbližším
přepočtu.

> ⚠️ Pozor: **změna úvazku ani mzdy se takto nehlásí.** Stanovená i sjednaná
> týdenní doba, měsíční mzda, hodinová sazba, mzdové složky, odpracované
> a neodpracované hodiny, přesčasy i daňové údaje jsou měsíční atributy hlášení.
> Projeví se samy v nejbližším měsíčním hlášení a **žádnou osmidenní lhůtu
> nespouštějí**. Aplikace na ně proto vědomě neupozorňuje: planý poplach
> u položky, která termín nemá, je horší než ticho, protože si na něj účetní
> zvykne a přestane číst i to upozornění pravé.

**Změna zdravotní pojišťovny vyrábí dvě povinnosti.** Vedle registrační akce
vůči ČSSZ vzniká samostatné oznámení zdravotním pojišťovnám podle § 10 odst. 1
písm. b) zákona č. 48/1997 Sb. Měsíční hlášení tu druhou povinnost
**nenahrazuje**. Obě mají vlastní řádek i vlastní termín. Přestup se navíc
hlásí oběma pojišťovnám, odcházející i přijímající, a protože aplikace sama
neurčí směr přestupu, tuhle povinnost jedním kliknutím podat nelze; splňte ji
v oficiálním kanálu a návrh potom uzavřete ručně. U dohod o provedení práce
a o pracovní činnosti není lhůta vůči pojišťovně osmidenní, ale do 20. dne
následujícího měsíce, a neposouvá se na pracovní den.

**Schválení je jedno kliknutí.** Tlačítko **Ohlásit změnu** se nabídne jen
u návrhu, který datová věta skutečně unese. Neptá se na důvod ani na potvrzení:
obsah je celý odvozený z porovnání, není co doplňovat. Před založením události
se stav ještě jednou přepočítá, aby se neohlásilo něco, co už mezitím někdo
vrátil zpátky. Rozhodným datem je **den detekce**, protože lhůta běží ode dne,
kdy se zaměstnavatel o změně dozvěděl.

Schválením ale **nic neodchází**. Vznikne registrační událost; podání se z ní
připravuje samostatným krokem a odeslání na ČSSZ je krok další. Postup
odesílání je stejný jako u prvotní registrace.

Datová věta A3 nese v tomto vydání jen část katalogu: **titul před jménem,
doručovací adresu, daňovou rezidenci a kód zdravotní pojišťovny**. Změna jména,
adresy pobytu, důchodu, profese, místa výkonu práce a další se proto ohlásí
větou „Tenhle údaj datová věta A3 v aplikaci nenese - podejte ho jinou cestou
a návrh pak uzavřete ručně." Nález se nezahazuje: povinnost i lhůta existují
dál a zůstávají vidět. Jedním kliknutím nelze podat ani vymazání hodnoty, ani
neúplnou doručovací adresu, ani vznik či zánik příslušnosti k cizím předpisům,
který má vlastní akci.

Ruční uzavření návrhu tlačítkem vedle **vyžaduje důvod** (1 až 500 znaků). Je to
jediná stopa, proč se touto cestou nehlásilo, takže ji napište věcně.

Návrh po marném uplynutí lhůty **nezmizí**. Zůstává otevřený a v přehledu
termínů se ukáže jako po termínu s počtem dnů; prokliknete se z něj rovnou na
kartu člověka. Pohne-li se stav dál, starý otevřený návrh se uzavře jako
nahrazený, nikdy se nemaže, aby lhůta, která existovala, zůstala dohledatelná.

Detekce má dvě hranice, které je dobré znát:

- **Bez odeslané prvotní registrace se nedetekuje nic.** Porovnává se proti
  poslednímu skutečně odeslanému podání, protože nemá smysl hlásit změnu údaje,
  který úřad ještě nemá. Samotný uložený profil A1 jako základ nestačí.
- **Porovnávají se jen údaje, které nese i to poslední podání.** Údaj, který
  v něm nebyl, se jako změna neohlásí. Aplikace zvlášť vypisuje i hlásitelné
  údaje, u kterých srovnávací základ nemá (variabilní symbol zaměstnavatele,
  název zaměstnavatele, ID PPV přidělované ČSSZ a nositel pojištění v cizině),
  aby byla mezera vidět.

Testovací a produkční prostředí mají návrhy oddělené a nemíchají se.

## 68.9 Storno a obsahová oprava JMHZ

Storno JMHZ nevzniká přepsáním původního XML. V **Stavu odeslání** otevřete
způsobilé předchozí podání a zvolte řízenou akci. **Připravit storno** zruší
celé hlášení za období. **Opravit hodnoty hlášení** pracuje s aktuální úplnou
přípravou JMHZ po opravě mzdových údajů a vytvoří skutečné obsahové opravné
hlášení. Aplikace vytvoří nový neměnný artefakt s vazbou na původní podání.

Příprava storna sama nic neodešle. Nový artefakt se ve **Stavu odeslání** ukáže
v oddílu **Připravená podání čekají na odeslání** se svým přesným číslem,
druhem a vazbou na původní hlášení. Odtud jej odešlete tlačítkem **Odeslat přes
ISDS** nebo **Odeslat přes VREP**; aplikace nehledá jiné podání za stejné
období. ISDS nejprve vytvoří odchozí zprávu a teprve další výslovná akce otevře
přihlášení a potvrzení odeslání. Samostatně potom sledujte protokol až do
přijetí. Opakování stejné přípravy vrací již vytvořený výsledek, i když
tlačítko použijete později znovu.

Přijetím storna celého hlášení se jako nahrazené označí řádné hlášení i všechny
jeho dříve přijaté dílčí opravy. Historie tak dál ukazuje celý řetězec, ale za
platné už nepovažuje žádnou jeho zrušenou část.

Jakmile už pro podání existuje odchozí zpráva ISDS, přehled ukáže její číslo a
aktuální stav. Další odeslání přes ISDS i VREP zablokuje, aby účetní omylem
nepodala tutéž datovou větu dvakrát. Pokračujte odkazem **Otevřít odchozí
zprávy**, kde se dokončí přihlášení, odeslání a evidence doručenky.

Ve **Stavu odeslání** nemusíte opisovat GUID ani interní číslo přípravy.
Aplikace nabídne jen úplné aktuální přípravy pro stejnou firmu, prostředí,
období a mzdový běh; jedinou možnost vybere automaticky, z více možností vyberete
ve vyhledávatelné nabídce. Potom spojí neměnné odeslané XML s výsledky
jednotlivých formulářů z přijatých podepsaných protokolů ČSSZ. Neověřený,
neúplný nebo rozporný protokol opravu zablokuje. Zaměstnance vybíráte primárně
podle jména, identifikátory ČSSZ zůstávají zobrazené jako technická kontrolní
stopa. U přijatého formuláře nabídne **Opravit přijaté hodnoty** a odešle jeho
úplné opravené tělo se zachovanou identitou. U odmítnutého, stornovaného nebo
dosud chybějícího formuláře nabídne **Doplnit odmítnutý/chybějící formulář** a
vytvoří novou identitu formuláře.

Vyberete jen vztahy, jejichž obsah chcete změnit, ale souhrn a PVPOJ se při
dopadu kontrolují proti úplnému aktuálnímu setu všech osob firmy. Tím se
pojistný přehled nikdy nepřepočítá jen z vybrané podmnožiny. Příprava opravy
zůstává oddělená od odeslání: nejprve potvrdíte zmrazení přesných bajtů XML a
teprve potom podání odešlete v oddílu připravených podání. Opakovaná stejná
akce vrátí tentýž zmrazený artefakt. Testovací a produkční prostředí mají
oddělené řetězce i idempotenci.

## 68.10 Evidenční list důchodového pojištění

**Evidenční list už není roční povinnost.** Od roku 2026 jej zaměstnavatel
nevyhotovuje ani nepředkládá: údaje pro důchodové pojištění sděluje jednotným
měsíčním hlášením a evidenční list z nich sestaví ČSSZ (§ 38 odst. 1 a 2 zákona
č. 582/1991 Sb. ve znění zákona č. 360/2025 Sb.). Zaměstnanci je dostupný na
ePortálu ČSSZ (§ 39 odst. 1). Žádný úkon „vygeneruj a odešli ELDP za rok" tedy
na konci roku nečekejte — aplikace jej nenabízí a přípravu za takový rok
odmítne.

Tiskopis ale zrušen nebyl a v aplikaci jej připravíte ve třech výjimkách:

- za období **před 1. lednem 2026**, na které se použije dřívější znění zákona,
- u zaměstnání **skončených před 1. dubnem 2026**, na která dopadá přechodné
  ustanovení,
- **na výzvu ČSSZ/ÚSSZ** podle § 38a odst. 2 a 3 — uplynula-li lhůta pro měsíční
  nebo opravné hlášení, anebo nelze-li z nahlášených údajů evidenční list
  sestavit. U výzvy zaškrtněte příslušné potvrzení a zadejte skutečné datum
  jejího doručení; od tohoto dne běží lhůta osmi dnů.

Nad formulářem vždy stojí věta, jestli evidenční list pro zvolený rok a pracovní
vztah vůbec vzniká, a proč. Není-li přípustný, tlačítko přípravy zůstane
nedostupné.

Přijde-li výzva ještě v průběhu vykazovaného roku a pracovní vztah trvá,
aplikace sestaví list jen do posledního měsíce, za který existuje aktuální
schválená mzdová revize. To odpovídá metodice ČSSZ: do údaje **Do** patří
poslední den měsíce, za který byl zaměstnanci naposledy zúčtován příjem.
Budoucí měsíce se nevyžadují. Chybí-li ale některá revize uvnitř takto
vymezeného období, příprava zůstane zablokovaná, protože by nebylo možné
doložit souvislou dobu pojištění ani vyměřovací základ.

Vygenerované XML slouží pouze ke kontrole údajů. Není to transportní datová
věta a MyÚčto je neodesílá ani nevkládá do datové schránky. ELDP dokončete
v aktuálním oficiálním rozhraní ČSSZ a výsledek potom doložte aktivním firemním
dokumentem z DMS, referencí potvrzení a skutečným datem.

Rozlišujte dva výsledky. **Podáno (`submitted`)** znamená, že máte doklad o
podání, ale ještě ne konečné přijetí; povinnost proto zůstává ve stavu čekání
na výsledek. **Přijato (`accepted`)** použijte jen tehdy, když připojený dokument
výslovně dokládá konečné přijetí. Teprve tento důkaz označí zákonnou povinnost
za splněnou. Kontrolní XML přitom zůstává stále jen ve stavu připraveno a nikdy
se nevykazuje jako odeslané.

## 68.11 Podání zdravotním pojišťovnám

Záložky zdravotních pojišťoven oddělují dvě povinnosti:

- **HOZ** je hromadné oznámení zaměstnavatele. Aplikace povinnosti odvodí,
  sestaví z nich datovou větu XML i PDF a obojí zmrazí. Připravený soubor není
  odeslaný — odeslání datovou schránkou musíte potvrdit sami.
- **PPZ** je měsíční přehled o platbě pojistného. Ze schválené revize se
  sestaví a zmrazí pouze formát doložený pro vybranou pojišťovnu. Připravený
  soubor není odeslaný.

### Kdy vyjde úřední tiskopis a kdy vlastní sestava

Vydání tiskopisů z roku 2026 je jednotné: hromadné oznámení má číslo
`UNI 73.51/2026`, přehled o platbě `UNI 76.51/2026`, ani jeden nemá logo nebo
kód konkrétní pojišťovny. Zveřejňuje je zatím jen VZP; VoZP používá stejná
čísla tiskopisů a stejnou XDP šablonu, takže MyÚčto vyplňuje úřední tiskopis
**pro VZP (111) a VoZP (201)**. Ostatní pojišťovny dál zveřejňují vlastní starší
formuláře, proto pro ně vzniká vlastní čitelná sestava se stejnými údaji.

Vlastní sestava vznikne také tehdy, když se oznámení na tiskopis nevejde:
úřední tiskopis má čtyři bloky vět a natištěné „1/1“ v poli počtu listů, takže
od páté věty se použít nedá. Ve všech případech aplikace důvod pojmenuje —
uvidíte ho u výsledku sestavení i v patce vytištěného dokumentu, nikdy se
nezamlčí.

Formát připravené přílohy se řídí pojišťovnou a obdobím:

| Kód | Pojišťovna | Formát připravený pro ISDS |
|---|---|---|
| 111 | VZP ČR | strojově čitelné PDF |
| 201 | VoZP ČR | strojově čitelné PDF |
| 205 | ČPZP | XML podle zveřejněného schématu |
| 207 | OZP | XML podle zveřejněného schématu |
| 209 | ZPŠ | strojově čitelné PDF |
| 211 | ZP MV ČR | strojově čitelné PDF; nový XML/B2B kanál je oddělený |
| 213 | RBP | XML podle zveřejněného schématu |

ZP MV ČR plánuje nový XML/B2B kanál od 1. 10. 2026, ale pro ISDS výslovně
zůstává podporované strojově čitelné PDF i od roku 2027; MyÚčto proto ISDS
automaticky na XML nepřepíná. RBP připouští XML i vytěžitelné PDF a MyÚčto
volí XML. U VZP a VoZP je XDP šablona pomůcka pro hromadné vyplnění PDF,
nikoli soubor, který by se přikládal k datové zprávě. XSD se rovněž
neodesílá: slouží jen jako schéma, proti kterému aplikace kontroluje XML.
Tato matice popisuje formát zvolený aplikací pro ISDS, nikoli neveřejná
portálová nebo B2B rozhraní pojišťoven.

Pokud panel u PPZ nabídne **Odeslat přes ISDS**, adresát musí pocházet ze
stejného centrálního katalogu pojišťoven jako sestavení souboru. Akci vždy
spustí uživatel; vytvoření záznamu ve frontě ani konceptu není odeslání.
Zkontrolujte adresáta, období a přílohu, v ISDS koncept výslovně schvalte
a následně ověřte doručenku i věcnou odpověď pojišťovny.

## 68.12 Nemocenské a další zákonné povinnosti

Záložka **Další povinnosti** ukazuje pro vybraný měsíc přesnou matici toho,
co MyÚčto umí a co musí zůstat ruční. NEMPRI je po zavedení JMHZ nahrazené
jen částečně a HZUPN zůstává samostatným hlášením. MyÚčto pro ně nevytváří
XML ani je neodesílá. Účetní ověří případ a zdrojová data, podání dokončí
v oficiálním kanálu ČSSZ a skutečnou doručenku nebo protokol uloží jako
firemní dokument do DMS.

Po ručním splnění lze u NEMPRI nebo HZUPN zapsat zaměstnance, referenci
případu, referenci doručenky, datum a ID firemního DMS dokumentu. Server
ověří vlastnictví dokumentu firmou a sám zmrazí jeho SHA-256. Záznam je
neměnný; oprava se přidává jako nový důkaz. Produkční a testovací důkazy se
nemíchají. Samotné vyplnění formuláře v MyÚčtu nikdy nenahrazuje podání
v oficiálním kanálu.

**Zákonné úrazové pojištění** je v matici výslovně uvedené jako samostatná
ruční povinnost. MyÚčto nyní nepočítá základ ani sazbu, nevytváří předpis,
výstup nebo platební závazek a nenabízí transport. Částku proto určete podle
odborně ověřených externích podkladů a skutečnou úhradu proveďte mimo MyÚčto.
Potvrzení úhrady nebo konkrétní oficiální doklad uložte jako firemní dokument
do DMS. Teprve potom lze zapsat externě ověřenou částku v CZK, referenci
povinnosti, referenci platby a DMS dokument jako neměnný důkaz. Zápis je
výslovné potvrzení uživatele; MyÚčto správnost výpočtu ani provedení platby
automaticky neověřuje. Absence automatizace neznamená, že povinnost zanikla
nebo ji nahradilo JMHZ.

## 68.13 Vyúčtování zálohové a srážkové daně

Za uplynulý rok podává plátce správci daně dvě samostatná vyúčtování, ne jedno
se dvěma přílohami:

- **Vyúčtování daně z příjmů ze závislé činnosti** (§ 38j odst. 4 ZDP, tiskopis
  25 5459). Lhůta jsou dva měsíce po skončení roku, elektronicky do 20. března.
- **Vyúčtování daně vybírané srážkou podle zvláštní sazby** (§ 38d ZDP, tiskopis
  25 5466). Lhůta jsou tři měsíce po skončení roku.

Ani jednu lhůtu nelze prodloužit. Aplikace je vypisuje jako text; do daňového
kalendáře ani do přehledu mzdových termínů se nepromítají.

> 🛈 Pozn: Za rok 2026 se obojí podává běžným způsobem. Teprve od období 2027
> nahradí vyúčtování zálohové daně hlášení k záloze v měsíčním hlášení, takže
> tuhle cestu je potřeba ještě jednu sezónu.

**Kde to je.** Na přehledu mezd, panel **Vyúčtování daně**, pod ročním
zúčtováním a roční uzávěrkou. Vybíráš rok (výchozí je loňský) a typ vyúčtování:
řádné, řádné opravné, dodatečné nebo dodatečné opravné. U obou dodatečných
variant se navíc zadává datum zjištění důvodů (§ 141 odst. 5 daňového řádu).
Víc se ručně vyplnit nedá: **žádnou částku ani řádek nelze přepsat**, podklad
je jen průmět schválených mzdových běhů.

Panel ukazuje tři dlaždice (zálohy, které měly být sraženy, skutečně odvedeno
finančnímu úřadu, srážková daň celkem), tabulku po měsících, přílohu č. 1 se
seznamem obcí místa výkonu práce a blok varování. Nejsou v něm žádná jména ani
osobní identifikátory. Měsíc bez schváleného mzdového běhu **není měsíc s
nulami** - řádek se prostě nevytvoří a dostaneš na to varování. Pokud v takovém
měsíci mzdy byly, schval je nejdřív.

Podklad je vždy zmrazený výsledek schválených revizí, nikdy nový výpočet.
Do přílohy č. 1 se počítají zaměstnanci podle obce místa výkonu práce k 1. 12.;
komu obec u vztahu chybí, ten se do přílohy nedostane a aplikace to spočítá do
varování. Okres se dopočítá z číselníku obcí, a co číselník nepokrývá, zůstane
prázdné.

**Co se záměrně negeneruje, a proč.** Věz to dopředu, ať to nehledáš:

- **Příloha č. 2 pro nerezidenty.** Vyžaduje číslo dokladu totožnosti, jeho typ
  a typ zahraničního daňového identifikátoru. Tyto údaje aplikace o osobě
  nevede, takže by příloha byla poloprázdná a nepravdivá. Místo ní vzniká
  varování s počtem evidovaných nerezidentů a výzvou doplnit přílohu ručně
  v EPO.
- **Přílohy č. 3 a 4 podle § 38i.** Modul opravy neeviduje jako samostatný
  záznam „měsíc chybný, měsíc opravy, částka" - opravuje se přepočtem revize.
  Prázdná příloha je pravdivá, vymyšlená by nebyla. Totéž platí pro obdobnou
  přílohu u srážkové daně (§ 38d odst. 8).
- **Částky předepsané k přímé úhradě.** To je rozhodnutí správce daně, které
  aplikace nezná; příslušný sloupec proto zůstává nulový.
- **Řádky „finanční úřad na žádost vrátil, převedl nebo použil"** podle § 35d
  odst. 5 a 9 zůstávají nulové ze stejného důvodu.
- **Rozdíl u dodatečného vyúčtování** se nepočítá: musel by být znám obsah
  původního podání jako celku, ne jen dnešní stav mezd. U dodatečné varianty se
  navíc vynechává část II. a dva sloupce části I.

**Výstup.** Dvě samostatná tlačítka stáhnou dvě XML pro EPO. Každé stažení se
archivuje s otiskem a najdeš ho v přehledu podání ve složce **Vyúčtování daně
ze závislé činnosti**; stažení nikdy neposune daňový zámek. Odtud pokračuješ
asistovaným nebo přímým podáním na EPO stejně jako u ostatních daňových
písemností, viz
[EPO podání, archív a daňová rekonciliace](89_Archiv_podani_a_rekonciliace.md).

Podání se nesestaví vůbec, když za rok není ani jeden schválený mzdový běh,
nebo když úhrn skutečně odvedené daně vyjde záporně - to znamená špatně
spárované platby finančnímu úřadu, oprav je dřív. Varování se zobrazí i tehdy,
když je příloha č. 1 prázdná, když firma nemá vyplněný finanční úřad (dosadí se
FÚ pro Prahu 1 a je potřeba ho ověřit), a když zaokrouhlení na celé koruny
zbylo přes.

## 68.14 Žádost o poukázání chybějící částky na daňovém bonusu

Vyplatí-li zaměstnavatel na daňových bonusech víc, než kolik ten měsíc srazil
na zálohách, rozdíl doplácí ze svého. Aby se mu vrátil, musí o něj finanční
úřad požádat; samo se to nestane a peníze do té doby leží u státu. Jde
o dobrovolné podání: podává je plátce tehdy, když chce své peníze zpátky.

Formuláře jsou dva a mají vlastní tiskopis:

| Písemnost | Právní základ | Čeho se týká |
|---|---|---|
| Žádost podle § 35d odst. 5 | měsíční daňové bonusy | bonusy vyplacené v daném měsíci |
| Žádost podle § 35d odst. 9 | doplatek z ročního zúčtování | doplatek na bonusu vyplacený z ročního zúčtování |

**Obě žádosti se vážou na měsíc, ne na rok.** I doplatek z ročního zúčtování,
protože rozhodné je datum jeho skutečné výplaty a záloha, proti které se
započítává, je měsíční. Doplatek vyplacený v březnu a doplatek z opravné revize
v červnu jsou proto dvě samostatné žádosti, i když jde o tentýž zdaňovací rok.

Podklad je zmrazený výsledek schválených mzdových revizí za daný měsíc, sečtený
přes všechny mzdové účtárny firmy; žádost jde na jeden finanční úřad za celou
firmu. Druhý výpočet nevzniká. Rozdělení mezi obě žádosti potřebovalo pravidlo,
které zákon nedává, a je zvolené takto: **sražené zálohy kryjí nejdřív měsíční
bonusy, zbytek doplatky**. Obě žádosti musí dát dohromady přesně tu částku,
kterou aplikace zaúčtovala jako pohledávku za finančním úřadem; nesouhlas
by podání zastavil. V jedenácti měsících v roce, kdy se roční zúčtování
nevyplácí, na pořadí stejně nezáleží.

Vyžaduje se zapnuté vedení mezd, oprávnění ke mzdovým sestavám a k exportu.
Žádost se nesestaví, když za měsíc není schválený mzdový běh s vypočtenou daní,
ani když bonusy zálohy nepřevýšily, tedy když není o co žádat. Chybí-li u běhu
datum výplaty, aplikace **nedosadí konec měsíce** a vrátí varování: rozhodné
datum musí být skutečný den výplaty bonusu. Je-li v měsíci víc běhů, použije se
poslední datum výplaty. Vnitřně se počítá v haléřích, tiskopis chce celé
koruny, a zbytek po zaokrouhlení se hlásí varováním, ne tiše zahazuje.

Aplikace **záměrně neurčuje, kam peníze poslat, ani zda je započíst proti
vlastním nebo cizím nedoplatkům**. To jsou rozhodnutí plátce, ne výpočet, a
vymyslet je by znamenalo tvrdit volbu, kterou nikdo neudělal. Vynechání těchto
částí znamená běžnou výplatu na účet plátce. Aplikace také nehlídá lhůtu pro
podání a žádost sama od sebe nenavrhuje; k prošlému měsíci se musíš vrátit sám.

Výstupem je XML pro EPO. Archivuje se se stejným otiskem jako ostatní daňová
podání a v přehledu podání je najdeš ve složce **Daňové bonusy**. Se
[Vyúčtováním daně](#6813-vyuctovani-zalohove-a-srazkove-dane) nemá žádost
společný formulář ani přílohu; jsou to samostatná podání, byť se stejnými
částkami vyplacených bonusů v pozadí.

> 🛈 Pozn: Samostatná obrazovka pro žádost zatím není. Připravená písemnost se
> zakládá přes rozhraní a hotové XML uvidíš v přehledu daňových podání.
