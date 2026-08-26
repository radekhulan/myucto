# 79. Účetní kontroly a inventarizace

MyÚčto nabízí několik kontrolních pohledů, ale žádný z nich sám neprokazuje věcnou
správnost účetnictví. Tato kapitola skládá jednotlivé kontroly do jednoho měsíčního
a ročního postupu a vysvětluje, co systém pouze signalizuje a co musí účetní ověřit
nezávislým podkladem.

## 79.1 Tři vrstvy kontroly

1. **Provozní úplnost** — [K doúčtování](47_Rucni_fronta_doctovani.md)
   a [Úplnost dokladů](54_Uplnost_dokladu.md) hledají známé položky bez
   dokončeného zpracování.
2. **Účetní integrita** — měsíční kontrola, deník, předvaha a saldokonto hledají
   nevyrovnané zápisy, neobvyklé zůstatky, nezpracované úhrady a chybějící návaznosti.
3. **Inventarizace a daňová rekonciliace** — účetní zůstatek se porovnává s fyzickým,
   smluvním nebo externě potvrzeným skutečným stavem.

Prázdná fronta nebo zelený technický check není důkaz, že nechybí smlouva, přijatá
faktura, závazek, majetek nebo časové rozlišení, které systém neměl z čeho rozpoznat.

## 79.2 Doporučená měsíční kontrola

1. Dokonči nebo vysvětli položky v [**K doúčtování**](47_Rucni_fronta_doctovani.md).
2. Otevři [**Účetnictví → Úplnost dokladů**](54_Uplnost_dokladu.md)
   a projdi bankovní pohyby bez dokladu a
   neuhrazené doklady po splatnosti.
3. Spusť [**Účetnictví → Měsíční kontrola**](55_Mesicni_kontrola.md)
   za přesný měsíc či kvartál.
4. Porovnej banku a pokladnu s výpisy, saldokonto s knihou pohledávek a závazků a
   účet 343 s DPH za stejné období.
5. U cizoměnových dokladů spusť **Daně → Audit kurzů (ČNB)** a vysvětli významné
   odchylky.
6. Před podáním DPH přiznání spusť **Daně → Úplnost číselné řady** a vysvětli
   nalezené mezery v číslování vydaných dokladů.
7. Oprav zdrojové doklady a kontroly spusť znovu.
8. Po potvrzeném podání DPH období vědomě zamkni k datu a ulož důvod.

Kontroly jsou read-only, pokud u nich není výslovně uvedeno tlačítko pro vytvoření
návrhu či zápisu. Nález se nemá „zazelenat“ ručním dorovnáním bez podkladu.

## 79.3 Provozní úplnost dokladů

Podrobný popis obou pracovních pohledů je rozdělený podle bodů menu:

- [K doúčtování](47_Rucni_fronta_doctovani.md) je operativní fronta skutečných
  bankovních pohybů bez návrhu, nezaúčtovaných dokladů a otevřených žádostí;
- [Úplnost dokladů](54_Uplnost_dokladu.md) kontroluje bankovní pohyby bez
  podkladu a opačným směrem otevřené saldo po splatnosti.

Pohyb může být oprávněně bez faktury — například daň, pojistné, bankovní poplatek,
převod mezi vlastními účty nebo výplata. Požadavek na chybějící dokument pouze
eviduje komunikaci; případ je vyřešený až po doručení, kontrole a správném
zaúčtování podkladu.

## 79.4 Audit párování plateb

[Měsíční kontrola](55_Mesicni_kontrola.md) porovnává stav úhrad, vazby
bankovních pohybů a otevřené saldo.
Typické nálezy:

- doklad označený jako zaplacený bez odpovídající úhrady,
- aktivní úhrada k dokladu, který stále vystupuje jako neuhrazený,
- spárovaná záloha a finální doklad se špatným zůstatkem,
- částka nebo měna platby neodpovídá vazbě,
- vlastní převod nemá obě strany účtu 261.

Opravuj vazbu platby nebo účetní zápis. Neměň ručně jen stav dokladu, pokud by tím
evidence úhrad a deník přestaly odpovídat skutečnosti.

## 79.5 Inventarizace rozvahových účtů

**Cesta: `Účetnictví → Inventarizace účtů`**

Sestava vytvoří k rozvahovému dni soupis konečných zůstatků účtů tříd 0–4. Číslo
účtu vede na opis a doporučený druh podkladu napovídá, čím zůstatek doložit:

| Oblast | Typický nezávislý podklad |
|---|---|
| Banka 221 | bankovní výpis nebo potvrzení banky |
| Pokladna 211 | fyzická inventura hotovosti |
| Pohledávky a závazky 311/321 | saldokonto, potvrzení partnera, následná úhrada |
| DPH 343 | podané přiznání, potvrzení a platební historie |
| Majetek 0xx | inventární karta, fyzické ověření, odpisový plán |
| Časové rozlišení a dohady | smlouva, období plnění, výpočet a schválení |
| Daně a pojistné | přiznání, přehled, předpis a platba |

Zůstatky se počítají z celého fiskálního období před závěrkovými převody na
účty 702/710. Koncepty se do nich nezapočítají; pokud v období existují,
stránka na jejich počet samostatně upozorní.

Pro otevřené nebo uzavírané období lze uložit hlavičku protokolu — odpovědnou
osobu, datum inventury a označení protokolu — a u každého účtu:

1. zadat zjištěný **skutečný stav**,
2. porovnat systémem vypočtený **rozdíl** proti účetnímu zůstatku,
3. doplnit poznámku a označit doložené či vyřešené položky,
4. uložit práci jako rozpracovanou nebo inventarizaci dokončit.

