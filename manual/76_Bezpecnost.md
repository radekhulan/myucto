# 76. Bezpečnost (MFA, passkeys, zámek session, IP allowlist, role, activity log)

Bezpečnost MyÚčto stojí na několika navazujících vrstvách:

1. **Autentizace** — heslo (bcrypt + pepper) nebo volitelně passkey bez hesla,
   brute-force ochrana a CAPTCHA
2. **Silné MFA** — passkey nebo TOTP
3. **Síťová izolace** — IP allowlist (volitelný, doporučeno v produkci)
4. **Autorizace** — databázové role s oprávněními neviditelné / čtení / zápis
5. **Audit** — activity log všech mutací
6. **Zámek session** — serverové uzamčení PWA po nečinnosti

## 76.1 Hesla

| Vrstva | Detail |
|---|---|
| Algoritmus | bcrypt cost 12 |
| Pepper | Sůl z `cfg.php → app.pepper` (32B base64), neukládá se v DB |
| Min. délka | 12 znaků |
| Max. délka | Bez limitu — passphrase je doporučená (20+ znaků) |
| Kontrola síly | Indikátor v UI (slabé / střední / silné) |
| Reset hesla | Odkaz na 1 hodinu, e-mailem |

> 💡 **Passphrase je bezpečnější než krátké složité heslo.** „korelace medvědí
> dýně přístav 2026" má 49 znaků a je odolnější vůči brute-force než „Hu1@n!22".

## 76.2 Vícefaktorové ověření

MyÚčto podporuje dva silné faktory:

- **passkey (WebAuthn)** — kryptografický přístupový klíč chráněný zařízením,
- **TOTP** — šestimístný časový kód z autentikátoru.

E-mailové OTP je kompatibilní druhý krok pro účet bez silného faktoru, ale
nesplňuje povinnou silnou MFA politiku. Důvěryhodné zařízení se týká pouze
e-mailového OTP.

### 76.2.1 Passkeys

Passkey zaregistruješ v **Profil → Přístupové klíče**. Každý klíč má
vlastní název, datum vytvoření a posledního použití. Lze jej přejmenovat nebo
odvolat. Aplikace podporuje více klíčů; doporučené jsou dvě passkeys nebo jedna
passkey spolu s TOTP.

Passkey se používá:

- samostatně k přihlášení bez e-mailu a hesla, pokud tuto možnost správce povolí,
- po správném e-mailu a hesle místo TOTP,
- k odemčení zamčené browserové/PWA session,
- jako čerstvé potvrzení citlivé operace, například vytvoření API tokenu.

Systémový dialog může podle zařízení použít otisk, obličej, PIN, gesto, heslo
zařízení nebo externí bezpečnostní klíč. MyÚčto konkrétní metodu nezjišťuje,
biometrická data neopouštějí zařízení a server ukládá pouze veřejný klíč.
Poskytovatel platformy nebo password manager může passkey end-to-end šifrovaně
synchronizovat mezi zařízeními.

Passkeys vyžadují stabilní veřejnou URL. V produkci musí `app.url` obsahovat
přesný HTTPS origin, například `https://faktury.example.cz`. Klíč je svázaný
s hostname; po změně domény jej na nové doméně nelze použít. Pro lokální vývoj
je podporované `http://localhost`, nikoli běžný HTTP přístup přes LAN IP.

#### Provozní diagnostika canonical `app.url`

`app.url` je současně canonical origin pro běžné routování, odkazy a WebAuthn.
Pro pravidelný monitoring vždy volej health přes **přesný origin z `app.url`**,
ne přes náhodnou IP nebo alternativní `Host` hlavičku. Například pro
`app.url = https://faktury.example.cz`:

```bash
curl --fail --silent --show-error https://faktury.example.cz/api/v1/health
```

HTTP 200 pouze potvrzuje, že endpoint odpověděl. Monitoring má v JSON zvlášť
kontrolovat `db`, podle nasazení `redis` a `configuration.app_url`. Poslední
objekt je veřejný a neobsahuje nastavenou URL ani hostname, userinfo, heslo,
cestu, query nebo fragment:

| `state` / `reason_code` | Routování a náprava |
|---|---|
| `missing` / `app_url_missing` | Klíč chybí, je přesně prázdný nebo obsahuje jen whitespace. Chybějící a přesně prázdná hodnota zachovává legacy fallback na validní request hostname; whitespace tento fallback nemá. Po setupu nastav explicitní HTTP(S) origin. |
| `invalid` / `app_url_invalid_origin` | Neprázdná hodnota není samostatný HTTP(S) origin. Pokud z ní legacy resolver ještě získá platný hostname, uzná nejvýše request s přesně stejným hostname, nikdy libovolný host; nejde však o podporovaný canonical origin a musí se opravit. Odstraň userinfo, cestu, query či fragment nebo oprav schéma, hostname a port. |
| `routing_only` / `app_url_webauthn_incompatible` | Běžné rozhraní funguje, včetně záměrného HTTP nebo LAN-IP nasazení, ale passkeys nejsou dostupné. Pro WebAuthn použij HTTPS DNS hostname. |
| `hostname_conflict` / `app_url_hostname_conflict` | Hostname z `app.url` je současně uložený jako vlastní doména firmy. Běžné cesty aplikace jsou fail-closed; přesný read-only health zůstane dostupný. Obnov původní canonical adresu, vlastní doménu deaktivuj a smaž, nebo nastav jiný canonical hostname. |
| `webauthn_ready` / `app_url_valid` | Origin vyhovuje routování i WebAuthn. Vedle HTTPS DNS originu je povolená jediná HTTP výjimka: `http://localhost`. |

