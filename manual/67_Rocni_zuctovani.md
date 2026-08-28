# Roční zúčtování

## 67.1 Účel

Roční zúčtování zpracuje podporované roční daňové údaje zaměstnance a připraví kontrolovatelný výsledek pro navazující mzdu a dokumenty.

## 67.2 Předpoklady a oprávnění

Uživatel potřebuje mzdové oprávnění a úplné podklady za rok. Ověřte žádost zaměstnance, prohlášení, příjmy a zálohy od všech relevantních plátců a podporu konkrétních slev či odpočtů v aplikaci.

## 67.3 Krokový postup

1. Otevřete **Mzdy → Roční zúčtování** a zvolte rok a zaměstnance.
2. Zkontrolujte měsíční údaje a vložte doložené externí podklady.
3. Vyplňte pouze slevy a odpočty, které obrazovka skutečně podporuje.
4. Spusťte výpočet, zkontrolujte souhrn a případná omezení.
5. Výsledek promítněte do určeného běhu a vytvořte navazující dokument.

## 67.4 Stavy

Rozpracované zúčtování je měnitelné, vypočtené čeká na kontrolu a dokončené je podkladem pro vypořádání. Blokovaný či nepodporovaný případ dokončete mimo aplikaci; nevynucujte jej jiným polem.

## 67.5 Kontroly a bezpečnost

Ověřte úplnost roku, souběhy plátců, podepsané dokumenty a platná pravidla pro daný rok. Některé roční odpočty aplikace nepokrývá; tyto případy zpracujte ručně nebo předejte daňovému specialistovi. Podklady uchovávejte podle retenčních pravidel.

## 67.6 Časté chyby

- Zúčtování zaměstnance, který nesplňuje podmínky.
- Pokus o zúčtování roku, který ještě neskončil.
- Podepsané prohlášení bez evidovaného nároku na slevu na poplatníka.
- Chybějící příjem od jiného plátce.
- Použití pravidel jiného roku.
- Nahrazení nepodporovaného odpočtu obecnou částkou.

## 67.7 Návaznosti

Osobní a vztahové údaje jsou v [kapitole 58k](69_Zamestnanci.md), účinná pravidla v [58q](75_Legislativni_pravidla_mezd.md), promítnutí výsledku v [mzdovém běhu](63_Mzdove_behy.md) a doklad v [58h](66_Dokumenty_a_vystupy.md).



## 67.8 Podrobný pracovní postup a kontroly

V **Mzdy → Roční zúčtování** zvol zdaňovací období. Vlevo je seznam zaměstnanců
se stavem žádosti a výsledkem, vpravo evidence podkladů a výpočet vybrané osoby.
Rok, který ještě neskončil, se nezúčtovává — stránka proto po otevření nabízí
uplynulé období.

