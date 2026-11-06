# 84. Roční zúčtování

> Návod, jak po skončení roku provést zaměstnancům roční zúčtování záloh na
> daň a daňového zvýhodnění a jak vrátit přeplatek. Pro mzdové účetní.

## 84.1 Kdy to potřebujete

Kapitolu otevřete, když:

- skončil rok a zaměstnanci vás žádají o roční zúčtování,
- měl zaměstnanec v roce i jiného zaměstnavatele a přinesl jeho potvrzení,
- chcete zúčtovat všechny žadatele najednou,
- se blíží březnová výplata a musíte vrátit přeplatky.

<!-- cols: 24 44 32 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| do 15. 2. | Přijmout žádosti a potvrzení od jiných plátců | `Mzdy → Roční zúčtování` |
| do 31. 3. | Provést roční zúčtování | `Mzdy → Roční zúčtování`, **Provést roční zúčtování** |
| se mzdou za březen | Vrátit přeplatek | mzdový vstup, `Mzdy → Mzdové složky a vstupy` |
| do 20. 3. (elektronicky) | Vyúčtování daně finančnímu úřadu | `Mzdy → Přehled mezd`, panel **Roční vyúčtování daně** |

## 84.2 Než začnete

1. **Mzdové oprávnění.** Provést zúčtování a zadávat potvrzení od jiných
   plátců smí jen uživatel s právem schvalovat mzdy.
