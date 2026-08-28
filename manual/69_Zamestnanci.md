# Zaměstnanci

## 69.1 Účel

Agenda zaměstnanců spojuje osobní kartu s jedním nebo více pracovními vztahy. Oddělení osoby od vztahu zachovává historii a umožňuje správně zpracovat souběhy.

## 69.2 Předpoklady a oprávnění

Uživatel potřebuje právo číst nebo měnit osoby a pracovní vztahy. Připravte pouze údaje potřebné pro mzdu, daň, pojištění, platbu, dokumenty a podání a ověřte je z oprávněného podkladu.

## 69.3 Krokový postup

1. Otevřete **Mzdy → Zaměstnanci** a založte osobu nebo otevřete existující.
2. Vyplňte identifikační, adresní, daňové, pojistné a platební údaje.
3. Založte samostatný vztah pro každý právně odlišný pracovní poměr či dohodu.
4. Doplňte typ vztahu, data, úvazek, odměňování, pojištění a daňový režim.
5. Změny podmínek zapisujte s účinností; nepřepisujte údaje použité v uzavřených obdobích.
6. Při skončení uzavřete poslední období, připravte dokumenty a proveďte podporovaná hlášení; nepodporované registrace dokončete ručně.

## 69.4 Stavy

Osoba může existovat bez aktivního vztahu. Vztah může být budoucí, aktivní nebo skončený. Neúplná data lze evidovat, ale výpočet, dokument či podání může vyžadovat jejich doplnění.

## 69.5 Kontroly a bezpečnost

Ověřte identifikátory, pojišťovnu, účet, data vztahu a souběhy. Osobní a zdravotní údaje zpřístupněte jen podle role. Odkaz na dokument je volitelná stopa k bezpečně uloženému podkladu; nenahrazuje zákonný identifikátor a nesmí obsahovat tajné údaje.

## 69.6 Časté chyby

- Duplicitní osobní karta místo dalšího vztahu.
- Přepsání historické podmínky bez data účinnosti.
- Nesprávné přiřazení vstupu k jednomu ze souběžných vztahů.
- Skončení vztahu bez poslední mzdy, dokumentů nebo ruční oznamovací povinnosti.

## 69.7 Návaznosti

Zaměstnavatele a předkontace nastavuje [kapitola 58o](73_Nastaveni_mezd.md), pravidelné odměňování [58p](74_Mzdove_slozky_a_vstupy.md), běhy [58e](63_Mzdove_behy.md) a životní události vůči institucím [58j](68_Podani_a_hlaseni.md).



## 69.8 Podrobný pracovní postup a kontroly

V **Mzdy → Zaměstnanci** se zobrazují stejné karty jako ve spodní části Mzdové
rekapitulace. Změna jména nebo aktivního stavu v původní agendě se proto týká
téže osoby; žádné slučování duplicitních karet není potřeba.

Primárním tlačítkem **Přidat zaměstnance** založíš právě tuto společnou kartu,
nikoli druhou osobu jen pro úplné mzdy. Formulář se otevře místo seznamu, takže
nemusíš nikam scrollovat ani hledat založenou osobu. Nahoře je jen to, bez čeho
uložení neprojde — jméno, druh vztahu a plánovaný nástup. Zbytek je hned pod
tím ve sbalitelné části **Další údaje**: rodné číslo, datum narození, základní
mzda, týdenní pracovní doba, mzdová účtárna a zdravotní pojišťovna. Jedno
uložení tak založí kartu, první pracovní vztah i jeho podmínky; nový zaměstnanec
se pak otevře k doplnění zbytku osobního profilu.

