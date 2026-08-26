# Podání a hlášení

## Účel

Agenda připravuje vybraná mzdová hlášení, provádí formální kontroly a vede uživatele přes ručně spuštěné odeslání. Pokrývá zejména podporované toky ČSSZ a zdravotních pojišťoven; vytvořený soubor ani datová zpráva nejsou samy o sobě potvrzením věcného přijetí.

## Předpoklady a oprávnění

Je nutné oprávnění `payroll.submissions`, způsobilý uzavřený běh nebo schválená revize, úplné identifikátory a správně oddělené TEST/produkční prostředí. Pro ČSSZ TEST použijte pouze testovací profil a certifikát v určeném bezpečném úložišti. ISDS musí být nastaveno pro správnou firmu a prostředí.

## Krokový postup

1. Otevřete **Mzdy → Podání a hlášení**, vyberte typ, období a schválenou revizi.
2. Spusťte náhled nebo kontrolní přípravu a odstraňte blokující chyby. Řádné JMHZ vzniká ze způsobilé běžné revize. Opravu nebo storno již připraveného JMHZ založte z jeho historie řízenou akcí; nejde o ruční nepodporovaný scénář.
3. Vytvořte výstup. U zdravotního PPZ aplikace podle pojišťovny připraví podporovaný XML nebo vytěžitelný PDF; XDP není odesílaný formulář. HOZ a další nepokryté životní události dokončete ručně na oficiálním kanálu.
4. Pro ISDS stiskněte **Odeslat přes ISDS**. Aplikace vytvoří záznam v outboxu a provede předběžnou kontrolu, ale zprávu sama neodešle.
5. Otevřete koncept na oficiálním rozhraní ISDS, přihlaste se metodou, kterou ISDS v daném prostředí skutečně nabídne, zkontrolujte adresáta a přílohy a odeslání výslovně potvrďte.
6. Alternativně použijte podporovaný profil VREP pro ČSSZ. Přihlašovací a certifikační údaje zadávejte jen do určených polí, nikdy do poznámek.
7. Inbox načítejte pouze ručně. Před načtením vždy potvrďte, že rozumíte tomu, že přístup ke zprávě může způsobit její doručení. Potom přiřaďte odpověď k podání a ověřte věcný výsledek.

## Stavy

Návrh čeká na doplnění, připravené podání prošlo lokální kontrolou, outbox čeká na uživatelskou akci a koncept čeká na potvrzení v ISDS. Odesláno popisuje transport. Doručeno dokládá doručení datové zprávy, nikoli přijetí obsahu institucí. Přijato, odmítnuto nebo vyžaduje opravu určete až z doručenky, odpovědi či stavu cílového systému.

## Kontroly a bezpečnost

Odesílací brána přesměruje uživatele na oficiální rozhraní ISDS. Podle nabídky
konkrétního účtu tam lze použít například jméno a heslo, heslo aplikace
s bezpečnostním klíčem eGovernmentu, Mobilní klíč eGovernmentu, SMS,
uživatelský certifikát nebo Identitu občana. MyÚčto údaje z této přihlašovací
stránky nevidí a zpráva odejde až po výslovném schválení konceptu uživatelem.

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

## Časté chyby

- Považování XML, PDF nebo záznamu outboxu za odeslané podání.
- Záměna testovacího certifikátu či adresáta za produkční.
- Odeslání řádného JMHZ bez schválené běžné revize nebo založení opravy či storna bez vazby na způsobilé předchozí podání.
- Považování doručenky za věcné přijetí bez kontroly odpovědi.
- Automatické nebo neuvážené otevření inboxu bez potvrzení možného účinku doručení.
- Uložení hesla, SMS kódu či privátního klíče do poznámky nebo evidence podání.

## Návaznosti

Identifikátory nastavte v [Nastavení mezd](58o_Nastaveni_mezd.md). Firemní přístupy, ruční inbox a odchozí zprávy popisuje kapitola [Datová schránka](73a_Datova_schranka.md), globální registraci pro odesílání správcem systému pak [Odesílací brána ISDS](73b_Odesilaci_brana_ISDS.md). Zdrojová data pocházejí z [mzdového běhu](58e_Mzdove_behy.md); kontrolní soubory a doručenky uchovávejte podle [retenčních lhůt](58r_Retencni_lhuty.md).



## Podrobný tok podání

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

### Registrace zaměstnance PREZEC a REGZEC

Test registrace zaměstnance čte identitu z osobní karty účinnou přesně k datu
nástupu pracovního vztahu. V **Mzdy → Zaměstnanci → Úplná osobní evidence a
historie → Identita a adresy** rozbal u příslušné verze jména část **Údaje pro
registraci zaměstnance** a doplň datum a místo narození, stát narození, státní
občanství, pohlaví a případné tituly. Občanství rozhoduje také o tom, zda lze
použít omezenou předregistraci PREZEC, nebo je potřeba úplná registrace REGZEC.
Náhled i následné zmrazení používají stejný historický zdroj a stejné kontroly;
pozdější změna osobní karty už nemění dříve zmrazené podání.