Při whitespace-only nebo jiné neprázdné hodnotě nepoužitelné pro routování
propustí tenant host gate přes jiný hostname jen přesné `GET` a `HEAD`
`/api/v1/health` (interně `/api/health`). POST, jiný endpoint, přihlášení ani
ostatní aplikační cesty výjimku nedostanou. Toto je pouze recovery cesta; po
opravě se health znovu monitoruje přes nakonfigurovaný canonical hostname. Pokud
cizí hostname odmítne už reverse proxy, spusť recovery dotaz ze serveru nebo
kontejneru přes hostname, který proxy přijímá. Health neobchází zapnutý IP
allowlist. Během nedokončeného first-run setupu používej `GET`; setup allowlist
metodu `HEAD` nepovoluje.

Stejná přesná health výjimka platí při kolizi canonical hostname s uloženou
vlastní doménou. Na rozdíl od syntakticky neplatného `app.url` ji volej přes
hostname z `app.url`; všechny ostatní cesty na něm zůstanou odmítnuté.

First-run setup doplní z otevřeného originu chybějící, prázdnou či
whitespace-only hodnotu a známé distribuční placeholdery. Jinou explicitně
neprázdnou neplatnou hodnotu nepřepisuje: preflight ji označí jako chybu, aby ji
správce opravil v `cfg.php`, `cfg.local.php` nebo přes
`MYINVOICE_APP_URL` vědomě.