Týdenní pracovní doba je součástí zakládacího formuláře. Zadejte skutečný
úvazek hned při založení, aby první interval podmínek odpovídal realitě.
Automatický nárok dovolené tuto sjednanou dobu převezme; není nutné ji znovu
opisovat v agendě absencí. Firemní výměra dovolené platí všem vztahům. Pole
**Výjimka z výměry dovolené** vyplňte jen tam, kde má konkrétní vztah jiný
nárok; prázdná hodnota znamená převzetí účinné firemní politiky. Změna se
ukládá jako nová účinná verze podmínek, takže starší nároky zůstanou dohledatelné.
Mzdová účtárna se
nabízí firmě s víc než jednou aktivní účtárnou; ostatním ji aplikace dosadí
z výchozí účtárny zaměstnavatele. Zdravotní pojišťovna se předvyplní tou, kterou
má firma v nastavení jako výchozí, a zapíše se do **zákonné evidence osoby**
k datu nástupu — týmž uložením jako zaměstnanec, takže nemůže vzniknout karta
bez ní. Neznámý kód pojišťovny proto celé založení odmítne a nic se neuloží.
Zaměstnance bez českého rodného čísla lze založit bez náhradní hodnoty.
EČP, VČP a zahraniční identifikátor se vedou samostatně a lze je doplnit
přímo v běžné editaci; úplná osobní evidence dál uchovává jejich 1:N historii.
Rodné číslo se v seznamu nezobrazuje. Tlačítko zůstává viditelné
i uživateli bez práva zápisu, ale je neaktivní a vysvětlí chybějící oprávnění.

Hvězdička u popisku znamená, že bez toho pole uložení neprojde. Při zakládání
jsou takové jen tři: jméno, druh vztahu a plánovaný nástup. Rodné číslo, datum
narození ani mzda povinné nejsou a jdou doplnit kdykoli později — rodné číslo
je potřeba až u přihlášky na ČSSZ (kde stačí i EČP) a u oznámení zdravotní
pojišťovně.

Toolbar nad seznamem umožňuje hledání podle jména, přepnutí mezi aktivními,
všemi a kartami vyžadujícími doplnění a rychlý přechod na měsíční zadání mezd.
Hledání i stránkování probíhá nad celou firmou na serveru po 25 osobách, takže
se stejným postupem pracujete s deseti i pěti sty zaměstnanci.

Seznam ukazuje:

- aktivní nebo neaktivní stav osoby;
- co je nejbližší konkrétní krok k dokončení karty, například doplnění bydliště,
  identifikátoru nebo pracovního vztahu;
- počet a druh pracovních vztahů;
- původní vztah převzatý z Mzdové rekapitulace.

Akce v řádku odpovídá tomuto kroku, například **Doplnit bydliště**; u hotové
karty se jmenuje **Otevřít kartu**. Detail nejdřív ukáže čtecí souhrn běžných
údajů. Citlivé hodnoty zůstávají maskované a odkryjí se jen samostatnou
oprávněnou akcí. Editor se otevře až tlačítkem **Upravit**, takže pouhá kontrola
karty nezobrazuje desítky vstupních polí. Technická verze záznamu se uživateli
nezobrazuje, ale dál se interně posílá při ukládání a chrání před přepsáním
souběžné změny.

V editoru **Běžné údaje zaměstnance** bez přepínání záložek upravíš jméno
a příjmení, rodné číslo, bydliště, e-mail, telefon, týdenní pracovní dobu
a pravidelnou hrubou mzdu. Stát bydliště se vybírá ze společného číselníku
zemí. Pokud je číselník dočasně nedostupný, formulář dovolí ručně zadat
dvoupísmenný ISO kód, aby úpravu adresy nezablokoval výpadek sítě. Změna
jména, bydliště, kontaktu, pracovní doby nebo mzdy nevynuluje historii:
starší záznam uzavře a založí novou účinnou verzi; záznam založený tentýž den
lze ještě opravit na místě. Uzavřená historická adresa se při založení nové
adresy nemění. Pokud je u osoby uloženo rodné příjmení, nová verze jména je
bez jeho odkrytí bezpečně převezme na serveru. Jméno a příjmení se zadávají
samostatně a systém je nikdy neodhaduje z celého zobrazovaného jména. Osobní
profil a primární pracovní vztah se ukládají jednou transakcí, takže při chybě
nezůstane změněná jen jedna část.

Na telefonu se seznam automaticky mění z tabulky na karty. Historii identit,
adres a kontaktů, výplatní účty a další méně časté údaje otevřeš pod formulářem
ve sbalené části **Úplná osobní evidence a historie**. Nejde o nahrazenou nebo
ztracenou evidenci: zůstávají zde vazby 1:N pro historické identity, adresy,
kontakty, výplatní účty a jejich období účinnosti. Také u všech historických
adres se stát vybírá ze stejného číselníku. U citlivých údajů se zobrazuje
pouze maska; novou hodnotu zadej jen tehdy, když ji chceš změnit. Po uložení
aplikace otevřenou hodnotu z formuláře odstraní.

