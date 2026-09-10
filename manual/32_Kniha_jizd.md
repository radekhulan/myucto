# 32. Kniha jízd

**Kniha jízd** je evidence jízd, vozidel a tankování (u elektromobilů nabíjení).
Slouží jako *kniha důkazů* pro uplatnění nákladů na pohonné hmoty — u elektromobilů
na elektřinu — v daních (§ 24 zákona o daních z příjmů, pokyn GFŘ D-22); bez ní
finanční úřad odpočet neuzná. Najdeš ji v menu v sekci **Dokumenty**, hned pod
[Dokumenty](31_Dokumenty.md).

Modul má pět záložek: **Kniha jízd** (jednotlivé jízdy), **Automobily**
(číselník vozidel), **Tankování** (i nabíjení), **Kategorie cest** a **Souhrny**
(roční daňový přehled). Vše je odděleně **per dodavatel** (firma/IČO).

Co kniha jízd ze zákona musí evidovat:

- **u vozidla** — typ, SPZ (registrační značku) a stav tachometru k zahájení
  (typicky k 1. 1.) i ke konci roku,
- **u každé jízdy** — datum, čas odjezdu/příjezdu, odkud → kam, **konkrétní účel**
  cesty (samotné slovo „práce" nestačí) a ujeté kilometry / stav tachometru.

## 32.1 Automobily

V záložce **Automobily** vedeš číselník vozidel. U auta zadáš **SPZ** (povinné),
volitelně značku, model, VIN, **druh paliva** a **počáteční stav tachometru** (k datu
pořízení / k 1. 1.). U druhu paliva vyber kromě nafty/benzínu/LPG/CNG i **Elektro**
nebo **Hybrid** — podle toho aplikace pozná, že vozidlo se **nabíjí v kWh** místo
tankování v litrech, a tomu přizpůsobí jednotky a spotřebu. Příznak **Výchozí auto**
určuje, na které vozidlo se nové záznamy a tankování navážou automaticky, když máš aut víc.

### 32.1.1 Řidič, režim užívání a odpočet DPH

- **Řidič** je zaměstnanec, jemuž je vozidlo svěřené. Vybírá se ze zaměstnanců firmy
  (Mzdy → Zaměstnanci); kniha jízd z nich vidí jen jméno.
- **Režim užívání** říká, k čemu vozidlo slouží: **jen firemní**, **firemní i soukromé**
  (smíšené), nebo **soukromé vozidlo** použité pro firmu.
- **Odpočet DPH** určuje, jaký nárok na odpočet mají doklady za provoz vozidla:
  **plný**, **poměrný (§ 75)** s podílem v procentech, nebo **bez odpočtu**.

Režimy spolu souvisejí: smíšené užívání vyžaduje **poměrný** odpočet (podíl odpovídá
podnikatelskému užití, které doložíš knihou jízd — poměr služebních a soukromých km
najdeš v Souhrnech), soukromé vozidlo odpočet nemá. Formulář nabízí jen povolené
kombinace. Koeficient krácení podle § 76 se u vozidla nenastavuje — je celofiremní
a počítá se v [Daních](36_Vykazy_DPH.md).

Nastavení odpočtu nic neúčtuje — odpočet uplatňuje doklad. Kniha jízd ale u každého
tankování porovná odpočet navázaného dokladu s nastavením vozidla a nesoulad ukáže
jako upozornění (viz 32.3.6).

Auto, které má navázané jízdy nebo tankování, nelze smazat (jen archivovat při
úpravě) — historie zůstane zachována.

## 32.2 Kniha jízd — jízdy

Záložka **Kniha jízd** je seznam jednotlivých jízd, seskupený **po měsících**
s měsíčním součtem ujetých km. Nahoře filtruješ podle auta, roku a měsíce
(výchozí = vše); dlouhý seznam se stránkuje.

### 32.2.1 Nový záznam

Tlačítko **Nový záznam** otevře formulář jízdy:

- **Tachometr zahájení** se předvyplní **posledním známým konečným stavem** auta,
  takže na sebe jízdy plynule navazují.
- Zadáš-li **Ujeto (km)** a necháš prázdný **Tachometr konec**, konec se
  **dopočítá** ze začátku. A naopak — vyplníš-li oba tachometry, ujeté km se
  spočítají jako rozdíl.
- Pole **Účel cesty** **našeptává** dříve zadané účely — stačí začít psát.
- **Kategorie cesty** (služební / soukromá) viz níže.

### 32.2.2 Import z CSV / XLSX

Tlačítko **Import** nahraje knihu jízd z **CSV nebo XLSX**. Hlavička určuje
sloupce (pořadí je libovolné), podporované názvy:

```
datum, cas, auto, km_zacatek, km_konec, ujeto, ucel, odkud, kam, kategorie
```

- **datum** je povinné (`dd.mm.rrrr` i ISO; u XLSX se datum čte z buňky, takže
  funguje bez ohledu na formát zobrazení),
- **auto** je SPZ nebo název; je-li prázdné a máš jen jedno auto, použije se ono,
- **ujeto** se dopočítá z rozdílu tachometrů, když ho nevyplníš,
- **kategorie**, která ještě neexistuje, se **automaticky založí**.

Tlačítkem **Stáhnout vzor** získáš prázdnou šablonu CSV. Po importu se zobrazí
přehled (kolik jízd vzniklo, kolik řádků selhalo a proč) i seznam nově
založených kategorií.

### 32.2.3 Export

Tlačítkem **Export** vyexportuješ jízdy za **zvolené období** (datum od/do) a
auto do **XLSX** nebo **PDF**. Výstup je seskupený po vozidlech, s mezisoučty
a celkovým počtem km — vhodné jako příloha k daňové evidenci.

## 32.3 Tankování a nabíjení

Tankování (u elektromobilů **nabíjení**) můžeš vést **ručně**, **importovat** ze
souboru, nebo ho nechat vzniknout **z dokladu**, kterým bylo zaplaceno — z přijaté
faktury od čerpací či nabíjecí stanice, z pokladního dokladu, nebo ho navázat na
bankovní pohyb či účetní zápis. Seznam je po měsících s měsíčním součtem částek,
s filtrem a stránkováním.

Akce záložky jsou v liště vpravo nahoře: **Nové tankování**, **Import**,
**Načíst z faktur**, **Z pokladny** a v nabídce „…" **Export** (XLSX/PDF, stejně
jako u jízd).

