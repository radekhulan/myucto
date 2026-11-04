# 38. E-shop

> Návod, jak připravit skladové zboží pro prodej přes e-shop: založit číselníky,
> doplnit karty, naimportovat katalog, nacenit zboží a napojit externí e-shop.
> Pro provozovatele e-shopu a správce katalogu.

Modul **E-shop** rozšiřuje skladovou kartu zboží (`Sklad → Skladové karty`) o vše,
co potřebujete pro **prodej přes e-shop**: vícejazyčný popis a SEO, zařazení do
kategorií a označení štítky, typované parametry, poplatky (autorský, recyklační),
cenotvorbu odvozenou z nákupní ceny ve více měnách, dodavatele zboží a hromadný
import. Stránka `Sklad → E-shop` obsahuje číselníky, nastavení a správu hlavních
produktů s variantami. Obsah jednotlivého zboží upravujete na kartě konkrétní položky
v editoru skladové karty (záložky **Obecné**, **Jazyky**, **Kategorie**, **Parametry**,
**Ceny**, **Dodavatelé**, **Přílohy**).

## 38.1 Kdy to potřebujete

- Zavádíte e-shop a potřebujete založit výrobce, kategorie, atributy, štítky,
  měny a jazyky.
- Máte katalog v tabulce nebo v jiném programu a chcete ho hromadně nahrát.
- Potřebujete nastavit prodejní ceny tak, aby se samy počítaly z nákupní ceny.
- Chystáte akci, výprodej nebo zvláštní ceny pro vybrané odběratele.
- Napojujete vlastní e-shop nebo prostředníka na MyÚčto přes webhook a API.