Runtime zapíše pro stavy s `routing_compatible: false` serverový warning
`configuration.app_url_unusable`. Kontext obsahuje jen stabilní `state` a
`reason_code`; původní ani odvozená hodnota konfigurace se neloguje. Umístění
logu určuje `logging.path`. Podrobný recovery postup je v
[§ 999 Řešení problémů](999_Reseni_problemu.md#diagnostika-appurl).

Vlastní domény klientských portálů se nestávají dalším WebAuthn RP ID. Browser
se z nich přesměruje na přesný canonical origin z `app.url`, kde proběhne
passwordless passkey, passkey jako druhý faktor nebo TOTP. Aplikace potom vydá
jednorázový kód platný 60 sekund, svázaný s PKCE verifierem, uživatelem, firmou
a přesným cílovým hostnamem. Kód lze spotřebovat jen jednou a skutečný session
token se v URL nikdy neobjeví. Na cílové doméně vznikne samostatná host-only
session; správa passkeys a ostatní interní obrazovky zůstávají na canonical
originu. Přímé WebAuthn operace na vlastní doméně server odmítne, včetně správy
klíčů a options/verify pro odemčení session. Zamykací obrazovka místo nich zahájí
nové ověření na canonical originu a po jednorázovém PKCE návratu vytvoří pro
vlastní doménu novou host-only session.

Přidání a odvolání passkey vyžaduje nové ověření passkey nebo TOTP. U účtu bez
dosavadního silného faktoru první registrace vyžádá aktuální heslo. Při povinném
MFA nelze odvolat poslední povolený silný faktor.

Pokud správce přechází z TOTP na passkeys a vyřadí TOTP ze seznamu povolených
metod, uživatel smí existujícím TOTP potvrdit pouze registraci své první
passkey. Přechod je dostupný jen tehdy, když jsou passkeys povolené a účet ještě
nemá žádnou aktivní passkey. Stejné omezení platí pro registraci heslem u účtu
bez dosavadního silného faktoru. Server pod databázovým zámkem znovu ověří, že
jde skutečně o první klíč, takže nelze předem otevřít více registrací a dokončit
je až po přidání prvního klíče. Další klíče už vyžadují aktuálně povolený faktor.

TOTP = time-based one-time password (RFC 6238).

### 76.2.2 Aktivace TOTP

**Profil → 2FA / TOTP → Aktivovat**.

![Aktivace 2FA](img/16_2fa_setup.webp)

1. Aplikace ukáže **QR kód** + textový **secret key**.
2. V mobilu otevři **autentikátor** (Google Authenticator, Authy, Microsoft
   Authenticator, 1Password, Bitwarden) → Přidat účet → Sken QR kódu.
3. Aplikace začne generovat 6-cifrené kódy každých 30 sekund.
4. Zadej aktuální kód do MyÚčto → **Potvrdit aktivaci**.

> 💡 Při ztrátě autentikátoru použij jinou passkey nebo **záložní kód**
> (viz [§ 76.2.4](#7624-obnova-pristupu)). Až když nemáš nic z toho, zbývá CLI
> rescue `php api/bin/reset-mfa.php <email>`.

### 76.2.3 Přihlášení s passkey a MFA

Po zadání e-mailu a hesla nabídne aplikace passkey, pokud ji účet má. Je-li
aktivní také TOTP, lze explicitně přepnout na šestimístný kód z autentikátoru.

![2FA výzva](img/04_2fa.webp)

Správce může navíc explicitně povolit přihlášení pouze pomocí passkey:

```php
'auth' => [
    'passwordless_login' => [
        'enabled' => true,
    ],
],
```

Totéž lze nastavit přes ENV:

```bash
MYINVOICE_AUTH_PASSWORDLESS_LOGIN=true
```

Výchozí hodnota je `false`, takže aktualizace nezmění dosavadní přihlašování.
Funkce je dostupná jen tehdy, když `auth.allowed_mfa_methods` obsahuje
`passkey` a WebAuthn konfigurace je platná. Přihlašovací stránka potom nabídne
**Přihlásit přístupovým klíčem**. Browser zobrazí passkeys pro aktuální doménu
a vybraný klíč bezpečně předá identitu účtu; e-mail ani heslo se neposílají.
Ověření uživatele na zařízení je povinné a úspěšná passkey rovnou vytvoří
silně ověřenou session, bez dalšího TOTP.

Passwordless režim neodstraňuje heslo ani standardní formulář. Ten zůstává
fallbackem pro jiné zařízení a cestou k TOTP. Pokud passkey není dostupná,
zruš systémový dialog a přihlas se e-mailem a heslem.

Účet s passkey nedostane automatický fallback na e-mailový kód. Pokud passkey
na aktuálním zařízení není dostupná, použij jinou passkey, TOTP nebo rescue.

### 76.2.4 Obnova přístupu

Kde passkey fyzicky leží, rozhoduje o tom, co se stane při ztrátě zařízení:

- **V zařízení** (Windows Hello, Touch ID, bezpečnostní klíč) — klíč je vázaný
  na hardware. S koncem zařízení končí i on.
- **Ve správci hesel nebo v cloudu účtu** (Keeper, 1Password, iCloud Keychain,
  Google Password Manager) — klíč se synchronizuje, takže přežije výměnu
  počítače a přihlásíš se jím i jinde.

Kam se klíč uloží, vybírá prohlížeč při registraci; aplikace to neřídí a ani to
nezjistí zpětně. Máš-li jediný klíč a ten je vázaný na zařízení, drž si jako
zálohu buď druhý klíč, nebo aktivní TOTP.

#### Záložní jednorázové kódy

**Profil → Přístupové klíče → Záložní kódy.** Sada deseti kódů ve tvaru
`ABCDE-FGHJK`; každý funguje **právě jednou**. Zadávají se na přihlašovací
stránce místo passkey i TOTP (odkaz „Nemám klíč ani autentikátor") a potvrdí se
jimi i odebrání ztraceného klíče.

Server ukládá jen SHA-256 kódu, takže **sadu jde zobrazit jedinkrát** — při
vygenerování. Ulož ji mimo počítač, ze kterého se přihlašuješ: tisk, trezor,
správce hesel. Vygenerování nové sady okamžitě ruší tu předchozí.

Co kód schválně **ne**umí, aby zůstal záchranou a nestal se trvalým faktorem:

- nevydá další sadu záložních kódů (nejdřív obnov reálný faktor),
- nepotvrdí vytvoření API tokenu ani práci s podpisovým certifikátem pro EPO,
- nepočítá se do `allowed_mfa_methods`; naopak jím projdeš i v konfiguraci, která
  by tě jinak zamkla ven (`allowed_mfa_methods = ['passkey']` + ztracený klíč).

Použití kódu se zapisuje do activity logu (`auth.recovery_code_login`,
`auth.recovery_code_used`) i s IP a počtem zbývajících kódů.

#### Rescue na serveru

Nejprve použij jinou zaregistrovanou passkey, TOTP nebo záložní kód. Pokud není
dostupné nic z toho, správce může na serveru spustit:

```bash
php api/bin/reset-mfa.php tvuj@email.cz
```

Skript vypne TOTP, odvolá všechny passkeys, zruší důvěryhodná zařízení,
čekající OTP, WebAuthn flow, step-up proofy i **záložní kódy** a invaliduje
všechny session uživatele. Stejný skript lze spustit také přes alias
`reset-2fa.php`.

#### Docker

V kontejneru je aplikace v `/var/www/html` a běží pod `www-data`. Spouštěj skript
pod tímto uživatelem — jako `root` sice projde taky, ale případné soubory, které
by po sobě zanechal, by pak měly špatného vlastníka:

```bash
# docker compose (název služby `app` dle docker-compose.yml)
docker compose exec -u www-data app php api/bin/reset-mfa.php tvuj@email.cz

# samostatný kontejner
docker exec -u www-data -w /var/www/html <container> php api/bin/reset-mfa.php tvuj@email.cz
```

Ověření, že reset opravdu proběhl (řádek `auth.mfa_reset` nese i jméno účtu, pod
kterým se skript spustil):

```bash
docker compose exec -u www-data app \
  php -r 'require "api/vendor/autoload.php";
    $c = MyInvoice\Bootstrap::buildApp()->getContainer();
    $pdo = $c->get(MyInvoice\Infrastructure\Database\Connection::class)->pdo();
    foreach ($pdo->query("SELECT created_at, payload FROM activity_log
                           WHERE action = \"auth.mfa_reset\"
                           ORDER BY id DESC LIMIT 5") as $r) {
        echo $r["created_at"], "  ", $r["payload"], PHP_EOL;
    }'
```

> ⚠️ Rescue používej jen z důvěryhodného shellu serveru. Přímý SQL zásah není
> ekvivalentní: snadno ponechá aktivní session nebo rozpracované ověřovací flow.
> Reset je zapsaný do auditní stopy a zapečetěný v hash-chainu (§ 33a) — kdo ho
> spustil a odkud, tedy zpětně dohledáš.

### 76.2.5 Vynucení silného MFA

Pokud chceš, aby **každý** uživatel měl passkey nebo TOTP,
nastav v `cfg.php` (nebo `cfg.local.php`):

```php
'auth' => [
    'require_mfa' => true,
    'allowed_mfa_methods' => ['passkey', 'totp'],
],
```

Stejné lze přepnout přes ENV (Docker / PaaS):

```bash
MYINVOICE_AUTH_REQUIRE_MFA=true
MYINVOICE_AUTH_MFA_METHODS=passkey,totp
```

Úvodní [wizard](07_Setup_wizard.md) nabízí jen přepínač „vyžadovat silné MFA";
seznam metod nechává na konfiguraci, takže po instalaci jsou povolené obě. Jeho
zúžení je vědomý zásah do `cfg.php` / ENV.

Chování:

- Uživatel bez povoleného silného faktoru dostane omezenou setup session a
  stránku `/setup-mfa`, kde zaregistruje passkey nebo zapne TOTP.
- Setup session smí pouze dokončit povolené MFA nastavení nebo se odhlásit.
  Business API zůstává serverově blokované.
- Po dokončení se setup session zneplatní a vydá se nové session ID i CSRF.

Starší `auth.require_totp = true` a `MYINVOICE_AUTH_REQUIRE_TOTP=true` zůstávají
podporované jako TOTP-only politika. Pro nové instalace používej obecné MFA
nastavení.

`allowed_mfa_methods` rozhoduje **co povinné MFA splní**, ne na co se přihlášení
zeptá. Zúžení seznamu (typicky na `['passkey']` při přechodu na passkey-only)
proto nikdy nezruší faktor, který uživatel reálně má:

- Kdo má zapnuté TOTP, zadává ho i dál. Když `totp` v seznamu není, výsledná
  session je jen `basic` — při `require_mfa = true` skončí uživatel na
  `/setup-mfa` a zaregistruje povolenou metodu.
- Kdo má passkey a WebAuthn je konfiguračně nedostupný (rozbité `app.url`),
  se přihlásí přes TOTP nebo e-mailové OTP, pokud je má. Bez jakéhokoliv jiného
  druhého faktoru vrací přihlášení `503 passkeys_unavailable` — nikdy nepropadne
  na samotné heslo. Řešením je opravit `app.url`, jinak `reset-mfa.php`.
- Totéž platí pro step-up při vydání API tokenu: zaregistrované TOTP se vyžaduje
  bez ohledu na `allowed_mfa_methods`.

Neznámá hodnota v seznamu (například `email_otp`, které sem nepatří) start
aplikace neshodí: použije se výchozí `['passkey', 'totp']` a přihlášený správce
uvidí na health endpointu warning `mfa_methods_configuration`.

> ⚠️ Povolení TOTP vyžaduje validní `app.secret_encryption_key` (32B base64).
> Health endpoint na chybnou konfiguraci upozorní; viz
> [§ 999 Řešení problémů](999_Reseni_problemu.md).

### 76.2.6 E-mailové ověření pro účet bez silného faktoru

Pro uživatele, kteří nechtějí (nebo neumí) authenticator aplikaci — typicky
externí účetní — lze zapnout **e-mailové OTP** jako druhý faktor. Kdo nemá
aktivní passkey ani TOTP, dostane po zadání hesla 6místný kód na e-mail a musí
ho opsat.

Zapnutí v `cfg.php` (výchozí stav je **vypnuto**):

```php
'auth' => [
    'email_otp' => [
        'enabled'                 => true,  // kód jen pro účet bez passkey i TOTP
        'code_ttl_minutes'        => 10,    // platnost kódu
        'max_attempts'            => 5,     // pokusů na jeden kód, pak je nutný nový
        'resend_cooldown_seconds' => 60,    // min. prodleva mezi odesláním nového kódu
        'trusted_device_days'     => 30,    // „zapamatovat toto zařízení" na kolik dní
        'trusted_cookie_name'     => '__Host-myinvoice_td',
    ],
],
```

Chování:

- **Priorita silného faktoru.** Má-li uživatel použitelnou passkey nebo zapnuté
  TOTP, e-mailové OTP se neuplatní. E-mailový kód se použije jen tam, kde silný
  faktor chybí — nebo jako záchranná cesta pro účet s passkey, jejíž ověření
  instalace dočasně neumí (viz § 39.2.5).
- **Po heslu** se zobrazí pole pro kód z e-mailu + tlačítko *„Kód nedorazil?
  Odeslat znovu"* s odpočtem (cooldown). Kód je jednorázový a hashovaný v DB
  (sloupec `login_otps.code_hash`, nikdy plaintext).
- **„Zapamatovat toto zařízení na 30 dní"** (checkbox) vystaví cookie
  důvěryhodného zařízení; na něm se druhý faktor po danou dobu nevyžaduje.
  Heslo se vyžaduje vždy. Týká se jen e-mailového OTP, ne TOTP.
- **Brute-force.** Šestimístný kód je chráněn per-user lockoutem (10 selhání /
  10 min) stejně jako TOTP.

> ⚠️ Vyžaduje funkční **SMTP**. Když e-maily nechodí, uživatelé bez TOTP se
> nepřihlásí — buď oprav SMTP, nebo nastav `enabled => false`. Nouzově lze
> uživateli zrušit i důvěryhodná zařízení a čekající kódy:
> `php api/bin/reset-mfa.php <email>`.

### 76.2.7 Serverový zámek session

Automatický zámek browserové a PWA session je ve výchozím stavu vypnutý, aby se
po aktualizaci nezměnilo chování existujících instalací. Správce nastavuje
výchozí timeout pomocí `session.lock_after_minutes` nebo
`MYINVOICE_SESSION_LOCK_AFTER_MINUTES`. Hodnota `0` znamená, že správce zámek
nevynucuje. Uživatel jej přesto může dobrovolně zapnout v profilu na záložce
**Zámek aplikace**.

Hodnota musí být celé číslo od 0 do 1440; podporovaný je i kanonický numerický
řetězec, například `"15"`. Neplatná hodnota nesmí zablokovat start aplikace:
výchozí automatický zámek se bezpečně vypne a přihlášený uživatel uvidí
upozornění `session_lock_configuration` na health endpointu. Osobní explicitně
nastavené intervaly zůstávají účinné.

Osobní nastavení má tyto hranice:

- **Použít nastavení správce** zachová hodnotu správce; při `0` je automatický
  zámek vypnutý.
- Pokud správce nastavil kladnou hodnotu, osobní interval může být pouze stejný
  nebo kratší.
- Při hodnotě správce `0` lze zvolit vlastní interval 1 až 1440 minut.
- Pozdější snížení limitu správce okamžitě zpřísní i dříve uloženou delší osobní
  volbu.
- Zkrácení timeoutu se vyhodnotí serverově hned při uložení a může aktuální
  session rovnou zamknout.

Ruční **Zamknout** v uživatelském menu je dostupné bez ohledu na timeout, ale
jen pokud má účet alespoň jednu aktivní passkey a instalace ji umí použít.
Bez dostupné passkey se tlačítko nezobrazuje a server přímý požadavek odmítne,
aby nevznikla session, kterou lze ukončit pouze úplným odhlášením.

Stejnou podmínku má i **osobní interval**: kladnou hodnotu server uloží jen účtu
s použitelnou passkey, jinak vrátí `400 validation_failed`. Volba *Použít
nastavení správce* zůstává dostupná vždy.

> ⚠️ Správcovská hodnota `session.lock_after_minutes > 0` platí pro **všechny**
> účty, i pro ty bez passkey — a ty pak zamčenou session jen odhlásí (rozepsaný
> formulář se ztratí). Typicky se to týká instalací, kde uživatelé jedou na
> e-mailovém OTP. Aplikace na to upozorní health warningem
> `session_lock_without_unlock_method`; buď uživatelům registruj passkey, nebo
> nech `session.lock_after_minutes = 0` a osobní volbu na nich.

Aktivitu posouvají pouze skutečné vstupy do viditelné soukromé stránky, například
kliknutí, dotyk nebo klávesa. Polling, běžné API requesty, focus okna ani service
worker timeout neposouvají. Po dosažení limitu backend označí session jako
zamčenou a odmítne business API i v případě, že někdo odstraní frontendový
overlay.

Odemčení vyžaduje passkey a rotuje session ID i CSRF token, přičemž zachová
původní absolutní expiraci. TOTP existující zamčenou session přímo neodemkne;
volba **Přihlásit se znovu** provede bezpečný logout a celý login.

Zámek omezuje náhodný přístup k odloženému odemčenému zařízení. Nechrání data,
která už přečetl malware nebo XSS během aktivní session. Webová PWA negarantuje
zákaz screenshotu ani skrytí Android Recents. Rozpracovaný formulář zůstane
zachovaný jen dokud prohlížeč stránku drží v paměti; po ukončení stránky
Androidem se neuložená data ztratí. Offline odemčení není možné, protože server
musí vydat a ověřit jednorázovou challenge.

### 76.2.8 Nasazení změny autentizačního modelu

Aktivní session vytvořené před doplněním autentizačního kontextu se po migraci
označí jako `legacy`; migrace z pouhé existence TOTP neodvozuje, že konkrétní
session druhý faktor skutečně ověřila. Pokud instalace vyžaduje MFA, uživatelé
s takovou session se proto musí jednou znovu přihlásit. Jde o záměrné
fail-closed chování, které brání povýšení staré session bez důkazu o MFA.
Přihlašovací endpointy přítomnou starou cookie ignorují, takže stačí dokončit
standardní login; cookie není nutné ručně mazat v nastavení prohlížeče.

Browser session a její stav zámku jsou autoritativně uložené v MariaDB. Redis
slouží pro rate limiting, brute-force ochranu a best-effort cache; jeho výpadek
nesmí obnovit odvolanou, nahrazenou nebo zamčenou session.

Z toho plyne jedna změna configu: **`session.driver` už se nepoužívá**. Starší
`cfg.php` ho může dál obsahovat (`'auto'` / `'redis'` / `'db'`), hodnota se ale
ignoruje — session vždy čte a zapisuje MariaDB. Klíč lze bez náhrady smazat.

Migrace `0145` přestavuje tabulku `sessions` (dvanáct nových sloupců, backfill
a tři indexy), takže po dobu jejího běhu je tabulka zamčená a přihlašování
nefunguje. Naměřeno na MariaDB 11.8: **~16 s na 300 000 session**, u běžných
instalací s jednotkami až stovkami řádků je to pod sekundu. Před upgradem se
vyplatí spustit `php api/bin/cron-cleanup.php`, ať se nepřestavují dávno
expirované řádky.

## 76.3 Brute-force ochrana

| Pokusy během | Akce |
|---|---|
| 5 selhání / 5 minut | CAPTCHA (Cloudflare Turnstile) |
| 10 selhání / 15 minut | Lockout 15 minut (per IP) |
| 30 selhání / 1 hodinu | Lockout 24 hodin + e-mail uživateli o pokusech |

Implementace: **Redis** pokud běží, jinak **MariaDB MEMORY engine** fallback.

## 76.4 IP allowlist (volitelné)

V `cfg.php → ip_allowlist.allow` můžeš omezit přístup jen na vybrané IP /
CIDR rozsahy.

```php
'ip_allowlist' => [
    'enabled' => true,
    'mode' => 'block',           // 'block' = ne-allowlisted IP dostane 403
    'allow' => [
        '127.0.0.1',
        '203.0.113.42',          // tvoje kancelářská WAN (IPv4)
        '2001:db8:1234::/48',    // IPv6 prefix
    ],
],
```

Doporučení v produkci:

- Tvá kancelářská IP
- VPN endpoint (pokud používáš)
- Rezervní mobilní hotspot pro nouzový přístup

> 🛈 IP allowlist je v `cfg.php` (file-based config) → změna vyžaduje SSH /
> deploy. Není v UI **schválně** — v případě omylu by ses zablokoval
> a nemohl si ho přes UI sundat.

### 76.4.1 Za reverse proxy: `trusted_proxies` (důležité)

Pokud aplikace běží **za reverse proxy** (doporučené produkční nasazení — viz
kap. 2), vidí všechny požadavky přicházet z IP proxy (např. brána Dockeru
`172.x.0.1`), ne od reálného klienta. Bez konfigurace pak:

- **IP allowlist** filtruje podle IP proxy — buď zablokuje všechny, nebo (když
  přidáš proxy do `allow`) pustí všechny → ochrana je neúčinná.
- **Brute-force lockout** (kap. 20.3) je fakticky **globální** — všechny pokusy
  vypadají ze stejné IP.
- **Audit log** loguje IP proxy místo reálného klienta (ztráta forenzní hodnoty).

Proto za reverse proxy uveď proxy do `trusted_proxies` — aplikace pak vezme
skutečnou klientskou IP z hlavičky `X-Forwarded-For`:

```php
'ip_allowlist' => [
    'trusted_proxies' => [
        '172.16.0.0/12',         // Docker bridge sítě
        // '10.0.0.0/8',         // nebo konkrétní IP/rozsah tvé proxy
    ],
    'header' => 'X-Forwarded-For', // výchozí; odkud číst reálnou IP (jen za trusted proxy)
],
```

> ⚠️ Do `trusted_proxies` patří **jen** IP/rozsahy proxy, kterým věříš —
> klient za nedůvěryhodnou proxy by jinak mohl `X-Forwarded-For` podvrhnout.
> Aplikace hlavičku respektuje pouze tehdy, když `REMOTE_ADDR` odpovídá
> `trusted_proxies`.

### 76.4.2 Edge proxy MUSÍ `X-Forwarded-For` přepisovat, ne appendovat

Tohle je **nejčastější a nejzávažnější chyba** v nasazení za proxy. `X-Forwarded-For`
je obyčejná klientská hlavička — kdokoli ji může poslat s libovolným obsahem:

```
curl -H 'X-Forwarded-For: 203.0.113.42' https://tvuj-server/api/...
```

Aplikace chain prochází **zprava** a odloupává známé trusted hopy, takže podvržené
položky *nalevo* jsou neškodné. Ale to je bezpečné **jen tehdy, když edge proxy
klientskou hodnotu zahodí**. Když ji jen appenduje (nebo ji nesahá vůbec), zůstane
v chainu obsah od útočníka a ten si může zvolit, jakou IP aplikace uvidí →
**obejití IP allowlistu**, obejití brute-force lockoutu a **podvržené auditní logy**.

Edge proxy = ta, která jako **první** přijímá provoz z internetu. Musí být
nastavená takto:

| Proxy | Správně (přepisuje) | ❌ Špatně (appenduje) |
|---|---|---|
| nginx | `proxy_set_header X-Forwarded-For $remote_addr;` | `proxy_add_x_forwarded_for` |
| Apache `mod_proxy` | `RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}s"` (před `ProxyPass`) | výchozí chování `mod_proxy_http` |
| HAProxy | `option forwardfor header X-Forwarded-For if-none` **+** `http-request del-header X-Forwarded-For` před ním | samotné `option forwardfor` |
| Traefik | `forwardedHeaders.trustedIPs` (mimo seznam se hlavička zahazuje) | `forwardedHeaders.insecure = true` |
| Cloudflare | přepisuje automaticky (nebo použij `CF-Connecting-IP`) | — |

> ⚠️ **Řetězíš-li víc proxy**, tohle pravidlo platí jen pro tu **nejkrajnější**.
> Vnitřní hopy smí appendovat — musí ale být všechny uvedené v `trusted_proxies`,
> aby je aplikace uměla odloupnout.

**Ověření** (z internetu, ne z LAN):

```bash
curl -H 'X-Forwarded-For: 1.2.3.4' https://tvuj-server/api/health
```

V audit logu (**Systém → Audit log**) musí být tvoje **reálná** IP, ne `1.2.3.4`.
Pokud vidíš `1.2.3.4`, edge proxy hlavičku nepřepisuje a máš otevřený bypass.

#### Dodávaný Docker image

Image tenhle problém řeší i **bez** `trusted_proxies`: nginx uvnitř kontejneru
předává PHP skutečnou IP TCP peera v parametru `MYUCTO_CLIENT_IP`
(`fastcgi_param` **bez** prefixu `HTTP_`). Klientské hlavičky se do FastCGI vždy
mapují jako `HTTP_*`, takže tenhle parametr **nelze zvenčí podvrhnout** a
aplikace ho preferuje před `X-Forwarded-For`.

Když je ale před kontejnerem ještě další proxy, je „TCP peer" právě ona. Pak v
`docker/nginx.conf` odkomentuj blok `set_real_ip_from` a vyjmenuj rozsahy té
proxy — teprve tím se `MYUCTO_CLIENT_IP` přepočítá na reálného klienta:

```nginx
set_real_ip_from  173.245.48.0/20;   # rozsahy tvé edge proxy
real_ip_header    X-Forwarded-For;
real_ip_recursive on;
```

## 76.5 RBAC (role-based access)

Role se spravují v **Systém → Role**. Každý modul a významná akce mají jednu
ze tří úrovní: **neviditelné**, **pouze čtení** nebo **zápis**. Zápis zahrnuje
čtení; chybějící nebo neznámé oprávnění znamená zákaz.

Role typu **staff** jsou pro interní pracovníky. Role typu **client** mohou
dostat jen katalogem povolené funkce klientského portálu. Systémová role
**Superadmin** stojí mimo matici, má plný přístup ke všem firmám a jako jediná
spravuje uživatele, role a globální administraci.

Každý non-superadmin potřebuje explicitní membership firmy. U jedné firmy může
mít kompatibilní přepis role; role se nesčítají. Neaktivní role, neplatný přepis
nebo prázdný membership jsou vždy fail-closed.

### Jak je to vynucené

1. **Backend** mapuje každou neveřejnou routu na konkrétní permission klíč a
   minimální úroveň. Nezmapovaná routa je odmítnuta; stavové, tenant a vlastnické
   guardy se kontrolují navíc.
2. **API token (PAT)** má průnik oprávnění vlastníka pro aktuální firmu a scope
   tokenu. Scope `read` nikdy nepovolí zápis; odebrání firmy nebo snížení role se
   projeví existujícímu tokenu okamžitě.
3. **UI** používá stejnou efektivní matici pro menu, přímé URL a skrytí akcí.
   Po přepnutí firmy stará práva zahodí a před vykreslením načte nová.

## 76.6 CSRF + Origin check

Každý mutating request (POST / PUT / PATCH / DELETE) musí mít:

1. **Origin header** se shodující s přesným originem bezpečně rozpoznané domény
2. **X-CSRF-Token** header se shodující s tokenem v session

Na canonical hostu je očekávaný origin odvozený z `app.url`; na aktivní vlastní
doméně je to výhradně `https://<její-hostname>`. Jiný port, koncová tečka,
podvržený `Host`, neaktivní alias ani origin jiné firmy neprojde.

Bez nich → 403 `csrf_failed` / `origin_mismatch`. UI to obsluhuje
automaticky (token v Pinia store, header v axios interceptoru).

## 76.7 Activity log

Každá mutace (vytvoření / změna / vystavení / smazání) se loguje. Záznamy
obsahují:

- Akce (`invoice.created`, `invoice.issued`, `client.updated`, `auth.login_success`,
  `auth.login_failed`, `bank.statement_imported`, `currency.updated`, …)
- Uživatel (NULL pro neautentizované akce jako neúspěšné login)
- Entita (typ + ID)
- IP adresa (binární `VARBINARY(16)` — IPv4 i IPv6)
- User-Agent
- Payload — JSON s relevantními detaily (např. fields=`['email', 'name']`
  u `client.updated`)
- Datum + čas

Viz [73. Nastavení](73_Nastaveni.md) pro UI.

### 76.7.1 Co log NEUKLÁDÁ

- **Hesla** — ani staré, ani nové
- **PII klientů** mimo to, co bylo změněno (jen fields seznam, ne hodnoty)
- **Bankovní transakce** — log obsahuje jen ID importovaného výpisu

### 76.7.2 Jak se do logu zapisuje IP adresa

Aplikace bere IP klienta z **IP síťového spojení** (`REMOTE_ADDR`). Když běží
**za reverse proxy** (Docker, nginx, Cloudflare…), je tím spojením proxy — bez
konfigurace by se proto do auditu zapisovala **IP proxy**, ne reálného klienta
(typicky uvidíš pořád stejnou IP, např. bránu Dockeru `172.x.0.1`).

Reálnou IP přečte aplikace z hlavičky `X-Forwarded-For` **pouze tehdy**, když
`REMOTE_ADDR` odpovídá rozsahu v `cfg.ip_allowlist.trusted_proxies` (viz
§ 76.4.1). Z hlavičky se bere **první** adresa (původní klient). Bez nastavené
`trusted_proxies` se `X-Forwarded-For` ignoruje (ochrana proti podvržení).

> 🛈 Stejná logika se zjišťování IP používá i pro **brute-force lockout**
> (kap. 20.3). Za reverse proxy bez `trusted_proxies` proto lockout počítá
> pokusy podle IP proxy = fakticky globálně. Po nastavení `trusted_proxies`
> začnou audit log i lockout pracovat s reálnou klientskou IP.

## 76.8 DKIM podpis e-mailů

Pro **deliverabilitu** (aby gmail / o365 / seznam tvé maily nepoznačily jako
spam) doporučujeme aktivovat DKIM:

1. Vygeneruj RSA klíč: `openssl genrsa -out private/dkim/myucto.pem 2048`
2. Public key → DNS TXT záznam `myucto._domainkey.tvoje-domena.cz`
3. V `cfg.php → smtp.dkim.enabled => true`
4. Restart služby

Detaily v `README.md` v rootu repa.

## 76.9 Klávesové zkratky

Položka **Klávesové zkratky** je pátým bodem menu pod jménem uživatele a
zároveň pátou záložkou obrazovky **Profil**. Na mobilu je dostupná ve výběru
záložek pod nadpisem Profil. Umožňuje změnit nebo vypnout zkratky pro viditelné
položky hlavního menu, rychlé vytváření přes **+** a globální hledání.
Preference se ukládá celosystémově k ID přihlášeného uživatele, nikoli k firmě
nebo zařízení.

Formulář nedovolí duplicitní kombinace ani klávesy vyhrazené pro prohlížeč a
pevné akce aplikace. Zkratky se nespouštějí při psaní do formuláře, během
zamčené relace ani v otevřeném modálním dialogu. **Obnovit výchozí** odstraní
uživatelský přepis a vrátí bezpečné kombinace popsané v
[Přehledu](10_Prehled.md#klavesove-zkratky).

## 76.10 Tipy

- **Vždycky 2FA pro admin** — pokud admin účet padne, padá vše. Žádná výmluva.
- **Pravidelně rotuj hesla** každých 6–12 měsíců.
- **IP allowlist** v produkci pro non-veřejné použití (B2B accounting).
- **Activity log review** — alespoň 1× za měsíc projeďté podezřelé login
  selhání nebo neočekávané force-edit.
- **Backup `cfg.php` + `private/dkim/`** mimo repo — není v gitu, ztrátou
  přijdeš o pepper a nepřihlásíš se ke starým heslům.

> 🛈 **Vypršení licence tvá data neohrozí.** Bezplatné funkce původního
> MyInvoice zůstávají plně funkční včetně zápisu. Komerční moduly se skryjí
> i pro čtení a API, jejich data ale zůstávají beze změny ve vlastní databázi
> a po obnovení licence se znovu zpřístupní. Detail v
> [79. Licence a aktivace](79_Licence_a_aktivace.md).