Tankování je čistě **evidenční vrstva** nad dokladem — náklad a DPH účtuje sám
doklad ([přijatá faktura](23_Prijate_faktury.md), [pokladní doklad](30_Pokladna.md)),
tankování ho jen rozpadá na jednotlivá čerpání/nabití a auta. Do DPH ani
[nákladů](13_Naklady.md) nevstupuje dvakrát.

### 32.3.1 Ruční záznam

Tlačítko **Nové tankování** otevře formulář s datem, množstvím, částkou a místem.
U množství je **přepínač jednotky l / kWh** — automaticky se předvyplní podle
vybraného vozidla (u elektromobilu **kWh**, jinak **litry**). U **plug-in hybridu**
přepínač necháváš na sobě: tankování benzínu zadáš v litrech, dobíjení v kWh.
Pole **Tachometr** se dá nechat prázdné — aplikace doplní *orientační* stav z knihy
jízd (zobrazí se jako `≈`), pro přesnost ho ale raději vyplň.

### 32.3.2 Vazba na doklad

Ve formuláři tankování je sekce **Vazba na doklad**. Vybereš druh dokladu —
**pokladní doklad**, **bankovní pohyb** (typicky platba kartou) nebo **účetní
zápis** — a aplikace nabídne doklady kolem data tankování; ty se **shodnou částkou**
jsou nahoře a označené. Seznam zúžíš hledáním (číslo dokladu, partner, popis).
Vazbu zrušíš tlačítkem **Zrušit vazbu**.

Tankování vytěžené z přijaté faktury má vazbu na fakturu pevnou (vznikla vytěžením).
V seznamu tankování je u každé vazby **proklik**: na přijatou fakturu, na tisk
pokladního dokladu, na bankovní výpis nebo na účetní zápis v deníku. Navázat lze jen
doklad vlastní firmy.

### 32.3.3 Načíst z faktur od stanic

1. V detailu dodavatele zaškrtni **Čerpací / nabíjecí stanice** (sekce dodavatele).
   Tím se jeho faktury začnou nabízet ke zpracování — funguje jak pro benzínky,
   tak pro provozovatele nabíjení (ČEZ, PRE, E.ON, Ionity apod.), kteří účtují v kWh.