<!-- cols: 26 42 32 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Založit číselníky (výrobci, kategorie, atributy, tagy, poplatky, měny, jazyky) | `Sklad → E-shop`, [§ 38.3](#383-krok-za-krokem-pripravit-ciselniky) |
| jednou při zavádění | Nahrát katalog zboží | `Sklad → E-shop`, záložka **Import zboží**, [§ 38.5](#385-krok-za-krokem-naimportovat-katalog) |
| u každého nového zboží | Doplnit popis, kategorie, parametry, ceny a dodavatele | editor skladové karty, [§ 38.4](#384-krok-za-krokem-pripravit-kartu-zbozi-pro-e-shop) |
| při změně nákupních cen | Zkontrolovat a přepočítat prodejní ceny | záložka **Ceny** na kartě, [§ 38.6](#386-krok-za-krokem-nacenit-zbozi) |
| před akcí | Založit akční cenu | záložka **Ceny**, sekce **Akční ceny**, [§ 38.7](#387-krok-za-krokem-akce-a-vyprodej) |
| při jednání s odběratelem | Nastavit individuální cenu nebo cenovou hladinu | [§ 38.8](#388-krok-za-krokem-ceny-pro-vybrane-odberatele) |
| jednou při napojení | Založit připojení externího systému | `Sklad → Integrace`, [§ 38.9](#389-krok-za-krokem-napojit-externi-e-shop) |

## 38.2 Než začnete

1. **Zapnutý Sklad.** E-shop je dostupný jen se zapnutým skladem (viz
   [Sklad](37_Sklad.md#373-krok-za-krokem-zapnout-sklad-a-zalozit-karty)). Bez něj se
   položka v menu nezobrazí.
2. **Oprávnění.** Číselníky a karty upravuje uživatel s právem zápisu. Uživatel jen
   se čtením vidí všechny záložky i report importu, ale nemá tlačítka pro zápis.
   Cenové profily a cenová matice vyžadují právo zápisu e-shopu i skladových karet,
   protože obsahují nákladové a maržové údaje. Integrační centrum vyžaduje oprávnění
   **Spravovat integrace e-shopu** (ve výchozím nastavení administrátor).
3. **Dodavatelé v adresáři.** Aby se klient objevil v nabídce dodavatelů, musí mít
   v adresáři zapnutou **roli dodavatele** (viz [Klienti](18_Klienti.md)). Na kartě
   zboží se dodavatel založit nedá.
4. **Kurzovní lístek** v databázi, chcete-li ceny v cizích měnách ([§ 38.11.8.8](#381188-cizi-meny-a-kurzy)).
5. **Plánovač úloh** pro přepočty katalogu na pozadí. Nastavuje ho správce (úloha
   `cron-catalog-worker`, viz [Po instalaci](05_Po_instalaci.md)).

> [!TIP]
> **Karta zboží nemusí mít skladový stav.** Vypnete-li u položky příznak **Skladová
> položka**, karta funguje bez jediné příjemky: jen se nacení a popíše, prodává se
> přes dodavatele (dropshipping) a skladové množství se nesleduje. E-shopová
> prezentace je proto nadstavba nad skladem, ne podmínka „napřed naskladni“.

> [!WARNING]
> **Kolik smí e-shop nabídnout: skladem mínus rezervováno.** U skladové položky se pro
> e-shop nepočítá holý fyzický stav, ale **prodejné množství** = skladem − rezervováno.
> Rezervované je zboží už vyfakturované zákazníkovi a dosud nevydané ze skladu. Díky
> tomu se stejný kus neprodá dvakrát. **Zboží na cestě** (objednané u dodavatele,
> ještě nedodané) se do nabídky **záměrně nepromítá**. Podrobně
> [Skladem, rezervováno, na cestě, u dodavatele](37_Sklad.md#37129-skladem-rezervovano-na-ceste-u-dodavatele).

## 38.3 Krok za krokem: připravit číselníky

Všechny číselníky mají stejný tvar: tabulka záznamů, tlačítko **Nový…** vpravo nahoře
a u každého řádku ikony **tužky** (upravit) a **koše** (smazat).

1. Otevřete `Sklad → E-shop` a přepněte se na záložku **Výrobci**. Klikněte na
   **Nový výrobce** a vyplňte **Kód** (jedinečný v rámci firmy), **Název**, případně
   **Web**, **Pořadí** a **Exportovat**. Uložte.
2. Záložka **Kategorie**: založte kategorie od nejvyšší úrovně. U podkategorií zvolte
   **Nadřazená kategorie**. Prázdná nadřazená znamená kategorii první úrovně.
3. Záložka **Atributy**: založte parametry zboží (barva, rozměr, výkon). Zvolte
   **Datový typ**. U typu s výběrem hodnot přidejte volby rovnou při zakládání.
4. Záložka **Tagy**: založte štítky (Novinka, Výprodej) s barvou.
5. Záložka **Poplatky**: založte typy poplatků (autorský, recyklační) a zvolte jejich
   **Sazbu DPH**.
6. Záložka **Měny**: přidejte prodejní měny, ve kterých budete uvádět ceny (viz
   [§ 38.11.19](#381119-meny)).
7. Záložka **Jazyky**: přidejte jazyky, ve kterých povedete popisy (viz
   [§ 38.11.13](#381113-jazyky)).
8. Záložka **Sklady**: sklady jsou stejné jako v modulu Sklad (viz
   [Sklady](37_Sklad.md#37126-sklady-vice-skladu)).

**Jak poznáte, že je hotovo:** záznamy jsou v tabulkách a nabízejí se na kartě zboží.
Výrobce se přiřazuje v poli **Výrobce** na záložce **Obecné**, kategorie a tagy
na záložce **Kategorie**, hodnoty atributů na záložce **Parametry**.

> [!WARNING]
> Import zboží výrobce **nezakládá**. Neexistuje-li kód výrobce v číselníku, řádek
> importu skončí chybou. Výrobce proto založte před importem.

### 38.3.1 Přesun kategorie

U řádku klikněte na tlačítko se šipkami, v dialogu **Přesunout kategorii** zvolte novou
nadřazenou kategorii (prázdné znamená nejvyšší úroveň) a potvrďte.

### 38.3.2 Smazání, nebo archivace

Je-li záznam použitý u nějakého zboží, smazání se odmítne hláškou, že je v použití.
Záznam **archivujte**: vypněte zaškrtávátko **Aktivní** ve formuláři.

## 38.4 Krok za krokem: připravit kartu zboží pro e-shop

1. Otevřete `Sklad → Skladové karty` a kartu zboží upravte (nebo založte novou).
2. Na záložce **Obecné** zvolte **Výrobce**, nastavte **Exportovat do e-shopu**
   a podle potřeby **Skladová položka** a **Cenovou bázi** ([§ 38.6](#386-krok-za-krokem-nacenit-zbozi)).
3. Na záložce **Jazyky** vyplňte název a popis. Čeština se otevře rovnou, další
   jazyk vyberte z aktivních jazyků.
4. Na záložce **Kategorie** přiřaďte jednu či více kategorií, jednu označte jako
   **hlavní**, a přidejte tagy.
5. Na záložce **Parametry** zadejte hodnoty atributů.
6. Na záložce **Ceny** nastavte prodejní ceny ([§ 38.6](#386-krok-za-krokem-nacenit-zbozi)).
7. Na záložce **Dodavatelé** přidejte dodavatele s nákupní cenou.
8. Na záložce **Přílohy** nahrajte obrázky a dokumenty.
9. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** karta se uloží a v seznamu karet vidíte její cenu.
Záložka **Přílohy** se ukládá samostatně: nahrání, změna pořadí, hlavní obrázek,
export i smazání platí okamžitě a nejsou součástí tlačítka **Uložit**.

> [!TIP]
> Tlačítko **Uložit** zapisuje základní údaje, e-shopový obsah, ceny, akční ceny
> a dodavatele jako celek. Neprojde-li kontrolou některá část, neuloží se nic.
> Podrobnosti o ukládání jsou v [§ 38.11.20](#381120-editor-karty-ukladani).

## 38.5 Krok za krokem: naimportovat katalog

Záložka **Import zboží** zakládá a aktualizuje skladové karty z CSV nebo XLSX do
50 MB.

1. Otevřete `Sklad → E-shop`, záložku **Import zboží**.
2. Vyberte soubor. U CSV nastavte oddělovač a kódování (UTF-8, Windows-1250 nebo
   ISO-8859-2), u XLSX zvolte list. Náhled ukáže hlavičku a první řádky. Pochází-li
   soubor z ABRA Flexi nebo POHODY, zvolte předvolbu ([§ 38.11.7.2](#381172-predvolby-abra-flexi-a-pohoda)).
3. Namapujte sloupce na údaje karty. Zvolte identitu podle SKU, interního ID nebo
   externího ID v pojmenovaném zdroji.
4. Vyberte režim zakládání, aktualizace, nebo obojího. Určete, zda se prázdné
   hodnoty zachovají, nebo vymažou. Mapování a pravidla uložte jako profil pro další
   soubor.
5. Spusťte náhled. Úloha ověří celý soubor a uloží rozdíly před zápisem. Report
   rozlišuje připravené řádky, řádky beze změny, chyby a konflikty.
6. Opravte chyby ve zdrojovém souboru a vytvořte nový náhled.
7. Potvrďte aplikaci připravených řádků. Samostatná úloha zapisuje po dávkách
   a ukazuje průběh.

**Jak poznáte, že je hotovo:** úloha dokončila zápis a report ukazuje počty
zapsaných řádků. Obsahoval-li import adresy médií, navazující úloha je stáhne až po
zápisu karet a jejich průběh je ve stejném reportu.

> [!TIP]
> Před prvním importem velkého katalogu vždy spusťte náhled, projděte problémové řádky
> a chyby opravte přímo ve zdrojovém souboru. Ušetříte si opravy karet po částečně
> nepovedeném importu.

## 38.6 Krok za krokem: nacenit zboží

Prodejní cena není hodnota, kterou prostě zadáte. Je to **výsledek výpočtu**, který
systém přepočítává z nákupní ceny ([§ 38.11.8](#38118-cenotvorba)).

### 38.6.1 Nacenění nového skladového zboží

1. Založte kartu a nechte zapnutou **Skladová položka**.
2. Na záložce **Obecné** nastavte **Cenová báze** na **Vážený průměr**.
3. Zboží naskladněte příjemkou (tím vznikne nákupní cena).
4. Na záložce **Ceny** upravte připravený řádek `CZK`: režim **Přirážka %**, hodnota
   podle cílové marže (převodní tabulka v [§ 38.11.8.3](#381183-prirazka-vs-marze-neplette-si-je)),
   zaokrouhlení **Na koruny (1)** nebo **Na 9 na konci**.
5. Klikněte na **Přepočítat** a zkontrolujte sloupec **Výsledná cena**.

**Jak poznáte, že je hotovo:** ve sloupci **Výsledná cena** je částka. Prázdná hodnota
znamená chybějící nákupní cenu nebo kurz ([§ 38.11.8.6](#381186-zalozka-ceny)).

### 38.6.2 Nacenění dropshippingového zboží

1. Vypněte **Skladová položka**, **Cenová báze** nastavte na **Ruční**.
2. Na záložce **Dodavatelé** přidejte dodavatele s **nákupní cenou, měnou, kódem
   a dodací lhůtou** a označte ho jako **Preferovaného**.
3. Na záložce **Ceny** nastavte `CZK` s přirážkou a klikněte na **Přepočítat**.

### 38.6.3 Změna dodavatele nebo jeho ceníku

1. Upravte nákupní cenu na záložce **Dodavatelé**. U karet s bází **Ruční** se cena
   přepočte hned po uložení. Přepočet prodejních cen spustí **každý** zápis nabídky,
   tedy i její založení a smazání.
2. Přesouváte-li nákup k jinému dodavateli, zapněte u něj **Preferovaný**. Starého
   nechte v seznamu jako záložní zdroj.

U rozsáhlého ceníku použijte hromadný import z XLSX nebo CSV (`Sklad → U dodavatele →
Import ceníku`). Páruje se podle SKU karty a dodavatele, běží v náhledu s výpisem změn
`z → na` a nikdy nic nemaže ani nezakládá. Formát popisuje
[Import ceníku dodavatele](37_Sklad.md#3712102-import-ceniku-dodavatele).

### 38.6.4 Kontrola marže

Cílová marže slouží k nacenění. Pro kontrolu skutečné marže porovnejte:

- **nákupní cenu** ve skladových sestavách (ocenění zásob,
  [Skladové sestavy](37_Sklad.md#37128-skladove-sestavy)) nebo na záložce **Dodavatelé**,
- **prodejní cenu** ve sloupci **Výsledná cena**.

Marži spočítáte jako `(prodej − nákup) ÷ prodej × 100`. Pro pravidelnou kontrolu
vyexportujte skladové karty a dopočtěte ji v tabulkovém procesoru.

### 38.6.5 Pravidla a obchodní kurzy pro celý katalog

1. Otevřete `Sklad → E-shop`, záložku **Cenová pravidla**.
2. Nastavte profil pro jednu měnu: přirážku nebo cílovou marži, zaokrouhlení, zdroj
   obchodního kurzu a jeho maximální stáří.
3. Přiřaďte profil pravidlem ke kartě, kategorii, výrobci, dodavateli nebo celé firmě.
4. Na cenovém řádku karty zapněte **Cenová pravidla**. Bez této volby zůstává lokální
   nastavení řádku.

**Jak poznáte, že je hotovo:** na stránce i v úlohách katalogu vidíte průběh přepočtu
existujících cenových řádků. Podrobnosti viz [§ 38.11.8.4](#381184-cenove-profily-pravidla-a-obchodni-kurzy).

### 38.6.6 Hromadná úprava cen v cenové matici

1. Otevřete `Sklad → E-shop`, záložku **Cenová matice**.
2. Vyberte karty a měny a vytvořte náhled.
3. Prohlédněte náklad, výslednou cenu, marži a použitý kurz. Filtrujte podle stavu,
   měny, chybějící ceny, ruční výjimky nebo odchylky od původní ceny.
4. Potvrďte hotový náhled. Změny se zapíší až po potvrzení.

Podrobnosti viz [§ 38.11.8.5](#381185-cenova-matice).

## 38.7 Krok za krokem: akce a výprodej

1. Otevřete kartu zboží, záložku **Ceny**, sekci **Akční ceny**, a klikněte na
   **Přidat akci**. Zadejte akční částku a měnu.
2. Určete, čím je akce omezená: **datum** (**Platí od** a **Platí do**), **počet kusů**,
   nebo obojí. Nevyplněné omezení neplatí.
3. Kartu uložte. Standardní cenu nechte beze změny: akce ji přebije jen po dobu své
   platnosti a po skončení se cena sama vrátí.
4. Zboží v akci můžete navíc označit **tagem** (například Výprodej, [§ 38.11.5](#38115-tagy))
   kvůli filtrování a exportu.

**Jak poznáte, že je hotovo:** sloupec **Stav** u akce ukazuje **Probíhá**
(nebo **Naplánovaná**, začíná-li později) a v seznamu karet je původní cena
přeškrtnutá.

> [!TIP]
> Postup s režimem **Fixní cena** a zaškrtnutou volbou **Ruční** používejte jen na
> **trvalou** změnu ceny. Pro časově nebo množstevně omezenou slevu je akční cena
> vždy lepší, nemusíte hlídat její konec.

## 38.8 Krok za krokem: ceny pro vybrané odběratele

### 38.8.1 Individuální cena odběratele

1. Otevřete kartu zboží, záložku **Ceny**, sekci individuálních cen zákazníků.
2. Přidejte řádek: odběratele, měnu, **pevnou cenu** nebo **slevu v %**, volitelně
   platnost od-do a poznámku.
3. Klikněte na **Uložit** editoru.

**Jak poznáte, že je hotovo:** řádek ukazuje výslednou cenu podle dnešní standardní
ceny. Při vystavení faktury tomuto odběrateli se použije jako základ
(pořadí viz [§ 38.11.8.12](#3811812-individualni-ceny-zakazniku)).

### 38.8.2 Cenová hladina

1. Otevřete `Sklad → E-shop`, záložku **Cenové hladiny**, a založte hladinu
   (**Název**, **Výchozí sleva nebo přirážka**, **Pořadí**).
2. V detailu hladiny přidejte pravidla pro produkt, kategorii nebo výrobce.
3. Hladinu přiřaďte odběrateli na jeho kartě (viz [Klienti](18_Klienti.md#1872-pole-formulare)).
4. Jednorázově ji můžete zvolit i na faktuře ve výběru **Cenová hladina dokladu**.

**Jak poznáte, že je hotovo:** řádek faktury nacení cenou podle hladiny a pod cenou ukáže
ceníkovou cenu a slevu („Ceník 1 000,00 · sleva 10 %“). Zákazníkovi ji na PDF rozepíšete
volbou **Na PDF faktury rozepsat slevu z ceníkové ceny**, viz
[§ 16.10.4](16_Faktura_PDF.md#16104-pdf-struktura). Přirážka hladiny se na PDF nerozepisuje.

Podrobnosti viz [§ 38.11.18](#381118-cenove-hladiny).

## 38.9 Krok za krokem: napojit externí e-shop

Integrační centrum propojuje MyÚčto s e-shopem nebo jiným externím systémem. Pro
každé propojení založíte **připojení**.

1. Otevřete `Sklad → Integrace` a vytvořte připojení (nebo upravte **Ukázkové napojení**).
2. **Krok 1 - Konektor**: zvolte **Vlastní napojení přes webhook a API** a pojmenujte
   připojení.
3. **Krok 2 - Mapování**: u sklady, měny, jazyky a sazby DPH přiřaďte místní hodnotě
   kód v externím systému (**Přidat řádek**).
4. **Krok 3 - Vlastnictví polí**: u každého pole zvolte, zda pravdu drží MyÚčto,
   externí systém, nebo se rozhoduje ručně.
5. **Krok 4 - Přístupy**: vyplňte přístupové údaje, pokud je konektor potřebuje.
6. Klikněte na **Uložit**.
7. **Krok 5 - Webhook**: klikněte na **Vytvořit secret**, secret **ihned uložte**
   do externího systému a zkopírujte adresu webhooku.
8. **Krok 6 - Aktivace**: nastavte stav **Aktivní**.

**Jak poznáte, že je hotovo:** připojení je **Aktivní**, webhook odpovídá a přehled
provozu pod editorem ukazuje události. Změny katalogu si externí systém stahuje sám
(viz [§ 38.11.16.7](#3811167-stahovani-zmen-katalogu)).

> [!TIP]
> Pro Shoptet bez API použijte hotové napojení přes soubory v kapitole
> [Shoptet](39_Shoptet.md). Konektor Shoptet v Integračním centru je zatím
> ve stavu Připravujeme.

## 38.10 Když něco nejde

<!-- cols: 34 32 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Hláška „…s tímto kódem už existuje“ | Kódy jsou jedinečné v rámci firmy | Použijte jiný kód |
| Smazání číselníku se odmítne, že je v použití | Záznam je použitý u zboží | Archivujte (vypněte **Aktivní**) |
| Řádek importu skončil chybou u výrobce | Kód výrobce v číselníku neexistuje | Založte výrobce, pak import zopakujte |
| Import označil řádek konfliktem | Karta se mezi náhledem a aplikací změnila ručně | Vytvořte nový náhled |
| Duplicitní identita v importu | Stejný identifikátor je ve víc řádcích | Opravte zdrojový soubor |
| **Výsledná cena** je prázdná | Chybí nákupní cena, nebo kurz měny | Naskladněte, nebo doplňte preferovaného dodavatele s cenou; doplňte kurz a klikněte na **Přepočítat** |
| Cena se nepřepočítá | Řádek je **Ruční**, nebo neběží plánovač | Odškrtněte **Ruční**; zkontrolujte úlohu `cron-catalog-worker` |
| Cena zůstala na staré hodnotě | Kartě chybí řádek `CZK`, takže se prodejní cena karty neaktualizuje | Na záložce **Ceny** založte řádek `CZK` |
| V nabídce dodavatelů nikdo není | Klient nemá roli dodavatele | Zapněte roli dodavatele v adresáři klientů |
| Akce neplatí na celý řádek faktury | Strop akce nestačí na množství řádku | Rozdělte řádek ([§ 38.11.8.11](#3811811-akcni-ceny)) |
| Editor odmítl uložení jako konflikt | Kartu mezitím uložil jiný uživatel | Porovnejte rozepsané hodnoty s aktuálním stavem a uložte znovu |
| Kód jazyka nejde změnit | K jazyku už existují překlady | Založte nový jazyk a překlady přepište |
| Webhook vrací **401** | Připojení není aktivní, chybí secret, hodiny se liší o víc než 5 minut, nebo se podepisuje jiné tělo | Ověřte podpis nástrojem v kroku 5 |
| Webhook vrací **409** | Stejné `event_id` už přišlo s jiným obsahem | Každá změna potřebuje nové `event_id` |
| Webhook vrací **400** | Chybí povinné pole nebo má špatný tvar | Zkontrolujte tělo podle kontraktu |
| Změnový feed vrací **410** | Externí systém se dlouho nepřipojil | Musí stáhnout celý katalog znovu |
| Připojení je ve stavu Chyba | Porovnání úplnosti našlo propojení na neexistující kartu | Opravte propojení a spusťte **Porovnat úplnost** znovu |
| Uložení připojení hlásí, že hodnota ve firmě neexistuje | Sklad, jazyk nebo měna byly smazány | Opravte nebo odeberte řádek mapování |

## 38.11 Podrobnosti a pravidla

### 38.11.1 Přehled záložek

Stránka `Sklad → E-shop` má nahoře vodorovné záložky. Stav záložky se ukládá do URL,
takže jde odkázat i na obnovení stránky.

<!-- cols: 26 74 -->
| Záložka | Obsah |
|---|---|
| **Variantní produkty** | Hlavní produkty s variantami ([§ 38.11.14](#381114-varianty-a-vztahy-produktu)) |
| **Výrobci** | Číselník výrobců a značek zboží |
| **Kategorie** | Strom kategorií e-shopového katalogu |
| **Atributy** | Typované parametry zboží (barva, rozměr, výkon) včetně voleb pro výběrové atributy |
| **Tagy** | Barevné štítky zboží |
| **Poplatky** | Typy poplatků (autorský, recyklační) s vlastní sazbou DPH |
| **Balení** | Kódy balení (karton, paleta), které karty používají jako nadřazené jednotky ([§ 38.11.17](#381117-baleni)) |
| **Cenové hladiny** | Hladiny odběratelů (Bronze, Silver, Gold) s výchozí slevou nebo přirážkou a pravidly pro produkty, kategorie a výrobce ([§ 38.11.18](#381118-cenove-hladiny)) |
| **Jazyky** | Jazykové mutace, ve kterých vedete názvy a popisy zboží a kategorií ([§ 38.11.13](#381113-jazyky)) |
| **Měny** | Prodejní měny ([§ 38.11.19](#381119-meny)) |
| **Sklady** | Stejná záložka jako číselník skladů v modulu Sklad, sklady patří oběma pohledům |
| **Import zboží** | Hromadný import a aktualizace karet z XLSX nebo CSV |
| **Cenová pravidla** | Cenové profily, pravidla a obchodní kurzy |
| **Cenová matice** | Porovnání a hromadná úprava cen po měnách |

Každý číselník (Výrobci, Kategorie, Atributy, Tagy, Poplatky, Balení, Cenové hladiny,
Jazyky) má stejný tvar: tabulka existujících záznamů, tlačítko **Nový…** vpravo nahoře
a u každého řádku ikony **tužky** (upravit) a **koše** (smazat). Editace i mazání jsou
dostupné jen uživatelům s právem zápisu. U čtenáře akční sloupec zmizí úplně.

### 38.11.2 Výrobci

Jednoduchý číselník značek: **Kód**, **Název**, **Web** (odkaz, otevře se v novém
okně), **Pořadí** (řadí výpis v e-shopu), příznak **Exportovat** (zda se výrobce
zobrazí na e-shopu) a **Aktivní**. Kód musí být v rámci firmy jedinečný.

Výrobce přiřadíte kartě zboží v poli **Výrobce** na záložce **Obecné** editoru skladové
karty. Import zboží umí výrobce **přiřadit podle kódu**, ale **nezakládá je**. Pokud kód
v souboru mezi výrobci neexistuje, řádek importu skončí chybou s instrukcí založit
výrobce nejdřív ručně.

### 38.11.3 Kategorie

Kategorie tvoří **strom** (kategorie může mít nadřazenou kategorii, ta svou vlastní
atd.). V tabulce se odsazují podle hloubky a mají ikonu šipky u podkategorií.
Formulář obsahuje:

- **Kód**, **Název**,
- **Nadřazená kategorie**, vyhledávací výběr se seznamem existujících kategorií
  (odsazeno podle úrovně). Prázdné znamená kategorii první úrovně,
- **Pořadí** zobrazení,
- příznak **Exportovat** (viditelnost na e-shopu) a **Aktivní**.

Sloupec **Cesta** v tabulce ukazuje interní cestu stromem (například `/12/45/`).
Slouží k rychlému dohledání podstromu, důležitý je hlavně vizuální odsazený název.

#### 38.11.3.1 Přesun kategorie

Tlačítko se šipkami u řádku otevře dialog **Přesunout kategorii**, kde vyberete novou
nadřazenou kategorii (nebo necháte prázdné pro přesun na nejvyšší úroveň). Nabídka
**vylučuje samotnou kategorii a celý její podstrom**, takže kategorii nelze zacyklit
(udělat z ní vlastního potomka). Přesun rovnou přepočítá cestu a hloubku pro celý
přesouvaný podstrom.

Kategorie s podřízeným zbožím nebo podkategoriemi nelze smazat. Systém nabídne
archivaci místo mazání (viz [§ 38.11.11](#381111-mazani-vs-archivace)).

Kartě zboží přiřadíte jednu i více kategorií na záložce **Kategorie** editoru
skladové karty, kde navíc označíte jednu jako **hlavní** (pro drobečkovou navigaci
a kanonickou URL na e-shopu).

### 38.11.4 Atributy (parametry)

Atributy jsou **typované parametry zboží**. Na rozdíl od volného textu mají přesně daný
datový typ, takže je lze na e-shopu i filtrovat. Formulář nového atributu obsahuje:

- **Kód** a **Název** (například „barva“, „Barva“),
- **Datový typ**: `Text`, `Number`, `Boolean`, nebo `Enum (Volby)`. U posledního se
  atribut vybírá z předem definovaného seznamu hodnot,
- **Měrná jednotka** (nepovinná, například „kg“, „cm“, „ks“, smysl dává hlavně
  u `Number`),
- **Pořadí** zobrazení,
- **Filtrovatelný**, příznak pro budoucí facetové filtrování na e-shopu,
- **Vícehodnotový**, povolí u karty zboží přiřadit atributu víc hodnot najednou
  (typicky u `Enum`, například „dostupné velikosti“),
- **Aktivní**.

#### 38.11.4.1 Volby atributu (jen typ Enum)

V okně úpravy atributu je pod základním formulářem sekce **Možnosti/Volby atributu**:
tabulka voleb (Kód, Popisek, Pořadí) a formulář pro přidání nové volby. Chování se
liší podle toho, zda atribut zakládáte, nebo upravujete:

- **Při zakládání** nového atributu se volby jen **uloží lokálně** (ještě nemají
  identifikátor na serveru) a **odešlou se až po uložení atributu**, postupně ve
  frontě. Selže-li část požadavků, zbytek fronty přežije i po opravě a opětovném
  uložení.
- **Při úpravě** existujícího atributu se volby ukládají a mažou **rovnou**.

Každá volba má svůj **Kód** (technický, používá se i při párování importu a API)
a **Popisek** (zobrazovaný text, kód se z něj typicky automaticky odvodí, lze ho ale
ručně přepsat).

Konkrétní hodnoty atributů se zadávají na záložce **Parametry** editoru skladové karty
pro každou kartu zvlášť. Atribut typu `Enum` bez alespoň jedné volby nemá u karty co
nabídnout k výběru, volby proto zakládejte rovnou při vytváření atributu.

### 38.11.5 Tagy

Tagy jsou volné barevné štítky zboží (například „Novinka“, „Výprodej“, „TOP prodej“).
Na rozdíl od kategorií a atributů nejsou hierarchické ani typované, slouží jen
k vizuálnímu odlišení a filtrování na e-shopu. Formulář: **Kód**, **Název**, **Barva**
(textové pole ve formátu `#RRGGBB` a barevný výběr vedle něj) a **Aktivní**. V tabulce
je u každého tagu barevný čtvereček, aby bylo na první pohled jasné, jak bude vypadat
na e-shopu.

Kartě zboží přiřadíte libovolný počet tagů na záložce **Kategorie** editoru skladové
karty.

### 38.11.6 Poplatky

Poplatky reprezentují dodatečné zákonné příplatky ke zboží, typicky **autorský
poplatek** nebo **recyklační poplatek (PHE)** za elektrozařízení. Formulář: **Kód**
(interní, například `copyright`, `recycling`), **Název**, **Sazba DPH** (výběr
z číselníku sazeb DPH, nebo „Bez DPH“, takže poplatek může mít jinou sazbu než
samotné zboží) a **Aktivní**.

Konkrétnímu zboží pak přiřadíte **částku** poplatku (v dané měně, s příznakem, zda je
částka „s DPH“) přímo na kartě zboží. Poplatek s navázaným zbožím nelze smazat, jen
archivovat.

> [!TIP]
> Recyklační poplatek (PHE) je u elektrozařízení a baterií ze zákona povinný a musí být
> na e-shopu **viditelně uveden odděleně od ceny zboží**. Proto je veden jako samostatný
> typ poplatku s vlastním režimem DPH, ne jako součást prodejní ceny.

### 38.11.7 Import zboží

Záložka **Import zboží** zakládá a aktualizuje skladové karty z CSV nebo XLSX do 50 MB.
Soubor se uloží s kontrolním součtem, validace a zápis běží na pozadí jako samostatné
úlohy. Průběh a výsledky zůstávají v historii úloh. Postup je v
[§ 38.5](#385-krok-za-krokem-naimportovat-katalog).

#### 38.11.7.1 Postup

1. Vyberte soubor. U CSV nastavte oddělovač a kódování UTF-8, Windows-1250 nebo
   ISO-8859-2, u XLSX zvolte list. Náhled ukáže hlavičku a první řádky.
2. Namapujte sloupce na údaje karty. Zvolte identitu podle SKU, interního ID nebo
   externího ID v pojmenovaném zdroji. U externího ID se existující karta nehledá
   náhradně podle shodného SKU.
3. Vyberte režim zakládání, aktualizace, nebo obojího. Určete zachování či vymazání
   prázdných hodnot. Mapování a pravidla lze uložit jako verzovaný profil pro další
   soubor.
4. Spusťte náhled. Úloha ověří celý soubor a uloží rozdíly před zápisem. Report je
   stránkovaný a rozlišuje připravené řádky, řádky beze změny, chyby a konflikty.
5. Potvrďte aplikaci připravených řádků. Samostatná úloha zapisuje po dávkách a ukazuje
   průběh. Chybné řádky se nezapisují, opravte je ve zdroji a vytvořte nový náhled.
6. Obsahuje-li import adresy médií, navazující úloha je stáhne až po úspěšném zápisu
   karet. Průběh a chyby jednotlivých adres jsou vidět ve stejném reportu importu.

#### 38.11.7.2 Předvolby ABRA Flexi a POHODA

Předvolba pouze vyplní mapování podporovaných sloupců. Nastavení můžete před náhledem
ručně upravit stejně jako běžný profil.

**ABRA Flexi - ceník v CZK (CSV)**

Předvolba načítá `cenaZaklBezDph` jako cenu v CZK bez DPH. Použijte ji pro korunový
ceník. U jiné měny toto cenové pole nemapujte a ceny nastavte samostatně.

1. V API použijte evidenci `cenik`, filtr `(exportNaEshop=true)` a formát `.csv`.
2. Vyžádejte detail `custom:kod,nazev,eanKod,cenaZaklBezDph,skladove,exportNaEshop,popis`,
   kódování UTF-8 a oddělovač středník. Parametr ABRA Flexi se v URL píše
   `delimeter=%3B`.
3. Nahrajte CSV a zvolte předvolbu **ABRA Flexi - ceník v CZK**.

```text
/c/FIRMA/cenik/(exportNaEshop=true).csv?detail=custom:kod,nazev,eanKod,cenaZaklBezDph,skladove,exportNaEshop,popis&limit=0&encoding=utf-8&delimeter=%3B
```

Předvolba mapuje `kod`, `nazev`, `eanKod`, `cenaZaklBezDph`, `skladove`,
`exportNaEshop` a `popis`. Hlavička byla ověřena proti veřejnému DEMO API ABRA Flexi.
Soubor z konkrétní zákaznické instalace a konkrétní verze ERP ověřen nebyl.
Podrobnosti popisuje [oficiální návod ABRA Flexi pro napojení e-shopu](https://www.flexibee.eu/napojeni-na-internetovy-obchod/)
a [dokumentace podporovaných formátů](https://podpora.flexibee.eu/cs/articles/3638755-jak-zacit-s-api-flexi-5-6-podporovane-formaty).

**POHODA - tabulka zásob (XLSX)**

1. Otevřete **Sklady > Zásoby** a v tabulce vyberte **Export tabulky**.
2. Do exportu zařaďte přesně sloupce `Kód`, `Název`, `M. j.` a `Čár. kód`. Výběr lze
   v POHODĚ uložit jako šablonu.
3. Zvolte XLSX, identifikátory ponechte jako text, nahrajte první list a použijte
   předvolbu **POHODA - tabulka zásob**.

POHODA předvolba záměrně neimportuje ceny, DPH ani skladové stavy. Přesné názvy čtyř
sloupců vycházejí z oficiální dokumentace uživatelského rozhraní. Export z konkrétní
verze POHODY ověřen nebyl. Postup exportu a ukládání šablon popisuje
[oficiální návod POHODA](https://www.stormware.cz/podpora/faq/pohoda/198/Jak-mohu-vyexportovat-udaje-z-tabulky-do-excelu-nebo-jako-textovy-soubor/?id=3257&p=4),
názvy polí jsou v [podrobném nastavení zásob](https://www.stormware.cz/prirucka-pohoda-online/Sklady/Podrobne_nastaveni/).

#### 38.11.7.3 Sloupce souboru

Názvy a pořadí sloupců jsou volitelné, jejich význam určuje mapování. SKU a textové EAN
zachovávají úvodní nuly. V XLSX proto ukládejte identifikátory jako text, číslice
odstraněné už tabulkovým editorem nelze obnovit.

<!-- cols: 32 68 -->
| Údaj | Význam |
|---|---|
| SKU | Katalogové číslo, nejvýše 50 znaků, povinné při zakládání nové karty |
| Název | Povinný pro novou kartu, nejvýše 255 znaků |
| Jednotka a EAN | Jednotka má výchozí hodnotu `ks`, EAN zůstává textem |
| Prodejní cena v CZK | Pevná cena bez DPH, například `1234.50` nebo `1 234,50`. Ostatní měny zůstávají zachované |
| Typ, DPH a minimum | Typ zboží, materiál nebo výrobek, ID existující sazby DPH a minimální množství |
| Výrobce | Kód nebo ID existujícího výrobce dané firmy |
| Aktivita a export | Logická hodnota `1`/`0`, `ano`/`ne`, `true`/`false` nebo `yes`/`no` |
| Hmotnost, záruka a dodání | Celá nezáporná čísla v gramech, měsících a dnech |
| Kategorie, štítky, překlady, parametry a poplatky | Pokročilé sloupce s JSON seznamy odpovídajícími údajům karty |
| Měnové ceny | JSON seznam cenových řádků. Nelze současně mapovat jednoduchou CZK cenu a měnové ceny |
| Adresy médií | JSON seznam nejvýše 10 veřejných HTTPS adres obrázků nebo dokumentů pro jednu kartu |

#### 38.11.7.4 Chování importu

- Nenamapované údaje se nemění. Prázdné hodnoty se standardně zachovávají, vymazání
  musí být zvolené pravidlem profilu nebo pole.
- Import nikdy nemaže kartu, která v souboru chybí. Skladové stavy a pohyby se tímto
  importem nezapisují.
- Stejné normalizované hodnoty, například `100` a `100.00`, nevytvářejí věcnou změnu
  ani novou verzi karty.
- Duplicitní identita označí všechny dotčené řádky jako chybné. Cizí nebo neexistující
  interní ID se nenahradí novou kartou.
- Novější ruční změnu mezi náhledem a aplikací import nepřepíše. Řádek skončí
  konfliktem a vyžaduje nový náhled.
- Dokončené dávky zůstávají zapsané. Po přerušení úloha pokračuje od kontrolního bodu
  bez opakovaného založení karet, velký import nemá globální návrat.
- Vzorce v XLSX se nespouštějí. Neplatné hodnoty, příliš velké buňky a nebezpečně
  rozbalitelné soubory se odmítnou.
- Média se přidávají, import je nemaže. Stejný obsah se ke stejné kartě nepřipojí
  podruhé a opakovaný běh nezmění verzi karty.
- Výsledek zápisu produktů včetně konfliktů zůstává dostupný i během následného
  stahování médií, oba kroky mají vlastní výsledky.
- Stahování dovoluje pouze HTTPS, kontroluje cílovou IP při každém přesměrování a odmítá
  interní, lokální a vyhrazené sítě. Jedno médium může mít nejvýše 8 MiB.
- Import zvládne jen XLSX a CSV do 50 MB.

### 38.11.8 Cenotvorba

Zatímco stránka `Sklad → E-shop` drží číselníky, samotná **cena zboží** se rodí na kartě
konkrétní položky (`Sklad → Skladové karty`, karta, záložka **Ceny**). Prodejní cena
přitom není hodnota, kterou prostě zadáte, je to **výsledek výpočtu**, který systém
přepočítává z nákupní ceny.

> [!WARNING]
> **MyÚčto má dva oddělené cenové subsystémy a nemíchají se.** Jednoduchý **Ceník**
> ([Nastavení](96_Nastaveni.md)) je určený pro fakturaci služeb a umí ceny per zákazník.
> **Sklad + E-shop** má vlastní cenotvorbu odvozenou z nákupní ceny, popsanou zde.
> Po zapnutí modulu Sklad **Ceník z menu zmizí**, ceny se mezi nimi nepřenášejí.

#### 38.11.8.1 Jak cena vzniká

Řetěz má pět kroků:

<!-- cols: 6 34 60 -->
| # | Krok | Kde se nastavuje |
|---|---|---|
| 1 | **Nákupní cena v CZK** (nákladová báze) | pole **Cenová báze** na záložce **Obecné** a skladové pohyby či dodavatelé |
| 2 | **Přirážka %** nebo **fixní cena** | záložka **Ceny**, sloupce **Režim** a **Přirážka % / Fixní cena** |
| 3 | **Přepočet do cílové měny** kurzem | automaticky z kurzovního lístku |
| 4 | **Zaokrouhlení** | záložka **Ceny**, sloupec **Zaokrouhlení** |
| 5 | **Výsledná cena** (bez DPH) | sloupec **Výsledná cena**, jen ke čtení |

```text
nákupní cena (CZK)
        │
        ├── režim Přirážka % ──→ × (1 + přirážka/100) ──→ ÷ kurz (cizí měna)
        │                                                        │
        └── režim Fixní cena ────────────────────────────────────┤
                                                                 ▼
                                                         zaokrouhlení
                                                                 │
                                                                 ▼
                                                    výsledná cena bez DPH
```

Výpočet používá přesnou desetinnou aritmetiku, zaokrouhlení se aplikuje na výslednou
cenu.

#### 38.11.8.2 Nákupní cena - cenová báze

Pole **Cenová báze** na záložce **Obecné** určuje, **odkud systém vezme nákupní cenu**,
ze které se počítá přirážka:

<!-- cols: 20 40 40 -->
| Cenová báze | Odkud bere nákupní cenu | Kdy ji použít |
|---|---|---|
| **Vážený průměr** | Průměrná pořizovací cena skladových zásob (hodnota ÷ množství **napříč všemi sklady**) | Výchozí volba pro běžné skladové zboží, cena se plynule přizpůsobuje nákupům |
| **Poslední nákup** | Jednotková cena z **poslední zaúčtované příjemky** | Když se nákupní ceny rychle mění a chcete marži počítat z aktuální hladiny, ne z historického průměru |
| **Ruční** | **Nákupní cena preferovaného dodavatele** ze záložky **Dodavatelé** | Zboží bez skladu (dropshipping), nebo když se řídíte ceníkem dodavatele, ne skutečnými nákupy |

Podrobnosti o tom, jak se vážený průměr počítá při příjmu a výdeji, jsou v
[Oceňování zásob](37_Sklad.md#37123-ocenovani-zasob).

Pokud zvolený zdroj nákupní cenu **nevrátí** (například **Vážený průměr** u karty bez
jediné příjemky), systém zkusí náhradní zdroje v pořadí:

**zvolená báze → poslední nákup → preferovaný dodavatel**

Teprve když selžou všechny tři, zůstane prodejní cena prázdná (`-`). Nákupní cena
**nula nebo záporná se nepočítá jako platná**, přirážka z nulového nákladu nedává smysl,
takže se pokračuje dalším zdrojem v řetězu.

> [!TIP]
> Díky záložnímu řetězu funguje karta se skladovou bází i **před první příjemkou**.
> Nacení se podle dodavatele a jakmile naskladníte, přepne se automaticky na skutečná
> skladová data.

#### 38.11.8.3 Přirážka vs. marže - nepleťte si je

Systém nabízí **přirážku** i **cílovou marži**. Každý režim počítá procento z jiného
základu:

- **Přirážka %** = kolik procent **nákupní ceny** přidáváte.
  `přirážka = (prodej − nákup) ÷ nákup × 100`
- **Marže %** = jaký podíl **prodejní ceny** vám zůstane.
  `marže = (prodej − nákup) ÷ prodej × 100`

Přirážka 30 % tedy **neznamená** marži 30 %. Nákup 1 000 Kč + 30 % přirážky = prodej
1 300 Kč, hrubý zisk 300 Kč, ale marže je `300 ÷ 1 300 = 23,1 %`.

| Přirážka % (zadáváte) | Marže % (dostanete) | | Chcete marži % | Zadejte přirážku % |
|---:|---:|---|---:|---:|
| 10 | 9,1 | | 10 | 11,11 |
| 20 | 16,7 | | 15 | 17,65 |
| 25 | 20,0 | | 20 | 25,00 |
| 30 | 23,1 | | 25 | 33,33 |
| 40 | 28,6 | | 30 | 42,86 |
| 50 | 33,3 | | 40 | 66,67 |
| 100 | 50,0 | | 50 | 100,00 |

Vzorce pro přepočet:

- `marže = přirážka ÷ (100 + přirážka) × 100`
- `přirážka = marže ÷ (100 − marže) × 100`

> [!TIP]
> Když vám dodavatel nebo konkurence mluví o „rabatu“, myslí zpravidla **slevu
> z doporučené ceny**, tedy ještě třetí veličinu. Než si nastavíte přirážky napříč
> katalogem, ujasněte si, které z těch tří čísel vlastně máte. U velkých katalogů je
> omyl v tomhle bodě dražší než cokoli jiného.

> [!WARNING]
> **Cílová marže** musí být alespoň 0 % a menší než 100 %. Cena se počítá jako
> `nákup ÷ (1 − marže / 100)`. Nákup 100 Kč a marže 25 % dají 133,33 Kč. Skutečný zisk
> dále ovlivňují akční ceny, slevy a dodatečné náklady.

#### 38.11.8.4 Cenové profily, pravidla a obchodní kurzy

V `Sklad → E-shop`, záložce **Cenová pravidla**, nastavíte profil pro jednu měnu:
přirážku nebo cílovou marži, zaokrouhlení, zdroj obchodního kurzu a jeho maximální
stáří. Pravidlo přiřadí profil konkrétní kartě, kategorii, výrobci, dodavateli nebo
celé firmě. Přednost má karta, potom kategorie a výrobce, dodavatel a nakonec výchozí
pravidlo. Ve stejné úrovni rozhoduje vyšší priorita, při shodě dříve založené pravidlo.

Na cenovém řádku karty zapněte **Cenová pravidla**. Výpočet pak přebírá profil, ruční
přepis ceny má nadále přednost. Bez této volby zůstává lokální nastavení cenového
řádku. Samotná změna profilu nezaloží kartám chybějící měnové řádky.

Obchodní kurzy mají měnu, datum, zdroj a hodnotu v CZK za jednotku měny. Jsou oddělené
podle firmy od účetního kurzovního lístku. Profil určuje povolené stáří, chybějící
nebo starý kurz je chyba, nepřepočítá cenu na nulu. Změna profilu, pravidla či kurzu
spustí úlohu na pozadí pro existující cenové řádky. Průběh je vidět na stránce i v
historii úloh. Uložení beze změny další běh nevytváří. Běh používá uložená pravidla
a kurzy, změněná karta dostane nový přepočet. Historické doklady si zachovávají původní
ceny a kurzy.

Správa i čtení profilů vyžadují právo na zápis e-shopu a skladových karet, protože
obsahují nákladové a maržové nastavení.

#### 38.11.8.5 Cenová matice

Záložka **Cenová matice** v e-shopu porovná ceny vybraných karet po měnách. Vyberte
karty a měny a vytvořte náhled. Náhled i následné použití změn běží jako úlohy na
pozadí s průběhem a výsledkem po jednotlivých kartách.

U cen vidíte náklad, výslednou cenu, marži a použitý kurz nebo pravidlo. Výsledek lze
filtrovat podle stavu, měny, chybějící ceny, ruční výjimky nebo odchylky od původní
ceny. Hranici odchylky nastavíte před výpočtem náhledu.

Jednotlivou cenu lze uzamknout, nastavit jako pevnou, vrátit pod pravidla nebo
odstranit pro danou měnu. Změny se zapíší až po potvrzení hotového náhledu. Konfliktní
kartu opravte a zahrňte do nového náhledu.

CSV původního nebo navrženého stavu umožní úpravy mimo aplikaci a zpětné načtení.
Zachovejte hlavičku, identifikátor karty a měnu. Zpětné načtení opět vytvoří náhled,
který je nutné potvrdit. Jeden soubor může mít nejvýše 50 MB a 90 000 cenových řádků.
Přístup vyžaduje právo zápisu e-shopu i skladových karet, protože matice zobrazuje také
interní náklady.

#### 38.11.8.6 Záložka Ceny

Záložka rovnou připraví **řádek pro každou aktivní prodejní měnu**. Prázdné řádky se
neukládají. Ikonou koše odstraníte uloženou cenu, aktivní měna zůstane připravená
k novému vyplnění. Dříve uložené ceny neaktivních měn jsou označené a zůstávají
viditelné.

<!-- cols: 26 74 -->
| Sloupec | Význam |
|---|---|
| **Měna** | Kód měny podle ISO 4217 (3 písmena, například `CZK`, `EUR`), jedinečný v rámci karty |
| **Režim** | **Přirážka %**, **Cílová marže %** nebo **Fixní cena** (pevná částka) |
| **Přirážka % / Fixní cena** | Hodnota podle zvoleného režimu, pole se přepíná automaticky |
| **Zaokrouhlení** | Bez, na haléře, desetihaléře, půlkoruny, koruny, na 9 na konci ([§ 38.11.8.7](#381187-zaokrouhleni)) |
| **Ruční** | Zafixuje cenu, přepočet ji nepřepíše |
| **Výsledná cena** | Dopočtená cena **bez DPH**, jen ke čtení |
| **Kurz** | Kurz použitý při posledním přepočtu (u CZK prázdný) |

Tlačítko **Přepočítat** nejdřív uloží aktuální nastavení řádků a **teprve pak spustí
výpočet**, nemusíte tedy ukládat zvlášť.

> [!WARNING]
> **Řádek v CZK má zvláštní postavení: zrcadlí se do prodejní ceny skladové karty**, a tím
> i do výchozí ceny na řádku faktury. Ostatní měny slouží jen e-shopové prezentaci
> a do fakturace nevstupují. Když kartě CZK řádek **nezaložíte**, prodejní cena karty
> zůstane na hodnotě, kterou jste zadali ručně (nebo naimportovali), cenotvorba ji
> nebude aktualizovat.

**Ruční přepis** vyřadí řádek z automatického dopočtu a chová se dvěma způsoby podle
toho, co je v hodnotovém poli:

- **Ruční** + režim **Fixní cena** + zadaná částka: cena je přesně tato částka (jen se
  zaokrouhlí dle nastavení). Tak zboží nacenite napevno.
- **Ruční** + režim **Přirážka %**: cena **zamrzne na poslední dopočtené hodnotě**
  a přestane reagovat na změny nákupní ceny i kurzu.

Odškrtnutím **Ruční** se řádek vrátí do automatického režimu a nejbližší přepočet cenu
přepíše.

Sloupec **Výsledná cena** zůstane prázdný (`-`) ve dvou situacích:

<!-- cols: 50 50 -->
| Příčina | Co s tím |
|---|---|
| **Chybí nákupní cena**, žádný zdroj v záložním řetězu nic nevrátil | Naskladněte příjemkou, nebo doplňte preferovaného dodavatele s nákupní cenou |
| **Chybí kurz**, v kurzovním lístku není kurz dané měny | Doplňte kurz ([§ 38.11.8.8](#381188-cizi-meny-a-kurzy)) a spusťte přepočet |

#### 38.11.8.7 Zaokrouhlení

Zaokrouhluje se **až úplně nakonec**, na výslednou cenu bez DPH, matematicky (půlka
nahoru). Pro dopočtenou cenu **1 035,7335 Kč** dopadnou režimy takto:

<!-- cols: 34 20 46 -->
| Režim | Výsledek | Poznámka |
|---|---:|---|
| **Bez** | 1 035,73 | Na haléře; levná položka, kterou by haléře zkreslily o víc než 1 % (0,0108 Kč), drží až šest desetinných míst |
| **Na haléře (0,01)** | 1 035,73 | |
| **Na desetihaléře (0,10)** | 1 035,70 | |
| **Na půlkoruny (0,50)** | 1 035,50 | |
| **Na koruny (1)** | 1 036,00 | Nejčastější volba pro běžný retail |
| **Na 9 na konci** | 1 039,00 | Psychologická cena, nejbližší celá koruna končící devítkou |

Režim **Na 9 na konci** zaokrouhlí nejdřív na celé koruny a pak vybere nejbližší číslo
končící devítkou. Při stejné vzdálenosti volí **nahoru**. Nejnižší cena, kterou tento
režim vytvoří, je 9 Kč.

> [!TIP]
> Zaokrouhlení nastavujte **per měnu**. „Na 9 na konci“ dává skvělý smysl u korunových
> cen, ale u eurových řádků často vyrobí zbytečně hrubý skok. Tam bývá vhodnější „Na
> haléře“ nebo „Na desetihaléře“.

#### 38.11.8.8 Cizí měny a kurzy

U řádku v cizí měně systém převede **nákupní cenu v CZK** kurzem do cílové měny
a **až pak** aplikuje přirážku a zaokrouhlení.

**Příklad:** nákupní cena 812,34 Kč, přirážka 27,5 %, kurz EUR 25,30 Kč:

```text
812,34 ÷ 25,30 = 32,108300 EUR   (nákup v EUR)
32,108300 × 1,275 = 40,938082    (+ přirážka 27,5 %)
zaokrouhlení „Na haléře"    →    40,94 EUR
```

Marže tedy zůstává stejná jako v CZK, ale **eurová cena plave s kurzem**. Při posílení
koruny sama klesá.

> [!WARNING]
> **Kurz se bere výhradně z kurzovního lístku v databázi**, nikdy se nestahuje živě
> z ČNB. Přepočet ceny nesmí záviset na dostupnosti cizí služby. Použije se **nejbližší
> kurz s datem ≤ dnešek**. Když pro danou měnu není v lístku žádný kurz, cena se
> **nedopočte vůbec** (zůstane prázdná). Cena se nikdy nespočítá z odhadnutého nebo
> nulového kurzu.

#### 38.11.8.9 Kdy se cena přepočítá

Přepočet spouští aplikace při zápisu ceny nebo prostřednictvím trvalé úlohy:

<!-- cols: 60 40 -->
| Událost | Přepočet |
|---|---|
| Uložení cenových řádků (záložka **Ceny**) | automaticky |
| Kliknutí na **Přepočítat** | vynuceně |
| Uložení dodavatelů (záložka **Dodavatelé**) | automaticky |
| Uložení karty zboží | automaticky |
| **Zaúčtování nebo storno skladového dokladu** | automaticky ve frontě pro dotčené karty |
| **Import změněných kurzů** | automaticky ve frontě pro dotčené firmy |
| Hromadné přecenění katalogu | tlačítkem v přehledu úloh |

Průběh najdete v `Sklad → Úlohy e-shopu`. Úloha ukazuje stav a počet dokončených
položek. Po chybě ji lze opakovat od poslední dokončené dávky. Zrušení zastaví další
dávky, ale již uložené ceny ponechá. Pevné ceny zůstávají pevné. Přepočet na pozadí
vyžaduje běžící plánovač s úlohou `cron-catalog-worker`.

> [!TIP]
> **Akčních cen** ([§ 38.11.8.11](#3811811-akcni-ceny)) se přepočet netýká. Je to zadaná
> částka, ne odvozená hodnota. Jejich platnost naopak řídí čas a počet kusů automaticky,
> bez jakéhokoli přepočtu.

#### 38.11.8.10 Co cena obsahuje - DPH a poplatky

- Všechny ceny v cenotvorbě jsou **bez DPH**. Sazbu DPH má karta zvlášť (pole na záložce
  **Obecné**) a připočítává se až na dokladu.
- **Poplatky** (recyklační, autorský - [§ 38.11.6](#38116-poplatky)) **nejsou součástí
  prodejní ceny**. Vedou se jako samostatné částky s vlastní sazbou DPH, protože zákon
  vyžaduje jejich oddělené uvedení. Do přirážky ani do zaokrouhlení nevstupují.
- **Sleva** na kartě zboží se dělá **akční cenou** ([§ 38.11.8.11](#3811811-akcni-ceny)).
  Kromě toho existuje ještě procentní **sleva na úrovni dokladu** (na faktuře), která se
  počítá až z ceny, kterou akce vrátí.

#### 38.11.8.11 Akční ceny

Akční cena je **dočasná sleva položená nad standardní cenou**. Standardní cenotvorba
(přirážka, přepočet měn, zaokrouhlení) běží dál beze změny, akce jen po dobu své
platnosti přebije výsledek. Jakmile akce skončí, cena se sama vrátí na standardní
hladinu, **není potřeba nic ručně vracet**. Akce se zadává na kartě, záložce **Ceny**,
v sekci **Akční ceny**. Postup je v [§ 38.7](#387-krok-za-krokem-akce-a-vyprodej).

Akce má tři omezení a **každé z nich je nepovinné**:

<!-- cols: 24 40 36 -->
| Omezení | Pole | Prázdné znamená |
|---|---|---|
| **Časové okno** | **Platí od** / **Platí do** | bez omezení (i jen jedna strana) |
| **Počet kusů** | **Počet kusů** (a číslo u volby *Omezený počet*) | výchozí je *Do vyprodání zásob* |
| **Akční cena** | **Akční cena** | povinná, to je jádro akce |

##### Tři režimy množstevního stropu

**1. Do vyprodání zásob** (výchozí): akce platí, dokud je zboží skladem, a nejvýš na
tolik kusů, kolik je právě na skladě. Strop se **neodečítá**, čte se živě ze skladu,
takže **doskladněním akci znovu „nabijete“**.

> *Příklad:* prodáváte termosku za 890 Kč a chcete ji vyprodat za 690 Kč. Na skladě je
> 12 ks. Založíte akci `690` v režimu **Do vyprodání zásob** bez data. Prvních 12 ks se
> prodá za 690 Kč, jakmile stav klesne na nulu, faktury se zase nacení na 890 Kč.
> Když vám dorazí dalších 5 ks, akce se sama znovu rozjede. Pro trvalé ukončení akci
> **vypněte** (odškrtněte **Aktivní**) nebo smažte.

**2. Omezený počet kusů**: pevný rozpočet („prvních N kusů“). Prodej ho odečítá
a **doskladnění ho neobnoví**. Aplikace vyčerpané množství **dopočítává z vystavených
faktur** dané karty a měny v období akce. Storno, smazání faktury i dobropis rozpočet
zase uvolní.

> *Příklad:* uvádíte novinku a chcete dát **prvních 100 ks za 1 490 Kč** místo
> 1 990 Kč. Založíte akci `1490`, režim **Omezený počet kusů**, počet `100`. Sloupec
> **Zbývá** průběžně ukazuje, kolik kusů z rozpočtu zbývá. Po stém kusu se akce označí
> jako **Vyčerpaná** a nové faktury se nacení na 1 990 Kč, i kdyby na skladě leželo
> dalších 300 ks.

**3. Bez omezení počtu**: žádný množstevní strop. Hodí se pro akci, kterou řídíte
výhradně kalendářem, nebo pro zboží bez skladové evidence (dropshipping), kde by režim
„do vyprodání zásob“ znamenal nulu.

> *Příklad:* letní sleva na službu montáže od 1. 7. do 31. 8. Založíte akci s cenou
> `990`, **Platí od** `1. 7.`, **Platí do** `31. 8.` a režim **Bez omezení počtu**. Celé
> dva měsíce se fakturuje 990 Kč bez ohledu na počet zakázek, 1. 9. se cena sama vrátí
> na standardní hladinu.

##### Časové okno

Datum **Platí od** i **Platí do** jsou **včetně** daného dne a obě jsou nepovinná:

- **obě prázdná**: akce platí, dokud ji nevypnete (nebo dokud jí nedojde strop),
- **jen „od“**: akce se sama spustí v zadaný den (do té doby má stav **Naplánovaná**),
- **jen „do“**: akce platí hned a v zadaný den večer sama skončí (stav **Skončila**).

> [!TIP]
> Kombinace „jen do“ a režimu **Do vyprodání zásob** je nejběžnější výprodej: „sleva do
> konce měsíce, nebo do vyprodání zásob, co nastane dřív“.

##### Několik akcí najednou

Na téže kartě a měně smí platit **víc akcí současně**, sezónní i produktové kampaně se
běžně vrství. Vyhrává **nejnižší platná cena** (při shodě novější záznam), takže
zákazník vždy dostane tu nejlepší. Akce, která by byla **dražší než standardní cena**,
se ignoruje. Po snížení běžné ceny tedy stará akce zboží nezdraží.

##### Když strop nestačí na celý řádek

Uplatnění je **vše nebo nic per řádek dokladu**. Když z akce zbývají 3 ks a vy
fakturujete 5 ks, míchaná jednotková cena by rozbila vztah *cena × množství = základ*
a na faktuře by se nedala vysvětlit. Aplikace proto:

1. zkusí **další akci v pořadí** (dražší akce s volnějším stropem je pořád lepší než
   plná cena),
2. a když ani ta nestačí, nacení celý řádek **standardní cenou**.

Chcete-li v takové situaci prodat část akčně, **rozdělte řádek** na dva: 3 ks s akční
cenou a 2 ks se standardní.

##### Stavy akce

Sloupec **Stav** u každého řádku říká, co se s ním právě děje:

<!-- cols: 24 76 -->
| Stav | Význam |
|---|---|
| **Probíhá** | akce se právě uplatňuje |
| **Naplánovaná** | **Platí od** je v budoucnosti |
| **Skončila** | **Platí do** už je v minulosti |
| **Vypnutá** | odškrtnuté **Aktivní** (akce se schovává, ale nemaže) |
| **Vyčerpaná** | došel množstevní strop, nebo zboží není skladem |

> [!TIP]
> Akční cena je **bez DPH**, stejně jako celá cenotvorba, a platí vždy jen pro **jednu
> měnu**. Pro akci v eurech založte druhý řádek s `EUR`. Na rozdíl od standardní ceny se
> akční cena **nepřepočítává** ani kurzem, ani přirážkou, je to zadaná částka.

##### Kde všude se akční cena projeví

Akční cenu aplikace dosazuje všude, kde zboží naceňuje:

- v **seznamu skladových karet** (původní cena přeškrtnutá, akční zeleně),
- na **detailu karty**,
- při **vložení zboží do faktury**: do řádku se předvyplní akční cena a aplikace na to
  upozorní hláškou.

Množstevní strop akce se posuzuje v **základní jednotce** karty: řádek faktury v balení
10 KT po 8 ks čerpá ze stropu 80 ks. Akční cena je vždy za základní jednotku, řádek
v balení ji dostane vynásobenou poměrem balení.

#### 38.11.8.12 Individuální ceny zákazníků

Na záložce **Ceny** editoru karty pod akčními cenami zadáte konkrétnímu odběrateli
vlastní cenu karty: **pevnou cenu** nebo **slevu v %** ze standardní ceny. Každý řádek
má odběratele, měnu, volitelnou platnost od-do a poznámku a ukazuje výslednou cenu
podle dnešní standardní ceny. Ukládá se společným tlačítkem **Uložit** editoru.
Duplikace karty zákaznické ceny nepřenáší.

- Cena je vždy **za základní jednotku** karty a **bez DPH**. Řádek faktury v balení
  ([Balení](37_Sklad.md#371228-baleni)) dostane cenu vynásobenou poměrem balení.
- Pro jednoho odběratele a měnu smí mít karta jen jednu individuální cenu.
- Sleva se počítá ze standardní ceny v dané měně a zaokrouhluje na haléře. Levné zboží,
  které by haléře zkreslily o víc než 1 %, drží až šest desetinných míst, takže se nevynuluje.

##### Která cena platí

Editor faktury nacení skladový řádek pro odběratele, měnu a datum dokladu:

1. **Základ** je individuální cena odběratele, pokud je platná k datu dokladu a v měně
   dokladu.
2. Jinak rozhoduje **cenová hladina**: zvolená přímo na dokladu, jinak hladina
   odběratele, pokud ji má přiřazenou a je aktivní ([§ 38.11.18](#381118-cenove-hladiny)).
3. Jinak je základem standardní cena z cenotvorby.
4. **Akční cena** se použije jen tehdy, když je nižší než tento základ. Zákazník tak
   dostane lepší z obou cen. U odběratele s přirážkou to platí i pro akci, která je
   dražší než standardní cena, ale levnější než jeho cena s přirážkou.

Odběratel bez individuální ceny a bez cenové hladiny se naceňuje přesně jako dosud.
Změna odběratele, měny nebo cenové hladiny dokladu na faktuře přecení jen řádky, jejichž
cenu doplnila aplikace, ručně přepsanou cenu nechá být. U uloženého dokladu se po změně
hladiny editor zeptá, jestli přecenit skladové řádky s nezápornou jednotkovou cenou
([§ 38.11.18.3](#3811183-cenova-hladina-na-dokladu)). Seznam karet a našeptávač dál
ukazují standardní (případně akční) cenu bez ohledu na odběratele.

Záporná jednotková cena vratky nebo odpočtu se cenou karty nepřepisuje. Změna balení ji
pouze přepočte podle poměru jednotek se zachováním znaménka; stejně přepočte ceníkovou
cenu řádku. Změna odběratele, měny nebo hladiny takový řádek nenacení.

> [!TIP]
> Ceník pro firmy **bez** skladové evidence (Faktury → Ceník) má vlastní zákaznické ceny
> položek ceníku. Individuální ceny na skladové kartě s ním nesouvisí, firma se skladem
> nacení zboží vždy ze skladové karty.

### 38.11.9 Dodavatelé zboží

Ke kartě zboží můžete na záložce **Dodavatelé** přiřadit **libovolný počet dodavatelů**,
každého s vlastními podmínkami. Slouží ke dvěma věcem: jako **podklad pro nákup**
a jako **zdroj nákupní ceny** pro cenovou bázi **Ruční**.

<!-- cols: 26 74 -->
| Pole | Význam |
|---|---|
| **Dodavatel** | Výběr z klientů, kteří mají v adresáři zapnutou **roli dodavatele** ([Klienti](18_Klienti.md)) |
| **Kód u dodavatele** | Katalogové číslo, pod kterým položku vede dodavatel (nejvýše 80 znaků). Tiskne se na objednávku a páruje se podle něj ceník |
| **Nákupní cena** | Cena, za kterou od něj nakupujete |
| **Měna** | Měna nákupní ceny (výchozí `CZK`) |
| **Dodání (dny)** | Dodací lhůta. U zboží bez skladu tvoří dostupnost na e-shopu |
| **Skladem (ks)** | Množství, které dodavatel drží. Orientační, neupravuje váš sklad, sčítá se do veličiny **U dodavatele** ([Skladem, rezervováno, na cestě, u dodavatele](37_Sklad.md#371294-u-dodavatele)) |
| **Preferovaný** | Přepínač, **nejvýš jeden dodavatel na kartu** |
| **Poznámka** | Volný text (sezónnost, kontakt) |

Tato záložka je **pohled po kartách** na tatáž data, která stránka `Sklad → U dodavatele`
ukazuje přes celý katalog. Jde o jeden a týž záznam. Kompletní sada polí (**minimální
odběr**, **balení**, **dostupnost**, **cena platí do**, **aktivní**) i **import ceníku**
jsou popsané v [Nabídky dodavatelů](37_Sklad.md#371210-nabidky-dodavatelu-u-dodavatele).
Právě minimální odběr a balení používá návrh
[doplnění zásob](37_Sklad.md#371212-doplneni-zasob-co-objednat).

#### 38.11.9.1 Preferovaný dodavatel

Preferovaný dodavatel je ten, jehož **nákupní cena se použije při cenové bázi Ruční**
a jako poslední článek záložního řetězu ([§ 38.11.8.2](#381182-nakupni-cena-cenova-baze)).
Je-li jeho cena v cizí měně, převede se do CZK kurzem k danému dni. **Chybí-li kurz,
nákupní cena z něj nepůjde získat.**

Aby se klient v nabídce dodavatelů vůbec objevil, musí mít v adresáři zapnutý **příznak
dodavatele**. Je-li seznam prázdný, nejdřív roli zapněte u příslušných klientů.

#### 38.11.9.2 Dropshipping - zboží bez skladu

Vypnete-li na kartě příznak **Skladová položka**, karta žije **jen z dodavatelů**:
skladové množství se nesleduje, dostupnost a dodací lhůta se berou od dodavatele
a nákupní cena pro cenotvorbu přijde z preferovaného dodavatele. Doporučené nastavení
pro dropshipping je kombinace **Skladová položka** vypnutá, cenová báze **Ruční**
a preferovaný dodavatel s cenou.

Dropshippingové zboží **nákupní modul neobjednává**. Návrh
[doplnění zásob](37_Sklad.md#371212-doplneni-zasob-co-objednat) pracuje jen s kartami,
které mají vyplněnou **minimální zásobu**, u zboží bez skladu žádná není a nemá být.
Potřebujete-li takovou položku přesto objednat, založte
[objednávku](37_Sklad.md#371211-objednavky-u-dodavatele) ručně.

### 38.11.10 Praktické postupy cenotvorby

Postupy pro nacenění skladového a dropshippingového zboží, změnu dodavatele, kontrolu marže, pravidla, cenovou matici a akce jsou v krocích [§ 38.6](#386-krok-za-krokem-nacenit-zbozi) a [§ 38.7](#387-krok-za-krokem-akce-a-vyprodej).

### 38.11.11 Mazání vs. archivace

U všech číselníků (výrobci, kategorie, atributy, tagy, poplatky) platí stejné pravidlo:
pokud je záznam **použitý u nějakého zboží**, smazání ho odmítne a zobrazí hlášku, že je
„v použití“. Řešením je záznam **archivovat** (vypnout zaškrtávátko **Aktivní** ve
formuláři) místo mazání. Archivované záznamy zůstávají v tabulce (zešednou), ale
nenabízejí se při zakládání nového zboží ani v exportu na e-shop.

### 38.11.12 Omezení a tipy

#### 38.11.12.1 Číselníky a import

- Modul E-shop je **dostupný jen se zapnutým Skladem**, bez něj se položka v menu vůbec
  nezobrazí (nastavuje se v [Nastavení](96_Nastaveni.md)).
- Kódy (výrobce, kategorie, atribut, tag, poplatek) jsou jedinečné **v rámci firmy**.
  Při kolizi vrátí formulář chybu „…s tímto kódem už existuje“.
- Kategorie nelze přesunout do vlastního podstromu (ochrana proti zacyklení stromu).
- Import zboží zvládne jen **XLSX nebo CSV do 50 MB** a nikdy nezakládá výrobce.
  Pořadí je proto: nejdřív číselníky (výrobci), pak import.
- Čtenáři vidí všechny záložky i importní report, ale nemají tlačítka pro zápis
  (nový, upravit, smazat, import naostro).

#### 38.11.12.2 Cenotvorba

Aby očekávání sedělo, tohle cenotvorba v MyÚčtu **neumí**:

<!-- cols: 34 66 -->
| Chybějící funkce | Náhradní řešení |
|---|---|
| **Množstevní slevy** (od X ks levněji) | Samostatná karta pro balení, nebo sleva na dokladu. Akční cena umí jen *strop* počtu kusů, ne cenové pásmo |
| **Částečné uplatnění akce v jednom řádku** | Akce je vše nebo nic per řádek, rozdělte řádek ([§ 38.11.8.11](#3811811-akcni-ceny)) |
| **Historie cen** | Není, uchovává se jen aktuální hodnota a datum posledního přepočtu |
| **Automatický feed nákupních cen od dodavatele** | Ceník se importuje ručně z XLSX nebo CSV ([Import ceníku dodavatele](37_Sklad.md#3712102-import-ceniku-dodavatele)), online napojení na dodavatele není |
| **Reporting skutečné marže** | Ručně z nákupní a prodejní ceny |
| **XML feed pro Heureku / Zboží.cz** | Příznak **Exportovat do e-shopu** je jen označení pro externí systém |

### 38.11.13 Jazyky

Číselník jazykových mutací, ve kterých vedete názvy a popisy zboží a kategorií
(`Sklad → E-shop`, záložka **Jazyky**). Karta zboží na záložce **Jazyky** rovnou otevře
češtinu. Další jazyk vyberete z aktivních jazyků tohoto číselníku, výběr ihned přidá
a otevře jeho formulář. Mezi rozepsanými překlady přepínáte jazykovými záložkami.
Prázdný připravený český řádek se neukládá, překlad s obsahem vyžaduje také název.

<!-- cols: 26 74 -->
| Pole | Význam |
|---|---|
| **Kód** | Kód jazyka ve tvaru `cs`, nebo `pt-BR` pro regionální variantu. Používá se v datech překladů |
| **Název** | Jak se jazyk zobrazí v nabídce (`Čeština`, `English`) |
| **Pořadí** | Řadí jazyky v nabídce, při shodě rozhoduje kód |
| **Výchozí jazyk** | Výchozí jazyk číselníku. Výchozí smí být jen jeden, editor karty otevírá češtinu |
| **Aktivní** | Neaktivní (archivovaný) jazyk se nenabízí pro nové překlady |

Ve formuláři je nahoře **rychlá volba** nejčastějších jazyků, klepnutím předvyplní kód
i název, oboje jde pak přepsat.

> [!WARNING]
> **Kód jazyka, ke kterému už existují překlady, nelze změnit.** Překlady jsou na jazyk
> navázané hodnotou kódu, ne odkazem, přejmenování by je od číselníku odpojilo. Chcete-li
> jazyk opravdu vyměnit, založte nový a překlady přepište.

Jazyk s uloženými překlady **nejde smazat**, jen archivovat. Archivace ho stáhne z
nabídky pro nové překlady, ale už uložené texty zůstávají a karta s takovým překladem
jde dál uložit.

Při zapnutí modulu dostanete do číselníku češtinu a všechny jazyky, ve kterých už nějaký
překlad existuje. Nová firma, která si sklad zapne později, začíná s prázdným
číselníkem. První uložení českého překladu do něj češtinu doplní, další jazyky přidáte
v číselníku.

### 38.11.14 Varianty a vztahy produktů

Na stránce E-shop v záložce **Variantní produkty** vytvoříte společný produkt a připojíte
k němu existující skladové karty jako varianty. Variantní produkt nemá vlastní SKU ani
skladovou zásobu. Každá varianta si ponechá své SKU, ceny, skladové pohyby a externí
identitu.

Osy variant vyberete z jednohodnotových výčtových parametrů, například velikost
a barva. Pro každou variantu zadáte jednu možnost každé osy. Dvě varianty stejného
produktu nemohou mít stejnou kombinaci. Parametry určující variantu měňte přes
variantní produkt, běžná záložka **Parametry** jejich změnu odmítne.

Varianta standardně přebírá výrobce a obsah překladů hlavního produktu. V záložce
**Master** na kartě můžete jednotlivá pole přepnout na vlastní hodnotu. Náhled ukazuje
výsledný obsah. Slug zůstává vlastní pro každé SKU. Před odpojením varianty aplikace
ukáže obsah, který na kartě zachová, aby odpojením nezmizel převzatý popis ani výrobce.

V záložce **Vztahy** propojíte příslušenství, náhrady a související zboží. Kopírování
obsahových částí mezi více kartami běží jako trvalá úloha s průběhem a výsledkem
jednotlivých položek. Změněné karty se nepřepíší potichu, jejich konflikty uvidíte ve
výsledku.

### 38.11.15 Virtuální sety

Na kartě bez vlastní skladové zásoby otevřete **Složení setu**. Set může obsahovat
pevné komponenty i další sety. Konfigurátor navíc nabízí povinné a volitelné skupiny
s omezením počtu voleb a měnovými příplatky. Cyklus ve složení systém odmítne. Pro
každou měnu lze použít součet cen komponent, procentní slevu nebo pevnou cenu. Výpočet
používá aktuální ceny komponent a zvolenou konfiguraci. Set s různými sazbami DPH
komponent nelze takto ocenit.

Virtuální set nemá vlastní zásobu a nelze jej přepnout na skladovanou kartu. Pro předem
vyrobené balení použijte samostatnou kartu výrobku a **Kompletaci** v modulu Sklad
([Kompletace výrobku](37_Sklad.md#371227-kompletace-vyrobku)). Kompletace jednou
transakcí vydá komponenty a přijme výrobek ve stejné celkové hodnotě. Storno se
provádí společně přes kompletaci. Zpětný pohyb, který by změnil ocenění již
zkompletovaných komponent, systém odmítne. Nejprve stornujte kompletaci nebo proveďte
korekci po ní.

### 38.11.16 Integrační centrum

Integrační centrum (`Sklad → Integrace`) propojuje MyÚčto s e-shopem nebo jiným externím
systémem. Pro každé propojení založíte **připojení**: vyberete konektor, přiřadíte
místním číselníkům hodnoty externího systému, rozhodnete, kdo je u kterých dat zdrojem
pravdy, uložíte přístupové údaje a připojení aktivujete. Stránka zároveň obsahuje všechny
podklady pro vývojáře, který napojení programuje, a přehled provozu.

Každá firma má předem připravené **Ukázkové napojení (vzor Shoptet)** ve stavu Koncept,
předvyplněné z jejích číselníků. Vznikne při prvním otevření stránky uživatelem s právem
integrace upravovat, a když ho smažete, znovu se už nezaloží (ručně ho vrátíte tlačítkem
**Vytvořit ukázkové napojení**). Formulář nového připojení je předvyplněný stejnými
výchozími hodnotami. Zástupné hodnoty v lomených závorkách, například
`<stockId skladu v Shoptetu>`, nahraďte skutečnými a jako první krok vytvořte secret
webhooku. Připojení smažete tlačítkem **Smazat připojení** v záhlaví editoru, zmizí i
jeho fronty událostí.

Stránka je dostupná jen firmě se zapnutým skladem a uživateli s oprávněním **Spravovat
integrace e-shopu** (ve výchozím nastavení administrátor). Uživatel jen s právem čtení
nastavení vidí, ale nemůže ho měnit.

#### 38.11.16.1 Postup nastavení

Editor připojení je rozdělený do šesti kroků. Nahoře vidíte jejich stav (hotovo,
doplnit, nepovinné, po uložení) a klepnutím na krok na něj stránka sjede. Všechno kromě
webhooku uložíte jedním tlačítkem **Uložit** v liště dole. **Zahodit změny** vrátí
formulář do uloženého stavu.

<!-- cols: 28 72 -->
| Krok | Co v něm nastavíte |
|---|---|
| **1. Konektor** | S jakým systémem se propojujete a název připojení |
| **2. Mapování** | Které sklady, měny, jazyky a sazby DPH odpovídají hodnotám v externím systému |
| **3. Vlastnictví polí** | U každého pole, jestli pravdu drží MyÚčto, externí systém, nebo rozhodujete ručně |
| **4. Přístupy** | Přístupové údaje k externímu systému, pokud je konektor potřebuje |
| **5. Webhook** | Adresa pro příchozí události, secret a podklady pro vývojáře (až po prvním uložení) |
| **6. Aktivace** | Stav připojení a provozní limity |

#### 38.11.16.2 Konektory

Konektor určuje, s jakým systémem se připojení baví a co od vás potřebuje. Konektor
uloženého připojení už nejde změnit, pro jiný systém založte nové připojení.

<!-- cols: 28 16 56 -->
| Konektor | Stav | K čemu je |
|---|---|---|
| **Vlastní napojení přes webhook a API** | K dispozici | Pro vlastní e-shop nebo prostředníka. Externí systém posílá podepsané události na webhook a změny katalogu si stahuje přes API |
| **Shoptet** | Připravujeme | Přímé napojení Shoptetu. Bude mít vlastní příjem webhooků Shoptetu a stahování změn objednávek, na obecnou adresu webhooku se nenapojuje. Zatím ho nelze vybrat |

Připojení, které vzniklo dřív, než aplikace začala konektory rozlišovat, může mít
konektor, který seznam nezná. Takové připojení dál funguje a jde upravovat. Stránka na
to upozorní a mapování, vlastnictví polí i přístupové údaje u něj zadáváte jako JSON
bez kontroly podle definice.

#### 38.11.16.3 Mapování číselníků

Mapování říká, pod jakou hodnotou zná externí systém vaše sklady, měny, jazyky
a sazby DPH. Každý typ má vlastní tabulku: vlevo vyberete místní hodnotu z číselníku
firmy, vpravo napíšete kód nebo ID v externím systému. Řádek přidáte tlačítkem
**Přidat řádek**, odeberete ikonou koše. Hodnotu, kterou nenamapujete, konektor
nepřenáší.

<!-- cols: 16 44 40 -->
| Typ | Místní hodnota | Příklad hodnoty v e-shopu |
|---|---|---|
| **Sklady** | Kód skladu (`Sklad → E-shop`, záložka **Sklady**) | ID skladu v e-shopu, například `1` |
| **Měny** | Měna firmy nebo měna, ve které má zboží prodejní cenu | `CZK`, `EUR` |
| **Jazyky** | Jazyk z číselníku (`Sklad → E-shop`, záložka **Jazyky**) | `cs`, `sk` |
| **Sazby DPH** | Sazba DPH podle číselníku | `21` nebo ID sazby |

Výběr nabízí jen aktivní hodnoty. Když sklad deaktivujete nebo jazyk archivujete, jeho
uložené mapování zůstává. Uložení odmítne hodnotu, která ve firmě neexistuje, prázdnou
hodnotu v externím systému i stejnou místní hodnotu namapovanou dvakrát. Chyba vždy
řekne, u kterého typu a hodnoty je.

**Pokročilý režim** (přepínač u kroku 2) ukáže mapování i vlastnictví polí jako JSON.
Hodí se vývojáři, který nastavení kopíruje mezi prostředími. Při vypnutí režimu se JSON
zkontroluje a převede zpět do tabulek, neplatný JSON tabulky nepřepíše. Uložený tvar
mapování:

```json
{
  "warehouses": { "HLAVNI": "1" },
  "currencies": { "CZK": "CZK", "EUR": "EUR" },
  "languages": { "cs": "cs" }
}
```

#### 38.11.16.4 Vlastnictví polí

U každého pole rozhodujete, kdo je zdrojem pravdy, když se hodnoty v MyÚčtu
a v externím systému liší. Konektor se tím řídí při přenosu změn.

<!-- cols: 16 84 -->
| Vlastník | Co znamená |
|---|---|
| **Místní** | Pravdou je MyÚčto. Hodnota se posílá do externího systému a jeho změna se nepřevezme |
| **Externí** | Pravdou je externí systém. Jeho změna přepíše hodnotu v MyÚčtu a MyÚčto ji ven neposílá |
| **Ruční** | Nic se nepřepisuje automaticky. Rozdíl se jen nahlásí a rozhodnete o něm vy |

Pole jsou seskupená podle oblasti. Výchozí volba je označená štítkem **výchozí**, pole,
které v nastavení chybí, má výchozího vlastníka.

<!-- cols: 46 14 40 -->
| Pole | Výchozí vlastník | Proč |
|---|---|---|
| Kód zboží, název, popis, SEO texty, EAN, výrobce, obrázky, hmotnost | Místní | Katalog vedete v MyÚčtu |
| Prodejní cena, sazba DPH | Místní | Cena a DPH vstupují do účetnictví |
| Prodejnost karty | Místní | Aktivace a vyřazení karty |
| Skladové množství | Místní | Sklad vede MyÚčto |
| Stav objednávky, kontaktní údaje zákazníka | Externí | Objednávku zakládá a mění zákazník v e-shopu |
| Stav úhrady objednávky | Místní | Úhradu páruje MyÚčto z banky a pokladny |
| Stav expedice | Místní | Rezervace a vyskladnění probíhají ve skladu MyÚčta |

**Příklady:**

- Popisy zboží píše marketing přímo v e-shopu: nastavte **Popis zboží** na **Externí**.
  Změna popisu v e-shopu se pak převezme do MyÚčta.
- Ceny spravujete v MyÚčtu, ale v e-shopu občas někdo cenu ručně upraví a vy o tom
  chcete vědět: nastavte **Prodejní cena** na **Ruční**. Rozdíl se nahlásí a nic se
  nepřepíše.

U vlastního napojení můžete přidat i **vlastní pole**, které definice konektoru nezná
(například `custom.loyalty_points`). Klíč začíná malým písmenem a obsahuje jen `a-z`,
`0-9`, tečku a podtržítko.

#### 38.11.16.5 Přístupové údaje

Přístupové údaje jsou pole, která konektor potřebuje k přístupu do externího systému.
Ukládají se šifrovaně a po uložení se už nikdy nezobrazí, ani administrátorovi. U každého
pole vidíte jen stav **Uloženo** nebo **Nevyplněno**.

- Uložené pole přepíšete vyplněním nové hodnoty. Prázdné pole ponechá uloženou hodnotu.
- Nepovinné uložené pole odstraníte zaškrtnutím **Odebrat uloženou hodnotu**.
- Pole typu adresa musí začínat `https://`.

Vlastní napojení nabízí nepovinnou **Adresu pro odchozí události** a **Token pro odchozí
události**. Odchozí doručování zatím nic nespouští, údaje si můžete připravit dopředu.
Secret webhooku s nimi nesouvisí, ten vzniká v kroku 5.

#### 38.11.16.6 Webhook a podklady pro vývojáře

Krok 5 se zobrazí po prvním uložení připojení. Obsahuje adresu webhooku s tlačítkem
**Kopírovat**, kontrolu připravenosti a podrobný popis kontraktu s ukázkami v curl, PHP
a Node.js.

Tlačítko **Vytvořit secret** vytvoří podpisový klíč webhooku a ukáže ho právě jednou.
Uložte ho hned do externího systému. **Vytvořit nový secret** předchozí klíč okamžitě
zneplatní, proto se aplikace nejdřív zeptá.

Webhook přijímá události, jen když je připojení **aktivní** a má vytvořený secret.
Požadavek je `POST` na adresu webhooku s těmito hlavičkami:

<!-- cols: 30 70 -->
| Hlavička | Obsah |
|---|---|
| `Content-Type` | `application/json`, tělo nejvýše 1 MiB |
| `X-Integration-Timestamp` | Unixový čas odeslání v sekundách, odchylka od času serveru nejvýše 5 minut |
| `X-Integration-Signature` | `sha256=` a hexadecimální HMAC-SHA256 z řetězce `časové_razítko.tělo` klíčem secret |

Podepisuje se přesně odeslané tělo, bajt po bajtu. Když tělo po výpočtu podpisu
přeformátujete, podpis přestane sedět. Ukázka výpočtu v PHP:

```php
$body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$timestamp = (string) time();
$signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, getenv('WEBHOOK_SECRET'));
```

Tělo události je JSON objekt s povinnými poli `event_id` (jednoznačné ID události,
nejvýše 190 znaků), `entity_type` (například `order`), `entity_id` (ID entity
v externím systému), `event_type` (například `order.created`) a `aggregate_version`
(celé číslo od 1, s každou změnou entity roste). Další pole se uloží spolu s událostí.
Ukázka je objednávka ve stylu Shoptetu převedená do tohoto kontraktu (syntetická data).
Skutečný Shoptet náš webhook sám nevolá (podepisuje HMAC-SHA1 v hlavičce
`Shoptet-Webhook-Signature`), takže jde o vzor pro prostředníka nebo vlastní skript:

```json
{
  "event_id": "shoptet-order-2026000123-1",
  "entity_type": "order",
  "entity_id": "2026000123",
  "event_type": "order.created",
  "aggregate_version": 1,
  "payload": {
    "code": "2026000123",
    "status": { "id": -1 },
    "currency": { "code": "CZK" },
    "price": { "withVat": "1210.00", "withoutVat": "1000.00", "vat": "210.00" },
    "paid": false,
    "items": [{ "itemType": "product", "code": "SKU-1001", "amount": "2", "vatRate": "21" }]
  }
}
```

<!-- cols: 14 86 -->
| Odpověď | Význam |
|---|---|
| **202** | Událost je přijatá do fronty. Při opakovaném doručení stejné události (stejné `event_id` i tělo) vrátí `duplicate: true` a znovu ji neuloží |
| **400** | Tělo není platný JSON objekt nebo chybí či je neplatné povinné pole |
| **401** | Připojení neexistuje nebo není aktivní, chybí secret, časové razítko je mimo 5 minut nebo nesedí podpis |
| **409** | Pod stejným `event_id` už přišla událost s jiným obsahem, změnu pošlete s novým `event_id` |
| **413** | Tělo je větší než 1 MiB |

Opakované doručení je proto bezpečné: když si odesílatel není jistý, že událost
dorazila, pošle ji znovu beze změny. O pořadí změn jedné entity rozhoduje
`aggregate_version`, ne čas doručení. Pozdě doručená starší verze se při zpracování
přeskočí.

Přijaté události čekají ve frontě příchozích událostí. Zapisovat je do katalogu
a objednávek bude konkrétní konektor, do té doby je v diagnostice uvidíte jako
čekající.

**Ověřit podpis a tělo události:** ve spodní části kroku 5 vloží vývojář secret,
časové razítko a tělo a stránka spočítá očekávanou hodnotu hlavičky
`X-Integration-Signature`, zkontroluje tělo proti kontraktu, porovná podpis z jeho
systému a připraví hotový příkaz curl. Výpočet probíhá jen v prohlížeči, secret se
nikam neodesílá ani neukládá.

#### 38.11.16.7 Stahování změn katalogu

Změny katalogu si externí systém stahuje sám přes veřejné API
`GET /api/v1/catalog/changes`. Potřebuje API token s právem číst e-shop (vytvoříte ho v
profilu v sekci **API tokeny**). Pokud token není vázaný na jednu firmu, posílá hlavičku
`X-Supplier-Id`.

```bash
curl -sS 'https://ucto.example.test/api/v1/catalog/changes?after_cursor=0&limit=250' \
  -H "Authorization: Bearer $API_TOKEN"
```

Každá změna karty, ceny, obrázku, překladu, zásoby nebo rezervace dostane rostoucí
kurzor. `after_cursor` je poslední zpracovaný kurzor (na začátku 0), `limit` 1 až 1000.
Po každé stránce si systém uloží `next_cursor`, dokud je `has_more` true, načte hned
další stránku. Položka říká, která karta se změnila (`entity_id`) a v jaké oblasti
(`source_area`). Aktuální data karty se pak načtou přes
`POST /api/v1/catalog/products/batch`. Hodnota `tombstone` znamená smazanou nebo
vyřazenou kartu.

Odpověď **410** znamená, že kurzor je starší než uchovávaná historie změn. Externí
systém stáhne celý katalog znovu a pokračuje od `minimum_cursor`.

#### 38.11.16.8 Aktivace a provozní limity

<!-- cols: 20 80 -->
| Stav | Co znamená |
|---|---|
| **Koncept** | Nastavujete. Webhook nic nepřijímá a pro připojení nevznikají odchozí události |
| **Aktivní** | Webhook přijímá události a pro připojení vznikají odchozí události o objednávkách |
| **Pozastavené** | Dočasně vypnuto. Webhook události odmítá, dosavadní fronty zůstávají |
| **Chyba** | Nastaví ho porovnání úplnosti, když najde problém. Po odstranění příčiny spusťte porovnání znovu nebo zvolte jiný stav |

**Limit odchozích požadavků za minutu** (1 až 6000) určuje, kolik odchozích událostí
smí konektor za minutu doručit, a chrání API externího systému před přetížením.
**Uchování provozních záznamů** (1 až 365 dní) určuje, po jaké době se z příchozích
a odchozích událostí odstraní obsah a změnový feed zapomene starší změny.

#### 38.11.16.9 Diagnostika, opakování a porovnání úplnosti

Přehled provozu pod editorem ukazuje počty příchozích a odchozích událostí, které čekají,
doručené události, trvalé chyby a jejich diagnostické kódy. Obsah zpráv ani přístupové
údaje nezobrazuje. Odchozí událost ve stavu trvalé chyby lze po odstranění příčiny vrátit
do fronty tlačítkem **Opakovat**.

Tlačítko **Porovnat úplnost** projde propojené identity připojení (které externí ID patří
ke které místní kartě) a ověří, že místní karty pořád existují. Chybějící hlásí jako
neúplné mapování a připojení přepne do stavu Chyba. Porovnání běží na pozadí po dávkách,
průběh vidíte v přehledu a pro aktivní připojení se spouští i pravidelně. Obsah
externího systému nestahuje ani nemění.

#### 38.11.16.10 Řešení potíží

<!-- cols: 34 66 -->
| Příznak | Příčina a řešení |
|---|---|
| Webhook vrací **401** | Připojení není aktivní, secret nebyl vytvořen nebo byl vyměněn, hodiny odesílatele se liší o víc než 5 minut, nebo se podepisuje jiné tělo, než se posílá. Ověřte podpis nástrojem v kroku 5 |
| Webhook vrací **409** | Stejné `event_id` bylo použito pro jinou změnu. Každá změna potřebuje nové `event_id` |
| Webhook vrací **400** | Chybí povinné pole nebo má špatný tvar (například `entity_type` s velkými písmeny, `aggregate_version` 0) |
| Uložení hlásí, že hodnota ve firmě neexistuje | Sklad, jazyk nebo měna byly smazány. Opravte nebo odeberte řádek mapování |
| Změnový feed vrací **410** | Externí systém se dlouho nepřipojil. Musí stáhnout celý katalog znovu |
| V diagnostice přibývají čekající příchozí události | Události se přijímají, ale konkrétní konektor je zatím nezpracovává |
| Připojení je ve stavu Chyba | Porovnání úplnosti našlo propojení na neexistující kartu. Opravte propojení a spusťte porovnání znovu |

### 38.11.17 Balení

Číselník **Balení** drží kódy nadřazených jednotek firmy, například `KT` Karton, `PAL`
Paleta, `BAL` Balík. Formulář: **Kód** (nejvýše 20 znaků bez mezer), **Název**, **Pořadí**
a **Aktivní**. Tabulka u každého balení ukazuje, kolik karet ho používá.

Samotný poměr („1 KT = 8 ks“) a EAN balení se zadávají na skladové kartě
([Balení karty](37_Sklad.md#371228-baleni)), protože karton jednoho zboží obsahuje jiný
počet kusů než karton jiného.

- Kód balení, které používá nějaká karta, nejde změnit ani balení smazat. Místo
  smazání ho **deaktivujte**, nové karty ho pak nenabídnou, stávající si ho ponechají.
- Nepoužívané balení smažete běžně.

### 38.11.18 Cenové hladiny

Cenová hladina seskupuje odběratele, kteří nakupují za stejných podmínek, například
**Bronze**, **Silver** a **Gold**. Hladinu přiřadíte odběrateli na jeho kartě
([Klienti](18_Klienti.md#1872-pole-formulare)). Odběratel bez hladiny patří do hladiny
**Default** a skladové zboží se mu naceňuje standardní cenou přesně jako dosud.

Formulář hladiny: **Název**, **Kód** (vyplní se z názvu, nejvýše 50 znaků bez mezer),
**Výchozí sleva nebo přirážka** (výběr **Sleva** / **Přirážka** a hodnota v %),
**Pořadí** a **Aktivní**. Tabulka u každé hladiny ukazuje výchozí hodnotu („−10 %“ sleva,
„+45 %“ přirážka) a počet odběratelů a pravidel.

Sleva sníží standardní cenu, přirážka ji zvýší. Sleva je nejvýše 100 %, přirážka nejvýše
999,999 %. Přirážku použijete tam, kde se pro některé zákazníky nebo zboží prodává dráž
než za standardní cenu, například díly s nízkou ceníkovou cenou pro koncové zákazníky
(+45 %) nebo urgentní objednávka běžného zákazníka (+5 %).

#### 38.11.18.1 Pravidla hladiny

V detailu hladiny zpřesníte slevu nebo přirážku pro konkrétní zboží:

<!-- cols: 20 80 -->
| Cíl | Co lze nastavit |
|---|---|
| **Produkt** | sleva nebo přirážka v %, nebo pevná cena v konkrétní měně |
| **Kategorie** | sleva nebo přirážka v % pro zboží zařazené v kategorii |
| **Výrobce** | sleva nebo přirážka v % pro zboží výrobce |

- Sleva, přirážka i pevná cena jsou vždy **za základní jednotku** karty a **bez DPH**.
  Řádek faktury v balení dostane cenu vynásobenou poměrem balení.
- Sleva i přirážka se počítá ze standardní ceny v měně dokladu a zaokrouhluje na haléře,
  u levného zboží, které by haléře zkreslily o víc než 1 %, na až šest desetinných míst.
  Příklad: standardní cena 1 000 Kč, přirážka 45 % → 1 450 Kč; sleva 10 % → 900 Kč.
- Pravidlo s hodnotou 0 % vyřadí zboží z výchozí slevy i přirážky hladiny.
- Pravidlo může platit jen v jedné měně, bez měny platí ve všech. Pevná cena má měnu
  vždy.

Výjimku pro produkt zadáte i přímo na kartě zboží v záložce **Ceny**, v sekci **Cenové
hladiny**. Řádek pro každou aktivní hladinu a měnu ukazuje zděděnou cenu a její zdroj
(výchozí hodnota, kategorie, výrobce). Pole **Sleva nebo přirážka pro produkt** má
výběr **Sleva** / **Přirážka**. Výjimku uložíte společným tlačítkem **Uložit**
editoru, návratem k zděděné ceně ji odeberete. Pravidla kategorií a výrobců se tím
nemění.

#### 38.11.18.2 Které pravidlo platí

Pro kartu a měnu dokladu se použije první, co platí:

1. pravidlo **produktu**,
2. pravidlo **kategorie** nebo **výrobce** karty. Obě mají stejnou váhu, rozhoduje
   priorita pravidla, při shodě pravidlo pro konkrétní měnu a pak dříve založené
   pravidlo. Stejné pořadí přednosti platí u cenových profilů v cenotvorbě,
3. **výchozí sleva nebo přirážka** hladiny, pokud je nenulová.

Pevná cena v jiné měně, než je měna dokladu, se nepoužije. Sleva ani přirážka bez
standardní ceny v měně dokladu nemá z čeho počítat a hladina pak cenu neurčí.

Na faktuře má přednost individuální cena odběratele, hladina rozhoduje až po ní a akční
cena vyhraje jen tehdy, když je levnější (viz
[§ 38.11.8.12](#3811812-individualni-ceny-zakazniku)). U řádku faktury se ukáže, že cenu
určila hladina, například „Gold −10 %“ nebo „Běžný +45 %“.

- Hladinu, kterou mají přiřazenou odběratelé, nejde smazat. **Deaktivujte** ji,
  její odběratelé se pak naceňují standardní cenou a hladinu mají na kartě dál, dokud
  ji nezměníte.
- Kód hladiny jde měnit kdykoli, odběratelé jsou na hladinu navázaní napevno.

#### 38.11.18.3 Cenová hladina na dokladu

Některé firmy nevolí ceník podle toho, kdo kupuje, ale podle obchodního případu:
expresní a standardní objednávka, zvláštní podmínky pro jednu zakázku nebo
velkoobchodní ceník pro jednorázový nákup. Proto má editor faktury pod odběratelem výběr
**Cenová hladina dokladu** (jen se zapnutým skladem a aspoň jednou hladinou):

- **Podle odběratele** (výchozí) naceňuje jako dosud hladinou z karty odběratele.
- Zvolená hladina ji pro tento doklad nahradí a platí i u odběratele bez hladiny.
  Individuální cena odběratele má dál přednost.
- Změna výběru přecení skladové řádky s automaticky doplněnou cenou. Ručně upravené
  ceny zůstanou.
- U uloženého dokladu (koncept i úprava vystavené faktury) se ceny řádků samy nemění.
  Když po znovuotevření změníte hladinu dokladu nebo vyberete odběratele s jinou
  hladinou, editor se zeptá, jestli přecenit skladové položky podle nové hladiny, uvede
  její název a počet řádků. Potvrzením se nacení skladové řádky s nezápornou jednotkovou
  cenou, i ty s ručně upravenou cenou. Záporné jednotkové ceny vratek a odpočtů se nemění;
  dobropisy se záporným množstvím a kladnou cenou se přecení.
  **Zrušit** ponechá uložené i ručně upravené ceny. Řádky bez skladové
  karty, textové řádky a sleva z celé faktury se nemění. Přeceněné řádky se při další
  změně odběratele, měny nebo hladiny přeceňují samy jako u nového dokladu. Karta, která
  nemá cenu v měně dokladu, se nepřecení: řádek si nechá cenu, editor řekne, kolika řádků
  se to týká, a při další změně hladiny se zeptá znovu. Když přecenění selže, editor to
  ohlásí a ceny zůstanou beze změny.
- Hladina se uloží s dokladem, takže při další úpravě konceptu zůstane vybraná, detail
  faktury ji ukazuje a kopie faktury ji převezme. Ceny jsou uložené na řádcích, pozdější
  změna nebo deaktivace hladiny vystavený doklad nemění.

Příklad: odběratel má hladinu **Dealer**, urgentní objednávky se prodávají s nižší
slevou. Založte hladinu **Dealer urgentní** s jejími slevami a u urgentní objednávky ji
vyberte na faktuře. Karta odběratele zůstane beze změny. Běžnému zákazníkovi, kterému
se urgentní objednávka účtuje dráž, vyberete hladinu s přirážkou, například +5 %.

### 38.11.19 Měny

Záložka **Měny** (`Sklad → E-shop`) obsahuje prodejní měny, ve kterých uvádíte ceny zboží
v e-shopu. Ceník na kartě zboží nabídne právě je. S bankovními účty to nesouvisí, nacenit
lze i měnu, ve které účet nemáte.

Formulář nové měny (**Nová měna**): **Rychlá volba** (nejčastější měny, předvyplní kód
a název, obojí lze přepsat), **Kód** (trojpísmenný kód ISO 4217, například „CZK“),
**Název**, **Symbol**, **Pořadí**, **Aktivní** a **Výchozí měna**. U měny se zadanými
cenami už nejde změnit kód. Taková měna nejde smazat, **archivujte** ji.

### 38.11.20 Editor karty - ukládání

Tlačítko **Uložit** v editoru skladové karty zapíše základní údaje, e-shopový obsah,
ceny, akční ceny a dodavatele jako jeden celek. Pokud některá z těchto částí neprojde
kontrolou, neuloží se ani ostatní rozpracované změny. Když tutéž kartu mezitím uloží
jiný uživatel, editor změnu odmítne jako konflikt a ponechá rozepsané hodnoty ve
formuláři, aby je šlo porovnat s aktuálním stavem.

Při přepnutí na jinou stránku editor upozorní na dosud neuložené změny. Spodní lišta
s akcemi **Uložit** a **Zrušit** zůstává viditelná i při dlouhém formuláři. Záložky mají
stav v URL, ovládají se také šipkami vlevo a vpravo a aktivní záložka se na telefonu
automaticky posune do viditelné části lišty.

Záložka **Přílohy** má vlastní stav ukládání. Nahrání, změna pořadí, nastavení hlavního
obrázku, exportu i smazání se ukládají okamžitě a nejsou součástí tlačítka **Uložit**
pro zbytek karty.

## 38.12 Související kapitoly

- [Sklad](37_Sklad.md): skladové karty, doklady, objednávky dodavatelům a inventury.
- [Shoptet](39_Shoptet.md): napojení Shoptetu přes soubory a feed zásob.
- [Klienti](18_Klienti.md): role dodavatele a cenová hladina odběratele.
- [Editor faktury](15_Faktura_editor.md): cenová hladina dokladu a nacenění skladových řádků.
- [Nastavení](96_Nastaveni.md): zapnutí skladu a ceník pro firmy bez skladu.
- [REST API](103_API.md): veřejné API pro změny katalogu.
- [Po instalaci](05_Po_instalaci.md): plánovač a úlohy na pozadí.
