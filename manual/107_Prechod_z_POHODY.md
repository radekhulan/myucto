# 107. Přechod z POHODY

> Návod, jak převést vybrané účetní roky z POHODY (i POHODY SQL) do firmy
> v MyÚčtu: od exportu ze starého programu přes zkoušku nanečisto a ostrý
> převod až po kontrolu převzatých dat. Pro účetní a správce, kteří přecházejí
> z POHODY.

**Cesta: `Systém → Přechod z jiných účetních systémů → POHODA`**

Průvodce převede vybrané účetní roky z programu POHODA do firmy v MyÚčtu.
Vstupem je ZIP s XML, který připravíte jednou ze tří cest (export přes XML
rozhraní POHODY, místní převod kopie datového souboru MDB, nebo export přímo
z databáze POHODA SQL). Nástroje stáhnete přímo z průvodce. Zdrojová data jen
čtou, v POHODĚ nic nemění. Průvodce převádí jen **účetnictví**. Mzdy
z POHODY Mzdy nebo PAMICA převádí samostatný průvodce, viz
[Přechod z PAMICA](108_Prechod_z_PAMICA.md).

## 107.1 Kdy to potřebujete

- Firma dosud účtovala v POHODĚ (nebo POHODĚ SQL) a chce pokračovat
  v MyÚčtu s deníkem, doklady, bankou, pokladnou a majetkem.
- Převádíte další rok z již nahraného exportu.
- Převod skončil chybou nebo rozdílem v protokolu.
- Chcete po převodu ověřit, že MyÚčto sedí na POHODU.

<!-- cols: 24 40 36 -->
| Fáze | Co udělat | Kde |
|---|---|---|
| 1 | Vybrat cestu a vytvořit export | počítač s Windows, nástroje z průvodce |
| 2 | Nahrát ZIP a zvolit roky | `Systém → Přechod z jiných účetních systémů → POHODA`, krok **Export z POHODY** |
| 3 | Zkouška nanečisto | krok **Zkouška nanečisto** |
| 4 | Ostrý převod | krok **Převod** |
| 5 | Kontrola převzetí | protokol, `Účetnictví`, obratová předvaha |
| 6 | Navázání číselné řady, skeny dokladů, mzdy | `Nastavení`, `Dokumenty`, průvodce PAMICA |

## 107.2 Než začnete

Agendu neziskové organizace (osnova podle vyhlášky 504/2002 Sb. s účty skupiny 9)
převod přijme jen do firmy, která účtuje podle vyhlášky 504; agendu podnikatele
do takové firmy odmítne. Převod přebírá deník, doklady a střediska (dimenze Středisko)
a ověřuje rovnováhu rozvahy. Činnost z POHODY nečte, všem řádkům nákladů a výnosů
nastaví hlavní činnost, kterou účetní přeřadí. Fondy, dotace ani členské příspěvky
zvlášť nepřebírá a výkazy s POHODOU neporovnává, proto je zkontrolujte. Průvodce na to
upozorní (podrobnosti v kapitole [Přechod z Money S3](106_Prechod_z_Money_S3.md),
oddíl 106.2).

Když firma místo činností vede střediska, přeřadí účetní řádky najednou podle tabulky
středisko a činnost. Správce instalace spustí na serveru
`php api/bin/pohoda-activity-by-centre.php --supplier=ID --map="VÝUKA=main,ZvukSvětlo=economic"`,
kde `main` je hlavní a `economic` hospodářská činnost. S volbou `--dry-run` nástroj nic
nezapíše a jen vypíše počty řádků a částky MD a D po středisku a činnosti. Mění řádky účtů
tříd 5 a 6 převedených zápisů a kód střediska porovnává přesně. Řádky bez střediska
a se střediskem mimo tabulku nechá. Přeskočí a v souhrnu vypíše řádky v uzavřeném období,
řádky rozdělené mezi více hodnot dimenze, stornované zápisy i jejich storna a řádky,
kterým jste v MyÚčtu změnili dimenzi Středisko. Spuštění je opakovatelné, a opakovaný
převod přeřazení nevrátí. Ručně nastavenou činnost na řádku se střediskem z tabulky
nástroj přepíše. Jednotlivé změny i souhrn běhu zapisuje do protokolu aktivit.

