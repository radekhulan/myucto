# 112. Kreditní karty

> Návod, jak do MyÚčta načíst výpis ke kreditní kartě, zaúčtovat dluh vůči bance
> a doložit nákupy doklady. Pro účetní firem v podvojném účetnictví. Kreditní
> karta je v podstatě kontokorent: nákupy zvyšují dluh vůči bance, splátky ho snižují.
> Pohyby se účtují na **231 Krátkodobé úvěry** s analytikou pro každý úvěrový účet.

Kreditní karta se vede **jen tady**, ne mezi [Platebními kartami](31_Platebni_karty.md). Platební karty jsou karty k běžnému účtu: jejich platby jdou z bankovního výpisu běžného účtu a účtují se jako úhrada z běžného účtu, případně přes mezičlen platební karty. Nákup kreditkou jde z výpisu úvěrového účtu a účtuje se přes úvěrový účet, i když výpis nese koncovku karty. Platební kartu s koncovkou, kterou nesou jen výpisy kreditní karty, aplikace nezaloží a odkáže sem.

## 112.1 Kdy to potřebujete

- Banka vám poslala měsíční výpis ke kreditní kartě a potřebujete zaúčtovat nákupy, úroky, poplatky a splátku.
- Firma začala používat novou kreditní kartu.
- Zůstatek dluhu v účetnictví nesedí na výpis banky.
- U nákupů kartou chybí doklady a chcete je doložit.
- První načtený výpis začíná dluhem z doby před načtením výpisů.
- Výpis ČSOB ke kartě jste dřív načetli jako výpis běžného účtu.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Vybrat účty a režim nákupů | `Peníze → Kreditní karty`, záložka **Nastavení účtování** |
| první výpis nové karty | Načíst výpis, zkontrolovat úvěrový účet, zaúčtovat počáteční dluh | **Nahrát výpis**, detail úvěrového účtu |
| každý měsíc po výpisu | Načíst výpis, zpracovat pohyby, ověřit zůstatek | **Nahrát výpis**, **Zpracovat ve výpisu** |
| průběžně | Doložit nákupy bez dokladu | detail kreditní karty, sekce **Nákupy bez dokladu** |

## 112.2 Než začnete

