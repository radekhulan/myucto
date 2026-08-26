# 999. Řešení problémů (FAQ)

## 999.1 Přihlášení

### Zapomenuté heslo

Klik **Zapomenuté heslo?** na login → zadej e-mail → klik na odkaz v e-mailu
(platnost 1 h).

Pokud e-mail nedorazí:

- Zkontroluj spam.
- Ověř s adminem, že máš nakonfigurované SMTP (`cfg.php → smtp.*`).
- Krajní řešení: admin spustí `php api/bin/set-password.php tvuj@email.cz`.

### „Origin nesedí s app URL"

CSRF check selhal. Příčiny:

- **`cfg.php → app.url`** nesedí s URL, na kterou chodíš. Příklad: chodíš na
  `http://localhost:8080`, ale v cfg je `https://dev.example.com`. Oprav v cfg.
- Reverse proxy / IIS bez správně nastaveného Host headeru. Zkontroluj,
  že server vidí původní hostname.
- **Docker setup z jiného hostu než `localhost`** (např. LAN IP serveru
  `http://10.0.0.8:8080`). First-run setup je z libovolného hostu povolen
  a `app.url` se uloží automaticky podle URL, kterou v setup wizardu použiješ.
  Alternativa: spusť kontejner s `-e MYINVOICE_APP_URL=http://10.0.0.8:8080`,
  nebo si po `docker run` uprav `cfg.php` přímo v kontejneru.

### „Aplikace ještě není inicializována" (HTTP 423)

Setup wizard ještě neproběhl. Otevři `/setup` v prohlížeči.

Pokud setup wizard nefunguje (špatně nakonfigurovaná DB):

```bash
php api/bin/migrate.php --status     # zkontroluj, že DB má migrace
php api/bin/setup.php                # interaktivní fallback z CLI
```

### Lockout po brute-force

Po 10 neúspěšných pokusech / 15 min jsi zablokovaný na 15 min. Po 30 / hod
na 24 h. Počkej, nebo požádej admina o reset z DB:
`DELETE FROM login_attempts WHERE bucket_key LIKE '%tvuj_email%';`

### Passkey nefunguje nebo se nezobrazuje systémový dialog

Zkontroluj:

- aplikaci otevíráš přes přesný hostname z `cfg.php → app.url`,
- v produkci používáš důvěryhodné HTTPS; pro lokální vývoj je povolené pouze
  `http://localhost`,
- prohlížeč a zařízení podporují WebAuthn a mají nastavený zámek obrazovky,
- dialog spouštíš explicitním tlačítkem na viditelné stránce.

Když se po kliknutí nic neděje a stránka vypadá zatuhle, čeká se na systémový
dialog, který se nemusel zobrazit — otevřel se za oknem prohlížeče, na jiném
monitoru, nebo si volání převzal správce hesel (Keeper, 1Password, Bitwarden)
a jeho okno se nevykreslilo. Po několika sekundách se dole objeví panel
**Čekám na potvrzení bezpečnostního dialogu** s tlačítkem *Zrušit čekání*;
tím se akce ukončí hned a jde ji zopakovat nebo přepnout na TOTP. I bez zásahu
se čekání samo ukončí po ~2 minutách chybovou hláškou. Když panel hlásí, že
WebAuthn obsluhuje rozšíření, zkus ho pro tuto doménu vypnout.

Passkey registrovaná na starém hostname nebude po změně domény fungovat.
Přihlas se pomocí TOTP nebo jiné passkey dostupné pro původní origin a
zaregistruj nový klíč. Pokud žádná recovery cesta nezůstala, použij CLI rescue
níže.

Přihlášený správce uvidí neplatnou WebAuthn konfiguraci také jako provozní
upozornění na stránce **Administrace → Aktualizace**. Běžný login heslem a TOTP
zůstává dostupný, dokud se `app.url` neopraví.

### Odemčení PWA selže nebo je zařízení offline

Odemčení vyžaduje spojení se serverem pro vydání a ověření jednorázové
challenge. Zrušení dialogu, neplatná passkey nebo offline stav ponechá session
zamčenou. Zkus připojení a akci opakuj. Případně zvol **Přihlásit se znovu**;
aplikace nejprve bezpečně ukončí zamčenou session a pak provede celý login.