Každá verze jména má sbalenou část **Údaje pro registraci zaměstnance**.
Zadává se v ní titul před a za jménem, datum a místo narození, stát narození,
státní občanství a pohlaví používané registračním formulářem ČSSZ. Běžnou práci
s kartou tato pole nezahltí; rozbal je při nástupu nebo při opravě registrační
identity. Státy vyber ze stejného číselníku zemí jako u adres. Údaje se uloží
do konkrétní historické verze a platí od data **Platí od** uvedeného nad nimi.
Při registraci pracovního vztahu proto aplikace použije verzi účinnou k datu
nástupu, nikoli dnešní nebo poslední zadanou hodnotu. Prázdné nepovinné pole lze
doplnit později; test registrace pak přesně řekne, který údaj ještě chybí.

Výplatní účet musí mít název, období účinnosti a rozdělení výplaty. Před
zařazením do platební dávky jej samostatně ověř tlačítkem **Ověřit účet** a
uveď druh podkladu i datum ověření. Máš-li ve formuláři neuloženou změnu účtu,
ověření je zablokované: nejdříve kartu ulož, aby se nikdy neověřila předchozí
uložená hodnota pod nově zobrazenými údaji. Každá pozdější změna čísla účtu,
účinnosti nebo aktivního stavu ověření automaticky zneplatní.

### 69.8.1 Vyživované osoby a daňové zvýhodnění na dítě

Ve sbalené části **Úplná osobní evidence a historie** je pod osobním profilem
sekce **Vyživované osoby a daňové zvýhodnění**. Eviduje děti, na které se
uplatňuje měsíční daňové zvýhodnění podle § 35c zákona o daních z příjmů,
a manžela nebo partnera, u kterých lze slevu uplatnit až v ročním zúčtování.

U osoby zadáš vztah k poplatníkovi, jméno, datum narození, volitelné rodné
číslo, průkaz ZTP/P, soustavné studium a období, po které je osoba vyživovaná.
Rodné číslo dítěte se ukládá šifrovaně, v seznamu i v detailu se zobrazuje jen
maskované a odkrýt je lze pouze auditovaným odhalením citlivých údajů.

Samotná evidence osoby ještě nezakládá nárok. Ten vzniká až **uplatněním**
s vlastním obdobím účinnosti, kde uvedeš:

- **pořadí dítěte** — určuje výši zvýhodnění a patří k uplatnění u konkrétního
  poplatníka, ne k dítěti; dvě děti nesmí mít v jednom měsíci stejné pořadí;
- **ZTP/P** — zvýhodnění za dítě s průkazem ZTP/P je dvojnásobné a zaškrtnout
  je lze jen tehdy, je-li ZTP/P vedeno i u samotné osoby;
- **důvod a stav ověření** — podepsané prohlášení poplatníka musí být platné
  k počátku nároku; volitelně lze přidat odkaz do mzdové dokumentace, ale jeho
  vyplnění není podmínkou uložení ani výpočtu;
- **potvrzení společně hospodařící domácnosti a druhého poplatníka** — chybí-li,
  výpočet skončí v ruční kontrole.

Aplikace nedovolí dvě překrývající se uplatnění na totéž dítě u jednoho
poplatníka ani uplatnění mimo období, kdy je osoba vedena jako vyživovaná.
Uplatňuje-li totéž dítě (rozpoznané podle rodného čísla) ve stejném měsíci jiný
zaměstnanec téže firmy, uložení se odmítne.

Sazby zvýhodnění se berou z legislativního rulesetu. Pokud pro dané období
žádná účinná sazba neexistuje, aplikace částku neodhaduje — označí nárok
k ruční kontrole.

Nárok zasahující do měsíce uzavřeného schválenou mzdovou revizí se věcnou
změnou nepřepisuje. Původní záznam se ukončí posledním zmrazeným měsícem
a vznikne nová účinná verze od měsíce následujícího, takže historický výsledek
zůstane nedotčený. Ukončení nároku mimo zmrazené období se provede běžnou
úpravou data „Nárok do".

### 69.8.2 Zákonná evidence osoby

