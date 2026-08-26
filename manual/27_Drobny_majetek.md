# 27. Drobný majetek

**Cesta: `Nákup → Drobný majetek`**

Drobný majetek je operativní evidence hmotných a nehmotných věcí
dlouhodobějšího použití, které firma podle své vnitřní směrnice účtuje při
pořízení přímo do nákladů. Nejde o zjednodušenou kartu dlouhodobého majetku:
nemá odpisový plán ani zůstatkovou cenu.

> [!IMPORTANT]
> Karta drobného majetku **nic nezaúčtuje**. Náklad vzniká z přijaté faktury
> nebo jiného zdrojového dokladu; karta pouze dokládá existenci a pohyb věci.

## 27.1 Seznam a filtry

Seznam lze filtrovat podle stavu, textu, umístění a roku pořízení. Fulltext
prohledává název, inventární číslo, dodavatele a odkaz na doklad. Tabulka
ukazuje množství, cenu, umístění, odpovědnou osobu a stav.

Stavy jsou:

- **V používání (`in_use`)**,
- **Vyřazeno (`disposed`)**,
- **Prodáno (`sold`)**.

Souhrn stránky počítá počet a cenu právě zobrazených řádků, nikoli nutně celé
evidence mimo aktuální stránku. Pro celkové částky používej sestavy.

## 27.2 Co karta uchovává

Karta obsahuje zejména:

- název a volitelné inventární číslo,
- datum pořízení a datum uvedení do používání,
- množství, jednotkovou a celkovou cenu v CZK,
- dodavatele a snapshot čísla zdrojového dokladu,
- umístění, odpovědnou osobu a poznámku,
- stav, datum a důvod vyřazení,
- u prodeje vydanou fakturu, datum a evidenční prodejní cenu.

Karta smí mít nejvýše jeden přímý zdroj: řádek přijaté faktury, pokladní doklad
nebo žádný zdroj u ruční evidence. Služba to kontroluje před zápisem a ověřuje,
že zdroj i dodavatel patří aktuální firmě. Současný webový formulář výběr
pokladního dokladu nenabízí, API a datový model jej podporují.

Ruční kartu použij pro historický majetek, dar nebo vklad, který nemá doklad v
aplikaci. Cena musí být nezáporná, množství kladné a datum uvedení do používání
nesmí předcházet pořízení.

## 27.3 Klasifikace na přijaté faktuře

Řádek přijaté faktury musí mít potvrzený druh **Drobný majetek**. Návrh může
přijít z importu, rozpoznání textu nebo firemního pravidla v kapitole
[Šablony](80_Sablony.md), konečné rozhodnutí však dělá účetní.

Zaúčtování faktury použije nákladovou předkontaci, typicky analytiku 501.
Vytvoření karty poté účetní zápis neopakuje. Smazání karty také nesmaže náklad
z deníku.

Rozlišuj:

- materiál a spotřebu,
- drobný hmotný či nehmotný majetek,
- dlouhodobý majetek nad pravidly firmy a zákona,
- soubor samostatných movitých věcí,
- technické zhodnocení.

Popis položky a cenový práh jsou pomůcky. Systém nezná skutečnou samostatnou
použitelnost ani vnitřní směrnici.

## 27.4 Generování karet z dokladu

Z detailu přijaté faktury lze vytvořit karty ze všech kladných řádků
klasifikovaných jako drobný hmotný nebo nehmotný majetek.

### 27.4.1 Idempotence

Editace faktury položky maže a znovu zakládá, proto idempotence nestojí pouze na
ID řádku. Přirozeným klíčem je v rámci dokladu normalizovaný **název + cena**.
Opakované spuštění nebo nevinná editace dokladu tak nevytvoří duplicitu.

Při synchronizaci se doplní nově klasifikované položky. Automatická karta bez
protějšku se může odstranit jen tehdy, pokud je stále v používání a uživatel
na ní nevyplnil inventární číslo, umístění, odpovědnou osobu ani poznámku.
Ručně doplněná či vyřazená karta se potichu nemaže.

### 27.4.2 Slevy a cizí měna

Záporný řádek stejné faktury představující slevu nevytváří zápornou kartu.
Služba jej poměrně rozloží mezi kladné majetkové řádky a haléřový zbytek přidá
největší položce. Součet cen karet tak odpovídá čistému nákladu dokladu.

U cizoměnového dokladu se cena převede do CZK kurzem uloženým na faktuře.
Stejný kurz se používá pro evidenční cenu i posouzení částky za kus.

Proforma ani zálohový doklad kartu nezakládají; pořízení vzniká až z finální
faktury. Účtenku služba automaticky negeneruje, lze ji evidovat ručně.

### 27.4.3 Dobropis a vrácení dodavateli

Navázaný dobropis nevytváří záporný majetek. Podle
`parent_purchase_invoice_id` najde původní doklad a podle názvu a absolutní
ceny jen skutečně vracenou kartu označí jako vyřazenou. Částečný dobropis tak
nevyřadí ostatní věci ze stejné faktury.

Nenavázaný dobropis nehádá původní kartu a nic automaticky nevyřadí. Účetní
musí nejdřív doplnit vazbu nebo provést evidenční opravu ručně.

