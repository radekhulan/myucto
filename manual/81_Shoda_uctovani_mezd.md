# 81. Shoda účtování mezd

> Jak po zaúčtování mezd ověřit, že schválená mzdová revize, účetní deník
> a platební závazky souhlasí po kategoriích. Pro účetní firem v podvojném
> účetnictví.

## 81.1 Kdy to potřebujete

- Schválili jste a zaúčtovali mzdový běh a chcete ověřit, že deník odpovídá
  mzdám.
- Připravili jste platby a chcete vidět, že závazky sedí na zaúčtované částky.
- Opravili jste schválený měsíc novou revizí a chcete zkontrolovat rozdílové
  zaúčtování.
- Na účtu 342, 336 nebo 331 vám nesedí zůstatek a hledáte, na které straně
  rozdíl vznikl.

Kontrola patří do měsíčního postupu hned po kroku **Zaúčtovat** na kartě běhu
(viz [§ 75.4](75_Uplne_mzdy.md#754-krok-za-krokem-zpracovani-mzdoveho-mesice)).

## 81.2 Než začnete

1. **Schválený mzdový běh** za období (`Mzdy → Mzdové běhy`).
2. **Předkontace mezd** v `Mzdy → Nastavení mezd` (viz
   [Nastavení mezd](90_Nastaveni_mezd.md)).
3. **Oprávnění k zaúčtování mezd** (`payroll.post`). Bez něj se položka
   `Mzdy → Shoda účtování mezd` v menu nezobrazí.

## 81.3 Krok za krokem: kontrola shody po zaúčtování

1. Na kartě běhu v `Mzdy → Mzdové běhy` klikněte na **Zaúčtovat**.
2. Otevřete `Mzdy → Shoda účtování mezd` a nahoře zvolte **Mzdové období**.
3. Přečtěte souhrnnou větu nad tabulkou.
4. V tabulce projděte kategorie. U každé porovnejte sloupce **Mzda**,
   **Deník** a oba sloupce plateb (závazek a uhrazeno) a podívejte se do
   sloupce **Stav**.
5. U kategorie se stavem **Rozdíl** zjistěte ze sloupců **Rozdíl (mzda ↔
   deník)**, **Rozdíl (mzda ↔ platby)** a **Rozdíl (deník ↔ platby)**, na
   které straně vznikl.
6. Opravte příčinu: chybějící nebo dvojí zaúčtování, chybnou předkontaci,
   nepřipravené platby. Mzdu opravíte jen novou revizí
   (**Vyžádat opravu** na kartě běhu).
7. Klikněte na **Obnovit** a zkontrolujte výsledek znovu.

**Jak poznáte, že je hotovo:** Nahoře je věta **Mzda, deník i platby si po
kategoriích odpovídají.** a všechny kategorie mají stav **Souhlasí**, případně
**Nepoužije se**. Řádky se štítkem **Informativní** rozdílem nejsou.

> [!WARNING]
> Rozdíl neřešte nesouvisejícím ručním zápisem do deníku. Zakryjete tím
> příčinu, například dvojí zaúčtování nebo zaokrouhlení, a v dalším měsíci se
> vrátí.

## 81.4 Když něco nejde

<!-- cols: 32 34 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Pro toto období neexistuje mzdový běh** | Za zvolené období není běh | Zvolte jiné období, nebo založte běh v `Mzdy → Mzdové běhy` |
| **Měsíc ještě není schválen** | Shoda se počítá až pro schválenou revizi | Dokončete schválení v `Mzdy → Mzdové běhy` |
| Souhrn hlásí, že období zatím není zaúčtováno a nejde o rozdíl | Automatické zaúčtování je vypnuté nebo krok ještě neproběhl | Na kartě běhu klikněte na **Zaúčtovat** |
| Souhrn hlásí, že firma vede daňovou evidenci a účetní srovnání se nepoužívá | Mzdy se v daňové evidenci do deníku neúčtují | Nic, sloupec Deník zůstává prázdný |
| Sloupce Platby jsou prázdné | Platební závazky pro období zatím nebyly připravené | Na kartě běhu klikněte na **Připravit platby** |
| Rozdíl v kategorii daně, účet 342 je debetní | Vyplacené daňové bonusy převýšily sražené zálohy | Nejde o chybu, viz [§ 81.5.4](#8154-kdy-je-rozdil-v-dani-legitimni) |
| Hrubá mzda se liší od mzdového listu | Nepeněžní plnění bez účetního dopadu se do porovnávané hrubé mzdy nezapočítává | Pro kontrolu proti mzdovému listu použijte mzdový list ([§ 81.5.3](#8153-ucetne-neutralni-nepenezni-plneni)) |
| Rozdíl mezi mzdou a deníkem po opravě měsíce | Opravná revize ještě není zaúčtovaná | Zaúčtujte novou revizi na kartě běhu |

## 81.5 Podrobnosti a pravidla

### 81.5.1 Co stránka porovnává

Stránka je dostupná v podvojném účetnictví pro schválenou revizi. Pro
zvolené období porovná mzdovou revizi, skutečně zaúčtovaný deník a platební
závazky po kategoriích:

- hrubé mzdy (521/522/523),
- pojistné hrazené zaměstnavatelem (524),
- sociální a zdravotní pojištění (336),
- daň ze závislé činnosti (342),
- ostatní srážky (379),
- exekuční a insolvenční srážky (379),
- čistá mzda k výplatě (331/366),
- povinné spoření u rizikové práce (527/379),
- zápočet čisté mzdy na účet společníka (365).

U každé kategorie ukáže, na které straně případný rozdíl vznikl. Stránka je
čistě informační a nic nezapisuje do deníku ani do mzdové revize. Podrobný
rozpad podle zaměstnance najdete v mzdovém běhu a na stránce Mzdové příkazy
a úhrady za totéž období.

Měsíc, který ještě nebyl zaúčtován (vypnuté automatické zaúčtování, čekající
krok, nebo firma vedoucí daňovou evidenci), stránka označí jako
nezaúčtovaný. Nejde o rozdíl.

Shoda potvrzuje částkovou a vazební kontrolu, ne správnost celého účtového
rozvrhu. Kontrolujte i vyrovnanost, období a účty závazků, nákladů, daně,
pojistného a srážek. U ručních změn zachovejte auditní stopu. Mzdový detail
zpřístupněte jen oprávněným účetním.

Časté chyby: dvojí zaúčtování stejného běhu, zaúčtování rozpracované místo
schválené revize, rozdíl ze zaokrouhlení zakrytý nesouvisejícím zápisem
a smíchání účetních období nebo středisek.

### 81.5.2 Opravy a účty mimo standardní syntetiky

Oprava schváleného měsíce se do porovnání promítne správně. Deník se sčítá
napříč všemi revizemi běhu, protože rozdílová revize účtuje jen rozdíl proti
poslední zaúčtované revizi.

Do hrubých mezd se započítají i nákladové účty převzaté ze zmrazené dimenze
nebo z výslovné předkontace mzdové složky. Nemusí tedy jít jen o syntetiky
521, 522 a 523. Opravná revize zachová v klasifikaci i původní účet, aby
jeho storno a přesun na nový účet skončily ve stejné mzdové kategorii.

U povinného spoření při rizikové práci se sleduje nákladová strana (527).
Účtujete-li na 527 i jinou mzdovou složku, oddělte příspěvek analytikou (např.
527.100). Zápočet čisté mzdy na účet společníka nic nevyplácí, je to účetní
překlasifikace závazku (331/366 MD / 365 D). Kategorie čisté mzdy je proto
o tuto částku nižší a platební sloupec zůstává prázdný.

Řádek **Placeno mimo mzdový můstek (zákonné pojištění, benefity)** je
informativní. Zákonné pojištění odpovědnosti a benefity se platí z mezd, ale
účtují se vlastním dokladem; mzdový můstek je nezapisuje, takže není s čím
porovnávat.

### 81.5.3 Účetně neutrální nepeněžní plnění

Nepeněžní složka, která nemá vlastní dvojici účtů, se do mzdového deníku
nezaúčtuje. Náklad je v knihách už ze zdrojového dokladu (faktura za
ubytování, za vzdělávání, leasing vozidla) a mzdový zápis by ho zaúčtoval
podruhé. Pro daň a pojistné je to ale zdanitelný příjem, takže do hrubé mzdy
patří.

Hrubá mzda se proto porovnává jako **účtovatelná** část a neutrální nepeněžní
plnění se z porovnání vyčleňuje do informativního řádku **Nepeněžní plnění
bez účetního dopadu**. Deník ani platby k němu nemají co přiřadit, takže
rozdíl nevzniká. Firma, která poskytuje například 1 % z ceny vozidla nebo
přechodné ubytování, tu nevidí rozdíl, který ve skutečnosti rozdílem není.

Hrubá mzda na této stránce proto **není** totéž co hrubá mzda na mzdovém
listu: neutrální nepeněžní plnění v ní chybí. Pro kontrolu proti mzdovému
listu použijte mzdový list a výplatní pásku, ne kategorii hrubých mezd
z porovnání.

### 81.5.4 Kdy je rozdíl v dani legitimní

Kategorie daně může vykázat rozdíl, i když je vše správně: převýší-li
vyplacené daňové bonusy sražené zálohy, kontrolní součty odvod podlahují
nulou, zatímco deník ne a účet 342 zůstane debetní. Je to skutečná
**pohledávka za finančním úřadem** podle § 35d odst. 5 zákona o daních
z příjmů, kterou má účetní vidět. Ukazuje ji informativní řádek
**Pohledávka za FÚ z daňových bonusů (§ 35d odst. 5)**. O rozdíl snížíte
odvod v dalších měsících, nebo o něj požádáte správce daně. Nepřekrývejte ji
ručním zápisem.

Pohledávka za zaměstnancem ze záporné čisté mzdy (účet 335) se do porovnání
záměrně nezapočítává jako záporná čistá mzda. Obě strany rozvahy se nesčítají
do jednoho čísla se znaménkem.

### 81.5.5 Podrobné zaúčtování mezd

Stránka **Mzdy → Zaúčtování mezd** ukazuje, jak se mzdový předpis měsíce
zaúčtoval, po dvojicích účtů:

- Nahoře je **souhrn za firmu**: druh mzdy, účet MD, účet Dal, hodiny a částka.
- Pod ním je **rozpad po zaměstnancích a druzích mezd**: osobní číslo a jméno,
  druh mzdy, účet MD, účet Dal, středisko, hodiny a částka.

Druh mzdy je u hrubé mzdy název mzdové složky, u ostatních řádků jejich povaha
(pojistné zaměstnance, záloha na daň, pojistné zaměstnavatele, srážka, zápočet
na účet společníka). Hodiny se vyplní jen u hodinové mzdy, jinde je katalog
mzdových složek nevede. Pojistné zaměstnavatele se zaměstnancům přiřadí, když
firma účtuje po zaměstnancích nebo pracovní vztahy nesou středisko; jinak je
v řádku **Celá firma**. Tam zůstává i závazek pojistného zaměstnavatele vůči
institucím.

Sestava čte zaúčtovaný stav posledních zaúčtovaných revizí běhů měsíce. Její
součet je proto obratem mzdových zápisů měsíce v deníku a zůstatek po účtech
sedí na deník bez ohledu na to, zda firma účtuje souhrnně, nebo po
zaměstnancích, a zda používá jednoduchou, nebo složenou formu zápisu. Po
opravné revizi sestava ukazuje opravený stav, deník k němu dojde rozdílovým
zápisem.

Tlačítka **Export XLSX** a **Export CSV** stáhnou sestavu do souboru. XLSX má
souhrn i rozpad na jednom listu, CSV jen řádky rozpadu. Sestavu vidí role
s právem na mzdové sestavy; stažení exportu se zapíše do historie činností.

## 81.6 Související kapitoly

- [Mzdové běhy](80_Mzdove_behy.md): zdroj porovnání a zaúčtování
- [Nastavení mezd](90_Nastaveni_mezd.md): předkontace mezd
- [Mzdové příkazy a úhrady](82_Platby_a_uhrady.md): peněžní vypořádání
- [Úplné mzdy](75_Uplne_mzdy.md): pořadí kroků mzdového měsíce
- [Koše benefitů](89_Kose_benefitu.md): nepeněžní plnění
