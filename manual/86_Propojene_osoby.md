# 86. Spojené osoby

**Cesta: `Nástroje → Spojené osoby`**

Stránka soustřeďuje zdanitelná plnění se spojenými osobami, měřitelné
odchylky prodejních cen a ručně evidované úpravy základu daně podle
§ 23 odst. 7 zákona o daních z příjmů.

## 86.1 Označení spojené osoby

Systém právní nebo faktický vztah z dokladů neodvozuje. Partner musí být v
kartě kontaktu označen jako **Spojená osoba** a lze u něj uvést typ vztahu:
kapitálově spojená, jinak spojená, blízká osoba nebo pracovněprávní vztah,
včetně poznámky s doložením vazby.

Stránka je dostupná uživatelům s právem číst sestavy. Založení nebo smazání
úpravy základu daně vyžaduje právo dokončovat daňové sestavy.

## 86.2 Soupis transakcí

Po výběru roku se načtou vydané i přijaté doklady partnerů označených jako
spojené:

- vydané doklady typu faktura, dobropis nebo daňový doklad, které nejsou
  konceptem ani stornem, podle data zdanitelného plnění,
- přijaté faktury, účtenky, dobropisy a daňové doklady, které nejsou
  konceptem ani stornem, podle efektivního data nákladu.

Proformy a přijaté zálohové doklady nejsou zdanitelným plněním a do tohoto
soupisu nevstupují. Tabulka ukazuje směr, partnera, typ vztahu, doklad, datum
a částku bez DPH. Celkem je prostý součet vydaných i přijatých částek; nejde
o jejich vzájemné netto. Doklad vede na svůj detail.

## 86.3 Měřitelné cenové odchylky

Obecnou cenu obvyklou systém nezná. Umí porovnat jen vydanou položku spojené
osobě s vlastními prodeji stejné položky nespojeným osobám ve zvoleném roce.

Srovnání používá tato pravidla:

1. popis položky se normalizuje bez ohledu na velikost písmen a vícenásobné
   mezery,
2. jednotková cena bez DPH i množství musí být kladné a množství musí být
   větší než 1,
3. jednotka musí být z podporované množiny kusových, časových, hmotnostních,
   délkových, plošných, objemových nebo litrových jednotek,
4. musí existovat alespoň dva srovnatelné prodeje nespojeným osobám,
5. referenční cena je **medián**, nikoli průměr,
6. odchylka se zobrazí až od absolutní hodnoty 20 %.

Vzorec je:

`odchylka % = (cena spojené osobě − medián nespojených) / medián × 100`

Medián omezuje vliv jednorázového výprodeje. Položka s množstvím 1 nebo bez
jednotky se nepovažuje za spolehlivě srovnatelnou, protože často představuje
měsíční paušál či celý souhrn práce.

Když srovnatelný vzorek chybí, systém odchylku netvrdí; transakce zůstane v
soupisu a cenu obvyklou musí doložit účetní například benchmarkem, posudkem
nebo obchodním důvodem. Výsledek je upozornění, nikoli automatická změna DPH
nebo účetnictví.

## 86.4 Úpravy základu daně

Tlačítko **Přidat úpravu** eviduje pro fiskální rok:

- směr **zvýšení** nebo **snížení**,
- kladnou částku,
- povinný důvod,
- volitelně konkrétního partnera (API jej podporuje, formulář stránky jej
  nyní běžně nepředává).

Souhrn počítá:

`Čistá úprava = Σ zvýšení − Σ snížení`

Úprava se sama neodvozuje z procentní odchylky. Podle § 23 odst. 7 záleží i
na tom, zda je rozdíl uspokojivě doložen, což účetní data sama nerozhodnou.
Záznam nic nezaúčtuje; stává se podkladem návrhu přiznání k dani z příjmů
právnických osob, kde se předává seznam, zvýšení, snížení, čistá delta,
objem transakcí a odchylky.

## 86.5 Návazné kontroly

Měsíční kontrola vždy informativně vypíše počet transakcí se spojenými
osobami. Zároveň varuje, pokud existuje alespoň jedna měřitelná cenová
odchylka. U účetní jednotky s auditorským rozsahem je samostatné zveřejnění
transakcí se spřízněnými stranami také jednou z ručně doplňovaných částí
přílohy účetní závěrky.

> ⚠️ **Výstup je podklad, nikoli automatické daňové posouzení.** Příznak
> partnera, srovnání cen i ruční úpravu musí před podáním posoudit účetní nebo
> daňový poradce. Systém nezná nárok protistrany na odpočet DPH, tržní
> okolnosti ani úplnou právní definici vztahu.