Pod běžnými údaji zaměstnance je sekce **Zákonná evidence osoby**. Vede právní
skutečnosti, ze kterých vychází zákonný výpočet:

- **prohlášení poplatníka k dani** — rozhoduje, zda se uplatní měsíční slevy
  a zvýhodnění, nebo se sráží daň bez nich;
- **daňová rezidence** — rezident, nerezident (se zemí), nebo neověřeno;
- **příslušnost k sociálnímu pojištění** včetně formuláře A1 u zahraničního
  režimu;
- **sleva pro pracujícího poplatníka v důchodu**;
- **příslušnost ke zdravotnímu pojištění** a zdravotní pojišťovna;
- **měsíční evidence zdravotního minima** — kdo za daný měsíc doplácí do
  minimálního vyměřovacího základu.

Chybí-li kterýkoli z prvních pěti údajů, mzdový běh zákonný výpočet této osoby
nespočítá a skončí v ručním posouzení. Sekce proto v hlavičce ukazuje počet
chybějících údajů a uvnitř je vyjmenuje pro konkrétní měsíc; datum **Ke kterému
dni** určuje, který měsíc se kontroluje.

Měsíční evidence zdravotního minima je **nepovinná**. Není-li za měsíc zadaná,
platí zákonný výchozí stav podle § 3 odst. 10 zákona č. 592/1992 Sb.: doplatek
do minimálního vyměřovacího základu hradí zaměstnanec. Zadává se tedy jen tehdy,
když je skutečnost jiná — doplatek jde k tíži zaměstnavatele, protože nižší
základ způsobily překážky na jeho straně, nebo si zaměstnanec
při souběhu zvolil pro doplatek jiného zaměstnavatele. Rozklad pojistného u
schválené mzdy pak ukazuje i to, jestli hodnota vznikla zápisem, nebo odvozením
ze zákona. Volba **neověřeno** dál znamená ruční posouzení.

Ověřené hodnoty (český nebo zahraniční režim, ověřená pojišťovna, platný A1)
jsou rozhodnutím uživatele. **Odkaz na podklad je všude volitelný**: lze zvolit
typický podklad nebo přes volbu **Jiné** zapsat konkrétní číslo dokladu (písmena,
číslice a znaky `.`, `:`, `/`, `_`, `-`), ale prázdné pole uložení, výpočet ani
podání neblokuje. Aplikace žádný domnělý odkaz sama nevytváří. Za ověření
správnosti právní skutečnosti odpovídá uživatel. Varianta **neověřeno** se dál
ukládá jako důvod ručního posouzení.

Tlačítko **Přidat záznam** předvyplní běžný český případ: daňový rezident ČR,
český sociální i zdravotní režim, formulář A1 se netýká, sleva pracujícího
důchodce se neuplatňuje a zdravotní pojišťovna je ta, u které je osoba dosud
vedená (jinak výchozí pojišťovna zaměstnavatele z nastavení mezd). U běžného
zaměstnance tak není co vyplňovat — stačí zkontrolovat a uložit.

Na co se evidence neptá, to si odvodí: u českého daňového rezidenta je stát vždy
ČR, u českého sociálního režimu je A1 vždy „netýká se". Tato pole se proto
nezobrazují a objeví se až po přepnutí na cizí režim — tehdy si evidence vyžádá
stát ze seznamu; odkaz k režimu zůstává nepovinný. Stát i zdravotní pojišťovna se vždy
vybírají ze seznamu, nepíšou se. Chybí-li něco, co server nepřijme, napíše to
evidence rovnou u záznamu i s tím, co s tím udělat.

Evidence se zadává **po celých měsících** a záznamy jedné řady musí na sebe
navazovat den po dni — čtecí cesta vyhodnocuje evidenci k prvnímu dni měsíce,
takže změna uprostřed měsíce by se buď ztratila, nebo by pro daný měsíc vznikly
dvě současně platné verze. Díra v řadě se odmítne už při uložení; jinak by se
projevila až tím, že mzdový běh za chybějící měsíc spadne do ručního posouzení.

Záznam, který začal před koncem posledního schváleného mzdového období, je
uzavřený: jeho začátek nejde posunout ani ho smazat. Věcná změna se do něj
nezapíše — původní záznam se ukončí posledním uzavřeným dnem a nová právní
skutečnost vznikne jako nový záznam od dalšího měsíce. Doplnit dosud chybějící
záznam do uzavřeného období naopak jde; nic tím nepřepisuje.

