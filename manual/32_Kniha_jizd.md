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

Tankování (u elektromobilů **nabíjení**) můžeš vést **ručně**, nebo je nechat
**vytěžit z přijatých faktur** od čerpacích a nabíjecích stanic. Seznam je opět
po měsících s měsíčním součtem částek, s filtrem a stránkováním; export do
XLSX/PDF funguje stejně jako u jízd.

Tankování je čistě **evidenční vrstva** nad přijatou fakturou — náklad účtuje
sama [přijatá faktura](23_Prijate_faktury.md), tankování ho jen rozpadá na
jednotlivá čerpání/nabití a auta. Do DPH ani [nákladů](13_Naklady.md) nevstupuje dvakrát.

### 32.3.1 Ruční záznam

Tlačítko **Nové tankování** otevře formulář s datem, množstvím, částkou a místem.
U množství je **přepínač jednotky l / kWh** — automaticky se předvyplní podle
vybraného vozidla (u elektromobilu **kWh**, jinak **litry**). U **plug-in hybridu**
přepínač necháváš na sobě: tankování benzínu zadáš v litrech, dobíjení v kWh.
Pole **Tachometr** se dá nechat prázdné — aplikace doplní *orientační* stav z knihy
jízd (zobrazí se jako `≈`), pro přesnost ho ale raději vyplň.

### 32.3.2 Načíst z faktur od stanic

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

### 32.3.3 Detailní výpisy (Axigon a další)

U dokladů s detailním rozpisem (např. **Axigon**) se aplikace pokusí dohledat
**jednotlivá tankování** včetně data, času, druhu paliva a ceny. Děje se to
**interně** přímo z PDF; když to u staršího/zhuštěného formátu nevyjde a máš
zapnutou [AI extrakci](25_AI_extrakce.md), použije se jako záloha AI (přes tvůj
vlastní API klíč — viz upozornění v okně). Když ani to není možné, uloží se jeden
souhrnný záznam s datem vystavení, popisem a částkou z faktury.

Architektura parserů je rozšiřitelná — další karetní společnost s jiným formátem
výpisu lze doplnit bez zásahu do zbytku.

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
- **Elektromobil** nastav v Automobilech jako **Elektro** — nové záznamy pak
  rovnou nabízí jednotku **kWh** a spotřeba se počítá v kWh/100 km. Dobíjení
  doma na domovní elektřinu, které nejde oddělit od fakturace za domácnost,
  zadávej **ručně** (odhad kWh z rozdílu tachometru a spotřeby).
