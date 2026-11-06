# 34. Dokumenty

> Návod, jak v MyÚčtu ukládat, hledat a třídit libovolné soubory k podnikání
> (smlouvy, skeny, datové zprávy) a jak je připojit k fakturám a klientům.
> Pro každého, kdo s doklady pracuje, i pro účetní, která po klientovi chybějící
> podklady vyžaduje.

## 34.1 Kdy to potřebujete

Kapitolu otevřete, když:

- potřebujete uložit smlouvu, sken účtenky, XML nebo ISDOC, datovou zprávu (ZFO) či
  elektronický podpis (P7S),
- chcete k faktuře, klientovi nebo zakázce přiložit podklad,
- hledáte soubor podle slova, které je uvnitř dokumentu,
- máte celý adresář z disku nebo archiv ZIP a chcete ho mít v aplikaci i se složkami,
- smazali jste dokument omylem a chcete ho vrátit,
- vám klient nedodal doklad k platbě a chcete ho vyžádat.

Sekce **Dokumenty** je úložiště pro soubory, které k podnikání patří, ale nejsou
to přímo faktury. Najdete ji v menu `Dokumenty → Dokumenty`.
Vše je odděleně **po firmách (dodavatelích)**: co nahrajete pod jednou firmou,
pod jinou neuvidíte.

## 34.2 Než začnete

- **Firma.** V přepínači firem mějte zvolenou firmu, do jejíhož úložiště soubory patří.
- **Oprávnění.** Procházet, otevírat, hledat a stahovat smí i uživatel jen pro čtení.
  Nahrávat, mazat, přesouvat, označovat tagy a vytvářet vazby smí účetní a administrátor.