2. V záložce Tankování klikni na **Načíst z faktur**. Zobrazí se faktury od
   stanic; každá má odznak **Nová** / **Zpracováno** a tlačítko **Detail**,
   které rozbalí položky faktury (pohonné hmoty i nabíjení jsou zvýrazněné).
3. Vyber auto a klikni **Rozpoznat** — z faktury se vytvoří záznamy tankování /
   nabíjení navázané na zvolené vozidlo a na původní doklad (číslo dokladu je
   v seznamu proklikem na fakturu).

Tlačítko **Vytěžit historii** projede zpětně **jen dosud nezpracované** faktury
od stanic a hromadně z nich vytvoří záznamy. Každá faktura se zpracuje jen
jednou, opakované spuštění nic nezdvojí.

U dokladů s detailním rozpisem (např. **Axigon**) se aplikace pokusí dohledat
**jednotlivá tankování** včetně data, času, druhu paliva a ceny. Děje se to
**interně** přímo z PDF; když to u staršího/zhuštěného formátu nevyjde a máš
zapnutou [AI extrakci](25_AI_extrakce.md), použije se jako záloha AI (přes tvůj
vlastní API klíč — viz upozornění v okně). Když ani to není možné, uloží se jeden
souhrnný záznam s datem vystavení, popisem a částkou z faktury.
Architektura parserů je rozšiřitelná — další karetní společnost s jiným formátem
výpisu lze doplnit bez zásahu do zbytku.

### 32.3.4 Tankování z pokladních dokladů

Účtenku za PHM zaplacenou hotově zaúčtuješ v [Pokladně](30_Pokladna.md) jako výdajový
doklad. Když firma vede knihu jízd (má aspoň jedno vozidlo), vznikne ze
**zaúčtovaného** dokladu tankování **samo**, pokud:

- partner dokladu je dodavatel označený jako **čerpací stanice** (podle IČO), nebo
- popis dokladu zní na pohonné hmoty („Nafta", „Natural 95", „Nabíjení vozidla"…).

Mytí, dálniční známka, parkování a podobné služby tankováním nejsou. Úhrada přijaté
faktury se tu nepočítá — tankování té faktury se vytěží z faktury, jinak by bylo
v knize dvakrát.

Z popisu dokladu aplikace přečte, co v něm je: **litry** (nebo kWh), **cenu za litr**
(chybí-li, dopočítá ji z částky), **druh paliva**, **SPZ** a **stav tachometru**
(„Nafta 42,5 l, tach. 98 765, 1AB 2345"). Částka a DPH se převezmou z dokladu.
**Vozidlo** se přiřadí podle SPZ (bez ohledu na mezery a velikost písmen), jinak
podle SPZ uvedené kdekoli v popisu, jinak výchozí vozidlo.

Tlačítko **Z pokladny** ukáže všechny takové doklady s odznakem **Nová** /
**Zpracováno**. U každého můžeš zvolit vozidlo (nebo nechat **automaticky**)
a kliknout **Rozpoznat**, resp. **Přiřadit** pro změnu vozidla u již vytvořeného
tankování. **Vytěžit historii** zpracuje dosud nezpracované doklady. Jeden pokladní
doklad dá vždy nejvýš jedno tankování — opakované vytěžení jen doplní chybějící údaje.

### 32.3.5 Import tankování z CSV / XLSX

Tlačítko **Import** nahraje tankování ze souboru (třeba výpis od karetní společnosti
nebo převod z jiné evidence). Po výběru souboru se nejdřív ukáže **náhled** — každý
řádek s tím, co by se stalo (**nové**, **už evidováno**, **chyba** s důvodem).
Teprve tlačítko **Importovat** tankování založí.

Podporované sloupce (hlavička určuje pořadí, CZ i EN názvy):

```
datum, cas, spz, palivo, litry, jednotka, cena_za_litr, celkem,
bez_dph, dph, mena, tachometr, stanice, cislo_uctenky, karta, poznamka
```

- **datum** a **celkem** (částka vč. DPH) jsou povinné; chybí-li částka, spočítá se
  z litrů a ceny za litr,
- **spz** přiřadí vozidlo (bez ohledu na mezery a pomlčky); neznámá SPZ je chyba řádku,
  prázdná znamená výchozí vozidlo,
- **jednotka** l / kWh; u elektromobilu je výchozí kWh.

**Opakovaný import nic nezdvojí.** Každý řádek má otisk z data, času, SPZ, částky,
litrů a čísla účtenky; řádek, který už v knize je, se jen **doplní** o dříve chybějící
údaje (litry, cenu, tachometr, vozidlo) — nikdy se nepřepíše. Stejně se pozná i řádek
uvedený v souboru dvakrát. Tlačítkem **Stáhnout vzor** získáš prázdnou šablonu CSV.

### 32.3.6 Upozornění

Nad seznamem tankování se zobrazí žlutý panel **Upozornění k tankování**, když
u některého vozidla:

- **chybí stav tachometru** — bez něj nejde doložit spotřebu ani návaznost stavů,
- je **nesouvislá řada tachometru** — tankování má nižší stav než předchozí tankování
  téhož vozidla (nebo nižší než počáteční stav vozidla); typicky překlep nebo tankování
  přiřazené jinému autu,
- **odpočet DPH dokladu nesedí s nastavením vozidla** — např. doklad uplatňuje plný
  odpočet u vozidla se smíšeným užíváním (viz 32.1.1).

Řada tachometru se posuzuje z celé historie vozidla, filtr roku jen zúží, co se
vypíše. Tlačítko **Detail** rozbalí konkrétní data a stavy. U dotčených řádků seznamu
je odznak **⚠ tachometr** / **⚠ DPH** s vysvětlením po najetí myší.

## 32.4 Kategorie cest

Záložka **Kategorie cest** je číselník pro rozlišení účelu jízdy. Výchozí jsou
**Služební** a **Soukromá**; příznak *soukromá* označuje daňově neuznatelné jízdy.
Kategorie můžeš přidávat, upravovat a archivovat; kategorii s navázanými jízdami
nelze smazat. Nové kategorie vznikají i automaticky při importu (viz výše).

## 32.5 Souhrny

Záložka **Souhrny** dává **roční daňový/účetní přehled** počítaný z jízd a
tankování/nabíjení — **per vozidlo**: ujeté km (služební / soukromé / nezařazené)
včetně poměru pro krácení, stav tachometru od → do, náklady na energii a
**spotřebu**. U vozidla na palivo se zobrazí **l/100 km**, u elektromobilu
**kWh/100 km**; u **plug-in hybridu** se obě spotřeby počítají **odděleně** a
zobrazí se vedle sebe (litry se s kWh nikdy nesčítají). Hvězdička `*` u spotřeby
značí, že u některých tankování/nabíjení nebylo známé množství, takže je spotřeba
jen orientační.

Souhrn dál hlídá **návaznost tachometru** (skoky mezi po sobě jdoucími jízdami)
a informativně srovnává s **paušálem na dopravu** (5 000 / 4 000 Kč/měs). Pod
přehledem jsou grafy najetých km po měsících a kumulativně; vše vyexportuješ do
**XLSX** nebo **PDF** jako přílohu k daňové evidenci.

## 32.6 Tipy

- **Tachometr na přelomu roku** — stav k 31. 12. / 1. 1. doložíš poslední jízdou
  v prosinci a první v lednu; díky předvyplnění tachometru na sebe navazují samy.
- **Účel piš konkrétně** — např. „Jednání s klientem, Praha", ne jen „práce";
  finanční úřad může vyžadovat bližší popis.
- **Soukromé jízdy** veď taky — při krácení odpočtu (např. paušál na PHM)
  je užitečné mít poměr služebních a soukromých km.
- **SPZ a tachometr do popisu účtenky** — napíšeš-li do popisu pokladního dokladu
  „Nafta 40 l, tach. 123 456, 1AB 2345", tankování se přiřadí správnému vozu
  i se stavem tachometru bez další práce.
- **Elektromobil** nastav v Automobilech jako **Elektro** — nové záznamy pak
  rovnou nabízí jednotku **kWh** a spotřeba se počítá v kWh/100 km. Dobíjení
  doma na domovní elektřinu, které nejde oddělit od fakturace za domácnost,
  zadávej **ručně** (odhad kWh z rozdílu tachometru a spotřeby).