Dokončení je možné jen tehdy, když není žádný nevyřešený účet. Nulový rozdíl se
považuje za vyřešený automaticky; nenulový rozdíl musí účetní výslovně označit
za vyřešený a doložit mimo samotný číselný údaj. Poznámka u řádku je v aplikaci
volitelná. Uložení kontroluje verzi období a zapisuje auditní událost, takže
souběžná změna účetnictví nemůže být tiše překryta starým formulářem.

> [!IMPORTANT]
> Nezahájená inventarizace je při uzavření knih varování, protože firma ji může
> vést mimo aplikaci. Jakmile ji ale v aplikaci založíš, rozpracovaný stav nebo
> nevyřešené rozdíly jsou chybou, která uzavření knih blokuje. Po změně deníku
> se zůstatky přepočtou znovu, takže dříve dokončený protokol může vyžadovat
> nové odsouhlasení.

Export PDF/XLSX poskytuje soupis účetních zůstatků a prostor pro ruční
inventurní doplnění. Aktuální exportní endpoint do souboru neslévá elektronicky
uložené skutečné stavy a poznámky z formuláře; při archivaci proto přilož i
podepsané důkazy, externí potvrzení a schválený protokol podle vnitřní směrnice.
Starší již uzavřené období bez uložené inventarizace je pouze pro čtení a
aplikace jeho stav zpětně odvodí z ověřených účetních zůstatků.

## 79.6 Kontrolní mapa K1–K10

| Kód | Oblast | Co je rozhodující |
|---|---|---|
| **K1** | technické a clearingové účty | Nenulový zůstatek musí mít konkrétní případ a podklad; nemusí být automaticky chybou. |
| **K2** | neobvyklá strana účtu | Rozliš přeplatek či dobropis od obrácené kontace. |
| **K3** | úhrady a saldokonto | Stav dokladu, vazby plateb a účetní saldo musí vyprávět stejný příběh. |
| **K4** | kurz proti ČNB | Odchylku lze ponechat jen s doloženým kurzem nebo pevnou kurzovou politikou. |
| **K5** | položky proti hlavičce | Rozdíl nad toleranci oprav na dokladu; nevytvářej nevysvětlené dorovnání. |
| **K6** | měnová stopa | Cizoměnový zůstatek musí nést měnu i původní částku pro přecenění. |
| **K7** | podvojnost a rozvaha | Nevyrovnaný deník nebo aktiva ≠ pasiva je strukturální bloker. |
| **K8** | kolize variabilních symbolů | Nejednoznačné shody potvrď podle partnera, částky, měny a data. |
| **K9** | podané přiznání | Porovnávej s XML, které bylo skutečně odesláno, nikoli jen vygenerováno. |
| **K10** | závěrková úplnost | Odpisy, rozlišení, opravné položky a opakované náklady jsou návrhy k odbornému posouzení. |
| **K11** | úplnost číselné řady | Mezera v číslování vydaných dokladů je auditní signál pro FÚ — dohledej a vysvětli, nedoplňuj číslo zpětně. |

Podrobný rozpad průběžných kontrol a možnost exportovat všechna zjištění jsou
v kapitole [Měsíční kontrola](55_Mesicni_kontrola.md).

## 79.7 Audit kurzů ČNB

Audit porovná uložený kurz dokladu s kurzem ČNB k rozhodnému dni a vypíše odchylku.
Neopravuje doklady ani deník. Odchylka může být správná, pokud firma používá doložený
pevný kurz, kurz celní hodnoty nebo jiný zákonný postup. Bez doloženého důvodu oprav
kurz na zdrojovém dokladu a použij řízené přeúčtování, aby zůstala auditní stopa.

Kurz na faktuře, kurz bankovní úhrady a závěrkový kurz plní různé účely. Jejich
rozdíl se nemá odstranit přepsáním historie; tvoří realizovaný nebo nerealizovaný
kurzový rozdíl podle povahy položky.

## 79.8 Úplnost číselné řady vydaných dokladů

**Cesta: `Daně → Úplnost číselné řady`**

Sestava projde číslování vydaných faktur a dobropisů za zvolený rok a nahlásí
chybějící čísla v jinak souvislé řadě. Mezera v číselné řadě je typický kontrolní
bod finančního úřadu — nedokazuje sama o sobě chybu, ale musí mít vysvětlení
(stornovaný a přesto číslovaný doklad, ruční přečíslování, oprávněné vynechání).
Sestava jen hlásí, nic neopravuje.

Faktury a dobropisy, které mají nastavenou **stejnou číselnou šablonu**, sestava
posuzuje jako jednu společnou řadu — číslo použité dobropisem se u faktur nehlásí
jako chybějící a naopak. Má-li klient v nastavení vlastní šablonu číslování
(`Systém → Dodavatelé → Číslování faktur`), počítá se jako samostatná, nezávislá
řada. Kolize dvou různých šablon (dvě řady vyprodukující stejný VS) hlásí
samostatná kontrola v nastavení dodavatele — viz **K8** výše.

## 79.9 Vazba na uzávěrku a balíček

Uzávěrkový balíček umí shromáždit účetní výkazy, deník, knihu DPH, daňový výstup,
inventuru dlouhodobého a drobného majetku, staré saldo a časová rozlišení. Balíček
není účetní archiv a jeho dokončení není potvrzením správnosti jednotlivých částí.

Před schválením závěrky ověř a případně doplň zejména:

- inventarizaci rozvahových účtů a podepsané inventurní soupisy,
- externí bankovní a partnerská potvrzení,
- smlouvy a výpočty k významným dohadům a časovému rozlišení,
- potvrzení podaných daňových formulářů,
- vysvětlení nevyřešených varování a schválení odpovědnou osobou.