Zúčtovat lze i **rok 2025**: aplikace pro něj má ověřenou legislativní sadu,
takže se použijí slevy, zvýhodnění a sazby platné tehdy, ne dnešní. Několik
hodnot roku 2025 ale zůstává nepotvrzených, a výpočet, který je potřebuje, se
proto bezpečně zastaví — viz
[Legislativní pravidla mezd](75_Legislativni_pravidla_mezd.md#7581-ktere-roky-jsou-pokryte).

Seznam se stránkuje na serveru a jde zúžit hledáním jména nebo stavem
(**Požádali, nezúčtováno** / **Bez zúčtování** / **Zúčtováno**). Zúžení hledá
v celém roce, ne jen na zobrazené straně, a dá se uložit jako pohled. Sloupce
se tu nevybírají: vlevo je výběr osoby, ne datová tabulka, a jediná tabulka na
stránce je pevný výpočet podle § 38ch.

Zúčtování je právní úkon zaměstnavatele podle § 38ch zákona o daních z příjmů,
ne dopočet. Aplikace ho proto provede jen tehdy, když je zodpovězené všechno
následující. Nezodpovězená otázka má stejný účinek jako záporná odpověď: dokud
platí „nevíme", zúčtování se neprovádí.

- **Zdaňovací období už skončilo.** Před 1. lednem následujícího roku je
  zúčtování zablokované; roční daň se nedá vyčíslit z neúplného roku. Blokace
  je symetrická k opačné hraně — po uplynutí lhůty pro provedení zúčtování se
  nabídne také. Obě lhůty se posuzují po celých dnech, takže 31. březen je celý
  ještě včas.
- **Zaměstnanec o zúčtování požádal**, a to nejpozději 15. února po skončení
  zdaňovacího období. Povinné je datum žádosti; odkaz na podklad je volitelný.
- **Prohlášení poplatníka je u vás na daný rok podepsané.** Neposuzuje se stav
  k 31. prosinci, ale **měsíc po měsíci za dobu trvání pracovního vztahu**.
  Výslovně neověřené prohlášení nebo nerezidence kdekoli v tomto rozsahu
  zúčtování zastaví; měsíc, ke kterému není žádný záznam, se přeskočí. U
  starších převzatých dat bez evidence vztahu se posuzuje celý rok.
- **Podepsané prohlášení má doložený nárok na slevu na poplatníka.** Je-li
  prohlášení podepsané, ale za celý rok není ani jeden měsíc nároku na základní
  slevu, zúčtování se zastaví. Dřív by se v takovém případě spočítala vyšší daň,
  než na jakou má zaměstnanec nárok; doplňte evidenci nároku a spusťte znovu.
- **Doklady od předchozích zaměstnavatelů** za tentýž rok jsou doložené,
  nebo zaměstnanec jiného zaměstnavatele neměl. Pozdější doručení než
  15. února zúčtování zastaví. Samotné údaje z těch potvrzení se zadávají
  v sekci níž a musí být úplné.
- **Zaměstnanec nepodává daňové přiznání.** Kdo přiznání podá nebo je povinen
  ho podat, tomu zaměstnavatel roční zúčtování provést nesmí. Aplikace tuhle
  povinnost neodvozuje — o většině rozhodných skutečností nic neví, a odpověď
  proto zadává mzdová účetní.
- **Zaměstnanec neuplatňuje položky, které jdou jen ročně.** Dary, úroky
  z úvěru na bytovou potřebu, penzijní a životní pojištění, dlouhodobý investiční
  produkt, pojištění dlouhodobé péče, sleva na manžela a sleva za zastavenou
  exekuci se podle § 38h odst. 6 uplatňují až v ročním zúčtování. Aplikace pro ně
  zatím nemá evidenci nároku ani doložení, takže je neumí spočítat — a raději
  zúčtování odmítne, než aby vydala nižší přeplatek, než na jaký má zaměstnanec
  nárok. Takové zúčtování je potřeba provést mimo aplikaci, nebo si zaměstnanec
  podá přiznání sám.

Nesplněné podmínky se vypisují všechny najednou jako věty, ne jako kódy, a
tlačítko **Provést roční zúčtování** zůstává vidět zašedlé i s vysvětlením.

### 67.8.1 Potvrzení od jiného plátce daně

Měl-li zaměstnanec v roce ještě jiného zaměstnavatele, zapiš jeho potvrzení
v sekci **Potvrzení od jiného plátce daně**. Údaje odpovídají tiskopisu
*Potvrzení o zdanitelných příjmech ze závislé činnosti* (25 5460, vzor č. 33)
a zúčtování je bez nich provést nelze — § 38ch odst. 3 říká, že plátce zúčtování
provede „jen na základě dokladů … o zúčtované nebo vyplacené mzdě, sražených
zálohách na daň z těchto příjmů, poskytnuté měsíční slevě na dani podle § 35ba
a 35c a vyplacených měsíčních daňových bonusech".

| Pole v aplikaci | Kde ho najdeš na potvrzení |
|---|---|
| Úhrn zúčtovaných příjmů | ř. 1 |
| Základ daně | ř. 5 |
| Záloha na daň celkem | ř. 8 |
| Poskytnuté měsíční slevy podle § 35ba | dopočítá se z ř. 12 a z měsíců prohlášení v záhlaví |
| Poskytnuté měsíční slevy podle § 35c | dopočítá se z ř. 11 |
| Vyplacené měsíční daňové bonusy | ř. 9 |

Slevy tiskopis jako částku neuvádí — nese je jako **měsíce nároku** (ř. 11 a 12
a údaj o prohlášení v záhlaví), protože záloha na ř. 8 je už po nich. Aplikace
si je proto nedomýšlí a žádá je zadat.

> [!IMPORTANT]
> **Prázdné pole není nula.** Prázdné pole znamená „na potvrzení ten údaj není"
> a zúčtování zastaví; nula znamená „na potvrzení je nula" a počítá se s ní.
> Kdyby se prázdné pole četlo jako nula, porovnal by se celoroční nárok na bonus
> proti nižšímu úhrnu už vyplacených bonusů a zaměstnanci by vyšel přeplatek,
> na který nemá nárok. U každého potvrzení je proto vidět, které údaje na něm
> chybí.

Potvrzení, které je vedené jako **nedoložené**, se do úhrnu nezapočítá — § 38ch
odst. 4 mluví o úhrnu mezd od všech plátců a do toho úhrnu patří doklad, ne
nepodložený údaj. Sekci smí zadávat jen ten, kdo smí zúčtování i provést: ta
čísla jdou přímo do úhrnu, ze kterého vychází přeplatek.

Stav **Doložené** potvrzuje uživatel. Textový odkaz na podklad je nepovinná
dohledávka a jeho nevyplnění samo o sobě zúčtování nezastaví; povinné zůstává
označení konkrétního potvrzení a jeho rozhodné částky.

Na výsledném dokladu je úhrn rozepsaný — kolik základu a záloh je od tohoto
zaměstnavatele a kolik podle potvrzení od předchozích.

Výpočet nic nepřepočítává znovu. Roční úhrny daně a záloh vznikají průběžně při
schválení každého mzdového běhu; roční zúčtování je jen sečte, porovná s roční
daní a rozdíl vyčíslí zvlášť na dani a zvlášť na daňovém bonusu. Historické
měsíce zůstávají nedotčené.

Základní sleva na poplatníka náleží za celé zdaňovací období v plné výši i tomu,
kdo pracoval jediný měsíc. Slevy na invaliditu, sleva na držitele průkazu ZTP/P
a daňové zvýhodnění na dítě se naopak krátí po dvanáctinách za měsíce, na
jejichž počátku byly podmínky splněné. Měsíce se berou z evidence nároků, ne
z toho, kolik se skutečně měsíčně uplatnilo — měsíční sleva je omezená výší
zálohy, takže z ní nárok zpětně vyčíst nejde.

Přeplatek se vrací mzdou, nejpozději při zúčtování mzdy za březen, a jen když
je vyšší než 50 Kč. Přeplatek do padesátikoruny je jiný stav než žádný
přeplatek: zúčtování proběhlo, jen se nevyplácí. **Případný nedoplatek se
zaměstnanci nesráží.** Samotnou výplatu založ jako mzdový vstup ve složkách
mzdy — aplikace ji nevytváří sama.

Zúčtování se provádí jednou za rok. Opakované spuštění nevytvoří druhý výsledek;
vrátí ten původní. Výsledkem je neměnný doklad **Roční zúčtování záloh**, který
najdeš i mezi ročními dokumenty a který se váže na konkrétní schválené mzdové
revize, ze kterých vznikl.

> [!IMPORTANT]
> **Provedené zúčtování nelze v aplikaci zrušit.** Jakmile jednou proběhne,
> další pokus vrátí původní výsledek a jinou částku už z něj nedostanete.
> Podklady proto zkontrolujte před spuštěním, ne po něm. Zjistíte-li chybu,
> vypořádejte ji mimo aplikaci a rozdíl doložte.

> [!WARNING]
> Vyúčtování daně z příjmů ze závislé činnosti vůči finančnímu úřadu
> (§ 38j odst. 4 a 5) aplikace nepodává. Roční zúčtování je vztah mezi
> zaměstnavatelem a zaměstnancem; vyúčtování je samostatné podání a je potřeba
> ho odevzdat mimo aplikaci.
