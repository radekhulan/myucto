# 96. Daňové konstanty

**Cesta: `Systém → Daňové konstanty`**

Daňové konstanty jsou samostatná celosystémová stránka umístěná v menu hned
pod položkou **Sazby a číselníky**. Nejde o nastavení aktuální firmy ani o
záložku číselníků.

## 96.1 Rok a efektivní hodnoty

Hodnoty jsou verzované po jednotlivých letech. Aplikace pro každý výpočet
vybere efektivní sadu odpovídající danému roku, aby pozdější změna sazeb
nepřepsala pravidla staršího období.

Konstanty zahrnují zejména:

- sazby, pásma, slevy a limity daně z příjmů;
- sociální a zdravotní pojistné a parametry mezd;
- pásma a měsíční rozvrhy paušální daně;
- odpisové parametry;
- limity DPH a kontrolního hlášení;
- zákonné termíny podání.

Mzdové hodnoty na této stránce používá především základní **Mzdová
rekapitulace**. Zkušební agenda **Úplné mzdy** má oddělené, auditovatelné
legislativní sady v **Mzdy → Legislativní pravidla**; jejich stav a schvalování
popisuje kapitola [Legislativní pravidla mezd](75_Legislativni_pravidla_mezd.md).

## 96.2 Vlastní přepis

Vestavěné hodnoty lze pro konkrétní rok administrátorsky přepsat. Tlačítko
**Uložit** zapíše vlastní efektivní hodnoty; **Obnovit výchozí** vlastní přepis
odstraní a znovu použije hodnoty dodané aplikací. Obě operace se zapisují do
activity logu.

U rozvrhů, jejichž částka se může změnit během roku, se zadává počáteční měsíc
každého období. Typickým příkladem je paušální daň: další období přidá změnu
měsíční zálohy a roční částka se dopočítá jako součet měsíců, neupravuje se
ručně.

## 96.3 Dopad změn

> [!WARNING]
> Změna daňové konstanty ovlivňuje všechny firmy a všechny budoucí přepočty,
> které daný rok používají. Před uložením ověř celý formulář proti aktuálním
> právním hodnotám; změna není lokální výjimkou pro jediný doklad.

Uložené finální snapshoty daňových výpočtů se změnou konstant zpětně
nepřepisují. Nový náhled nebo nový výpočet však už použije aktuální efektivní
sadu. Souvislosti s orientačním porovnáním režimů OSVČ popisuje
[Daňový optimalizátor](41_Danovy_optimalizator.md).
