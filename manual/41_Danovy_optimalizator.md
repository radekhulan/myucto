# 41. Daňový optimalizátor (OSVČ)

> [!WARNING]
> Jde o orientační scénářový model, ne o výpočet přiznání. Používá zjednodušený
> klientský výpočet a nepokrývá všechny vstupy, kontroly ani zaokrouhlení finálního
> DPFO. Pro podání použij [Daň z příjmů](38_Dan_z_prijmu.md).

**Cesta:** `Daně → Daňový optimalizátor`. Stránka je dostupná fyzické osobě/OSVČ.

## Zdroj příjmů

Pro minulý rok se standardně používají zaplacené vydané faktury s datem úhrady v roce,
po přepočtu kurzem dokladu. U plátce se bere částka bez DPH, u neplátce brutto;
doklady označené jako příjem mimo základ se zobrazí odděleně. Pokud se optimalizátor
spustí nad daňovou evidencí s volbou evidence, může místo toho použít daňové příjmy
a výdaje peněžního deníku.

Pro běžící rok se sčítají měsíční příjmy a dosavadní tempo se lineárně promítne do
konce roku. Jde o odhad, nikoli časové rozlišení smluv, sezónnosti či budoucích plateb.
U daňové evidence rozhoduje inkaso, takže vystavení prosincové faktury samo neurčuje,
do kterého roku příjem vstoupí.

## Porovnávané scénáře

Retrospektiva porovná:

- paušální daň ve zvoleném pásmu,
- standardní režim s jednou zvolenou sazbou výdajového paušálu nebo ručně zadanými
  skutečnými výdaji,
- orientační daň, sociální a zdravotní pojistné a čistý příjem.

Predikce sleduje roční limity z daňových konstant: pásmo paušální daně, hranice
registrace DPH a u vedlejší činnosti rozhodnou částku sociálního pojištění. Limity
se mohou v průběhu času měnit a některé se posuzují odlišně od prostého ročního
součtu; upozornění je proto signálem ke kontrole, nikoli právním rozhodnutím.

### Změna měsíční zálohy uprostřed roku

Záloha paušální daně se může změnit i během roku (2026 klesá 1. pásmo od
1. července z 9 984 na 9 162 Kč). Karta paušálu proto ukazuje **zálohu po
obdobích**, ne průměr, a roční částka je jejich součtem (2026 v 1. pásmu
114 876 Kč). Při snížení sazby navíc přibude upozornění s **přeplatkem** za
měsíce zaplacené vyšší zálohou a s částkou, na kterou lze **snížit nejbližší
zálohu** — alternativou je požádat si o vrácení až po skončení roku.

Rozvrh záloh je editovatelný v
[Systém → Daňové konstanty](75_Danove_konstanty.md)
(sekce *Paušální daň*): tlačítkem **přidat změnu sazby** vznikne další období od
zvoleného měsíce. Roční částka se z rozvrhu dopočítá, needituje se.

## Profil a co skutečně modeluje

Profil se ukládá po jednotlivých letech. Obsahuje jednu hlavní sazbu činnosti,
volbu paušálních nebo skutečných výdajů, pásmo paušální daně, celoroční příznak
vedlejší činnosti, jednoduchý nárok na manžela/manželku a počet dětí, úroky z
bytové potřeby a vybrané penzijní či pojistné odpočty.

Optimalizátor nepracuje s podrobným seznamem činností, měsíci dětí a manžela/manželky,
pořadím a ZTP/P dětí, měsíčním průběhem hlavní/vedlejší činnosti, invaliditou, DIP,
dlouhodobou péčí, § 6, § 8 až § 10, ztrátami, zaplacenými zálohami ani přechodovými
úpravami. Neprovádí ani finální kontrolu důkazních podkladů.

Proto se může lišit od DPFO například takto:

- finální DPFO uplatní strop výdajového paušálu jednou pro všechny činnosti se stejnou
  sazbou; optimalizátor používá jedinou sazbu,
- DPFO krátí některé odpočty podle měsíců a provádí zákonná zaokrouhlení na stokoruny
  a celé koruny; optimalizátor pracuje průběžně s desetinnými částkami,
- finální pojistné respektuje podrobnější měsíční data a přesto má omezení popsaná
v kapitole [Pojistné OSVČ](38_Dan_z_prijmu.md#pojistne-osvc-dulezita-omezeni),
- paušální daň má i nečíselné podmínky vstupu, které samotné porovnání nákladů neověří.

## Jak výsledek používat

Rozpad příjem → výdaje → základ → daň a pojistné použij pro citlivostní analýzu.
Přepínej pouze skutečně možné varianty a porovnej výsledek s náhledem finálního DPFO.
Při blízkosti limitu ověř datum skutečné úhrady, strukturu činností a podmínky režimu
s účetní. Aplikace neslouží k doporučení účelového posouvání faktur nebo plateb.

## Daňové konstanty

Roční sazby, minima a limity spravuje admin v
[Systém → Daňové konstanty](75_Danove_konstanty.md).
Výchozí hodnoty jsou verzované podle roku, ale jejich existence
nepotvrzuje, že na konkrétní situaci dopadá obecné pravidlo bez výjimky. Změna konstanty
ovlivní budoucí přepočet; sama nemění již uložený finální snapshot DPFO.