2. **Schválené mzdy za celý rok** v `Mzdy → Mzdové běhy`.
3. **Prohlášení poplatníka a nároky na slevy** v zákonné evidenci osoby
   (viz [Zaměstnanci](86_Zamestnanci.md#86124-prohlaseni-k-dani-ma-jedine-misto)).
4. **Ověřená legislativní pravidla** pro zúčtovávaný rok
   ([Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md#9281-ktere-roky-jsou-pokryte)).
5. **Podklady od zaměstnance:** žádost, potvrzení od všech dalších
   zaměstnavatelů v roce a odpověď, zda podává daňové přiznání.

## 84.3 Krok za krokem: roční zúčtování jednoho zaměstnance

1. Otevřete `Mzdy → Roční zúčtování` a zvolte zdaňovací období.
2. Vlevo vyberte zaměstnance. Seznam zúžíte hledáním jména nebo stavem
   **Požádali, nezúčtováno**, **Bez zúčtování**, **Zúčtováno**.
3. Vpravo zapište žádost s **Datum žádosti** a zodpovězte všechny otázky
   (předchozí zaměstnavatelé, daňové přiznání, roční položky).
4. Měl-li zaměstnanec jiného zaměstnavatele, zapište jeho potvrzení
   ([§ 84.4](#844-krok-za-krokem-potvrzeni-od-jineho-platce-dane)).
5. Zkontrolujte výpočet podle § 38ch a případné nesplněné podmínky.
6. Klikněte na **Provést roční zúčtování**.
7. Je-li přeplatek vyšší než 50 Kč, založte jeho výplatu jako mzdový vstup
   ve mzdě za březen. Nedoplatek se nesráží.

**Jak poznáte, že je hotovo:** Zaměstnanec má stav **Zúčtováno** a mezi
ročními dokumenty je neměnný doklad **Roční zúčtování záloh**.

> [!WARNING]
> Provedené zúčtování nejde v aplikaci zrušit. Další pokus vrátí původní
> výsledek. Podklady zkontrolujte před spuštěním. Zjistíte-li chybu,
> vypořádejte ji mimo aplikaci a rozdíl doložte.

## 84.4 Krok za krokem: potvrzení od jiného plátce daně

1. U zaměstnance otevřete sekci **Potvrzení od jiného plátce daně**.
2. Opište údaje z tiskopisu *Potvrzení o zdanitelných příjmech ze závislé
   činnosti* (25 5460, vzor č. 33) podle tabulky v
   [§ 84.7.3](#8473-potvrzeni-od-jineho-platce-dane). Pole, které na
   potvrzení je s nulou, vyplňte nulou; prázdné nechte jen to, co na
   potvrzení chybí.
3. Potvrzení označte jako **Doložené**.

**Jak poznáte, že je hotovo:** U potvrzení nesvítí chybějící údaje a výpočet
ukazuje úhrn rozepsaný na tohoto a předchozí zaměstnavatele.

## 84.5 Krok za krokem: zúčtování všem žadatelům najednou

1. V `Mzdy → Roční zúčtování` zvolte rok.
2. Klikněte na **Provést zúčtování všem žadatelům** a potvrďte.
3. Počkejte na výsledek. Zúčtování běží na serveru, stránku můžete zavřít.
4. Projděte přeskočené: u každého je jméno a seznam toho, co chybí. Po
   doplnění je zúčtujte jednotlivě, nebo hromadné zúčtování spusťte znovu.

**Jak poznáte, že je hotovo:** Zpráva ukáže „Zúčtováno: N, přeskočeno: N,
selhalo: N“. Už zúčtované osoby se přeskočí.

### Hromadné nastavení stavu žádosti

Když potřebujete u mnoha lidí (typicky brigádníků) jen zapsat, že o roční
zúčtování nepožádali, nemusíte otevírat každého zvlášť.

1. V `Mzdy → Roční zúčtování` zvolte rok a případně zúžte seznam hledáním nebo stavem.
2. Zaškrtněte zaměstnance, nebo použijte **Vybrat vše na stránce**. Výběr zůstane
   zachovaný i při listování na další stránky.
3. Klikněte na **Nastavit: nepožádal** (nebo **Nastavit: požádal**) a potvrďte.

Změní se jen stav žádosti. Lidem, kteří žádost zatím nemají, se založí s ostatními
údaji ve stavu „Neznámé“, u ostatních zůstává datum, doklad i další evidence beze
změny. Stav „požádal“ nastavený hromadně nemá datum žádosti, takže zúčtování takové
osoby neproběhne, dokud datum nedoplníte v jejím detailu. Hromadně lze zpracovat
nejvýše 1000 lidí najednou.

**Jak poznáte, že je hotovo:** Zpráva ukáže, kolik žádostí se založilo a kolik změnilo,
a seznam vlevo ukazuje nový stav.

## 84.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Provést roční zúčtování** je zašedlé | Některá podmínka § 38ch není splněná nebo zodpovězená | Přečtěte vypsané věty a doplňte, co chybí ([§ 84.7.2](#8472-kdy-se-zuctovani-provede)). |
| Zúčtování roku nejde spustit | Rok ještě neskončil | Počkejte do 1. ledna následujícího roku. |
| „U podané žádosti chybí datum podání. Bez něj nejde doložit, že žádost přišla do 15. února…“ | Žádost nemá datum | Doplňte **Datum žádosti**. |
| Zastaveno kvůli prohlášení | V některém měsíci trvání vztahu je prohlášení výslovně neověřené, nebo jde o nerezidenta | Opravte zákonnou evidenci osoby. |
| Zastaveno kvůli slevě na poplatníka | Prohlášení je podepsané, ale za rok není ani jeden měsíc nároku na základní slevu | Doplňte evidenci nároku a spusťte znovu. |
| Zastaveno kvůli potvrzení od jiného plátce | Potvrzení chybí, je nedoložené, má prázdné pole, nebo bylo doručeno po 15. únoru | Doplňte údaje nebo zúčtování proveďte mimo aplikaci. |
| Zastaveno kvůli ročním položkám | Žádost uplatňuje něco, co aplikace neeviduje, nebo je rozpor mezi žádostí a evidencí položek | Opravte volbu v žádosti nebo doplňte položky v sekci **Ročně uplatňované položky**. Jde-li o položku, kterou aplikace neumí, proveďte zúčtování mimo aplikaci, nebo ať zaměstnanec podá přiznání. |
| Zastaveno kvůli nedoložené položce | Některá roční položka nemá stav **Doloženo** | Doplňte doložení u položky, nebo ji odeberte. |
| Zastaveno kvůli slevě na manžela | Nejsou splněné podmínky (manžel mimo evidenci vyživovaných osob, vlastní příjem manžela nad 68 000 Kč, žádný označený měsíc) | Přečtěte vypsané důvody a opravte evidenci vyživovaných osob nebo údaje slevy. |
| Výpočet roku 2025 se zastaví | Výpočet potřebuje hodnotu, která pro rok 2025 není potvrzená | Viz [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md#9281-ktere-roky-jsou-pokryte). |

Časté chyby: zúčtování zaměstnance, který nesplňuje podmínky, pokus
o zúčtování roku, který neskončil, podepsané prohlášení bez evidovaného
nároku na slevu na poplatníka, chybějící příjem od jiného plátce, pravidla
jiného roku, nahrazení nepodporovaného odpočtu obecnou částkou, zadání
příspěvku zaměstnavatele místo částky k odpočtu z potvrzení fondu.

## 84.7 Podrobnosti a pravidla

### 84.7.1 Co roční zúčtování je

Roční zúčtování je právní úkon zaměstnavatele podle § 38ch zákona o daních
z příjmů, ne dopočet. Aplikace zpracuje podporované roční daňové údaje
a připraví kontrolovatelný výsledek. Blokovaný nebo nepodporovaný případ
dokončete mimo aplikaci; nevynucujte ho jiným polem. Roční odpočty, které
aplikace nepokrývá, zpracujte ručně nebo předejte daňovému specialistovi. Podklady uchovávejte podle [retenčních lhůt](93_Retencni_lhuty.md).

Zúčtovat lze i **rok 2025**: aplikace pro něj má ověřenou legislativní sadu,
takže použije slevy, zvýhodnění a sazby platné tehdy. Několik hodnot roku 2025
ale zůstává nepotvrzených a výpočet, který je potřebuje, se bezpečně zastaví.

Seznam vlevo se stránkuje na serveru. Zúžení hledá v celém roce, ne jen na
zobrazené straně, a dá se uložit jako pohled. Sloupce se tu nevybírají:
vlevo je výběr osoby a jediná tabulka na stránce je pevný výpočet podle
§ 38ch. Stránka po otevření nabízí uplynulé období.

### 84.7.2 Kdy se zúčtování provede

Aplikace zúčtování provede, jen když je zodpovězené všechno následující.
Nezodpovězená otázka má stejný účinek jako záporná odpověď.

- **Zdaňovací období skončilo.** Před 1. lednem následujícího roku je
  zúčtování zablokované, roční daň se nedá vyčíslit z neúplného roku. Po
  uplynutí lhůty pro provedení zúčtování se také nenabídne. Obě lhůty se
  posuzují po celých dnech, takže 31. březen je celý ještě včas.
- **Zaměstnanec o zúčtování požádal**, nejpozději 15. února po skončení
  zdaňovacího období. Povinné je datum žádosti, odkaz na podklad je
  volitelný.
- **Prohlášení poplatníka je na daný rok podepsané.** Neposuzuje se stav
  k 31. prosinci, ale měsíc po měsíci za dobu trvání vztahu. Výslovně
  neověřené prohlášení nebo nerezidence kdekoli v tomto rozsahu zúčtování
  zastaví; měsíc bez záznamu se přeskočí. U starších převzatých dat bez
  evidence vztahu se posuzuje celý rok.
- **Podepsané prohlášení má doložený nárok na slevu na poplatníka.** Bez
  jediného měsíce nároku na základní slevu by vyšla vyšší daň, než na jakou
  má zaměstnanec nárok.
- **Doklady od předchozích zaměstnavatelů** za tentýž rok jsou doložené,
  nebo zaměstnanec jiného zaměstnavatele neměl. Doručení později než
  15. února zúčtování zastaví.
- **Zaměstnanec nepodává daňové přiznání.** Kdo přiznání podá nebo ho podat
  musí, tomu zaměstnavatel zúčtování provést nesmí. Aplikace tuto povinnost
  neodvozuje (o většině skutečností neví), odpověď zadává mzdová účetní.
- **Ročně uplatňované položky jsou zodpovězené a doložené.** Dary, úroky
  z úvěru na bytovou potřebu, penzijní a životní pojištění, dlouhodobý
  investiční produkt, pojištění dlouhodobé péče, sleva na manžela, sleva za
  umístění dítěte a sleva za zastavenou exekuci se podle § 38h odst. 6
  uplatňují až v ročním zúčtování.
  V žádosti zvolte **Uplatňuje, evidováno níže** a položky zapište v sekci
  **Ročně uplatňované položky** ([§ 84.7.7](#8477-rocne-uplatnovane-polozky)).
  Volba **Uplatňuje něco, co aplikace neeviduje** zúčtování zastaví, aby
  nevyšel nižší přeplatek. Zastaví ho také rozpor mezi žádostí a evidencí
  položek, nedoložená položka a nesplněné podmínky slevy na manžela nebo
  slevy za umístění dítěte.

Nesplněné podmínky se vypisují všechny najednou jako věty a tlačítko
**Provést roční zúčtování** zůstává vidět zašedlé s vysvětlením.

Hromadné **Provést zúčtování všem žadatelům** zpracuje za zvolený rok
všechny, kdo požádali a mají v roce schválenou mzdu. Výpočet i doklad jsou
tytéž jako u jednotlivé osoby. Spustit ho smí jen uživatel s právem
schvalovat mzdy. Průběh se ukáže i po návratu na stránku.

### 84.7.3 Potvrzení od jiného plátce daně

Bez potvrzení od jiného zaměstnavatele zúčtování provést nejde. § 38ch
odst. 3 říká, že plátce zúčtování provede „jen na základě dokladů …
o zúčtované nebo vyplacené mzdě, sražených zálohách na daň z těchto příjmů,
poskytnuté měsíční slevě na dani podle § 35ba a 35c a vyplacených měsíčních
daňových bonusech“.

| Pole v aplikaci | Kde ho najdete na potvrzení |
|---|---|
| Úhrn zúčtovaných příjmů | ř. 1 |
| Základ daně | ř. 5 |
| Záloha na daň celkem | ř. 8 |
| Poskytnuté měsíční slevy podle § 35ba | dopočítá se z ř. 12 a z měsíců prohlášení v záhlaví |
| Poskytnuté měsíční slevy podle § 35c | dopočítá se z ř. 11 |
| Vyplacené měsíční daňové bonusy | ř. 9 |

Slevy tiskopis jako částku neuvádí. Nese je jako **měsíce nároku** (ř. 11
a 12 a údaj o prohlášení v záhlaví), protože záloha na ř. 8 je už po nich.
Aplikace si je nedomýšlí a žádá je zadat.

> [!WARNING]
> Prázdné pole není nula. Prázdné pole znamená „na potvrzení ten údaj není“
> a zúčtování zastaví; nula znamená „na potvrzení je nula“ a počítá se
> s ní. Kdyby se prázdné pole četlo jako nula, porovnal by se celoroční
> nárok na bonus s nižším úhrnem vyplacených bonusů a zaměstnanci by vyšel
> přeplatek, na který nemá nárok. U každého potvrzení je vidět, které údaje
> chybí.

Potvrzení vedené jako **Nedoložené** se do úhrnu nezapočítá: § 38ch odst. 4
mluví o úhrnu mezd od všech plátců a do něj patří doklad, ne nepodložený
údaj. Stav **Doložené** potvrzuje uživatel. Textový odkaz na podklad je
nepovinný; povinné je označení konkrétního potvrzení a jeho rozhodné částky.
Sekci smí zadávat jen ten, kdo smí zúčtování i provést, protože čísla jdou
přímo do úhrnu, ze kterého vychází přeplatek. Na výsledném dokladu je úhrn
rozepsaný na tohoto zaměstnavatele a na předchozí podle potvrzení.

### 84.7.4 Výpočet

Výpočet nic nepřepočítává znovu. Roční úhrny daně a záloh vznikají průběžně
při schválení každého mzdového běhu; roční zúčtování je sečte, porovná
s roční daní a rozdíl vyčíslí zvlášť na dani a zvlášť na daňovém bonusu.
Historické měsíce zůstávají nedotčené.

Výsledek i tisk (tiskopis MFin 5460) ukazují základ daně před odpočty,
jednotlivé nezdanitelné části, slevu za zastavenou exekuci a slevu na
manžela. Základ se zaokrouhlí na celá sta dolů až po odpočtu.

Základní sleva na poplatníka náleží za celé zdaňovací období v plné výši
i tomu, kdo pracoval jediný měsíc. Slevy na invaliditu, sleva na držitele
průkazu ZTP/P, sleva na studenta (od roku 2027) a daňové zvýhodnění na dítě
se krátí po dvanáctinách za
měsíce, na jejichž počátku byly podmínky splněné. Měsíce se berou z evidence
nároků, ne z toho, kolik se měsíčně skutečně uplatnilo: měsíční sleva je
omezená výší zálohy, takže z ní nárok zpětně vyčíst nejde.

### 84.7.5 Přeplatek, nedoplatek a doklad

Přeplatek se vrací mzdou, nejpozději při zúčtování mzdy za březen, a jen
když je vyšší než 50 Kč. Přeplatek do padesáti korun je jiný stav než žádný
přeplatek: zúčtování proběhlo, jen se nevyplácí. Nedoplatek se zaměstnanci
nesráží. Výplatu založte jako mzdový vstup ve složkách mzdy; aplikace ji
nevytváří sama.

Zúčtování se provádí jednou za rok. Opakované spuštění vrátí původní
výsledek. Doklad **Roční zúčtování záloh** je neměnný, najdete ho i mezi
ročními dokumenty a váže se na konkrétní schválené mzdové revize.

Vyplacený doplatek na daňovém bonusu se firmě nevrátí sám. Pokud vyplacené
bonusy převýšily sražené zálohy, požádejte o rozdíl finanční úřad, viz
[Žádost o poukázání chybějící částky na daňovém bonusu](85_Podani_a_hlaseni.md#851424-zadost-o-poukazani-chybejici-castky-na-danovem-bonusu).

### 84.7.6 Vyúčtování daně finančnímu úřadu

Roční zúčtování je vztah mezi zaměstnavatelem a zaměstnancem. Vyúčtování
daně z příjmů ze závislé činnosti (§ 38j odst. 4 a 5) a vyúčtování srážkové
daně jsou samostatná podání finančnímu úřadu. Aplikace je sama neodešle:
připraví XML pro EPO na `Mzdy → Přehled mezd`, panelu **Roční vyúčtování
daně**, a podáte je přes EPO. Postup je v
[§ 85.14.23](85_Podani_a_hlaseni.md#851423-vyuctovani-zalohove-a-srazkove-dane).

### 84.7.7 Ročně uplatňované položky

Položky podle § 38h odst. 6 zapište na detailu zaměstnance v ročním
zúčtování v sekci **Ročně uplatňované položky**. Sekce se ukládá vlastním
tlačítkem, nezávisle na žádosti. V žádosti pak zvolte **Uplatňuje,
evidováno níže**. U každé položky vedete protistranu, doložení (stav
a čím je doloženo) a poznámku. Nedoložená položka zúčtování zastaví.

| Druh | Co zadat | Co aplikace uplatní |
|---|---|---|
| Dar | Hodnota daru | Jen když úhrn darů přesáhne 2 % základu daně nebo činí aspoň 1 000 Kč, nejvýše 15 % základu. |
| Odběr krve | Počet odběrů | 3 000 Kč za odběr. |
| Odběr orgánu od žijícího dárce | Počet odběrů | 20 000 Kč. |
| Odběr krvetvorných buněk | Počet odběrů | 20 000 Kč za odběr. |
| Úroky z úvěru na bytovou potřebu | Zaplacené úroky, měsíce placení, volba bytové potřeby obstarané před 1. 1. 2021 | Limit 150 000 Kč na domácnost, za každý měsíc placení 1/12. U bytové potřeby obstarané před 2021 limit 300 000 Kč; při souběhu nový úvěr nejvýše 150 000 Kč a oba dohromady 300 000 Kč. |
| Penzijní připojištění, doplňkové penzijní spoření, penzijní pojištění | Částka k odpočtu z potvrzení fondu | Příspěvky zaměstnance nad hranicí nejvyššího státního příspěvku, nikdy příspěvek zaměstnavatele. |
| Soukromé životní pojištění | Zaplacené pojistné | Společný limit 48 000 Kč. |
| Dlouhodobý investiční produkt (DIP) | Zaplacená částka | Společný limit 48 000 Kč. |
| Pojištění dlouhodobé péče | Zaplacené pojistné | Společný limit 48 000 Kč. |
| Sleva za zastavenou exekuci (§ 35 odst. 4) | Výše náhrady přiznané exekutorem | Snižuje daň před ostatními slevami, nejvýše do výše daně. |

Měsíce placení úroků se sčítají přes všechny úvěry, takže refinancování
v polovině roku znamená dohromady 12 měsíců. Penzijní produkty, životní
pojištění, DIP a pojištění dlouhodobé péče mají jeden společný limit
48 000 Kč. Úhrn odpočtů je nejvýše do výše základu daně.

**Sleva na manžela nebo manželku.** Manžela i dítě vyberte z evidence
vyživovaných osob zaměstnance ([Zaměstnanci](86_Zamestnanci.md)); manžel
musí mít vztah **manžel/manželka**. Dále označte:

- měsíce, na jejichž počátku byly podmínky splněné (společná domácnost
  s vyživovaným dítětem do 3 let),
- měsíce ZTP/P manžela, za které náleží dvojnásobek, a to jen má-li manžel
  v evidenci ZTP/P,
- vlastní příjem manžela za rok (limit 68 000 Kč),
- doložení.

Sleva je 24 840 Kč ročně, za každý označený měsíc 1/12. Dítě, které dovrší
tři roky v průběhu měsíce, zakládá nárok ještě za tento měsíc. Roční JMHZ
vykazuje slevu na manžela s manželem, dítětem, počtem měsíců a ZTP/P.

**Sleva za umístění dítěte (školkovné).** Uplatňuje se za zdaňovací období
2027 a další (§ 35bd ZDP), jen ročně: k zálohám se nepřihlíží. Pro každé
dítě přidejte jeden řádek a vyplňte:

- dítě z evidence vyživovaných osob; vnuk jen tehdy, je-li v péči
  nahrazující péči rodičů (zaškrtněte to u něj),
- druh zařízení: mateřská škola, dětská skupina, nebo péče o dítě
  provozovaná na živnostenské oprávnění (i obdobné zařízení v zahraničí),
- výdaje za rok podle potvrzení zařízení, bez stravného,
- počet domácností, ve kterých dítě žije, a u sdílené péče případně strop
  z písemné dohody rodičů s odkazem na tuto dohodu,
- vyživuje-li totéž dítě v roce i další poplatník (druhý rodič v jiné
  domácnosti, manžel v téže domácnosti), jeho potvrzení: jméno, výši jím
  uplatněné slevy (i 0 Kč) a odkaz na potvrzení; je-li zaměstnaný a dělá mu
  roční zúčtování zaměstnavatel, potvrzení vydává zaměstnavatel,
- doložení potvrzením zařízení.

Sleva se rovná skutečně zaplaceným výdajům, nejvýše minimální mzdě
platné k 1. lednu roku (pro rok 2027 je to 24 900 Kč) za každé dítě. Žije-li
dítě ve více domácnostech, je strop podíl minimální mzdy a počtu domácností
v celých korunách dolů, při střídavé péči ve dvou domácnostech 12 450 Kč;
písemná dohoda rodičů může strop rozdělit jinak. Je to sleva na dani,
ne odpočet: snižuje daň spolu se slevou na poplatníka a ostatními slevami,
nejvýše do výše daně, a uplatní se před daňovým zvýhodněním na děti. Může
tak zvýšit daňový bonus.

Zúčtování zastaví nedoložená sleva a nesplněné podmínky: dítě v roce
se zaměstnancem nežilo, slevu na totéž dítě v téže domácnosti uplatňuje
jiná osoba, výdaje jsou uplatněné jako výdaj na dosažení příjmů,
dohodnutý strop je vyšší než minimální mzda, chybí písemná dohoda nebo
potvrzení dalšího poplatníka, nebo sleva zaměstnance spolu se slevou
potvrzenou dalším poplatníkem překročí minimální mzdu. Které to jsou,
vypíše výsledek u překážky.

## 84.8 Související kapitoly

- [Zaměstnanci](86_Zamestnanci.md): prohlášení k dani a nároky na slevy.
- [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md): účinná
  pravidla roku.
- [Mzdové běhy](80_Mzdove_behy.md): výplata přeplatku.
- [Dokumenty a výstupy](83_Dokumenty_a_vystupy.md): roční dokumenty.
- [Podání a hlášení](85_Podani_a_hlaseni.md): vyúčtování daně a žádost
  o poukázání bonusu.
