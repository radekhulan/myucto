# 36. Kniha jízd

> Návod, jak vést evidenci jízd, vozidel a tankování (u elektromobilů nabíjení), aby
> finanční úřad uznal náklady na pohonné hmoty nebo elektřinu. Pro každého, kdo
> firemní vozidla používá, i pro účetní, která podklady kontroluje.

## 36.1 Kdy to potřebujete

Kapitolu otevřete, když:

- pořizujete firemní nebo soukromé vozidlo používané pro podnikání a chcete ho evidovat,
- jste se vrátili z cesty a potřebujete zapsat jízdu,
- jezdíte opakovaně stejné trasy a chcete jízdy doplnit podle stavu tachometru jedním klikem,
- máte účtenku nebo fakturu za pohonné hmoty a chcete ji rozpadnout na tankování,
- máte evidenci jízd v tabulce a chcete ji nahrát najednou,
- se blíží konec roku a potřebujete doložit najeté kilometry, spotřebu a stav tachometru,
- aplikace u tankování hlásí upozornění k tachometru nebo k odpočtu DPH.

Kniha jízd je podklad pro uplatnění nákladů na pohonné hmoty (u elektromobilů na
elektřinu) v daních podle **§ 24 zákona o daních z příjmů** a pokynu GFŘ D-22. Bez ní
finanční úřad odpočet neuzná. Najdete ji v menu `Dokumenty → Kniha jízd`, pod
[Dokumenty](34_Dokumenty.md). Vše je odděleně po firmách (dodavatelích).

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při pořízení vozidla | Založit auto s počátečním stavem tachometru | záložka **Automobily** |
| po každé cestě | Zapsat jízdu s konkrétním účelem | záložka **Kniha jízd**, **Nový záznam** |
| jednou, při změně tras | Založit pravidelné cesty | záložka **Pravidelné cesty** |
| průběžně (třeba týdně) | Zapsat stav tachometru a doplnit km z pravidelných cest | záložka **Kniha jízd**, **Stav tachometru** a panel **Km k doplnění** |
| jednou, umí-li to auto | Připojit auto k automatickému stavu tachometru (BMW CarData), stav se pak načítá sám každý den | záložka **Automobily**, **Tachometr** |
| po každém tankování nebo nabití | Zapsat tankování, nebo nechat vzniknout z dokladu | záložka **Tankování** |
| měsíčně | Projít upozornění k tankování | záložka **Tankování**, panel **Upozornění k tankování** |
| na konci roku | Zkontrolovat souhrn a vyexportovat ho k daňové evidenci | záložka **Souhrny** |

## 36.2 Než začnete

- **Oprávnění.** Knihu jízd lze zpřístupnit interním i klientským rolím. Čtení
  umožňuje prohlížení; zápis jízd, aut a tankování vyžaduje samostatné oprávnění
  k zápisu. Import a mazání mají vlastní oprávnění a lze je povolit nezávisle.
- **Zdrojové doklady.** Tlačítko **Z pokladny** vyžaduje čtení pokladny,
  **Z faktur** čtení přijatých faktur. Také vyhledání a změna vazby na bankovní
  pohyb nebo účetní zápis vyžadují čtení příslušné evidence. Bez něj se vazby
  a jejich popisky nezobrazují; při běžné úpravě tankování zůstávají uložené.
- **Auto.** Bez založeného auta nelze zapsat jízdu ani tankování (**Nejprve přidejte
  automobil v záložce Automobily.**).
- **Dodavatel stanice.** Aby se faktury od čerpacích a nabíjecích stanic nabízely ke
  zpracování, zaškrtněte v detailu dodavatele **Čerpací / nabíjecí stanice**.
- **Řidič a karta (volitelně).** Chcete-li vozidlo přiřazovat podle platební karty,
  vyberte u vozidla **Řidiče** a kartu veďte v [Platebních kartách](31_Platebni_karty.md)
  s držitelem vybraným ze zaměstnanců.

Kniha jízd má šest záložek: **Kniha jízd** (jednotlivé jízdy), **Automobily**,
**Tankování**, **Pravidelné cesty**, **Kategorie cest** a **Souhrny**.

## 36.3 Krok za krokem: zapsat jízdu

1. Otevřete `Dokumenty → Kniha jízd`, záložku **Kniha jízd**.
2. Klikněte na **Nový záznam**.
3. Vyberte **Auto** a vyplňte **Datum**, **Čas odjezdu**, **Čas příjezdu**, **Odkud** a **Kam**.
4. Do pole **Účel cesty** napište konkrétní důvod (našeptávač nabídne dříve zadané účely).
   Samotné „práce" nestačí.
5. Vyplňte **Tachometr (zahájení)** (předvyplní se posledním známým konečným stavem auta)
   a buď **Ujeto (km)**, nebo **Tachometr (konec)**. Druhý údaj se dopočítá.
6. Zvolte **Kategorie** (služební, nebo soukromá) a uložte.

**Jak poznáte, že je hotovo:** jízda je v seznamu seskupená po měsících a měsíční
součet ujetých km se zvýšil. Nahoře lze filtrovat podle auta, roku a měsíce (výchozí
je vše), dlouhý seznam se stránkuje.

### 36.3.1 Nahrát jízdy z CSV nebo XLSX

