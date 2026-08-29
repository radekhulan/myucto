# 9. Klientský portál

Klientský portál je samostatná domovská obrazovka pro roli **client** — účet, který
účetní firma zřídí svému klientovi (podnikateli, majiteli firmy), aby mohl bez
zprostředkování účetní pracovat s vlastními doklady a zároveň měl po ruce rychlý
přehled hospodaření. Portál **není** oddělená aplikace ani zjednodušené UI — klient
se přihlašuje do stejného MyÚčto.cz, jen s výrazně užší nabídkou menu a s doklady,
které se po zaúčtování uzamknou proti dalším úpravám.

> [!NOTE]
> Role **client** je jiná kategorie než **readonly** (viz [§ 92.2.2 Role](92_Nastaveni.md#9222-role-a-opravneni)).
> Readonly je interní pracovník firmy, který smí jen číst a exportovat cokoliv.
> Client je naopak externí osoba (majitel firmy, na kterou účetní vede agendu),
> která **smí vystavovat a upravovat vlastní doklady**, ale nevidí nic z účetnictví,
> banky, reportů ani nastavení systému a nemůže sáhnout na doklad, který už účetní
> zaúčtovala. Vybrané provozní nastavení vlastní firmy dostane pouze tehdy, když
> má jeho klientská role oprávnění **Nastavení firmy** na úrovni **Zápis**.

## 9.1 Kdo roli client dostane a jak

Uživatele s klientskou rolí zakládá výhradně **superadmin** v **Systém →
Uživatelé** (stejný formulář jako u ostatních rolí, viz [§ 92.2 Uživatelé](92_Nastaveni.md#922-uzivatele)):

1. V poli **Role** se zvolí aktivní role typu **client**.
2. V sekci přiřazení firem se našeptávačem přidají firmy, ke kterým má klient
   mít přístup — typicky jedna, u víceoborových klientů i více. U každé firmy
   lze ponechat výchozí klientskou roli nebo vybrat jinou aktivní roli typu
   **client**; interní roli typu **staff** backend odmítne.
3. Bez uloženého přiřazení k žádné firmě se klient po přihlášení dostane na portál,
   ale uvidí jen prázdný stav s výzvou kontaktovat účetní (viz [§ 9.3.6](#936-prazdny-stav-bez-firmy)) —
   přístup je **fail-closed**: žádná firma v systému mu není vidět, dokud ji superadmin
   explicitně nepřiřadí.
4. U klienta s víc firmami funguje běžný přepínač dodavatele ve spodní liště —
   portál i všechny stránky se přepnou na aktuálně zvolenou firmu.

> [!TIP]
> Heslo, 2FA a **Můj profil** fungují pro klienta stejně jako pro ostatní role
> (viz [§ 92.3](92_Nastaveni.md#923-muj-profil)) — klient si po prvním přihlášení
> může nastavit vlastní 2FA, pokud to instalace vyžaduje.

## 9.2 Menu klienta — jen zlomek aplikace

Po přihlášení nabídne menu běžnému klientovi pět sekcí: **Přehled**
(portál, domovská stránka), **Prodej** (vydané faktury + pravidelná fakturace),
**Nákup** (přijaté faktury), **Kontakty** (klienti/dodavatelé) a **Dokumenty**
s položkami **Předat doklady účetní** a **Chybějící doklady**
(viz [§ 9.8](#98-vyzadane-doklady-od-klienta)).
Na desktopu jsou sekce v horní liště s popup položkami, na mobilu v nabídce
**☰**. Nápovědu otevírá kontextová ikona **?** v horní liště.
Cokoliv jiného v aplikaci existuje — účetnictví, banka, sklad, e-shop, reporty,
Grafy, kniha jízd, DMS dokumenty, systémové nastavení, administrace uživatelů — klient v menu
vůbec nevidí a při pokusu dostat se tam přímo přes adresu URL ho systém přesměruje
zpátky na portál. Toto omezení je vynucené na dvou místech zároveň (frontend
i API), takže ho nejde obejít ani ruční úpravou adresy v prohlížeči.

Uvnitř povolených sekcí ale klient **není v režimu jen pro čtení** — smí zakládat
i upravovat vlastní doklady stejně jako účetní, pokud doklad ještě nebyl zaúčtovaný
(viz [§ 9.5](#95-zamek-zauctovanych-dokladu)):

- **Vydané faktury** — založení, editace, vystavení, odeslání e-mailem, přijaté
  platby, přílohy, storno, klonování, výkaz práce k faktuře.
- **Přijaté faktury** — založení, nahrání PDF/ISDOC, editace položek, přechod
  mezi stavy `přijatá ⇄ zaplacená`.
- **Pravidelná fakturace** — správa šablon (šablony samy o sobě nejsou účetní
  doklad, takže se nikdy nezamykají).
- **Kontakty** — zákazníci i dodavatelé, včetně vyhledání firmy přes ARES/VIES.

Naopak klientovi zůstávají nedostupné i uvnitř těchto sekcí citlivější podsekce —
například **platební příkazy** (bankovní ABO/KPC soubory a ověřování účtů), **sken
e-mailové schránky účetní**, **zakázky** ani **DMS dokumenty** k přijatým fakturám.
Sklad a e-shop, daňová evidence, účetní deník a bankovní výpisy jsou pro klienta
zavřené úplně.

### 9.2.1 Delegované nastavení firmy

Samostatná role **Client Admin** není potřeba. Superadmin může vytvořit nebo
duplikovat běžnou roli typu **client** a u položky **Nastavení firmy** zvolit
**Zápis**. Klient pak v nové sekci **Firma → Nastavení firmy** spravuje pouze
výslovně povolené provozní oblasti aktuální firmy:

- odesílací profily, odesílatele, Reply-To, DKIM a supplier-scoped SMTP/IMAP,
- branding komunikace, logo, barvy, patičky a brandingové profily.

Úroveň **Pouze čtení** tuto sekci nezobrazí. Zápis zároveň neotevře původní
administrátorskou stránku nastavení ani obecný endpoint dodavatele. Klient proto
nemůže měnit obchodní jméno, IČ, DIČ, bankovní účty, číslování dokladů, DPH,
účetní režim, integrace, přístupové údaje AI, uživatele ani role. Systémový `sendmail`
a profily s kryptografickým S/MIME podpisem zůstávají ve správě administrátora.

SMTP a IMAP hesla se po uložení už nikdy nevracejí do prohlížeče; rozhraní ukáže
jen informaci, zda je heslo nastavené. Změny i testovací odeslání se zapisují do
historie akcí bez tajných hodnot a požadavky podléhají stejnému rate limitu jako
ostatní mutace. Všechny operace používají firmu z ověřeného kontextu — ID firmy
zaslané v těle požadavku rozsah nerozšíří.

Roli lze přiřadit jako přepis jen u jedné firmy. Tentýž uživatel tak může mít ve
firmě A klientskou roli s **Nastavení firmy = Zápis**, zatímco ve firmě B zůstane
u běžné klientské role. Po přepnutí firmy se menu i oprávnění ihned přepočítají;
stejně se chová klientská vlastní doména.

## 9.3 Co portál (Přehled) zobrazuje

Domovská stránka klienta (`/portal`) je agregovaný přehled hospodaření aktuálně
zvolené firmy — čistě souhrnná čísla, **žádná jména konkrétních zákazníků/dodavatelů
ani čísla dokladů** se v přehledu neobjevují (to je záměrné bezpečnostní omezení,
platí i pro náhled účetní/admina). V záhlaví je název firmy, rozsah období
(od 1. 1. do dneška) a poznámka „Orientační přehled hospodaření — není účetní
závěrka."

### 9.3.1 KPI dlaždice

Čtveřice/pětice karet ukazuje **fakturováno / náklady / rozdíl** za pět období —
**tento měsíc**, **minulý měsíc**, **letos (YTD)**, **loni do dneška** a
**posledních 12 měsíců**. Pokud firma pracuje ve víc měnách, každá karta zobrazí
řádek za každou měnu zvlášť (částky se nesčítají napříč měnami).

### 9.3.2 Měsíční graf

Sloupcový graf fakturace vs. nákladů za posledních 12 měsíců. Pokud firma používá
víc měn, nad grafem je přepínač měny (výchozí CZK, jinak první dostupná).

### 9.3.3 Cashflow — pohledávky, závazky, výhled

Tři karty vedle sebe:

- **Pohledávky** — neuhrazené vydané faktury rozdělené do bucketů „nesplatné",
  „po splatnosti 1–30", „31–60", „61–90" a „90+ dní", s počtem dokladů a součtem
  za měnu.
- **Závazky** — totéž pro nezaplacené přijaté faktury.
- **Výhled 4 týdnů** — týdenní čistý cashflow (očekávané příjmy minus výdaje) na
  4 týdny dopředu + součet za celé období, červeně/zeleně podle znaménka.

### 9.3.4 DPH a daňové termíny

U plátců DPH karta **DPH** ukáže aktuální období, daň na výstupu, daň na vstupu,
výslednou daňovou povinnost (nebo nadměrný odpočet) a termín podání. U neplátců
se místo toho zobrazí informace „Firma není plátce DPH." Vedle karta **Daňové
termíny** vypisuje blížící se termíny v okně 35 dní dopředu (barevně podle
závažnosti) — souvisí s [§ 36 Výkazy DPH](36_Vykazy_DPH.md).

### 9.3.5 Pruh „Účetní čeká na doklady"

Pokud má klient otevřené (nevyřízené) [vyžádání dokladů](#98-vyzadane-doklady-od-klienta),
zobrazí se hned pod záhlavím výrazný pruh s počtem a odkazem na stránku **Chybějící
doklady** — po termínu je pruh červený, jinak žlutý. Pruh zmizí, jakmile klient
všechny otevřené požadavky vyřídí (nahraje doklad nebo je účetní uzavře jinak).

### 9.3.6 Prázdný stav bez firmy

Klient bez přiřazené firmy (viz [§ 9.1](#91-kdo-roli-client-dostane-a-jak))
uvidí místo přehledu jednoduchou zprávu, že jeho účet zatím není propojen se
žádnou firmou, a výzvu kontaktovat účetní. Nová firma bez jakýchkoliv dokladů
zobrazí analogický „zatím tu nejsou žádná data" stav.

## 9.4 Rychlé akce

Pod záhlavím je výrazná akce **Předat doklad účetní**. Otevře bezpečnou podatelnu,
kde stačí vybrat soubor; klient nemusí opisovat dodavatele, částky ani DPH. Běžné
rychlé odkazy **Vystavit fakturu**, **Nahrát přijatou fakturu** a **Přidat kontakt**
zůstávají k dispozici podle oprávnění a vedou na plné editory
[Faktur](14_Faktury.md), [Přijatých faktur](23_Prijate_faktury.md) a
[Klientů](18_Klienti.md). Když klient v editoru přijaté faktury nahraje běžné PDF
nebo fotografii, ze kterých se údaje automaticky nenačtou, může je stále vyplnit
ručně. Druhou možností je jediné tlačítko **Uložit a předat účetní**: formulář se
nezaloží jako neúplná faktura a původní soubor se přesune do stejné bezpečné
podatelny jako při rychlé akci.

## 9.5 Zámek zaúčtovaných dokladů

Jádrem bezpečnosti role client je jednotný **zámek zaúčtovaných dokladů** — jakmile
účetní doklad zaúčtuje (nebo pro něj vznikne aktivní zápis v účetním deníku, nebo
spadá do uzavřeného účetního období), stává se pro klienta **needitovatelným**.
Doklad zamyká kterákoli z těchto podmínek:

- doklad má vyplněné **datum zaúčtování** (u vydaných faktur ho nastaví buď
  zaúčtování do deníku, nebo účetní ručně; u přijatých fakturu odpovídá stavu
  **Zaúčtovaná**),
- existuje aktivní zápis v účetním deníku vázaný na tento doklad,
- firma vede podvojné účetnictví a datum dokladu spadá do **uzavřeného** nebo
  **schváleného** účetního období,
- probíhá závěrka období (**closing**) — to zamyká **jen klienta**, účetní musí
  v této fázi s doklady dál pracovat,
- přijatá faktura vznikla z originálu, který klient předal účetní přes podatelnu;
  takový výsledek zůstává ve správě účetní i jako koncept.

Zamčený doklad zobrazuje badge **„Zaúčtováno"** s vysvětlením „Doklad je
zaúčtovaný — změny a storno vyřídí vaše účetní." (u uzavřeného období obdobný
text). Akce jako úprava, storno, smazání, přidání/zrušení platby nebo napojení
zálohy z menu detailu prostě zmizí — nejsou jen zašedlé, ale skryté, protože
konečné rozhodnutí stejně vynucuje server. Naopak zůstávají dostupné akce, které
doklad **nemění** (zobrazení, PDF, odeslání e-mailem, přidání přílohy) nebo
vytvářejí **nový** doklad (klonování, daňový doklad k platbě).

U přijatých faktur má klient navíc speciální pravidlo: přechod do stavu
**Zaplaceno** nebo zpět je povolený i bez zaúčtování (tenhle přechod sám o sobě
doklad nezamyká — přeznačení „zaplaceno" není účetní úkon), ale přechod do stavu
**Zaúčtováno** klient nikdy neudělá — to je vždy vyhrazené účetní/adminovi.

> [!WARNING]
> Zámek je čistě serverová záležitost — frontend badge je jen informativní.
> I kdyby se klient pokusil odeslat požadavek na úpravu zamčeného dokladu mimo
> běžné UI, API ho odmítne. Naopak jakmile účetní zaúčtování stornuje (zápis
> zruší), doklad se klientovi znovu odemkne.

Pro účetní a admina funguje zámek jinak — otevřené období smí upravovat vždy,
u uzavřeného období dostanou informativní chybu místo tichého zamítnutí a admin
si může úpravu vynutit (s automatickým záznamem do historie akcí). Detaily
vynucené editace řeší [§ 55 Bezpečnost — RBAC](97_Bezpecnost.md).

## 9.6 Náhled portálu pro účetní a admina

Portál není určený jen klientovi — v menu **Grafy → Přehled firmy** ho najde i admin
a účetní, u aktuálně zvolené firmy vidí přesně to samé, co by viděl klient.
Slouží to jako rychlá kontrola „co vidí klient" i jako samostatný přehled
hospodaření bez nutnosti procházet jednotlivé reporty. Náhled je čistě informativní
a nijak neomezuje, co účetní/admin může jinde v aplikaci dělat — nemá vlastní
zámek ani jiná omezení navíc.

## 9.7 Omezení a tipy

- Portál agreguje data **živě** při každém načtení stránky — nejde o statický
  export ani cache, čísla jsou vždy aktuální k okamžiku otevření.
- Přepínání firmy v horní liště (u víceoborového klienta) portál i všechny
  povolené stránky okamžitě přenačte na nově zvolenou firmu.
- Klient nemá přístup k **osobním přístupovým tokenům (API)**, ani kdyby uměl
  najít odkaz na stránku — endpoint mu API vrátí zamítnuto.
- Klient může mít u konkrétní firmy jinou klientskou roli; přepis musí být
  aktivní a stejného typu **client** jako jeho výchozí role.

> [!TIP]
> Než klientovi předáte přístup, zkontrolujte v **Grafy → Přehled firmy** na jeho
> firmě, jestli přehled dává smysl (zaúčtované doklady, aktuální DPH stav) —
> ušetří to zbytečné dotazy „proč mi tohle nejde upravit", protože klient uvidí
> přesně to, co vy v náhledu.

## 9.8 Vyžádané doklady od klienta

Nejčastější zdržení měsíční uzávěrky je čekání na doklady od klienta.
**Vyžádané doklady** drží požadavky i jejich stav přímo v aplikaci: účetní
založí požadavek, klient ho vidí v portálu a doklad rovnou nahraje.

Stejná podatelna podporuje oba směry práce: klient může doklad předat spontánně
(**push**) nebo odpovědět na konkrétní požadavek účetní (**pull**). V obou
případech se nejprve uloží neměnný originál do Dokumentů a vznikne samostatné
podání mimo účetnictví. Dokud ho účetní nezpracuje, nejde o přijatou fakturu,
nevstupuje do nákladů, cashflow, DPH ani kontrolního hlášení.

### 9.8.1 Spontánní předání klientem

Na stránce **Dokumenty → Předat doklady účetní**
(`/portal/purchase-invoice-submissions`) lze najednou vybrat až 20 souborů ve
formátu PDF, JPG, PNG, ISDOC, XML nebo ISDOCX. Ke skupině lze přidat poznámku pro
účetní a nepovinný tip na typ dokladu. Každý soubor vytvoří samostatné podání.

Přehled rozlišuje stavy **Předáno**, **Zpracovává se**, **Čeká na doplnění**,
**Zpracováno** a **Odmítnuto**. Originál lze vždy zobrazit nebo stáhnout. Pokud
účetní vyžádá čitelnější či správný soubor, klient u daného řádku použije
**Nahrát náhradu**; původní podání zůstane v auditní stopě. Opakovaný upload
bitově shodného souboru se podle SHA-256 nerozmnoží a shodný soubor nelze vydávat
za opravenou náhradu.

### 9.8.2 Účetní strana — Příchozí doklady

Stránka **Nákup → Příchozí doklady** (`/purchase-invoices/incoming`) je pracovní
fronta účetní. Nabízí filtr stavů, poznámku klienta, náhled PDF/obrázku a stažení
každého originálu. Účetní může:

- použít **Vytěžit a vytvořit** — ISDOC a ISDOCX se zpracují deterministicky;
  běžné PDF nebo fotografie mohou při přiděleném oprávnění pokračovat přes AI;
  po vytvoření se rovnou otevře editor výsledného konceptu ke kontrole,
- zvolit **Přepsat ručně** a vyplnit přijatou fakturu s originálem vedle formuláře,
- napsat klientovi důvod a vybrat **Vyžádat náhradu** nebo **Odmítnout**.

Po úspěšném automatickém nebo ručním zpracování se originál připojí k výsledné
přijaté faktuře a podání přejde na **Zpracováno**. Fakturu dál spravuje účetní;
klient ji může zobrazit, ale nemůže měnit její hlavičku, položky, přílohy ani stav.

### 9.8.3 Účetní strana — Dokumenty → Chybějící doklady

Stránka **Dokumenty → Chybějící doklady** (`/document-requests`) nabízí:

- **Nový požadavek** — formulář s popisem (co chybí), volitelnou částkou,
  kontextovým datem a termínem.
- **Vyžádat doklad jedním klikem z nespárované platby** — v detailu bankovního
  výpisu (**Banka → detail výpisu**) má každá nespárovaná transakce v řádkových
  akcích tlačítko **Vyžádat doklad**; popis se předvyplní z částky a data platby
  (např. „Chybí doklad k platbě 4 520 Kč z 12. 6.") a požadavek se rovnou naváže
  na danou transakci.
- **Filtr stavu** — Vyžádáno / Nahráno — čeká na kontrolu / Vyřízeno.
- **Proklik na doklad** — po zpracování podání vede sloupec **Doklad** na vzniklou
  přijatou fakturu; do té doby požadavek pouze ukazuje, že originál čeká na kontrolu.
- **Uzavřít** — potvrdí, že je požadavek vyřízený (i bez uploadu, např. když
  doklad dorazil jinou cestou) — přechod na stav Vyřízeno. **Znovu otevřít**
  vrátí omylem uzavřený požadavek zpátky na Vyžádáno.

### 9.8.4 Klientská strana — odpověď na požadavek

Klient vidí otevřené požadavky na stránce **Chybějící doklady** v portálu
(`/portal/document-requests`) — u každého popis, kontextovou částku/datum a
termín (po termínu červeně). Tlačítko **Nahrát doklad** přijímá stejné formáty
jako spontánní podatelna. Soubor se bezpečně uloží do příchozí fronty a požadavek
se přepne na **Nahráno — čeká na kontrolu**; faktura v této chvíli ještě nevzniká.
Vyřízené požadavky zůstávají na stránce v sekci **Vyřízené** jako historie.

> [!NOTE]
> Samotné předání nic nezaúčtuje. Účetní nejprve podání zpracuje v příchozí
> frontě a požadavek potom podle skutečného vyřízení uzavře. Automatická extrakce
> je jen jedna z možností kontroly (viz
> [§ 25 AI extrakce přijatých faktur](25_AI_extrakce.md)).

### 9.8.5 Notifikace na obou stranách

Dashboard účetní i domovská stránka portálu klienta zobrazí počet otevřených
požadavků jako barevnou dlaždici/pruh (červeně, pokud je aspoň jeden po
termínu) — proklik vede rovnou na příslušnou stránku. Pokud klient na požadavek
nereaguje, denní úloha (`cron-document-request-reminders.php`) po výchozích 3
dnech pošle e-mailovou urgenci (šablona **E-mail šablony → Chybí doklad**,
upravitelná stejně jako ostatní šablony) a opakuje ji nejdřív po 7 dnech
(cooldown) — obojí lze při spuštění úlohy přenastavit parametry `--days`
a `--cooldown`.

### 9.8.6 Oprávnění a izolace firem

Klient vidí vždy jen požadavky **vlastní aktuálně zvolené firmy** — stejný
fail-closed princip jako zbytek portálu (viz [§ 9.1](#91-kdo-roli-client-dostane-a-jak)).
Cizí požadavek (jiné firmy) vrátí 404, ne 403, aby se neprozrazovala ani jeho
existence.

Oprávnění **Předávat doklady účetní** patří jen klientským rolím. Oprávnění
**Příchozí doklady** je naopak interní a odděluje pouhé čtení fronty od jejího
zpracování. Přístup k příchozí frontě sám o sobě nenahrazuje oprávnění vytvořit
přijatou fakturu ani použít AI extrakci.

## 9.9 Vlastní doména portálu

Každá firma může vedle výchozí adresy instalace (`app.url`) používat vlastní
doménu, například `portal.klient.cz`. Nastavuje ji oprávněný správce na kartě
**Nastavení → Firma → Vlastní domény**. Pokud firma žádnou aktivní doménu nemá,
portál i veřejné odkazy dál fungují na výchozí adrese beze změny.

Funkci musí nejdřív zapnout správce serveru v `cfg.php`
(`'domains' => ['enabled' => true]`) — dokud je vypnutá, karta s doménami se
vůbec nenabízí a instalace se chová jako bez ní. Provozní důsledky zapnutí
popisuje kapitola *Nastavení → Vlastní domény klientského rozhraní*.

Doméně se přiřazuje účel:

- **Klientské rozhraní** — celý rozsah stránek, které dovoluje klientská role:
  přehled, vydané a přijaté faktury, pravidelná fakturace, kontakty, předávání
  dokladů a osobní profil;
- **Veřejné odkazy** — webové faktury, schvalování a výkazy práce;
- **Klientské rozhraní i veřejné odkazy** — oba předchozí účely.

Firma může mít více aktivních aliasů, ale pro každý účel nejvýše jednu primární
doménu. Nově odesílané odkazy používají primární doménu; starší canonical odkazy
na `app.url` zůstávají platné. Deaktivace poslední použitelné domény vrátí nové
odkazy automaticky na `app.url`.

Aktivní vlastní doména zároveň jednoznačně určuje firmu. Přepínač firem se na ní
nezobrazuje a firmu nelze podvrhnout hlavičkou, parametrem URL ani API tokenem.
Uživatel musí mít k určené firmě stále platné přiřazení. Jedinou výjimkou je
globální superadmin, který může firmu určenou aktivní vlastní doménou otevřít i bez
tohoto přiřazení. Výjimka obchází pouze kontrolu přiřazení: stav a účel domény,
vazba hostname na firmu, canonical přihlášení, PKCE a bezpečná návratová cesta se
ověřují stejně jako u ostatních uživatelů. Veřejný token jiné firmy na této doméně
vrátí pouze „nenalezeno"; stejný token na canonical adrese zůstává funkční.
Vlastní doména zpřístupní jen klientské agendy podle skutečných oprávnění uživatele.
Zakázky, platební příkazy, příchozí fronta účetní, API tokeny, systémová nastavení
a ostatní interní agendy na ní dostupné nejsou; jejich přímý odkaz se otevře na
canonical adrese `app.url`. Na canonical adrese se v novém panelu otevírá také
uživatelský manuál, který není součástí autentizované klientské plochy.

### 9.9.1 Přihlášení a passkeys

Přihlášení se bezpečně dokončuje na canonical adrese z `app.url`, kde jsou
zaregistrované passkeys a WebAuthn RP ID. Po heslu, TOTP nebo passkey se prohlížeč
vrátí na přesnou původně otevřenou klientskou stránku vlastní domény jednorázovým
krátkodobým kódem svázaným s PKCE.
Session token se v URL nepřenáší; cílová doména dostane novou host-only cookie,
kterou jiná doména nemůže číst.

Na stejné canonical adrese probíhá také **správa přístupových klíčů** a vynucené
první nastavení MFA. WebAuthn klíče jsou kryptograficky svázané s RP ID a originem
z `app.url`, takže vlastní doména tyto obrazovky neotevře s nefunkčním bezpečnostním
dialogem. Místo toho zahájí stejný jednorázový přechod, na canonical adrese nabídne
správu klíčů nebo nastavení MFA a po dokončení se vrátí na předchozí bezpečnou
klientskou stránku vlastní domény. Cílová firma i hostname jsou po celou dobu
svázané se serverovým požadavkem; nelze je změnit parametrem návratové URL.

Při zamčení session server na vlastní doméně odmítne přímé WebAuthn options i
verify. Zamykací obrazovka proto zahájí nové ověření na canonical originu a po
jednorázovém PKCE návratu vytvoří novou host-only session; návrat zachová aktuální
klientskou stránku. Vlastní doména je originem celého klientského rozhraní, nikoli
druhým originem interních agend účetní a správce. Povolení se neurčuje podle
prefixu `/portal`, ale podle stejného katalogu klientských oprávnění, který řídí
menu, router i API. Staré adresy `/exchange`, `/admin/export` a `/admin/import`
se nejprve převedou na svůj skutečný klientský cíl; samotný prefix `/admin` jim
tedy přístup ani nedává, ani nebere.

Neznámý, neověřený, deaktivovaný nebo účelově nekompatibilní hostname nikdy
nezobrazí data firmy. Postup ověření a aktivace popisuje
[§ 92.16 Vlastní domény](92_Nastaveni.md#9216-vlastni-domeny-klientskeho-rozhrani).
