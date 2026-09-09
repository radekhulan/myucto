# 29. Bankovní účty, přímé napojení a e-mailová avíza (IMAP)

**Cesta: `Peníze → Bankovní účty`**

Stránka sdružuje záložku **Bankovní výpisy** (import GPC a párování plateb —
viz [28. Banka](28_Banka.md)), dvě záložky účetní automatiky — **K zaúčtování**
a **Pravidla účtování** (viz [§ 29.8](#298-automaticke-zauctovani-bankovnich-transakci-jen-podvojne-ucetnictvi),
vidí je jen dodavatel s **podvojným účetnictvím**) — a tři administrátorské
záložky: **Měny a účty**, **Stavy na účtech** a **Bankovní avíza z e-mailu**.

Tato kapitola popisuje správu **bankovních účtů dodavatele** (pro PDF faktury,
QR platby a GPC výpisy) a **bankovních e-mailových avíz přes IMAP**. Bankovní avízo je
e-mail od banky s údaji o platbě — MyÚčto ho umí pravidelně načítat, vytěžit
z něj VS, částku, měnu, datum a vlastní účet a vytvořit z něj bankovní transakci
stejně jako z [výpisu](28_Banka.md).

## 29.1 Bankovní účty

Sekce **Měny + bankovní účty** je čistý seznam účtů dodavatele. Účet zde
nastavuješ stejně jako pro PDF faktury, QR platby a GPC výpisy:

- měna a označení účtu,
- české číslo účtu + kód banky,
- případně IBAN/BIC,
- výchozí účet pro danou měnu,
- aktivní/neaktivní stav.

Nastavení bankovních avíz je oddělené níže, aby se běžné bankovní údaje
nemíchaly s parsery a IMAP účty.

### 29.1.1 Stavy na účtech

Záložka **Stavy na účtech** zobrazuje každý bankovní účet samostatně podle
čísla účtu, kódu banky a měny. Aktuální stav vychází z posledního oficiálního
GPC nebo PDF výpisu, novějšího API výpisu s dostupným zůstatkem nebo
e-mailového avíza s disponibilním zůstatkem. U bankovního API se použije také
zůstatek vypočtený z předchozího bankovního výpisu a navazujících pohybů.
Datum odpovídá použitému výpisu a údaj je označen **z API**. Stejný zůstatek
se promítá do měsíčního vývoje i přepočtu do CZK. Neověřitelný výpočet
nenahrazuje poslední známý stav; při shodném datu má GPC nebo PDF přednost.

Pod tabulkou je pro každý účet samostatný graf měsíčních konečných zůstatků
v jeho vlastní měně. Graf **Celkový vývoj v CZK** zobrazuje jednotlivé účty
i řadu **Celkem**. Cizoměnové účty se pro tento graf přepočítávají kurzem ČNB
ke konci příslušného měsíce.

Pokud mají dva účty stejné číslo před lomítkem, rozlišují se kódem banky.
Starý výpis bez uloženého kódu banky se při více možných bankách do zůstatku
nezapočte, protože jej nelze bezpečně přiřadit.

### 29.1.2 Kontace účtů — analytika 221 (jen podvojné účetnictví)

Záložka **Kontace účtů** drží účetní pohled na tytéž účty. **Každý bankovní
účet má vlastní analytiku syntetického účtu 221** — 221.100, 221.200, 221.300 …
(tečkovaný zápis, viz [§ 81.3.1](81_Ucetni_osnova.md#8131-teckovany-zapis-analytik))
Číslo se přiděluje **automaticky** (první volné, které v
[účtovém rozvrhu](81_Ucetni_osnova.md) nekoliduje s už existujícím účtem) a
analytika se zároveň založí v rozvrhu pod 221.

Proč to tak je:

- **zůstatek analytiky sedí na výpis** konkrétního účtu — na ploché 221 leží
  několik reálných účtů najednou a zůstatek pak neodpovídá ničemu,
- **inventarizace k rozvahovému dni** (§ 29–30 zákona o účetnictví) se dá doložit
  výpisem daného účtu,
- **cizoměnové účty se přeceňují automaticky** — jednoměnová analytika už
  nemíchá měny, takže ji [uzávěrkové přecenění](87_Uzaverka.md) nabídne samo,
- **převod mezi vlastními účty** je v deníku vidět (obě nohy jsou různé účty).

V tabulce u každého účtu nastavíš:

| Pole | Význam |
|---|---|
| Název | Vlastní pojmenování účtu (použije se i jako název analytiky v rozvrhu) |
| Druh | Běžný účet / spořicí účet / termínovaný vklad |
| Analytika | Číslo za tečkou — vedle se hned ukáže výsledný účet (např. `221.200`) |
| Aktivní | Neaktivní účet se do kontací nenabízí, ale své číslo si drží |

Číslo můžeš **přepsat** — typicky když už některý účet v rozvrhu vedeš pod
konkrétní analytikou a chceš na ni bankovní účet **namapovat** (např.
termínovaný vklad na 221.100). Analytiku, kterou už používá jiný účet,
systém odmítne, a jednou přidělenou analytiku **nelze zrušit** — jde ji jen
změnit na jiné číslo. Analytika, na které už leží účetní zápisy, se sama
nikdy nepřidělí, aby nový účet nezdědil cizí zůstatek.

> [!NOTE]
> Zápisy zaúčtované dřív, než účet analytiku dostal, zůstávají na syntetice 221.
> Jejich přesun je **účetní reklasifikace k datu** — účtuje se ručně jako interní
> doklad (`221.xxx` / 221) v **otevřeném** období; do uzavřených a schválených let se
> nezasahuje a rozvahový řádek „Peněžní prostředky na účtech" se rozpadem uvnitř
> 221 stejně nemění.

### 29.1.3 Přímé napojení na banku

Pohyby ukládané jako `bank_api` se zobrazují v jednom měsíčním výpisu pro
danou firmu, číslo účtu, kód banky a měnu. Opakované načtení doplní stejný
měsíc; překrývající se pohyby se nezapočítají podruhé. Pohyby z odpovědi
přesahující více měsíců se rozdělí podle data zaúčtování.
Prázdné načtení nezakládá další výpis téhož měsíce. Původní odpovědi banky
zůstávají uložené jako podklady a původní odkazy na pohyby zůstávají platné.
Měsíční přehled se průběžně doplňuje a nelze jej samostatně smazat.
Úplný GPC nahraný pro tentýž účet a měsíc jej nahradí jako hlavní výpis
se zachováním odkazu, párování a zaúčtování. Nové pohyby se doplní.
Pokud GPC některé evidované pohyby neobsahuje, zůstanou v měsíčním přehledu;
neúplný dokument je uložený jako podklad.

Na záložce **Měny a účty** je pod seznamem účtů sekce **Přímé napojení na banku**.
Napojení je v testovacím režimu. Pokud používáš účet u některé z uvedených
bank, kontaktuj nás pro společné ověření připojení. U názvů bank se nezobrazuje
označení dostupnosti; seznam účtů obsahuje pouze účty podporované konektorem.
Každý měnový účet má vlastní nastavení a přístupové údaje. Načítání pohybů podporuje
Fio ČR (kód **2010**) i Fio SR (kód **8330**) přes stejné API. Slovenský účet
lze zadat i slovenským IBANem, se správným kódem banky a měnou EUR.

V dolním seznamu se zobrazují jen účty s dostupným konektorem. Přehled bank
a celý box zůstávají viditelné i bez podporovaných účtů.

**ČSOB (0300)** používá službu CEB Business Connector. V CEB aktivuj službu,
získej komunikační certifikát a povol mu požadovaná oprávnění ke smlouvě.
V MyÚčto vyplň číslo smlouvy, vyber certifikát `.p12` nebo `.pfx` a zadej
jeho heslo, pokud je chráněný. Účet musí mít zapnuté **Aktivní účet**.
Ověření kontroluje certifikát a přístup ke smlouvě, nikoli existenci pohybů.
Připojit lze i nový účet bez výpisů. Samotný přístup ke smlouvě nepotvrzuje
bankovní oprávnění ke konkrétnímu účtu; číslo účtu a měna se kontrolují
při importu každého výpisu. Prázdný seznam nevytváří žádný umělý výpis.
Datum při načítání určuje vytvoření souboru výpisu v bance. Pro import
musí být v CEB dostupný formát GPC. Předané příkazy je nutné zkontrolovat
a autorizovat v bankovnictví.

**Česká spořitelna (0800)** používá Premium Accounts API v3. Na
[Erste Developer Portal](https://developers.erstegroup.com) vytvoř aplikaci,
přidej Premium Accounts API a OAuth2 Authorization Code. V jejím nastavení
zaregistruj přesnou návratovou adresu z formuláře MyÚčta. Produkční přístup
musí být schválený a služba aktivovaná u banky. Zadej produkční WEB-API-key,
Client ID a Client Secret a pokračuj do banky k udělení souhlasu.
MyÚčto vybere pouze účet se shodným číslem a měnou, další účty připoj samostatně.
Přístupové údaje jsou šifrované, přístup se automaticky obnovuje refresh tokenem.
Po vypršení nebo odvolání souhlasu připojení zopakuj. Správce má vypnout
logování citlivých parametrů návratu z banky; samotné upozornění připojení neblokuje.

Pro testování nastav v `cfg.php` volbu `bank_connectors.csas.sandbox` na `true`:

```php
'bank_connectors' => [
    'csas' => ['sandbox' => true],
],
```

Výchozí hodnota je `false` (produkce). Přepínač společně mění adresy Accounts API,
OAuth přihlášení a obnovování tokenů; připravená adresa Payments API používá stejné
prostředí, jeho odesílání ale zatím není implementováno. Sandbox je v nastavení účtu
výrazně označený a vyžaduje sandboxový WEB-API-key, Client ID a Client Secret.
V registraci aplikace připoj Accounts API v3 a nastav scope `siblings.accounts`
jako required. Použij uvedenou callback adresu a samostatnou testovací firmu s číslem
účtu ze sandboxu. Sandbox vrací statická data, ne pohyby tvého skutečného účtu.
Po změně prostředí připoj účet znovu: uložený token z jiného prostředí se nikdy
neodešle do banky. Starší uložené přístupy bez označení prostředí se považují za produkční.

Konektor načítá zaúčtované pohyby (`BOOK`) jako `bank_api` do společné evidence
výpisů a automatického načítání. Informační položky (`INFO`) se neúčtují.
Prázdný účet lze připojit. Identita účtu se ověřuje podle IBANu, nikoli podle
proměnlivého systémového ID. Načítání má bezpečnostní limit 10 000 pohybů;
při jeho překročení zvol kratší interval. Zůstatek se z pohybů neodhaduje.
Přímé odesílání příkazů, notifikace a originální soubory výpisů tento konektor
zatím nepodporuje. Pro platby zůstává export KPC/PDF.

**Raiffeisenbank (5500)** používá Premium API. Nejdříve kontaktuj bankéře,
který ověří dostupnost pro daný účet a nastaví službu i oprávnění
**import hromadných plateb a stažení výpisů**. Služba může být zpoplatněna.
[Postup RB](https://www.rb.cz/podnikatele/ucty-a-platebni-styk/prime-bankovnictvi/premium-api)
a [návod k certifikátu](https://www.rb.cz/attachments/podnikatele/vytvoreni-certifikatu-premium-api-rbcz.pdf).
Pokud generování certifikátu v bankovnictví chybí, ověř aktivaci a oprávnění s bankéřem.
Samotná registrace aplikace nedává přístup k bankovnímu účtu. V portálu
[developers.rb.cz](https://developers.rb.cz/premium/documentation/01rbczpremiumapi)
zaregistruj aplikaci a získej Client ID. V nastavení internetového bankovnictví
vytvoř certifikát pro Premium API, povol přístup k účtu a stáhni soubor `.p12`.
V napojení zadej Client ID, certifikát a jeho heslo. Ověření kontroluje číslo
účtu včetně předčíslí a aktivní měnovou složku. Certifikát i heslo se ukládají
šifrovaně; soukromý klíč se při komunikaci předává pouze v paměti.

RB poskytuje pohyby nejvýše 90 dní zpětně. Aplikace načte všechny stránky
požadovaného období; původní odpověď lze stáhnout jako JSON. Tento přehled
pohybů neobsahuje konečný zůstatek výpisu. Certifikát je potřeba pravidelně
odblokovat v bankovnictví. Při zachování přístupu nech všechna pole prázdná;
při výměně zadej znovu všechny přístupové údaje.

**KB Business (KB+, 0100)** napojuje bankovní produkt **Extra služba API Business**.
KB+ je název bankovnictví, nikoli API služby. Nezaměňuj jej se starším
**KB Business API** pro MojeBanku / MojeBanku Business.

Pro podnikatelský či firemní účet KB požaduje placený bankovní tarif
**Standard Business, Komfort Business nebo Exclusive Business** a zvlášť
sjednanou Extra službu API Business. Samotné KB+ nebo Start Business nestačí.
KB ve svém FAQ uvádí také možnost pro nepodnikající osoby s aktivní službou
Premium; konkrétní dostupnost ověř u banky.

Bankovní tarif Business není totéž co varianta API Business **Basic / Plus / Pro**.
Pro pohyby i odesílání dávek potřebuješ **Plus nebo Pro**. Basic nezahrnuje
odesílání dávek. KB uvádí u Plus interval 61 minut a u Pro 10 minut; u čtení
se do limitů započítávají datové stránky. Cena a rozsah podléhají aktuálním
podmínkám banky. Přehled variant a detail ADAA nejsou v popisu Basic jednotné:
detail ADAA uvádí omezený počet čtení i pro Basic. Dostupnost samotného čtení
v této variantě proto ověř u KB; tento návod ji neslibuje.

Nejprve v KB+ vyber variantu API služby a uzavři smlouvu, potom pokračuj
v MyÚčtu. Již udělené souhlasy spravuješ přes **Nastavení → Nastavení služeb →
Přístupy k účtům → Přístupy třetích stran**. Chybějící volbu či oprávnění řeš
s KB na `kbplus@kb.cz`.
[Podmínky a varianty](https://www.kb.cz/cs/kbapi/extra-sluzba-api-business),
[FAQ a správa souhlasů](https://www.kb.cz/cs/kbapi/caste-dotazy-rozcestnik/caste-dotazy-extra-sluzba-api-business),
[limity ADAA](https://www.kb.cz/cs/kbapi/extra-sluzba-api-business/primy-pristup-k-uctu-v-kb).

Náš konektor používá **ADAA** pro pohyby a **BATCHDA** pro příkazy. Import
pohybů do evidence výpisů není stažení originálního souboru přes **STATDA**;
STATDA ani notifikace **NOTDA** zde nejsou implementované.
Registrace aplikace a následné udělení přístupu k účtu probíhá přes OAuth.
Technické klíče nenahrazují smlouvu o API službě ani souhlas majitele účtu.
Před prvním připojením správce připraví čtyři API klíče pro příslušné
služby: **Client Registration**, **OAuth**, **ADAA** (účty a pohyby)
a **BATCHDA** (platební dávky). Potřebuješ také kvalifikovaný certifikát
v souboru `.p12` nebo `.pfx` včetně soukromého klíče a jeho heslo, pokud
je chráněný. Samotné číslo účtu nebo jeden API klíč k připojení nestačí.

Správce musí předem nastavit veřejnou HTTPS adresu aplikace (`app.url`),
serverový šifrovací klíč a platný kontaktní e-mail (`smtp.from_email`,
nejvýše 43 znaků). Pro oba návratové endpointy KB musí zajistit,
že webový server, reverzní proxy ani aplikační logy neukládají parametry
URL obsahující registrační nebo autorizační údaje:

- `/api/settings/bank-connections/kb-plus/registration/callback`
- `/api/settings/bank-connections/kb-plus/oauth/callback`

Aplikace na riziko logování upozorňuje, ale připojení kvůli němu neblokuje.
Žádný potvrzovací příznak pro logování není vyžadován. Upozornění samo
nastavení logů nemění; doporučujeme citlivé parametry nelogovat nebo anonymizovat.

U účtu otevři napojení KB+, vyplň požadované údaje a zvol **Pokračovat do KB+**.
Na stránkách banky dokonči registraci a uděl souhlas s přístupem ke správnému
účtu. Bankovní návrat zpracuje server a vrátí tě do záložky **Měny a účty**;
žádné kódy ani návratové URL ručně nekopíruj. Připojení je dokončené až po
ověření účtu a měny bankou. Pokud už má dodavatel aplikaci zaregistrovanou,
znovu nezadáváš API klíče ani certifikát a pokračuješ rovnou udělením přístupu
k dalšímu účtu. Při přerušení použij **Obnovit stav připojení**. Nové ověření
zneplatní předchozí nedokončený odkaz; vypršelý postup je nutné zahájit znovu.

**Banka CREDITAS (2250)** vyžaduje bezpečnostní klíč (**Bearer token**)
pro konkrétní účet a jeho systémový identifikátor. Přístup pomocí ručně
vygenerovaného klíče lze použít bez klientského certifikátu. Certifikát
pro vzájemnou TLS autentizaci (**mTLS**) je volitelný, pokud jej banka
vyžaduje pro konkrétní typ přístupu.

V internetovém bankovnictví vytvoř klíč s potřebnými oprávněními. V MyÚčto
zadej jeho 64 písmen a číslic, **Account ID** z detailu účtu u aktivního API
klíče a zvol typ **Běžný účet** nebo **Spořicí účet**. Account ID je systémový
identifikátor banky, nikoli číslo účtu ani IBAN. Pokud tvůj přístup vyžaduje
certifikát, vyber `.p12` nebo `.pfx` včetně soukromého klíče a případně zadej heslo. Po volbě
**Ověřit a uložit připojení** aplikace kontroluje shodu účtu a měny. Pro
odesílání příkazů musí klíč navíc dovolovat zadávání plateb.

Uložené údaje zůstávají skryté. Pozastavení nebo opětovné zapnutí nevyžaduje
jejich nové zadání. Při volbě **Změnit přístupové údaje** vyplň znovu celou
sadu přístupových údajů. Certifikát přilož jen při použití mTLS; jeho
vynechání při změně údajů přepne CREDITAS na přístup bez certifikátu.
Certifikáty pro KB+ a CREDITAS lze vybrat do velikosti 24 KiB. Přístupové
údaje se ukládají na serveru šifrovaně, nikoli do úložiště prohlížeče.

**Fio: vytvoření tokenu a první načtení**

1. Založ účet se správným číslem, kódem banky a měnou.
2. Ve Fio internetovém bankovnictví vytvoř API token pro tento konkrétní účet.
   Pro načítání pohybů stačí právo číst. Pro odesílání příkazů musí token umožňovat
   i import plateb. Token má omezenou platnost podle nastavení v bance.
3. U účtu otevři **Napojení banky**, vlož token a zvol **Ověřit a uložit**.
   Aplikace při ukládání ověří, že bankovní účet odpovídá připojení.
4. Klikni na **Načíst pohyby**. Prázdné datum **Od data** automaticky navazuje
   na předchozí načítání; pole **Do data** je v tomto režimu vypnuté a nepoužívá se.
   Pro ruční načtení vyplň obě data, nejvýše 31 dní včetně krajních dnů.
   Starší historii může být potřeba dočasně odemknout
   u tokenu v internetovém bankovnictví.

Token se ukládá šifrovaně a nelze jej zpětně zobrazit. Správce instalace musí
mít nastavený samostatný šifrovací klíč serveru (`app.secret_encryption_key`
v konfiguraci nebo proměnná prostředí `MYINVOICE_SECRET_KEY`, 32 náhodných
bajtů v base64). Klíč bezpečně zálohuj, bez něj uložené tokeny nelze přečíst.
Při změně nastavení nech token
prázdný, pokud jej chceš zachovat; nový token původní nahradí. Po vypršení
platnosti vytvoř token v bance znovu a zde jej vyměň.

Výsledek načítání ukáže počet nových, spárovaných a přeskočených duplicitních
pohybů a odkaz na výpis. Pohyby vstupují do stejného párování a účtování jako
ručně nahrané výpisy, včetně převzetí vazeb z odpovídajících e-mailových avíz.
Překrývající se období můžeš načíst znovu.

Měsíční GPC při jednoznačné shodě použije již načtený pohyb z API. Zachová jeho
ID, párování faktur, mzdové vazby i účetní zápisy a připojí k němu další zdrojový
výpis. Pohyb je vidět také v detailu GPC, v účetní evidenci však existuje pouze
jednou. Původní API údaje se nepřepisují méně podrobným GPC. Stejná ochrana platí
i při následném načtení API po GPC. Dosud nespárované pohyby se znovu zkusí spárovat.

Shoda vyžaduje stejnou firmu, vlastní účet, banku, měnu, den a částku se znaménkem.
Kontrolují se dostupné symboly a protiúčet. Automatické spojení rozpozná společnou
bankovní referenci, shodný protiúčet s neprázdným variabilním symbolem nebo shodný
dostatečně podrobný popis platby. U popisu se ignorují mezery a interpunkce;
rozpozná se také zpráva doplněná názvem před oddělovačem. Každá platba musí mít
jediný protějšek. Tyto shody automaticky zpracuje i cron a uloží jejich vazbu
pro další načítání bez zásahu uživatele.
Samotná shoda částky a dne nestačí, protože může jít o další skutečnou platbu.
Při nedostatku dalších údajů načítání nabídne jednoznačné dvojice
ke kontrole. Porovnej datum, částku, popisy a dostupné platební údaje s původním
výpisem. Potvrď je pouze tehdy, když jde o stejné platby. Potvrzení připojí nový
výpis k existujícím pohybům a další překrývající se načítání už tyto vazby pozná.
Při zrušení se nový výpis neuloží. Automatické načítání samo slabé shody nepotvrzuje;
vyřeš je ručním načtením se stejným rozsahem nebo s prázdným datem Od.
Pokud nelze odlišit několik stejných plateb, import se zastaví bez možnosti hromadného
potvrzení a pohyby je potřeba jednotlivě prověřit. Již zaúčtované pohyby
se tím nemění. Historické duplicity vytvořené staršími importy se automaticky nemažou.

Selhání načítání aktivního účtu se zobrazí také v přehledu **Akce pro tebe**
uživateli s oprávněním spravovat bankovní účty. Upozornění rozliší nejednoznačné
shody od ostatních chyb a otevře nastavení konkrétního účtu. Po úspěšném
načtení samo zmizí.

Seznam i detail výpisu ukazují také připojené pohyby z jiného importu, včetně
stavu párování a zaúčtování. Sdílený pohyb je v účetních součtech stále jen jednou.

Vypnutím napojení pozastavíš jeho používání. **Odpojit** odstraní uložený token;
načtené pohyby a historie odeslaných příkazů zůstanou zachované. Správa napojení
a ruční načítání vyžadují právo zápisu k bankovním účtům.

### 29.1.4 Odeslání příkazu do banky

V **Nákup → Platební příkazy** vyber tuzemské faktury a účet plátce v CZK.
Akce **Připravit příkaz pro banku** uloží příkaz a nabídne jej v sekci
**Odeslání platebního příkazu do banky** pod přehledem faktur. Tento způsob
přípravy faktury neoznačí jako zaplacené ani při zaškrtnuté volbě pro ruční
označení úhrady. Vybrat lze i dříve uložený příkaz z historie.

Pro přímé odeslání musí existovat aktivní ověřené napojení stejného účtu plátce
v CZK s podporou příkazů: Fio ČR (2010), ČSOB (0300), Raiffeisenbank (5500),
Banka CREDITAS (2250) nebo KB+ (0100). Přímé odesílání slovenských EUR příkazů
zatím není implementované; přímé předání je určené pro tuzemské CZK příkazy.
Příkaz, který už při vytvoření označil faktury jako
zaplacené, se tímto způsobem znovu neposílá. Odeslání vyžaduje právo zápisu
k bankovním účtům i platebním příkazům.

Po **Odeslat do banky** zkontroluj potvrzení s účtem, počtem plateb a částkou.
Přijetí bankou znamená pouze předání příkazu k autorizaci. Příkaz musíš dále
zkontrolovat a potvrdit v internetovém bankovnictví. Skutečná úhrada se prokáže
bankovním pohybem, samotné odeslání ji nepotvrzuje.

Aplikace ukládá výsledek odeslání a referenci banky. Pokud banka vrátí počty
přijatých a odmítnutých položek, zobrazí se u výsledku také tyto počty.
Částečné přijetí příkazu vyžaduje kontrolu v bance. **Načíst uložený stav**
znovu načte tuto evidenci aplikace, ne stav autorizace nebo provedení v bance.
Přijatý, odmítnutý i nejasný výsledek blokuje další odeslání stejného příkazu.
Při výpadku spojení nebo nejasném výsledku nejdříve ověř příkaz v internetovém
bankovnictví, než vytvoříš jakoukoli další platbu.

Stav zahájeného importu znamená pouze převzetí dávky ke zpracování,
nikoli dokončenou kontrolu jednotlivých příkazů, autorizaci nebo provedení
platby. Výsledek importu i následnou autorizaci zkontroluj v bankovnictví.
Datum splatnosti se při přenosu automaticky neposouvá.

## 29.2 Mapování bankovních avíz

Sekce **Mapování bankovních avíz** určuje, jak se vytěžený e-mail napojí na
konkrétní bankovní účet dodavatele.

| Sloupec | Význam |
|---|---|
| Bankovní účet | Účet z měn dodavatele, proti kterému se porovnává cílový účet v e-mailu |
| IMAP účet | Konkrétní schránka, ze které se má avízo pro tento účet brát; „Žádný IMAP účet" = výchozí stav bez skenování, „Všechny IMAP účty" = neomezeno |
| Parser | Konkrétní parser provider; „Automatický výběr" = systém zkusí všechny aktivní providery |
| Tolerance | Povolená odchylka částky při párování faktury, např. `1.00` pro ±1 Kč |
| Aktivní | Vypnutý řádek se při scanování nepoužije |

Mapování se vyhodnocuje až po úspěšném vytěžení e-mailu. Pokud e-mail přijde
z jiného IMAP účtu nebo ho zpracoval jiný parser, než je v mapování nastaveno,
řádek se nepoužije.

Nové nebo nenastavené mapování začíná volbou **Žádný IMAP účet**. Takový řádek
se při scanování nepoužije, dokud nezvolíš konkrétní IMAP účet nebo vědomě
nepovolíš variantu **Všechny IMAP účty**.

## 29.3 Vaše nastavení pro načítání bankovních avíz a PDF z e-mailů

Každý dodavatel může mít více IMAP účtů, typicky jeden pro každou banku.

| Pole | Význam |
|---|---|
| Název | Popisek v UI, např. „RB avíza" |
| Host / port / šifrování | Připojení k IMAP serveru |
| Uživatel / heslo | Přístup ke schránce; heslo se ukládá šifrovaně |
| Složka | IMAP složka, např. `INBOX` nebo `INBOX.Banka` |
| Procházet | Ověří připojení a nabídne složky ze serveru |
| Max. zpráv na běh | Kolik nejnovějších e-mailů cron načte při jednom běhu |
| Zpracovat od data | Starší e-maily se ignorují i když spadnou do limitu |
| Vyžadovat ověření autenticity | Zpracují se jen e-maily, u kterých přijímací server potvrdil DKIM/DMARC; **zapnuto** |
| Důvěryhodné authserv-id | Povinné při zapnutém ověření autenticity; přesný identifikátor přijímacího serveru z jeho hlavičky `Authentication-Results` (např. `mx.mojedomena.cz`) |
| Přijímat přeposlaná (FW) avíza | Rozpozná banku i z těla e-mailu, když avíza chodí do schránky přeposlaná (odesílatel je tvoje adresa, ne banka) |
| E-mail přeposílatele | Volitelné omezení, od koho smí přeposlaná avíza chodit — adresa (`jan@firma.cz`) nebo doména (`firma.cz`); prázdné = libovolný |
| Načítat PDF faktury z příloh | Vedle avíz se z každé zprávy posoudí i PDF přílohy a doklady adresované tvé firmě se založí do Nákup → Příchozí doklady; **vypnuto** |
| Po úspěchu | Co udělat se zpracovanou zprávou |

### 29.3.1 Ověření autenticity e-mailu (DKIM/DMARC)

Odesílatel e-mailu se dá podvrhnout, takže samotná adresa v poli *Od* nic
negarantuje. **Vyžadovat ověření autenticity** je proto u nových účtů zapnuté:
systém sám podpisy nepřepočítává, ale věří verdiktu, který k doručené zprávě
připsal tvůj přijímací server do hlavičky `Authentication-Results`. Proto musíš
zároveň vyplnit jeho přesné **Důvěryhodné authserv-id**. Zpracuje se jen e-mail,
jehož první hlavička má právě toto authserv-id a obsahuje `dmarc=pass` s doménou
`header.from` zarovnanou na odesílatele, nebo `dkim=pass` se stejně zarovnanou
doménou `header.d`.

**Chybějící hlavička nebo authserv-id je odmítnutí, ne výjimka.** Když zprávě
hlavička chybí, první hlavičku nepřidal připnutý server nebo verdikt nesedí,
avízo se nezpracuje a v přehledu zpracovaných zpráv skončí ve stavu
`security_rejected` s uvedeným důvodem. Pokud tvůj poštovní server hlavičku
`Authentication-Results` vůbec nepřidává, kontrolu u daného účtu vypni. Je to
ale vědomé snížení ochrany, po kterém stačí k označení faktury za zaplacenou
jediný podvržený e-mail.

Hlavičku `Authentication-Results` si umí do těla zprávy vložit kdokoli. Důvěryhodná
je jen první, kterou přidal tvůj server. Server musí při přijetí zvenčí odstranit
podvržené hlavičky se svým authserv-id a vlastní výsledek vložit navrch. Hodnota
v poli **Důvěryhodné authserv-id** se porovnává celá, bez částečné shody, a systém
při neúspěchu nehledá jiný výsledek v nižších hlavičkách. U přeposlaných avíz
platí, že přeposláním původní podpis banky zaniká, takže se ověření vztahuje na
přeposílatele, ne na banku.

Pokud do schránky chodí avíza **přeposlaná** (např. z firemní schránky na sběrnou
adresu), zapni **Přijímat přeposlaná (FW) avíza**. U přímého avíza poznává banku
podle odesílatele, ale přeposláním se odesílatelem stáváš ty — proto se pak banka
hledá i z těla e-mailu. Volitelně omez **E-mail přeposílatele**, ať se zpracují
jen avíza od tvé adresy.

Polling zprávy standardně **neoznačuje jako přečtené**. Systém si úspěšně
zpracované e-maily pamatuje v databázi podle `Message-ID` / UID / fallback
hashe, takže funguje i s účtem, kde aplikace nemůže zprávy přesouvat nebo
označovat. Pokud má účet zápis povolený, můžeš zvolit doplňkovou akci po
úspěchu: neměnit zprávu, přidat flag, přesunout do jiné složky nebo označit
jako přečtené.

### 29.3.2 Načítání PDF faktur z příloh

Do schránky, kam chodí bankovní avíza, obvykle posílají faktury i dodavatelé.
Přepínač **Načítat PDF faktury z příloh** proto u daného IMAP účtu zapne druhou,
nezávislou větev zpracování: u každé nové zprávy se projdou PDF přílohy a ty,
které projdou rozpoznáním, se založí jako podání ve frontě
[Nákup → Příchozí doklady](23_Prijate_faktury.md).

Příloha projde, jen když splní **obě** podmínky:

1. **Je to doklad** — v textu PDF je označení dokladu: faktura, daňový doklad,
   zálohová faktura, dobropis, účtenka, vyúčtování, splátkový kalendář, invoice.
2. **Je adresovaný tobě** — v textu je tvoje **IČO** (shoda na všechny číslice;
   mezery uvnitř čísla nevadí), tvoje **DIČ**, nebo **název tvé firmy** shodný
   nejméně na 70 % (bez ohledu na diakritiku a právní formu).

Výjimka: PDF se strojově čitelným ISDOC uvnitř (PDF/A-3) se bere jako doklad bez
dalšího zkoumání — data v něm jsou průkazná sama o sobě.

Co se z přílohy **nestane**: nic se neúčtuje ani nezakládá jako přijatá faktura.
Vznikne jen podání ve frontě příchozích dokladů, které účetní zpracuje stejně jako
doklad z klientského portálu. Rozpoznání dat z dokladu (ISDOC nebo AI) se spouští
až tam, takže se za nepřečtenou přílohu neplatí žádné AI volání.

Výsledek posouzení každé přílohy — včetně zamítnuté — najdeš v tabulce
**PDF faktury z příloh** pod přehledem zpracovaných zpráv:

| Výsledek | Význam |
|---|---|
| Přidáno do příchozích dokladů | Vzniklo podání ve frontě |
| Duplicita | Stejný soubor už systém zná (podle otisku obsahu) |
| Není doklad pro nás | Chybí označení dokladu, nebo identita tvé firmy |
| Odmítnuto | Příloha není PDF, nebo je větší než 20 MiB |
| Chyba | Založení podání selhalo — důvod je ve sloupci Důvod |

Skenované PDF bez textové vrstvy rozpoznat nejde (aplikace nemá OCR) a skončí
jako *Není doklad pro nás* s vysvětlením. Takový doklad nahraj do fronty ručně.

Jednou posouzená příloha se už znovu neposuzuje, takže změna nastavení zpětně
nepřehodnotí staré zprávy — projeví se až na nově načtených e-mailech.

E-mail s fakturou samozřejmě není bankovní avízo, takže ho parser avíz odmítne.
Pokud z něj vzniklo podání, zpráva skončí ve stavu `attachment_imported` a
post-processing (přesun, příznak) s ní zachází jako s úspěšně zpracovanou — do
složky chyb se nepřesune.

Přílohy se posuzují **až po** ověření autenticity e-mailu (kapitola 29.3.1). Zpráva
zamítnutá jako `security_rejected` do fronty dokladů nedostane nic.

## 29.4 Parser provideri

Provider říká, jak poznat e-mail dané banky a jak z něj vytěžit platební údaje.

Typy providerů:

- **Systémový provider** — dodaný aplikací, např. Raiffeisenbank, UniCredit Bank, ČSOB, Česká spořitelna, Fio banka, Banka CREDITAS, MONETA Money Bank nebo Air Bank.
- **Regex provider** — vlastní provider dodavatele, konfigurovaný v UI.

Předpřipravený společný provider **Česká spořitelna** je zapnutý a má vyplněný
whitelist odesílatelů (`csas.cz`) — stejně jako banky s vlastním parserem, které si
odesílatele ověřují v kódu. Dřív byl dodáván s prázdným whitelistem, a protože
prázdný whitelist nepustí nic (viz *Odesílatel je povinný* níže), nedokázal
naparsovat vůbec žádné avízo; kdo na to narazil, musel si udělat vlastní kopii.
Posílá-li tvoje ČS avíza z jiné adresy, klikni na **Duplikovat**, v kopii adresu
uprav a přepni na ni mapování účtu.

Systémový provider se přímo needituje (je společný pro všechny). Když ho chceš
upravit, použij u něj tlačítko **Duplikovat** — vytvoří se editovatelná kopie,
ve které si dolaď vzory a otestuj ji přes **Test parseru**. V mapování účtu pak
přepneš účet z původního providera na svou kopii. Duplikovat lze i vlastní regex
provider.

Tlačítko **Vypnout pro firmu** u společného provideru platí **jen pro tvoji firmu**
a ostatních se nedotkne. Hodí se, když nechceš, aby se společný provider vůbec
pokoušel tvoje e-maily zpracovat; stejným tlačítkem ho zapneš zpátky. Vzory se
u společného provideru měnit nedají — pokus o to skončí hláškou s odkazem na
**Duplikovat**.

Systémový provider Raiffeisenbank rozlišuje směr převodu podle úvodního textu
o příchozí nebo odchozí platbě; u starší či odlišné šablony použije jako záložní
údaj znaménko částky. U odchozí úhrady je vlastním účtem pole **Z účtu** a
protiúčtem pole **Na účet**; u příchozí úhrady je to opačně. Díky tomu se odchozí
avízo mapuje na účet, ze kterého byla platba skutečně odepsána. U karetní
transakce se vlastní účet načte z pole **Účet** a obchodník z pole **Detaily**;
chybějící variabilní symbol ani bankovní protiúčet importu nebrání.

U Air Bank se avíza zapínají v internetovém bankovnictví pod **Účty a karty →
Možnosti → Info o dění na účtu** (odesílatel `info@airbank.cz`, předměty
„Zvýšení/Snížení zůstatku“). Nastavení v IB není úplně intuitivní — praktický
postup je např. v návodu FAPI
[Nastavení zasílání e-mailů o příchozích platbách z Air Bank](https://napoveda.fapi.cz/article/40-nastaveni-zasilani-e-mailu-o-prichozich-platbach-z-air-bank)
(místo FAPI adresy uveď mailbox napojený v MyÚčtu).

Detekce e-mailu i vytěžení polí pracují **tolerantně k diakritice**: pokud avízo
dorazí v jiném kódování nebo s rozbitou diakritikou (typicky u přeposlaných
zpráv), vzory `Směr platby` a `Smer platby` se vyhodnotí stejně. Když přesto
nějaký provider zlobí, můžeš si vzory napsat rovnou bez diakritiky.

U regex provideru nastavuješ:

| Pole | Význam |
|---|---|
| Název / kód | Interní identifikace provideru |
| Odesílatel | **Povinný** whitelist odesílatelů, např. `info@rb.cz` |
| Regex předmětu | Volitelný pattern pro subject, např. `Pohyb\s+na\s+účtě` |
| Regex těla | Volitelný pattern, který musí být v těle e-mailu |
| Vytěžená pole | Regexy pro VS, částku, měnu, datum, cílový účet atd. |

### 29.4.1 Odesílatel je povinný

Pole **Odesílatel** vyplň vždy. Regex provider s prázdným odesílatelem
**nezpracuje nic** — prázdná hodnota neznamená „přijmout od kohokoli". Vzory
předmětu a těla samy o sobě nechrání: text avíza si dokáže napsat kdokoli a čísla
účtu i variabilní symbol jsou vytištěné na každé vydané faktuře, takže bez
whitelistu by stačil jeden podvržený e-mail do sledované schránky k označení
faktury za zaplacenou.

Whitelist může obsahovat víc položek oddělených mezerou, čárkou nebo středníkem
a rozlišuje dva tvary:

- **adresa** (`info@rb.cz`) — musí sedět přesně; funguje i tvar `Název <info@rb.cz>`,
- **doména** (`rb.cz`) — projde libovolná adresa v této doméně i v jejích
  subdoménách (`noreply@mail.rb.cz`), ale ne `info@rb.cz.podvod.example`.

Doménový tvar použij u bank, které rozesílají avíza z několika adres. Odesílatel
je ale jen první filtr — skutečnou ochranu dělá **Vyžadovat ověření autenticity**
u IMAP účtu a povinné mapování cílového účtu avíza na tvůj bankovní účet.

Povinná vytěžená pole:

- `variable_symbol`
- `amount`
- `currency`
- `posted_at`
- `recipient_account`

Volitelná pole:

- `counterparty_account`
- `counterparty_name`
- `constant_symbol`
- `message`
- `bank_ref`
- `balance` (disponibilní zůstatek účtu z avíza — zobrazí se v detailu
  měsíčního avízo-výpisu a promítne se do přehledu **Stavy na účtech**)

Regex parser používá první zachycenou skupinu nebo pojmenovanou skupinu se
stejným názvem jako pole. Pro částku umí formáty typu `+1.234,56`, datum např.
`01. 06. 2026 10:15`.

## 29.5 Příklad regex provideru pro Raiffeisenbank

Následující příklad je **anonymizovaný**. Čísla účtů, variabilní symbol, název
protistrany i zpráva jsou fiktivní. Do manuálu nikdy nedávej reálné e-maily
z banky s osobními údaji, zůstatky nebo skutečnými čísly účtů.

Testovací text e-mailu může vypadat např. takto:

```text
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Firma Test s.r.o.
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo s.r.o.
Variabilní symbol
2606001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2606001
Disponibilní zůstatek po pohybu
+99.999,99 CZK
```

Základní nastavení provideru:

| Pole | Hodnota |
|---|---|
| Název | `Raiffeisenbank regex test` |
| Kód | `raiffeisenbank_regex` |
| Aktivní provider | Ano |
| Odesílatel | `info@rb.cz` |
| Regex předmětu | viz níže |
| Regex těla | `Variabilní\s+symbol` |
| Normalizer config | `{}` |

Regex předmětu:

```text
Pohyb\s+na\s+účtě|Pohyb\s+na\s+ucte
```

Regexy pro vytěžená pole:

| Pole | Regex |
|---|---|
| Datum platby | `Datum\s+a\s+čas\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2})` |
| Cílový účet | `Na\s+účet\s*([0-9-]+/[0-9]{4})` |
| Částka | `Částka\s+v\s+měně\s+účtu\s*([+\-]?[0-9 .]+,[0-9]{2})\s*[A-Z]{3}` |
| Měna | `Částka\s+v\s+měně\s+účtu\s*[+\-]?[0-9 .]+,[0-9]{2}\s*([A-Z]{3})` |
| Protiúčet | `Z\s+účtu\s*([0-9-]+/[0-9]{4})` |
| Název protistrany | `Z\s+účtu\s*[0-9-]+/[0-9]{4}\s*([^\n]+?)\s*Variabilní\s+symbol` |
| Variabilní symbol | `Variabilní\s+symbol\s*([0-9]+)` |
| Konstantní symbol | `Konstantní\s+symbol\s*([0-9]+)` |
| Zpráva | `Zpráva\s+pro\s+příjemce\s*(.*?)\s*Disponibilní\s+zůstatek` |
| Reference banky | prázdné |
| Disponibilní zůstatek | `Disponibilní\s+zůstatek(?:\s+po\s+pohybu)?\s*([+\-]?[0-9 .]+,[0-9]{2})` |

> 🛈 Do UI zadávej regex bez krajních oddělovačů (`/.../`). Parser je doplní
> sám.

## 29.6 Test parseru a zpracované e-maily

V sekci **Parser provideri** můžeš vložit testovací e-mail, odesílatele a
předmět. Test ukáže, který provider se použil a jaká pole se vytěžila.

Sekce **Zpracované e-maily** je debug přehled:

- zobrazuje `Message-ID` / fallback hash,
- IMAP účet,
- datum a čas zpracování,
- stav zpracování,
- použitý provider,
- vytěžené platební údaje,
- navázanou transakci nebo fakturu.

Hlavní stav se průběžně odvozuje z aktuálního párování transakce. Pokud byla
platba úspěšně spárovaná, případná chyba následného přesunu nebo označení
e-mailu v IMAP už nezobrazuje párování jako neúspěšné; původní post-processing
chyba zůstane viditelná jako upozornění.

Smazání záznamu zde nemaže transakci ani fakturu. Maže jen deduplikační záznam,
takže je možné stejný e-mail znovu zpracovat při dalším scanu. Používej to jen
jako emergency/debug akci.

### 29.6.1 Když k avízu dorazí GPC výpis

Zdroj pravdy je GPC. Když se importuje transakce, která už předtím přišla
avízem a je spárovaná, aplikace **párování převezme z avíza na GPC transakci**
— platba se přepojí, avízo zůstane rozpárované a nevznikne dvojí započtení.
Je to důležité i účetně: **avízo se nikdy neúčtuje**, takže dokud platba visí
na něm, nedostane se platební noha (u cizoměnové faktury včetně kurzového
rozdílu) do deníku vůbec.

Převzetí je záměrně opatrné — proběhne jen při **právě jednom** kandidátovi se
shodou účtu, měny, částky na haléř a data v okně ±5 dní. Identita se hledá
takto:

| Situace | Podmínka převzetí |
|---------|-------------------|
| GPC má variabilní symbol | VS musí číselně sedět s VS avíza |
| GPC nemá VS, ale avízo nese VS nebo protiúčet | musí sedět protiúčet |
| Ani jedna strana nemá VS ani protiúčet (karetní platba, „Blokace") | stačí výše uvedená shoda účtu, měny, částky a data |

Poslední řádek pokrývá karetní úhrady, které VS ani protiúčet nenesou. Pokud
by avízo bylo bez identity, ale GPC protistranu znal, jde nejspíš o běžný
převod a k převzetí nedojde. Dvě stejné blokace ve stejném okně jsou
nejednoznačné — aplikace nechá párování na tobě.

> 🛈 Karetní **blokace** se může od finálně zúčtované částky lišit. Pak se
> částky neshodují, převzetí neproběhne a platbu je potřeba přepárovat ručně.

## 29.7 Cron pro e-mailová avíza

Pro automatické zpracování nastav samostatný cron:

```bash
cmd/cron-bank-email-notices.sh   # každých 30 minut
```

Skript spustí `php api/bin/cron-bank-email-notices.php`, projde aktivní IMAP
účty dodavatele, načte nejnovější zprávy podle limitu a zapíše heartbeat do
plánovaných úloh.

## 29.8 Automatické zaúčtování bankovních transakcí (jen podvojné účetnictví)

Bankovní výpis ([kapitola 28](28_Banka.md)) i e-mailové avízo (§ 29.2–29.7) řeší jen **párování na
faktury**. Transakce, které s fakturou nesouvisí — bankovní poplatky, úroky,
odvody sociálního a zdravotního pojištění, splátky leasingu, převody mezi
vlastními účty — potřebují vlastní **účetní zápis** (MD/D dle [Předkontace](88_Ucetni_nastroje.md#883-predkontace)).
O to se stará **PostingService** a dvě záložky na téže stránce **Peníze →
Bankovní účty**, viditelné jen dodavateli s **podvojným účetnictvím**:

- **K zaúčtování** — fronta návrhů zápisů čekajících na schválení.
- **Pravidla účtování** — naučená pravidla, podle kterých se opakující se
  platby (odvody, poplatky, úroky) rozpoznají a zaúčtují samy.

### 29.8.1 Pravidla účtování

Tlačítkem **Nové pravidlo** (nebo přímo z platby přes hint „Podobná platba se
opakuje" — viz § 29.8.4) založíš pravidlo:

| Pole | Význam |
|---|---|
| Název | Popisek pravidla v seznamu |
| Směr | **Příchozí** / **Odchozí** — platba na účet dodavatele, nebo z něj |
| Protiúčet + kód banky | Číslo protiúčtu, na které/z kterého platba chodí |
| Variabilní symbol | Přesná shoda VS (číslice) |
| Fragment zprávy | Podřetězec v popisu/zprávě transakce (bez ohledu na velikost písmen a diakritiku) |
| Rozsah částky (od–do) | Interval absolutní částky, ve kterém se pravidlo použije |
| MD účet / D účet | Kontace zápisu — účty musí existovat v [účtovém rozvrhu](81_Ucetni_osnova.md) |
| Režim | **Návrh**, nebo **Automaticky** (§ 29.8.2) |

Pravidlo musí mít **aspoň jedno kritérium** (protiúčet, VS nebo fragment zprávy)
— samotný rozsah částky nestačí. Kontace má dvě záměrná omezení:

- **Bankovní strana musí být účet 221** (dle směru — příchozí = MD 221,
  odchozí = D 221). Pravidlo vždy účtuje proti bankovnímu účtu. Zadává se
  syntetika **221**; při zaúčtování ji MyÚčto samo nahradí **analytikou účtu,
  ze kterého je výpis** ([§ 29.1.2](#2912-kontace-uctu-analytika-221-jen-podvojne-ucetnictvi)),
  takže jedno pravidlo funguje pro všechny bankovní účty firmy.
- **Druhá strana nesmí být saldokontní účet** (311, 321, 314, 324, 325) —
  platby faktur se **párují** (§ 24.4), ne účtují pravidlem.

> [!TIP]
> Tlačítkem **Otestovat na historii** ověříš návrh pravidla proti transakcím
> za posledních 12 měsíců, ještě než ho uložíš — uvidíš, kolika transakcím
> odpovídá a kolik z nich je už zaúčtovaných. Pokud test najde nezaúčtované
> historické shody, nabídne se zaškrtávátko **„Navrhnout zaúčtování N
> historických plateb"**, které pro ně rovnou založí návrhy k schválení.

### 29.8.2 Režim Návrh vs. Automaticky

Nové pravidlo je vždy v režimu **Návrh** — každá shoda jen vytvoří položku v
záložce **K zaúčtování**, kterou musíš ručně **Schválit** nebo **Odmítnout**.
Po pěti potvrzeních za sebou beze změny, bez odmítnutí a s vyplněným rozsahem
částky se u pravidla objeví **Povýšit na automatiku**. Režim se nikdy nepřepne
sám — povýšení vždy potvrdí člověk. V automatickém režimu se zápis do deníku
vytvoří **rovnou při shodě**, bez čekání na schválení. Tlačítko **Historie**
ukazuje potvrzení, korekce, povýšení i případný návrat pravidla na návrhy.

Pokud transakci odpovídá **víc aktivních pravidel najednou**, systém nikdy
neúčtuje automaticky — vytvoří jen návrh podle pravidla s nejvyšší
úspěšností a označí ho jako konfliktní. Když se u transakce **nenajde žádné
pravidlo**, zkusí ještě rozpoznat vzor podle **historie** — pokud se stejný
protiúčet a směr v minulosti opakovaně účtovaly na stejnou dvojici účtů,
nabídne se návrh označený jako „naučeno", i bez existujícího pravidla.

Automatické zaúčtování se **nikdy neprovede do uzavřeného účetního období** —
místo zápisu vznikne jen návrh s poznámkou o uzavřeném období, který doplníš
ručně po otevření období.

### 29.8.3 Schvalování návrhů a záložka „K zaúčtování"

Záložka **K zaúčtování** má čtyři podzáložky — **K zaúčtování** (čekající),
**Zaúčtováno automaticky**, **Schválené** a **Odmítnuté**. U čekajícího návrhu
vidíš datum, částku, protistranu, navrhované pravidlo a **kontaci** (MD/D):

- **Schválit** — vytvoří zápis do deníku (viz [Předkontace](88_Ucetni_nastroje.md#883-predkontace)
  pro logiku sestavení zápisu) a návrh přejde do stavu Schváleno.
- **Odmítnout** — návrh se zahodí; ke stejné transakci a pravidlu se už
  znovu nenabídne. **Tři odmítnutí stejného pravidla po sobě** ho automaticky
  **deaktivují** (na to upozorní hláška) — pravidlo zjevně přestalo sedět.
- Ikonou ozubeného kola u řádku **přepíšeš MD/D účty** ještě před schválením,
  aniž bys musel(a) upravovat pravidlo.
- Upravená kontace se uloží jako učicí signál. Další naučený návrh u stejného
  protějšku ukáže, kdy a z jakých účtů byla kontace změněna.
- Víc řádků najednou vyřídíš přes **hromadné Schválit vybrané**.

Transakce v **cizí měně** se automaticky ani návrhem neúčtují (chybí
řešení kurzových rozdílů) — takové řádky nesou štítek **Cizí měna** a řeší se
ručně v [Předkontace](88_Ucetni_nastroje.md#883-predkontace).

U už **zaúčtovaných** položek (podzáložka **Zaúčtováno automaticky**) vidíš
odkaz na zápis v deníku a tlačítko **Stornovat** — vytvoří opravný (storno)
zápis a transakci vrátí mezi nezaúčtované, aniž by se mazala historie.
Pokud zápis vytvořilo automatické pravidlo, storno ho zároveň vrátí do režimu
**Návrh**. Nezvyšuje tím počet odmítnutí.

### 29.8.4 Založení pravidla přímo z platby

Když se v posledním roce objeví **podobná platba víckrát** (stejný protiúčet
nebo VS) a ještě pro ni neexistuje pravidlo, nabídne se u transakce ve výpisu
(§ 24) hláška **„Podobná platba se opakuje (N× za poslední rok)"** s
tlačítkem **Vytvořit pravidlo** — otevře formulář pravidla předvyplněný podle
dané transakce (protiúčet, VS, fragment zprávy, rozsah částky ±10 % okolo
částky) i s vybranou kontací, kterou stačí zkontrolovat a uložit.

> [!WARNING]
> Kontaci volí uživatel — systém nikdy nedosadí účty sám bez potvrzení.
> Pravidlo, které nesedí, radši nastav na **Návrh** a chvíli sleduj v záložce
> **K zaúčtování**, než ho přepneš na **Automaticky**.
