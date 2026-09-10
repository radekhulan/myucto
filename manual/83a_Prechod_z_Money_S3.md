# 83a. Přechod z Money S3

**Cesta: `Nákup → Přechod z Money S3`**

Průvodce převede účetní agendu z Money S3 do firmy v MyÚčtu. Vstupem je záloha
agendy, kterou si firma nebo účetní vytvoří v Money funkcí *Zálohovat agendu*
(soubor `.lz`). Záloha se jen čte, instalace Money se převodem nijak nemění.

Průvodce vidí a zkoušku nanečisto spouští uživatel s oprávněním
`utilities.import` pro zápis. Ostrý převod zapisuje účetní deník, mění nastavení
firmy a uzavírá roky, proto navíc vyžaduje zápis do účetního deníku
(`accounting.journal.write`) a do nastavení firmy (`settings.company.write`),
s uzávěrkou historických let i uzavírání období (`accounting.periods.close`).
Chybějící oprávnění průvodce ukáže a převod nespustí.

Průvodce je dostupný i firmě, která zatím vede daňovou evidenci: převod ji sám
přepne do podvojného účetnictví.

## 83a.1 Co převod přenese

| Z Money | Do MyÚčta |
|---|---|
| účtový rozvrh (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy, `042000` → `042.000` |
| účetní roky | účetní období |
| účetní deník včetně počátečních stavů | účetní zápisy, počáteční stavy jako otevírací zápis k 1. dni období |
| zakázka na řádku deníku | středisko na řádku zápisu |
| adresář a bankovní spojení partnerů | klienti a jejich bankovní účty |
| předkontace | pravidla zaúčtování se zkratkou z Money |
| přijaté a vydané faktury | doklady se stavem zaúčtováno nebo uhrazeno, položka na každou sazbu DPH; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| pokladny a pokladní doklady | pokladny a zaúčtované pokladní doklady |
| bankovní účty a bankovní doklady | výpis na účet a rok se zdrojem „import", bankovní pohyby |
| úhrady faktur | spárování faktury s bankovním pohybem nebo pokladním dokladem |

**Zaúčtování se nepřepočítává.** Deník je přesná kopie toho, co bylo v Money,
a doklady se k němu jen připojí. Z dokladu je proto vidět jeho zápis a naopak,
detail faktury ukazuje úhradu jako zaúčtovanou a automatika už doklad znovu
nezaúčtuje.

**Doklady k ruční kontrole.** Fakturu, jejíž daňovou povahu záloha Money
spolehlivě neurčuje, převod převezme jako koncept:

- zálohovou fakturu, proformu a daňový doklad k platbě (jiný druh než běžná faktura),
- dobropis, stornovaný doklad a doklad, který je v Money označený „neúčtovat",
- doklad v cizí měně (částky se převezmou v Kč, měnu a kurz uvede poznámka dokladu),
- doklad s členěním DPH mimo tuzemské řádky přiznání: přenesená daňová
  povinnost, plnění z EU a do EU, zvláštní režimy, nebo doklad s daní bez členění.

Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do účtování.
Protokol ho vypíše i s důvodem. Po opravě druhu dokladu, měny nebo klasifikace
DPH ho potvrďte. Přijatá faktura s členěním „do přiznání nezahrnovat" se
převezme bez nároku na odpočet.

Číslo dokladu, které už ve firmě je, dostane příponu roku, například
`FP001/2025`. Money čísluje řady každý rok od začátku, takže stejné číslo
v dalším roce je běžné. Stejně se rozliší doklady se stejným číslem na dvou
bankovních účtech nebo ve dvou pokladnách.

## 83a.2 Co převod nepřenese

- **Přílohy a elektronický archiv.** Money je drží v šifrovaných souborech,
  které ze zálohy číst nejde. Skeny dokladů se připojují zvlášť.
- **Majetek, mzdy a sklad.** Jejich zápisy jsou v převedeném deníku, evidence
  (karty majetku, zaměstnanci, zásoby) se zakládá v MyÚčtu.
- **Interní doklady, kniha pohledávek a závazků.** V deníku jsou jako ruční
  zápisy s původním číslem dokladu, samostatný doklad z nich nevzniká.
- **Číselné řady a řádky DPH pokladních dokladů.** Podaná přiznání k DPH za
  převáděné roky zůstávají v Money.

## 83a.3 Postup

1. **Záloha agendy.** Nahrajte soubor `.lz`. Průvodce ho rozbalí (jen datové
   soubory agendy) a načte.
2. **Náhled a volby.** Zkontrolujte firmu, IČO, verzi Money a účetní roky.
   Kontrola před převodem zastaví převod, když:
   - záloha patří firmě s jiným IČO,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem,
   - období je v MyÚčtu uzavřené,
   - agenda vede hospodářský rok odlišný od kalendářního.

   Chybí-li IČO v záloze nebo ve firmě, nejde ověřit, že agenda patří této
   firmě. Zkouška nanečisto jen upozorní, ostrý převod se spustí až po
   výslovném potvrzení.

   Volby:
   - *Uzavřít historické roky* — viz 83a.5, ve výchozím stavu zapnuto.
   - *Začátek prvního účetního období* — jen u firmy založené během prvního
     převáděného roku. Bez něj začíná první období 1. 1., u roku bez počátečních
     stavů prvním zápisem deníku.
   - *Sestavy z Money* — ke každému roku můžete nahrát obratovou předvahu
     vyexportovanou z Money do CSV (účet v prvním sloupci, dál PS MD, PS D,
     obrat MD, obrat D, KS MD, KS D). Rekonciliace ji porovná s předvahou MyÚčta.
3. **Zkouška nanečisto.** Proběhne celý převod včetně uzávěrky a rekonciliace,
   na konci se ale všechno vrátí. Výsledkem je protokol; v MyÚčtu nic nezůstane
   a nastavení automatiky se nezmění. Zkouška běží v jedné databázové transakci
   a po celou dobu drží zámky převáděných účetních období: spouštějte ji mimo
   běžnou práci ve firmě, zápisy do těchto období do jejího konce počkají.
4. **Ostrý převod.** Po potvrzení běží na pozadí, stránku můžete zavřít. Po
   dokončení průvodce nabídne účetní deník a obratovou předvahu. Převod jedné
   firmy běží vždy jen jeden, druhý se do jeho konce nespustí.

## 83a.4 Rekonciliace a protokol

Každý běh (zkouška i převod) končí protokolem. Najdete v něm kroky převodu
s počty, upozornění a chyby a pro každý rok rekonciliaci:

- obratová předvaha MyÚčta proti předvaze spočtené přímo z deníku Money
  v záloze, po syntetických účtech, počáteční stav, obrat a konečný stav na haléř,
- obratová předvaha proti sestavě z Money, pokud jste ji nahráli,
- obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné rozpracované zápisy,
- doklady proti deníku: přijaté faktury proti 321, vydané proti 311, pokladna
  proti 211 a banka proti 221.

Doklad, ke kterému v deníku Money není zápis se stejným číslem, protokol vypíše
jako doklad bez zápisu. Takový doklad není zaúčtovaný. Zaúčtujte ho ručně nebo
hromadně v Účetnictví → Doúčtovat doklady. Koncepty k ruční kontrole se mezi
doklady bez zápisu nepočítají.

Úhradu faktury páruje převod podle čísla dokladu úhrady, které Money
u faktury drží. Číslo se v Money každý rok opakuje, takže mezi pohyby se
stejným číslem rozhoduje shoda částky, pak datum úhrady a nakonec rok. Dva
stejně dobré kandidáty převod nespáruje a protokol je vypíše k ručnímu
spárování. Spárovaná faktura dostane stav uhrazeno.

Protokoly všech běhů zůstávají v přehledu pod průvodcem.

## 83a.5 Uzávěrka historických let

Money převáděné roky uzavřelo, převod je ale naveze otevřené. Průvodce je pak
uzavře průvodcem uzávěrkou MyÚčta od nejstaršího roku, a jen tehdy, když konečné
stavy roku sedí účet po účtu na počáteční stavy dalšího roku z Money včetně
výsledku hospodaření na 431. Při rozdílu rok zůstane otevřený a pozdější roky
také.

Kroky, které proběhly v Money (odpisy, dohadné položky, časové rozlišení,
rezervy, daň z příjmů), se přeskočí s poznámkou, protože jejich zápisy jsou
v deníku. Kurzové přecenění a zásoby se nepřeskakují: má-li rok co přeceňovat,
uzavřete ho ručně v Uzávěrce (kapitola [Uzávěrka](87_Uzaverka.md)).

Otevření dalšího roku převzaté počáteční stavy z Money ponechá a nic nového
nezaúčtuje, takže se počáteční stavy nezdvojí. Poslední převedený rok zůstává
otevřený. Rok, jehož knihy se uzavřely, ale další rok se otevřít nepodařilo,
opakovaný převod dotáhne.

## 83a.6 Režim účetnictví a automatika

Převod zapíše podvojné účetnictví od začátku prvního převáděného období do
nastavení firmy i do historie režimů. Automatika účtování je během převodu
vypnutá: deník přichází hotový a každý automatický zápis nad týmiž doklady by
byl duplicita.

Po úspěšném převodu se automatika vrátí do stavu před převodem. Firma, která
podvojné účetnictví zapíná právě převodem, dostane výchozí nastavení účetní
jednotky jako po aktivaci. Skončí-li převod chybou, automatika zůstane vypnutá,
dokud převod nedoběhne bez chyb. Stav před převodem se ukládá ještě před
vypnutím automatiky, takže ani převod přerušený pádem ji nenechá vypnutou
natrvalo: další úspěšný běh ji vrátí na stav před prvním převodem.

Záznam daňové evidence, který v historii režimů firmy leží uvnitř převáděných
let, převod odstraní, protože v Money byly tyto roky podvojné.

## 83a.7 Opakovaný převod

Převod si pamatuje, co z které agendy už vzniklo. Opakovaný převod téže nebo
novější zálohy založí jen to, co ještě chybí, a nic nezdvojí. Převod přerušený
chybou tak stačí po opravě spustit znovu. Do uzavřeného roku už převod nic
nepřidá.

Už převedené doklady ani zápisy deníku opakovaný převod nepřepisuje, protože
mohly být mezitím zaúčtované, spárované nebo upravené v MyÚčtu. Změnila-li se
v Money celková částka faktury, protokol ji vypíše jako změněnou v Money
a ponechanou v MyÚčtu; upravte ji ručně.

## 83a.8 Omezení

- Formát dat Money není veřejně dokumentovaný. Čtení je ověřené na verzi
  Money S3 26.600; u jiné verze průvodce upozorní a výsledek je o to důležitější
  porovnat se sestavami z Money.
- Hospodářský rok odlišný od kalendářního převod nepodporuje, kontrola před
  převodem ho zastaví.
- Převod čte jen zálohu agendy, nikdy živou instalaci Money. Z archivu rozbalí
  jen datové soubory agendy s omezeným počtem i velikostí.
- Nahraná záloha zůstává na serveru pro další běh. Po úspěšném ostrém převodu
  se smaže, jinak ji denní úklid smaže po týdnu bez práce s ní.