Rozpracovaný formulář zůstane zachovaný jen dokud stránka zůstává v paměti.
Pokud Android stránku ukončil, neuložená data nelze ze zámku obnovit.

### Ztratil jsem passkey nebo TOTP zařízení

Použij jinou passkey, TOTP nebo jeden z dříve uložených **záložních kódů**.
Každý záložní kód funguje jen jednou; po přihlášení zkontroluj zbývající počet
a s funkčním silným faktorem si v profilu případně vygeneruj novou sadu.

Pokud není dostupný žádný silný faktor ani záložní kód, správce spustí CLI
obnovu:

```bash
php api/bin/reset-mfa.php tvuj@email.cz
```

Reset vypne TOTP, odvolá passkeys, smaže důvěryhodná zařízení, čekající
ověřovací procesy i záložní kódy a invaliduje všechny session. Detail včetně
Docker příkazů je v [§ 97.2.4](97_Bezpecnost.md#9724-obnova-pristupu). Neupravuj
jen sloupce TOTP ručně v databázi: ponechal bys aktivní další faktory a session.

### Diagnostika `app.url`

V běžném provozu ověřuj canonical adresu přes přesný origin nastavený v
`app.url`. Například pro `app.url = https://faktury.example.cz`:

```bash
curl --fail --silent --show-error https://faktury.example.cz/api/v1/health
```

Pokud whitespace-only nebo jiná neprázdná neplatná hodnota zablokuje běžné
stránky host gate, lze stejný přesný endpoint dočasně zavolat přes jiný hostname,
který přijímá reverse proxy, případně ze serveru či kontejneru. Tato recovery
výjimka neplatí pro žádnou jinou aplikační cestu a neobchází zapnutý IP
allowlist. Během nedokončeného first-run setupu používej `GET`, protože setup
allowlist metodu `HEAD` nepovoluje.

Veřejná odpověď obsahuje pouze bezpečný verdikt. Původní `app.url`, hostname,
přihlašovací údaje, cesta, query ani fragment se do ní nikdy nekopírují:

```json
{
  "configuration": {
    "app_url": {
      "state": "invalid",
      "reason_code": "app_url_invalid_origin",
      "routing_compatible": false,
      "webauthn_compatible": false
    }
  }
}
```

| `state` | `reason_code` | Význam |
|---|---|---|
| `missing` | `app_url_missing` | Hodnota chybí, je přesně prázdná nebo obsahuje jen whitespace. |
| `invalid` | `app_url_invalid_origin` | Hodnota není samostatný HTTP(S) origin. Legacy resolver může uznat jen hostname, který z ní ještě bezpečně vyčte; nikdy ne libovolný request host. |
| `routing_only` | `app_url_webauthn_incompatible` | Běžné routování funguje, WebAuthn ne. |
| `hostname_conflict` | `app_url_hostname_conflict` | Hostname z `app.url` je současně uložený jako vlastní doména firmy; běžné cesty jsou bezpečně odmítnuté. |
| `webauthn_ready` | `app_url_valid` | Hodnota vyhovuje routování i WebAuthn. |

`app.url` nastav na přesný origin: schéma `http` nebo `https`, hostname a
volitelný port. Nesmí obsahovat userinfo (`jmeno:heslo@`), cestu, query ani
fragment. Běžné rozhraní dál podporuje HTTP a LAN IP adresy. Passkeys mají
užší pravidlo: vyžadují HTTPS a DNS hostname; jediná HTTP výjimka je
`http://localhost`.

Při `hostname_conflict` vrať `app.url` na předchozí canonical origin. Potom
kolidující záznam v **Nastavení → Firma → Vlastní domény** deaktivuj a smaž,
nebo pro `app.url` zvol jiný hostname. Dokud kolize trvá, aplikace na canonical
hostname zpřístupní pouze přesné `GET`/`HEAD` healthchecku; hostname ani údaje
firmy se v diagnostice nevracejí.

Při prvním setupu je chybějící, prázdná nebo whitespace-only hodnota v
preflightu v pořádku — wizard ji doplní z adresy, přes kterou je otevřený.
Stejně umí nahradit známý distribuční placeholder. Jinou explicitně neprázdnou
neplatnou hodnotu preflight označí jako problém a setup ji nepřepíše. Po
dokončení setupu je chybějící hodnota také problém a musí se opravit ručně v
`cfg.php`, `cfg.local.php` nebo přes `MYINVOICE_APP_URL`.

Chybějící nebo přesně prázdná hodnota zachovává dosavadní fallback, ve kterém
se validní request hostname považuje za canonical. Whitespace-only a jiná
neprázdná neplatná hodnota tento fallback nemá: host gate pro ně přes cizí
hostname povoluje výhradně přesné `GET`/`HEAD` healthchecku. POST, jiné API,
přihlášení ani ostatní aplikační cesty výjimku nedostanou. Po opravě monitoruj
health znovu přes hostname z `app.url`, ne přes recovery adresu.

Stav s `routing_compatible: false` se v serverovém logu hlásí jako
`configuration.app_url_unusable` pouze se stabilními poli `state` a
`reason_code`. Log záměrně neobsahuje nastavenou URL ani žádnou její odvozenou
část. Umístění logu určuje `logging.path`; provozní souhrn je také v
[§ 97.2.1](97_Bezpecnost.md#provozni-diagnostika-canonical-appurl).

### Varování `secret_encryption_key` (špatná délka klíče)

Backend vrací v `GET /api/health` pole `warnings[]` a admin vidí
upozornění i v UI (**Systém → Aktualizace**), pokud je problém s
`app.secret_encryption_key` (typicky omyl: 24B místo 32B).

Oprav konfiguraci v `cfg.php` / `cfg.docker.php`:

```bash
openssl rand -base64 32
```

Vygenerovanou hodnotu ulož do `app.secret_encryption_key`. Klíč musí být
base64, který po dekódování dává přesně 32 bajtů.

### Varování `mfa_methods_configuration`

V `auth.allowed_mfa_methods` (nebo `MYINVOICE_AUTH_MFA_METHODS`) je neznámá
hodnota. Podporované jsou pouze `passkey` a `totp`; e-mailové OTP sem nepatří —
zapíná se přes `auth.email_otp.enabled`. Aplikace kvůli tomu nespadne, jen
dočasně jede na výchozím seznamu `['passkey', 'totp']`. Oprav seznam v `cfg.php`.

### Varování `session_lock_without_unlock_method`

`session.lock_after_minutes` je kladné, ale někteří aktivní uživatelé nemají
passkey. Zamčenou session jde odemknout **jen passkey**, takže se z ní dostanou
pouze odhlášením (a přijdou o rozepsaný formulář). Buď jim registruj passkey
(**Profil → Přístupové klíče**), nebo nastav `session.lock_after_minutes = 0`
a nech volbu intervalu na jednotlivých uživatelích.

### Varování `session_lock_configuration`

`session.lock_after_minutes` není celé číslo 0–1440. Výchozí automatický zámek
je proto vypnutý; osobní intervaly uživatelů platí dál.

## 999.2 Faktury

### Nemůžu editovat vystavenou fakturu

Schválně. Vystavená faktura je **immutable** (snapshot dodavatele, klienta,
banky). Pokud potřebuješ změnu:

- **Drobná chyba (překlep, špatná částka)** → admin: detail faktury → klik
  **Editovat (force)**, vyžaduje admin roli, zaloguje se v activity logu.
- **Klient ji ještě nedostal** → udělej **Storno** (interní) + nová.
- **Klient ji už dostal** → udělej **Dobropis** (oficiální oprava) + nová.

### Klonování / „Vystavit znovu" inkrementuje měsíc špatně

Inkrement funguje pro popisy obsahující vzor `M/YYYY` (např. „Konzultace
3/2026" → „Konzultace 4/2026"). Pokud máš vzor jiný (např. „březen 2026"),
musíš ručně.

### QR platba se na PDF nezobrazuje

Bankovní účet musí projít **mod-11 kontrolou** (CZ účty) nebo **IBAN
checksum** (EUR). Zkontroluj v **Systém → Číselníky → Měny**, jestli máš
platný účet. Příklad platného CZ testovacího účtu: `1000000005 / 0100`.

### Faktura má v PDF špatné údaje dodavatele

Vystavená faktura má snapshot v `supplier_snapshot` (JSON). Pokud jsi po
vystavení změnil údaje dodavatele (logo, adresa, …), faktura zůstává
s původními. **Toto je zamýšlené** — vystavený doklad nelze měnit.

Pokud potřebuješ regenerovat PDF s novými údaji (např. opravil jsi překlep
v názvu firmy), použij **Editovat (force)** s admin rolí.

## 999.3 E-maily

### Faktura odešla, ale klient ji nedostal

1. Zkontroluj v **Systém → Activity log** záznam `invoice.sent` — měl by být
   s adresou klienta.
2. Zkontroluj log SMTP serveru (mailhog / SMTP relay).
3. Klient: zkontroluje spam.
4. Pošli **Test odeslání** na svůj e-mail — pokud nedorazí, problém je v SMTP
   konfiguraci.

### „Test odeslání" funguje, ale klientovi nic nechodí

- E-mail klienta v MyÚčtu je špatný (typo) → uprav v detailu klienta.
- Klient má restriktivní spam filtr → zkontroluj, jestli máš správně
  nastavený SPF + DKIM + DMARC pro doménu, ze které posíláš.

### DKIM podpis se nedaří aktivovat

1. Vygeneruj klíče: viz [§ 97.8](97_Bezpecnost.md#978-dkim-podpis-e-mailu).
2. Publikuj DNS TXT — počkej 5–60 minut na propagaci.
3. Ověř DKIM přes [mxtoolbox.com](https://mxtoolbox.com/dkim.aspx).
4. Až DNS funguje, zapni v `cfg.php → smtp.dkim.enabled => true`.

## 999.4 Banka

### GPC výpis se nenahraje („tento výpis už byl importovaný")

SHA-256 hash souboru se shoduje s nějakým dříve importovaným. Buď:

- Skutečně už je naimportovaný (zkontroluj **Peníze → Bankovní účty**, záložka Bankovní výpisy)
- Stáhl jsi stejný výpis 2× → použij jiný (nebo si vyžádej z banky export
  s jiným časovým rozsahem)

### PDF výpis se nenahraje nebo nesedí zůstatek

PDF import je deterministický a podporuje aktuální rozvržení výpisů **Banky
CREDITAS, ČSOB a KB**. Naskenovaný obrázek bez textové vrstvy, PDF jiné banky
nebo nové neznámé rozvržení se neodhaduje pomocí AI a import se odmítne.

- Ověř, že jde o originální PDF stažené z bankovnictví, ne tisk do PDF nebo scan.
- Zkontroluj, zda hlavička obsahuje číslo účtu, období, počáteční a konečný
  zůstatek.
- Pokud součet transakcí nesedí na zůstatky na haléř, systém výpis neuloží.
  Nestvrzuj chybějící pohyby ručně; stáhni úplný výpis za stejné období.
- U neznámé varianty rozložení přilož k hlášení anonymizovaný vzor bez citlivých
  údajů nebo přesný popis banky a rozložení. Originál s čísly účtů neposílej do veřejného issue.

### Auto-matching nefunguje

- Otevři **Všechny pohyby** nebo detail výpisu a rozbal důvody skórovaného
  návrhu. Bez VS může pomoci číslo faktury ve zprávě, zbývající částka, název,
  datum nebo dříve potvrzený účet protistrany.
- Automatická shoda vyžaduje nejméně 85 %, deterministický signál a náskok 15
  procentních bodů. Kandidát od 35 % se jen nabízí; slabší se nezobrazuje.
- Nový účet protistrany se stane důvěryhodným až po třech bezchybných ručních
  shodách. Chybné párování zruš — tím se účet znovu nepoužije naslepo.
- Částka neodpovídá (částečná platba, přeplatek, kurz nebo bankovní poplatek) →
  potvrď ruční/rozdělené párování a zkontroluj vzniklou alokaci.
- Faktura je v jiné měně než platba (klient pošle EUR na CZK fakturu) →
  manuálně, doúčtuj kurzový rozdíl

Překlep ve VS, přeplatek, poplatek, rozdílná měna a zálohová faktura se nikdy
nepotvrdí automaticky, i kdyby ostatní signály byly silné.

### Pohyb není v „K zaúčtování"

Záložka **K zaúčtování** je pracovní fronta, ne úplný archiv. Pohyb najdeš v
top-level záložce **Všechny pohyby**, která zahrnuje i zaúčtované a ignorované
transakce napříč výpisy. Pokud pro nezaúčtovaný pohyb nevznikl vůbec žádný
návrh, objeví se také v **Účetnictví → K doúčtování** s důvodem „bez pravidla“
nebo „nepodporovaná cizí měna“.

### Vlastní převod se nespároval nebo nezaúčtoval přes 261

- Oba účty musí být v nastavení banky evidované jako vlastní účty stejné firmy.
- Automaticky se zpracují jen převody ve stejné měně. Převod mezi CZK a EUR je
  kvůli kurzu a kurzovému rozdílu ruční.
- V nastavení automatiky musí být povoleny jak **Převody mezi vlastními účty**,
  tak **Rozpoznávání vlastních převodů**.
- Druhá noha může přijít v jiném výpisu nebo období. Do té doby je zůstatek 261
  legitimně „na cestě“; nevytvářej duplicitní ruční zápis.

### Odvod finančnímu úřadu nebo pojišťovně čeká na potvrzení

Rozpoznání účtu u ČNB/0710 samo nestačí. Automatické zaúčtování odvodu je
povolené jen proti existujícímu zaúčtovanému předpisu a nejvýše do jeho
kreditního zůstatku. Nejdříve zaúčtuj předpis daně, sociálního či zdravotního
pojištění. Nejasný VS, neznámé předčíslí nebo nedostatečný zůstatek ponechá
položku v Automatu k ruční kontrole.

### Bankovní účet z výpisu „nepatří aktuálnímu dodavateli"

Multi-supplier ochrana — výpis musí být z účtu, který je v **Systém →
Číselníky → Měny** aktuálního dodavatele. Pokud chceš nahrát výpis pro jiného
dodavatele, **přepni na něj** přes přepínač v horní liště.

## 999.5 Exporty

### ISDOC import do Pohody hodí chybu

ISDOC je univerzální standard, ale Pohoda má vlastní quirks. Doporučujeme
spíš **Pohoda XML export** (nativní formát), pro kterého je import
spolehlivější.

### Pohoda XML import vyžaduje kódy

Před exportem nastav v **Systém → Číselníky → Dodavatelé → [tvůj] → záložka Pohoda**:
číselnou řadu, středisko, činnost, předkontace. Bez toho Pohoda hlásí varování
při importu.

### Měsíční PDF ZIP je velký (>100 MB)

Normální při ~100 fakturách/měsíc s 2. stranou výkazu. Pokud chceš menší ZIP,
exportuj jen menší rozsah období (1 týden místo měsíce).

## 999.6 Cron / automatika

### Cron upomínek odeslal víc upomínek za den

Buď cron je spuštěný 2× (zkontroluj `crontab -l` / Task Scheduler), nebo
`--cooldown` je moc krátký. Default 14 dní by neměl pouštět více než 1 upomínku
na fakturu / 14 dní.

### Bank scan cron neimportuje nové výpisy

1. Zkontroluj, že soubory v `private/bank-incoming/` mají správný formát
   (ABO/GPC nebo podporované PDF; jiné XML ani scan PDF se neimportují).
2. Zkontroluj práva služby nad `private/bank-incoming/` a
   `private/bank-archive/`. Na Windows ověř identitu IIS/Task Scheduleru, na
   Linuxu vlastníka a oprávnění adresářů.
3. Spusť ručně `php api/bin/cron-bank-scan.php` a zkontroluj konkrétní chybu.

### „K doúčtování“ není prázdné, ale Automat ano

To je očekávané. **Automat** zobrazuje návrhy a blokace automatizačního motoru.
**K doúčtování** navíc inventarizuje bankovní pohyby bez jakéhokoli návrhu,
nezaúčtované vydané/přijaté doklady a otevřené žádosti o dokument. Otevři akci
na řádku; společná fronta je read-only a sama zápis nevytváří.

### V reportu Úplnost dokladů chybí nebo přebývá položka

Report vychází z aktuálních vazeb. Bankovní pohyb zmizí po doložení a párování
nebo po vzniku aktivního bankovního zápisu; stornovaný zápis se za aktivní
nepočítá. Zkontroluj nastavený práh dnů a směr příchozí/odchozí. Druhá část
reportu vychází ze saldokonta 311/321 a ukazuje jen doklady po splatnosti s
nenulovým zůstatkem, nikoli všechny faktury ve stavu „nezaplaceno“.

### Valutová pokladna nenabízí úhradu faktury nebo převod

Není to chyba oprávnění. Valutová pokladna podporuje PPD Prodej/Ostatní a VPD
Nákup/Ostatní s kurzem a CZK protihodnotou. Úhrada cizoměnové faktury přes
311/321 a valutový převod přes 261 nejsou podporované a systém je
záměrně blokuje. Proveď doložený ruční zápis v deníku. V daňové evidenci je
pokladna pouze korunová.

### AI kontace nic nenavrhla

Ověř zapnutí AI asistence pro daný typ, přihlašovací údaje poskytovatele,
potvrzenou DPA, rezidenční politiku a denní limit. Nepoužitelná odpověď levného
modelu může být jednou zopakována silnějším modelem; pokud ani ta neprojde,
položka zůstane ruční. AI nikdy nezaúčtuje položku sama. Pokus a jeho výsledek
jsou uložené v auditní stopě návrhu.

### Úplné mzdy zastavily výpočet v ruční kontrole

Úplné mzdy jsou zkušební agenda. Stav **Ruční kontrola** je bezpečnostní výsledek,
ne technická porucha: pro rozhodné datum může chybět účinný a odborně schválený
ruleset, úplný personální podklad nebo podporovaný scénář. V **Mzdy →
Legislativní pravidla** ověř období účinnosti a stav všech dotčených oblastí;
v detailu revize potom projdi konkrétní blokery. Chybějící rok se nesmí nahradit
nejbližší sadou pravidel. Výsledek neopravuj ručním přepsáním vypočtených částek
a nepoužívej jej jako jediný podklad pro výplatu nebo podání. Podrobný postup je
v kapitolách [Mzdové běhy](63_Mzdove_behy.md) a
[Legislativní pravidla mezd](75_Legislativni_pravidla_mezd.md).

### Odkaz do EPO po otevření zmizel

To je očekávané. Handoff URL je jednorázová a portál ji spotřebuje prvním
otevřením; MyÚčto ji proto podruhé nenabídne. Pokud jsi podání v otevřeném okně
nedokončil, v detailu snapshotu vytvoř **nový odkaz EPO**. Nový odkaz sám nic
neodesílá. Úspěšné otevření formuláře také není důkaz podání — rozhoduje až
potvrzení podatelny nahrané nebo převzaté do archivu.

## 999.7 Výkon

### Dashboard se otevírá pomalu

Stats cache možná chybí. Spusť `php api/bin/recompute-stats.php` — přepočítá
`project_revenue_cache` + `client_revenue_cache`.

### Aplikace pomalu reaguje pod zátěží

- Zapni Redis (`cfg.php → redis.enabled => true`) — rate limiting, brute-force
  ochrana a aplikační cache pak používají paměť místo DB
- Zkontroluj `cfg.php → app.debug => false` v produkci (debug logs jsou
  drahé)
- Sledování v `log/app-YYYY-MM-DD.log` (pomalé queries = `slow_query` v DB)

## 999.8 Multi-supplier

### Po přepnutí dodavatele vidím prázdný seznam klientů

Klienti jsou per-dodavatel izolovaní. Buď přepneš zpět na původního, nebo si
v aktuálním dodavateli vytvoř klienty znovu (nelze migrovat klienta mezi
dodavateli — záměrně).

### Faktura mi nešla vystavit, hlásí „klient nepatří aktuálnímu dodavateli"

Multi-supplier guard. Buď přepni na dodavatele klienta, nebo si v aktuálním
vytvoř toho samého klienta (oddělená data).

## 999.9 Diagnostika

**Systém → Diagnostika** je první místo, kam se podívat, když se aplikace chová
divně a není jasné proč. Nejde o výpis hodnot, ale o verdikt — **vyhovuje /
vyhovuje s výhradami / nevyhovuje** — a u každého nálezu je dopad, náprava
a odkaz do příslušné kapitoly manuálu.

Kontroluje se verze PHP a povinná rozšíření, klíčové hodnoty `php.ini`
(`memory_limit`, limity nahrávání, časové pásmo, OPcache), verze a nastavení
MariaDB, dostupnost Redisu, volné místo a práva zápisu, shoda časových pásem,
stav databázových migrací, poslední běhy plánovaných úloh, stav licence
a dostupnost novější verze aplikace.

Nálezy jsou seřazené od problémů k varováním, takže shora dolů odpovídají
pořadí, v jakém má smysl je řešit.

### Diagnostický balíček

Tlačítkem na téže stránce vznikne ZIP s podklady pro **placenou technickou
podporu**. Balíček se vytvoří u tebe v instalaci a zůstane u tebe — aplikace ho
nikam neodesílá. Stáhneš si ho a na portálu podpory ho k incidentu přiložíš
stejně jako kterýkoli jiný soubor.

Ve výchozím stavu obsahuje:

| Soubor | Obsah |
|---|---|
| `README.txt` | co balíček je a kdy vznikl |
| `manifest.json` | seznam položek s kontrolním součtem SHA-256 |
| `version.json` | verze aplikace a dostupnost novější |
| `environment.json` | kompletní audit prostředí a jeho vyhodnocení |
| `health.json` | dostupnost databáze a Redisu, provozní varování |
| `license.json` | stav licence (klíč je maskovaný) |
| `migrations.txt` | stav migrací včetně těch, které čekají |
| `cron.json` | poslední běhy plánovaných úloh |
| `config-sanitized.json` | výřez konfigurace pořízený allowlistem |

**Konfigurace prochází allowlistem**, ne filtrem na podezřelé názvy: ven jde jen
to, co je jmenovitě povolené. U hesel, klíčů a tokenů se přenáší pouze
informace, jestli jsou nastavené (`<set>` / `<empty>`), nikdy hodnota.

### Logy v balíčku

Logy aplikace v balíčku **ve výchozím stavu nejsou** a přidávají se zaškrtnutím.
Před vytvořením balíčku si jejich obsah můžeš přímo na stránce prohlédnout,
den po dni a po stránkách.

Z výřezu se odstraňují navázané parametry databázových dotazů, stack trace
a záznamy o komunikaci se SMTP serverem. **Zbytek se neupravuje** — logy proto
mohou obsahovat osobní údaje třetích osob, například e-mailové adresy příjemců
dokladů, jména a adresy klientů nebo hodnoty z chybových hlášek databáze.
Rozsah je ve výchozím stavu 7 dnů a úroveň `WARNING` a výš; obojí lze změnit.

Balíček se z instalace automaticky smaže po 24 hodinách. Jeho vytvoření se
zapisuje do auditní stopy včetně otisku SHA-256, takže je vždy dohledatelné,
co a kdy bylo předáno.

Velikost je omezená na 25 MB, což je limit přílohy na portálu podpory. Když ji
rozsah logů přesáhne, stránka to ohlásí ještě před vytvořením balíčku.

## 999.10 Hlášení chyb

Pokud problém nevyřeší tato kapitola, kontaktuj:

- **GitHub Issues** repo MyÚčto.cz
- E-mail vývojáře — viz `cfg.php → smtp.from`
- IT administrátor tvé organizace
- **Systém → Podpora** — rozcestník: co je zdarma, co se platí, a odkaz na portál
  podpory, na kterém se placená instalace přihlásí sama (licenční klíč nikam
  nezadáváš)

Užitečné pro hlášení:

- **Diagnostický balíček** ze Systém → Diagnostika (viz 999.9) — pokryje verzi,
  prostředí, stav migrací i plánovaných úloh naráz
- Browser / OS
- Krok-po-kroku, jak chybu reprodukovat
- Screenshot
