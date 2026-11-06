# 73. Nástroje

> Návod k účetním pomůckám, které se nevejdou do běžných účetních sestav: hromadný export podkladů,
> střediska, předkontace, kurzový režim, repo sazba ČNB, retenční lhůty a kompletní export dat.
> Pro účetní a administrátory.

## 73.1 Kdy to potřebujete

<!-- cols: 36 38 26 -->
| Kdy | Co udělat | Postup |
|---|---|---|
| Na konci měsíce nebo kvartálu předáváte podklady účetní nebo daňovému poradci | Vytvořit ZIP s fakturami, výpisy a knihou DPH | [§ 73.3](#733-krok-za-krokem-hromadny-export-podkladu) |
| Chcete rozlišit odpovědnost nebo část firmy na řádcích zápisu | Založit středisko | [§ 73.4](#734-krok-za-krokem-strediska) |
| Zaúčtování používá špatné výchozí účty | Upravit předkontaci | [§ 73.5](#735-krok-za-krokem-predkontace) |
| Účtujete cizí měnu a chcete pevný kurz místo denního | Nastavit kurzový režim | [§ 73.6](#736-krok-za-krokem-kurzovy-rezim) |
| Počítáte úrok z prodlení a chybí sazba | Doplnit repo sazbu ČNB | [§ 73.7](#737-krok-za-krokem-repo-sazba-cnb) |
| Probíhá daňová kontrola nebo spor | Zadržet skartaci záznamů | [§ 73.8](#738-krok-za-krokem-retencni-lhuty-a-zadrzeni-skartace) |
| Potřebujete celoúčetní archiv nebo přenos instalace | Vytvořit kompletní export dat | [§ 73.9](#739-krok-za-krokem-kompletni-export-dat) |

Sekce **Nástroje** následuje v hlavním menu bezprostředně po **Účetnictví** v horním i levém rozložení.
Stránka `Nástroje → Účetní nastavení` je jedna položka menu s několika záložkami. Viditelnost závisí na
účetním režimu a roli:

<!-- cols: 40 60 -->
| Záložka | Dostupnost |
|---|---|
| **Střediska** | Podvojné účetnictví |
| **Předkontace** | Podvojné účetnictví |
| **Kurzový režim** | Podvojné účetnictví |
| **Repo sazba ČNB** | Podvojné účetnictví |
| **Číselné řady** | Podvojné účetnictví i daňová evidence (číselné řady dokladů, viz [Uzávěrka](72_Uzaverka.md)) |

Účetní období a průvodce uzávěrkou jsou samostatná položka menu `Nástroje → Uzávěrka` ([Uzávěrka](72_Uzaverka.md)), nikoli záložka Účetního nastavení.
Hromadný export je samostatná stránka `Daně → Hromadný export`. Retenční lhůty jsou samostatná stránka
`Nástroje → Retenční lhůty`.

## 73.2 Než začnete

1. **Režim účetnictví.** Záložky Střediska, Předkontace, Kurzový režim a Repo sazba ČNB jsou jen v podvojném účetnictví.
2. **Oprávnění.** Podvojné záložky vyžadují účetní oprávnění; jejich změny zápisovou variantu příslušného modulu. Hromadný export vyžaduje oprávnění k exportu sestav (zápisová varianta pro spuštění, zrušení a smazání). Předkontace vyžadují oprávnění k šablonám účtování. Kompletní export, archiv a právní zadržení jsou administrátorské.
3. **Správná firma.** Všechny soubory a záznamy jsou omezené na aktuální firmu.
4. **Správně nastavená osnova** pro předkontace a střediska (viz [Účtový rozvrh](66_Ucetni_osnova.md)).

## 73.3 Krok za krokem: hromadný export podkladů

1. Otevřete `Daně → Hromadný export`.
2. Zvolte měsíc nebo kvartál.
3. Náhled spočítá dostupné soubory. Vyberte, co chcete: vydané faktury v PDF a ISDOC, přijaté faktury v PDF a ISDOC, bankovní výpisy v PDF a GPC, knihu DPH.
4. Klikněte na **Připravit export**. Vznikne úloha na pozadí.
5. Sledujte aktuální krok a počet hotových položek. Chcete-li export zastavit, klikněte na **Zrušit**.
6. Hotový ZIP stáhněte z historie exportů tlačítkem **Stáhnout ZIP**.

**Jak poznáte, že je hotovo:** Úloha je dokončená a v historii je ZIP ke stažení.

> [!WARNING]
> Chybějící zdrojové PDF nebo chyba rendereru se objeví v chybě úlohy. Export nepovažujte za úplný jen proto,
> že ZIP existuje. Aktivní může být jen jeden export firmy. Smazání historie odstraní i výsledný soubor.

## 73.4 Krok za krokem: střediska

Středisko rozlišuje odpovědnost nebo část firmy na řádcích účetního zápisu.

1. Otevřete `Nástroje → Účetní nastavení` a záložku **Střediska**.
2. Klikněte na **Nové středisko**.
3. Vyplňte **Kód** (doplní se z názvu, můžete ho přepsat) a **Název**.
4. Zaškrtněte **Aktivní středisko** a uložte.
5. Středisko pak vyberete u řádku ručního zápisu nebo šablony.

**Jak poznáte, že je hotovo:** Středisko je v seznamu a nabízí se u řádků zápisu.

Nepoužité středisko lze smazat. Pokud je použité řádkem deníku nebo šablonou, aplikace je místo smazání
deaktivuje a ohlásí to. Kód středisko po založení nemění, aby se historické řádky a šablony nerozešly.

## 73.5 Krok za krokem: předkontace

Předkontace jsou efektivní mapa systémových účetních operací na výchozí účty.

**Změna předkontace:**

1. Otevřete `Nástroje → Účetní nastavení` a záložku **Předkontace**.
2. U pravidla klikněte na **Upravit**.
3. Změňte **MD účet** nebo **Dal účet** (aspoň jedna strana musí být vyplněná; každý kód musí existovat a být aktivní v osnově firmy).
4. Klikněte na **Uložit**. Vznikne firemní předkontace (sloupec **Původ**: **Firemní**), výchozí globální pravidlo se nemění.

**Doplnění podle analytik:** tlačítkem **Doplnit podle osnovy** posunete kontace ze syntetik na analytiky firmy
(postup viz [Šablony a pravidla](65_Sablony.md)).

**Export a import:**

1. Klikněte na **Export XLSX**. Soubor má sloupce `klic`, `popis`, `md_ucet`, `d_ucet`, `aktivni`, `priorita` a `zdroj`.
2. Upravte účty v tabulce a klikněte na **Import**. Soubor XLSX nebo CSV (do 2 MB) se nejdřív zkontroluje v náhledu.
3. Potvrďte import tlačítkem **Importovat**.

**Jak poznáte, že je hotovo:** U upravené předkontace je původ **Firemní** a další zaúčtování použije nové účty.

Web zobrazí editaci a import jen s oprávněním k zápisu šablon účtování; server při zápisu navíc vyžaduje
zápisové oprávnění k účetnictví.

## 73.6 Krok za krokem: kurzový režim

Firma může zvolit **denní kurz ČNB** podle rozhodného dne, **pevný měsíční kurz**, nebo **pevný roční kurz**
(§ 24 odst. 7 zákona o účetnictví).

1. Otevřete `Nástroje → Účetní nastavení` a záložku **Kurzový režim**.
2. V části **Režim přepočtu cizích měn** zvolte **Denní kurz ČNB**, **Pevný měsíční**, nebo **Pevný roční**. Aplikace ohlásí **Režim uložen**.
3. U pevného režimu v části **Pevné kurzy** zadejte **Měnu**, **Rok**, u měsíčního i **Měsíc**, a **Kurz (CZK)**. Tlačítkem **Dotáhnout z ČNB (1. den období)** předvyplníte kurz ČNB k prvnímu dni období, můžete ho před uložením změnit.
4. Klikněte na **Přidat**.

**Jak poznáte, že je hotovo:** Kurz je v seznamu pevných kurzů (zdroj **Ruční** nebo **ČNB**) a nový doklad v dané
měně se přepočte tímto kurzem.

> [!WARNING]
> Přepnutí režimu platí jen do budoucna: už zaúčtované doklady si drží zafixovaný kurz na hlavičce. Chybí-li
> pro měnu a období pevný kurz, vznik dokladu skončí chybou. Aplikace potichu nepřejde na denní kurz.

## 73.7 Krok za krokem: repo sazba ČNB

1. Otevřete `Nástroje → Účetní nastavení` a záložku **Repo sazba ČNB**.
2. Vyplňte **Platnost od**, **Sazbu (% p.a.)** a případně **Poznámku**.
3. Klikněte na **Přidat / uložit**. Řádek se stejným datem se aktualizuje, nikoli duplikuje.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Repo sazba uložena** a sazba je v seznamu.

Editaci sazeb svěřte administrátorovi nebo účetnímu, který doloží zdroj ČNB. Smazání používané historické sazby
může znemožnit reprodukovat starší výpočet úroku z prodlení (viz [Upomínky](22_Upominky.md)).

## 73.8 Krok za krokem: retenční lhůty a zadržení skartace

Stránka `Nástroje → Retenční lhůty` je informativní přehled lhůt úschovy účetních a daňových záznamů. Nic se
nemaže. Uplynulá lhůta znamená konec povinnosti záznamy uchovávat, ne pokyn ke skartaci.

**Zadržení skartace při kontrole nebo sporu (§ 32 zákona o účetnictví):**

1. Otevřete `Nástroje → Retenční lhůty`.
2. Klikněte na **Zadržet skartaci**, nebo u konkrétního roku na **Zadržet rok**.
3. Vyplňte č. j. nebo popis řízení (například daňová kontrola FÚ) a potvrďte.
4. Po skončení řízení zadržení uvolněte tlačítkem **Uvolnit** (po uvolnění už nic nebude bránit skartaci záznamů).

**Jak poznáte, že je hotovo:** Zadržení se objeví v seznamu **Zadržení skartace (§ 32)** a aplikace ohlásí
**Zadržení bylo založeno**. Uvolněná zadržení zobrazíte volbou **zobrazit i uvolněná**.

## 73.9 Krok za krokem: kompletní export dat

Pro přenos instalace nebo archiv slouží jeden nadřazený ZIP.

1. Otevřete `Systém → Kompletní export dat`.
2. Zvolte, co do ZIPu patří (viz [§ 73.11.5](#73115-obsah-kompletniho-exportu)): **Úplný obnovitelný archiv**, **Data, doklady a přílohy**, u plátce DPH podklady po měsících, v podvojném účetnictví uzávěrkové balíčky.
3. Zadejte případný rozsah období.
4. Klikněte na **Spustit export**. Během exportu nic neměňte v evidenci ani v souborech.
5. Po dokončení stáhněte ZIP tlačítkem **Stáhnout**.

**Jak poznáte, že je hotovo:** Úloha je dokončená a ZIP je ke stažení.

Obnova ze serveru je popsána v [§ 73.11.4](#73114-obnova-ze-serveru).

> [!WARNING]
> Archiv účetnictví je přenosný účetní export, ne jediná záloha. Pravidelně ověřujte také obnovitelnost celé
> databáze a datového adresáře.

## 73.10 Když něco nejde

<!-- cols: 34 32 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Záložky Střediska, Předkontace a další chybí | Firma vede daňovou evidenci | Přejděte na podvojné účetnictví ([Aktivace účetnictví](68_Aktivace_ucetnictvi.md)). |
| Středisko se smazat nepodařilo, jen se deaktivovalo | Je použité v zápisu nebo šabloně | Je to záměr, historie zůstává. |
| Předkontace nejde uložit | Účet neexistuje, je neaktivní, nebo jsou obě strany prázdné | Vyplňte aspoň jednu stranu aktivním účtem z osnovy. |
| Zaúčtování ohlásí chybu u deaktivovaného účtu | Účet předkontace byl později vypnut | Účet aktivujte nebo předkontaci opravte. Aplikace nepoužije jiný účet potichu. |
| Import předkontací odmítl klíč | Klíč neexistuje v globální šabloně | Import nový druh operace nevytvoří. Použijte existující klíče. |
| Doklad v cizí měně se nevytvoří | V pevném režimu chybí kurz měny a období | Doplňte pevný kurz v záložce **Kurzový režim**. |
| Úrok z prodlení nejde spočítat | Chybí potřebná historická repo sazba | Doplňte sazbu v záložce **Repo sazba ČNB**. |
| Export skončil chybou | Chybí zdrojové PDF, chyba rendereru nebo nedostatek místa | Otevřete poslední krok a hlášení úlohy. Nespouštějte úlohu opakovaně naslepo. |
| Nelze spustit druhý export | Aktivní může být jen jeden export firmy | Počkejte na dokončení, nebo ho zrušte. |
| Kurz nebo sazbu opravíte, ale doklady se nezměnily | Uložené doklady se zpětně nepřepočítávají | Je to záměr. Opravte doklad ručně. |

## 73.11 Podrobnosti a pravidla

### 73.11.1 Hromadný export: zařazení do období a úloha

Export používá stejné rozhodné datum jako daňová vrstva:

- vydané faktury podle **DUZP** (efektivní datum zdanitelného plnění),
- přijaté faktury podle společného výrazu data nároku na odpočet z evidence DPH, včetně data doručení
  a reverse-charge větví,
- bankovní výpisy podle data výpisu a výhradně podle vlastnictví účtu aktuální firmou,
- knihu DPH po jednotlivých měsících zvoleného období.

Tím se podklady přijatých faktur zařadí do stejného měsíce jako kniha DPH. Změna data doručení nebo daňové
klasifikace proto může změnit výsledek náhledu.

Po spuštění vznikne úloha ve stavech zařazeno, běží, dokončeno, případně selhalo nebo zrušeno. Worker vytváří
soubory postupně, ukládá aktuální krok a počet hotových položek. Hotový ZIP zůstává v historii ke stažení.
Zrušení je kooperativní: worker požadavek kontroluje mezi položkami.

### 73.11.2 Střediska

Číselník obsahuje neměnný unikátní **kód**, název a aktivní stav. Neaktivní středisko zůstane v historii, ale
není nabízeno pro nový zápis. Středisko samo nic nezaúčtuje a nemění účetní výkazy podle účtů. Je analytickým
rozměrem pro filtrování, export a manažerské vyhodnocení.

### 73.11.3 Předkontace, kurzy a repo sazba

**Předkontace.** Aplikace spojí globální pravidla s firemní úpravou stejného klíče.

<!-- cols: 22 78 -->
| Sloupec | Význam |
|---|---|
| Klíč | Stabilní typ operace (například vydaná faktura za služby) |
| Popis | Lidský význam operace |
| MD účet | Výchozí účet strany Má dáti |
| Dal účet | Výchozí účet strany Dal |
| Původ | Globální nebo firemní |

Prázdná strana může být záměrná: konkrétní protiúčet doplní aplikace podle dokladu, například u kurzového
rozdílu. DPH na 343 také není součástí základní mapy; dopočítává ji daňová služba z položek. Uložením úpravy
vznikne firemní předkontace; globální výchozí pravidlo se nemění. Zaúčtování si účet ověří znovu. Deaktivuje-li
se později, operace skončí chybou, nepoužije jiný účet potichu. Stejně se hlídají závěrkové účty, podrozvaha,
otevřené období, zámek a vyrovnanost.

**Import a export předkontací.** Import přijímá XLSX/CSV do 2 MB a má dry-run před potvrzením.

- Klíč musí existovat v globální šabloně; nový druh operace import nevytvoří.
- Účty musí být aktivní.
- `priorita` a `zdroj` jsou při importu informativní.
- Řádek shodný s efektivní hodnotou se přeskočí, aby nevznikal zbytečný firemní zásah.
- Import nemaže a reportuje každý založený, změněný, přeskočený a chybný řádek.

**Kurzový režim.** U pevného režimu se zadává měna, rok, u měsíčního i měsíc, a kurz. Roční řádek má měsíc 0.
Při vzniku dokladu aplikace vyhledá přesný firemní kurz pro měnu a období. Chybějící pevný kurz je chyba,
aplikace nesmí potichu přejít na denní kurz. Použitý kurz se uloží do hlavičky dokladu jako historický snapshot.
Pozdější změna režimu nebo číselníku již uložený doklad nepřepočítá. V denním režimu může kontrola upozornit
na odchylku od ČNB. V pevném režimu je tato odchylková kontrola záměrně vypnutá, protože odlišný kurz je
zvolená účetní metoda, ne chyba. Pevné kurzy jsou oddělené podle firmy, měna se normalizuje na třípísmenný kód
a kurz musí být kladný. Změna režimu i sazby se auditují.

**Repo sazba ČNB.** Číselník uchovává 2T repo sazbu, datum platnosti a poznámku. Sazbu používá výpočet
zákonného úroku z prodlení:

`jistina × (repo sazba k počátku prodlení + 8) / 100 × dny / 365 nebo 366`

Rozhodná je sazba platná k prvnímu dni kalendářního pololetí, ve kterém prodlení začalo; po dobu jednoho
prodlení se změnou sazby uprostřed období nepřepíná. Chybí-li potřebná historická sazba, kalkulátor vrátí
chybu a úrok nevymyslí. Více viz [Upomínky](22_Upominky.md).

### 73.11.4 Obnova ze serveru

Obnova není dostupná ve webu. Administrátor serveru použije:

```text
php api/bin/archive-restore.php --file=<export.zip> --database=<prazdna_migrovana_db> --dry-run
php api/bin/archive-restore.php --file=<export.zip> --database=<prazdna_migrovana_db> --restore --storage=<prazdny_datovy_adresar> --documents
```

Databáze musí být předem migrovaná cílovou, stejnou nebo novější verzí MyÚčto a spolu s datovým adresářem
prázdná. Dry-run ověří hash a počet každé části i přílohy bez zápisu. Ostrá obnova zachová interní ID, obnoví
vše v jedné databázové transakci. Obnova odmítne databázi s existujícími firemními daty.

Cílová instalace během obnovy nesmí obsluhovat uživatele ani spouštět úlohy. Databázový účet potřebuje také
oprávnění vytvářet a odstraňovat triggery. Obnova uloží jejich cílové definice do pomocné tabulky, dočasně je
odpojí pro vložení historického stavu a následně obnoví včetně původního pořadí. To umožňuje obnovit i
zaúčtované mzdové dávky a uzavřené revize, aniž by se měnily ochrany běžného provozu. Při přerušení procesu
spusťte příkaz znovu; nejdříve obnoví uložené triggery. Pokud už byla data potvrzena, druhý import odmítne.
Pomocnou tabulku `instance_restore_trigger_recovery` nemažte ručně.

Před potvrzením transakce se ověří všechny cizí klíče a zápis souborů. Parametr `--storage` určuje cestu
k cílové složce `storage`. Volitelný parametr `--documents` navíc uloží originály přijatých faktur,
importované originály vydaných faktur i aktuální PDF vydaných faktur do jejich aplikačních úložišť; PDF vydané
faktury přitom znovu propojí přes `invoices.pdf_path`. Pokud původní přijaté PDF ve zdroji chybí, export to
oznámí a přidá označenou rekonstrukci, kterou obnova propojí s přijatou fakturou. Rekonstrukce nenahrazuje
ztracený originál. Bez tohoto parametru se obnoví databáze, výpisy a přílohy, ale PDF dokladů zůstávají jen
v exportním ZIPu. Přihlašovací tajemství, tokeny a klíče se neobnovují; uživatelé jsou zablokovaní a správce
jim pošle pozvánku nebo reset hesla.

Mzdové osobní údaje a bankovní exporty zůstávají v archivu kontextově zašifrované. Cílová instalace proto musí
bezpečně převzít původní `app.secret_encryption_key`, případně jej po rotaci dočasně ponechat mezi
`app.secret_encryption_previous_keys`. Pro mzdové vyhledávací a kontrolní otisky zachovejte i
`app.payroll_hash_key`, případně původní `app.pepper`, pokud se používá jako jeho náhrada. V takovém případě
musí mít cílová instalace `app.payroll_hash_key` prázdný, i když ho při instalaci vygeneroval instalátor
nebo průvodce nastavením. Tyto klíče
v exportu nejsou. Automatický round-trip test hlídá počty, vazby a hashe souborů, ale archiv stále nenahrazuje
celoinstanční zálohu databáze.

Po každé zkušební obnově proveďte ruční kontrolu alespoň tohoto vzorku:

- porovnejte počet osob a pracovních vztahů a otevřete náhodně vybranou osobní kartu včetně historických údajů,
- u dvou různých měsíců porovnejte schválenou revizi mzdy, čistou mzdu, zákonné odvody a stav jejich skutečné úhrady,
- otevřete náhodnou výplatní pásku a další mzdové PDF, ověřte jejich obsah a možnost stažení oprávněným uživatelem,
- u JMHZ a přehledu zdravotní pojišťovny porovnejte stav, období, neměnný odeslaný artefakt a přijatý protokol
  nebo jiný důkaz doručení,
- ověřte, že bankovní export lze zpřístupnit až po nastavení správného šifrovacího klíče a že uživatel bez
  mzdových práv osobní údaje neuvidí.

Výsledek, datum, verzi aplikace, kontrolované měsíce a případné rozdíly zapište do provozního protokolu obnovy.
Teprve po úspěšné kontrole je obnovená instalace připravená k používání; zkušební databázi ani datový adresář
nepřipojujte k ostrému provozu.

### 73.11.5 Obsah kompletního exportu

Kompletní export v `Systém → Kompletní export dat` může být přímo obnovitelný. Není pro něj samostatná
obrazovka ani druhý ZIP: je jednou z volitelných částí jediného exportního balíčku. Správce instalace zvolí
u jednotlivých částí, co do ZIPu patří:

- **Úplný obnovitelný archiv**: hlavní ZIP s databází, binárními výpisy a přílohami; volba automaticky zapne
  nutné části, včetně podkladů pro volitelnou obnovu PDF dokladů,
- **Data, doklady a přílohy**: přenositelný JSON Lines export, PDF/ISDOC doklady, bankovní výpisy, mzdové PDF,
  zašifrované mzdové platební exporty a nahrané soubory,
- u plátce DPH **podklady po měsících** (Kniha DPH v PDF a kontrolní hlášení v XML), při čtvrtletní periodě
  také ZIP za každé čtvrtletí,
- v podvojném účetnictví **uzávěrkové balíčky** za vybraná účetní období.

Export obsahuje JSON Lines data firmy, mimo jiné:

- účetní období, osnovu, deník, předkontace a střediska,
- dlouhodobý majetek a odpisy,
- vydané a přijaté faktury, položky a částečné úhrady,
- banku a pokladnu,
- přílohy deníku včetně binárních souborů,
- kompletní firemní mzdovou evidenci včetně zaměstnanců, pracovních vztahů, docházky, vstupů, běhů, výsledků,
  srážek, plateb, dokumentů a podání,
- daň z příjmů a u skladové firmy skladovou evidenci.

Obnovitelný archiv přidává také připnuté legislativní katalogy JMHZ a výpočetní obsah správcovských odchylek
mzdových pravidel, aby obnovené snapshoty neztratily své podklady. Globální audit, identity správců a jimi
zapsané důvody se do exportu jedné firmy nepřenášejí.

Zahrnuty jsou také země, sazby DPH, vlastní jednotky, e-mailové šablony, daňové konstanty a historické kurzy.
Do čisté cílové instalace se přenesou místo výchozích hodnot. Soubory zahrnují originální zdroje přijatých
dokladů, loga firmy a brandingových profilů, uložené účetní archivy a výsledky importů.

Manifest uvádí verzi schématu, počty řádků a SHA-256 každé datové části i přílohy. Hesla, API klíče, soukromé
certifikáty, volba podpisového certifikátu, uložené osobní přístupy k ISDS a jiné provozní tajné hodnoty se
neexportují; po obnově se nastaví znovu. PDF faktur i mzdové dokumenty jsou součástí exportu; zahrnuty jsou
také zašifrované bankovní exporty mzdových plateb.

Zadaný rozsah omezuje výkazy. Pokud není zvolen úplný obnovitelný archiv, omezuje také doklady a bankovní
výpisy. Úplný obnovitelný archiv zahrnuje doklady, výpisy a data celé firmy bez ohledu na zadané období.
Databázové tabulky se čtou v jednom konzistentním snapshotu; soubory a nově generované sestavy vznikají
následně. Po dobu exportu proto neprovádějte změny evidence ani souborů. Obnovu provádějte přímo z tohoto ZIPu
příkazem uvedeným v předchozím oddílu, jeho formát je kompatibilní se stejnou i novější verzí MyÚčto. Obecné
soubory `data/*.jsonl` jsou kontrolní a přenosový export, nikoli vstup pro import.

V podvojném účetnictví export slouží také jako praktický podklad pro zákonnou retenci účetních a daňových
záznamů. Přehled lhůt a zadržení skartace najdete na stránce `Nástroje → Retenční lhůty`.

### 73.11.6 Interní ověření mzdového produktu

Produkční kvalifikaci, syntetické paralelní běhy a recovery drill provádí výhradně tým MyÚčta v izolovaném
interním prostředí. Zákazník tyto důkazy nevytváří a nenahrává kvalifikační protokol. Ostrá mzdová podání
a mzdové platební příkazy jsou dostupné každé firmě, která dokončila základní nastavení mezd.

### 73.11.7 Retence a právní zadržení

Aplikace retenční pravidla vynucuje při mazání účetních a daňových dokladů (rozhraní `/api/accounting/retention`).

<!-- cols: 62 38 -->
| Kategorie | Lhůta od konce období |
|---|---:|
| Účetní závěrka a výroční zpráva | 10 let |
| Účetní doklady, knihy, odpisové plány a inventury | 5 let |
| Daňové doklady | 10 let |

U dokladu s DPH vyhrává delší desetiletá lhůta. Poslední den lhůty je stále chráněný; aplikace nic nemaže
automaticky ani po jejím uplynutí.

Administrátor může při výslovně potvrzeném mazání retenční ochranu přehlasovat. Takový zásah se zapíše do
auditu s vypočteným datem uchování. Běžný uživatel ochranu obejít nemůže.

Probíhající daňová kontrola nebo spor se eviduje jako **zadržení** podle § 32 zákona o účetnictví. Zadržení
může platit pro období nebo celou firmu a uchovává důvod a spisovou značku. Aktivní zadržení blokuje smazání
i po uplynutí běžné lhůty. Uvolnění je ruční a auditované; historický záznam nezmizí.

## 73.12 Související kapitoly

- [Šablony a pravidla](65_Sablony.md)
- [Účtový rozvrh](66_Ucetni_osnova.md)
- [Uzávěrka](72_Uzaverka.md)
- [Upomínky](22_Upominky.md)