1. **Podvojné účetnictví.** Výpisy kreditní karty jde načíst jen ve firmě v podvojném účetnictví (viz [§ 112.9.5](#11295-danova-evidence)).
2. **Korunový účet.** Kreditní účet v cizí měně aplikace odmítne. Všechny podporované banky vedou kreditní účty podnikatelů v korunách.
3. **PDF výpis** od Komerční banky, Raiffeisenbank, České spořitelny (Erste) nebo ČSOB (viz [§ 112.9.1](#11291-podporovane-banky-a-vypisy)).
4. **Oprávnění.** Přehled, detail a nastavení vidí uživatelé s přístupem k bance. Načtení výpisu vyžaduje oprávnění k importu bankovních výpisů. Nastavení účtování, režim nákupů, výběr analytiky 231 i mezičlenu, zaúčtování čekajících pohybů a počátečního dluhu a převod účtu na kreditní kartu vyžadují oprávnění zaúčtovat bankovní pohyby. Údaje úvěrového účtu upravují uživatelé s oprávněním ke správě bankovních účtů firmy.
5. **Nastavení účtování.** Výchozí režim nákupů a účty zkontrolujte na záložce **Nastavení účtování** (viz [§ 112.7](#1127-krok-za-krokem-nastaveni-uctovani-a-rezim-nakupu)).

## 112.3 Krok za krokem: načtení výpisu

1. Otevřete `Peníze → Kreditní karty` a klikněte na **Nahrát výpis**.
2. Vyberte PDF výpis ke kreditní kartě.
3. Aplikace výpis zkontroluje, uloží pohyby, založí úvěrový účet (pokud ho firma ještě nemá) a zaúčtuje, co umí.
4. Otevřete nový úvěrový účet, zkontrolujte název, limit, **Účet pro splátku**, **Kód banky** a **Variabilní symbol splátky**. Klikněte na **Uložit a potvrdit**.

**Jak poznáte, že je hotovo:** Výpis je v tabulce výpisů, úvěrový účet je ve stavu **Aktivní** (ne **Ke kontrole**) a v přehledu je u něj hlášení **Sedí na výpis**.

Co aplikace při načtení udělá:

- **zkontroluje výpis**: součet pohybů musí sedět na rozdíl konečného a počátečního zůstatku na haléř, jinak výpis odmítne a nic neuloží,
- **založí úvěrový účet**, pokud ho firma ještě nemá. Účet založený importem je označený **Ke kontrole**, dokud jeho údaje neuložíte v detailu,
- **převezme z výpisu** úvěrový limit a u Raiffeisenbank i účet a variabilní symbol pro splátku (jen do prázdných polí),
- **uloží pohyby** jako bankovní pohyby účtu. Stejný soubor ani pohyb z překrývajícího se výpisu se neuloží dvakrát,
- **zaúčtuje** pohyby, které umí (viz [§ 112.9.7](#11297-ucetni-zapisy)), ostatní jdou do fronty `Účetnictví → K doúčtování` stejně jako pohyby z běžného účtu.

> [!TIP]
> Výpis kreditní karty jde načíst i v sekci `Peníze → Bankovní účty` tlačítkem **Nahrát GPC/ABO nebo PDF** na záložce **Bankovní výpisy**: aplikace ho pozná a zpracuje stejně jako na stránce Kreditní karty. Výpis z e-mailové schránky se načte automaticky, pokud úvěrový účet už v aplikaci je. První výpis nového účtu nahrajte ručně.

## 112.4 Krok za krokem: měsíční odsouhlasení

1. Načtěte nový výpis (viz [§ 112.3](#1123-krok-za-krokem-nacteni-vypisu)).
2. Otevřete detail úvěrového účtu (kliknutím na řádek v přehledu).
3. Podívejte se do souhrnu **Co zbývá dořešit**. Kliknutím na **Otevřít ve výpisu** otevřete výpis na nejstarším nevyřešeném pohybu.
4. Ve výpisu zpracujte pohyby: spárujte nákup s dokladem, zaúčtujte MD/D, schvalte nebo odmítněte návrh automatiky. Pohyby se zpracovávají se všemi akcemi bankovního pohybu (viz [§ 112.9.2](#11292-zpracovani-pohybu-ve-vypisu)).
5. U nákupů bez dokladu pokračujte podle [§ 112.6](#1126-krok-za-krokem-nakupy-bez-dokladu).
6. V tabulce výpisů zkontrolujte sloupec **Účetnictví ke dni výpisu**: zeleně je zůstatek analytiky 231, který sedí na konečný zůstatek výpisu.

**Jak poznáte, že je hotovo:** Zůstatek 231 ke dni výpisu sedí na výpis, v režimu přes mezičlen zůstatek mezičlenu odpovídá nákupům bez dokladu a souhrn **Co zbývá dořešit** hlásí, že jsou všechny pohyby vyřešené. V přehledu je u účtu **Sedí na výpis**.

Pohyby mají v detailu stav: **Nezaúčtováno**, **Návrh ke schválení**, **Chybí doklad** (nákup leží na mezičlenu), **Vypořádáno**, **Zaúčtováno** nebo **Ignorováno**. Klik na pohyb otevře výpis rovnou na něm. U každého pohybu vidíte jeho druh: **Nákup**, **Vratka**, **Splátka**, **Úrok**, **Poplatek**, **Výběr hotovosti** nebo **Odměna**.

## 112.5 Krok za krokem: počáteční dluh z prvního výpisu

První načtený výpis většinou začíná dluhem z doby, kdy výpisy ještě v aplikaci nebyly.

1. Otevřete detail úvěrového účtu. Nahoře je oddíl **Počáteční dluh z prvního výpisu**, který ukáže počáteční zůstatek výpisu a kolik z něj v účetnictví chybí.
2. Zkontrolujte **Protiúčet** (výchozí z nastavení, obvykle 379) a **Datum zápisu** (den prvního pohybu výpisu). Zvolte datum v otevřeném období.
3. Klikněte na **Zaúčtovat počáteční dluh** a potvrďte.

**Jak poznáte, že je hotovo:** Zobrazí se hláška „Počáteční dluh zaúčtován“, oddíl zmizí a zůstatek 231 ke dni prvního výpisu sedí na výpis.

> [!WARNING]
> Počáteční dluh jde zaúčtovat jen jednou (po stornu zápisu ho detail nabídne znovu). Do uzavřeného nebo zamčeného období ho aplikace nezaúčtuje. Protiúčet smí být z tříd 3 a 4. Stav k začátku účetního roku patří do počátečních zůstatků (účet 701), ne sem.

Zápis je dluh MD protiúčet / D 231.x, přeplatek obráceně. Zaúčtuje se jen rozdíl proti tomu, co na analytice 231 už leží mimo pohyby výpisů. Dluh převzatý jinak (převodem zůstatků, ručním zápisem) se nezdvojí.

## 112.6 Krok za krokem: nákupy bez dokladu

Sekce **Nákupy bez dokladu** v detailu kreditní karty ukazuje nákupy úvěrového účtu zaúčtované na mezičlen, ke kterým zatím není přijatý doklad. Sekce se zobrazuje jen v podvojném účetnictví s režimem nákupů přes mezičlen.

**Doložit účtenkou nebo fakturou:**

1. V detailu kreditní karty přejděte na sekci **Nákupy bez dokladu**.
2. U nákupu klikněte na **Nahrát účtenku** a vyberte PDF nebo fotografii (do 32 MB).
3. S nastavenou AI se účtenka vytěží do konceptu přijatého dokladu s formou úhrady karta. Otevřete ho z oznámení, zkontrolujte a potvrďte.
4. Vraťte se do sekce a u nákupu klikněte na **Spárovat**.

**Uzavřít bez dokladu**, pokud doklad nebude (vyžaduje oprávnění zaúčtovat bankovní pohyby):

1. U nákupu klikněte na **Bez dokladu, nedaňově**, **Bez dokladu, daňově** nebo **K tíži držitele**.
2. V dialogu zkontrolujte datum, obchodníka a částku, případně vyberte jiný **Účet**.
3. Potvrďte.

Tlačítko **Výpis** otevře výpis kreditní karty rovnou na tomto pohybu.

**Jak poznáte, že je hotovo:** Nákup ze sekce zmizel. Spárovaný nákup má stav **Vypořádáno**, uzavřený nákup má zápis s číslem **KARTA-** a číslem pohybu.

> [!TIP]
> Výpisy kreditních karet často neuvádějí koncovku karty, takže doklad se k nákupu přiřadí až tlačítkem **Spárovat**. Dorazí-li k uzavřenému nákupu později doklad a spáruje se, uzavření se samo stornuje. Omylem uzavřený nákup vrátíte stornem zápisu KARTA-… v účetním deníku.

## 112.7 Krok za krokem: nastavení účtování a režim nákupů

1. Otevřete `Peníze → Kreditní karty`, záložku **Nastavení účtování**.
2. Vyberte **Výchozí režim nákupů** (**Přes mezičlen** nebo **Bez mezičlenu**, výchozí je bez mezičlenu, viz [§ 112.9.7.1](#112971-rezim-nakupu)).
3. Zkontrolujte účty (**Úroky z úvěru**, **Poplatky**, **Splátka bez protiúčtu**, **Výběr hotovosti kartou**, **Odměna a cashback**, **Nákup bez dokladu, daňový náklad**, **Nákup bez dokladu, nedaňový náklad**, **Soukromý nákup (k tíži držitele)**, **Protiúčet počátečního dluhu**).
4. Klikněte na **Uložit nastavení**.

Režim pro jednotlivý úvěrový účet změníte v jeho detailu v oddílu **Účtování nákupů** (**Režim nákupů kartou**, tlačítko **Uložit režim**).

**Jak poznáte, že je hotovo:** Zobrazí se hláška „Nastavení účtování uloženo“. Změna platí jen pro nové zápisy. Pohyby, které ještě nejsou zaúčtované, zaúčtujete tlačítkem **Zaúčtovat čekající pohyby** v detailu účtu (nabídne se i po změně režimu).

## 112.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Výpis se nenačte, hláška o součtu pohybů | Součet pohybů nesedí na rozdíl zůstatků | Stáhněte výpis z banky znovu. Neúplný výpis aplikace odmítne. |
| „Tenhle výpis už je načtený.“ | Stejný soubor jste nahráli podruhé | Nic neděláte. |
| Úvěrový účet nejde načíst | Účet eviduje jiná firma, do které nemáte přístup k importu banky, nebo je v cizí měně | Načtěte výpis ve správné firmě. Cizoměnový kreditní účet aplikace neumí. |
| Výpis se načetl do jiné firmy | Úvěrový účet eviduje jiná vaše firma | Výpis kreditní karty, kterou aktivní firma neeviduje, ale eviduje ji právě jedna jiná firma, kde smíte importovat banku, se načte do ní. Aplikace to ohlásí. Nový úvěrový účet se zakládá vždy v aktivní firmě. |
| Výpis kreditní karty nejde načíst v daňové evidenci | Kreditní karty jsou jen pro podvojné účetnictví | Nákupy evidujte přes doklady (viz [§ 112.9.5](#11295-danova-evidence)). |
| Účet je ve stavu **Ke kontrole** | Založil ho import výpisu | V detailu zkontrolujte údaje a klikněte na **Uložit a potvrdit**. |
| U účtu vidíte **Rozdíl proti výpisu** | Část pohybů není zaúčtovaná, nebo je režim bez mezičlenu | Zpracujte pohyby (viz [§ 112.4](#1124-krok-za-krokem-mesicni-odsouhlaseni)), případně **Zaúčtovat čekající pohyby**. |
| Pohyb zůstal **Nezaúčtováno** | Automatika nenašla kontaci | Ve výpisu ho spárujte s dokladem nebo zaúčtujte MD/D. |
| Počáteční dluh nejde zaúčtovat | Datum leží v uzavřeném nebo zamčeném období | Zvolte datum v otevřeném období. |
| Analytika 231 se nepřidělila automaticky | Na analytice už leží cizí zápisy (typicky bankovní úvěr) | V detailu účtu vyberte **Jiná analytika 231**, pokud na stávající není zůstatek. |
| Převod na kreditní kartu se nepovedl | Účet má zaúčtované pohyby v uzavřeném období | Otevřete období a převod zopakujte (viz [§ 112.9.4](#11294-ucet-vedeny-driv-jako-bankovni-ucet)). |
| Úvěrový účet je vypnutý na záložce **Kontace účtů** v bance | Pohyby by se účtovaly na 221 místo 231 | Úvěrový účet tam nevypínejte. Nepoužívanou kartu archivujte v jejím detailu. |

## 112.9 Podrobnosti a pravidla

### 112.9.1 Podporované banky a výpisy

**Nahrát výpis** načte PDF výpis ke kreditní kartě. Podporované banky:

| Banka | Výpis | Identifikace účtu |
|---|---|---|
| Komerční banka | Výpis z účtu ke kreditní kartě | číslo úvěrového účtu |
| Raiffeisenbank | Výpis z kartového účtu | referenční číslo karty (RB číslo účtu netiskne) |
| Česká spořitelna (Erste) | Výpis z kartového účtu | číslo kartového účtu |
| ČSOB | Výpis z úvěrového účtu ke kartě | číslo úvěrového účtu |

Úvěrový účet se hledá nejprve v aktivní firmě. Pokud tam není a eviduje ho právě jedna další firma, do které smíte importovat banku, výpis se přiřadí jí. Nový úvěrový účet vzniká v aktivní firmě; při nejednoznačnosti nejprve zvolte správnou firmu. Kreditní účet vedený v cizí měně aplikace odmítne. Úvěrový účet se na záložce **Kontace účtů** v bance nevypíná: jeho pohyby se účtují na 231 a vypnutý by je poslal na 221. Kartu, kterou už nepoužíváte, archivujte v detailu kreditní karty. Výpisy i zaúčtované pohyby zůstanou beze změny, účet se jen skryje z přehledu. Nákupy kreditkou se automaticky párují s přijatými fakturami stejně jako odchozí platby z běžného účtu; splátky na kreditní účet se s vydanými fakturami nepárují.

### 112.9.2 Zpracování pohybů ve výpisu

Výpis kreditní karty je bankovní výpis jako každý jiný. Pohyby zpracujete v detailu výpisu v sekci Banka se všemi akcemi bankovního pohybu: spárování s dokladem (i částečné a na více dokladů), zrušení párování, zaúčtování MD/D i rozúčtování na více řádků, přeúčtování, zrušení zaúčtování, ignorování, poznámka, dimenze, pravidlo z pohybu a návrhy automatiky. Strana úvěru se u pohybu kreditní karty vždy zapíše na analytiku 231 úvěrového účtu.

Detail kreditní karty slouží jako rozcestník:

- u každého pohybu ukazuje druh a stav. Klik na pohyb otevře výpis rovnou na něm,
- souhrn **Co zbývá dořešit** sečte počty a částky nezaúčtovaných pohybů, návrhů a nákupů bez dokladu a otevře výpis na nejstarším z nich; u nákupů bez dokladu vede na sekci **Nákupy bez dokladu** níže na stránce,
- sekce **Nákupy bez dokladu** ukazuje nákupy tohoto úvěrového účtu, ke kterým zatím není doklad: nahrajete k nim účtenku, spárujete je, nebo nákup uzavřete bez dokladu (akce stejné jako u platebních karet). Nákupy kreditkou se v Platebních kartách neukazují,
- tabulka výpisů ukazuje ke každému výpisu zůstatek analytiky 231 ke dni výpisu (zeleně, když sedí na konečný zůstatek výpisu), počet nevyřešených pohybů a tlačítko **Zpracovat ve výpisu**.

### 112.9.3 Přehled a detail úvěrového účtu

Přehled ukazuje u každého úvěrového účtu banku, číslo účtu, analytiku 231, limit, **zůstatek v účetnictví** a poslední výpis. Pod zůstatkem je kontrola proti výpisu: zůstatek analytiky ke dni posledního výpisu se porovná s jeho konečným zůstatkem. **Sedí na výpis** znamená, že účetnictví odpovídá bance. Dluh je v účetnictví i na výpisu záporný. Archivované účty zobrazíte volbou **Zobrazit archivované**.

V detailu úvěrového účtu upravíte:

| Pole | Význam |
|---|---|
| **Název** | vaše pojmenování účtu |
| **Úvěrový limit** | limit úvěru podle smlouvy nebo výpisu |
| **Účet pro splátku**, **Kód banky** | kam posíláte splátku z běžného účtu |
| **Variabilní symbol splátky** | VS, pod kterým banka splátku přijímá |
| **Poznámka** | volný text |

Raiffeisenbank přijímá splátky na svůj sběrný účet s variabilním symbolem: když ho v detailu úvěrového účtu vyplníte, platba z běžného účtu na tento účet a s tímto VS se zaúčtuje jako převod MD 261 / D 221.x, ne jako výdaj.

Úvěrový účet, který už nepoužíváte, **archivujte**. Výpisy i zaúčtované pohyby zůstanou beze změny, účet se jen skryje z přehledu.

### 112.9.4 Účet vedený dřív jako bankovní účet

Výpis ČSOB ke kreditní kartě má stejný vzhled jako výpis běžného účtu. Pokud jste ho v minulosti načetli v sekci Banka, vede ho aplikace jako bankovní účet a jeho pohyby jsou zaúčtované na 221. Při dalším načtení nabídne **převod na kreditní kartu**. Převod:

- přepne účet na úvěrový účet kreditní karty a přidělí mu analytiku 231,
- přepíše zaúčtované pohyby účtu z 221 na analytiku 231; protiúčty, částky ani data se nemění,
- nejde provést, pokud má účet zaúčtované pohyby v uzavřeném účetním období.

Přesun mezi 221 a 231 nemá vliv na daně, proto se zápisy přepisují na místě i v datu zamčeném podaným přiznáním k DPH.

### 112.9.5 Daňová evidence

Kreditní karty jsou jen pro firmy v **podvojném účetnictví**. Firma v daňové evidenci výpis kreditní karty nenačte: úvěrový účet by v peněžním deníku vystupoval jako peníze, dluh by snižoval zůstatek peněz a splátky by se tvářily jako příjem. Nákupy kreditkou v daňové evidenci evidujte přes doklady (výdaj v den úhrady splátkou z běžného účtu).

### 112.9.6 Nákupy bez dokladu: akce podrobně

Nahoře v sekci je název účtu, počet plateb a jejich součet. Pod obchodníkem je vedle popisu z výpisu (datum transakce, zúčtovaná částka a měna, místo) uvedena analytika mezičlenu, na které nákup čeká, například **Mezičlen 378.101**. Každý úvěrový účet má svou analytiku, takže zůstatek mezičlenu se rovná součtu nákupů v této sekci. Odkaz **Detail kreditní karty** u názvu účtu vede na tentýž detail.

- **Nahrát účtenku**: vložené ISDOC má přednost před AI, fotografie se převede na PDF a stejný soubor se podruhé nezaloží. Bez AI nebo při neúspěšném vytěžení se účtenka uloží do **Příchozích dokladů** (složka Příchozí doklady / rok / měsíc) s vazbou na platbu a doklad založíte tam. Samotné nahrání nic nezaúčtuje.
- **Spárovat**: po potvrzení dokladu spustí párování nákupu znovu. Spárováním vznikne vypořádání MD 321 / D 378.x (s kurzovým či haléřovým rozdílem). Podobný doklad se nabídne jako návrh k potvrzení v detailu výpisu.
- **Bez dokladu, nedaňově**: uzavře nákup do nedaňového nákladu: MD 548 (analytika z nastavení kreditní karty, jinak platebních karet) / D 378.x.
- **Bez dokladu, daňově**: uzavře nákup do daňového nákladu, výchozí MD 518 / D 378.x, bez odpočtu DPH. Jen tam, kde výdaj prokážete jiným průkazným dokladem než přijatou fakturou.
- **K tíži držitele**: soukromý nákup kartou firmy: MD 335 (případně 355 nebo 378) / D 378.x. Vznikne pohledávka za držitelem v účetnictví, žádný doklad ani srážka ze mzdy.

Dialog ukáže datum, obchodníka a částku a dovolí vybrat jiný účet z osnovy. Zápis dostane číslo **KARTA-**číslo pohybu a datum nákupu (v uzavřeném období první otevřený den); vratka se zaúčtuje s opačnými stranami. Uzavřený nákup ze sekce zmizí.

**Splátka kreditní karty** mezičlen nepoužívá. Splátka z vlastního běžného účtu se spáruje jako vlastní převod: na úvěrovém účtu MD 231.x / D 261, na běžném účtu MD 261 / D 221.x. Splátka bez protiúčtu ve výpisu se zaúčtuje MD 231.x / D 261 (nastavitelné 261 nebo 395).

### 112.9.7 Účetní zápisy

Každý úvěrový účet má vlastní analytiku **231.101, 231.102 …**, přidělovanou postupně. Analytiku, na které už leží cizí zápisy (typicky bankovní úvěr), si úvěrový účet automaticky nevezme. Jinou existující analytiku 231 lze vybrat ručně v detailu účtu, pokud na stávající analytice není zůstatek.

Datum zápisu je **datum zaúčtování bankou**, ne datum transakce. Zůstatek analytiky tak ke každému dni odpovídá výpisu. Datum transakce a původní částka v cizí měně zůstávají v popisu pohybu.

#### 112.9.7.1 Režim nákupů

Nákup kartou se účtuje podle **režimu nákupů**. Výchozí režim firmy určíte v Nastavení účtování, u jednotlivého úvěrového účtu ho změníte v jeho detailu.

Výchozí režim je **Bez mezičlenu**; režim **Přes mezičlen** zapnete v Nastavení účtování pro celou firmu, nebo jen u jednoho úvěrového účtu.

**Přes mezičlen.** Nákup se zaúčtuje hned v den zaúčtování bankou na mezičlen proti úvěru, MD 378.x / D 231.x. Dluh vůči bance tak v účetnictví je, i když doklad ještě nedorazil, a zůstatek 231 vždy sedí na výpis. Doklad pak nákup vypořádá z mezičlenu:

- spárováním pohybu s přijatým dokladem (MD 321 / D 378.x, kurzový rozdíl 563/663, haléřový rozdíl 548/648),
- nahráním účtenky v sekci Nákupy bez dokladu v detailu kreditky (účtenka se vytěží do přijatého dokladu, po jeho kontrole ho spárujete),
- bez dokladu jedním z uzavření: **nedaňově** (nedaňový náklad), **daňově** (daňový náklad bez DPH, jen s jiným průkazným dokladem) nebo **k tíži držitele** (soukromý nákup, pohledávka za zaměstnancem nebo společníkem). Dorazí-li doklad později, uzavření se samo zruší.

Mezičlen je syntetika z nastavení platebních karet (378, 261 nebo 395). Každý úvěrový účet dostane vlastní analytiku ve stejné řadě jako platební karty (378.101, 378.102 …); karta se u kreditky pozná podle úvěrového účtu, ne podle koncovky. Jinou existující analytiku mezičlenu vyberete v detailu účtu. Přes mezičlen jdou jen nákupy a vratky; úroky, poplatky, splátky, výběry a odměny se účtují podle tabulky níže.

**Bez mezičlenu** (výchozí). Nákup se zaúčtuje až spárováním s dokladem (MD 321 / D 231.x), pravidlem, naučenou kontací nebo ručně. Dokud k tomu nedojde, na 231 chybí a kontrola proti výpisu ukáže rozdíl.

Změna režimu platí pro pohyby, které ještě nejsou zaúčtované. Zaúčtované pohyby zůstávají, jak jsou. Akce **Zaúčtovat čekající pohyby** v detailu účtu (nabídne se i po změně režimu) pošle nezaúčtované pohyby výpisů znovu automatikou podle aktuálního režimu a nastavení.

#### 112.9.7.2 Zápisy

| Případ | Zápis |
|---|---|
| Nákup kartou, režim přes mezičlen | MD 378.x / D 231.x |
| Doklad k nákupu z mezičlenu | MD 321 / D 378.x |
| Nákup bez dokladu, uzavření nedaňově / daňově / k tíži držitele | MD 548.990 / 518 / 335 / D 378.x |
| Vratka, režim přes mezičlen | MD 231.x / D 378.x |
| Nákup kartou spárovaný s dokladem, režim bez mezičlenu | MD 321 / D 231.x |
| Nákup bez dokladu podle pravidla nebo ručně, režim bez mezičlenu | MD 5xx / D 231.x |
| Vratka spárovaná s dobropisem, režim bez mezičlenu | MD 231.x / D 321 |
| Počáteční dluh z prvního výpisu | MD 379 / D 231.x |
| Splátka z vlastního běžného účtu | kreditní karta MD 231.x / D 261, běžný účet MD 261 / D 221.x |
| Splátka bez protiúčtu na výpisu | MD 231.x / D 261 |
| Úrok | MD 562 / D 231.x |
| Poplatek | MD 568 / D 231.x |
| Výběr hotovosti kartou | MD 261 / D 231.x |
| Odměna, cashback připsaný na úvěrový účet | MD 231.x / D 648 |

V pravidlech se strana banky zadává jako 221; u pohybu kreditní karty se zapíše na analytiku 231 úvěrového účtu. Nákup v cizí měně banka přepočte a výpis ho nese v korunách; kurzový rozdíl proti dokladu v cizí měně jde na 563 nebo 663. Poplatek za převod měny, který banka strhne zvlášť, je poplatek.

Splátku z vlastního účtu aplikace spáruje jako převod mezi vlastními účty přes 261. Úroky, poplatky, splátky bez protiúčtu, výběry a odměny se účtují automaticky nebo jako návrh podle nastavení automatiky: úroky a odměny podle typu Bankovní úroky, poplatky podle Bankovní poplatky, splátky a výběry podle Převody mezi vlastními účty. Zamítnutý návrh se u pohybu znovu nenabízí.

#### 112.9.7.3 Nastavení účtování

Záložka **Nastavení účtování** určuje výchozí režim nákupů a účty:

| Pole | Výchozí účet | Povolené účty |
|---|---|---|
| **Úroky z úvěru** | 562 | 56x |
| **Poplatky** | 568 | 5xx |
| **Splátka bez protiúčtu** | 261 | 261, 395 |
| **Výběr hotovosti kartou** | 261 | 261, 211 |
| **Odměna a cashback** | 648 | 6xx |
| **Nákup bez dokladu, daňový náklad** | 518 | 5xx |
| **Nákup bez dokladu, nedaňový náklad** | podle nastavení platebních karet (548.990) | 5xx |
| **Soukromý nákup (k tíži držitele)** | podle nastavení platebních karet (335) | 335, 355, 378 |
| **Protiúčet počátečního dluhu** | 379 | třídy 3 a 4 |

Nedaňové účty jsou v nabídce označené podle příznaku v účtové osnově. Analytiku mezičlenu karty zvolit nejde. Změna platí jen pro nové zápisy, zaúčtované pohyby se nepřeúčtovávají.

## 112.10 Související kapitoly

- [Platební karty](31_Platebni_karty.md) - karty k běžnému účtu a jejich platby bez dokladu.
- [Banka](29_Banka.md) - detail výpisu, kde se pohyby kreditní karty zpracovávají.
- [Bankovní účty](30_Bankovni_ucty.md) - účty a kontace účtů.
- [Účetní deník](52_Ucetni_denik.md) - zápisy KARTA- a storna.