- **Osobní, nebo firemní.** Nad seznamem je přepínač **Firemní / Osobní**. Zvolte
  vrstvu, do které soubor patří (viz [§ 34.10.4](#34104-firemni-a-osobni-dokumenty)).
- **Klientský portál.** Chcete-li po klientovi chybějící doklady, musí mít klient
  v portálu přístup ([Klientský portál](09_Klientsky_portal.md)).

## 34.3 Krok za krokem: nahrát soubory

1. Otevřete `Dokumenty → Dokumenty` a přejděte do složky, kam soubory patří. Nové
   soubory se vždy nahrají do **aktuálně otevřené složky**.
2. Zvolte způsob nahrání vpravo nahoře:
   - **Nahrát soubory** vybere jeden nebo více souborů,
   - **Vyfotit** (jen na telefonu a tabletu) vyfotí doklad fotoaparátem, navrhne ořez podle okrajů dokladu (rohy jdou posunout), doklad narovná, zmenší na JPEG (zhruba 150 kB) a po potvrzení náhledu nahraje do aktuální složky,
   - **Nahrát složku** vybere celý adresář z disku, podsložky se v aplikaci vytvoří,
   - nebo soubory či celé složky přetáhněte kamkoli do okna sekce (**Přetáhni sem soubory nebo celé složky**).
3. Nahráváte-li archiv ZIP, zvolte přepínačem **Soubory ZIP**, co se s ním stane:
   - **Rozbalit a kategorizovat** archiv rozbalí, podsložky promítne do stromu složek
     a každý soubor uloží samostatně,
   - **Nahrát jako jeden ZIP** nechá archiv jako jediný soubor ke stažení.
4. Velké soubory a složky se nahrávají na pozadí. Průběh uvidíte v panelu úloh.

**Jak poznáte, že je hotovo:** soubory se objeví v seznamu a ohlásí se hláška
o počtu nahraných souborů. Co se nenahrálo, aplikace vypíše i s důvodem (například
nepodporovaný typ, spustitelný soubor, příliš velký soubor, prázdný soubor).

> [!TIP]
> Nahráváte-li datovou zprávu ve formátu **ZFO**, aplikace ji sama rozbalí: uloží
> metadata zprávy a její přílohy jako samostatné dokumenty navázané na původní ZFO.

## 34.4 Krok za krokem: najít dokument

1. Otevřete `Dokumenty → Dokumenty`.
2. Do pole **Hledat v dokumentech (název, obsah)…** napište alespoň dva znaky.
3. Chcete-li hledat podle štítku, klikněte na tag v nabídce **Všechny tagy**. Filtr
   zrušíte tlačítkem **Zrušit filtr**.
4. Dokument otevřete kliknutím. Náhled se zobrazí přímo v detailu (PDF, obrázky,
   XML, TXT, GPC, ABO a CSV). Ostatní typy souborů lze jen **Stáhnout**.

**Jak poznáte, že je hotovo:** v seznamu zůstanou jen vyhovující dokumenty. Když
nic nevyhovuje, uvidíte **Nic nenalezeno**.

## 34.5 Krok za krokem: připojit dokument k faktuře, klientovi nebo zakázce

Z dokumentu:

1. Otevřete detail dokumentu.
2. V sekci **Souvisí s** klikněte na **Přidat vazbu** a začněte psát. Našeptávač
   nabízí vystavené i přijaté faktury, klienty, zakázky a pokladní doklady. Hledat
   můžete podle čísla dokladu, názvu firmy, e-mailu, IČ nebo DIČ, názvu či čísla
   projektu, u pokladních dokladů i podle partnera a popisu.
3. Klikněte na nabídku. Vazba je hotová.
4. Vazbu zrušíte tlačítkem **Odpojit**. Dokument se tím nesmaže.

Z faktury, klienta nebo zakázky:

1. Otevřete detail vystavené faktury, přijaté faktury, klienta nebo zakázky.
2. V panelu **Dokumenty** klikněte na **Připojit dokument** a vyberte soubor.
3. Pokladní doklad má stejný panel pod názvem **Přílohy**. Sken do něj nahrajete rovnou
   (viz [§ 32.11.2.2](32_Pokladna.md#321122-prilohy-pokladniho-dokladu)).

**Jak poznáte, že je hotovo:** vazba je vidět na obou stranách, v detailu dokumentu
i v panelu Dokumenty u faktury nebo klienta.

Kliknutí na navázaný pokladní doklad otevře jeho detail nad seznamem pokladních
pohybů. Detail se zobrazí i tehdy, když doklad nespadá do zvoleného období nebo
aktuální stránky seznamu. Tlačítkem **Zavřít** se vrátíte k seznamu.

> [!TIP]
> Hromadu skenů ke stávajícím dokladům nepřipojujte po jednom. Použijte
> `Dokumenty → Skeny k dokladům` ([kapitola 35](35_Pripojeni_skenu.md)).

## 34.6 Krok za krokem: uspořádat dokumenty

Složky a tagy:

1. Novou složku založíte tlačítkem **Nová složka**. Složky se dají přejmenovat
   a přesouvat. Přesun je okamžitý, nic se nekopíruje.
2. Tagy přidáte v detailu dokumentu v poli **Přidat tag a stisknout Enter**.
3. Seznam přepnete mezi **Mřížka** a **Seznam**.

Hromadné akce:

1. Zaškrtněte více dokumentů i složek najednou (**Vybrat vše** označí všechny).
2. V liště nahoře zvolte:
   - **Přesunout** do jiné složky (cíl vyberete ve stromu),
   - **Otagovat** (jen u souborů),
   - **Stáhnout ZIP** vybraných souborů i složek (zachová strukturu složek),
   - **Smazat** (do koše, u složky včetně obsahu).
3. Výběr zrušíte tlačítkem **Zrušit výběr**.

**Jak poznáte, že je hotovo:** hláška **Uloženo** a dokumenty jsou v nové složce.

> [!WARNING]
> Na mobilu (bez najetí myší) se akce složky, přejmenování a smazání, odkryjí prvním
> ťuknutím a spustí až druhým. Chrání to před nechtěným smazáním.

## 34.7 Krok za krokem: smazat a obnovit z koše

1. Dokument nebo složku smažte tlačítkem **Smazat** a potvrďte dotaz. Dokument se
   přesune do koše.
2. Koš otevřete tlačítkem **Koš**. Vidíte v něm smazané dokumenty i složky.
3. Omylem smazanou položku vraťte tlačítkem **Obnovit**.
4. Chcete-li koš definitivně vyprázdnit, klikněte na **Vysypat koš** a potvrďte.
5. Zpět do běžného seznamu se vrátíte tlačítkem **Zpět z koše**.

**Jak poznáte, že je hotovo:** obnovená položka je opět ve své složce. Po vysypání
hlásí aplikace **Koš je prázdný**.

> [!WARNING]
> Smazání je nevratné až po vysypání koše. Vysypání koše dokumenty trvale odstraní
> z databáze i z disku. Doklady, které jsou navázané jinou agendou, v koši zůstanou
> a aplikace je vypíše.

## 34.8 Krok za krokem: vyžádat od klienta chybějící podklady

Postup je pro účetní nebo administrátora. Klient odpovídá v klientském portálu.

1. Otevřete `Dokumenty → Chybějící doklady` (stránka **Vyžádané doklady**).
2. Klikněte na **Nový požadavek**.
3. Vyplňte povinný **Popis** (co chybí, například doklad k platbě). Volitelně
   doplňte **Částku**, **Datum (kontext)** a **Termín**.
4. Klikněte na **Založit požadavek**.
5. Požadavek lze založit i přímo z nespárovaného bankovního pohybu. Vazba na pohyb
   zůstane zachována pro kontrolu úplnosti.
6. Až klient doklad v portálu odevzdá, požadavek přejde do stavu **Nahráno - čeká na kontrolu**.
   Zkontrolujte podklad a požadavek uzavřete tlačítkem **Uzavřít**.
7. Klient poslal špatný soubor? Požadavek znovu otevřete tlačítkem **Znovu otevřít**.
8. Požadavek, který už nepotřebujete, zrušíte v řádku tlačítkem pro smazání a potvrzením.

**Jak poznáte, že je hotovo:** požadavek má stav **Vyřízeno** a počet otevřených
požadavků nad seznamem klesl.

Seznam filtrujete podle stavu (**Všechny stavy**). Prošlý termín je v seznamu zvýrazněný.

> [!TIP]
> Klient může doklady předat účetní i bez požadavku, přes `Dokumenty → Předat doklady účetní`.
> Postup je v [§ 9.4 Klientský portál](09_Klientsky_portal.md#94-krok-za-krokem-predani-dokladu-ucetni).

## 34.9 Když něco nejde

<!-- cols: 30 35 35 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Nelze nahrávat, mazat ani přesouvat | Máte oprávnění jen pro čtení | Požádejte administrátora o roli účetní |
| **Nic nenalezeno** u dokumentu, který určitě máte | Sken bez textové vrstvy se neindexuje podle obsahu | Hledejte podle názvu nebo tagu, případně přidejte popis a tag |
| Soubor se nenahrál (**nepodporovaný typ**, **spustitelný soubor**) | Typ se ověřuje podle obsahu a spustitelné soubory, HTML a SVG jsou z bezpečnostních důvodů odmítnuty | Soubor nenahrávejte, nebo ho zabalte jinak, než jako spustitelný obsah |
| Soubor je **příliš velký** | Překročen limit velikosti souboru, který aplikace u nahrávání uvádí | Soubor zmenšete, nebo ho rozdělte |
| Náhled souboru se nezobrazí | Ostatní typy souborů se nabízejí jen ke stažení; velký textový soubor má náhled zkrácený | Soubor stáhněte tlačítkem **Stáhnout**, celý originál je vždy dostupný |
| Cizí osobní dokument nevidím | Osobní dokumenty vidí běžný uživatel jen své | Požádejte administrátora, ten vidí osobní dokumenty všech |
| Koš po vysypání nechal několik dokladů | Doklady jsou navázané jinou agendou | Odpojte vazbu v jiné agendě a koš vysypejte znovu |
| Požadavek zmizel z fronty **K doúčtování**, ale bankovní pohyb zůstal v Úplnosti dokladů | Založení požadavku pohyb nevyřeší | Doložte doklad a nechte ho správně zaúčtovat |

## 34.10 Podrobnosti a pravidla

### 34.10.1 Organizace - složky, vazby a tagy

Dokumenty organizujete třemi způsoby, které se doplňují:

- **Strom složek.** Klasické složky a podsložky jako na disku. Složky jsou virtuální
  (soubor fyzicky leží podle svého otisku), takže přesun složky je okamžitý.
- **Vazby na entitu.** Dokument můžete připojit k vystavené faktuře, přijaté faktuře,
  klientovi nebo zakázce. Vazba je oboustranná, vidíte ji v detailu dokumentu i v panelu
  Dokumenty v detailu faktury nebo klienta.
- **Tagy.** Volné štítky pro průřezové hledání (například `smlouva`, `2026`, `GDPR`).

Velikost každé složky je vidět přímo v dlaždici.

### 34.10.2 Soubory ZIP a datové zprávy ZFO

Rozbalený archiv ZIP se zpracovává bezpečně. Podsložky uvnitř se promítnou do stromu
složek.

Nahraná **ZFO** (stažená nebo odeslaná datová zpráva) se automaticky rozbalí:

- uloží se veškerá metadata zprávy: ID zprávy, odesílatel, příjemce, předmět, datum
  dodání i odeslání (zobrazí se v detailu v panelu **Datová zpráva**),
- jednotlivé přílohy zprávy se uloží jako samostatné dokumenty navázané na původní ZFO,
- případný odpojený podpis P7S se napáruje na podepsaný dokument.

### 34.10.3 Náhledy a vyhledávání

U PDF a obrázků se generují náhledy (thumbnaily) a v detailu je inline náhled přímo
v aplikaci, stejně jako u přijatých faktur. V detailu lze otevřít také **XML**
(odsazené a čitelně formátované), **TXT, GPC a ABO** (text s čísly řádků) a **CSV**
(tabulka s automaticky rozpoznaným oddělovačem). Velké textové soubory mají náhled
omezený, celý originál zůstává vždy dostupný ke stažení. Ostatní typy souborů se
z bezpečnostních důvodů nabízejí pouze ke stažení.

Pole **Hledat** prohledává názvy, popisy i obsah dokumentů. U PDF s textovou vrstvou,
dokumentů Office (DOC, XLS) a XML se text indexuje při nahrání, takže dokument najdete
i podle slova uvnitř. Naskenované PDF bez textové vrstvy zůstává dohledatelné podle názvu
a tagů.

### 34.10.4 Firemní a osobní dokumenty

Přepínač **Firemní / Osobní** řídí, kterou vrstvu dokumentů procházíte:

- **Firemní.** Klasické sdílené úložiště. Vidí ho každý, kdo má k sekci Dokumenty přístup.
- **Osobní.** Dokumenty patřící konkrétnímu uživateli. Běžný uživatel vidí jen svoje
  vlastní. Administrátor vidí osobní dokumenty všech uživatelů firmy a vedle přepínače
  má výběr konkrétního vlastníka (nebo volbu **Všichni vlastníci**).

Rozlišení firemní a osobní je vlastnost každého jednotlivého dokumentu, ne složky.
Složky samotné vrstvu nemají a procházejí se společně. Rozlišení platí důsledně v celé
sekci: v seznamu, ve fulltextovém hledání, ve filtru podle tagu, při párování
s fakturami a klienty i v koši. Osobní dokument cizího uživatele se běžnému uživateli
nezobrazí v žádném z těchto míst, ani v hromadném exportu ZIP.

> [!TIP]
> Vrstva firemní a osobní je nezávislá na izolaci po firmách. Obě běží současně. Nejdřív
> vás systém omezí na dokumenty vaší aktuální firmy, teprve uvnitř ní pak na firemní
> a vaše osobní.

### 34.10.5 Oprávnění

- **Jen pro čtení.** Procházení, náhledy, fulltext, stahování a export.
- **Účetní a administrátor.** Navíc nahrávání, mazání, přesouvání, tagy a vazby.

### 34.10.6 Stavy požadavků na chybějící podklady

Každý řádek na stránce **Vyžádané doklady** je pracovní požadavek, ne další složka.
Má povinný popis a volitelnou částku, datum účetního případu a termín dodání.

- **Vyžádáno.** Podklad ještě nebyl doručen. Prošlý termín se v seznamu zvýrazní.
- **Nahráno - čeká na kontrolu.** Klient v portálu odevzdal PDF, obrázek nebo ISDOC.
  Originál čeká v `Nákup → Příchozí doklady` mimo účetnictví. Odkaz na přijatou fakturu
  se objeví až po zpracování účetní.
- **Vyřízeno.** Účetní podklad zkontrolovala a požadavek ručně uzavřela. Vyřízený
  požadavek lze znovu otevřít, například když klient dodal nesprávný soubor.

Požadavky smí zakládat, řešit, znovu otevírat a mazat účetní nebo administrátor.
Uživatel jen pro čtení je může prohlížet. Klient pracuje jen se svými požadavky
v klientském portálu a nemá přístup ke správě požadavků.

Podání od klienta se ukládá jako originál (auditní stopa), hlídají se přesné duplicity
a účetní může vyžádat náhradní soubor, aniž by se původní verze ztratila. Celý postup
je v [§ 9.4 Klientský portál](09_Klientsky_portal.md#94-krok-za-krokem-predani-dokladu-ucetni).

Klient může vlastní čekající předání odstranit před zahájením zpracování. Originál se
přesune do koše a související nahraný požadavek se vrátí do stavu **Vyžádáno**.
Odmítnutí účetní s povinným důvodem naopak originál zachová a klient může nahrát
opravenou náhradu. Doklady již použité v jiné agendě a vyřízené požadavky jsou proti
smazání podání chráněné.

Otevřený požadavek ve stavu **Vyžádáno** se promítá také do
[fronty K doúčtování](54_Rucni_fronta_doctovani.md). U bankovního pohybu se jeho
existence ukáže v [Úplnosti dokladů](61_Uplnost_dokladu.md), ale pohyb z kontroly
zmizí až po doložení a správném účetním zpracování. Samotné založení požadavku ho
neřeší.

### 34.10.7 Přílohy účetních zápisů (§ 33a)

Kromě obecného úložiště má MyÚčto samostatnou, oddělenou evidenci příloh přímo
u jednotlivých zápisů v účetním deníku. Je to sken faktury, dodacího listu nebo jiného
průkazného dokladu, který dokládá konkrétní účetní zápis podle **§ 33a zákona
o účetnictví**. Tuto přílohu nenahráváte v sekci Dokumenty, ale v detailu zápisu
v [Účetním deníku](52_Ucetni_denik.md). Tady popisujeme jen princip, protože jde
o technicky příbuzné, ale oddělené úložiště.

#### 34.10.7.1 Proč oddělené úložiště

Přílohy zápisu mají vlastní databázovou tabulku a vlastní diskový prostor (`storage/journal/`,
mimo `storage/documents/`, které používá zbytek kapitoly). Bajty se ukládají stejně jako
u Dokumentů podle otisku, ale deduplikace a mazání posledního zbylého souboru se počítá
jen mezi přílohami zápisů a nikdy se nekříží s obecným úložištěm. Díky tomu:

- průkazný záznam k zápisu nejde smazat ani přesunout přes rozhraní Dokumentů,
- práva k přílohám zápisu se řídí účetní rolí, ne oprávněními k sekci Dokumenty,
- životní cyklus přílohy je svázaný se zápisem (smazání zápisu smaže i jeho přílohy).

#### 34.10.7.2 Nahrávání a limity

U zápisu jde nahrát více souborů najednou. Každý soubor se zpracuje samostatně. Pokud
jeden selže (například je duplicitní), zbytek dávky se přesto nahraje a v odpovědi
uvidíte přehled, co se povedlo a co ne, s důvodem (například „už evidováno", „příliš velký").

- Nejvýše **20 MiB** na jeden soubor.
- Nejvýše **100 MiB** celkem na jeden zápis (součet všech jeho příloh).
- **Deduplikace podle obsahu.** Stejný soubor (stejný otisk sha256) nejde ke stejnému
  zápisu přiložit dvakrát, vrátí se chyba „už evidováno". Ke **různým** zápisům jej
  přiložit lze, bajty na disku se sdílejí.
- Typ souboru se stejně jako u Dokumentů pozná z obsahu, ne z přípony. Spustitelné
  a aktivní obsahy (skripty, HTML, SVG) jsou odmítnuty stejným blocklistem jako
  v [§ 34.10.9](#34109-bezpecnost).
- Rozpoznávané typy jsou PDF, obrázek, XML nebo ISDOC(x) a ZFO. Ostatní se uloží jako „ostatní".

#### 34.10.7.3 Popisek, stažení a mazání

- Ke každé příloze lze dopsat nebo upravit popisek (do 255 znaků). Každá změna se
  zapisuje (před a po) do historie zápisu.
- Příloha se vždy stahuje jako soubor ke stažení. Na rozdíl od Dokumentů se příloha
  zápisu nikdy nezobrazuje inline v prohlížeči, ani PDF.
- Smazání přílohy odstraní záznam u zápisu. Samotný soubor na disku zmizí, jen když na
  jeho otisk neukazuje žádná jiná příloha (stejný princip jako u Dokumentů, počítaný odděleně).

#### 34.10.7.4 Oprávnění

- **Čtení** seznamu příloh u zápisu: kdokoli s přístupem k účetnímu deníku (od role jen pro čtení).
- **Nahrávání, mazání a úprava popisku:** jen role účetní nebo administrátor.

> [!WARNING]
> Plánovaná úloha `cron-backup-documents` (viz [§ 34.10.8](#34108-zalohovani))
> zálohuje jen `storage/documents/`. Přílohy účetního deníku (`storage/journal/`)
> v ní aktuálně obsažené nejsou. Počítejte s tím při plánování celkové zálohy databáze
> a souborového úložiště.

### 34.10.8 Zálohování

Dokumenty zálohuje samostatná plánovaná úloha `cron-backup-documents` (viz
`Systém → Plánované úlohy`), oddělená od zálohy PDF faktur. Zálohuje celé úložiště
`storage/documents/` (všechny typy souborů) do `storage/backup/{db}-documents-RRRR-MM-DD.zip`
s retencí 30 denních a 12 měsíčních záloh. Náhledy se nezálohují, regenerují se. Zálohu
lze volitelně šifrovat heslem `cron.backup.password` v `cfg.php` (AES-256, společné pro
všechny typy záloh, viz [§ 5.5 Cron skripty](05_Po_instalaci.md#55-krok-za-krokem-naplanovani-uloh-cron)).

### 34.10.9 Bezpečnost

Sekce přijímá libovolné soubory, proto je nahrávání chráněné. Typ se ověřuje podle
obsahu (ne podle přípony), spustitelné soubory a HTML či SVG jsou odmítnuty, rozbalování
ZIP má ochranu proti „zip bombě" i průniku cesty (Zip Slip) a parsování ZFO a XML je
chráněno proti útokům přes XML entity (XXE).

## 34.11 Související kapitoly

- [Připojení skenů k dokladům](35_Pripojeni_skenu.md) - hromadné připojení skenů ke stávajícím dokladům
- [Klientský portál](09_Klientsky_portal.md) - předávání a vyžádání dokladů od klienta
- [Pokladna](32_Pokladna.md) - přílohy pokladních dokladů
- [Účetní deník](52_Ucetni_denik.md) - přílohy účetních zápisů
- [Fronta K doúčtování](54_Rucni_fronta_doctovani.md) a [Úplnost dokladů](61_Uplnost_dokladu.md)
