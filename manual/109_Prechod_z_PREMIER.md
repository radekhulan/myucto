# 109. Přechod z PREMIER

> Návod, jak převést vybrané účetní roky z programu PREMIER do firmy v MyÚčtu:
> od zálohy dat v PREMIERu přes zkoušku nanečisto a ostrý převod až po kontrolu
> převzatých dat. Pro účetní a správce, kteří přecházejí z PREMIERu.

**Cesta: `Systém → Přechod z jiných účetních systémů → PREMIER`**

Průvodce převádí **účetnictví** a k němu **zaměstnance a zpracované mzdy**
([§ 109.8.2.1](#109821-zamestnanci-a-mzdy)). Mzdové zápisy jsou v převedeném
deníku, mzdy se proto převezmou jako evidence předchozího systému a žádný
účetní zápis nezaloží. Na rozdíl od POHODY tu není samostatný exportní nástroj
ke stažení, zálohu vytvoříte přímo v PREMIERu.

## 109.1 Kdy to potřebujete

- Firma dosud účtovala v PREMIERu a chce pokračovat v MyÚčtu s deníkem,
  doklady, bankou, majetkem a mzdami.
- Převádíte další rok z již nahrané zálohy.
- Převod skončil chybou nebo rozdílem v protokolu.
- Chcete po převodu ověřit, že MyÚčto sedí na PREMIER, včetně kontroly proti
  podáním (KH, DPPO).

<!-- cols: 24 40 36 -->
| Fáze | Co udělat | Kde |
|---|---|---|
| 1 | Vytvořit zálohu dat | PREMIER, `Správce → Záloha dat` (F11) |
| 2 | Nahrát zálohu, zvolit roky | `Systém → Přechod z jiných účetních systémů → PREMIER`, krok **Záloha z PREMIER** |
| 3 | Zkouška nanečisto | krok **Zkouška nanečisto** |
| 4 | Ostrý převod | krok **Převod** |
| 5 | Kontrola převzetí | protokol, `Účetnictví`, obratová předvaha |
| 6 | Doplnit, co se nepřevádí | `Mzdy`, `Dokumenty → Skeny k dokladům` |

## 109.2 Než začnete

1. **Firma v MyÚčtu.** Musí existovat a mít vyplněné stejné IČO jako v PREMIERu.
   Převádí se do firmy, ve které právě pracujete; novou firmu nejdřív založte
   (kapitola [Multi supplier](95_Multi_supplier.md)). Firma, která zatím vede
   daňovou evidenci, se převodem přepne na podvojné účetnictví od začátku
   převáděného roku.
2. **Oprávnění.** Průvodce vidí a zkoušku nanečisto spouští uživatel
   s oprávněním k zápisu importů. Ostrý převod zapisuje účetní deník a mění
   nastavení firmy, proto vyžaduje navíc zápis do účetního deníku a do
   nastavení firmy (`utilities.import`, `accounting.journal.write`,
   `settings.company.write`). Chybějící oprávnění průvodce ukáže a převod nespustí.
   Položka je v menu Systém, které vidí administrátor; jiný uživatel otevře
   průvodce přímým odkazem `/imports/premier`.
3. **Pořadí let.** Převádějte od nejstaršího roku a nevynechávejte starší
   nepřevedený rok ([§ 109.8.1.2](#109812-poradi-let)).
4. **Klid ve firmě** pro zkoušku nanečisto.

> [!TIP]
> Převod je určen pro menší množství dat. Převody z jiných systémů jejich
> výrobci nepodporují. Průvodce nabízí kontakt na podporu, která převod provede
> nebo upraví na míru. Převzatá data si ověřte vždy.

## 109.3 Krok za krokem: záloha dat v PREMIERu

1. V PREMIERu otevřete **Správce → Záloha dat** (klávesa **F11**).
2. Zálohu uložte na disk. PREMIER nabízí formát **iZIP** nebo **iCAB**,
   průvodci vyhovuje kterýkoli z nich. Záloha ve formátu iCAB musí být jeden
   soubor s kompresí MSZIP, jak ji PREMIER ukládá; jiný archiv CAB uložte v
   PREMIERu jako iZIP.
3. Soubor nerozbalujte, nahrajete ho beze změny. Může mít až 2 GB.

**Jak poznáte, že je hotovo:** Máte soubor `.izip` nebo `.icab` se všemi
účetními roky vedenými v PREMIERu.

## 109.4 Krok za krokem: nahrání zálohy a zkouška nanečisto

1. Otevřete `Systém → Přechod z jiných účetních systémů` a u dlaždice **PREMIER** klikněte na **Otevřít průvodce**.
2. V kroku **Záloha z PREMIER** klikněte na **Soubor zálohy (.izip nebo
   .icab)**, vyberte soubor a klikněte na **Nahrát a načíst**. Stránku nechte
   během nahrávání otevřenou. Průvodce ukazuje procenta a při výpadku spojení
   naváže. Rozbalení a načtení běží na serveru na pozadí, u velké zálohy i
   několik minut; obnovení stránky průvodce nepřeruší.
3. V kroku **Náhled a volby** v tabulce **Roky v záloze** zkontrolujte
   **IČO**, **Firma**, **Rok** a **Zápisů v deníku**. Roky k převodu zaškrtněte
   ve sloupci **Převést**; předvybrané jsou všechny roky s IČO vaší firmy.
4. Zkontrolujte **Kontrolu před převodem** pro každý vybraný rok. Převod se
   zastaví, když u kteréhokoli roku záloha patří firmě s jiným IČO, chybí deník,
   osnova nebo číselník kódů DPH, účetní období v MyÚčtu už obsahuje zápisy,
   které nevznikly převodem, nebo je období uzavřené.
5. Klikněte na **Pokračovat** a v kroku **Zkouška nanečisto** na **Spustit
   zkoušku nanečisto**. Proběhne celý převod vybraných roků včetně
   rekonciliace a kontroly proti podáním, na konci se ale všechno vrátí.
6. Přečtěte protokol za každý rok. Chyby opravte a zkoušku zopakujte
   (**Zkoušku zopakovat**).

**Jak poznáte, že je hotovo:** Každý rok skončí stavem **V pořádku** nebo **S
upozorněními**. Selže-li zkouška jen na **Rozdílech k přijetí**, postupujte podle
[§ 103.10.5.1](103_Prechod_z_Money_S3.md#1031051-chyby-upozorneni-a-rozdily-k-prijeti).

> [!WARNING]
> Zkouška převádí každý rok samostatně a hned ho vrací zpět. Pozdější rok
> proto ve zkoušce nevidí data předchozího roku (převzaté doklady, uzávěrku) a
> jeho výsledek se od ostrého převodu může lišit.

## 109.5 Krok za krokem: ostrý převod

1. V kroku **Převod** zaškrtněte potvrzení, že rozumíte dopadu převodu.
   Potvrzení vyjmenuje převáděné roky.
2. Po zkoušce, která selhala jen na rozdílech k přijetí, zaškrtněte navíc
   **Převést i přes rozdíly**.
3. Klikněte na **Spustit převod**. Převod běží na pozadí, stránku můžete
   zavřít. Roky se převádějí vzestupně jeden po druhém a průběh ukazuje, který
   rok z kolika právě běží. Převod můžete zastavit tlačítkem **Zastavit převod**.
4. Po dokončení klikněte na **Otevřít účetní deník** nebo **Obratová
   předvaha**.

**Jak poznáte, že je hotovo:** Všechny vybrané roky skončí stavem **V pořádku**
nebo **S upozorněními**. Skončí-li rok chybou nebo převod zrušíte, další roky se
nespustí a průvodce je vypíše. Převod jedné firmy běží vždy jen jeden.

Další rok převedete později stejným postupem ze stejné zálohy (zaškrtněte další
rok v pořadí).

## 109.6 Krok za krokem: kontrola převzetí

1. Otevřete protokol každého roku (přehled **Protokoly převodů** pod
   průvodcem). Zkontrolujte rekonciliaci: obratová předvaha MyÚčta proti
   předvaze spočtené přímo z deníku PREMIER na haléř, včetně počátečních stavů.
2. Projděte upozornění v části **Kontrola proti podáním z PREMIER**: kontrolní
   hlášení DPH za každý měsíc a přiznání k DPPO. Rozdíl neznamená chybu převodu
   ([§ 109.8.5](#10985-rekonciliace-kontrola-a-protokol)).
3. V protokolu projděte po měsících upozornění, kde se mzdy liší od deníku.
4. Otevřete obratovou předvahu a porovnejte syntetické účty s výstupy z
   PREMIERu.
5. Koncepty k ruční kontrole (doklady s nejistou daňovou povahou) opravte a
   potvrďte. Doklady s kurzem a klasifikací DPH zkontrolujte podle poznámek v
   protokolu.
6. Projděte, co protokol označil k ověření u mezd: druh vztahu, výplatní
   účty (založené jako neověřené), OIČ bez přijatého hlášení, exekuční případy a
   dohody o srážkách, mzdové složky pro první měsíc vedený v MyÚčtu.
7. Zkontrolujte, zda se uzavřené roky uzavřely i v MyÚčtu; jinak je uzavřete
   v `Nástroje → Uzávěrka` ([§ 109.8.6.1](#109861-uzaverka-uzavrenych-roku)).
8. Doplňte, co se nepřevádí ([§ 109.8.3](#10983-co-prevod-neprenese)):
   mzdové složky a pravidelné předpisy, skeny dokladů v
   `Dokumenty → Skeny k dokladům`.

**Jak poznáte, že je hotovo:** Kontroly rekonciliace projdou, nezbývají doklady
v konceptu ani nepřijaté rozdíly a obraty v MyÚčtu odpovídají PREMIERu.

## 109.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Záloha neobsahuje žádný rok s IČO firmy v MyÚčtu** | IČO v záloze se liší od IČO firmy | Zkontrolujte IČO v nastavení firmy |
| **Firma ... nemá vyplněné IČO** | Chybí IČO v MyÚčtu | Doplňte IČO a nahrajte zálohu znovu |
| Kontrola před převodem hlásí chybu | Jiné IČO, chybí deník/osnova/číselník kódů DPH, zápisy v období nebo uzavřené období | Opravte příčinu a nahrajte zálohu znovu |
| Nahrávání se přerušilo | Výpadek spojení | Vyberte soubor a nahrajte ho znovu |
| Zkouška selhala jen na rozdílech k přijetí | Doklad se nepřevedl, nebo výsledek nesedí na PREMIER | Zaškrtněte **Převést i přes rozdíly**, nebo opravte a zopakujte |
| Rok se po převodu neuzavřel | Uzávěrka by musela zaúčtovat něco navíc, nebo něco nesouhlasí | Uzavřete ručně v `Nástroje → Uzávěrka` |
| Mzdy se nepřevedly | Licence bez mzdového doplňku | Zakupte doplněk a převod roku zopakujte |
| Doklad je koncept | Kód opravy podle § 44 nebo § 74, nejistá daňová povaha | Opravte klasifikaci DPH a potvrďte |
| Rozdíl proti podání KH nebo DPPO | Doklad upravený po podání, kód DPH mimo KH v PREMIERu, dřívější odpočet, zaokrouhlení | Posuďte podle příčin v [§ 109.8.5](#10985-rekonciliace-kontrola-a-protokol) |
| Chybí docházka a složky mezd | Nepřevádějí se | Zadejte je v modulu Mzdy |

## 109.8 Podrobnosti a pravidla

### 109.8.1 Záloha z PREMIER

#### 109.8.1.1 Co je v záloze

- Záloha obsahuje **všechny účetní roky** vedené v PREMIERu, ne jen jeden.
  Průvodce v ní najde všechny roky a k převodu nabídne ty, které podle IČO
  patří firmě v MyÚčtu.
- Firma musí v MyÚčtu existovat a mít vyplněné stejné IČO jako v PREMIERu.
  Převádí se do firmy, ve které právě pracujete; novou firmu nejdřív založte
  (kapitola [Multi supplier](95_Multi_supplier.md)).
- Nahraná záloha zůstává na serveru pro převod dalších let. Aplikace ji smaže
  po 7 dnech, kdy se s ní nepracovalo.
- Soubor může mít až 2 GB. Průvodce ho posílá po částech a ukazuje průběh
  v procentech; při výpadku spojení část zopakuje a naváže tam, kde server
  data má. Stránku nechte během nahrávání otevřenou.

#### 109.8.1.2 Pořadí let

Záloha nese celé účetnictví najednou a převádějí se roky, které zaškrtnete
v náhledu (jeden i víc najednou). Vybrané roky převod projde **vzestupně od
nejstaršího**, každý s vlastním protokolem. Nevynechávejte nepřevedený starší
rok. PREMIER
počáteční ani uzávěrkové zápisy do deníku neukládá, převod proto počáteční
stavy roku spočte z deníku všech předchozích let v záloze: zůstatky
rozvahových účtů a výsledek hospodaření minulých let na účet 431. Doklady
předchozích let ale převede jen převod těch let, a proto se vyplatí začít
nejstarším. Průvodce v přehledu předvybere všechny roky zálohy s IČO firmy.
Další rok převedete i později zopakováním postupu (§ 109.8.4).

### 109.8.2 Co převod přenese

| Z PREMIER | Do MyÚčta |
|---|---|
| účtová osnova (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy (`518100` → `518.100`) včetně daňové uznatelnosti účtu |
| účetní rok | účetní období 1. 1. až 31. 12. |
| počáteční stavy spočtené z předchozích let | otevírací zápis k 1. dni období (účty proti 701, výsledek na 431) |
| účetní deník | účetní zápisy, přesná kopie |
| adresář partnerů | klienti, párování podle IČO |
| přijaté a vydané faktury a zálohové listy včetně položek | doklady se stavem zaúčtováno nebo uhrazeno; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| pokladní doklady z deníku | pokladní doklady, u tuzemského kódu DPH i s řádky DPH |
| ostatní doklady s DPH mimo faktury (bankovní poplatky, interní doklady) | přijaté nebo vydané doklady s položkami po kódech DPH |
| bankovní řady deníku | výpisy a bankovní pohyby v měně účtu |
| vazby úhrad na faktury | spárování faktury s bankovním pohybem nebo pokladním dokladem |
| dlouhodobý majetek (řady hmotného a nehmotného majetku) | karty s daňovými a účetními odpisy let převodu |
| drobný majetek (řady drobného majetku, operativní evidence) | karty evidence drobného majetku |
| zaměstnanci a pracovní vztahy (pracovní poměr, DPP, DPČ, jednatel) | osoby a pracovní vztahy v modulu Mzdy |
| zpracované mzdy po měsících | převzaté mzdy předchozího systému, bez účetních zápisů |
| ruční úpravy základu daně z přiznání k DPPO | položky rozpracovaného přiznání k DPPO |
| uzavřený rok | uzávěrka roku v MyÚčtu (702/710) a navazující počáteční stavy |

**Faktura v cizí měně.** Převezme se v měně a kurzu dokladu, když částky
položek v měně přepočtené kurzem dávají na haléř koruny ze zaúčtování
v deníku (z nich PREMIER počítá přiznání). DPH, kontrolní hlášení i deník
tak zůstávají v Kč přesně stejné a platbu v měně jde spárovat s fakturou
včetně kurzového rozdílu. Když přepočet na haléř nesedí (položka složená
z deníku, haléřový rozdíl kurzu dorovnaný na největší položku), u samovyměření,
u částečně uhrazené vydané faktury nebo když firma nemá měnu v číselníku měn,
se faktura převede v Kč podle zaúčtování a poznámka dokladu i protokol uvedou
důvod.

**Klasifikace DPH.** PREMIER vede u každé položky dokladu kód DPH a jeho
definici v číselníku kódů: řádky přiznání a oddíl kontrolního hlášení. Převod
položku klasifikuje **podle definice kódu**, ne podle jeho čísla, účtu ani
textu dokladu. Platí to i pro samovyměření u přijatých plnění (pořízení
zboží a služby z EU, služby ze třetích zemí, dovoz, tuzemský přenos
daňové povinnosti): položka dostane nulovou daň a kód zařazení a daň na
výstupu i odpočet dopočte evidence DPH MyÚčta. Nezáleží na tom, jestli
účetní samovyměření zaúčtovala na účet 343. Vydaná položka s kódem mimo
přiznání, která nese daň, je plnění v režimu OSS a posoudí ji stejné
pravidlo jako ostatní importy.

**Období odpočtu.** Datum pro DPH a datum pro kontrolní hlášení
z přijaté faktury převod přebírá. Pokud PREMIER uplatnil odpočet dřív, než
je datum plnění nebo vystavení dokladu, MyÚčto ho tak brzy nepřipustí a
doklad zařadí do období podle data dokladu. Protokol takový doklad vypíše.

**Majetek.** PREMIER vede karty majetku v řadách podle druhu evidence. Karty
řad hmotného a nehmotného majetku se převedou jako dlouhodobý majetek
s odpisy. Karty řad drobného neodpisovaného majetku a operativní evidence
ostatního majetku se převedou do evidence drobného majetku (název,
inventární číslo, datum pořízení, cena, umístění, odpovědná osoba,
vyřazení). Evidence finančního majetku, leasingu, rezerv a ostatní
evidence se nepřevádí, účetně je v převedeném deníku a protokol ji vypíše.

**Drobný majetek bez evidence v PREMIERu.** Když účetní drobný majetek
v PREMIERu jako evidenci nevedla a účtovala ho jen do nákladů, převod karty
odvodí z přijatých faktur: položka zaúčtovaná na účet, který osnova
PREMIERu pojmenovává jako drobný majetek (například „Spotřeba materiálu -
dr. majetek"), s cenou za kus od 1 000 Kč bez DPH dostane kartu drobného
majetku, levnější zůstane materiálem. Dobropis, který věc vrací, kartu
vyřadí, pokud jde jednoznačně určit (stejný dodavatel a název, případně
cena). Jinak ho protokol vypíše k ručnímu vyřazení.

**Zaúčtování se nepřepočítává.** Deník je přesná kopie toho, co bylo
v PREMIERu, a doklady se k němu jen připojí. Zápisy na 702 a 710 se
nepřebírají, rok uzavře průvodce uzávěrkou MyÚčta (§ 109.8.6).

**Doklady k ruční kontrole.** Doklad, jehož daňovou povahu záloha spolehlivě
neurčuje (například kód opravy podle § 44 nebo § 74), převod převezme jako
koncept. Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do
účtování. Protokol ho vypíše i s důvodem. Po opravě klasifikace DPH ho
potvrďte.

Číslo dokladu, které už ve firmě je, dostane příponu roku.

#### 109.8.2.1 Zaměstnanci a mzdy

Firmě, která mzdy v MyÚčtu ještě nemá, převod modul Mzdy zapne. Začátek
vedení mezd v MyÚčtu nastaví na měsíc po poslední mzdě zpracované v PREMIERu
a chybí-li nastavení zaměstnavatele, založí ho s mzdovou účtárnou `MZDY`
a výchozími předkontacemi. Variabilní symbol ČSSZ, kód OSSZ a číslo plátce
zdravotního pojištění, které firma vede v Nastavení firmy, převezme do Mezd
a k variabilnímu symbolu založí registraci účtárny s účinností od začátku vedení
mezd (viz [§ 90.14.1](90_Nastaveni_mezd.md#90141-mzdove-uctarny-a-registrace-u-cssz)).
Co v Nastavení firmy není, ani účty institucí převod nevymýšlí; protokol
vypíše k doplnění v Mzdy → Nastavení jen to, co opravdu chybí. Zapnutý
modul, jeho začátek ani existující nastavení převod nemění. Když modul zapnout
nejde (licence bez mzdového doplňku), převede účetnictví, mzdy přeskočí
a protokol to řekne; po zakoupení doplňku převod roku zopakujte a mzdy se doplní.

- **Zaměstnanci.** Každý pracovní vztah z PREMIERu se založí jako osoba
  a pracovní vztah s osobním číslem z PREMIERu: jméno, rodné číslo, datum
  narození, stát narození, státní občanství, rodné příjmení, trvalá
  a kontaktní adresa, kontakty, druh vztahu (pracovní
  poměr, DPP, DPČ, jednatel), nástup, skončení, týdenní pracovní doba
  a sjednaná mzda včetně jejích změn. V zákonné evidenci osoby doplní daňovou
  rezidenci, prohlášení poplatníka po měsících (podepsané je jen v měsících,
  kde ho PREMIER vede jako podepsané; měsíce zdaněné srážkou mají prohlášení
  nepodepsané), historii zdravotních
  pojišťoven (pro měsíc se zpracovanou mzdou platí pojišťovna té mzdy, pro
  ostatní měsíce oznámení pojišťovnám), příslušnost k sociálnímu pojištění
  a slevu pracujícího důchodce. Doplňuje se jen to, co v MyÚčtu chybí. Vztah
  se stejným osobním číslem a jménem, který ve firmě už je, převod převezme
  místo založení nového. Učně (kategorie UCN) převod nezakládá, MyÚčto pro
  něj druh vztahu nemá; protokol ho vypíše.
- **Registrace ČSSZ.** Přihlášky, dohlášení a odhlášky (REGZEC) a částečná
  přihlášení (PREZEC), které PREMIER odeslal a ČSSZ přijala, převod zapíše
  stejným importem registrací jako Mzdy → Importy, věty po jedné v pořadí
  odeslání. Z nich se doplní profil přihlášky A1, OIČ a ID pracovněprávního
  vztahu, rezidence, adresy po složkách, důchodové údaje a další pole, takže
  je nemusíte zadávat ručně. Podání, které ČSSZ odmítla, a věty odmítnuté
  uvnitř přijatého podání se nepřebírají. Věta, ke které převod nezná vztah
  (PREMIER ho eviduje s jiným nástupem nebo druhem vztahu, než hlásil
  ČSSZ), vztah nezaloží; protokol ji vypíše k ověření. Věta nese variabilní
  symbol zaměstnavatele: pokud je ve mzdové účtárně firmy vyplněný jiný,
  import větu zablokuje a protokol to řekne. Opakovaný převod už zapsané věty
  nepřepisuje.
- **Podání dávek ČSSZ.** Oznámení o žádosti o dávku (NEMPRI) a hlášení při
  ukončení pracovní neschopnosti (HZUPN), které PREMIER odeslal a ČSSZ přijala,
  převod zapíše stejným importem jako Mzdy → Importy. K převzatému vztahu
  vznikne případ dávky, ve kterém je podání vedené jako vyřízené předchozím
  programem, takže ho hlídač lhůt znovu nepožaduje. HZUPN k neschopnosti,
  ke které ho PREMIER neodeslal, zůstává v hlídači otevřené. Věty jdou po jedné
  v pořadí odeslání a jen ty odeslané do konce převáděného roku. Podání
  nemocenského se páruje se schválenou neschopností v měsíci události; podání,
  ke kterému převod neschopnost nebo jednoznačný vztah nemá, se nezapíše
  a protokol ho vypíše s důvodem. Podání odmítnutá ČSSZ se nepřebírají
  a opakovaný převod zapsaná podání nepřepisuje.
- **Evidence JMHZ.** OIČ a ID pracovněprávního vztahu z posledního hlášení
  JMHZ, které ČSSZ přijala, pracoviště (obec a stát), kód CZ-ISCO a doklady
  k Zákonným termínům (přihlášky a odhlášky ČSSZ a zdravotní pojišťovně,
  ELDP, prohlášení poplatníka). Čísla bez přijatého hlášení protokol vypíše
  k ověření.
- **Karta osoby.** Děti s daňovým zvýhodněním podle skutečného uplatnění
  v mzdách, výplatní účty (aktuální s výplatou, dřívější bez ní) a účty
  zdravotních pojišťoven, ČSSZ a finančního úřadu. Účty ČSSZ a finančního
  úřadu z nastavení mezd PREMIERu se zakládají jako převzaté a před první
  platbou je porovnejte s rozhodnutím úřadu.
- **Nepřítomnosti, dovolená a průměry.** Nepřítomnosti s daty, zůstatek
  dovolené a průměrné výdělky čtvrtletí převáděného roku, jen z měsíců před
  začátkem vedení mezd v MyÚčtu. Pracovní neschopnost, která trvá přes
  převáděné období, navazuje až do konce případu eNeschopenky.
- **Srážky.** Exekuce a insolvence, které na konci zpracovaných mezd trvají,
  se založí jako nedoložené exekuční případy se zbývající pohledávkou
  a příjemcem, ostatní trvalé srážky jako dohody o srážkách. Exekuční případ
  před aktivací ověřte proti spisu.
- **Zpracované mzdy.** Každý měsíc do konce převáděného roku se uloží jako
  převzatá mzda předchozího systému: hrubý příjem, vyměřovací základy,
  pojistné zaměstnance i zaměstnavatele, záloha a srážková daň, daňový bonus,
  čistá mzda, částka k výplatě a doby pojištění včetně vyloučených dob
  (kalendářní dny nemoci a neplaceného volna). Z nich vznikne převzatý
  mzdový běh, evidenční list důchodového pojištění za rok přechodu
  a srovnávací sestava převzatých mezd. Měsíce od začátku vedení mezd
  v MyÚčtu se nepřebírají, ty počítá MyÚčto.
- **Počáteční stavy ročních kumulací.** Za měsíce roku, ve kterém začíná
  vedení mezd v MyÚčtu, před jeho prvním měsícem převod zapíše počáteční
  stavy kumulací (roční zúčtování daně a potvrzení o zdanitelných příjmech
  na ně navážou). Začátek vedení mezd, který firma nemá, nastaví převod na
  měsíc po poslední mzdě z PREMIERu; jiný začátek nastavte v Mzdy → Nastavení
  ještě před převodem posledního roku.

Zkontrolujte po převodu:

- druh vztahu u zaměstnanců, u kterých ho protokol označil jako odvozený,
- výplatní účty: převod je založí jako neověřené, ověřte je na kartě osoby,
- položky, které protokol označil k ověření (OIČ bez přijatého hlášení,
  děti s příznakem jiné vyživující osoby, důchodci bez slevy, neschopnost
  bez známého konce, věty registrací bez odpovídajícího vztahu, nezapsaná
  podání dávek),
- exekuční případy a dohody o srážkách,
- mzdové složky a pravidelné předpisy pro první měsíc vedený v MyÚčtu,
- upozornění rekonciliace mezd proti deníku (§ 109.8.5).

### 109.8.3 Co převod nepřenese

- **Docházka a složky mezd.** Mzdové složky jednotlivých měsíců se
  nepřevádějí. Z trvalých karet vztahu převod založí jen osobní ohodnocení
  pevnou částkou, a to jako opakovanou složku *Osobní ohodnocení*
  (pravidelná odměna) pro měsíce, které počítá MyÚčto; karta, která na konci
  převáděného období už skončila, se nepřevezme. Příspěvek na penzijní
  připojištění, stravenkový paušál a příspěvek na praní zadejte v modulu Mzdy.
- **Doklad a DIČ nerezidenta.** Stát daňové rezidence nerezidenta převod vezme
  z karty nerezidenta; číslo dokladu a DIČ v zemi rezidence doplňte ručně.
- **Sklad, zakázky a CRM.** Zápisy jsou v převedeném deníku, evidence se
  zakládá v MyÚčtu.
- **Objednávky, nabídky a přílohy dokladů.** Skeny dokladů připojíte zvlášť
  v `Dokumenty → Skeny k dokladům`.
- **Podaná přiznání a hlášení.** Zůstávají v PREMIERu, proti nim ale
  probíhá kontrola, viz § 109.8.5.

### 109.8.4 Postup

1. **Záloha z PREMIER.** Vytvořte zálohu (109.1) a nahrajte soubor `.izip`
   nebo `.icab`. Rozbalení a načtení běží na serveru na pozadí, u velké
   zálohy i několik minut; obnovení stránky mezitím průvodce nepřeruší.
2. **Náhled a volby.** Tabulka ukáže roky nalezené v záloze s IČO firmy
   a počtem zápisů deníku. Roky k převodu zaškrtněte v prvním sloupci
   tabulky; předvybrané jsou všechny (§ 109.8.1.2).

   Kontrola před převodem se ukáže pro každý vybraný rok zvlášť. Převod
   zastaví, když u kteréhokoli vybraného roku:
   - záloha patří firmě s jiným IČO,
   - v záloze chybí deník, osnova nebo číselník kódů DPH,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem,
   - období je v MyÚčtu uzavřené.
3. **Zkouška nanečisto.** Proběhne celý převod vybraných roků včetně
   rekonciliace a kontroly proti podáním, na konci se ale všechno vrátí.
   Výsledkem je protokol za každý rok; v MyÚčtu nic nezůstane a nastavení
   automatiky se nezmění. Každý rok se zkouší samostatně a hned po své
   zkoušce se vrátí, pozdější rok proto ve zkoušce nevidí data předchozího
   roku (převzaté doklady, uzávěrku) a jeho výsledek se od ostrého převodu
   může lišit. Zkouška běží v databázové transakci, spouštějte ji proto mimo
   běžnou práci ve firmě.
4. **Ostrý převod.** Potvrzení vyjmenuje převáděné roky. Převod běží na
   pozadí, stránku můžete zavřít. Roky se převádějí vzestupně jeden po druhém
   a průběh ukazuje, kolikátý rok z kolika právě běží. Skončí-li rok chybou
   nebo převod zrušíte, další roky se nespustí a průvodce je vypíše. Po
   dokončení průvodce ukáže protokoly všech převedených roků a nabídne účetní
   deník a obratovou předvahu. Převod jedné firmy běží vždy jen jeden, druhý
   se do jeho konce nespustí.

### 109.8.5 Rekonciliace, kontrola a protokol

Každý převáděný rok (ve zkoušce i v převodu) má vlastní běh a protokol s kroky převodu, počty,
upozorněními a chybami a rekonciliací převáděného roku, obdobně jako
u přechodu z POHODY, viz [§ 107.9.5](107_Prechod_z_POHODY.md#10795-rekonciliace-a-protokol).
Obratová předvaha MyÚčta se porovná s předvahou spočtenou přímo z deníku
PREMIER na haléř, včetně počátečních stavů. Protokol zkoušky nanečisto
z přehledu pod průvodcem smažete, protokol ostrého převodu zůstává. Co je
v protokolu chyba, upozornění a rozdíl k přijetí a jak rozdíly přijmout, popisuje
[§ 103.10.5.1](103_Prechod_z_Money_S3.md#1031051-chyby-upozorneni-a-rozdily-k-prijeti).

**Úpravy základu daně.** Výsledek hospodaření, odpisy a nedaňové účty spočte
MyÚčto z převedených dat samo. Ruční úpravy, které účetní zadala do přiznání
k DPPO v PREMIERu (například paušální výdaj na dopravu, příjmy osvobozené,
ztráta minulých let, zaplacené zálohy), převod zapíše jako položky
rozpracovaného přiznání k DPPO s odkazem na řádek PREMIERu. Přiznání, které
už ve firmě je, nemění.

**Kontrola proti podáním z PREMIER.** Kontrolní hlášení DPH za každý měsíc
a přiznání k DPPO spočtené v MyÚčtu z převedených dat se porovnají s podáními,
která má PREMIER uložená v záloze (u KH vždy s posledním podáním měsíce).
Rozdíl protokol vypíše jako upozornění, převod kvůli němu neselže. Typické
příčiny:

- doklad upravený v PREMIERu až po podání (podání neodpovídá aktuálním datům),
- kód DPH, který PREMIER podle vlastního nastavení do kontrolního hlášení
  nezahrnul, přestože tam podle zákona patří (například služba od
  dodavatele ze třetí země v oddílu A.2),
- odpočet, který PREMIER uplatnil dřív, než MyÚčto připustí (§ 109.8.2),
- zaokrouhlení částek přiznání k DPPO: PREMIER zaokrouhluje na koruny
  nahoru, MyÚčto matematicky. Rozdíl do 1 Kč protokol neoznačí jako
  neshodu, daň vychází stejně.

Přiznání k DPH PREMIER v záloze neukládá, proto se s ním nekontroluje.

**Mzdy proti deníku.** Zpracované mzdy převáděného roku se po měsících
porovnají se zaúčtováním v deníku: hrubé příjmy (náklad 52x proti účtům 331,
333 a 366), pojistné zaměstnance (proti 336), pojistné zaměstnavatele
(náklad proti 336) a daň (proti 342, snížená o daňový bonus). Měsíc, který
se liší, protokol vypíše i s částkami jako upozornění. Typicky jde o mzdu
zpracovanou, ale ještě nezaúčtovanou, nebo o mzdu přepočtenou v PREMIERu po
zaúčtování. Rekonciliace mezd běží i u firmy bez modulu Mzdy.

### 109.8.6 Režim účetnictví a automatika

Chování je stejné jako u přechodu z POHODY: převod zapíše podvojné
účetnictví od začátku převáděného roku, automatika účtování je během
převodu vypnutá a po úspěšném převodu se vrátí do stavu před ním, viz
[§ 107.9.6](107_Prechod_z_POHODY.md#10796-rezim-ucetnictvi-a-automatika).

Odpisy majetku, které PREMIER v převedeném roce zaúčtoval, jsou v převedeném
deníku. Hromadné zaúčtování odpisů v uzávěrce je proto pro převedené roky
znovu neúčtuje a plán odpisů naváže dalším měsícem.

#### 109.8.6.1 Uzávěrka uzavřených roků

PREMIER uzávěrkové zápisy do deníku neukládá. Rok, který je v PREMIERu
uzavřený, převod uzavře i v MyÚčtu průvodcem uzávěrky. Za uzavřený se
považuje rok, ke kterému záloha obsahuje podané přiznání k dani z příjmů
právnických osob, nebo rok celý zamčený v PREMIERu v „Zamykání period".

- Kurzové rozdíly a odpisy se spustí, ale nesmějí nic zaúčtovat: převzatý
  deník je už obsahuje.
- Dohadné položky, časové rozlišení, opravné položky a daň z příjmů se
  potvrdí jako zaúčtované v PREMIERu.
- Uzavření knih zaúčtuje zápis na 702 a 710 a otevření dalšího roku převezme
  počáteční stavy z převodu (musí sedět účet po účtu).

Uzávěrka proběhne jen tehdy, když převod roku skončil bez chyb a bez
přijatých rozdílů a předchozí rok je uzavřený. Když by musela zaúčtovat cokoli navíc nebo něco nesouhlasí,
celá se vrátí, rok zůstane otevřený a protokol řekne proč. Uzavřete ho pak
ručně v Účetnictví → Uzávěrka. Rok, který v PREMIERu uzavřený není (typicky
běžný rok), zůstává otevřený.

### 109.8.7 Opakovaný převod

Převod si pamatuje, co z které zálohy už vzniklo. Opakovaný převod téže nebo
novější zálohy založí jen to, co ještě chybí, a nic nezdvojí. Převod
přerušený chybou tak stačí po opravě spustit znovu. Takhle se převádí i další
rok: ve stejné záloze zaškrtněte další rok v pořadí.

Před převodem firmy znovu od začátku stáhněte v průvodci **profil firmy** a po
ostrém převodu ho nahrajte zpět, viz [§ 96.13](96_Nastaveni.md#9613-krok-za-krokem-profil-firmy).

### 109.8.8 Omezení

- Převádí se kalendářní účetní rok, období se vždy založí od 1. 1. do 31. 12.
- Převod čte jen nahranou zálohu, nikdy živou databázi PREMIER.
- Jeden běh převede vybrané roky jedné firmy, každý rok s vlastním
  protokolem. Záloha musí mít IČO firmy
  v MyÚčtu.
- Záloha ve formátu iCAB musí být jeden soubor s kompresí MSZIP, jak ji
  PREMIER ukládá. Jiný archiv CAB uložte v PREMIERu jako iZIP.

## 109.9 Související kapitoly

- [Přechod z POHODY](107_Prechod_z_POHODY.md)
- [Přechod z Money S3](103_Prechod_z_Money_S3.md)
- [Souběh se starým systémem](111_Soubeh_se_starym_systemem.md)
- [Mzdy: nastavení](90_Nastaveni_mezd.md)
- [Řešení problémů](999_Reseni_problemu.md)