## 27.5 Úprava, vyřazení, prodej a obnovení

Kartu v používání lze upravit nebo vyřadit s datem a důvodem. Datum vyřazení
nesmí předcházet pořízení. Vyřazení mění stav, ale kartu nemaže, aby byla
zachovaná historická inventurní stopa.

**Prodat** vyžaduje vydanou fakturu stejné firmy a datum prodeje. Karta se
propojí s dokladem a může uložit prodejní cenu bez DPH. Výnos a DPH vznikají
zaúčtováním vydané faktury, nikoli kartou; pořizovací náklad už byl uplatněn,
takže karta neúčtuje zůstatkovou cenu.

Pohodlnější cesta vede z druhé strany, přímo z faktury. V editoru vydané faktury
zaškrtni **Prodej majetku** — u každé položky se objeví našeptávač karet v užívání
(drobný i dlouhodobý majetek pohromadě). Vybraná karta předvyplní popis řádku a
určí, kam půjde výnos: drobný majetek na **642** (tržby z prodeje materiálu,
protože pořízením šel do spotřeby na 501), dlouhodobý na **641**. Rozpad je po
řádcích, takže jedna faktura může vedle sebe prodat majetek i fakturovat službu a
každý řádek sedne na svůj účet. Po vystavení faktury se karta uzavře sama —
drobný majetek přejde na *prodáno*, dlouhodobý se vyřadí včetně doúčtování
zůstatkové ceny. Storno faktury karty vrátí do užívání.

Vyřazenou nebo prodanou kartu lze vrátit do stavu v používání. Obnovení vymaže
údaje vyřazení/prodeje na kartě, ale nestornuje zdrojovou fakturu ani jiné
ruční účetní zápisy. Ty musí účetní posoudit samostatně.

Kartu lze otevřít k prodeji i z opačné strany — z **detailu přijaté faktury**, ze
které vznikla. U položky s vazbou na kartu se zobrazí její název a stav; u karty
v používání odkaz **Vyřadit prodejem** otevře v evidenci drobného majetku rovnou
okno prodeje pro tuto kartu, takže ji není třeba dohledávat ručně v seznamu.

Fyzické smazání používej jen pro chybně založenou kartu. Běžné vyřazení se
eviduje stavem.

## 27.6 Sestavy

Sekce sestav nabízí PDF i XLSX:

### 27.6.1 Soupis k datu

Zahrne karty existující k rozhodnému dni a seskupí je podle umístění. Uvede
inventární číslo, zdroj, dodavatele, odpovědnou osobu, množství a cenu. Karta
vyřazená až po zvoleném dni v historickém soupisu zůstane.

### 27.6.2 Pohyby za období

Odděleně vypíše přírůstky podle data pořízení a úbytky podle data
vyřazení/prodeje. Součástí jsou počty a celkové částky.

### 27.6.3 Rozpis nákladů

Rozpis nevychází z karet, ale z řádků přijatých faktur. Rozděluje materiál a
drobný majetek a uvádí doklad, dodavatele, popis, množství a částku. Tím lze
porovnat účetní podklad na 501 s operativní evidencí.

Rozdíl mezi rozpisem a cenou karet může znamenat chybějící kartu, slevu,
dobropis, cizí kurz nebo chybnou klasifikaci. Není pokynem k automatickému
doúčtování bez kontroly zdroje.

## 27.7 Inventura a měsíční kontrola

Při inventuře porovnej sestavu s fyzickou existencí, inventárními štítky,
umístěním a odpovědnými osobami. Systém umí sestavit seznam a součty, nikoli
potvrdit skutečný stav věci.

Měsíční kontrola porovnává náklady řádků označených jako drobný majetek s
kartami v relevantním období. Chybějící pokrytí je varování, ne automatický
zápis.

Uzávěrka může nabídnout volitelné časové rozlišení nákladu drobného majetku.
Nejde o daňový odpis. Použije účetní politiku období a předkontaci 381/501,
v dalším období vytvoří zrcadlové rozpuštění. Bez doložené doby užitku a
významnosti návrh nepotvrzuj; podrobnosti jsou v kapitole
[Uzávěrka](87_Uzaverka.md).

## 27.8 Oprávnění a chyby

Čtení, sestavy a export používají oprávnění `accounting`; založení, úprava,
vyřazení, prodej, obnovení a smazání jeho zápisovou variantu. Modul je dostupný
jen v podvojném účetnictví. API je tenantově omezené.

Nejčastější chyby:

- neplatné datum nebo nulové množství,
- zdrojový doklad, řádek, dodavatel či faktura prodeje patří jiné firmě,
- karta má současně více zdrojů,
- opakované vyřazení nebo prodej,
- prodej vyřazené karty bez předchozího obnovení,
- dobropis nemá vazbu na původní fakturu,
- položka je proforma, sleva nebo jiný druh nákladu a kartu nevytváří.

> [!TIP]
> Nejdřív odsouhlas klasifikaci a zaúčtování zdrojového dokladu, potom vytvoř
> karty a doplň inventární údaje. Evidence i účet 501 tak budou vycházet ze
> stejného podkladu.