Celá sekce se ukládá jedním tlačítkem **Uložit**. Čtení stačí obecné oprávnění
pro mzdy, zápis vyžaduje **Spravovat zaměstnance** (`payroll.person.write`) —
evidence je vedená na osobě, ne na jednotlivém pracovním vztahu.

### 69.8.3 Pobytová a pracovní oprávnění cizinců

Ve sbalené části **Úplná osobní evidence a historie** je samostatná sekce
**Pobytová a pracovní oprávnění**. Každé oprávnění eviduje druh, označení,
stát vydání, počátek účinnosti, konec platnosti a autoritativní podklad ve
firemních Dokumentech. Osobní dokument, dokument jiné firmy nebo dokument
v koši aplikace nepřijme.

Historie se nepřepisuje. Prodloužení založte akcí **Navázat obnovení** u
předchozího oprávnění; aplikace zachová původní podklad a vytvoří nový
neměnný záznam. Jedno oprávnění může mít jen jedno přímé pokračování a
překrývající se záznam bez uvedeného předchůdce se odmítne.

Sekce upozorňuje na oprávnění, jejichž platnost skončila nebo skončí do
30 dnů. Čtenář mezd bez oprávnění k Dokumentům uvidí věcnou historii a
upozornění, nikoli odkaz na podklad. Zápis vyžaduje současně právo
**Spravovat zaměstnance** a právo číst firemní Dokumenty.

## 69.9 Pracovní vztah a předkontace

Jedna osoba může mít více samostatných právních vztahů. Rozlišení je důležité
pro výpočet, podání i účetnictví:

| Druh vztahu | Hrubý náklad | Závazek |
|---|---:|---:|
| pracovní poměr mimo výkon funkce, zaměstnání malého rozsahu, DPP, DPČ | 521 | 331 |
| příjem společníka ze závislé činnosti | 522 | 366 |
| odměna za výkon funkce člena orgánu | 523 | 366 |
| pojistné hrazené zaměstnavatelem | 524 | 336 |

Odměna jednatele za výkon funkce tedy není totéž co pracovní poměr jednatele
mimo výkon funkce ani jiný příjem společníka. Souběh se vede jako více vztahů
jedné osoby.

Převzatý legacy vztah zachovává dosavadní kontaci Mzdové rekapitulace. Před
ostrým použitím úplných mezd zkontroluj, zda právní titul odpovídá skutečnosti;
zejména starší karta „jednatel-společník“ sama nerozliší smlouvu o výkonu funkce
od ostatní závislé činnosti.

## 69.10 Životní cyklus vztahu

Nový vztah začíná jako **Plánovaný**. Stav se nemění volným přepsáním pole, ale
jen nabízenými akcemi:

`Plánovaný → Předregistrovaný → Aktivní → Přerušený → Skončený → Archivovaný`

Z přerušeného vztahu se lze vrátit do aktivního stavu nebo jej ukončit.
Plánovaný či předregistrovaný vztah lze samostatně označit jako **Nenastoupil**
a potom archivovat. U každé akce zvolíš datum účinnosti. Přeskočení povinného
kroku nebo návrat ze skončeného vztahu aplikace odmítne.

Skončení vztah nemaže. Zůstává dostupný pro pozdější doplatek, opravu, podání a
dohledání tehdy platných údajů. Archivace jej pouze odklidí z aktivního workflow.

## 69.11 Historie smluvních podmínek a souběhy

Tlačítko **Nová verze podmínek** založí další účinný interval. Předchozí verzi
uzavře dnem před novou účinností; starší mzdové období proto pozdější změna
nepřepíše. Historie drží zejména:

- uzavření smlouvy, plánovaný a skutečný nástup a dobu určitou;
- úvazek, týdenní hodiny, místo práce, pravidelné pracoviště, CZ-ISCO a druh
  činnosti;
- mzdovou účtárnu, pojistnou účast, A1 a cizí předpisy, rizikovou práci,
  daňový režim a prohlášení k dani;
- příznak primárního pracovního vztahu a důvod změny.