Samostatná záložka **ZP — oznámení** řeší oznamovací povinnost vůči zdravotní
pojišťovně, tedy hlášení nástupů, skončení a dalších skutečností v osmidenní
lhůtě. Je to jiná povinnost než měsíční přehled o platbě pojistného, a proto
má vlastní záložku; podrobnosti jsou v oddílu
[Podání zdravotním pojišťovnám](#podani-zdravotnim-pojistovnam).

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

## Storno a následná oprava JMHZ

Storno JMHZ nevzniká přepsáním původního XML. V **Stavu odeslání** otevřete
způsobilé předchozí podání a zvolte řízenou akci. **Připravit storno** zruší
celé hlášení za období. **Stornovat vybrané vztahy** vytvoří podání druhu O,
které zneplatní pouze vybrané součásti podle konkrétních pracovněprávních
vztahů. Tato druhá akce sama neopravuje jejich hodnoty. Aplikace vytvoří nový
neměnný artefakt s vlastní identitou a vazbou na původní podání.

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

Ve **Stavu odeslání** nemusíte opisovat GUID ani identifikátory osoby a vztahu.
Akce **Stornovat vybrané vztahy** se nabídne až po konečném protokolu. Aplikace
načte součásti přímo ze zmrazeného řádného XML; prohlížeč posílá serveru jen
vybrané GUIDy a zákonné identifikátory znovu doplní server z neměnného originálu.
Ve větším seznamu můžete hledat podle identifikátoru vztahu nebo osoby. Nelze
vybrat všechny součásti — pokud nemá žádná zůstat platná, použijte storno celého
podání. U částečně přijatého hlášení se akce odemkne až po doložení výsledku
jednotlivých formulářů z úplného protokolu ČSSZ. Plné sestavení opravného
hlášení s novými hodnotami z opravné mzdové revize tato obrazovka zatím
nenabízí; nepovažujte storno vybraných vztahů za dokončenou opravu údajů.

## Evidenční list důchodového pojištění

Samostatný ELDP se od roku 2026 běžně nesestavuje: ČSSZ jej vytváří z JMHZ.
V aplikaci jej připravte jen pro starší roky, při přechodném skončení účasti
před 1. dubnem 2026 nebo na výzvu ČSSZ/ÚSSZ. U výzvy zaškrtněte příslušné
potvrzení a zadejte skutečné datum jejího doručení; od tohoto dne běží lhůta
osmi dnů.

Přijde-li výzva ještě v průběhu vykazovaného roku a pracovní vztah trvá,
aplikace sestaví list jen do posledního měsíce, za který existuje aktuální
schválená mzdová revize. To odpovídá metodice ČSSZ: do údaje **Do** patří
poslední den měsíce, za který byl zaměstnanci naposledy zúčtován příjem.
Budoucí měsíce se nevyžadují. Chybí-li ale některá revize uvnitř takto
vymezeného období, příprava zůstane zablokovaná, protože by nebylo možné
doložit souvislou dobu pojištění ani vyměřovací základ.

## Podání zdravotním pojišťovnám

Záložky zdravotních pojišťoven oddělují dvě povinnosti:

- **HOZ** je přehled oznamovaných životních událostí a lhůt. Aplikace povinnosti
  odvodí a umí je idempotentně propsat do pracovního inboxu, ale nevytváří ani
  neodesílá domnělý HOZ artefakt. Podání dokončete ručně na oficiálním kanálu
  a teprve potom položku v inboxu výslovně potvrďte.
- **PPZ** je měsíční přehled o platbě pojistného. Ze schválené revize se
  sestaví a zmrazí pouze formát doložený pro vybranou pojišťovnu. Připravený
  soubor není odeslaný.

Formát připravené přílohy se řídí pojišťovnou a obdobím:

| Kód | Pojišťovna | Formát připravený pro ISDS |
|---|---|---|
| 111 | VZP ČR | strojově čitelné PDF |
| 201 | VoZP ČR | strojově čitelné PDF |
| 205 | ČPZP | XML podle zveřejněného schématu |
| 207 | OZP | XML podle zveřejněného schématu |
| 209 | ZPŠ | strojově čitelné PDF |
| 211 | ZP MV ČR | PDF do 30. 6. 2026, od 1. 7. 2026 nový XML formát |
| 213 | RBP | XML podle zveřejněného schématu |

ZP MV ČR přijímá ve druhém pololetí 2026 přechodně také PDF; MyÚčto od
1. 7. 2026 volí novější XML. RBP připouští XML i vytěžitelné PDF a MyÚčto
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