1. **Firma v MyÚčtu.** Musí existovat a mít vyplněné stejné IČO jako v POHODĚ.
   Převádí se do firmy, ve které právě pracujete; novou firmu nejdřív založte
   (kapitola [Multi supplier](95_Multi_supplier.md)). Firma, která zatím vede
   daňovou evidenci, se převodem přepne na podvojné účetnictví od začátku
   převáděného roku. Převádí se jen agenda POHODY vedená v podvojném účetnictví;
   agendu v daňové evidenci (peněžní deník) převod nepodporuje. Faktury z ní
   převedete importem Pohoda XML ([§ 21.3](21_Importy.md#213-krok-za-krokem-import-vydanych-faktur-ze-souboru),
   [§ 21.4](21_Importy.md#214-krok-za-krokem-import-prijatych-faktur-ze-souboru)).
2. **Oprávnění.** Průvodce vidí a zkoušku nanečisto spouští uživatel
   s oprávněním k zápisu importů. Ostrý převod zapisuje účetní deník a mění
   nastavení firmy, proto vyžaduje navíc zápis do účetního deníku a do
   nastavení firmy (`utilities.import`, `accounting.journal.write`,
   `settings.company.write`). Chybějící oprávnění průvodce ukáže a převod nespustí.
   Položka je v menu Systém, které vidí administrátor; jiný uživatel otevře
   průvodce přímým odkazem `/imports/pohoda`.
3. **Počítač s Windows** pro exportní nástroje. Běží v PowerShellu 5.1, který
   je součástí Windows 10 a 11.
4. **Podle zvolené cesty:** nainstalovaná POHODA (cesta 1), kopie datového
   souboru MDB a ovladač Microsoft Access Database Engine (cesta 2), síťový
   přístup k SQL Serveru s POHODOU (cesta 3).
5. **Klid ve firmě** pro zkoušku nanečisto.

> [!WARNING]
> Samotný MDB ani původní zálohu POHODY na server nenahrávejte. Nahrává se
> jen ZIP s XML vytvořený exportním nástrojem, nerozbalený.

## 107.3 Krok za krokem: export z POHODY

Vyberte jednu ze tří cest. Všechny vytvoří ZIP s XML pro tento průvodce.

<!-- cols: 20 40 40 -->
| Cesta | Kdy ji použít | Co potřebujete |
|---|---|---|
| 1. Export XML přes POHODU | Běžná cesta; u velké agendy trvá export desítky minut | nainstalovaná POHODA, uživatel s právem na XML import/export |
| 2. Převod kopie MDB | Máte datový soubor a export přes POHODU trvá dlouho | kopie `.mdb`, ovladač Access |
| 3. Export z POHODA SQL | Účetnictví běží na SQL Serveru | síťový přístup k SQL Serveru, čtecí login |

**Příprava (všechny cesty):**

1. V průvodci klikněte na **Zobrazit exportní nástroj**, pak na **Stáhnout vše
   (ZIP)**. Stáhne se `pohoda-export.zip`.
2. Rozbalte celý balíček do jedné složky na počítači s Windows. Všechny soubory
   musí ležet ve stejné složce.

**Cesta 1: export XML přes POHODU**

1. Spusťte `Export-Pohoda.cmd` dvojklikem, nebo s parametry, například
   `Export-Pohoda.cmd -Uzivatel Admin -Rok 2026 -Ico 12345678`.
2. Zadejte heslo uživatele POHODY (uživatel musí mít právo na XML
   import/export, nejjednodušší je Admin).
3. Počkejte na dokončení. Vede-li POHODA víc firem, zadejte IČO parametrem
   `-Ico`. Bez parametru `-Rok` se vyexportují všechny roky.
4. Vedle skriptu vznikne `pohoda_export_<datum>.zip`.

**Cesta 2: převod kopie MDB**

1. Zavřete POHODU u všech uživatelů a vytvořte kopii datového souboru `.mdb`
   správné firmy a roku. Máte-li zálohu v ZIP, nejdřív z ní datový soubor
   obnovte.
2. Spusťte `Export-PohodaMdbAccounting.cmd` dvojklikem a vyberte kopii MDB.
   Chybí-li ovladač Access, nainstalujte Microsoft 365 Access Runtime (32bitovou
   nebo 64bitovou variantu podle Office).
3. Převodník vypíše cestu k výsledku. Zdrojový MDB zůstává na vašem počítači.

**Cesta 3: export z POHODA SQL**

1. Zkopírujte vzor `pohoda-sql.example.json` jako `pohoda-sql.json` vedle
   skriptu a vyplňte připojení (nebo nechte nástroj, ať se zeptá).
2. Spusťte `Export-PohodaSQL.cmd` dvojklikem, vyberte agendu ze seznamu a
   potvrďte IČO a rok.
3. Vedle skriptu vznikne složka a ZIP `<IČO>_<rok>.zip`. Soubor
   `pohoda-sql.json` s heslem po exportu smažte.

Parametry a konfigurace jsou v
[§ 107.9.1](#10791-export-z-pohody).

**Jak poznáte, že je hotovo:** Máte ZIP (`pohoda_export_<datum>.zip`, výsledek
převodníku MDB, nebo `<IČO>_<rok>.zip`). Souhrn nástroje neuvádí chybu.

## 107.4 Krok za krokem: nahrání exportu a zkouška nanečisto

1. Otevřete `Systém → Přechod z jiných účetních systémů` a u dlaždice **POHODA** klikněte na **Otevřít průvodce**.
2. V kroku **Export z POHODY** klikněte na **Soubor exportu (.zip)**, vyberte
   ZIP a klikněte na **Nahrát a načíst**. Stránku nechte během nahrávání
   otevřenou. Soubor může mít až 2 GB, průvodce ho posílá po částech.
3. V kroku **Náhled a volby** v tabulce **Agendy v exportu** zkontrolujte
   **IČO**, **Firma**, **Rok** a počty. Převést jde jen agendu s IČO firmy
   v MyÚčtu. Roky k převodu zaškrtněte v prvním sloupci; předvybrané jsou
   všechny roky agend s IČO firmy. U firmy s hospodářským rokem je **Rok**
   účetní rok firmy, do kterého agenda patří, s hranicemi období (například
   2025 (1. 8. 2025 – 31. 7. 2026)).
4. Má-li agenda doklady po konci roku, průvodce nabídne každý pozdější rok jako
   samostatný řádek pod agendou (u hospodářského roku jako další účetní rok
   firmy). Pozdější rok jde převést jen spolu s rokem agendy; nevybraný převod
   přeskočí ([§ 107.9.4](#10794-postup)).
5. Zkontrolujte **Kontrolu před převodem** pro každý vybraný rok. Převod se
   zastaví, když export patří firmě s jiným IČO, chybí nebo nejde přečíst deník,
   osnova nebo členění DPH, účetní období v MyÚčtu už obsahuje zápisy, které
   nevznikly převodem, nebo je období uzavřené.
6. Klikněte na **Pokračovat** a v kroku **Zkouška nanečisto** na **Spustit
   zkoušku nanečisto**. Proběhne celý převod včetně rekonciliace, na konci se ale
   všechno vrátí.
7. Přečtěte protokol za každý rok. Chyby opravte a zkoušku zopakujte.

**Jak poznáte, že je hotovo:** Každý rok skončí stavem **V pořádku** nebo **S
upozorněními**. Selže-li zkouška jen na **Rozdílech k přijetí**, postupujte podle
[§ 106.10.5.1](106_Prechod_z_Money_S3.md#1061051-chyby-upozorneni-a-rozdily-k-prijeti).

> [!WARNING]
> Zkouška převádí každý rok samostatně a hned ho vrací zpět. Pozdější rok proto
> ve zkoušce nevidí data předchozího roku a jeho výsledek se od ostrého
> převodu může lišit.

## 107.5 Krok za krokem: ostrý převod

1. V kroku **Převod** zaškrtněte potvrzení, že rozumíte dopadu převodu.
   Potvrzení vyjmenuje převáděné roky.
2. Po zkoušce, která selhala jen na rozdílech k přijetí, zaškrtněte navíc
   **Převést i přes rozdíly**.
3. Klikněte na **Spustit převod**. Převod běží na pozadí, stránku můžete
   zavřít. Roky se převádějí vzestupně jeden po druhém. Převod zastavíte
   tlačítkem **Zastavit převod**.
4. Po dokončení klikněte na **Otevřít účetní deník** nebo **Obratová
   předvaha**.

**Jak poznáte, že je hotovo:** Všechny vybrané roky skončí stavem **V pořádku**
nebo **S upozorněními**. Skončí-li rok chybou nebo převod zrušíte, další roky se
nespustí. Převod jedné firmy běží vždy jen jeden.

## 107.6 Krok za krokem: kontrola převzetí

1. Otevřete protokol každého roku v přehledu **Protokoly převodů** pod
   průvodcem. Zkontrolujte rekonciliaci: obratová předvaha MyÚčta proti
   předvaze z deníku POHODY na haléř, obraty MD = D, vyrovnané počáteční stavy,
   rozvaha, doklady proti deníku (321, 311, 211, 221).
2. Doklady označené jako **doklad bez zápisu** zaúčtujte v
   `Účetnictví → Doúčtovat doklady`.
3. Koncepty k ruční kontrole (doklad s daní bez členění DPH, neznámé členění,
   samovyměření bez interního dokladu, OSS bez státu MOSS) opravte a potvrďte.
4. Zkontrolujte pohyby, které POHODA nezaúčtovala
   ([§ 107.9.5.1](#107951-pohyby-ktere-pohoda-nezauctovala)): návrhy
   párování u výpisu a platby kartou k zaúčtování.
5. Zkontrolujte převzaté karty majetku (koncepty s důvodem v protokolu) a
   drobný majetek bez dokladu.
6. Otevřete obratovou předvahu a porovnejte syntetické účty s POHODOU.
7. Převáděný rok zůstává neuzavřený. Uzávěrku a převod zůstatků do dalšího roku
   provedete v `Nástroje → Uzávěrka`.
8. Doplňte, co se nepřevádí ([§ 107.9.3](#10793-co-prevod-neprenese)).

**Jak poznáte, že je hotovo:** Kontroly rekonciliace projdou, nezbývají doklady
v konceptu ani nepřijaté rozdíly a obraty v MyÚčtu odpovídají POHODĚ.

## 107.7 Krok za krokem: navázání číselné řady

1. Otevřete `Firma → Nastavení`, záložku **Číslování faktur** a najděte šablonu řady.
2. Do pole **Příští číslo** zadejte číslo, kterým má řada pokračovat.
3. Klikněte na **Nastavit počítadlo**. Ukládá se samostatně, mimo tlačítko
   **Uložit**.

**Jak poznáte, že je hotovo:** Náhled ukazuje očekávané příští číslo.
Pravidla (perioda resetu, zděděná šablona) jsou v
[§ 107.9.4.1](#107941-navazani-na-existujici-ciselnou-radu).

## 107.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Nahrávání se přerušilo | Výpadek spojení | Vyberte soubor a nahrajte ho znovu |
| Náhled exportu se nenačetl | Zpracování trvá déle, nebo selhalo | Klikněte na **Zkusit znovu** nebo **Načíst stav znovu**; nahraný export zůstává na serveru |
| Export patří jiné firmě | IČO v exportu se liší | Zvolte správnou firmu, nebo exportujte správnou agendu |
| Export neobsahuje agendu s IČO firmy | Chybí agenda nebo IČO ve firmě | Doplňte IČO v nastavení firmy, nebo exportujte znovu |
| Doklady OSS skončily jako koncepty | Starší export nese údaje OSS bez státu MOSS | Vytvořte export znovu aktuálním nástrojem |
| Doklad OSS se nepřevedl | Firma nemá zapnutý OSS nebo chybí sazba státu spotřeby | Doplňte nastavení ([§ 107.9.2](#10792-co-prevod-prenese)) a převod zopakujte |
| Zkouška selhala jen na rozdílech k přijetí | Doklad se nepřevedl, nebo výsledek nesedí na POHODU | Zaškrtněte **Převést i přes rozdíly**, nebo opravte a zopakujte |
| Doklad je koncept | Nejistá daňová povaha | Opravte klasifikaci DPH a potvrďte |
| Faktura uhrazená v nevybraném roce zůstala neuhrazená | Pozdější rok jste nevybrali | Převeďte pozdější rok opakovaným převodem ([§ 107.9.7](#10797-opakovany-prevod)) |
| Přeskočený doklad s neznámým typem | Neznámý typ faktury, banky, pokladny nebo zdroj úhrady | Doklad doplňte ručně, číslo a důvod uvádí protokol |
| Karta majetku je koncept | Neznámý typ majetku nebo odpisu, jiná vstupní cena, chybí plán nebo účet | Zkontrolujte kartu podle důvodu v protokolu |
| Nástroj hlásí, že datový soubor je chráněný heslem databáze | MDB má heslo databáze | Spusťte nástroj znovu a heslo zadejte, když se zeptá, nebo parametrem `-Heslo` |
| Nástroj hlásí, že datový soubor drží jiný program | POHODA je otevřená na jiném počítači, nebo chybí právo ke čtení | Zavřete POHODU u všech uživatelů, případně pracujte s kopií souboru |

## 107.9 Podrobnosti a pravidla

### 107.9.1 Export z POHODY

#### 107.9.1.1 Exportní nástroj

V prvním kroku průvodce ukáže tlačítkem *Zobrazit exportní nástroj* soubory
nástroje:

| Soubor | K čemu slouží |
|---|---|
| `Export-Pohoda.cmd` | spouštěč, který se otevírá dvojklikem |
| `Export-Pohoda.ps1` | vlastní exportní skript |
| `Export-PohodaMdbAccounting.cmd`, `Export-PohodaMdbAccounting.ps1` | převod účetnictví z kopie MDB do ZIP s XML bez spouštění XML exportu POHODY |
| `Export-PohodaMdb.cmd`, `Export-PohodaMdb.ps1` | majetek a mzdy z datového souboru POHODY (XML export je neobsahuje) |
| `Export-PohodaSQL.cmd`, `Export-PohodaSQL.ps1` | účetnictví, majetek a mzdy z databáze POHODA SQL do stejného ZIP s XML jako převod MDB |
| `pohoda-sql.example.json` | vzor konfigurace připojení k SQL Serveru pro `Export-PohodaSQL` |
| `Pohoda-Common.ps1`, `PohodaSql-Common.ps1` | podpůrné skripty převodu MDB a POHODA SQL; spouštějí je hlavní nástroje |

Stáhněte každý soubor zvlášť, nebo všechny najednou tlačítkem *Stáhnout vše (ZIP)*
jako `pohoda-export.zip`. Všechny soubory musí ležet ve stejné složce.

Balíček rozbalte na počítači s Windows. Nástroje běží v PowerShellu 5.1,
který je součástí Windows 10 a 11. Pro první cestu je potřeba nainstalovaná
POHODA, kterou exportér spouští v režimu XML komunikace z příkazového řádku.
Pro druhou cestu potřebujete kopii MDB a ovladač Microsoft Access Database
Engine (ACE). Pro třetí cestu stačí síťový přístup k SQL Serveru s POHODOU,
ovladač je součástí Windows.

#### 107.9.1.2 Cesta 1: export XML přes POHODU

1. Spusťte `Export-Pohoda.cmd` dvojklikem, nebo z příkazového řádku s parametry:

   ```
   Export-Pohoda.cmd -Uzivatel Admin -Rok 2026 -Ico 12345678
   ```

2. Zadejte heslo uživatele POHODY. Uživatel musí mít právo na XML
   import/export, nejjednodušší je Admin.
3. Počkejte na dokončení. Okno ukazuje průběh po agendách, u velké agendy
   export trvá desítky minut.
4. Vedle skriptu vznikne soubor `pohoda_export_<datum>.zip`. Ten nahrajte do
   průvodce beze změny, nerozbalený.

| Parametr | Význam |
|---|---|
| `-Uzivatel` | uživatel POHODY s právem na XML import/export |
| `-Rok` | účetní rok, případně víc roků; bez něj se vyexportují všechny roky |
| `-Ico` | IČO firmy; nutné, když POHODA vede víc firem |
| `-Heslo` | heslo uživatele; bez něj se na něj nástroj zeptá |

Export jen čte. Do POHODY nic nezapisuje a nic v ní nemění.

#### 107.9.1.3 Co je v exportu

- POHODA vede každý účetní rok samostatně a export obsahuje jednu agendu za
  každou kombinaci IČO a roku. Převádějí se roky, které zaškrtnete v náhledu
  (jeden i víc najednou). Nevybrané roky zůstávají v POHODĚ.
- Majetek, mzdy a ocenění skladu XML export POHODY neobsahuje. Nástroj je čte
  přímo z datového souboru a přidá do exportu jako `90_majetek.xml`,
  `91_mzdy.xml` a `92_sklad.xml`, viz [§ 107.9.1.4](#107914-majetek-z-datoveho-souboru). Tento průvodce
  zpracuje majetek a sklad - mzdy z téhož souboru převede
  [Přechod z PAMICA](108_Prechod_z_PAMICA.md).
- ZIP může obsahovat podsložky. Průvodce v nich najde XML soubory všech agend
  a k převodu nabídne jen ty, které podle IČO patří firmě v MyÚčtu. Agendy
  jiných firem ukáže jen pro informaci.
- Firma musí v MyÚčtu existovat a mít vyplněné stejné IČO jako v POHODĚ.
  Převádí se do firmy, ve které právě pracujete; novou firmu nejdřív založte
  (kapitola [Multi supplier](95_Multi_supplier.md)).
- Export obsahuje celé účetnictví firmy. Nahraný soubor aplikace po 24 hodinách
  bez práce s převodem sama smaže, po úspěšném ostrém převodu hned.
- Soubor může mít až 2 GB. Průvodce ho posílá po částech a ukazuje průběh
  v procentech; při výpadku spojení část zopakuje a naváže tam, kde server
  data má. Stránku nechte během nahrávání otevřenou.

#### 107.9.1.4 Majetek z datového souboru

XML rozhraní POHODY nevrací dlouhodobý majetek, drobný majetek, mzdy ani
ocenění skladu. Čte je skript `Export-PohodaMdb.ps1` přímo z datového souboru
POHODY (`.mdb`), u POHODA SQL z databáze agendy. Data jen čte, nic v nich nemění.

| Soubor | Obsah |
|---|---|
| `90_majetek.xml` | karty dlouhodobého majetku, daňové odpisy po letech, účetní odpisy po měsících, drobný majetek a jeho zdrojové doklady |
| `91_mzdy.xml` | zaměstnanci, pracovní poměry, zpracované mzdy a číselníky mezd - tento průvodce ho nevyužije, viz [Přechod z PAMICA](108_Prechod_z_PAMICA.md) |
| `92_sklad.xml` | sklady, členění skladu, karty zásob se stavem, ceníky, kusovníky a ocenění stavu každé karty po jejím posledním pohybu; samotné pohyby ne |

**Při běžném exportu nemusíte dělat nic.** `Export-Pohoda.cmd` skript spustí
sám po každé agendě a soubory přidá do její složky v ZIP. Datový soubor najde
ve složce dat POHODY; když leží jinde, zadejte ji parametrem `-DataDir`.
U POHODA SQL se připojí k instanci `.\POHODA`, jinou určí `-SqlServer`.
Když datový soubor nenajde, export doběhne a souhrn to uvede. Parametrem
`-BezMajetkuAMezd` se tento krok vynechá.

**Samostatně** skript spusťte, když máte jen datový soubor:

```
Export-PohodaMdb.cmd -Mdb "C:\...\StwPh_12345678_2026.mdb" -Vystup .\pohoda_export\12345678_2026
```

| Parametr | Význam |
|---|---|
| `-Mdb` | datový soubor POHODY |
| `-SqlServer`, `-Databaze` | místo `-Mdb` databáze POHODA SQL (`StwPh_<IČO>_<rok>`) |
| `-Vystup` | složka agendy pojmenovaná `<IČO>_<rok>`; podle ní průvodce pozná firmu a rok |
| `-Heslo` | heslo databáze, je-li jím datový soubor chráněný; bez parametru se na něj skript zeptá |

Složku `<IČO>_<rok>` pak zabalte do ZIP (samotnou, nebo spolu s XML exportem
agendy) a nahrajte do průvodce. Soubor vznikne, jen když v datovém souboru
něco je: majetek, jen když jsou karty, mzdy, jen když jsou zaměstnanci nebo
mzdy - tento průvodce ale z nahraného `91_mzdy.xml` převede jen majetek,
mzdy zpracuje [Přechod z PAMICA](108_Prechod_z_PAMICA.md). Systémové údaje
(kdo a kdy záznam změnil) skript nevytahuje.

Pro `.mdb` je potřeba ovladač Microsoft Access Database Engine, který se
instaluje s POHODOU. Když ho 64bitový PowerShell nenajde, skript se sám spustí
v 32bitovém. Když se datový soubor neotevře z jiného důvodu (heslo databáze,
soubor drží jiný program, nejde o databázi Accessu), skript ten důvod vypíše
i s hlášením ovladače. Datový soubor chráněný heslem databáze otevře
s parametrem `-Heslo`; bez něj se na heslo zeptá. Totéž platí pro převodník
`Export-PohodaMdbAccounting` (cesta 2).

#### 107.9.1.5 Cesta 2: účetnictví z MDB přes místní převodník

Tato cesta čte datový soubor přímo a vynechává postupné exportní požadavky
na XML rozhraní POHODY. Hodí se, když máte datový soubor MDB a export přes
POHODU trvá dlouho. Databázi POHODA SQL převede cesta 3 ([§ 107.9.1.6](#107916-cesta-3-export-z-pohoda-sql)).

1. V POHODĚ ověřte firmu a účetní rok, které chcete převést. Připravte
   odpovídající datový soubor `.mdb`. Zavřete POHODU u všech uživatelů
   a pracujte s kopií souboru. Pokud máte zálohu v ZIP, nejprve z ní obnovte
   datový soubor. ZIP zálohy není MDB a do převodníku nepatří.
2. V průvodci stáhněte *exportér a převodník (ZIP)* a rozbalte celý
   `pohoda-export.zip` do jedné složky na Windows.
3. Spusťte `Export-PohodaMdbAccounting.cmd` dvojklikem a vyberte připravenou
   kopii MDB. Převodník běží ve Windows PowerShellu 5.1, PHP na svém počítači
   instalovat nemusíte. Potřebuje ovladač Access. Pokud chybí, nainstalujte
   [Microsoft 365 Access Runtime z webu Microsoftu](https://support.microsoft.com/en-us/access/download-and-install-microsoft-365-access-runtime).
   Zvolte 32bitovou (x86) nebo 64bitovou (x64) variantu podle nainstalovaného
   Office.
4. Počkejte na vytvoření výsledného ZIP s XML účetnictví, majetku a mezd.
   Doplněk pro majetek a mzdy zvlášť spouštět nemusíte, převodník jej používá
   automaticky. Převodník vypíše cestu k výsledku. Zdrojový MDB zůstává
   na vašem počítači.
5. Do průvodce nahrajte až tento výsledný ZIP. Nerozbalujte jej
   a nenahrávejte místo něj MDB, původní zálohu ani stažený balíček nástrojů.
6. Průvodce formát automaticky rozpozná. Zkontrolujte firmu, rok a počty
   dokladů, spusťte zkoušku nanečisto a projděte její protokol před ostrým
   převodem.

Převod MDB běží na Windows, následný import ZIP funguje stejně i na serveru
s Linuxem. Server pro tuto cestu nepotřebuje ovladač Access ani přístup
k původnímu MDB. Mzdy se nadále převádějí samostatným průvodcem
[Přechod z PAMICA](108_Prechod_z_PAMICA.md).

Přijaté proforma faktury se převádějí jako přijaté zálohy včetně úhrad.
Neznámý typ faktury, bankovního nebo pokladního dokladu či zdroj úhrady
neblokuje převod ostatních dokladů. Konkrétní doklad se přeskočí a protokol
uvede jeho číslo a důvod. Případné zápisy dokladu v účetním deníku se zachovají.
Totéž platí pro neznámé typy dokladů v běžném XML exportu.

#### 107.9.1.6 Cesta 3: export z POHODA SQL

POHODA SQL ukládá každou účetní jednotku a rok do vlastní databáze na Microsoft
SQL Serveru, pojmenované `StwPh_<IČO>_<rok>`. Tabulky jsou stejné jako
v datovém souboru MDB, proto `Export-PohodaSQL.cmd` vytvoří **stejný ZIP jako
převod MDB** v cestě 2: `89_ucetnictvi_mdb.xml` s účetnictvím, `90_majetek.xml`,
`91_mzdy.xml` a `92_sklad.xml`, se stejnými kontrolami IČO a roku a se souhrny
vedle souborů.
Nástroj posílá jen dotazy `SELECT` přes šifrované spojení jen pro čtení
(`ApplicationIntent=ReadOnly`). Do databáze nic nezapisuje.

1. Rozbalte celý `pohoda-export.zip` do jedné složky na počítači s Windows,
   ze kterého je vidět SQL Server s POHODOU. Stačí Windows PowerShell 5.1,
   který je součástí Windows 10 a 11.
2. Zkopírujte vzor `pohoda-sql.example.json` jako `pohoda-sql.json` vedle
   skriptu a vyplňte připojení. Bez tohoto souboru se nástroj na všechno
   zeptá sám.
3. Spusťte `Export-PohodaSQL.cmd` dvojklikem. Když v konfiguraci chybí
   databáze, nástroj nabídne seznam agend na serveru (IČO, rok a název firmy
   z registru agend POHODY) a agendu vyberete číslem. Před exportem potvrdíte
   IČO a rok, které agenda uvádí.
4. Vedle skriptu vznikne složka `<IČO>_<rok>` a ZIP `<IČO>_<rok>.zip`. ZIP
   nahrajte do průvodce beze změny, nerozbalený.

Konfigurace `pohoda-sql.json` (hodnoty ve vzoru jsou fiktivní):

```json
{
  "host": "SERVER\\POHODA",
  "port": null,
  "database": "StwPh_12345678_2026",
  "user": "",
  "password": "",
  "trustServerCertificate": true,
  "driver": "sqlclient",
  "odbcDriver": "ODBC Driver 18 for SQL Server"
}
```

| Klíč | Význam |
|---|---|
| `host` | server, případně s pojmenovanou instancí (`SERVER\POHODA`; v JSON se zpětné lomítko píše dvakrát) |
| `port` | TCP port; prázdné nebo `null` = výchozí 1433, u pojmenované instance ho zjistí služba SQL Browser |
| `database` | databáze agendy `StwPh_<IČO>_<rok>`; prázdné = výběr ze seznamu |
| `user`, `password` | přihlášení SQL; prázdný `user` = přihlášení účtem Windows. Prázdné heslo se nástroj zeptá skrytě |
| `trustServerCertificate` | `true` = certifikát serveru se neověřuje (výchozí, POHODA SQL mívá certifikát podepsaný sám sebou) |
| `driver` | `sqlclient` (výchozí, součást Windows), nebo `odbc` |
| `odbcDriver` | jen pro `odbc`: název ovladače, výchozí `ODBC Driver 18 for SQL Server` |

Na klíč, který v souboru chybí, se nástroj zeptá: server, port, uživatele,
heslo (skrytě, nikam se nevypisuje) a důvěru v certifikát. Soubor
`pohoda-sql.json` s heslem po exportu smažte.

| Parametr | Význam |
|---|---|
| `-Config` | jiný konfigurační soubor než `pohoda-sql.json` vedle skriptu |
| `-Databaze` | databáze agendy; přebije `database` z konfigurace |
| `-Ico`, `-Rok` | vyberou agendu ze seznamu bez dotazu a ověří ji proti údajům agendy |
| `-Vystup` | složka pro výsledek; bez něj složka skriptu |
| `-BezZip` | nevytvářet ZIP |

**Uživatel SQL.** Nástroj potřebuje jen čtení. Doporučujeme založit samostatný
login s rolí `db_datareader` v databázi agendy. Aby nástroj nabídl u agend i
názvy firem, přidejte ho se stejnou rolí i do databáze `StwPh_sys`:

```sql
CREATE LOGIN myucto_export WITH PASSWORD = '<silné heslo>';
USE [StwPh_12345678_2026];
CREATE USER myucto_export FOR LOGIN myucto_export;
ALTER ROLE db_datareader ADD MEMBER myucto_export;
```

**Ovladač.** Výchozí ovladač SqlClient je součástí Windows a nic se
neinstaluje. Když se k serveru nepřipojí (typicky kvůli verzi TLS), zvolte
v konfiguraci `"driver": "odbc"` a nainstalujte
[Microsoft ODBC Driver for SQL Server](https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server).
Výsledek je s oběma ovladači stejný.

Soubory `90_majetek.xml`, `91_mzdy.xml` a `92_sklad.xml` jsou stejné jako u datového souboru
([§ 107.9.1.4](#107914-majetek-z-datoveho-souboru)). Mzdy z programu PAMICA SQL popisuje
[Přechod z PAMICA](108_Prechod_z_PAMICA.md).

### 107.9.2 Co převod přenese

| Z POHODY | Do MyÚčta |
|---|---|
| účtová osnova (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy |
| účetní rok | účetní období 1. 1. až 31. 12. |
| účetní deník včetně počátečních stavů | účetní zápisy, počáteční stavy jako otevírací zápis k 1. dni období |
| adresář | klienti, párování podle IČO |
| předkontace | pravidla zaúčtování se zkratkou z POHODY |
| přijaté a vydané faktury | doklady se stavem zaúčtováno nebo uhrazeno; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| ostatní pohledávky a závazky bez DPH | otevřené položky s jednoznačným již převedeným účetním zápisem na 315 nebo 325 |
| vydané doklady v režimu OSS (členění mimo přiznání, daň, stát MOSS) | plnění v režimu OSS včetně země spotřeby, typu sazby a typu plnění |
| interní daňové doklady | daňové doklady k platbě a samovyměření DPH u přijatých faktur |
| interní doklady | záznam v agendě Účetnictví → Interní doklady nad zápisem v deníku, s číslem z POHODY. Druh se odvodí: Zápočet (doklad hradí jiné doklady nebo text začíná na zápočet), Kurzový rozdíl (jen 563/663 proti pohledávce nebo závazku), Dohadná položka (časové rozlišení a dohady na účtech 381, 383, 384, 385, 388, 389), jinak Ostatní. Partner se převezme z řádků zápisu v deníku, jen když je jediný: známý se naváže na kartu, neznámý zůstane jménem na dokladu, karta se nezakládá. Export převodníkem musí být vytvořený aktuální verzí nástroje, starší export partnera v deníku nenese |
| pokladny a pokladní doklady | pokladny a zaúčtované pokladní doklady |
| bankovní účty a bankovní doklady | výpisy podle čísla výpisu v POHODĚ, bankovní pohyby; zůstatky jsou dopočtené z počátečního stavu v deníku a dorovná je první nahraný výpis banky se stavem |
| likvidace faktur | spárování faktury s bankovním pohybem nebo pokladním dokladem |
| karty dlouhodobého majetku (`90_majetek.xml`) | karty zařazené do užívání s počátečními stavy daňových a účetních odpisů |
| drobný majetek (`90_majetek.xml`) | karty evidence drobného majetku navázané na zdrojový doklad |
| sklady a karty zásob (`92_sklad.xml`) | sklady, skladové karty a počáteční stav každé karty po skladech k datu exportu |
| ceníky (`92_sklad.xml`) | cenové hladiny s cenou bez DPH u každé karty |
| členění skladu, alternativní jednotky, dodavatel karty (`92_sklad.xml`) | kategorie skladu, balení karty, dodavatel karty s nákupní cenou |

**Přeplatky a pohyby bez dokladu.** Bankovní nebo pokladní pohyb, který POHODA
zaúčtovala přímo na účet 315 nebo 325 (typicky přeplatek na analytice 315.999
nebo 325.999), doklad v agendě nemá. Převod spočítá nevyrovnaný zůstatek po účtu
a partnerovi a založí z něj ostatní pohledávku nebo závazek nad řádkem pohybu.
Nový účetní zápis nevzniká a zůstatek účtu se nemění, saldokonto tak sedí na
hlavní knihu.

- Partnera určí protiúčet, u pohybu bez něj název a nakonec variabilní symbol.
- Platba a vrácení téhož partnera a částky se vyruší a položku nezakládají.
  Částečné vrácení položku zmenší.
- Pohyb bez partnera i symbolu převod nezakládá a uvede ho v protokolu, stejně
  jako pohyb, jehož položka by rozdíl saldokonta zvětšila (protiváha je třeba
  v počátečním stavu), nebo pohyb v uzavřeném období.
- Opakovaný převod položky nezdvojí a položku, kterou jste stornovali, znovu
  nezaloží.

**Sklad.** Převádí se jen z nejnovější agendy v exportu a jen když ho
v náhledu necháte zaškrtnutý. Firma musí mít zapnutý sklad. Převede se stav,
ne historie: každá karta dostane ve svém skladu počáteční stav k datu exportu
(nejpozději ke konci roku agendy), jednou příjemkou za sklad. Hodnota stavu je
ocenění, které vede POHODA po posledním pohybu karty, takže sklad v MyÚčtu
sedí s POHODOU na haléř. Příjemka se neúčtuje: hodnotu zásob nese převedený
deník a do účetnictví se sklad promítne roční uzávěrkou.

- Karta, kterou POHODA vede ve více skladech, je v MyÚčtu jedna se stavem
  v každém skladu. Údaje karty se vezmou ze skladu s největším stavem.
- Typ karty: zásoba se převede jako zboží nebo materiál podle druhu zásoby
  z POHODY. Kartám bez druhu (POHODA ho vede jen při účtování zásob způsobem A)
  určíte typ v náhledu volbou u každého skladu. Volbou *Nepřevádět* sklad
  vynecháte celý; konsignační sklady jsou tak předvolené, jejich zásoba firmě
  nepatří.
- Služba se převede jako neskladová karta bez stavu. Komplet a výrobek jako
  výrobek; kusovník MyÚčto nevede a protokol uvede, kolik karet ho mělo.
  Textové položky a soupravy se nepřevádějí.
- Záporný stav se nepřevede, protokol uvede počet karet; doplňte ho inventurou.
  Šarže a výrobní čísla se nepřevádějí, stav karty je souhrnný.
- Základní ceník POHODY je prodejní cena karty. Ostatní ceníky se převedou
  jako cenové hladiny s pevnou cenou bez DPH (cenu s DPH převod přepočte sazbou
  karty), v měně ceníku.
- Pojmenované větve členění skladu jsou kategorie, alternativní jednotky karty
  balení a dodavatel karty její dodavatel s nákupní cenou, je-li v převedeném
  adresáři dodavatelem.
- Opakovaný převod nic nezdvojí: karta, stav i ceník, které už převedené jsou,
  zůstanou beze změny. Sklad, který v MyÚčtu už byl a má pohyby, dostane karty,
  ale stav do něj nejde.

**Majetek.** Karta vznikne jako zařazená, bez zápisu v deníku: zařazení
i dosavadní odpisy už v převedeném deníku jsou. Daňové odpisy za roky před
převáděným rokem se převezmou jako počáteční stav. Účetní odpisy navážou na
poslední měsíc, který POHODA zaúčtovala; plán skončí ve stejném měsíci jako
v POHODĚ a odpis roku v MyÚčtu je jen za zbytek roku. Majetkový účet se
určí z počátečního stavu účtu 01x až 03x ve výši vstupní ceny, účet oprávek
z odpisových zápisů karty. Kartu, u které něco z toho nejde spolehlivě určit
(neznámý typ majetku nebo odpisu, jiná daňová vstupní cena, chybějící plán,
účet), převod založí jako koncept a protokol uvede důvod. Majetek vyřazený
před převáděným rokem se nepřevádí.

**Drobný majetek.** Karty operativní evidence POHODY se převedou do evidence
drobného majetku včetně množství, ceny, umístění a vyřazení. Nic se
neúčtuje, náklad je v převedeném deníku. Zdrojový doklad karty dohledá už
exportní nástroj; převod pak kartu naváže na převedenou přijatou fakturu
a její položku (podle textu, jinak podle částky), na pokladní nebo interní
doklad. Číslo zdrojového dokladu zůstane na kartě i tehdy, když doklad
v převáděném roce není. Kartu, u které POHODA odkaz na doklad nevede (to je
častý případ), převod naváže na položku přijaté faktury se stejným datem
vystavení nebo DUZP a stejnou částkou bez DPH, jen když je taková položka
jediná a žádná jiná karta ji ještě nemá. Jinak karta zůstane bez dokladu
a doklad jde doplnit ručně.

**Zaúčtování se nepřepočítává.** Deník je přesná kopie toho, co bylo v POHODĚ,
a doklady se k němu jen připojí podle čísla dokladu. Z dokladu je proto vidět
jeho zápis a naopak a automatika už doklad znovu nezaúčtuje. Uzávěrkové zápisy
se nepřebírají. Agenda POHODY často vede i doklady po konci roku (výpisy
a faktury dalších měsíců). Jejich zápisy převod dá do účetního období podle
skutečného data; chybějící období následujícího roku založí otevřené. Převáděný
rok zůstane neuzavřený, uzávěrku a převod zůstatků do dalšího roku provede
účetní v MyÚčtu. Doklad s datem před převáděným rokem se zaúčtuje k prvnímu dni
období.

**Popisy zápisů se dogenerují.** POHODA veze v řádku deníku jen volný text, který
je u celé řady dokladů shodný („Fakturujeme Vám za …"). Po navázání dokladů proto
převod popisy přeskládá do tvaru **doklad - protistrana - obsah**, aby se zápisy
v deníku daly rozlišit; v protokolu to uvidíte jako *„U N převedených zápisů se popis
doplnil o číslo dokladu a protistranu."* Částek, účtů ani dat se to nedotýká a jde
to kdykoli zopakovat - viz [§ 52.14.13.1](52_Ucetni_denik.md#5214131-dogenerovani-popisu-u-prevzatych-zapisu).

**Doklady v režimu OSS.** Vydaná faktura, jejíž členění DPH stojí mimo přiznání
a přesto nese daň, je typicky prodej koncovému zákazníkovi do jiného členského
státu - v POHODĚ se vede vlastní zkratkou členění bez řádku přiznání, sazbou
státu spotřeby, odběratelem bez DIČ a vyplněným **státem MOSS**. Převod takový
doklad převezme rovnou jako [OSS plnění](45_OSS.md): nastaví na řádcích příznak
OSS, zemi spotřeby a typ sazby a doklad vstoupí do OSS přiznání, ne do českého.
Zemí spotřeby je stát MOSS z dokladu, adresa odběratele jen tehdy, když stát
MOSS chybí. Rozhoduje o tom stejné pravidlo jako u všech ostatních cest
([§ 45.10.8](45_OSS.md#45108-jak-vznika-oss-radek)), tedy číselník sazeb členských států.

Doklad s členěním mimo přiznání a s daní, který stát MOSS nemá, POHODA do svého
OSS přiznání nezahrnula. Převod ho proto převezme jako koncept k ruční kontrole
s důvodem *„… není v režimu OSS (chybí stát MOSS)"*; řádky s daní jsou navržené
podle země odběratele a označené k ručnímu posouzení. Rozhodněte, zda plnění
patří do OSS, nebo do tuzemského přiznání, a koncept potvrďte.

U dokladu v eurech převezme převod do OSS přiznání **částky v eurech přímo
z dokladu** (ruční částky pro OSS na řádku). Plnění v eurech se pro OSS
nepřepočítává, takže podání sedí na eura z POHODY. Tuzemská evidence dokladu
zůstává v korunách.

Aby to fungovalo, musí být před převodem splněné dvě věci:

- firma má **zapnutý režim OSS** ([§ 45.10.4](45_OSS.md#45104-platnost-registrace))
  s platností pokrývající převáděný rok,
- v číselníku DPH sazeb jsou **sazby států spotřeby** se správným státem
  ([§ 45.10.5](45_OSS.md#45105-sazby-dph-cizich-zemi-a-pole-stat) - formulář
  předvyplňuje `CZ`, což je nejčastější příčina, proč se doklad nepřevede).

Když některá chybí, řekne to protokol jednou větou hned u prvního takového
dokladu. Doklady, u kterých sazbu není na co navázat, převod nepřevezme a vypíše
je jmenovitě; po doplnění nastavení stačí převod zopakovat, doplní se jen ony.
Typ plnění (zboží/služba) převod bere z typu plnění MOSS na položce dokladu:
dodání zboží je zboží, ostatní druhy (elektronické, telekomunikační a ostatní
služby) jsou služba. Jen když ho položka nemá, odvodí se z měrné jednotky, karty
odběratele a CZ-NACE - pak u e-shopu se zbožím před převodem vyplňte
[výchozí typ plnění na kartě odběratele](45_OSS.md#45107-nastaveni-na-karte-odberatele)
nebo převažující činnost firmy, jinak řádky spadnou na výchozí „služba" (protokol
na to upozorní).

Export z datového souboru (MDB) nese údaje OSS jen z aktuální verze exportního
nástroje. Se starším exportem skončí doklady v režimu OSS jako koncepty kvůli
chybějícímu státu MOSS; vytvořte export znovu.

**Doklady k ruční kontrole.** Fakturu, jejíž daňovou povahu export spolehlivě
neurčuje, převod převezme jako koncept, například doklad s daní bez členění
DPH, doklad s neznámým členěním nebo samovyměření bez interního dokladu.
Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do účtování.
Protokol ho vypíše i s důvodem. Po opravě klasifikace DPH ho potvrďte.
U dokladů, které vypadaly na OSS a nerozhodlo se o nich, důvod rovnou říká, co
doplnit; hromadně je pak dorovná akce
[Nastavit OSS](14_Faktury.md#148-krok-za-krokem-hromadne-nastaveni-oss) v seznamu faktur.

Otevřené ostatní pohledávky a závazky bez DPH vzniknou jako samostatné položky,
jen když se shodují s jediným již převedeným zápisem v deníku na účtu 315 nebo
325. Účetní zápis se použije znovu a nevzniká druhý náklad ani výnos. Doklady
s likvidací, zálohou, cizí měnou nebo nejednoznačnou kontací zůstanou k ruční
kontrole. Interní doklady mimo přiznání k DPH mají zápis v deníku a záznam v agendě
Interní doklady. Zápis takového dokladu nejde smazat v deníku, opravuje se stornem
v detailu dokladu.

**Samovyměření jen na interních dokladech.** Někdy účetní vede přijatou fakturu
v členění mimo přiznání (například `PN`) a výstup i odpočet zachytí až dvojicí
interních dokladů navázaných na fakturu (vyměření `DD…`, odpočet `PD…`). Převod
takovou fakturu převezme se samovyměřením ze základu interního dokladu. U smíšené
faktury zůstane tuzemská část s daní dodavatele v odpočtu ř. 40/41. Když k faktuře
chybí interní doklad s odpočtem, převezme se samovyměření bez nároku na odpočet,
stejně jako ho podala POHODA.

**Platební brána.** Úhradu přes bránu vede POHODA jako několik bankovních dokladů
se stejným číslem: příjem, poplatek a odvod netto. Zápis v deníku pod tímto číslem
patří k pohybu, kterým POHODA hradí doklad. Úhrada faktury proto nezůstane mezi
nezaúčtovanými pohyby.

Číslo dokladu, které už ve firmě je, dostane příponu roku, například
`FV-0001/2026`.

### 107.9.3 Co převod nepřenese

- **Sklad.** Zápisy jsou v převedeném deníku, zásoby se zakládají v MyÚčtu.
- **Mzdy.** Tento průvodce mzdy nepřevádí, ani z datového souboru
  (`91_mzdy.xml`); ty převede samostatný průvodce
  [Přechod z PAMICA](108_Prechod_z_PAMICA.md).
- **Objednávky, nabídky a přílohy dokladů.** Skeny dokladů připojíte zvlášť
  v `Dokumenty → Skeny k dokladům`.
- **Číselné řady a podaná přiznání.** Přiznání k DPH a kontrolní hlášení za
  převáděný rok zůstávají v POHODĚ.
- **Cizí měny.** Doklad v cizí měně se převezme v měně a kurzu dokladu, když
  částky položek v měně přepočtené kurzem dávají na haléř koruny z POHODY
  (DPH, kontrolní hlášení i deník tak zůstávají v Kč přesně stejné). Jinak se
  převezme v Kč a poznámka dokladu i protokol uvedou důvod: samovyměření,
  odpočet nedaňové zálohy, částečná úhrada vydaného dokladu, položky jen
  z rekapitulace nebo nesedící přepočet. Měnu, kterou firma v číselníku měn
  nemá, převod založí a protokol to uvede.

### 107.9.4 Postup

1. **Export z POHODY.** Vytvořte export nástrojem ([§ 107.3](#1073-krok-za-krokem-export-z-pohody)) a nahrajte soubor
   `.zip`. Rozbalení a načtení běží na serveru na pozadí, u velkého exportu
   i několik minut; obnovení stránky mezitím průvodce nepřeruší.
2. **Náhled a volby.** Tabulka ukáže všechny agendy v exportu: IČO, firmu,
   rok, počty řádků deníku, počátečních stavů, dokladů a partnerů a rozsah
   zápisů. Převést jde jen agendu s IČO firmy v MyÚčtu. Roky k převodu
   zaškrtněte v prvním sloupci tabulky; předvybrané jsou všechny roky agend
   s IČO firmy. Soubory exportu, které POHODA vrátila prázdné nebo které
   v exportu chybí, průvodce vypíše jako informaci.

   Agenda POHODY často vede i doklady po konci roku. Každý takový pozdější
   rok průvodce nabídne jako samostatný řádek pod agendou („Doklady roku 2026
   vedené v agendě 2025"), starší doklady přenesené jako neuhrazené z minulých
   let samostatný rok netvoří. Pozdější rok jde převést jen spolu s rokem
   agendy: jeho zaškrtnutí vybere i rok agendy, odškrtnutí roku agendy ho
   zruší. Nevybraný pozdější rok převod přeskočí: zápisy deníku, pohyby
   v bance a pokladně, faktury a úhrady s datem v něm nepřevede a protokol
   uvede jejich počty. Faktura uhrazená až v nevybraném roce zůstane
   v MyÚčtu neuhrazená. Chcete-li třeba jen rok 2025, zaškrtněte jen jeho
   agendu; rok 2026 doplníte později opakovaným převodem ([§ 107.9.7](#10797-opakovany-prevod)).

   Kontrola před převodem se ukáže pro každý vybraný rok zvlášť. Převod
   zastaví, když u kteréhokoli vybraného roku:
   - export patří firmě s jiným IČO,
   - v exportu chybí nebo nejde přečíst účetní deník, osnova nebo členění DPH,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem
     (uzávěrkové zápisy a kurzové přecenění se nepočítají),
   - období je v MyÚčtu uzavřené.

   Obojí platí i pro vybrané období následujícího roku, do kterého padají
   doklady agendy s pozdějším datem.
3. **Zkouška nanečisto.** Proběhne celý převod vybraných roků včetně
   rekonciliace, na konci se ale všechno vrátí. Výsledkem je protokol za každý
   rok; v MyÚčtu nic nezůstane a nastavení automatiky se nezmění. Každý rok se
   zkouší samostatně a hned po své zkoušce se vrátí, pozdější rok proto ve
   zkoušce nevidí data předchozího roku (počáteční stavy, převzaté doklady
   a úhrady) a jeho výsledek se od ostrého převodu může lišit. Zkouška běží
   v databázové transakci, spouštějte ji proto mimo běžnou práci ve firmě.
4. **Ostrý převod.** Potvrzení vyjmenuje převáděné roky. Převod běží na
   pozadí, stránku můžete zavřít. Roky se převádějí vzestupně jeden po druhém
   a průběh ukazuje, kolikátý rok z kolika právě běží. Skončí-li rok chybou
   nebo převod zrušíte, další roky se nespustí a průvodce je vypíše. Po
   dokončení průvodce ukáže protokoly všech převedených roků a nabídne účetní
   deník a obratovou předvahu. Převod jedné firmy běží vždy jen jeden, druhý
   se do jeho konce nespustí.

#### 107.9.4.1 Navázání na existující číselnou řadu

Převod přenáší doklady s čísly, která měly v POHODĚ, ale počítadlo nové řady
tím sám nenastaví. Číslo, kterým má řada v MyÚčtu pokračovat, zadejte
v `Firma → Nastavení`, záložce **Číslování faktur** do pole **Příští číslo** u příslušné
šablony a potvrďte tlačítkem *Nastavit počítadlo*. Ukládá se samostatně, mimo
tlačítko *Uložit*, a po potvrzení ukáže náhled výsledného čísla.

Vlastní řadu může mít i jednotlivý zákazník nebo kategorie tržby; pole *Příští
číslo* je pak u jejich šablony. U zděděné šablony se pole nenabízí, protože se
čísluje řadou dodavatele a počítadlo je společné.

> [!WARNING]
> Zkontrolujte, že **perioda resetu sedí se šablonou**: u masky bez `{MM}`
> a měsíčního resetu by počítadlo prvního dne dalšího měsíce spadlo zpátky na
> začátek a čísla by kolidovala. Podrobně viz
> [§ 95.6](95_Multi_supplier.md#956-krok-za-krokem-cislovani-faktur).

> [!TIP]
> Sestava *Úplnost číselné řady* začne řadu počítat až od nastaveného čísla,
> takže začátek řady na vyšším čísle nehlásí jako chybějící doklady.

### 107.9.5 Rekonciliace a protokol

Každý převáděný rok (ve zkoušce i v převodu) má vlastní běh a protokol. Najdete v něm kroky převodu
s počty, upozornění a chyby a rekonciliaci převáděného roku:

- obratová předvaha MyÚčta proti předvaze spočtené přímo z deníku POHODY
  v exportu, po syntetických účtech, počáteční stav, obrat a konečný stav na haléř,
- obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné rozpracované zápisy,
- rozvaha vyrovnaná a všechny účty zařazené ve výkazu; účty, které výkazy
  neznají, protokol vypíše k přiřazení v mapování výkazů,
- doklady proti deníku: přijaté faktury proti 321, vydané proti 311, pokladna
  proti 211 a banka proti 221. Doklady účtované jinak (zápočet, úhrada v témže
  zápisu) protokol uvede zvlášť. Rozdíl, který je už v deníku POHODY, převod
  nehlásí jako chybu, ale vypíše ho po dokladech: doklad zní na jinou částku,
  než kolik jeho zápis v deníku POHODY dá na účet dokladu, a MyÚčto převzalo
  obojí beze změny. Typicky jde o odpočet nedaňové zálohy, který POHODA
  zaúčtovala kladně na stranu MD účtu 311 (například 311/602). Faktura je
  uhrazená, účet 311 ale v deníku drží navíc částku zálohy. Saldo takových
  dokladů zkontrolujte a případně opravte interním dokladem,
- saldokonto proti hlavní knize za každé období převodu, ke konci období, nejvýš
  k poslednímu zápisu v deníku. Rozdíl nad 1 Kč na účtu protokol uvede jako
  upozornění s účtem, zůstatkem hlavní knihy, součtem položek a rozdílem. Převod
  kvůli němu neselže, protože rozdíl může být oprávněný (například přeplatek bez
  dokladu). Porovnejte ho v `Účetnictví → Saldokonto`. Když sestavu nejde
  postavit (příliš mnoho otevřených položek), protokol to uvede jako informaci.

Doklad, ke kterému v deníku POHODY není zápis se stejným číslem, protokol
vypíše jako doklad bez zápisu. Takový doklad není zaúčtovaný. Zaúčtujte ho
ručně nebo hromadně v Účetnictví → Doúčtovat doklady.

Co je v protokolu chyba, upozornění a rozdíl k přijetí (například doklad se
sazbou DPH, kterou číselník nezná, nebo zápis bez data) a jak rozdíly přijmout
a převést i s nimi, popisuje
[§ 106.10.5.1](106_Prechod_z_Money_S3.md#1061051-chyby-upozorneni-a-rozdily-k-prijeti).

Úhradu faktury páruje převod podle likvidace, kterou POHODA u faktury drží:
číslo bankovního nebo pokladního dokladu a datum úhrady. Mezi pohyby se
stejným číslem rozhoduje datum. Nejednoznačnou úhradu převod nespáruje
a protokol ji vypíše k ručnímu spárování. Úhrada zápočtem nebo zálohou se
jako úhrada bankou ani pokladnou nepáruje. Spárovaná faktura dostane stav
uhrazeno.

#### 107.9.5.1 Pohyby, které POHODA nezaúčtovala

Bankovní pohyb s předkontací „Nevím" v deníku POHODY zápis nemá. Bývá to
úhrada faktury, kterou účetní ještě nezlikvidovala. Převod ji spáruje
s fakturou a rovnou zaúčtuje úhradu (321/221, u příjmu 221/311, na analytiku
předpisu faktury a banky) jako bankovní zápis pohybu, ale jen když je shoda
jednoznačná. Rozhoduje v tomto pořadí:

1. úhrada, kterou u faktury eviduje POHODA,
2. párovací symbol pohybu nebo jeho položky (číslo nebo VS otevřené faktury)
   a částka,
3. variabilní symbol pohybu a částka,
4. účet protistrany uvedený na přijaté faktuře, částka a datum platby
   v rozumném okně kolem splatnosti.

Výdaj se páruje jen s přijatou fakturou, příjem jen s vydanou. Faktura musí
být jediná pro pohyb a pohyb jediný pro fakturu. Nejistou shodu (víc faktur
se stejnou částkou, jiná částka při shodném symbolu) převod jen navrhne,
návrh najdete u výpisu. Platby kartou a ostatní pohyby zůstávají
k zaúčtování v Účetnictví → Doúčtovat doklady. Úhradu dokladu, jehož saldo
je v počátečních stavech nebo který v deníku POHODY zápis nemá, převod spáruje,
ale nezaúčtuje a protokol ji vypíše.

Zaúčtuje-li POHODA takový pohyb později sama, opakovaný převod novějšího
exportu odvozený zápis stornuje a pohyb nese zápis z deníku POHODY; úhrada
v deníku není dvakrát. Rekonciliace se zápisy odvozených úhrad počítá
a protokol uvede jejich počet.

U ostatních pohybů bez zápisu převod připraví návrh zaúčtování, když ho
podloží zaúčtované pohyby téže firmy. Hledá pohyby stejného směru se stejným
účtem protistrany, a nemá-li účet aspoň tři takové pohyby, se stejným jménem
partnera. Kontace se musí shodovat aspoň u tří pohybů a aspoň u 90 % z nich.
Složený zápis se počítá jako neshoda a kontace přes saldokonto (311, 321, 314,
324, 325) návrh nedá. Pohyb, který chytá jiné aktivní pravidlo firmy nebo čeká
na nejistou shodu s fakturou, návrh nedostane.

Návrh najdete v Bance na záložce K zaúčtování a u pohybu ve výpisu, tlačítky
ho schválíte, upravíte kontaci nebo odmítnete. Převod přitom nic nezaúčtuje.
Ke každému protiúčtu nebo partnerovi založí návrhové pravidlo s názvem
začínajícím „POHODA:", které nabídne stejnou kontaci i pohybům z příštích
výpisů. Samo účtovat nezačne ani po obnovení automatiky, na automatiku ho
můžete přepnout jen ručně. Protokol kroku uvede počet návrhů, pravidel a částku.

Opakovaný převod pravidla nezdvojí a neobnoví pravidlo, které jste smazali
nebo vypnuli, ani návrh, který jste odmítli. Zaúčtuje-li POHODA pohyb mezitím
sama, čekající návrh zanikne. Zápis ze schváleného návrhu je zápis vzniklý
v MyÚčtu, takže další úplný převod téhož období zastaví stejně jako jiné
zápisy mimo převod.

Protokoly všech běhů zůstávají v přehledu pod průvodcem. Protokol zkoušky
nanečisto z přehledu smažete, protokol ostrého převodu zůstává.

### 107.9.6 Režim účetnictví a automatika

Převod zapíše podvojné účetnictví od začátku převáděného roku do nastavení
firmy. Automatika účtování je během převodu vypnutá: deník přichází hotový
a každý automatický zápis nad týmiž doklady by byl duplicita.

Po úspěšném převodu se automatika vrátí do stavu před převodem. Firma, která
podvojné účetnictví zapíná právě převodem, dostane výchozí nastavení účetní
jednotky jako po aktivaci. Skončí-li převod chybou, automatika zůstane
vypnutá, dokud převod nedoběhne bez chyb.

### 107.9.7 Opakovaný převod

Převod si pamatuje, co z které agendy už vzniklo. Opakovaný převod téhož nebo
novějšího exportu založí jen to, co ještě chybí, a nic nezdvojí. Převod
přerušený chybou tak stačí po opravě spustit znovu. Takhle se převádí i další
rok: nahrajte export a zaškrtněte jiný rok. Stejně se doplní pozdější rok
agendy, který jste napoprvé nevybrali: převod založí jen jeho zápisy, pohyby,
doklady a úhrady a doklady uhrazené v tomto roce označí jako uhrazené. Zápisy, které do období dalšího roku
přinesla už agenda minulého roku, převod dalšího roku podruhé nezaloží.
Zápis, který dřívější převod posunul k 31. 12., opakovaný převod přesune do
období podle jeho data (jen v otevřených obdobích a mimo uzamčené datum).

Kontrola před převodem ([§ 107.9.4](#10794-postup)) běží i při opakovaném převodu
a stejně přísně: vznikl-li mezitím v některém z převáděných roků (včetně
pozdějšího roku agendy) ruční zápis, který nepochází z převodu, převod se zastaví
a nic nezapíše. Opakovaný převod do roku s vlastními zápisy tedy nejde; další rok
převádějte dřív, než v něm začnete účtovat ručně. Výjimkou je opravný převod vazeb
už převedené firmy: vlastní zápisy v MyÚčtu nechá beze změny a nové doklady
z POHODY nepřidá.

**Dorovnání novější zálohou.** Když už se v MyÚčtu účtuje a v POHODĚ mezitím přibyly
starší záznamy, převod s volbou dorovnání vlastní zápisy nechá a převezme jen záznamy
POHODY starší než první zápis zadaný v MyÚčtu. Novější záznamy POHODY nepřebere, aby
nevznikl dvojí zápis, a protokol uvede datum, od kterého se nepřebírají. Rekonciliace
tu může hlásit rozdíl, protože deník nese i vlastní zápisy.

Už převedené doklady ani zápisy deníku opakovaný převod nepřepisuje, protože
mohly být mezitím zaúčtované, spárované nebo upravené v MyÚčtu. Změnila-li se
v POHODĚ celková částka faktury, protokol ji vypíše jako změněnou v POHODĚ
a ponechanou v MyÚčtu; upravte ji ručně.

Před převodem firmy znovu od začátku stáhněte v průvodci **profil firmy** a po
ostrém převodu ho nahrajte zpět, viz [§ 96.13](96_Nastaveni.md#9613-krok-za-krokem-profil-firmy).

### 107.9.8 Omezení

- Převádí se kalendářní účetní rok, období se vždy založí od 1. 1. do 31. 12.
  (i období následujícího roku pro doklady agendy s pozdějším datem).
- Převod čte jen export vytvořený nástrojem, nikdy živou databázi POHODY.
- Jeden běh převede vybrané roky jedné firmy, každý rok s vlastním
  protokolem. Agenda roku musí mít IČO firmy v MyÚčtu.
- Nahraný export zůstává na serveru pro další běh. Smaže se po úspěšném
  ostrém převodu, který prošel všechny agendy firmy v exportu, jinak ho
  aplikace smaže po 24 hodinách bez práce s ním.
  Obsahuje-li export i `91_mzdy.xml`, nahrajte ho beze změny i do průvodce
  [Přechod z PAMICA](108_Prechod_z_PAMICA.md) - mzdy tento průvodce
  nepřevede.

## 107.10 Související kapitoly

- [Přechod z PAMICA](108_Prechod_z_PAMICA.md) - mzdy z POHODY Mzdy a PAMICA
- [Přechod z PREMIER](109_Prechod_z_PREMIER.md)
- [Režim OSS](45_OSS.md)
- [Souběh se starým systémem](111_Soubeh_se_starym_systemem.md)
- [Řešení problémů](999_Reseni_problemu.md)