Formulář nové verze podmínek je rozdělený na dvě části. Nahoře je jen to, co se
běžně mění: účinnost, plánovaný nástup, **mzdová účtárna**, týdenní hodiny,
úvazek, data smlouvy, příznak primárního vztahu, prohlášení k dani a nepovinný
důvod změny. Zbytek — evidence pro JMHZ, režimy sociálního a zdravotního
pojištění, daňový režim, cizí předpisy, sazbová kategorie § 5a a sleva § 7a —
je ve sbalené části **Další údaje**; ta se sama otevře jen u vztahu, kde už je
něco z ní vyplněné.

Hvězdička u popisku označuje pole, bez kterého uložení neprojde. Důvod změny
mezi ně nepatří — vyplň ho, jen když chceš, aby v časové ose bylo vidět proč.

Mzdovou účtárnu vybíráš přímo na kartě vztahu. Je to jediné místo, kde jde
změnit: z účtárny vychází variabilní symbol zaměstnavatele pro odvod sociálního
pojistného a mzdový běh se dá na účtárnu zúžit, takže vztah bez ní nemá čím
vykázat odvod. Novému vztahu ji aplikace dosadí z výchozí účtárny
zaměstnavatele; firmě s víc účtárnami nabídne výběr už při zakládání vztahu.
Vztah, který účtárnu nemá (typicky převzatá data), na to na kartě upozorní —
uložení to ale nikde neblokuje, blokátorem se to stane až při uzamčení vstupů
mzdového běhu. V nabídce se objeví jen aktivní účtárny; deaktivovaná účtárna,
kterou vztah drží, v ní zůstává, aby ji úprava podmínek tiše nezměnila.

U odměny člena statutárního orgánu, u dohody o pracovní činnosti a u práce
společníka pro vlastní společnost přibývá v podmínkách pole **Účast na
nemocenském pojištění z odměny**. Rozhoduje o tom, jak se odměna zdaní, když
zaměstnanec nepodepsal prohlášení k dani (§ 6 odst. 4 písm. b) zákona o daních
z příjmů):

- **Zakládá účast** — sjednaná odměna dosahuje rozhodné částky, takže se sráží
  zálohová daň v každém měsíci.
- **Nezakládá účast** — měsíce, ve kterých odměna rozhodné částky nedosáhne
  (pro rok 2026 je to 4 500 Kč), se daní srážkovou daní 15 % ze samostatného
  základu; ostatní měsíce zálohou.
- **Neurčeno** — výchozí stav. Aplikace odpověď neodhaduje, protože za zařazení
  ručí plátce daně, a zákonný výpočet skončí ručním posouzením, dokud ji někdo
  nedoplní.

U pracovního poměru, zaměstnání malého rozsahu a dohody o provedení práce se
pole nenabízí — tam zařazení plyne přímo z druhu vztahu a aplikace si ho odvodí
sama.

Ve stejné verzi podmínek je skupina **JMHZ – vykonávaná pozice**. Eviduje
strukturovanou obec pracoviště, kód obce a stát, druh činnosti, bližší určení
pracovněprávního vztahu, příspěvek a nástroj APZ, funkční požitky a dočasné
přidělení. Druh činnosti i bližší určení se vybírají z připnutých číselníků
JMHZ. U druhů 1 až 9 je bližší určení povinným podkladem pro výběr scénáře;
chybějící hodnota se nikdy nevykládá jako „Žádné“. Příznaky používají tři stavy **Neověřeno**,
**Ne** a **Ano**; chybějící historický údaj se nikdy automaticky nepovažuje za
„ne“. Obec, její kód a stát se ukládají jen jako úplná trojice. Dokud nejsou
údaje vybrané z autoritativních číselníků CISOB a CZEM, aplikace kontroluje
shodu názvu obce s kódem i platnost státu. Obec se vybírá našeptávačem; stát z
připnuté nabídky. Podmínky před začátkem účinnosti připnutých číselníků nelze
takto označit jako ověřené. Budoucí personální změnu lze naplánovat podle
posledního připnutého snapshotu, ale mzdový snapshot ji pro JMHZ označí jako
neověřenou, pokud vykazované období přesahuje jeho ověřené pokrytí. Taková data
nesmějí projít budoucí readiness bránou ani se odeslat bez novějšího snapshotu.
Při dočasném přidělení
**Ano** je navíc nutné doplnit identitu alespoň jednoho uživatele; samotný
příznak nestačí k přípravě podání.

