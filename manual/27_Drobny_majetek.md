# 27. Drobný majetek

> Návod, jak vést evidenci drobného majetku, který firma účtuje při pořízení rovnou do nákladů:
> jak karty vznikají z přijatých faktur, jak je vyřadit nebo prodat a jak připravit inventurní
> sestavy. Pro účetní a každého, kdo odpovídá za inventuru majetku.

## 27.1 Kdy to potřebujete

- Přišla faktura za věc dlouhodobějšího použití pod hranicí dlouhodobého majetku (například notebook) a chcete ji mít v evidenci.
- Pořídili jste majetek bez dokladu v aplikaci (dar, vklad, historický majetek) a potřebujete ho zaevidovat ručně.
- Věc se rozbila, ztratila, nebo ji prodáváte.
- Blíží se inventura a potřebujete soupis podle umístění a odpovědných osob.
- Chcete odsouhlasit účet 501 s operativní evidencí.

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| při zaúčtování faktury | Zkontrolovat **Druh nákladu** **Drobný majetek** na řádku | detail nebo editor přijaté faktury, [§ 27.3](#273-krok-za-krokem-zaevidovat-drobny-majetek-z-prijate-faktury) |
| když věc vyřadíte | Vyřadit kartu s datem a důvodem | `Nákup → Drobný majetek`, [§ 27.5](#275-krok-za-krokem-vyradit-nebo-prodat-kartu) |
| když věc prodáte | Prodat kartu a propojit s vydanou fakturou | stejná stránka nebo editor vydané faktury |
| při inventuře | Vytisknout soupis k datu | sekce **Sestavy**, [§ 27.6](#276-krok-za-krokem-pripravit-sestavy-a-inventuru) |
| měsíčně | Odsouhlasit náklady na 501 s kartami | [§ 27.9.4](#2794-inventura-a-mesicni-kontrola) |

> [!WARNING]
> Karta drobného majetku **nic nezaúčtuje**. Náklad vzniká z přijaté faktury nebo jiného zdrojového dokladu,
> karta pouze dokládá existenci a pohyb věci.

## 27.2 Než začnete

- Modul je dostupný firmám v **podvojném účetnictví** i v **daňové evidenci** a vyžaduje oprávnění k účetnictví. Pro čtení,
  sestavy a export stačí čtecí varianta, pro založení, úpravu, vyřazení, prodej, obnovení a smazání je potřeba zápisová varianta.
- V **daňové evidenci** evidence povinná není (§ 28 odst. 5 zákona o účetnictví je účetní předpis), pomáhá ale doložit výdaj
  a inventuru. Karty fungují stejně, jen nic neúčtují: výdaj nese peněžní deník při úhradě dokladu. Sestava **Rozpis nákladů**
  (účet 501) tu chybí, soupis k datu a pohyby za období jsou k dispozici.
- Drobný majetek je operativní evidence hmotných a nehmotných věcí dlouhodobějšího použití, které firma podle své vnitřní
  směrnice účtuje při pořízení přímo do nákladů. Nejde o zjednodušenou kartu dlouhodobého majetku: nemá odpisový plán
  ani zůstatkovou cenu.
- Rozhodněte, co je pro vás drobný majetek a co materiál či dlouhodobý majetek (viz [§ 27.9.2](#2792-klasifikace-na-prijate-fakture)).
  Systém nezná skutečnou samostatnou použitelnost ani vaši vnitřní směrnici.

## 27.3 Krok za krokem: zaevidovat drobný majetek z přijaté faktury

Karty vznikají **automaticky při uložení přijaté faktury**, jejíž řádek má **Druh nákladu** **Drobný majetek** (nebo **Drobný nehmotný majetek**). Ruční generování
tlačítkem neexistuje.

1. Otevřete přijatou fakturu (viz [Přijaté faktury](23_Prijate_faktury.md)).
2. U každého řádku, který je drobný majetek, nastavte **Druh nákladu** na **Drobný majetek**. Návrh může přijít z importu,
   rozpoznání textu nebo firemního pravidla (viz [Šablony](65_Sablony.md)), konečné rozhodnutí však dělá účetní.
3. Fakturu uložte a zaúčtujte. Zaúčtování použije nákladovou předkontaci, typicky analytiku 501.
4. Otevřete `Nákup → Drobný majetek` a zkontrolujte, že karta vznikla. Doplňte inventární číslo, umístění a odpovědnou osobu.

**Jak poznáte, že je hotovo:** v seznamu je karta se stavem **V užívání**, zdrojem **Přijatá faktura** a cenou odpovídající
řádku faktury. Na kartě u položky v detailu faktury je vidět její název a stav.

### 27.3.1 Ruční karta

Ruční kartu použijte pro historický majetek, dar nebo vklad, který nemá doklad v aplikaci.

1. Otevřete `Nákup → Drobný majetek` a klikněte na **Nová karta**.
2. Vyplňte název, datum pořízení, množství a cenu, případně inventární číslo, umístění, odpovědnou osobu a poznámku.
3. Uložte.

**Jak poznáte, že je hotovo:** hláška **Karta založena.** a karta je v seznamu se zdrojem **Bez dokladu**. Cena musí být
nezáporná, množství kladné a datum uvedení do používání nesmí předcházet pořízení.

## 27.4 Krok za krokem: najít kartu v seznamu

1. Otevřete `Nákup → Drobný majetek`.
2. Filtrujte podle **Stavu**, **Umístění**, **Roku pořízení** nebo fulltextu **Hledat**. Fulltext prohledává název,
   inventární číslo, dodavatele a odkaz na doklad.
3. Tabulka ukazuje množství, cenu, umístění, odpovědnou osobu a stav.

Stavy jsou **V užívání**, **Vyřazeno** a **Prodáno**. Souhrn **Součet stránky** počítá počet a cenu právě zobrazených řádků,
nikoli nutně celé evidence mimo aktuální stránku. Pro celkové částky použijte sestavy.

## 27.5 Krok za krokem: vyřadit nebo prodat kartu

### 27.5.1 Vyřazení

1. V seznamu u karty klikněte na **Vyřadit**.
2. Zadejte **Datum vyřazení** (nesmí předcházet pořízení) a **Důvod vyřazení**.
3. Potvrďte.

**Jak poznáte, že je hotovo:** hláška **Karta vyřazena.** a stav **Vyřazeno**. Karta se nemaže, aby byla zachovaná historická
inventurní stopa.

### 27.5.2 Prodej z karty

1. V seznamu u karty klikněte na **Prodat**.
2. Vyhledejte **Vydanou fakturu prodeje** podle variabilního symbolu nebo odběratele. Pokud fakturu nemáte, použijte odkaz **vystavit fakturu**.
3. Zadejte **Datum prodeje** a volitelně **Prodejní cenu bez DPH**.
4. Potvrďte.

**Jak poznáte, že je hotovo:** hláška **Karta prodána.** a stav **Prodáno**. Výnos a DPH vznikají zaúčtováním vydané faktury,
ne kartou. Zůstatková cena je 0 (náklad na 501 padl při pořízení), takže se z karty nic nedoúčtovává.

### 27.5.3 Prodej z vydané faktury

Pohodlnější cesta vede z druhé strany:

1. V editoru vydané faktury zaškrtněte **Prodej majetku**.
2. U každé položky vyberte kartu z našeptávače karet v užívání (drobný i dlouhodobý majetek pohromadě). Vybraná karta předvyplní
   popis řádku a určí, kam půjde výnos.
3. Fakturu vystavte.

**Jak poznáte, že je hotovo:** karta se po vystavení faktury uzavře sama. Drobný majetek přejde na *prodáno*, dlouhodobý se
vyřadí včetně doúčtování zůstatkové ceny. Storno faktury karty vrátí do užívání.

Výnos z drobného majetku jde na účet **642** (tržby z prodeje materiálu, protože pořízením šel do spotřeby na 501), z dlouhodobého
na **641**. Rozpad je po řádcích, takže jedna faktura může vedle sebe prodat majetek i fakturovat službu a každý řádek sedne na
svůj účet.

### 27.5.4 Prodej z detailu přijaté faktury

U položky s vazbou na kartu se v detailu přijaté faktury zobrazí její název a stav. U karty v užívání odkaz **Vyřadit prodejem**
otevře v evidenci drobného majetku rovnou okno prodeje pro tuto kartu, takže ji není třeba dohledávat ručně v seznamu.

### 27.5.5 Vrácení do užívání

Vyřazenou nebo prodanou kartu vrátíte do stavu v užívání tlačítkem **Vrátit do užívání**. Hláška **Karta vrácena do užívání.**
Obnovení vymaže údaje vyřazení nebo prodeje na kartě, ale nestornuje zdrojovou fakturu ani jiné ruční účetní zápisy. Ty musí
účetní posoudit samostatně.

Fyzické smazání používejte jen pro chybně založenou kartu. Běžné vyřazení se eviduje stavem.

## 27.6 Krok za krokem: připravit sestavy a inventuru

1. Otevřete `Nákup → Drobný majetek` a přejděte do sekce **Sestavy**.
2. Zvolte sestavu a datum či období:
   - **Soupis drobného majetku k datu** (pole **Ke dni**),
   - **Přírůstky a úbytky za období** (pole **Od** a **Do**),
   - **Rozpis 501 dle druhu výdaje** (pole **Od** a **Do**).
3. Klikněte na **PDF** nebo **XLSX**.
4. Při inventuře porovnejte sestavu s fyzickou existencí, inventárními štítky, umístěním a odpovědnými osobami.

**Jak poznáte, že je hotovo:** stáhne se soubor sestavy. Systém umí sestavit seznam a součty, nikoli potvrdit skutečný stav věci.

## 27.7 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Hláška **Karta už není v užívání - je vyřazená nebo prodaná.** | Opakované vyřazení nebo prodej | Kartu nejdřív vraťte do užívání |
| Hláška **Vyberte fakturu prodeje.** | Při prodeji chybí vydaná faktura | Vyhledejte fakturu, nebo ji vystavte odkazem **vystavit fakturu** |
| Hláška **Název je povinný.** | Chybí název karty | Doplňte název |
| Neplatné datum nebo nulové množství | Datum uvedení do používání předchází pořízení, nebo je množství nulové | Opravte hodnoty |
| Karta se nevytvořila z řádku faktury | Řádek nemá **Druh nákladu** Drobný majetek, je to proforma, záloha, sleva, účtenka nebo záporný řádek | Nastavte **Druh nákladu** na řádku a fakturu uložte. Účtenku zaevidujte ručně kartou |
| Dobropis nic nevyřadil | Dobropis nemá vazbu na původní fakturu | Doplňte vazbu v dobropisu, nebo kartu vyřaďte ručně (viz [§ 27.9.3](#2793-generovani-karet-z-dokladu)) |
| Zdrojový doklad, řádek, dodavatel nebo faktura prodeje patří jiné firmě | Karta musí patřit do firmy, ve které ji zakládáte | Vyberte doklad aktuální firmy |
| Karta má více zdrojů | Karta smí mít nejvýše jeden přímý zdroj | Ponechte jeden zdroj |
| Rozdíl mezi rozpisem a cenou karet | Chybějící karta, sleva, dobropis, cizí kurz nebo chybná klasifikace | Zkontrolujte zdroj. Rozdíl není pokynem k automatickému doúčtování |

> [!TIP]
> Nejdřív odsouhlaste klasifikaci a zaúčtování zdrojového dokladu, potom doplňte inventární údaje. Evidence i účet 501 tak budou
> vycházet ze stejného podkladu.

## 27.8 Související kapitoly

- [Přijaté faktury](23_Prijate_faktury.md) - odkud karty vznikají.
- [Majetek](28_Majetek.md) - dlouhodobý majetek s odpisy.
- [Šablony](65_Sablony.md) - pravidla nákladů, která navrhují druh výdaje.
- [Uzávěrka](72_Uzaverka.md) - volitelné časové rozlišení nákladu drobného majetku.

## 27.9 Podrobnosti a pravidla

### 27.9.1 Co karta uchovává

Karta obsahuje zejména:

- název a volitelné inventární číslo,
- datum pořízení a datum uvedení do používání,
- množství, jednotkovou a celkovou cenu v CZK,
- dodavatele a snapshot čísla zdrojového dokladu,
- umístění, odpovědnou osobu a poznámku,
- stav, datum a důvod vyřazení,
- u prodeje vydanou fakturu, datum a evidenční prodejní cenu.

Karta smí mít nejvýše jeden přímý zdroj: řádek přijaté faktury, pokladní doklad nebo žádný zdroj u ruční evidence. Služba to
kontroluje před zápisem a ověřuje, že zdroj i dodavatel patří aktuální firmě. Současný webový formulář výběr pokladního dokladu
nenabízí, API a datový model jej podporují.

Seznam fulltextem prohledává název, inventární číslo, dodavatele a odkaz na doklad.

### 27.9.2 Klasifikace na přijaté faktuře

Řádek přijaté faktury musí mít potvrzený druh **Drobný majetek**. Návrh může přijít z importu, rozpoznání textu nebo firemního
pravidla v kapitole [Šablony](65_Sablony.md), konečné rozhodnutí však dělá účetní.

Zaúčtování faktury použije nákladovou předkontaci, typicky analytiku 501. Vytvoření karty poté účetní zápis neopakuje.
Smazání karty také nesmaže náklad z deníku.

Rozlišujte:

- materiál a spotřebu,
- drobný hmotný či nehmotný majetek,
- dlouhodobý majetek nad pravidly firmy a zákona,
- soubor samostatných movitých věcí,
- technické zhodnocení.

Popis položky a cenový práh jsou pomůcky. Systém nezná skutečnou samostatnou použitelnost ani vnitřní směrnici. Hranice
80 000 Kč se poměřuje proti ceně za kus (§ 26 odst. 2 ZDP, § 28 odst. 5 ZoÚ, ČÚS 013).

### 27.9.3 Generování karet z dokladu

Karty se zakládají ze všech kladných řádků klasifikovaných jako drobný hmotný nebo nehmotný majetek.

#### Idempotence

Editace faktury položky maže a znovu zakládá, proto idempotence nestojí pouze na ID řádku. Přirozeným klíčem je v rámci dokladu
normalizovaný **název + cena**. Opakované uložení nebo nevinná editace dokladu tak nevytvoří duplicitu.

Při synchronizaci se doplní nově klasifikované položky. Automatická karta bez protějšku se může odstranit jen tehdy, pokud je
stále v užívání a uživatel na ní nevyplnil inventární číslo, umístění, odpovědnou osobu ani poznámku. Ručně doplněná či vyřazená
karta se potichu nemaže.

#### Slevy a cizí měna

Záporný řádek stejné faktury představující slevu nevytváří zápornou kartu. Služba jej poměrně rozloží mezi kladné majetkové
řádky a haléřový zbytek přidá největší položce. Součet cen karet tak odpovídá čistému nákladu dokladu.

Slevový řádek (Sleva, Rabatt, Aktionsrabatt, Discount, Kupon, Voucher, Promo) se přiřadí k položce, kterou zlevňuje, bez ohledu
na to, jaký druh výdaje má sám na sobě. Stejně ho rozdělí zaúčtování i evidence, takže cena karty sedí na účet 501. Pravidla
přiřazení:

- sleva, která mluví o dopravě („Sleva na dopravné"), patří k dopravě,
- sleva přesně ve výši dopravy (doprava zdarma) patří k dopravě,
- sleva, která jmenuje položku („Sleva AlzaPlus+"), patří k té položce,
- jinak patří ke zboží stejné sazby DPH, doprava, poplatky a služby slevu nenesou, je-li na dokladu i zboží.

Slevový řádek s ručně zvoleným účtem zůstává na svém účtu. Už zaúčtované doklady přeúčtuje
`php api/bin/purchase-discount-reclass.php` (bez `--apply` jen vypíše kandidáty, s `--supplier=N --id=N --apply` přepíše zápis
na místě a srovná karty).

U cizoměnového dokladu se cena převede do CZK kurzem uloženým na faktuře. Stejný kurz se používá pro evidenční cenu i posouzení
částky za kus.

Proforma ani zálohový doklad kartu nezakládají, pořízení vzniká až z finální faktury. Účtenku služba automaticky negeneruje,
lze ji evidovat ručně.

#### Dobropis a vrácení dodavateli

Navázaný dobropis nevytváří záporný majetek. Podle vazby na původní fakturu najde původní doklad a podle názvu a absolutní ceny
jen skutečně vracenou kartu označí jako vyřazenou. Částečný dobropis tak nevyřadí ostatní věci ze stejné faktury.

Nenavázaný dobropis nehádá původní kartu a nic automaticky nevyřadí. Účetní musí nejdřív doplnit vazbu nebo provést evidenční
opravu ručně.

### 27.9.4 Inventura a měsíční kontrola

Při inventuře porovnejte sestavu s fyzickou existencí, inventárními štítky, umístěním a odpovědnými osobami. Systém umí sestavit
seznam a součty, nikoli potvrdit skutečný stav věci.

Měsíční kontrola porovnává náklady řádků označených jako drobný majetek s kartami v relevantním období. Chybějící pokrytí je
varování, ne automatický zápis.

Uzávěrka může nabídnout volitelné časové rozlišení nákladu drobného majetku. Nejde o daňový odpis. Použije účetní politiku
období a předkontaci 381/501, v dalším období vytvoří zrcadlové rozpuštění. Bez doložené doby užitku a významnosti návrh
nepotvrzujte, podrobnosti jsou v kapitole [Uzávěrka](72_Uzaverka.md).

### 27.9.5 Sestavy

Sekce sestav nabízí PDF i XLSX:

- **Soupis k datu** zahrne karty existující k rozhodnému dni a seskupí je podle umístění. Uvede inventární číslo, zdroj,
  dodavatele, odpovědnou osobu, množství a cenu. Karta vyřazená až po zvoleném dni v historickém soupisu zůstane.
- **Pohyby za období** odděleně vypíše přírůstky podle data pořízení a úbytky podle data vyřazení či prodeje. Součástí jsou
  počty a celkové částky.
- **Rozpis nákladů** nevychází z karet, ale z řádků přijatých faktur. Rozděluje materiál (501.100) a drobný majetek (501.200)
  a uvádí doklad, dodavatele, popis, množství a částku. Tím lze porovnat účetní podklad na 501 s operativní evidencí.

Rozdíl mezi rozpisem a cenou karet může znamenat chybějící kartu, slevu, dobropis, cizí kurz nebo chybnou klasifikaci. Není
pokynem k automatickému doúčtování bez kontroly zdroje.

### 27.9.6 Oprávnění a bezpečnost

Čtení, sestavy a export používají oprávnění k účetnictví, založení, úprava, vyřazení, prodej, obnovení a smazání jeho zápisovou
variantu. Modul je dostupný v podvojném účetnictví i v daňové evidenci, rozpis nákladů jen v podvojném účetnictví. API je
omezené na firmu.
