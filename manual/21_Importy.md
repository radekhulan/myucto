# 21. Importy (Pohoda XML, ISDOC/ISDOCX, PDF/A-3, iDoklad API, Fakturoid API, Stereo NX)

> Návod, jak do MyÚčta přenést historické vystavené a přijaté faktury z jiného
> systému: ze souborů (Pohoda XML, ISDOC, ISDOCX, PDF/A-3), přímo z iDokladu
> a Fakturoidu, nebo celou agendu ze zálohy Stereo NX. Pro každého, kdo
> přechází na MyÚčto a nechce doklady opisovat.

## 21.1 Kdy to potřebujete

- Přecházíte z Pohody, iDokladu, Fakturoidu, Superfaktury nebo programu
  s podporou ISDOC a chcete převzít vystavené faktury.
- Máte přijaté faktury od dodavatelů jako ISDOC, ISDOCX nebo PDF s vloženým
  ISDOC a nechcete je zadávat ručně.
- Chcete jednorázově přenést kontakty, faktury a bankovní pohyby z iDokladu
  nebo Fakturoidu včetně skutečného platebního stavu.
- Máte zálohu Stereo NX a chcete z ní převést účetnictví.
- Import skončil chybou nebo hláškou v reportu a potřebujete vědět, co dál.

Existují tři cesty. Zvolte podle zdroje dat:

<!-- cols: 26 40 34 -->
| Zdroj | Kde v aplikaci | Postup |
|---|---|---|
| Soubory (Pohoda XML, ISDOC, ISDOCX, PDF/A-3, ZIP) | `Prodej → Import` (vydané), `Nákup → Import` (přijaté) | [§ 21.3](#213-krok-za-krokem-import-vydanych-faktur-ze-souboru), [§ 21.4](#214-krok-za-krokem-import-prijatych-faktur-ze-souboru) |
| iDoklad nebo Fakturoid přes API | `Firma → Externí integrace` | [§ 21.5](#215-krok-za-krokem-import-z-idokladu), [§ 21.6](#216-krok-za-krokem-import-z-fakturoidu) |
| Záloha Stereo NX | `Systém → Přechod z jiných účetních systémů` | [§ 21.7](#217-krok-za-krokem-prevod-ze-stereo-nx) |

## 21.2 Než začnete

1. **Správná firma.** Přepněte se v aplikaci na firmu, do které importujete.
   Směr určuje položka menu: **Prodej → Import** očekává aktuální firmu jako
   dodavatele, **Nákup → Import** jako odběratele. Kontrola IČO brání tomu,
   aby se do firmy omylem importoval cizí doklad.
2. **Oprávnění k importu.** Položky **Import** v menu se zobrazí jen uživateli
   s oprávněním k zápisu do importů a nikdy ne klientské roli.
3. **Databázové migrace (jen import zahraničních dokladů).** Správce musí mít
   spuštěné migrace (`php api/bin/migrate.php`), jinak se import zahraničních
   dokladů nerozběhne (viz [§ 21.9.7.1](#21971-nez-spustite-import)).
4. **Zdrojová data jsou strukturovaná.** Soubor musí být Pohoda XML, ISDOC,
   ISDOCX, PDF/A-3 s vloženým ISDOC, nebo ZIP s takovými doklady. Běžné PDF bez
   strukturovaných dat nejde importovat (viz [§ 21.9.9](#2199-pdfa-3-a-isdocx-import)).
5. **Přihlašovací údaje ke zdrojovému systému** (jen u iDokladu a Fakturoidu):
   Client ID a Client Secret, u Fakturoidu navíc slug účtu.
6. **Vyzkoušejte nanečisto.** U iDokladu a Fakturoidu pusťte první běh jako
   zkoušku (dry-run).

> [!TIP]
> Chcete-li klienty založit ručně se správnou výchozí měnou a paušálem,
> udělejte to před importem. Import pak použije existující karty a nebude
> volat ARES.

## 21.3 Krok za krokem: import vydaných faktur ze souboru

1. Přepněte se na firmu, která je na dokladech dodavatelem.
2. Otevřete `Prodej → Import` (stránka **Import vystavených**).
3. Přetáhněte soubory do rámečku, nebo na něj klikněte a vyberte je. Přijímá se
   `.xml` (Pohoda dataPack), `.isdoc`, `.isdocx`, `.pdf` (PDF/A-3 s vloženým
   ISDOC) a `.zip` s exportovanými doklady.
4. Zkontrolujte seznam **Vybrané soubory** a klikněte na **Importovat**.
5. Počkejte, až se soubory nahrají. Import pak běží na pozadí a ukazuje
   průběh (zpracováno *n* z *N* dokladů a počty vytvořeno, přeskočeno, chyb).
6. Stránku můžete zavřít, import doběhne i bez ní. Chcete-li ho přerušit,
   klikněte na **Zastavit import**.
7. Po dokončení projděte **Souhrn importu** a tabulku výsledků. Přepínač
   **Jen problémové** ukáže jen doklady, které potřebují pozornost.
8. Doklady, které je potřeba dopracovat, otevřete přes odkaz v posledním sloupci
   tabulky.

**Jak poznáte, že je hotovo:** V reportu mají doklady stav **vytvořeno**
(nebo **už existoval**, pokud je import opakujete), v souhrnu nezbývá žádná
**chyba** a nad tabulkou není varování o zastaveném importu.

Netrefili jste se s exportem? Tlačítkem **Zahodit tuto dávku** smažete právě
naimportované doklady a můžete nahrát opravený soubor. Zaúčtované, zamčené nebo
uhrazené doklady se hromadně nemažou, otevřete je jednotlivě.

> [!WARNING]
> Naimportované doklady nejsou zaúčtované, ani když máte zapnutou plnou
> automatiku účtování. Do deníku je dostanete v `Účetnictví → Doúčtovat doklady`
> (viz [§ 21.9.2](#2192-naimportovane-doklady-nejsou-zauctovane)).

## 21.4 Krok za krokem: import přijatých faktur ze souboru

1. Přepněte se na firmu, která je na dokladech odběratelem.
2. Otevřete `Nákup → Import` (stránka **Import přijatých**).
3. Přetáhněte soubory (Pohoda XML, ISDOC, ISDOCX, PDF/A-3 s vloženým ISDOC,
   ZIP) nebo je vyberte kliknutím. Lze vybrat více souborů najednou.
4. Zaškrtněte **Založit jako koncept** jen u dávky, kterou chcete nejdřív
   projít. Bez zaškrtnutí vzniknou doklady rovnou jako přijaté.
5. Klikněte na **Importovat** a sledujte průběh stejně jako v
   [§ 21.3](#213-krok-za-krokem-import-vydanych-faktur-ze-souboru).
6. Projděte report (**Vytvořeno / Přeskočeno / Chyba**) a před zaúčtováním
   otevřete každý doklad. Zkontrolujte dodavatele, období, DUZP, částky, DPH
   klasifikaci a nárok na odpočet.

**Jak poznáte, že je hotovo:** Doklady jsou v `Nákup → Přijaté faktury`
(jako přijaté, nebo jako koncepty, pokud jste zaškrtli **Založit jako koncept**)
a v reportu nezbývá žádná chyba.

Jednu přijatou fakturu vložíte bez stránky Import: na
`Nákup → Přijaté faktury → Nová přijatá faktura` přetáhněte `.isdoc`, `.isdocx`
nebo PDF/A-3 s vloženým ISDOC. Vznikne předvyplněný koncept ke kontrole. Tuto
cestu smí použít každý uživatel s právem vytvářet přijaté faktury, včetně
klientské role.

PDF bez vloženého ISDOC patří do `Nákup → AI import`, případně do scan inboxu
(viz [§ 21.9.14](#21914-import-prijatych-faktur-pravidla-a-scan-inbox)).

## 21.5 Krok za krokem: import z iDokladu

### 21.5.1 Získání přístupových údajů

1. Přihlaste se do [iDokladu](https://app.idoklad.cz/).
2. Otevřete **Nastavení → API → Aplikace**.
3. Vytvořte novou aplikaci se scope `idoklad_api`.
4. Zkopírujte **Client ID** a **Client Secret**. Secret se zobrazí jen jednou,
   uschovejte si ho.

### 21.5.2 Spuštění importu

1. Otevřete `Firma → Externí integrace` a záložku **iDoklad**.
2. Vyplňte **Client ID** a **Client Secret** a klikněte na **Uložit a otestovat**.
   Aplikace ověří připojení. Při chybě 401 zkontrolujte, zda se při vkládání
   nepřidala mezera.
3. V části **Spustit import** zaškrtněte, co chcete přenést: **Bankovní účty**,
   **Bankovní pohyby**, **Kontakty (clients)**, **Vydané faktury**, **Přijaté
   faktury**. Import přenese doklady všech let, které v iDokladu jsou; rozsah
   let se nevolí.
4. Volitelně zaškrtněte **Pouze změny od posledního importu** a **Stáhnout PDF
   přílohy**.
5. Při prvním běhu zaškrtněte **Dry-run (jen ukázat, nepsat)**. Nic se nezapíše
   a výpis ukáže, co by import udělal.
6. Klikněte na **Spustit import**. Nevypadá-li výpis dry-run rozumně, opravte
   příčinu, jinak dry-run odškrtněte a import spusťte znovu naostro.
7. Průběh sledujte v kartě úlohy (stav, počty **Vytvořeno**, **Přeskočeno**,
   **Chyby** a **Log zpráv**). Běžící import zastavíte tlačítkem **Zrušit**.

**Jak poznáte, že je hotovo:** Úloha má stav **Dokončeno**. Stav **Dokončeno
s chybami** znamená, že část dokladů se nepřenesla. Důvody najdete v reportu
a v logu.

> [!WARNING]
> Doklady, které iDoklad vede jako neuhrazené nebo částečně uhrazené, se
> importují jako **Koncept**. Zkontrolujte je a vystavte sami. Automaticky se
> nevystavují, aby klientům nezačaly odcházet upomínky na reálně nezaplacené
> historické faktury.

## 21.6 Krok za krokem: import z Fakturoidu

### 21.6.1 Získání přístupových údajů

**OAuth2 (doporučeno):**

1. Přihlaste se do [Fakturoidu](https://app.fakturoid.cz/).
2. Otevřete **Nastavení → Uživatelský profil → API v3 přístupové údaje**.
3. Přidejte aplikaci a zkopírujte **Client ID** a **Client Secret**.
4. Zjistěte **slug účtu** z adresy po přihlášení: v
   `https://app.fakturoid.cz/jannovak/invoices` je slug `jannovak`. Slug je
   vaše subdoména, ne název firmy.

**E-mail a osobní API token** (starší účty): v **Nastavení → Uživatelský profil
→ API** zkopírujte e-mail a osobní API token, slug zjistěte stejně.

### 21.6.2 Spuštění importu

1. Otevřete `Firma → Externí integrace` a záložku **Fakturoid**.
2. Zvolte typ ověření: **OAuth2 (nové účty)**, nebo **E-mail + API token
   (starší účty)**. Vyplňte **Account slug** a odpovídající údaje.
3. Klikněte na **Uložit a otestovat**.
4. V části **Spustit import** zaškrtněte **Subjekty (kontakty)**, **Vydané
   faktury** a **Přijaté (expenses)**, případně **Pouze změny od posledního importu**
   a **Stáhnout PDF přílohy**.
5. Při prvním běhu zaškrtněte **Dry-run (jen ukázat, nepsat)**. Zkouška projde
   stejné kontroly jako ostrý import (subjekty, sazby DPH, přepočet a shoda
   částek s Fakturoidem), nic nezapíše a vypíše, co by import udělal.
6. Klikněte na **Spustit import**. Poté, co zkouška vypadá rozumně, spusťte
   import znovu naostro.

**Jak poznáte, že je hotovo:** Úloha má stav **Dokončeno** a report ukazuje
počty **Přeneseno**, **Přeskočeno**, **Nepřeneseno** a **Ke kontrole** po
agendách. Doklady v seznamu **Nepřenesené doklady** nebo **Doklady ke kontrole**
mají důvod a návod, co opravit.

Doklady se sazbou DPH, která není v číselníku, se nepřenesou. Sazbu doplňte do
číselníku a import spusťte znovu, už přenesené doklady se přeskočí.

## 21.7 Krok za krokem: převod ze Stereo NX

Převod se hodí pro menší množství dat. Zdrojová i cílová firma musí mít stejné
IČO, být plátcem DPH a cílová firma musí používat **daňovou evidenci** nebo
**podvojné účetnictví**.

1. Ve Stereo NX spusťte program jako oprávněný uživatel. V nabídce **Ostatní →
   Zálohování dat** vyberte firmu a vytvořte zálohu. Vznikne soubor ZIP.
2. V MyÚčtu zvolte cílovou firmu a otevřete `Systém → Přechod z jiných účetních
   systémů`. U dlaždice **Stereo NX** klikněte na **Otevřít průvodce**.
3. Krok **Záloha:** nahrajte ZIP tlačítkem **Nahrát zálohu**. Výchozí heslo Stereo
   NX aplikace použije automaticky.
4. Krok **Firma:** ze seznamu firem v záloze vyberte správnou. Liší-li se firemní
   údaje, označte pole, která chcete převzít z `firma.bin`, a potvrďte jejich
   převzetí. Pokud prázdná země protistrany ve zdroji znamená ČR, zaškrtněte
   odpovídající volbu.
5. Krok **Zkouška nanečisto:** klikněte na **Zkouška nanečisto**. Projde stejnými
   databázovými operacemi jako skutečný převod, ale změny vrátí zpět.
   Zkontrolujte rozsah dat, počty dokladů, konceptů a důvody ruční kontroly.
6. Krok **Převod:** zaškrtněte potvrzení **Zkontroloval(a) jsem výsledek
   a chci data převést do vybrané firmy.** a klikněte na **Spustit převod**.
   Volitelně zaškrtněte **Smazat nahranou zálohu po úspěšném importu**.
7. Po dokončení projděte protokol převodu a odkazy na doklady a peněžní pohyby
   k ruční kontrole.

**Jak poznáte, že je hotovo:** Průvodce ohlásí **Převod dokončen.** a protokol
ukazuje u kroků stav **V pořádku**. Položky označené **Ke kontrole** projděte
ručně.

> [!WARNING]
> Změna vybrané firmy nebo výkladu prázdné země vyžaduje novou zkoušku nanečisto.
> Zálohu si uchovejte. Při nepřevedených nebo kontrolovaných údajích se
> automatické smazání zálohy nenabídne.

Pokud deník v záloze ukazuje jiný účetní režim než má cílová firma, průvodce
převod zastaví a zobrazí oba režimy. Odkaz **Nastavení firmy** otevře správu
účetnictví s kontrolami změny režimu. Po změně načtěte zálohu znovu a zopakujte
zkoušku.

## 21.8 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Soubor nejde vybrat, "Jeden soubor může mít nejvýše 40 MiB" | Překročen limit souboru | Rozdělte export do menších souborů (viz [§ 21.9.1](#2191-limity-nahravani-a-beh-na-pozadi)) |
| "Vyberte nejvýše N souborů" | Příliš mnoho souborů najednou | Rozdělte výběr do menších dávek |
| Celý soubor je přeskočený, v reportu je hláška o cizím dodavateli | IČO dodavatele v souboru není aktuální firma | Přepněte se na správnou firmu (viz [§ 21.9.5](#2195-co-se-preskoci)) |
| Doklad je **už existoval** nebo **přeskočeno** | Doklad stejného druhu se stejným variabilním symbolem už u firmy je | Nechte být, nebo existující doklad smažte a naimportujte znovu |
| Doklad je ve stavu **chyba**, protože nemá položky | Doklad bez jediné položky se nezakládá | Doplňte položky ve zdrojovém systému a importujte znovu |
| Dobropis má poznámku **VS nahrazen** | Dobropis měl stejný VS jako faktura, nelze mít dva pod jedním symbolem | Dobropis hledejte pod odvozeným symbolem (viz [§ 21.9.6](#2196-dobropis-se-stejnym-variabilnim-symbolem-jako-faktura)) |
| Import zahraničních dokladů se vůbec nerozběhne, hláška žádá spustit migrace | Chybí číselník sazeb států OSS | Správce spustí `php api/bin/migrate.php` a import zopakujete |
| Doklad se zahraniční sazbou se odmítl | Sazba v zemi dodavatele k datu plnění není v číselníku, nebo je u sazby špatná země | Opravte zemi v `Nastavení → Číselníky → DPH sazby` a import zopakujte (viz [§ 21.9.7.2](#21972-jak-se-import-rozhoduje)) |
| "PDF neobsahuje ISDOC přílohu" | Běžné PDF nemá strukturovaná data | Stáhněte ISDOC samostatně ve zdrojovém systému, použijte `Nákup → AI import`, nebo doklad zadejte ručně |
| Šifrované PDF se nenačte | Extraktor šifrovaná PDF neumí | Otevřete PDF v Adobe Readeru, uložte bez šifrování a nahrajte znovu |
| iDoklad nebo Fakturoid vrací "Neplatné credentials" nebo 401 | Mezera při vkládání Client Secret nebo tokenu | Vložte údaje znovu bez okolních mezer a zkuste **Uložit a otestovat** |
| Nevíte, kde je slug Fakturoidu | Je v adrese po přihlášení | `app.fakturoid.cz/jannovak/invoices` má slug `jannovak`, ne název firmy |
| Import se zasekl nebo neodpovídá | Zasekl se proces na pozadí | Klikněte na **Zrušit** (u iDokladu a Fakturoidu), případně správce restartuje aplikační kontejner (`docker compose restart app`) a import spustíte znovu |
| Pokus o druhý import se stejnými parametry skončí odkazem na běžící úlohu | Stejný import nelze spustit dvakrát souběžně | Počkejte na dokončení nebo běžící úlohu zrušte |
| Faktury z iDokladu nebo Fakturoidu nemají DPH klasifikaci | Zdrojový systém ji u položek nevyplnil | Aplikace dosadí výchozí podle sazby, nulovou sazbu vystaveného řádku musíte zařadit sami (viz [§ 21.9.13](#21913-importy-z-api-prubeh-a-pravidla-klasifikace-dph)) |
| Kontakty nemají e-mail | Zdrojový systém je nemá vyplněné | Doplňte v `Prodej → Klienti`, jinak nepůjdou upomínky |
| Doklady se nezobrazují v účetním deníku | Import neúčtuje | `Účetnictví → Doúčtovat doklady` (viz [§ 21.9.2](#2192-naimportovane-doklady-nejsou-zauctovane)) |
| Stereo NX: "Chybí databázová migrace Stereo NX" | Správce nespustil migrace | Správce spustí `php api/bin/migrate.php`, pak zkoušku zopakujte |
| Stereo NX: "IČO se neshoduje" nebo "Tento převod vyžaduje plátce DPH" | Zdrojová a cílová firma si neodpovídají | Zvolte správnou firmu v záloze i v MyÚčtu |
| Stereo NX: převod zastaven pro cizoměnové doklady, odpočty záloh nebo nepodporované druhy dokladů | Nepodporovaný rozsah převodu | Řiďte se vysvětlením v protokolu, majetek, mzdy a sklad vyžadují samostatný převod |

## 21.9 Podrobnosti a pravidla

### 21.9.1 Limity nahrávání a běh na pozadí

Najednou lze vybrat až 5000 souborů. Jeden soubor může mít nejvýše 40 MiB,
celá nahrávaná dávka 128 MiB. Větší export rozdělte do menších dávek. Soubory se
nahrávají po částech a tlačítko ukazuje průběh. Import se spustí až po úspěšném
nahrání celé dávky; neúplná nebo odmítnutá část import nespustí. ZIP může mít
nejvýše 20 000 položek a rozbalené doklady celé dávky nejvýše 128 MiB.
Jednotlivé XML nebo ISDOC v ZIP může mít nejvýše 10 MiB.

Import běží jako úloha na pozadí, ne v rámci odeslání formuláře. Export z jiného
systému běžně nese tisíce dokladů a takový běh by se do jednoho požadavku
nevešel. Během běhu vidíte:

- **ukazatel průběhu** - zpracováno *n* z *N* **dokladů** (ne souborů)
  a průběžné počty vytvořeno, přeskočeno, chyb,
- **Zastavit import** - doběhne rozepsaný doklad a skončí. Doklady založené do
  té chvíle v systému zůstávají a report řekne, kolik dokladů zůstalo
  nezpracovaných. Tutéž dávku můžete nahrát znovu, hotové doklady se přeskočí
  jako duplicitní.

Stránku můžete zavřít, import doběhne i bez ní. Report se zobrazí po dokončení.

> [!TIP]
> Zastavení ani zavření stránky nepřeruší závěrečné kroky importu: dorovnání
> číselných řad a přepočet statistik klientů proběhnou nad tím, co se stihlo.
> Bez toho by seznam klientů ukazoval stará čísla a další vystavená faktura by
> dostala číslo, které v importu už je.

### 21.9.2 Naimportované doklady nejsou zaúčtované

**Import do deníku neúčtuje.** Doklady vznikají nezaúčtované, a to i když máte
zapnutou plnou automatiku účtování: automatika je háček na **vznik** dokladu
(vystavení faktury, přijetí přijaté faktury, opakovaná fakturace), ne zametač
dokladů, které už v systému leží.

Do deníku je dostanete v `Účetnictví → Doúčtovat doklady`. Úloha projde všechny
nezaúčtované faktury najednou, běží na pozadí a jde zastavit; každý doklad se
účtuje samostatně, takže jeden vadný dávku nezastaví. Hromadné zaúčtování
z výběru v seznamu faktur zůstává pro menší zásahy, má strop 500 dokladů na
dávku.

### 21.9.3 Co se založí

Pro každou fakturu v souboru:

| Entita | Logika |
|---|---|
| **Klient** | Vyhledání podle IČO. Pokud neexistuje, načte se adresa z **ARES** (preferenčně), záložně z adresy v XML. Vznikne nový klient. |
| **Zakázka** | Má-li faktura číslo zakázky (ISDOC `OrderReference/ID`, Pohoda `numberOrder`), přiřadí se k zakázce s tím číslem (vytvoří se, pokud chybí). Nemá-li číslo zakázky, ale klient má v importovaném balíku **více různých e-mailů**, vytvoří se zakázka za každý e-mail s názvem `{Firma} - {e-mail}`. Jinak `bez zakázky`. |
| **Faktura** | Uloží se se zachovaným původním variabilním symbolem. Položky, sazby DPH, kurz a měna se převezmou. Snapshoty klienta, dodavatele a banky se zafixují z aktuálních dat. |

### 21.9.4 Stav vydaných faktur: pravidlo 30 dní

Aby se nemusel po importu řešit starý stav:

- **Datum splatnosti starší než 30 dní** - faktura se uloží jako **Zaplacená**
  (datum úhrady = DUZP nebo datum vystavení). Předpoklad: starý doklad už dávno
  zaplacený.
- **Datum splatnosti v posledních 30 dnech nebo v budoucnu** - faktura se uloží
  jako **Vystavená**. Platbu spárujete standardně přes bankovní výpis, nebo
  fakturu ručně označíte jako zaplacenou.
- **Daňový doklad k přijaté platbě** je vždy **Zaplacený** (ke dni přijetí
  platby) a nic se na něm nedoplácí. Dokumentuje platbu, která už přišla.
- **Faktura, která odečítá zálohy**, nebo jejíž součet položek se od celkové
  částky či částky k úhradě ze souboru liší o víc než 1 Kč, se uloží jako
  **Koncept** s varováním v reportu. Odpočet zálohy je v souboru mimo položky,
  takže by vystavená faktura zaevidovala tržbu i DPH v plné výši a platba
  zdaněná dokladem k záloze by se zdanila podruhé. Koncept nejde do DPH ani do
  pohledávek: navažte ho na daňový doklad k záloze, zkontrolujte a vystavte.

### 21.9.5 Co se přeskočí

- **Cizí dodavatel** - celý soubor se přeskočí, pokud IČO dodavatele v souboru
  neodpovídá aktuální firmě. V reportu je hláška.
- **Duplicita** - pokud doklad **stejného druhu** s daným variabilním symbolem
  u této firmy už existuje, přeskočí se. V reportu se zobrazí důvod a odkaz na
  existující fakturu.
- **Doklad bez položek** - doklad, který v souboru nemá jedinou položku, se
  **nezaloží** a v reportu skončí jako chyba. Vznikl by doklad na nulu, který
  v seznamu vypadá jako naimportovaný, ale do žádného výkazu nepřispěje.
  Doplňte položky ve zdrojovém systému a doklad naimportujte znovu.

### 21.9.6 Dobropis se stejným variabilním symbolem jako faktura

Většina systémů vystavuje opravný daňový doklad (dobropis) s **týmž variabilním
symbolem**, jaký má opravovaná faktura, aby vratka odešla na stejný symbol.
Dva doklady pod jedním symbolem ale u jedné firmy vést nejde, variabilní symbol
je jediné, podle čeho se páruje platba z banky.

Import to řeší takto:

- Dobropis se naimportuje pod **variabilním symbolem odvozeným z čísla dokladu**
  (např. `D262200015`). V reportu je o tom poznámka. Pod symbolem ze souboru
  doklad v aplikaci **nedohledáte** a platba se na něj sama nenapáruje.
- Vazba na opravovaný doklad se přesto zachová: dobropis se naváže na původní
  fakturu (v detailu dokladu je vidět odkaz), pokud je faktura u téhož
  odběratele v systému. Když tam není, řekne to poznámka v reportu a vazbu
  doplníte ručně.
- Když se symbol z čísla dokladu odvodit nedá (soubor číslo neuvádí) nebo je
  i ten obsazený, doklad se přeskočí s hláškou, ať mu zadáte jiný variabilní
  symbol a naimportujete ho znovu.

### 21.9.7 Zahraniční doklady a režim OSS

Import vydaných faktur umí sám poznat plnění v [režimu OSS](45_OSS.md) a vyplnit
na položce příznak OSS, zemi spotřeby, typ sazby i typ plnění. Nemusíte je
proklikávat ručně.

#### 21.9.7.1 Než spustíte import

1. **Spusťte databázové migrace** (`php api/bin/migrate.php`). Bez číselníku
   [sazeb států OSS](96_Nastaveni.md#96161-ciselniky-podrobnosti) se import
   zahraničních dokladů **vůbec nerozběhne**: raději neudělá nic, než aby
   doklady zařadil naslepo.
2. **Zkontrolujte zemi u zahraničních sazeb** v
   `Nastavení → Číselníky → DPH sazby`. Formulář zemi předvyplňuje na `CZ`, takže
   sazba `PL-23` bývá založená se zemí `CZ`. Import ji v takovém stavu nepřijme.
3. **Zapněte OSS** na kartě `Nastavení → Daně a účetnictví → Režim OSS (One Stop
   Shop)` a vyplňte platnost registrace. Doklady s datem plnění před začátkem
   registrace zůstanou tuzemské, což je správně.

#### 21.9.7.2 Jak se import rozhoduje

Rozhodovací pravidlo je společné všem vstupním kanálům a popisuje ho
[§ 43.3](45_OSS.md#45108-jak-vznika-oss-radek). Ve zkratce: **autoritou pro místo
plnění je číselník sazeb států OSS, ne vaše tabulka DPH sazeb**, a do tuzemského
přiznání smí jen řádek, u kterého číselník potvrdí, že sazba v zemi dodavatele
k datu plnění opravdu platí. Každá jiná odpověď znamená buď zařazení do OSS,
nebo odmítnutí dokladu s hláškou, co doplnit.

Specifika importu ze souboru:

- **Procento sazby se nikdy nedosazuje odhadem.** Bere se v tomto pořadí:
  hodnota `percentVAT` z Pohoda XML nebo `Percent` z ISDOC, číselná hodnota
  v atributu sazby, dopočet z rekapitulace v témže souboru (daň ÷ základ),
  a teprve u tuzemského odběratele převod slovního označení sazby ("základní",
  "snížená") na sazbu platnou k datu plnění. Když ani to nevyjde, je sazba
  neznámá a rozhodne pravidlo výše.
- **Sazba se páruje na zemi a platnost k datu**, ne na nejbližší procento. Když
  se nenajde, odmítne se **celý doklad**, protože doklad s vynechaným řádkem má
  špatné součty. Hláška řekne, u které sazby a na jaký stát opravit zemi.
- **Země spotřeby se bere z odběratele na importovaném dokladu**, ne z uložené
  karty klienta a ne z měny. Doklad v eurech pro slovenského odběratele jde do SK.
- **Odmítnutý řádek znamená, že se doklad nevytvoří.** Zdroj pravdy je
  v souboru, takže po opravě stačí import zopakovat, už naimportované doklady se
  přeskočí jako duplicity.
- **Nejednoznačnou sazbu import zařadí do OSS** a označí k ručnímu posouzení, ne
  naopak. Proč právě tímto směrem, vysvětluje
  [§ 43.4.1](45_OSS.md#45109-plneni-k-rucnimu-posouzeni).
- **Doklad, který se rozpadne** mezi OSS podání a tuzemské přiznání, se
  neodmítá (smíšená faktura umí vzniknout legitimně), ale hlásí se zvlášť
  a jeho řádky se označí k posouzení.
- **Původní období u dobropisů import nedoplňuje** (v souboru není z čeho ho
  poznat) a na každý takový doklad upozorní. Dokud období nedoplníte (`RRRRQn`
  v editoru položky), vykáže se oprava do běžného čtvrtletí.

#### 21.9.7.3 Co po importu zkontrolovat

1. **Typ plnění zboží nebo služba** u položek, kde soubor jednotku neuvedl.
   Dosadí se výchozí "služba" a v podání to vyjde jako `S`, kdežto u zboží tam
   patří `G`.
2. **Řádky k ručnímu posouzení.**
3. **OSS řádky bez typu sazby**, protože bez typu sazby se řádek do podání
   nedostane.
4. **Náhled OSS podání** před stažením XML, poslední místo, kde se chyba dá
   chytit.

Kolik čeho vzniklo, říká souhrn importu ([§ 21.9.8](#2198-report)); souhrn ale
po zavření stránky zmizí, kdežto filtr **Místo plnění (OSS)** v seznamu faktur
ne. Všechny tři první body má [hromadná úprava OSS](45_OSS.md#456-krok-za-krokem-hromadna-uprava-oss)
jako samostatný výběr položek, nemusíte je hledat po jednom.

> [!TIP]
> Doklady s prázdným příznakem OSS vyžadují kontrolu, protože jejich zahraniční
> daň může být vykázaná v českém přiznání. Než podáte přiznání za období, do
> kterého import spadl, projděte zahraniční doklady v tom období a ověřte, že
> v přiznání k DPH nefigurují.

### 21.9.8 Report

Po importu vidíte tabulku:

| Sloupec | Význam |
|---|---|
| **Soubor** | Cesta v balíku (název ZIPu nebo interní cesta) |
| **Stav** | **vytvořeno** / **už existoval** / **přeskočeno** / **chyba** |
| **Var. symbol** | Z faktury |
| **Detail** | Odkaz na vytvořenou fakturu, štítek **uloženo jako zaplacené** nebo **uloženo jako vystavené**, štítky **+ klient** a **+ zakázka** (pokud něco vzniklo). U přeskočených a chybných důvod. |

Doklad může projít a přesto mít poznámku, typicky když se **nahradil variabilní
symbol** ([§ 21.9.6](#2196-dobropis-se-stejnym-variabilnim-symbolem-jako-faktura))
nebo když se **odvodilo něco, co v souboru nebylo**. Poznámka se u dokladu
objeví jen jednou, i když se týká víc položek, aby dvacetipoložková faktura
nevyrobila dvacet stejných vět. Zobrazí ji odkaz **Poznámky**.

Nad tabulkou je **Souhrn importu** za celý běh. U zahraničních dokladů v něm
najdete:

| Údaj | Význam |
|---|---|
| **položek v režimu OSS** | Kolik řádků se zařadilo do OSS |
| **položek bez typu sazby OSS** | Řádky, které se do podání nedostanou, dokud typ sazby nedoplníte |
| **položek k ručnímu posouzení** | Řádky s nejistým místem plnění (viz [§ 21.9.7.2](#21972-jak-se-import-rozhoduje)) |
| **dobropisů bez období opravy** | Opravné doklady, kterým chybí původní OSS čtvrtletí |
| **dokladů s nahrazeným variabilním symbolem** | Kolik dokladů dostalo symbol odvozený z čísla dokladu |
| **dokladů s varováním** | Kolik dokladů prošlo, ale nese poznámku ke kontrole |

Jednotlivé doklady mají v seznamu odpovídající štítky (**OSS: n**, **neurčený
typ sazby**, **k ručnímu posouzení**, **dobropis bez období opravy**, **VS
nahrazen**), takže se dá z tisícovky řádků rychle vyfiltrovat to, co potřebuje
pozornost (přepínač **Jen problémové**). Souhrn existuje právě proto, aby se při
tisícovce dokladů dalo přečíst jedno číslo místo tisícovky hlášek.

### 21.9.9 PDF/A-3 a ISDOCX import

Většina českých fakturačních systémů (**iDoklad**, **Fakturoid**,
**Superfaktura**, **Pohoda**, **MyÚčto**) dnes vkládá ISDOC XML přímo do PDF
dokumentu jako přílohu (standard **PDF/A-3** a ISDOC). Máte-li v ruce jen PDF
faktury, typicky to, co přišlo e-mailem od dodavatele, můžete ho importovat
přímo. MyÚčto z něj vytáhne vloženou přílohu `*.isdoc` a importuje stejně, jako
kdybyste nahráli samostatný `.isdoc` soubor.

**ISDOCX balíček (ISDOC Package).** Některé systémy fakturu nevkládají do PDF,
ale balí strukturovaný ISDOC i čitelné PDF do jednoho **ZIP archivu s příponou
`.isdocx`** (uvnitř `manifest.xml`, vlastní `*.isdoc` a `*.pdf`). MyÚčto takový
balíček **rozbalí**, vytáhne z něj ISDOC a naimportuje ho stejně jako samostatný
`.isdoc`, **deterministicky, zdarma a bez AI**, a čitelné PDF z balíčku navíc
archivuje pro náhled v detailu faktury. Funguje to jak při nahrání samotného
`.isdocx`, tak když je `.isdocx` přílohou uvnitř PDF/A-3. Hlavní ISDOC se
v balíčku určí podle `manifest.xml` (`<maindocument>`), s fallbackem na `.isdoc`
v kořeni archivu (balíčky bez manifestu).

**Jak poznáte, jestli PDF má vložený ISDOC:**

- Otevřete PDF v jakémkoli prohlížeči a klikněte na ikonu **přílohy** (sponka).
  Pokud uvidíte soubor typu `*.isdoc` (často `invoice.isdoc`, ale třeba iDoklad
  ho pojmenuje `Vydaná faktura - 20230005-invoice.isdoc`), je to ono.
- V Adobe Readeru najdete přílohu v levém panelu pod ikonou kancelářské sponky.
- Zjistíte to také příkazem `pdfdetach -list <soubor>.pdf` (z balíku
  `poppler-utils`).

**Co když PDF přílohu nemá?** Pak ho **nelze automaticky importovat**: běžné
PDF nemá strukturovaná data faktury, jen vizuální layout. Import vrátí čitelnou
chybu "PDF neobsahuje ISDOC přílohu". V tom případě buď ve zdrojovém systému
(iDoklad, Pohoda ...) **stáhněte XML nebo ISDOC samostatně** a importujte ten
soubor, nebo fakturu zadejte ručně.

**Co se podporuje:**

- PDF/A-3 s vloženým souborem, jehož název končí `.isdoc` (oficiální specifikace
  ISDOC PDF).
- PDF s vloženým ISDOC pod jiným jménem (rozpoznání podle jmenného prostoru
  ISDOC `http://isdoc.cz/namespace/2013`).
- **ISDOCX balíček** (`.isdocx` ZIP s `manifest.xml`, `.isdoc` a PDF) jako
  samostatný soubor i jako příloha PDF/A-3.
- PDF s *compressed object streams* (PDF 1.5+). Specifikace sice ObjStm zavedla,
  ale stream objekty (a tím i vložený soubor) v ObjStm být nesmí, vždy zůstávají
  na nejvyšší úrovni, takže je extraktor najde i v takových PDF.

**Limity:**

- **Šifrované PDF** (heslem nebo certifikátem). Stream byty jsou zašifrované,
  extraktor je neumí dekódovat. Otevřete PDF v Adobe Readeru, zadejte heslo,
  uložte znovu bez šifrování a nahrajte.
- **Jiný filtr streamu než FlateDecode** (LZW, RunLengthDecode, ASCII85 bez
  následného Flate). Extraktor zvládá jen FlateDecode, tedy drtivou většinu
  běžných PDF. U producentů používajících jiné filtry můžete narazit.
- **Vícestupňový řetěz filtrů** (`/Filter [/ASCII85Decode /FlateDecode]`).
  Vzácné, ale existuje. Řešení: stáhněte si ISDOC samostatně ve zdrojovém systému.

### 21.9.10 Doporučení k souborovému importu

- **Pohoda do MyÚčta:** exportujte v Pohodě datový balíček (XML) a nahrajte ho.
  Pohoda neukládá číslo zakázky na fakturu, takže se importují bez zakázky
  (pokud klient nemá více e-mailů, viz [§ 21.9.3](#2193-co-se-zalozi)).
- **Více firem:** přepněte se na cílovou firmu před spuštěním importu. IČO
  z XML se ověří proti této firmě.
- **Při chybě importu** zkontrolujte soubor v textovém editoru, zda je validní
  XML s očekávaným kořenovým elementem (`<dat:dataPack>` pro Pohodu, `<Invoice>`
  v jmenném prostoru ISDOC pro ISDOC). U PDF ověřte, zda má vloženou přílohu
  `.isdoc` (viz [§ 21.9.9](#2199-pdfa-3-a-isdocx-import)).

### 21.9.11 API import z iDokladu

Alternativa k nahrání souboru: přímé volání iDoklad API v3 (OAuth2 Client
Credentials). Vhodné pro většinu dat: táhne **kontakty, vystavené faktury,
dobropisy, přijaté faktury, přijaté účtenky a bankovní pohyby** najednou, po
sekcích a rocích, s náhledem (dry-run) a úlohou na pozadí.

Client Secret se ukládá šifrovaně (AES-256-GCM) pro každou firmu zvlášť.

#### 21.9.11.1 Co se importuje

<!-- cols: 26 74 -->
| Sekce | Co se vytvoří |
|---|---|
| **Kontakty** | Klienti (IČO, název, adresa, DIČ, e-mail, telefon). ARES se nevolá, důvěřuje se datům z iDokladu. |
| **Vydané faktury** | Faktury s položkami a klasifikací DPH. Stav viz [§ 21.9.11.3](#219113-platebni-stav). |
| **Dobropisy** | Dobropisy navázané na původní fakturu. |
| **Přijaté faktury** | Přijaté faktury s položkami, dodavatel se založí jako klient s rolí dodavatele. |
| **Přijaté účtenky a paragony** | Přijaté faktury druhu účtenka. Účtenka nemá splatnost ani DUZP, proto datum vystavení = DUZP = splatnost. Hrazená na místě se importuje rovnou jako **Zaplacená**. Hotovostní účtenka **bez dodavatele** viz [§ 21.9.11.3](#219113-platebni-stav). |
| **Bankovní účty** a **Bankovní pohyby** | Výpisy a transakce se zachováním firmy, mapováním účtů a deduplikací proti GPC, PDF a e-mailovým avízům. |

Volba **Bankovní účty** synchronizuje číselník účtů z iDokladu a mapuje jej jen
na aktivní účty stejné měny v MyÚčtu. Přesná a jednoznačná shoda se propojí;
neznámý nebo nejednoznačný účet zůstane ke kontrole. Synchronizace automaticky
nezakládá ani nepřepisuje místní bankovní účet.

Volitelná sekce **Bankovní pohyby** se načítá až po synchronizaci dokladů.
Importuje se pouze účet s jednoznačným mapováním. Stabilní ID pohybu z iDokladu
brání duplicitám a vazba na konkrétní doklad z iDokladu má přednost před obecným
párováním podle variabilního symbolu. Volba **Pouze změny od posledního importu**
používá ID posledního uloženého pohybu; dry-run ukáže také přímé shody, ale
platby nezapisuje.

GPC nebo PDF výpis z banky je autoritativnější zdroj. Když stejná platba přijde
i z iDokladu nebo e-mailového avíza, sekundární záznam se při jednoznačné shodě
označí jako ignorovaný, aby nevznikla dvojí úhrada.

U vydané faktury se bankovní účet přebírá z historických údajů `MyAddress`
konkrétního dokladu. Výchozí účet měny se použije jen tehdy, když doklad účet
neobsahuje nebo jej nelze jednoznačně spojit s aktivním účtem v MyÚčtu.

Číslo vydané faktury i dobropisu se bere z iDokladového čísla dokladu
(`DocumentNumber`). Liší-li se od něj platební variabilní symbol
(`VariableSymbol`), uloží se jako **platební variabilní symbol** faktury
([§ 15.2.5](15_Faktura_editor.md)). PDF, QR platba i párování plateb pak
používají VS, pod kterým klient skutečně platí. Stejně se převezme `symVar`
z Pohody při migraci vydaných dokladů.

#### 21.9.11.2 Idempotence

Každý přenesený záznam si pamatuje své ID ze zdrojového systému. Druhý import
téhož období záznamy **přeskočí** (žádné duplicity, žádná aktualizace
existujících). Import je čistě aditivní.

#### 21.9.11.3 Platební stav

API import přebírá **skutečný platební stav ze zdrojového systému**, na rozdíl
od souborového importu ([§ 21.9.4](#2194-stav-vydanych-faktur-pravidlo-30-dni)),
kde se stáří jen odhaduje pravidlem 30 dní:

- Doklad v iDokladu **Uhrazeno / Přeplaceno** se importuje jako **Zaplacená**
  (datum úhrady z iDokladu; nepošle se na ni upomínka).
- Vše ostatní (neuhrazeno, částečně uhrazeno) se importuje jako **Koncept**.
  Doklady si zkontrolujete a vystavíte sami, záměrně se automaticky nevystavují,
  aby na reálně nezaplacené historické faktury nezačaly klientům odcházet
  upomínky.
- Totéž platí pro **přijaté faktury** (uhrazeno je Zaplacená, jinak Koncept).
- **Přijaté účtenky a paragony** jsou hrazené na místě, importují se rovnou jako
  **Zaplacená** (datum úhrady = datum vystavení), pokud iDoklad nevrátí jiný stav.

**Hotovostní účtenka bez dodavatele.** Účtenka bez navázaného kontaktu (typicky
hotovostní nákup) se **nezahazuje**: náklad se navěsí na sběrného systémového
dodavatele **"Hotovostní nákup (účtenka)"** (jeden na firmu, založí se
automaticky jako neplátce). Protože dodavatele ani jeho plátcovství DPH nelze
u anonymní účtenky ověřit, importuje se **bez nároku na odpočet DPH** a doklad
dostane upozornění "Účtenka bez identifikace dodavatele...". Chcete-li si odpočet
uplatnit, otevřete doklad, **doplňte skutečného dodavatele a přepněte odpočet**
na plný. Pro neplátce DPH je tohle bez dopadu, účtenka je jen daňový náklad.

**Sleva.** Sleva z iDokladu se přenáší: sleva na úrovni dokladu
(`DiscountType=OnDocument`) se u vydaných faktur uloží jako procentuální sleva
(viz § 10.4.1), u přijatých jako záporná položka "Sleva X %" po sazbách DPH;
položková sleva se zapečetí do jednotkové ceny. Importovaná částka tak odpovídá
iDokladu.

### 21.9.12 API import z Fakturoidu

Stejný postup jako iDoklad, jiný zdroj. Podporují se dvě metody ověření: e-mail
s osobním API tokenem i OAuth2 Client Credentials.

#### 21.9.12.1 Ověření

Přepínač typu ověření:

| Typ | Pole |
|---|---|
| **OAuth2 (nové účty)** (doporučená metoda) | Slug + Client ID + Client Secret |
| **E-mail + API token (starší účty)** | Slug + E-mail + API token |

Oba způsoby mohou existovat vedle sebe pro každou firmu. Pokud má firma
vyplněné oba bloky, **OAuth2 má prioritu** (Bearer token).

OAuth2 token MyÚčto uchovává šifrovaně (AES-256-GCM) s platností asi 2 hodiny.
Při chybě 401 se token vyřadí a automaticky obnoví, nemusíte nic dělat.

#### 21.9.12.2 Co se importuje

| Sekce | Co se vytvoří |
|---|---|
| **Subjekty (kontakty)** | Klienti |
| **Vydané faktury** | Faktury s položkami a DPH klasifikací |
| **Dobropisy** | Dobropisy |
| **Přijaté** (Fakturoid "expenses") | Přijaté faktury |

**Platební stav.** Stejně jako u iDokladu se přebírá skutečný stav z Fakturoidu:
doklad **Zaplaceno** se importuje jako Zaplacená (datum úhrady `paid_on`),
**Stornováno** jako Stornovaná; vše ostatní (včetně částečných úhrad) zůstává
Koncept k ručnímu vystavení.

Fakturoid stránkuje po 40 záznamech. MyÚčto automaticky načte všechny stránky,
tedy doklady všech let; s volbou **Pouze změny od posledního importu** jen
záznamy změněné od posledního běhu. Idempotence funguje stejně jako u iDokladu (ID ze zdrojového
systému).

**Napojení na existující karty.** Import subjektů z Fakturoidu i z iDokladu
nejdřív hledá kartu, kterou už ve firmě máte: podle IČO, u subjektu bez IČO podle
DIČ. Najde-li ji, subjekt na ni jen napojí (karta dostane externí ID a případně
chybějící roli klient nebo dodavatel) a novou nezakládá. Stejná přijatá faktura,
která přišla jinou cestou (ruční zadání, AI vytěžení, převod z jiného programu),
se tak nezaeviduje podruhé. Existující karta se nepřepisuje. Má-li karta už jiné
externí ID téhož zdroje, založí se karta nová.

#### 21.9.12.3 Ceny, zaokrouhlení a výsledek importu z Fakturoidu

Import přebírá ceny tak, jak jsou ve Fakturoidu. U dokladů s cenami včetně DPH
se daň počítá shora, takže 1 210 Kč včetně 21 % dá základ 1 000 Kč a DPH 210 Kč.
U přijatých faktur se převezme zaokrouhlení a rekapitulace DPH dokladu; haléřový
rozdíl se srovná ruční rekapitulací DPH. Liší-li se částky po přenosu od
Fakturoidu víc, než vysvětlí zaokrouhlení, doklad se označí ke kontrole. Doklady
bez DPH (neplátci, nulová sazba) se přenášejí beze změny.

Po doběhnutí ukáže úloha počty přenesených, přeskočených, nepřenesených
a ke kontrole označených dokladů po agendách a seznam dokladů s důvodem
a návodem. Úloha s chybou končí stavem **Dokončeno s chybami**. Doklad se sazbou
DPH, která není v číselníku, se nepřenese a jiná sazba se za ni nedosadí; po
doplnění sazby stačí import spustit znovu.

Zkouška nanečisto projde stejné kontroly jako ostrý import (subjekty, sazby DPH,
přepočet a shoda částek s Fakturoidem), nic nezapíše a vypíše, co by import
udělal.

Doklady převzaté dřívější verzí opraví příkaz
`php api/bin/fix-fakturoid-imported-amounts.php --supplier-id=N`. Bez `--apply`
jen vypíše stav před a po. Zaúčtované a zamčené doklady, doklady v období
s podaným přiznáním nebo hlášením k DPH a doklady s úhradou jen nahlásí ke
kontrole.

### 21.9.13 Importy z API: průběh a pravidla klasifikace DPH

**Dry-run (společný pro iDoklad i Fakturoid).** Po zaškrtnutí **Dry-run (jen
ukázat, nepsat)** import nezapisuje nic do databáze. Slouží k ověření přístupových
údajů a náhledu dat. Příklad výstupu:

```
[contacts]    Nalezeno 45 kontaktů - 40 by se vytvořilo, 5 přeskočeno (duplicita)
[invoices]    Nalezeno 120 faktur - 115 nových, 5 přeskočeno (varsymbol existuje)
[purchases]   Nalezeno 30 přijatých faktur - 30 nových
```

Vypadá-li výstup rozumně, odškrtněte dry-run a spusťte ostrý import.

**Úloha na pozadí (ostrý import).** Ostrý import běží jako proces na pozadí
(`api/bin/import-worker.php`). Aplikace okamžitě vrátí číslo úlohy a obrazovka
sleduje průběh:

1. ukazatel průběhu se obnovuje každé 2 sekundy,
2. podrobný log každého záznamu (sekce, akce, ID nebo důvod přeskočení),
3. **Zrušit** - proces bezpečně dokončí aktuální dávku a zastaví se. Stav se
   nastaví na **Zrušeno**.

**Prevence duplicitních úloh:** stejné parametry (zdroj a sekce) nelze
spustit znovu, dokud běží; aplikace odkáže na běžící úlohu.

**Chybějící DPH klasifikace.** Pokud zdrojový systém nemá u položek členění DPH,
MyÚčto použije výchozí podle sazby: 21 % je `1` (prodej) nebo `40` (nákup),
12 % je `2` nebo `41`. Vystavený řádek s 0 % vyžaduje výslovnou klasifikaci
(např. osvobození, vývoz nebo plnění mimo předmět daně); systém jej automaticky
nezařadí na ř. 50. U přijatého řádku bez nároku zůstává výchozí kód `42`.

**Restart po zaseknutí.** Procesy na pozadí nejsou hlídané. Po restartu
aplikačního kontejneru (`docker compose restart app`) tiše spadnou a import je
třeba spustit znovu.

### 21.9.14 Import přijatých faktur: pravidla a scan inbox

Import přijatých je oddělený od importu vydaných. Přijímá Pohoda XML, ISDOC,
ISDOCX, PDF/A-3 s vloženým ISDOC a ZIP balíky; lze vybrat více souborů najednou.
Server u této stránky vždy použije směr **přijatá faktura** a ověří, že IČO
odběratele ve vstupním dokladu odpovídá aktuálně zvolené firmě. Doklad s cizím
odběratelem odmítne, i kdyby byl jinak syntakticky platný.

Pro každý platný doklad systém:

1. vyhledá nebo založí dodavatele (podle ARES a IČO),
2. vytvoří **přijatou fakturu** a její položky,
3. u ISDOCX nebo PDF/A-3 uloží čitelný PDF originál k faktuře,
4. odděleně zaarchivuje původní strojový artefakt ISDOC, ISDOCX nebo Pohoda XML;
   u vícedokladového souboru (export z Pohody nese celou agendu najednou) se
   archivuje **úsek právě tohoto dokladu**, ne celý soubor,
5. vrátí report **Vytvořeno / Přeskočeno / Chyba** s odkazem na nový doklad.

**Stav zakládaných dokladů.** Doklad ze strukturovaného souboru je úplný, takže
vzniká rovnou jako **přijatý**. Koncept se nezapočítává do nákladů, závazků ani
do výkazů, takže po migraci z jiného systému by firma vypadala, že žádné náklady
nemá, a účetní by musela stovky dokladů otevřít jednu po druhé. Zaškrtávátko
**Založit jako koncept** je pro dávku, kterou chcete ještě projít, než ji
pustíte do výkazů.

**Datum přijetí** se přebírá z dokladu: **datum vystavení**, a když ho doklad
nenese, DUZP. Nikdy se nepoužije datum v budoucnosti. Stejné pravidlo platí pro
**všechny** formáty importu, tedy i pro AI import z PDF, iDoklad, Fakturoid a scan
inbox. Nemá-li doklad čitelné vůbec žádné datum, zbyde den importu a doklad
dostane žluté upozornění, ať si údaj zkontrolujete.

Chcete-li mít jako datum přijetí den importu, přepněte
v `Firma → Nastavení`, záložka **Fakturace**, volbu **Datum přijetí
u importovaných přijatých dokladů** na "Den importu". Volba je nastavením firmy,
takže se pamatuje a nemusíte ji klikat u každé dávky.

Na období nároku na odpočet to vliv nemá. To se u importovaného dokladu řídí
DUZP a datem vystavení (§ 73), protože datum přijetí není vědomé zadání účetní.

Strukturovaný import nepoužívá AI. PDF bez vloženého ISDOC proto patří do
`Nákup → AI import`, případně je lze zpracovat přes scan inbox. Importovaný
doklad před zaúčtováním vždy otevřete a zkontrolujte dodavatele, období, DUZP,
částky, DPH klasifikaci a nárok na odpočet.

**Scan inbox.** Na stránce `Nákup → Import` je také ruční spuštění **scan
inboxu**. Ten projde nakonfigurovaný adresář, použije ISDOC přednostně
a u nestrukturovaného PDF může přejít na nastavenou AI bránu. Spustíte ho
tlačítkem **Spustit scan**. Volba **Zkušební běh (bez zápisu)** vrátí report bez
vytvoření dokladů. Nezpracované a chybné soubory zůstávají v samostatném seznamu
s důvodem, aby se neztratily v souhrnných počtech. Adresář se nastavuje v
konfiguraci instalace; u spravované instalace ho nastavuje provozovatel a scan
nejde spustit z aplikace.

**Limity.** Platí stejné limity jako u ostatních importů: nejvýše 5000 souborů,
40 MiB na soubor a 128 MiB na dávku (viz [§ 21.9.1](#2191-limity-nahravani-a-beh-na-pozadi)); právě velké dávky jsou důvod, proč import běží na pozadí. Zápis
vyžaduje oprávnění k importu; všechny výsledky jsou omezené na aktuální firmu.
Souběžně běží nejvýše jeden import na firmu; pokus o druhý skončí odkazem na ten
běžící.

### 21.9.15 Převod ze Stereo NX: rozsah a pravidla

Průvodce používá stejné čtyři kroky jako převody POHODA a PREMIER: nahrání
zálohy, výběr firmy, zkoušku nanečisto a potvrzení importu. Rozsah převodu se
řídí účetním režimem cílové firmy. U prázdného či nejednoznačného deníku import
režim neodhaduje. Označení "EU" bez konkrétního státu volbou pro prázdnou zemi
protistrany určeno není. Vyplněné firemní údaje ze zálohy nejsou předem označené.
Převzetí údajů používá oprávnění ke správě firmy a při souběžné změně vyžádá nový
náhled. IČO, režim účetnictví a plátcovství se tím nemění.

Zkouška i převod běží na pozadí, průvodce průběžně ukazuje stav a druhý převod
téže firmy se nespustí, dokud první neskončí. Název ZIPu nemusí odpovídat všem
rokům obsaženým v záloze. Smaže se pouze nahraná kopie ZIPu po potvrzeném zápisu
dokladů; zkouška nanečisto ani neúspěšný převod ji nemažou. Pokud odstranění
selže, import zůstává dokončený a zálohu lze odstranit ze seznamu. Převod zálohu
pouze čte, původní data ani instalaci Stereo NX nemění.

#### 21.9.15.1 Daňová evidence

Převádí se adresář, vydané faktury s položkami, přijaté faktury, bankovní výpisy
a pohyby, pokladní pohyby, vazby úhrad a zařazení do peněžního deníku. Peněžní
deník ve zdroji slouží ke kontrole fyzických pohybů, nevytváří druhou sadu plateb.
Přijaté faktury bez položek dostanou položky podle uložené rekapitulace sazeb DPH
s původním popisem. Samovyměřená daň se nepřičítá k částce splatné dodavateli.

Doklady s neurčenými příznaky DPH nebo cen, neurčenou protistranou či zemí,
nesouladem kontrolní evidence nebo neověřeným datem se převezmou jako
**koncepty**. Také vydaný doklad bez položek vyžaduje ruční kontrolu. Společný
protokol převodu uvádí číslo každého dokladu k ruční kontrole a jeho konkrétní
důvody. Důvody zůstávají také v poznámce dokladu. Koncepty nevstupují do přiznání
DPH; jejich původní úhrady se uchovají. Před potvrzením konceptu ověřte částky,
režim cen, členění DPH, datum a údaje protistrany.

Převod kontroluje uzavřená a již naplněná cílová období. Již převedené záznamy
rozpozná podle zdrojových klíčů; opakování stejné zálohy je nezdvojí. Změněný
nebo chybějící dříve převedený záznam vyžaduje kontrolu, automaticky se
nepřepisuje. Chyba během převodu vrátí celý aktuální zápis zpět.

Podporovaný rozsah je domácí evidence v CZK. Cizoměnové doklady, odpočty záloh,
nepodporované druhy dokladů, přijaté faktury s vlastními položkami nebo naplněné
neověřené agendy převod zastaví s vysvětlením. Majetek, mzdy a sklad vyžadují
samostatný převod.

#### 21.9.15.2 Podvojné účetnictví

Převádí se účtový rozvrh a účetní deník včetně počátečních zápisů a červeného
storna. Záporné kontace zůstávají na původních stranách MD/Dal, takže snižují
obraty. Období určuje datum účetního případu; případný rozpor se samostatným
zdrojovým rokem se objeví v protokolu.

Vedle deníku se přebírají adresář, vydané a přijaté faktury, ověřené bankovní
výpisy a pohyby a pokladní doklady. Doklady se propojí s jejich převzatými
kontacemi, aby se nevytvořilo duplicitní zaúčtování. Úhrady se párují jen při
doložené vazbě a shodě částek a směru platby. Pohyby bez ověřené vazby zůstávají
ke kontrole. Bankovní pohyb bez doložené kontace zůstává ignorovaný, pokladní
doklad konceptem. Před obnovením automatického účtování ověřte vazbu na převzatý
deník, aby se zápis nevytvořil podruhé. Samostatné pokladní pohyby s výslovně
vypnutým DPH, nulovým daňovým rozpisem a bez odkazu na jiný doklad nevyžadují
kontrolu, pokud jejich částka, směr a datum souhlasí s převzatými pokladními
kontacemi. Vydané dobropisy a proformy se přebírají v odpovídajícím druhu
dokladu. Zdrojové zálohové faktury se převádějí jako vydané proformy nebo přijaté
zálohové doklady a samy nevstupují do evidence DPH. Chybějící datum DPH u takového
nedaňového dokladu samo o sobě nevyžaduje kontrolu. Původní položky přijatých
faktur se zachovají při úplné shodě s rekapitulací DPH. Neověřené typy, vazby
záloh a daňové členění zůstávají konceptem s konkrétním důvodem kontroly; nejde
automaticky o stornované doklady.

Cizoměnové faktury s doloženou měnou a kurzem se převezmou; neověřené členění DPH
zůstává konceptem. Potřebné měny musí existovat v Nastavení měn cílové firmy.
Zdrojový účetní deník neobsahuje cizoměnové částky, proto se přebírá v CZK. Před
kurzovým přeceněním zkontrolujte a doplňte cizoměnové zůstatky. Bankovní pohyby
bez doloženého kódu měny se nepřevádějí. Cizoměnový doklad se převádí jen při
shodě položek v měně, zdrojového kurzu a korunových částek. Neověřené doklady se
vypíšou jako nepřevedené; jejich zdrojové kontace zůstávají v účetním deníku.
Neověřené měnové vazby úhrad se automaticky nepárují.

Převod zahrnuje také podporované karty dlouhodobého a drobného majetku,
historické odpisy, karty zaměstnanců a pracovní vztahy, sklady, skladové karty
a vozidla. Tyto evidence nevytvářejí další účetní zápisy vedle převzatého
deníku. Dostupnost některých evidencí závisí na zapnutých modulech cílové firmy.

Ověřené historické mzdy standardního hlavního pracovního poměru se přebírají do
`Mzdy → Importy → Převzaté mzdy` se zdrojem Stereo NX. Přenáší se měsíční hrubá
a čistá mzda, základy a pojistné, zálohová daň, odpracovaná doba, sražené částky
a dobírka. Odvody zaměstnavatele se rekonstruují podle historických sazeb
přiložených v záloze a protokol na to upozorní. Převod nevytváří nový mzdový
výpočet ani další účetní zápisy.

Převod přes společné nastavení mezd doplní chybějící modul, účtárnu a začátek
vedení mezd na měsíc po posledních zdrojových mzdách, pokud to licence
a podporované období dovolují. Existující nastavení ani datum nepřepíše.
Historické mzdy patří před tento začátek; měsíce od něj zpracovává MyÚčto.
Chybějící sazby, neúplné údaje nebo nepodporované varianty mezd se označí
v protokolu a nepřevezmou jako úplné historické mzdy. Převzatá srážka je částka
již sražená v daném měsíci, nezakládá exekuci ani dohodu pro budoucí výpočty.
Počáteční roční kumulace se zatím nedoplňují. Z ověřených mzdových kontací
a parametrů zálohy vzniká návrh předkontací v `Mzdy → Importy`; použije se
a nastavení teprve po potvrzení účetní. Již potvrzený návrh převod nepřepisuje.

Neúplné údaje dětí, mzdové daňové údaje, dovolené a průměry se bez ověřeného
významu a období nepřebírají. Nepřevádí se leasing, skladové doklady a stavy
zásob, objednávky ani opakované trasy jako skutečně uskutečněné jízdy. Neověřené
technické zhodnocení se nepřičítá k ceně majetku. Samostatná kontrolní evidence
DPH, interní doklady a počáteční saldo se nepřebírají jako samostatné doklady;
jejich kontace mohou být součástí zdrojového deníku. Konkrétní vynechané údaje
a jejich počty uvádí protokol.

Po úspěšném převodu nabízí protokol odkazy na převzaté doklady a peněžní pohyby
k ruční kontrole. Zkouška nanečisto odkazy na dočasné záznamy nenabízí, protože
se všechny její zápisy vracejí zpět. U podvojného účetnictví se po úspěšné
kontrole předvahy zároveň dokončí aktivace účetnictví od začátku prvního
převáděného roku. Zkouška nanečisto stav aktivace nemění.

#### 21.9.15.3 Správa záloh

Výchozí heslo je součástí serverového importéru; prohlížeč jej nevyžaduje ani
neobdrží. Záloha se nerozbaluje do souborů. Nahranou zálohu najdete v seznamu
**Dříve nahrané zálohy** i po obnovení stránky. Dokončenou zálohu lze znovu
otevřít (**Otevřít zálohu**) bez dalšího nahrávání; nepotřebnou nebo nedokončenou
zálohu lze odstranit (**Odstranit zálohu**) a uvolnit místo. Firma může mít
nejvýše tři nahrané zálohy. Odstranění zálohy nemaže již importované doklady.
Dočasné uploady podléhají úklidu po sedmi dnech. Čtení archivu má limit
20 000 položek, nejvýše 64 MiB na soubor a 1 GiB celkového rozbaleného obsahu.

#### 21.9.15.4 Technická kontrola zdroje

Bez připojení k aplikační databázi lze zálohu prověřit příkazem:

```text
php api/bin/stereo-nx-inspect.php --archive=backup.zip --company=0 --password-stdin --purchases
```

Bez volby `--password-stdin` se použije výchozí heslo Stereo NX. Jiné heslo lze
předat standardním vstupem, například přesměrováním ze souboru uloženého mimo
repozitář. Index firmy odpovídá seznamu `ObsahBck.txt`; index `0` označuje
adresář `Firma_0`.

Výstupem je JSON se schématem, počty řádků, kontrolou domácích úhrad a při volbě
`--purchases` také souhrnem přijatých rekapitulací. Neobsahuje hodnoty
jednotlivých firemních řádků. Tento příkaz **neimportuje data a není zkouškou
převodu nanečisto**; úplnou zkoušku provede průvodce v aplikaci.

Volba `--accounting` navíc kontroluje zdrojový účetní deník proti účtové osnově.
Vrací souhrn částek v haléřích, rozsah dat a počty nalezených problémů. Rozpor
mezi datem účetního případu a zdrojovým rokem ohlásí jako upozornění. Kontaci
časově řadí podle data účetního případu a původní rok uchová samostatně.
Součástí jsou také počty zaměstnanců a mzdových záznamů, kontrola jejich
vzájemných vazeb a období. Osobní údaje ani částky jednotlivých mezd diagnostika
nevypisuje; mzdové výpočty neověřuje.

### 21.9.16 Import firmy z Kompletního exportu dat MyÚčta

Pro převod databázových grafů firmy použijte stávající **Systém → Kompletní
export dat**, vyberte **Úplný obnovitelný archiv** a ponechte období bez omezení.
Export již obsahuje právě vybranou firmu, JSONL data, kontrolní součty a soubory.
Není potřeba zavádět nový formát zálohy. Běžný databázový ZIP ze sekce Zálohy
není vstupem tohoto příkazu.

V aplikaci otevřete **Importy → MyÚčto**. Vyberte ZIP, vyplňte stabilní název
původní instance a případně heslo ZIPu. Klikněte na **Zkontrolovat nanečisto**.
Po úspěšné kontrole uvidíte přehled ověřených, nových a již přenesených řádků
včetně oblastí mimo rozsah. Potvrďte cílovou firmu a klikněte na
**Importovat do aktuální firmy**. Cílem je firma vybraná v přepínači aplikace;
změna firmy zruší výběr souboru i výsledek kontroly. Potřebujete právo zápisu
pro import, účetní deník a nastavení firmy.

Průvodce přijímá ZIP do **2 GiB**, nahrává jej po částech a používá stejný
importér jako CLI. Vybraná data a přílohy mají společný limit **64 MiB / 100 000
řádků**. Kontrola i obnova běží na pozadí ve společném workeru importů. Stránku
můžete zavřít: po návratu se připojí k rozpracovanému běhu. V **Historii běhů**
uvidíte průběh, protokol i výsledek dokončených kontrol a obnov. Nahrané ZIPy
se čistí po týdnu bez práce s nimi; protokoly zůstávají v historii.

Prázdné heslo použije heslo záloh cílové instalace. Zadané heslo se pro předání
workeru dočasně uloží zašifrované, vázané na firmu a nahraný ZIP; worker je po
převzetí odstraní. Po návratu na stránku heslo zadejte znovu, pokud je potřeba.
Obnova probíhá v jedné transakci, během běhu ji nelze přerušit. Pokud se
odpověď ztratí, výsledek najdete v historii; opakovaná kontrola ověří dokončený
import bez duplicit.

Import spouští správce instalace z příkazové řádky. Cílovou firmu vyberte
pomocí jejího ID; musí již existovat a mít stejné IČO, zemi, výchozí měnu, účetní režim, typ poplatníka, období DPH,
plátcovství včetně historie a význam klasifikací DPH. Její nastavení, přístupy uživatelů
ani přihlašovací údaje se nepřepisují. Cíl nesmí obsahovat vlastní obchodní data;
přítomné výchozí číselníky se mohou použít při shodném významu.

Nejprve spusťte zkoušku nanečisto:

```bash
php api/bin/myucto-import.php --file=export.zip --supplier=2 --actor=1 --source=zdrojova-instance
```

`--supplier` je ID cílové firmy, `--actor` ID aktivního uživatele a `--source`
stabilní jedinečný název původní instance. Zkouška provede skutečné zápisy,
ověří přenesené hodnoty a vrátí transakci i nově vytvořené soubory. Protokol
obsahuje počty vytvořených, použitých a již převzatých řádků, rekonciliaci
po tabulkách a `outside_scope` — nepřenesené neprázdné tabulky.

První profil přenáší partnery a jejich účty, měny, kategorie, střediska,
účtovou osnovu, firemní předkontace, účetní období, zakázky a výkazy práce, faktury a jejich položky,
pravidelné šablony, jednoduchý ceník, pokladnu, bankovní výpisy a transakce,
úhrady, vypořádání a zápočty, majetek s odpisy a účetní deník. Přenáší také ruční
klasifikace bankovních a pokladních pohybů daňové evidence a jejich historii,
včetně zrušených klasifikací. Uložené částky,
řádkové součty, daňová data a režim cen včetně DPH se zachovávají. Původní PDF
přijatých faktur, jejich zdrojové soubory a importovaná PDF vydaných faktur se
kopírují do nových cest; binární výpisy se obnovují k přemapovaným řádkům.
PDF vydaných faktur vytvořená aplikací se mohou v cíli vygenerovat znovu.

Firemní předkontace zachovají účty, priority a aktivitu; neúčtují doklady znovu.
Globální instalační předkontace se nepřenášejí, proto se jejich výchozí pravidla
mohou mezi instalacemi lišit. Nenulové kódy účtů předkontací musí být obsažené
v exportované účtové osnově. Existující firemní pravidlo se stejným klíčem
a prioritou se použije jen při shodném významu, jinak se obnova odmítne.
Historie klasifikací zachová původní časy a změny kategorií; odkazy na autory
se stejně jako ostatní uživatelské odkazy převedou na importujícího uživatele.
Zdrojové uživatelské účty se neobnovují.

Firmu obnovenou starším profilem bez předkontací a klasifikací nelze tímto
importem dodatečně rozšířit. Pro úplný přenos použijte prázdnou cílovou firmu;
opakování obnovy provedené současným profilem zůstává bez duplicit.

Mzdy, skladové grafy, DMS, přístupové a komunikační profily ani další tabulky
mimo tento seznam první profil nepřenáší. Doklad s živou vazbou na nepodporovaný
graf se odmítne, vazba se tiše nenuluje. Rovněž se odmítají nepodporovaná
syntetická ID závěrkových zápisů. Zkontrolujte proto před ostrým importem
`outside_scope` a celý výsledek zkoušky. Limit načtených dat a potřebných souborů
je 64 MiB, nejvýše 100 000 řádků; celý ZIP může mít nejvýše 20 GiB.

Po kontrole protokolu přidejte `--apply`:

```bash
php api/bin/myucto-import.php --file=export.zip --supplier=2 --actor=1 --source=zdrojova-instance --apply
```

Šablony pravidelné fakturace se přenesou **pozastavené**, s vypnutým automatickým
vystavením a odesláním. Zkontrolujte jejich nastavení a termíny a poté je
aktivujte. Automatické upomínky převzatých faktur jsou vypnuté. Veřejné a
schvalovací tokeny vydaných faktur se nepřenášejí; rozpracované schvalovací
žádosti je potřeba v cíli zahájit znovu.

Opakování stejného importu nevytvoří duplicity a ověří existující hodnoty i
soubory. Změněná zdrojová data nebo změněné převzaté doklady se odmítnou;
příkaz neslouží k průběžné synchronizaci. Heslo ZIPu se čte z
`MYUCTO_IMPORT_PASSWORD`, případně z `cron.backup.password` cílové konfigurace.
Heslo nezadávejte jako argument příkazu.

## 21.10 Související kapitoly

- [Export vydaných faktur](20_Exporty.md)
- [Export přijatých faktur](24_Export_prijatych.md)
- [AI extrakce přijatých faktur](25_AI_extrakce.md)
- [OSS](45_OSS.md)
- [Faktura: editor](15_Faktura_editor.md)
- [Klienti](18_Klienti.md)
- [Zakázky](19_Zakazky.md)