Jedna osoba může mít souběžně například HPP a DPP nebo samostatný pracovní poměr
a odměnu za výkon funkce. V aktivním workflow může být právě jeden vztah označen
jako primární. Každý souběh má vlastní kód, stav, historii a budoucí registrační
identitu.

### 69.11.1 OIČ / IK MPSV a ID PPV pro JMHZ

Po přidělení identifikátorů ČSSZ otevřete na kartě konkrétního pracovního vztahu
sbalenou sekci **Identifikátory JMHZ od ČSSZ**. Sekce se načte až po otevření,
takže ani firma se stovkami zaměstnanců neposílá zbytečně stovky dotazů.

Rozlišujte, komu údaj patří:

- **OIČ / IK MPSV** identifikuje osobu a použije se u jejích pracovních vztahů;
- **ID PPV** identifikuje právě jeden pracovní vztah. Souběžný HPP a DPP téže
  osoby proto mají stejný osobní identifikátor, ale každý vlastní ID PPV.

Identifikátory pro **Testovací prostředí** a **Produkci** jsou oddělené. Před
uložením vždy zkontrolujte zvolené prostředí, datum platnosti a oba údaje podle
protokolu ČSSZ. Přepnutí prostředí rozepsané hodnoty zahodí, aby se testovací
identifikátor omylem neuložil do produkce. Odkaz na zdroj je nepovinná interní
poznámka; uložení neblokuje. Povinné je pouze výslovné potvrzení, že jste údaje
ověřili v podkladu ČSSZ.

Po uložení aplikace zobrazuje jen masky a otevřené hodnoty už do formuláře
nevrací. Již platný identifikátor nelze tiše přepsat jinou hodnotou. Pokud ČSSZ
identifikátor opravila nebo změnila, nejprve ověřte datum účinnosti a navazující
protokol; nesprávnou hodnotu neobcházejte založením druhé osobní karty.

Pro zobrazení stačí právo číst mzdy. Uložení mění údaj osoby i pracovního
vztahu, a proto vyžaduje současně oprávnění spravovat zaměstnance i pracovní
vztahy. Nejde o pravidlo čtyř očí: uživatel s oběma oprávněními provede celý
krok sám.

## 69.12 Checklist a časová osa

Detail ukazuje povinnosti nástupu, změny a skončení. Patří sem smlouva nebo
dohoda, registrace a změny pro zdravotní pojišťovnu a ČSSZ/JMHZ, daňové
prohlášení, výstupní doklady, kontrola exekucí či insolvence a kontrola
pozdějšího doplatku. U každé položky je termín a stav **Nesplněno**,
**Splněno** nebo **Netýká se**.

Časová osa zachovává stavové přechody, změny checklistu i rozdíl každé smluvní
verze. Pokud jiný uživatel mezitím vztah změnil, starší formulář se neuloží a je
nutné načíst aktuální verzi.

### 69.12.1 Navazující agendy

Karta vztahu má sekci **Navazující agendy**. Vede z ní jedno kliknutí do každé
agendy, kde se k tomuto člověku dá něco pořídit — docházka a směny,
nepřítomnosti, mzdové vstupy, pracovní cesty, opakované složky, průměrný
výdělek, dohody o srážkách, exekuce, dokumenty a roční zúčtování. Cílová
obrazovka se otevře už zúžená na daného zaměstnance; zúžení je vidět v horní
liště a jedním tlačítkem se ruší. Zužuje server, ne jen zobrazená stránka —
hledaný člověk se najde, i kdyby jeho záznamy ležely až na několikáté straně,
a stránkování i počty mluví o zúženém seznamu. Když zúžení nedá žádný záznam
(cizí nebo zaniklý vztah, zestaralý odkaz), řekne to lišta větou; prázdná
tabulka bez vysvětlení se nezobrazí.

Pod tlačítky je souhrn: u agend, ve kterých něco je, počet záznamů, datum
posledního a případně částka. Agendy, ve kterých zatím nic není, se jmenují
jednou nenápadnou větou pod souhrnem. Agenda, na kterou uživatel nemá
oprávnění, se nenabízí ani nezapočítává.
