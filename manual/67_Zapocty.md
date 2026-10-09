# 67. Vzájemné zápočty

> Návod, jak vyrovnat pohledávky a závazky vůči jednomu stejnému partnerovi bez bankovní platby.
> Pro účetní firmy v podvojném účetnictví.

> [!TIP]
> **Daňová evidence** zápočty partnerů nevede. Dobropis se s opravovanou fakturou započte sám, zápočet pohledávky a závazku
> zapíšete při roční uzávěrce jako nepeněžní úpravu. Viz [Daňová evidence - postupy](115_Danova_evidence_postupy.md).

## 67.1 Kdy to potřebujete

Kapitolu otevřete, když:

- partner vám dluží za vydané faktury a vy dlužíte jemu za přijaté a chcete obojí vyrovnat zápočtem,
- potřebujete dohodu o zápočtu ve formě PDF,
- potřebujete potvrzený zápočet zrušit.

Zápočet vyrovná pohledávky a závazky vůči **jednomu stejnému partnerovi**. Aplikace sestaví dohodu, po
potvrzení vytvoří účetní zápis **321 / 311** a sníží otevřené částky vybraných vydaných i přijatých faktur.
Funguje jen s doklady v **CZK** a jen v podvojném účetnictví.

> [!TIP]
> Tento postup páruje **dva doklady** téhož partnera. Chcete-li závazek nebo pohledávku vyrovnat proti
> ÚČTU (pohledávka za společníkem, mzdový závazek, poskytnutá záloha), použijte přímo na dokladu
> *Označit jako uhrazené → Zápočtem proti účtu*, viz
> [§ 23.11.15](23_Prijate_faktury.md#231115-zpusoby-uhrady-prijate-faktury). Oba kanály se navzájem
> započítávají do zbytku dokladu, takže se nemohou překrýt.

## 67.2 Než začnete

1. **Podvojné účetnictví.** Stránka `Nástroje → Zápočty` je dostupná jen firmě v podvojném účetnictví.
2. **Oprávnění.** Zobrazení vyžaduje oprávnění k zápočtům, vytvoření, potvrzení a zrušení jeho zápisovou variantu.
3. **Partner s oběma otevřenými částkami.** Partner musí mít současně otevřenou pohledávku i závazek, oba v CZK.
4. **Předkontace.** Účty zápočtu jsou v předkontacích (výchozí MD 321 / Dal 311). Můžete je firemně změnit, ale musí zůstat věcně správné a aktivní (viz [Nástroje](73_Ucetni_nastroje.md)).
5. **Otevřené období.** Datum zápočtu musí ležet v otevřeném účetním období a za zámkem účtování.

## 67.3 Krok za krokem: sestavit a potvrdit zápočet

1. Otevřete `Nástroje → Zápočty` a klikněte na **Nový zápočet**.
2. V poli **Partner** vyberte partnera. Nabízí se jen ti, kdo mají zároveň otevřenou pohledávku i závazek. Jinak aplikace napíše, že partner nemá zároveň pohledávku i závazek k započtení.
3. Zadejte **Datum zápočtu** (určuje rok číselné řady i datum účetního zápisu) a případně **Poznámku**.
4. V tabulce **Pohledávky (vydané faktury)** a **Závazky (přijaté faktury)** vyplňte u každého dokladu ve sloupci **Započíst** částku, která se má započíst. Sloupec **Zbývá** ukazuje aktuální otevřenou částku.
5. Sledujte součty **Celkem pohledávky** a **Celkem závazky**. Musí se rovnat; pak se zobrazí hláška **Strany se rovnají**.
6. Klikněte na **Vytvořit dohodu**. Vznikne koncept s číslem dohody (obvykle `ZAP-RRRR-NNNN`).
7. V přehledu dohod klikněte u konceptu na **Potvrdit**.

**Jak poznáte, že je hotovo:** Dohoda má stav **Potvrzeno**, aplikace ohlásila **Zápočet potvrzen
a zaúčtován** a zápis je v deníku dohledatelný podle čísla ZAP.

> [!WARNING]
> Zápočet řeší účetní vyrovnání dokladů. Neprokazuje automaticky doručení, přijetí nebo právní účinnost
> dohody protistranou. Příslušné podklady archivujte spolu s PDF.

## 67.4 Krok za krokem: stáhnout PDF dohody

1. Otevřete `Nástroje → Zápočty`.
2. U dohody v přehledu klikněte na **PDF**.

**Jak poznáte, že je hotovo:** Prohlížeč stáhne soubor `dohoda-o-zapoctu-<číslo dohody>.pdf`.

PDF obsahuje hlavičku dohody, obě sady dokladů a částky. Je to obraz evidované dohody; samotné stažení
ani vytvoření konceptu není potvrzením protistrany.

## 67.5 Krok za krokem: zrušit dohodu

1. Otevřete `Nástroje → Zápočty`.
2. U dohody klikněte na **Zrušit** a potvrďte dotaz „Opravdu zrušit tento zápočet? Zaúčtování se stornuje a doklady se vrátí zpět.“

**Jak poznáte, že je hotovo:** Dohoda má stav **Zrušeno**, aplikace ohlásila **Zápočet zrušen** a doklady
se vrátily do původního stavu.

Koncept lze zrušit bez dopadu na doklady. U potvrzené dohody aplikace:

- stornuje účetní zápis,
- smaže jím vytvořené platby vydaných faktur a přepočte jejich stav,
- u přijaté faktury, kterou zápočet označil jako uhrazenou, vrátí stav na přijato,
- ponechá dohodu ve stavu zrušeno kvůli auditní stopě.

Storno respektuje otevřenost účetního období a zámek k datu. Pokud původní období už nelze měnit, nejdřív
rozhodněte o správném opravném postupu. Modul nesmí obejít uzávěrku.

## 67.6 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Partner se v nabídce neobjeví | Nemá současně otevřenou pohledávku i závazek | Zkontrolujte stavy faktur. Doklad musí být v CZK a v otevřeném stavu. |
| **Strany zápočtu se musí rovnat** | Součty pohledávek a závazků se liší o víc než haléř, nebo je jedna strana prázdná | Upravte částky ve sloupci **Započíst**. Každá strana musí mít aspoň jeden doklad. |
| Částka je vyšší než zbytek | Započítáváte víc, než je u dokladu otevřeno | Snižte částku na hodnotu ve sloupci **Zbývá**. |
| Potvrzení skončí chybou, že se zbytek změnil od vytvoření konceptu | Mezitím přišla platba nebo jiný zápočet | Koncept zrušte a sestavte znovu. Aplikace nikdy nevytvoří skrytý přeplatek, jen aby starý koncept prošel. |
| Dohodu nejde potvrdit | Je už potvrzená nebo zrušená | Zkontrolujte stav v přehledu. |
| Chyba u účtu předkontace | Účet je neaktivní | Aktivujte účet nebo opravte předkontaci ([Účtový rozvrh](66_Ucetni_osnova.md)). |
| Datum nejde použít | Leží v uzavřeném období nebo před zámkem účtování | Zvolte datum v otevřeném období. |

## 67.7 Podrobnosti a pravidla

### 67.7.1 Přehled dohod a jejich stavy

Tabulka zobrazuje číslo dohody, datum, partnera, částku a stav:

- **Návrh**: dohoda je sestavená, ale doklady ani deník se ještě nezměnily.
- **Potvrzeno**: vznikl účetní zápis a vyrovnání dokladů.
- **Zrušeno**: případný zápis byl stornován a vytvořené vyrovnání odvoláno.

### 67.7.2 Sestavení zápočtu

Aplikace nabídne k započtení:

- vydané faktury ve stavech vystaveno, odesláno nebo upomenuto,
- přijaté faktury ve stavech přijato nebo zaúčtováno,
- jejich číslo, data, celkovou, dosud uhrazenou a zbývající částku.

Součet pohledávek se musí rovnat součtu závazků; částky se porovnávají v haléřích a toleruje se nejvýše
jednohaléřový rozdíl. Každá strana musí obsahovat alespoň jeden doklad a částka nesmí být nulová ani vyšší
než aktuální zbytek.

Datum dohody určuje rok číselné řady i datum budoucího účetního zápisu. Při uložení se z řady zápočtů přidělí
jedinečné číslo, standardně `ZAP-RRRR-NNNN`. Mezery po zrušených dohodách se nedoplňují.

Vytvoření konceptu je transakční: hlavička a všechny řádky vzniknou společně, nebo nevznikne nic. Doklady se
v této fázi nemění.

### 67.7.3 Jak se počítá otevřená částka

U vydané faktury je zbytek částka k úhradě minus uhrazeno. Uhrazeno zahrnuje evidované platby včetně dříve
potvrzených zápočtů.

U přijaté faktury je zbytek částka k úhradě minus bankovní párování minus potvrzené zápočty. Přijatá faktura
nemá stejný součet úhrad jako vydaná, proto aplikace odečítá párované bankovní platby a potvrzené řádky
zápočtů samostatně. Koncept jiné dohody se neodečítá; teprve potvrzení musí znovu ověřit, zda zbytek mezitím
nesnížila platba nebo jiný zápočet.

### 67.7.4 Potvrzení a zaúčtování

Potvrzením se v jedné databázové transakci:

1. zamkne řádek dohody,
2. znovu zamknou a zkontrolují všechny dotčené doklady,
3. načte předkontace zápočtu (výchozí MD 321 / Dal 311),
4. vytvoří nebo aktualizuje jediný účetní zápis se zdrojem zápočet,
5. u vydaných faktur založí evidovanou platbu,
6. u přijaté faktury nastaví stav uhrazeno, jen pokud zápočet pokryl celý aktuální zbytek,
7. dohodu označí jako potvrzenou.

Částečně započtená přijatá faktura zůstává ve stavu přijato/zaúčtováno, ale účetní saldo 321 je o zápočet
snížené. V přehledu proto vždy posuzujte i skutečný otevřený zbytek, ne jen textový stav.

Účty lze firemně změnit v předkontacích, ale musí zůstat věcně správné. DPH se zápočtem znovu neúčtuje:
vznikla už při zaúčtování faktur.

### 67.7.5 Idempotence a souběh

Účetní zápis má přirozený klíč zdroj zápočet a identifikátor dohody. Opakované potvrzení proto nevytvoří druhý
zápis. Platby dokladů se zakládají jen při přechodu koncept na potvrzeno.

Samotná stavová kontrola by nestačila při dvou souběžných požadavcích. Aplikace proto zamyká dohodu i dotčené
doklady. Druhý požadavek počká na první a uvidí už snížený zbytek. Zrušení drží stejný zámek, takže se
nemůže předběhnout s potvrzením.

### 67.7.6 Deník a audit

Účetní zápis je v deníku dohledatelný podle čísla ZAP a zdrojový panel odkazuje zpět na zápočet. Vytvoření,
potvrzení i zrušení zapisuje samostatnou auditní událost s firmou, uživatelem a technickým kontextem
požadavku. Data jsou oddělená podle firmy: partner, dohoda, faktury i výsledný zápis musí patřit stejné firmě.

## 67.8 Související kapitoly

- [Přijaté faktury](23_Prijate_faktury.md)
- [Účtový rozvrh](66_Ucetni_osnova.md)
- [Účetní nástroje a předkontace](73_Ucetni_nastroje.md)