1. Klikněte na **Import**.
2. Tlačítkem **Stáhnout vzor** získáte prázdnou šablonu CSV (formát sloupců viz
   [§ 36.8.5](#3685-import-jizd-a-tankovani-ze-souboru)).
3. Klikněte na **Vybrat soubor** a soubor nahrajte.

**Jak poznáte, že je hotovo:** zobrazí se přehled (**Vytvořeno** a počet chyb s důvodem
a seznam nově založených kategorií).

### 36.3.2 Exportovat jízdy

1. Klikněte na **Export**.
2. Zvolte **Datum od** a **Datum do** a auto ve filtru nahoře.
3. Zvolte formát XLSX, nebo PDF.

Výstup je seskupený po vozidlech, s mezisoučty a celkovým počtem km. Hodí se jako
příloha k daňové evidenci.

### 36.3.3 Přepočítat stav tachometru

Opravíte-li u jízdy konečný stav tachometru, aplikace se po uložení zeptá, zda
přepočítat následující jízdy téhož auta. Každá další jízda pak začne na konci
předchozí a její konec se dopočítá z ujetých km. Ujeté km se nemění.

Celé auto přepočítáte tlačítkem **Přepočítat tachometr**: vyberte auto ve filtru
nahoře a potvrďte. Řada začne počátečním stavem první jízdy, a nemá-li ho, počátečním
stavem auta. Jízdy se řadí podle data a času odjezdu.

**Jak poznáte, že je hotovo:** zobrazí se počet přepočítaných jízd a sloupec stavu
tachometru v seznamu na sebe navazuje.

### 36.3.4 Zapsat jízdu z pravidelné cesty

1. Na záložce **Pravidelné cesty** klikněte na **Nová pravidelná cesta**.
2. Vyplňte **Odkud** a **Kam** (název se z nich navrhne sám), **Účel cesty**, **Ujeto (km)**
   a **Kategorii**. Volitelně **Obvyklý čas odjezdu** a **příjezdu** a **Auto**; bez auta
   se cesta nabízí u všech aut.
3. Uložte. Cestu tam a zpět zadejte jako dvě pravidelné cesty, nebo jednu s km za obě
   cesty.
4. Při zápisu jízdy zvolte v okně **Nový záznam** pole **Z pravidelné cesty**. Vyplní se
   odkud, kam, účel, kategorie, časy a ujeté km a tachometr (konec) se dopočítá.

**Jak poznáte, že je hotovo:** pravidelná cesta je v seznamu a formulář nové jízdy ji
nabízí. Jízdy si cestu nepamatují, úprava nebo smazání pravidelné cesty je nezmění.

### 36.3.5 Doplnit km podle stavu tachometru

Nechcete zapisovat každou jízdu hned? Stačí čas od času opsat stav tachometru a
kilometry pak rozdělit na jízdy.

1. Na záložce **Kniha jízd** klikněte na **Stav tachometru**.
2. Vyberte **Auto**, zadejte **Datum** a **Stav tachometru (km)** a klikněte na
   **Zapsat stav**. Druhý zápis téhož dne stav přepíše.
3. Nad seznamem jízd se objeví žlutý panel **Km k doplnění** s obdobím, autem a počtem km.
   U řádku klikněte na **Doplnit**.
4. Tlačítky pravidelných cest přidejte jízdy (každé kliknutí jednu). U jízdy můžete změnit
   datum v rámci období, km, kategorii, odkud, kam a účel. Tlačítkem **Jiná jízda**
   přidáte jízdu bez pravidelné cesty.
5. Nechte zaškrtnuté **Zbytek zapsat jako soukromou jízdu**, chcete-li zbývající km
   zapsat jako soukromé. Bez zaškrtnutí zbytek zůstane k doplnění na později.
6. Klikněte na **Uložit jízdy**.

**Jak poznáte, že je hotovo:** v seznamu přibyly jízdy, které na sebe navazují stavem
tachometru a poslední končí přesně na zapsaném stavu, a řádek v panelu **Km k doplnění**
zmizel.

## 36.4 Krok za krokem: přidat auto

1. Otevřete záložku **Automobily** a klikněte na **Nové auto**.
2. Vyplňte **SPZ** (povinné). Volitelně **Značku**, **Model**, VIN a **Palivo**.
3. Zadejte **Tachometr (zahájení)** a **Datum stavu** (k datu pořízení nebo k 1. 1.).
4. U elektromobilu zvolte palivo **Elektro**, u plug-in hybridu **Hybrid**. Aplikace pak
   nabízí jednotku kWh místo litrů.
5. Zvolte **Řidiče**, **Režim užívání** a **Odpočet DPH** (viz [§ 36.8.1](#3681-automobily-ridic-rezim-uzivani-a-odpocet-dph)).
6. Máte-li aut víc, označte **Výchozí auto**. Na něj se navážou nové záznamy a tankování,
   když vozidlo neurčíte jinak.
7. Uložte.

**Jak poznáte, že je hotovo:** auto je v seznamu. Auto, které má navázané jízdy nebo
tankování, nelze smazat. Při úpravě ho jen archivujte (**Zobrazit archivované** je ukáže).

### 36.4.1 Připojit automatický stav tachometru

Stav tachometru se může načítat sám každý den ze služby výrobce auta. Zatím to umí
**BMW CarData**: potřebujete BMW s aktivním ConnectedDrive a musíte být jeho hlavní
uživatel v aplikaci My BMW. Pak už jen doplňujete km z pravidelných
cest (viz [§ 36.3.5](#3635-doplnit-km-podle-stavu-tachometru)).

1. V portálu My BMW otevřete **CarData** a klikněte na **Create CarData Client**.
2. U klienta zapněte službu **CarData API** a asi minutu počkejte. Zkopírujte
   **Client ID**.
3. V MyÚčtu na záložce **Automobily** klikněte u auta na **Tachometr** a vyberte **BMW CarData**.
4. Vložte **Client ID** a klikněte na **Připojit**.
5. Klikněte na **Otevřít přihlášení BMW CarData**, přihlaste se u BMW a potvrďte zobrazený kód.
   Dialog nechte otevřený, sám pozná, že je přihlášení hotové.
6. Patří-li k účtu BMW víc aut a žádné neodpovídá VIN zapsanému u auta v MyÚčtu,
   vyberte auto a klikněte na **Použít**.

Další auta ze stejného účtu BMW se připojí sama, mají-li v MyÚčtu zapsaný stejný VIN.
U ostatních aut otevřete **Tachometr** a vyberte účet v části **Použít účet, který už je
připojený**. Znovu se přihlašovat nemusíte.

**Jak poznáte, že je hotovo:** u auta svítí štítek **Tachometr**, v dialogu je VIN a poslední
stav tachometru a stav se objeví i v seznamu **Stav tachometru** se štítkem **BMW**.

## 36.5 Krok za krokem: zapsat tankování nebo nabíjení

Tankování můžete zapsat ručně, nahrát ze souboru, nebo nechat vzniknout z dokladu,
kterým bylo zaplaceno. Akce jsou v liště vpravo nahoře na záložce **Tankování**:
**Nové tankování**, **Import**, **Načíst z faktur**, **Z pokladny** a v nabídce „…"
**Export**.

### 36.5.1 Ruční zápis

1. Klikněte na **Nové tankování**.
2. Vyplňte datum, množství, částku a místo. Přepínač jednotky **l / kWh** se předvyplní
   podle auta. U plug-in hybridu ho přepínejte: benzín v litrech, dobíjení v kWh.
3. Vyplňte **Tachometr**. Necháte-li ho prázdný, aplikace doplní orientační stav z knihy
   jízd (zobrazí se jako `≈`), pro přesnost ho ale raději vyplňte.
4. Volitelně v sekci **Vazba na doklad** zvolte druh dokladu (**Pokladní doklad**,
   **Bankovní pohyb**, **Účetní zápis**). Aplikace nabídne doklady kolem data tankování,
   doklady se shodnou částkou jsou nahoře a označené. Seznam zúžíte polem **Hledat**
   (číslo dokladu, partner, popis). Vazbu zrušíte tlačítkem **Zrušit vazbu**.
5. Uložte.

**Jak poznáte, že je hotovo:** tankování je v seznamu po měsících s měsíčním součtem
částek. U každé vazby je proklik na doklad.

### 36.5.2 Načíst z faktur od stanic

1. V detailu dodavatele zaškrtněte **Čerpací / nabíjecí stanice**.
2. Na záložce **Tankování** klikněte na **Načíst z faktur**. Každá faktura má odznak
   **Nová** nebo **Zpracováno**. Tlačítko **Detail** rozbalí položky faktury, pohonné
   hmoty a nabíjení jsou zvýrazněné.
3. Vyberte auto a klikněte na **Rozpoznat**.
4. Starší faktury zpracujete najednou tlačítkem **Vytěžit historii**. Zpracuje jen dosud
   nezpracované a vozidlo určí automaticky.

**Jak poznáte, že je hotovo:** faktura má odznak **Zpracováno** a v seznamu přibyla
tankování navázaná na fakturu.

### 36.5.3 Z pokladních dokladů

1. Účtenku za pohonné hmoty zaúčtujte v [Pokladně](32_Pokladna.md) jako výdajový doklad.
2. Na záložce **Tankování** klikněte na **Z pokladny**.
3. U dokladu zvolte vozidlo (nebo nechte **Vozidlo automaticky (SPZ / karta / výchozí)**)
   a klikněte na **Rozpoznat**. U už vytvořeného tankování změníte vozidlo tlačítkem **Přiřadit**.
4. **Vytěžit historii** zpracuje dosud nezpracované doklady.

**Jak poznáte, že je hotovo:** doklad má odznak **Zpracováno** (hláška **Tankování
z pokladního dokladu vytvořeno.**).

> [!TIP]
> Napíšete-li do popisu pokladního dokladu „Nafta 40 l, tach. 123 456, 1AB 2345",
> tankování se přiřadí správnému vozu i se stavem tachometru bez další práce.

### 36.5.4 Import tankování ze souboru

1. Na záložce **Tankování** klikněte na **Import**.
2. Klikněte na **Vybrat soubor a zobrazit náhled**. Každý řádek ukáže, co se stane:
   **nové**, **už evidováno**, nebo **chyba** s důvodem.
3. Klikněte na **Importovat**. Šablonu získáte tlačítkem **Stáhnout vzor**.

**Jak poznáte, že je hotovo:** zobrazí se souhrn (vytvořeno, doplněno, duplicit, chyb).
Opakovaný import nic nezdvojí.

## 36.6 Krok za krokem: přečíst souhrn za rok

1. Otevřete záložku **Souhrny** a zvolte rok.
2. Pro každé vozidlo zkontrolujte ujeté km (služební, soukromé, nezařazené), stav tachometru,
   náklady na energii a spotřebu.
3. Zkontrolujte řádek **Návaznost tachometru**. Hodnota **✓ v pořádku** znamená bez skoků.
4. Souhrn vyexportujte do XLSX nebo PDF jako přílohu k daňové evidenci.

**Jak poznáte, že je hotovo:** u všech vozidel je návaznost tachometru v pořádku a
souhrn je vyexportovaný.

## 36.7 Když něco nejde

<!-- cols: 30 35 35 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Nejprve přidejte automobil v záložce Automobily.** | Není založené žádné auto | Přidejte auto (viz [§ 36.4](#364-krok-za-krokem-pridat-auto)) |
| **Vyplňte SPZ.** | SPZ je u auta povinná | Doplňte SPZ |
| **Auto má navázané jízdy nebo tankování - nelze smazat (lze ho archivovat při úpravě).** | Historie musí zůstat zachována | Auto při úpravě archivujte |
| **Kategorie má navázané jízdy - nelze smazat (lze ji archivovat při úpravě).** | Kategorie se používá | Archivujte ji při úpravě |
| Žlutý panel **Upozornění k tankování** | Chybí tachometr, řada tachometru je nesouvislá, nebo odpočet DPH dokladu nesedí s vozidlem | Klikněte na **Detail** a opravte dotčená tankování (viz [§ 36.8.3](#3683-upozorneni-a-odhad-tachometru)) |
| **Odhad nejde doplnit u žádného tankování** | Není z čeho odhadovat | Zadejte aspoň jeden skutečný stav tachometru nebo počáteční stav vozidla |
| Tankování z pokladny nevzniklo | Firma nemá žádné vozidlo, doklad není zaúčtovaný, partner není čerpací stanice a popis nezní na pohonné hmoty | Založte auto, doklad zaúčtujte, označte dodavatele jako stanici |
| **Žádné faktury od čerpacích/nabíjecích stanic.** | Dodavatel není označen jako stanice | Zaškrtněte v jeho detailu **Čerpací / nabíjecí stanice** |
| Řádek importu skončil **chybou** | Neznámá SPZ, chybí datum nebo částka | Opravte řádek v souboru a nahrajte znovu |
| **Tankování se nepodařilo rozpoznat.** | Faktura má nečitelný formát | Zapište tankování ručně |
| Tlačítko **Z pokladny** chybí | Nemáte právo číst pokladnu | Požádejte administrátora o oprávnění |
| **Stav tachometru nenavazuje na ostatní stavy auta** | Stav je nižší než dřívější stav nebo konec dřívější jízdy, nebo vyšší než pozdější stav | Zkontrolujte číslo a datum, případně opravte dřívější stav nebo jízdu |
| **Km k doplnění** se ukazují i za období, kde jízdy jsou | Jízdy nemají vyplněný stav tachometru, takže kniha jízd neví, kde na tachometru leží | Doplňte u jízd tachometr, případně použijte **Přepočítat tachometr** (viz [§ 36.3.3](#3633-prepocitat-stav-tachometru)) |
| **Zbytek zapsat jako soukromou jízdu** nejde zaškrtnout | Chybí kategorie cest označená jako soukromá jízda | Založte ji na záložce **Kategorie cest** |
| **Jízdy mají víc km, než kolik je k doplnění.** | Součet km jízd přesahuje rozdíl stavů tachometru | Snižte km u některé jízdy nebo jízdu odeberte |
| **Client ID nebylo přijato.** | Client ID je zkopírované špatně, nebo u klienta není zapnutá služba CarData API | Zkontrolujte klienta v portálu My BMW. Když to nepomůže, klienta smažte, po 30 sekundách založte nový a znovu zapněte CarData API |
| Štítek **Tachometr: chyba** a **Přihlášení ke službě vypršelo** | Stav se nenačetl déle než dva týdny, nebo bylo u BMW přihlášení zrušeno | Otevřete **Tachometr** u kteréhokoli auta účtu a klikněte na **Připojit znovu**. Ostatní auta účtu se tím opraví také |
| **K tomuto autu nemáte v BMW CarData přístup** | V aplikaci My BMW nejste hlavní uživatel auta | Požádejte hlavního uživatele, ať auto připojí on, nebo vás udělá hlavním uživatelem |
| **Vyčerpán denní limit dotazů BMW (50).** | Příliš mnoho ručních načtení za den | Počkejte do dalšího dne. Denní načtení ve 3:30 proběhne samo |
| Štítek **Tachometr: upozornění** a **Auto zatím stav tachometru neodeslalo.** | Auto po připojení ještě nepřeneslo data | Načtěte stav znovu po jízdě nebo po zamknutí auta |
| Štítek **Tachometr: upozornění** a **Stav tachometru z auta nenavazuje na zapsané jízdy a stavy.** | Některá zapsaná jízda nebo stav má vyšší tachometr, než ukazuje auto. Stav se přeskočil | Opravte tachometr u dotčené jízdy nebo stavu. Další den se stav načte znovu |

## 36.8 Podrobnosti a pravidla

Co kniha jízd ze zákona musí evidovat:

- **u vozidla** typ, SPZ a stav tachometru k zahájení (typicky k 1. 1.) i ke konci roku,
- **u každé jízdy** datum, čas odjezdu a příjezdu, odkud a kam, konkrétní účel cesty
  (samotné slovo „práce" nestačí) a ujeté kilometry nebo stav tachometru.

### 36.8.1 Automobily, řidič, režim užívání a odpočet DPH

U auta zadáte **SPZ** (povinné), volitelně značku, model, VIN, druh paliva a počáteční
stav tachometru. Druh paliva: nafta, benzín, LPG, CNG, **Elektro**, **Hybrid**, jiné.
Podle něj aplikace pozná, že se vozidlo nabíjí v kWh, a přizpůsobí jednotky a spotřebu.
Příznak **Výchozí auto** určuje, na které vozidlo se nové záznamy a tankování navážou
automaticky, když máte aut víc.

- **Řidič** je zaměstnanec, jemuž je vozidlo svěřené. Vybírá se ze zaměstnanců firmy
  (`Mzdy → Zaměstnanci`), kniha jízd z nich vidí jen jméno.
- **Režim užívání:** **Jen firemní**, **Firemní i soukromé** (smíšené), nebo **Soukromé vozidlo**
  použité pro firmu.
- **Odpočet DPH:** **Plný**, **Poměrný (§ 75)** s podílem v procentech, nebo **Bez odpočtu**.

Režimy spolu souvisejí: smíšené užívání vyžaduje poměrný odpočet (podíl odpovídá
podnikatelskému užití, které doložíte knihou jízd; poměr služebních a soukromých km je
v Souhrnech), soukromé vozidlo odpočet nemá. Formulář nabízí jen povolené kombinace.
Koeficient krácení podle § 76 se u vozidla nenastavuje, je celofiremní a počítá se
v [Daních](41_Vykazy_DPH.md).

Nastavení odpočtu nic neúčtuje, odpočet uplatňuje doklad. Kniha jízd ale u každého
tankování porovná odpočet navázaného dokladu s nastavením vozidla a nesoulad ukáže jako
upozornění (viz [§ 36.8.3](#3683-upozorneni-a-odhad-tachometru)).

#### 36.8.1.1 Formulář jízdy

Tachometr zahájení se předvyplní posledním známým konečným stavem auta, takže jízdy na
sebe navazují. Zadáte-li **Ujeto (km)** a necháte prázdný **Tachometr (konec)**, konec se
dopočítá ze začátku. Vyplníte-li oba tachometry, ujeté km se spočítají jako rozdíl.

### 36.8.2 Přiřazení vozidla

Když tankování vzniká z dokladu nebo importu, aplikace vozidlo určí sama. Rozhoduje první
krok, který vozidlo najde:

1. vozidlo vybrané ručně (v okně Načíst z faktur / Z pokladny nebo ve formuláři),
2. SPZ z dokladu (porovnává se bez mezer, pomlček a velikosti písmen),
3. SPZ uvedená v textu dokladu,
4. platební karta: karta platná k datu tankování, její držitel (zaměstnanec), vozidlo, jehož
   je řidičem. Jen aktivní vozidla. Řídí-li držitel víc vozidel, nepřiřadí se podle karty
   nic, protože nejde poznat, kterým tankoval,
5. výchozí vozidlo firmy (nebo jediné aktivní).

Aby krok s kartou fungoval, veďte kartu v [Platebních kartách](31_Platebni_karty.md)
s držitelem vybraným ze zaměstnanců a u vozidla vyplňte **Řidiče**. Použije se vždy jen
karta i vozidlo vlastní firmy. Navážete-li tankování bez vybraného vozidla na bankovní
pohyb kartou, aplikace vozidlo dohledá podle karty.

V seznamu tankování je pod SPZ drobně uvedeno, podle čeho bylo vozidlo přiřazeno:
„vybráno ručně", „podle SPZ", „SPZ v textu dokladu", „podle karty •••• 1234" nebo
„výchozí vozidlo". Starší tankování tento údaj nemají.

### 36.8.3 Upozornění a odhad tachometru

Nad seznamem tankování se zobrazí žlutý panel **Upozornění k tankování**, když
u některého vozidla:

- **chybí stav tachometru** (bez něj nejde doložit spotřebu ani návaznost stavů),
- je **nesouvislá řada tachometru** (tankování má nižší stav než předchozí tankování téhož
  vozidla nebo než počáteční stav vozidla; typicky překlep nebo tankování přiřazené jinému autu),
- **odpočet DPH dokladu nesedí s nastavením vozidla** (například doklad uplatňuje plný odpočet
  u vozidla se smíšeným užíváním).

Řada tachometru se posuzuje z celé historie vozidla, filtr roku jen zúží, co se vypíše.
Tlačítko **Detail** rozbalí konkrétní data a stavy. U dotčených řádků je odznak
**⚠ tachometr** / **⚠ DPH** s vysvětlením po najetí myší.

Chybí-li u tankování stav tachometru, nabídne panel tlačítko **Nastav tachometr na odhad**
s počtem tankování, u kterých jde stav odhadnout. Po potvrzení aplikace doplní odhad jen
tam, kde tachometr chybí, zadané stavy nikdy nemění. Tlačítko respektuje filtr vozidla
a roku. Odhad vychází ze skutečných stavů u tankování, z jízd v knize jízd a z počátečního
stavu vozidla:

- připadá-li tankování na den s jízdou, vezme se začátek nebo konec jízdy (podle času
  tankování, jinak podle natankovaného množství a spotřeby vozidla),
- jinak se stav dopočítá mezi nejbližším dřívějším a pozdějším známým stavem; mezi dvěma
  tankováními se skutečným stavem podle natankovaných litrů (případně částky), jinak podle data,
- před prvním a za posledním známým stavem se použije průměrný denní nájezd.

Odhad nikdy neporuší návaznost řady: není nižší než dřívější stav ani vyšší než pozdější.
U vozidla, kde odhad nejde, uvede panel důvod (vozidlo nemá žádný známý stav, zná ho jen
k jednomu datu, nebo sousední zadané stavy jdou proti sobě). Odhadnutý stav nese v seznamu
štítek **odhad** a v exportu znak ≈. Přepíšete-li ho ve formuláři skutečnou hodnotou, nebo
přijde-li skutečný stav z dokladu, odhad se nahradí.

### 36.8.4 Jak tankování vzniká z dokladů

Tankování je čistě evidenční vrstva nad dokladem. Náklad a DPH účtuje sám doklad
([přijatá faktura](23_Prijate_faktury.md), [pokladní doklad](32_Pokladna.md)), tankování
ho jen rozpadá na jednotlivá čerpání a nabití a auta. Do DPH ani [nákladů](13_Naklady.md)
nevstupuje dvakrát. Tankování vytěžené z přijaté faktury má vazbu na fakturu pevnou.
V seznamu je u každé vazby proklik na přijatou fakturu, tisk pokladního dokladu, bankovní
výpis nebo účetní zápis. Navázat lze jen doklad vlastní firmy.

#### 36.8.4.1 Faktury od stanic

Tlačítko **Vytěžit historii** projede zpětně jen dosud nezpracované faktury od stanic.
Vozidlo se u nich určí automaticky (podle [§ 36.8.2](#3682-prirazeni-vozidla)). Každá faktura
se zpracuje jen jednou. U dokladů s detailním rozpisem (například **Axigon**) se aplikace
pokusí dohledat jednotlivá tankování včetně data, času, druhu paliva a ceny. Děje se to
interně přímo z PDF. Když to u staršího nebo zhuštěného formátu nevyjde a máte zapnutou
[AI extrakci](25_AI_extrakce.md), použije se jako záloha AI (přes váš vlastní API klíč,
viz upozornění v okně, může vzniknout drobný náklad za tokeny). Když ani to není možné,
uloží se jeden souhrnný záznam s datem vystavení, popisem a částkou z faktury. Architektura
parserů je rozšiřitelná, další karetní společnost s jiným formátem výpisu lze doplnit bez
zásahu do zbytku. Funguje to pro benzínky i provozovatele nabíjení (ČEZ, PRE, E.ON, Ionity
a podobné), kteří účtují v kWh.

#### 36.8.4.2 Pokladní doklady

Když firma vede knihu jízd (má aspoň jedno vozidlo), vznikne ze zaúčtovaného výdajového
dokladu tankování samo, pokud:

- partner dokladu je dodavatel označený jako čerpací stanice (podle IČO), nebo
- popis dokladu zní na pohonné hmoty („Nafta", „Natural 95", „Nabíjení vozidla" a podobně).

Mytí, dálniční známka, parkování a podobné služby tankováním nejsou. Úhrada přijaté
faktury se tu nepočítá, tankování té faktury se vytěží z faktury, jinak by bylo v knize
dvakrát.

Z popisu dokladu aplikace přečte litry (nebo kWh), cenu za litr (chybí-li, dopočítá ji
z částky), druh paliva, SPZ a stav tachometru („Nafta 42,5 l, tach. 98 765, 1AB 2345").
Částka a DPH se převezmou z dokladu. Vozidlo se přiřadí podle [§ 36.8.2](#3682-prirazeni-vozidla),
přičemž SPZ se hledá nejdřív v SPZ dokladu, pak kdekoli v popisu a karta se pozná podle
maskovaného čísla v popisu („karta **** 1234"). Jeden pokladní doklad dá vždy nejvýš jedno
tankování, opakované vytěžení jen doplní chybějící údaje. Samotné právo na knihu jízd na
pokladní doklady nestačí, tlačítko **Z pokladny** vidí jen uživatel, který smí číst pokladnu.

#### 36.8.4.3 Skeny účtenek

Když [dávka skenů](35_Pripojeni_skenu.md) připojí sken k přijaté faktuře nebo pokladnímu
dokladu (sama, nebo po vašem potvrzení), aplikace z vytěžené účtenky vytvoří tankování,
pokud firma vede knihu jízd a účtenka je tankování:

- některá položka účtenky je pohonná hmota nebo nabíjení (mytí, dálniční známka a podobné
  služby ne), nebo
- účtenka položky nemá a dodavatel je čerpací stanice, případně účtenka nese SPZ.

Z účtenky se převezmou datum, částka, litry, cena za litr, druh paliva, stanice, SPZ
a koncovka platební karty. Vozidlo se určí podle [§ 36.8.2](#3682-prirazeni-vozidla).
Tankování je navázané na doklad, ke kterému se sken připojil.

Jeden doklad dá vždy nejvýš jedno tankování. Když doklad tankování už má (vzniklo
z pokladního dokladu nebo z faktury od stanice), sken jen doplní chybějící údaje, vyplněné
hodnoty nepřepíše. Stejně tak pozdější **Načíst z faktur** doplní tankování vzniklé ze
skenu, místo aby založilo druhé.

### 36.8.5 Import jízd a tankování ze souboru

**Jízdy** (CSV nebo XLSX). Hlavička určuje sloupce, pořadí je libovolné:

```
datum, cas, auto, km_zacatek, km_konec, ujeto, ucel, odkud, kam, kategorie
```

- **datum** je povinné (`dd.mm.rrrr` i ISO; u XLSX se datum čte z buňky, takže funguje
  bez ohledu na formát zobrazení),
- **auto** je SPZ nebo název; je-li prázdné a máte jen jedno auto, použije se ono,
- **ujeto** se dopočítá z rozdílu tachometrů, když ho nevyplníte,
- **kategorie**, která ještě neexistuje, se automaticky založí,
- **stav tachometru** se ukládá v celých kilometrech (128,5 se zaokrouhlí na 129); ujeté
  km se ale dopočtou z přesných stavů, takže 128,5 → 135,2 je 6,7 km.

Čísla v importech (jízdy, tankování i ostatní importy v aplikaci) mohou mít české
i anglické formátování: `128,5`, `128.5`, `1 234,50`, `1.234,50` i `1,234.50`. Obsahuje-li
číslo čárku i tečku, desetinný oddělovač je ten poslední. Jediná čárka nebo tečka je vždy
desetinná, opakovaná (`1.234.567`) odděluje tisíce.

Chcete-li import zopakovat s opraveným souborem, původní jízdy smažte hromadně: zaškrtněte
jízdy v seznamu (zaškrtávátko u měsíce označí celý měsíc na stránce) a zvolte **Smazat
označené**. Jsou-li označeny všechny jízdy na stránce, nabídne lišta **Označit všech N jízd
podle filtru**, které smaže vše, co odpovídá zvolenému autu, roku a měsíci, i přes více
stránek.

**Tankování** (CSV nebo XLSX, české i anglické názvy sloupců):

```
datum, cas, spz, palivo, litry, jednotka, cena_za_litr, celkem,
bez_dph, dph, mena, tachometr, stanice, cislo_uctenky, karta, poznamka
```

- **datum** a **celkem** (částka včetně DPH) jsou povinné; chybí-li částka, spočítá se
  z litrů a ceny za litr,
- **spz** přiřadí vozidlo (bez ohledu na mezery a pomlčky); neznámá SPZ je chyba řádku,
  prázdná znamená vozidlo podle karty, jinak výchozí vozidlo,
- **karta** je koncovka platební karty; uloží se jen poslední čtyři číslice, i kdyby soubor
  nesl celé číslo karty,
- **jednotka** je l nebo kWh; u elektromobilu je výchozí kWh.

Opakovaný import nic nezdvojí. Každý řádek má otisk z data, času, SPZ, částky, litrů a čísla
účtenky. Řádek, který už v knize je, se jen doplní o dříve chybějící údaje (litry, cenu,
tachometr, vozidlo), nikdy se nepřepíše. Stejně se pozná i řádek uvedený v souboru dvakrát.

### 36.8.6 Kategorie cest

Záložka **Kategorie cest** je číselník pro rozlišení účelu jízdy. Výchozí jsou **Služební**
a **Soukromá**. Příznak **Soukromá jízda (daňově neuznatelná)** označuje daňově neuznatelné
jízdy. Kategorie přidáte tlačítkem **Nová kategorie** (**Kód** a **Název**), můžete je
upravovat a archivovat. **Výchozí kategorie** se předvyplní u nové jízdy. Kategorii
s navázanými jízdami nelze smazat. Nové kategorie vznikají i automaticky při importu.

### 36.8.7 Souhrny

Záložka **Souhrny** dává roční daňový a účetní přehled počítaný z jízd a tankování
a nabíjení, po vozidlech: ujeté km (služební, soukromé, nezařazené) včetně poměru pro
krácení, stav tachometru od - do, náklady na energii a spotřebu. U vozidla na palivo se
zobrazí l/100 km, u elektromobilu kWh/100 km. U plug-in hybridu se obě spotřeby počítají
odděleně a zobrazí se vedle sebe, litry se s kWh nikdy nesčítají. Hvězdička `*` u spotřeby
značí, že u některých tankování nebo nabíjení nebylo známé množství, takže je spotřeba
jen orientační.

Souhrn dál hlídá návaznost tachometru (skoky mezi po sobě jdoucími jízdami: chybí km,
překryv km) a informativně srovnává s paušálem na dopravu (5 000 / 4 000 Kč/měs; max. 3
vozidla, vzájemně se vylučuje se skutečnými výdaji). Pod přehledem jsou grafy najetých km
po měsících a kumulativně. Vše vyexportujete do XLSX nebo PDF.

### 36.8.8 Tipy

- **Tachometr na přelomu roku.** Stav k 31. 12. a 1. 1. doložíte poslední jízdou v prosinci
  a první v lednu. Díky předvyplnění tachometru na sebe jízdy navazují samy.
- **Účel pište konkrétně**, například „Jednání s klientem, Praha", ne jen „práce". Finanční
  úřad může vyžadovat bližší popis.
- **Soukromé jízdy veďte také.** Při krácení odpočtu (například paušál na PHM) je užitečné
  mít poměr služebních a soukromých km.
- **SPZ a tachometr do popisu účtenky** (viz tip v [§ 36.5.3](#3653-z-pokladnich-dokladu)).
- **Elektromobil** nastavte v Automobilech jako **Elektro**. Nové záznamy pak rovnou nabízejí
  jednotku kWh a spotřeba se počítá v kWh/100 km. Dobíjení doma na domovní elektřinu, které
  nejde oddělit od fakturace za domácnost, zadávejte ručně (odhad kWh z rozdílu tachometru
  a spotřeby).

### 36.8.9 Pravidelné cesty a km k doplnění

**Km k doplnění** jsou rozdíl mezi zapsaným stavem tachometru a tím, co pokrývají zapsané
jízdy. U každého stavu tachometru se vezme nejvyšší z těchto hodnot:

- konec poslední jízdy téhož auta s datem do data stavu,
- předchozí zapsaný stav tachometru,
- počáteční stav auta.

Co stav tachometru převyšuje, je k doplnění. Období začíná datem té hodnoty a končí dnem
zápisu stavu. Zapisujete-li stav každý den, dostanete km k doplnění rozložené po dnech.
Jízdy bez stavu tachometru pokrytí neposunou. První stav auta bez jízd a bez počátečního
stavu je jen výchozí bod a km k doplnění netvoří.

Doplněné jízdy se seřadí podle data a času odjezdu stejně jako při přepočtu tachometru
a v souhrnu.
Jízda bez času je v daném dni první. První jízda začíná na stavu, kde období začíná, každá
další navazuje na konec předchozí a poslední končí přesně na zapsaném stavu. Tachometr je
v celých km, ujeté km s desetinou. Konce se proto zaokrouhlují ze součtu, aby se
nepřesnost nesčítala. Soukromá jízda na zbytek nemá čas odjezdu. Dostane první
nearchivovanou kategorii označenou jako soukromá jízda a účel „Soukromá jízda".

Účel u pravidelné cesty pište stejně konkrétně jako u jednotlivé jízdy. Finanční úřad
posuzuje každou jízdu zvlášť, i když vznikla z pravidelné cesty.

### 36.8.10 Automatický stav tachometru

Stav tachometru se načítá každý den ve 3:30 úlohou `cron-logbook-odometer-source`
(nastavení plánovače je v [playbooku automatizačních skriptů](../cmd/README.md)).
Tlačítko **Načíst teď** v dialogu **Tachometr** načte stav hned.

- Ukládá se stav, který auto naposledy odeslalo, k datu a času odeslání. Auto data
  posílá hlavně při jízdě a zamknutí.
- Napojení patří k účtu u služby, ne k autu. Všechna auta jednoho účtu sdílí jedno
  přihlášení i denní limit dotazů.
- Na den připadá jeden automatický stav. Další načtení téhož dne ho přepíše. Ručně
  zapsaný stav téhož dne zůstává vedle něj a pro **Km k doplnění** platí ten ruční.
- Stav nižší než konec dřívější jízdy nebo dřívější stav, případně vyšší než pozdější
  stav, se neuloží. U auta se ukáže varování **Tachometr: upozornění** a další den se
  stav zkusí znovu. Stejně se označí auto, které stav tachometru zatím neodeslalo.
  Varování denní načtení nepovažuje za chybu, štítek **Tachometr: chyba** znamená, že je
  potřeba zásah (nové přihlášení, oprávnění k autu u služby).
- Stav v mílích se převede na kilometry.
- BMW CarData povoluje 50 dotazů denně za účet. Denní načtení spotřebuje jeden na auto,
  připojení prvního auta tři, dalšího dva. Kolik je dnes použito, ukazuje dialog **Tachometr**.
- Přihlášení k BMW CarData platí dva týdny a každé načtení ho prodlouží. Když se stav
  dva týdny nenačte, je potřeba účet připojit znovu.
- Ze služby se čte jen stav tachometru, ne poloha ani trasy. Přístup je uložený šifrovaně.
  **Odpojit auto** auto od účtu odpojí. U posledního auta účtu smaže i přístup. Načtené
  stavy tachometru zůstanou.
- Napojení spravujete jen po přihlášení v aplikaci, ne přes API token.

## 36.9 Související kapitoly

- [Dokumenty](34_Dokumenty.md) - úložiště skenů a dokladů
- [Připojení skenů k dokladům](35_Pripojeni_skenu.md) - tankování ze skenů účtenek
- [Přijaté faktury](23_Prijate_faktury.md) a [Pokladna](32_Pokladna.md) - doklady, ze kterých tankování vzniká
- [Platební karty](31_Platebni_karty.md) - přiřazení vozidla podle karty
- [AI extrakce](25_AI_extrakce.md) - záložní vytěžení faktur
- [Výkazy DPH](41_Vykazy_DPH.md) - krácení odpočtu podle § 76
