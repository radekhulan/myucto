# 101. Bezpečnost (MFA, passkeys, zámek session, IP allowlist, role, activity log)

> Návod pro uživatele a správce: jak si zabezpečit přihlášení (TOTP, přístupové
> klíče, záložní kódy), jak správce vynutí silné ověření, omezí přístup podle IP
> adres, nastaví role a zkontroluje activity log. Na konci jsou podrobná
> pravidla a provozní postupy.

Bezpečnost MyÚčto stojí na několika navazujících vrstvách:

1. **Autentizace** - heslo (bcrypt + pepper) nebo volitelně passkey bez hesla,
   brute-force ochrana a CAPTCHA
2. **Silné MFA** - passkey nebo TOTP
3. **Síťová izolace** - IP allowlist (volitelný, doporučeno v produkci)
4. **Autorizace** - databázové role s oprávněními neviditelné / čtení / zápis
5. **Audit** - activity log všech mutací
6. **Zámek session** - serverové uzamčení PWA po nečinnosti

## 101.1 Kdy to potřebujete

Kapitolu otevřete, když:

- si zapínáte druhý faktor (TOTP nebo přístupový klíč),
- jste ztratili telefon nebo klíč a potřebujete se dostat do účtu,
- chcete, aby všichni uživatelé měli povinné silné MFA,
- chcete omezit přístup do aplikace jen z vybraných IP adres,
- zakládáte nebo upravujete roli uživatele,
- vyšetřujete, kdo a kdy co změnil,
- potřebujete anonymizovanou kopii databáze pro testování.

<!-- cols: 26 40 34 -->
| Kdy | Co udělat | Kde |
|---|---|---|
| při prvním přihlášení | Zapnout TOTP nebo registrovat přístupový klíč | `Profil → 2FA / TOTP`, `Profil → Přístupové klíče` |
| hned po zapnutí druhého faktoru | Vygenerovat a uložit záložní kódy | `Profil → Přístupové klíče`, **Záložní kódy** |
| při nasazení | Vynutit MFA, nastavit IP allowlist a `trusted_proxies` | `cfg.php`, viz [§ 101.6](#1016-krok-za-krokem-vynuceni-silneho-mfa-spravce) |
| při novém uživateli | Přidělit mu roli | `Systém → Role a oprávnění`, `Systém → Uživatelé` |
| jednou měsíčně | Projít activity log | `Systém → Log` |
| před předáním dat vývojáři | Vyrobit anonymizovanou kopii | viz [§ 101.11.10](#1011110-anonymizovana-kopie-pro-testovaci-instanci) |

## 101.2 Než začnete

- Pro TOTP potřebujete autentikátor v mobilu. Doporučujeme
  [Proton Authenticator](https://proton.me/authenticator) nebo
  [Ente Auth](https://ente.com/auth/), případně
  [Google Authenticator](https://support.google.com/accounts/answer/1066447)
  nebo [Microsoft Authenticator](https://support.microsoft.com/cs-cz/authenticator/download-microsoft-authenticator).
  Funguje i 1Password nebo Bitwarden.
- Pro přístupový klíč potřebujete prohlížeč a zařízení s podporou passkeys.
  Aplikace musí běžet na stabilní HTTPS adrese (`app.url`).
- Při přidání druhého faktoru vás aplikace požádá o nové ověření: aktuální
  heslo, nebo existující přístupový klíč.
- Správcovské kroky (§ 101.6 a § 101.7) vyžadují přístup k `cfg.php` nebo
  k proměnným prostředí na serveru.
- Nastavení rolí vyžaduje účet superadmin.

## 101.3 Krok za krokem: zapnutí TOTP

1. Otevřete `Profil → 2FA / TOTP`.
2. Zadejte aktuální heslo a klikněte na **Nastavit TOTP**. Máte-li už přístupový
   klíč, klikněte místo toho na **Ověřit přístupovým klíčem a nastavit TOTP**.
3. Aplikace ukáže QR kód a textový klíč. V autentikátoru zvolte přidat účet a
   naskenujte QR kód.
4. Zadejte aktuální šestimístný kód z autentikátoru a klikněte na **Aktivovat
   TOTP**.

**Jak poznáte, že je hotovo:** Záložka **2FA / TOTP** ukazuje
**TOTP je aktivní.** a při dalším přihlášení aplikace požádá o kód z autentikátoru.

> [!TIP]
> Hned potom si vygenerujte záložní kódy ([§ 101.5](#1015-krok-za-krokem-zalozni-kody)).
> Při ztrátě autentikátoru jinak zbývá jen zásah správce na serveru.

## 101.4 Krok za krokem: registrace přístupového klíče

1. Otevřete `Profil → Přístupové klíče`.
2. Do pole **Název přístupového klíče** napište název (například název
   telefonu) a klikněte na **Přidat přístupový klíč**.
3. Potvrďte registraci systémovým dialogem zařízení (otisk, obličej, PIN nebo
   bezpečnostní klíč). U prvního klíče zadejte aktuální heslo, nebo TOTP kód.
4. Doporučujeme zaregistrovat druhý klíč, nebo mít vedle klíče aktivní TOTP.

**Jak poznáte, že je hotovo:** Klíč je v seznamu s datem **Vytvořeno** a po
použití s datem **Naposledy použito**. Klíč lze **Přejmenovat** nebo **Odebrat**.

## 101.5 Krok za krokem: záložní kódy

1. Otevřete `Profil → Přístupové klíče` a v části **Záložní kódy** klikněte na
   **Vygenerovat záložní kódy**. Potvrďte ověření přístupovým klíčem nebo kódem
   z autentikátoru.
2. Kódy si hned uložte (**Stáhnout jako soubor**, **Kopírovat**, nebo tisk) mimo
   počítač, ze kterého se přihlašujete. Zobrazí se jen jednou.
3. Klikněte na **Mám je uložené**.

**Jak poznáte, že je hotovo:** Sekce ukazuje **Zbývá 10 z 10 kódů**. Při ztrátě
klíče i autentikátoru zadáte kód na přihlašovací stránce místo druhého faktoru
(odkaz „Nemám klíč ani autentikátor“). Každý kód funguje právě jednou.

> [!WARNING]
> Nová sada okamžitě zruší tu předchozí. Kód neumožní vytvořit API token ani
> vydat další sadu kódů; nejdřív je potřeba obnovit skutečný faktor.

## 101.6 Krok za krokem: vynucení silného MFA (správce)

1. V `cfg.php` (nebo `cfg.local.php`) nastavte:

   ```php
   'auth' => [
       'require_mfa' => true,
       'allowed_mfa_methods' => ['passkey', 'totp'],
   ],
   ```

   V Dockeru a PaaS stejné nastavíte proměnnými `MYINVOICE_AUTH_REQUIRE_MFA=true`
   a `MYINVOICE_AUTH_MFA_METHODS=passkey,totp`.
2. Uživatelé bez silného faktoru se při dalším přihlášení dostanou na stránku
   pro nastavení MFA a bez ní nepokračují.
3. Chcete-li povolit přihlášení jen přístupovým klíčem, nastavte
   `auth.passwordless_login.enabled` (viz [§ 101.11.2.3](#1011123-prihlaseni-s-passkey-a-mfa)).

**Jak poznáte, že je hotovo:** Nový uživatel po přihlášení heslem vidí
`/setup-mfa` a do aplikace se dostane až po registraci klíče nebo zapnutí TOTP.

## 101.7 Krok za krokem: omezení přístupu IP allowlistem (správce)

1. Do `cfg.php` zapište povolené IP adresy a rozsahy (příklad je v
   [§ 101.11.4](#101114-ip-allowlist-volitelne)). Vždy ponechte svou IP, VPN a
   záložní hotspot.
2. Běží-li aplikace za reverse proxy, uveďte proxy do `trusted_proxies`
   ([§ 101.11.4.1](#1011141-za-reverse-proxy-trustedproxies-dulezite)) a
   zajistěte, aby edge proxy přepisovala `X-Forwarded-For`
   ([§ 101.11.4.2](#1011142-edge-proxy-musi-x-forwarded-for-prepisovat-ne-appendovat)).
3. Ověřte z internetu: `curl -H 'X-Forwarded-For: 1.2.3.4' https://vas-server/api/health`.
   Pak v `Systém → Log` musí být vaše skutečná IP, ne `1.2.3.4`.

**Jak poznáte, že je hotovo:** Cizí IP dostane 403 a v logu jsou skutečné IP
klientů.

> [!WARNING]
> IP allowlist je záměrně jen v `cfg.php`, ne v UI. Při omylu byste se
> zablokovali a nešel by sundat přes UI.

## 101.8 Krok za krokem: přidělení role uživateli (superadmin)

1. Otevřete `Systém → Role a oprávnění`. Novou roli založíte tlačítkem
   **Nová role**, existující upravíte (**Upravit**) nebo zkopírujete
   (**Duplikovat**).
2. Zadejte **Název role**, **Typ role** a u každého modulu úroveň
   **Neviditelné**, **Pouze čtení** nebo **Zápis**. Klikněte na **Uložit**.
3. Otevřete `Systém → Uživatelé` a uživateli přiřaďte roli a firmy.

**Jak poznáte, že je hotovo:** Uživatel po přepnutí na firmu vidí v menu jen
moduly, na které má právo. Role se u firmy nesčítají; chybějící oprávnění
znamená zákaz.

## 101.9 Krok za krokem: kontrola activity logu

1. Otevřete `Systém → Log`.
2. Filtrujte podle akce a entity. U záznamu uvidíte čas, uživatele, akci,
   entitu, IP adresu a payload.
3. Hledejte neúspěšná přihlášení (`auth.login_failed`) a neočekávané úpravy.

**Jak poznáte, že je hotovo:** Podezřelé záznamy jste vysvětlili nebo
eskalovali. Doporučujeme kontrolu aspoň jednou měsíčně.

## 101.10 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Nemáte autentikátor ani klíč | Zařízení je ztracené | Zadejte záložní kód ([§ 101.5](#1015-krok-za-krokem-zalozni-kody)); jinak správce spustí `php api/bin/reset-mfa.php <email>` ([§ 101.11.2.4](#1011124-obnova-pristupu)) |
| Odebrat poslední silný faktor nejde | Je vynucené MFA | Nejdřív zaregistrujte druhý klíč nebo zapněte TOTP |
| Přístupové klíče nejsou dostupné | `app.url` není HTTPS DNS adresa, nebo je v konfiguraci vypnutá metoda `passkey` | Opravte `app.url` ([§ 101.11.2.1](#1011121-passkeys)) |
| Přihlášení hlásí `503 passkeys_unavailable` | WebAuthn je nedostupný a účet nemá jiný druhý faktor | Opravte `app.url`, případně `reset-mfa.php` |
| TOTP kód nejde použít podruhé | Kód platí jen jednou | Počkejte na nový kód v autentikátoru |
| Lockout po opakovaných chybách | Brute-force ochrana ([§ 101.11.3](#101113-brute-force-ochrana)) | Počkejte, až lockout vyprší |
| Zamčená aplikace po nečinnosti | Zámek session | Klikněte na **Odemknout přístupovým klíčem**, nebo na **Odhlásit** a přihlaste se znovu |
| Uživatelé bez TOTP se nepřihlásí (e-mailové OTP) | Nechodí e-maily | Opravte SMTP, nebo vypněte `email_otp.enabled` |
| V logu je pořád IP proxy | Chybí `trusted_proxies` | Viz [§ 101.7](#1017-krok-za-krokem-omezeni-pristupu-ip-allowlistem-spravce) |
| Chyba 403 `csrf_failed` / `origin_mismatch` | Chybí Origin nebo CSRF token | Obnovte stránku a přihlaste se znovu ([§ 101.11.6](#101116-csrf-origin-check)) |

## 101.11 Podrobnosti a pravidla

### 101.11.1 Hesla

| Vrstva | Detail |
|---|---|
| Algoritmus | bcrypt cost 12 |
| Pepper | Sůl z `cfg.php → app.pepper` (32B base64), neukládá se v DB |
| Min. délka | 12 znaků |
| Max. délka | Bez limitu - passphrase je doporučená (20+ znaků) |
| Kontrola síly | Indikátor v UI (slabé / střední / silné) |
| Reset hesla | Odkaz na 1 hodinu, e-mailem |

> [!TIP]
> **Passphrase je bezpečnější než krátké složité heslo.** „korelace medvědí
> dýně přístav 2026" má 49 znaků a je odolnější vůči brute-force než „Hu1@n!22".

### 101.11.2 Vícefaktorové ověření

MyÚčto podporuje dva silné faktory:

- **passkey (WebAuthn)** - kryptografický přístupový klíč chráněný zařízením,
- **TOTP** - šestimístný časový kód z autentikátoru.

E-mailové OTP je kompatibilní druhý krok pro účet bez silného faktoru, ale
nesplňuje povinnou silnou MFA politiku. Důvěryhodné zařízení se týká pouze
e-mailového OTP.

#### 101.11.2.1 Passkeys

Passkey zaregistrujete v `Profil → Přístupové klíče`. Každý klíč má
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

##### Provozní diagnostika canonical `app.url`

`app.url` je současně canonical origin pro běžné routování, odkazy a WebAuthn.
Pro pravidelný monitoring vždy volejte health přes **přesný origin z `app.url`**,
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
| `missing` / `app_url_missing` | Klíč chybí, je přesně prázdný nebo obsahuje jen whitespace. Chybějící a přesně prázdná hodnota zachovává legacy fallback na validní request hostname; whitespace tento fallback nemá. Po setupu nastavte explicitní HTTP(S) origin. |
| `invalid` / `app_url_invalid_origin` | Neprázdná hodnota není samostatný HTTP(S) origin. Pokud z ní legacy resolver ještě získá platný hostname, uzná nejvýše request s přesně stejným hostname, nikdy libovolný host; nejde však o podporovaný canonical origin a musí se opravit. Odstraňte userinfo, cestu, query či fragment nebo opravte schéma, hostname a port. |
| `routing_only` / `app_url_webauthn_incompatible` | Běžné rozhraní funguje, včetně záměrného HTTP nebo LAN-IP nasazení, ale passkeys nejsou dostupné. Pro WebAuthn použijte HTTPS DNS hostname. |
| `hostname_conflict` / `app_url_hostname_conflict` | Hostname z `app.url` je současně uložený jako vlastní doména firmy. Běžné cesty aplikace jsou fail-closed; přesný read-only health zůstane dostupný. Obnovte původní canonical adresu, vlastní doménu deaktivujte a smažte, nebo nastavte jiný canonical hostname. |
| `webauthn_ready` / `app_url_valid` | Origin vyhovuje routování i WebAuthn. Vedle HTTPS DNS originu je povolená jediná HTTP výjimka: `http://localhost`. |

Při whitespace-only nebo jiné neprázdné hodnotě nepoužitelné pro routování
propustí tenant host gate přes jiný hostname jen přesné `GET` a `HEAD`
`/api/v1/health` (interně `/api/health`). POST, jiný endpoint, přihlášení ani
ostatní aplikační cesty výjimku nedostanou. Toto je pouze recovery cesta; po
opravě se health znovu monitoruje přes nakonfigurovaný canonical hostname. Pokud
cizí hostname odmítne už reverse proxy, spusťte recovery dotaz ze serveru nebo
kontejneru přes hostname, který proxy přijímá. Health neobchází zapnutý IP
allowlist. Během nedokončeného first-run setupu používejte `GET`; setup allowlist
metodu `HEAD` nepovoluje.

Stejná přesná health výjimka platí při kolizi canonical hostname s uloženou
vlastní doménou. Na rozdíl od syntakticky neplatného `app.url` ji volejte přes
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
[§ 999.5.1.8](999_Reseni_problemu.md#999518-diagnostika-appurl).

Vlastní domény klientských portálů se nestávají dalším WebAuthn RP ID. Browser
se z nich přesměruje na přesný canonical origin z `app.url`, kde proběhne
passwordless passkey, passkey jako druhý faktor nebo TOTP. Aplikace potom vydá
jednorázový kód platný 60 sekund, svázaný s PKCE verifierem, uživatelem, firmou
a přesným cílovým hostnamem. Kód lze spotřebovat jen jednou a skutečný session
token se v URL nikdy neobjeví. Na cílové doméně vznikne samostatná host-only
session. Každé nové přihlášení z klientské domény vyžaduje nové ověření uživatele;
existující relace hlavní instance se k němu automaticky nepoužije. Ověření na hlavní
adrese používá vlastní krátkodobou HttpOnly cookie svázanou s konkrétním požadavkem
na přihlášení. Přihlášení jiným účtem ani úklid této dočasné relace nepřepíše
a neodhlásí účet otevřený v hlavním okně.

Správa passkeys a ostatní interní obrazovky zůstávají na canonical
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

#### 101.11.2.2 Aktivace TOTP

`Profil → 2FA / TOTP`, tlačítko **Nastavit TOTP**.

![Aktivace 2FA](img/16_2fa_setup.webp)

1. Nejdřív znovu prokážete, že jste to vy: bez passkey **aktuálním heslem**,
   s aktivní passkey **ověřením passkey** (stejně jako při registraci dalšího
   klíče). Samotná přihlášená session na přidání druhého faktoru nestačí -
   jinak by si ho na unesené session mohl založit útočník. Špatně zadaná
   hesla se tu počítají do stejné ochrany proti hádání jako při přihlášení,
   takže po opakovaných chybách se účet na 15 minut zamkne i pro login.
2. Aplikace ukáže **QR kód** + textový **secret key**.
3. V mobilu otevřete **autentikátor** (Proton Authenticator, Ente Auth,
   Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden) → Přidat účet → Sken QR kódu.
4. Aplikace začne generovat 6-cifrené kódy každých 30 sekund.
5. Zadejte aktuální kód do MyÚčta a klikněte na **Aktivovat TOTP**.

Na vlastní doméně klientského portálu se nastavení TOTP otevře na hlavní
adrese aplikace, kde lze bezpečně ověřit také existující passkey.

Pokud průvodce nabídne nebo vyžádá první TOTP bezprostředně po přihlášení
heslem nebo po prvním nastavení hesla z uvítacího odkazu, heslo znovu neopisujete.
Totéž platí při prvotním nastavení vlastní instalace. Pokračování je jednorázové
a platí nejvýše pět minut. Po obnovení stránky, opuštění průvodce nebo změně
hesla je potřeba nové ověření. Existující passkey tato možnost nenahrazuje.

Při přechodu politiky z passkeys na TOTP lze existující passkey použít
výhradně k potvrzení zřízení TOTP. Nepovoluje to přihlášení ani jiné citlivé
operace metodou, kterou správce zakázal.

Změna nebo reset hesla, včetně změny správcem, ruší rozpracovanou aktivaci
TOTP. Začněte znovu a načtěte nový QR kód. Již aktivní TOTP se změnou hesla neruší.

> [!TIP]
> Při ztrátě autentikátoru použijte jinou passkey nebo **záložní kód**
> (viz [§ 101.11.2.4](#1011124-obnova-pristupu)). Až když nemáte nic z toho, zbývá CLI
> rescue `php api/bin/reset-mfa.php <email>`.

#### 101.11.2.3 Přihlášení s passkey a MFA

Po zadání e-mailu a hesla nabídne aplikace passkey, pokud ji účet má. Je-li
aktivní také TOTP, lze explicitně přepnout na šestimístný kód z autentikátoru.

TOTP kód lze použít jen jednou pro přihlášení nebo potvrzení citlivé operace.
Pokud hned po přihlášení vytváříte API token nebo přidáváte další passkey,
počkejte na nový kód v autentikátoru.

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
zrušte systémový dialog a přihlaste se e-mailem a heslem.

Účet s passkey nedostane automatický fallback na e-mailový kód. Pokud passkey
na aktuálním zařízení není dostupná, použijte jinou passkey, TOTP nebo rescue.

#### 101.11.2.4 Obnova přístupu

Kde passkey fyzicky leží, rozhoduje o tom, co se stane při ztrátě zařízení:

- **V zařízení** (Windows Hello, Touch ID, bezpečnostní klíč) - klíč je vázaný
  na hardware. S koncem zařízení končí i on.
- **Ve správci hesel nebo v cloudu účtu** (Keeper, 1Password, iCloud Keychain,
  Google Password Manager) - klíč se synchronizuje, takže přežije výměnu
  počítače a přihlásíte se jím i jinde.

Kam se klíč uloží, vybírá prohlížeč při registraci; aplikace to neřídí a ani to
nezjistí zpětně. Máte-li jediný klíč a ten je vázaný na zařízení, mějte jako
zálohu buď druhý klíč, nebo aktivní TOTP.

Hned po registraci aplikace požádá o jedno zkušební ověření novým klíčem.
Některé správce klíčů registraci projdou, ale ověření pak vracejí neplatné;
takový klíč aplikace rovnou odebere a vyzve vás, abyste ho uložili jinam nebo
si zapnuli TOTP. Platí to i při prvním nastavení účtu z uvítacího e-mailu: když
klíč neprojde, zůstanete na obrazovce nastavení ověření a vyberete jiný klíč
nebo TOTP. Zavřete-li systémový dialog, klíč zůstane uložený jako
neověřený a do 15 minut od registrace ho můžete vyzkoušet tlačítkem
**Vyzkoušet** v seznamu klíčů.

##### Záložní jednorázové kódy

**Profil → Přístupové klíče → Záložní kódy.** Sada deseti kódů ve tvaru
`ABCDE-FGHJK`; každý funguje **právě jednou**. Zadávají se na přihlašovací
stránce místo passkey i TOTP (odkaz „Nemám klíč ani autentikátor“). Po přihlášení
jimi potvrdíte i obnovu faktoru: na stránce **Přístupové klíče** odkaz „Klíč
nefunguje? Použít záložní kód“ zpřístupní odebrání klíče i přidání nového a na
stránce **2FA / TOTP** stejný odkaz zapnutí TOTP. To se hodí, když správce klíčů
vrací neplatné ověření a nový klíč by skončil v témže správci. Kód se spotřebuje,
i když operaci nedokončíte.

Odebrat **poslední** silný faktor nejde ani záložním kódem, pokud je MFA povinné.
Nejdřív zapněte TOTP nebo přidejte nový klíč, teprve potom starý klíč odeberte.

Server ukládá jen SHA-256 kódu, takže **sadu jde zobrazit jedinkrát** - při
vygenerování. Uložte ji mimo počítač, ze kterého se přihlašujete: tisk, trezor,
správce hesel. Vygenerování nové sady okamžitě ruší tu předchozí.

Co kód schválně **ne**umí, aby zůstal záchranou a nestal se trvalým faktorem:

- nevydá další sadu záložních kódů (nejdřív obnovte reálný faktor),
- nepotvrdí vytvoření API tokenu ani práci s podpisovým certifikátem pro EPO,
- nepočítá se do `allowed_mfa_methods`; naopak jím projdete i v konfiguraci, která
  by vás jinak zamkla ven (`allowed_mfa_methods = ['passkey']` + ztracený klíč).

Použití kódu se zapisuje do activity logu (`auth.recovery_code_login`,
`auth.recovery_code_used`) i s IP a počtem zbývajících kódů. Neúspěšné ověření
přístupovým klíčem zapisuje `auth.passkey_step_up_failed` (při přihlášení
`auth.login_failed`) s kategorií důvodu, např. `assertion_invalid` s hláškou
validátoru nebo `unknown_credential`, podle které se dá dohledat, proč klíč
neprošel.

##### Rescue na serveru

Nejprve použijte jinou zaregistrovanou passkey, TOTP nebo záložní kód. Pokud není
dostupné nic z toho, správce může na serveru spustit:

```bash
php api/bin/reset-mfa.php vas@email.cz
```

Skript vypne TOTP, odvolá všechny passkeys, zruší důvěryhodná zařízení,
čekající OTP, WebAuthn flow, step-up proofy i **záložní kódy** a invaliduje
všechny session uživatele. Zruší také všechny **API tokeny a MCP připojení**
uživatele a vypíše jejich počet; integrace je po obnově účtu potřeba napojit
novým tokenem. Stejný skript lze spustit také přes alias `reset-2fa.php`.

Běžná změna hesla ani obnova zapomenutého hesla API tokeny neruší: tokeny slouží
integracím, které na hesle nezávisí. Spravují a ruší se samostatně v aplikaci.

##### Docker

V kontejneru je aplikace v `/var/www/html` a běží pod `www-data`. Spouštějte skript
pod tímto uživatelem - jako `root` sice projde taky, ale případné soubory, které
by po sobě zanechal, by pak měly špatného vlastníka:

```bash
# docker compose (název služby `app` dle docker-compose.yml)
docker compose exec -u www-data app php api/bin/reset-mfa.php vas@email.cz

# samostatný kontejner
docker exec -u www-data -w /var/www/html <container> php api/bin/reset-mfa.php vas@email.cz
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

> [!WARNING]
> Rescue používejte jen z důvěryhodného shellu serveru. Přímý SQL zásah není
> ekvivalentní: snadno ponechá aktivní session nebo rozpracované ověřovací flow.
> Reset je zapsaný do auditní stopy a zapečetěný v hash-chainu (§ 33a) - kdo ho
> spustil a odkud, tedy zpětně dohledáte.

#### 101.11.2.5 Vynucení silného MFA

Pokud chcete, aby **každý** uživatel měl passkey nebo TOTP,
nastavte v `cfg.php` (nebo `cfg.local.php`):

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
podporované jako TOTP-only politika. Pro nové instalace používejte obecné MFA
nastavení.

`allowed_mfa_methods` rozhoduje **co povinné MFA splní**, ne na co se přihlášení
zeptá. Zúžení seznamu (typicky na `['passkey']` při přechodu na passkey-only)
proto nikdy nezruší faktor, který uživatel reálně má:

- Kdo má zapnuté TOTP, zadává ho i dál. Když `totp` v seznamu není, výsledná
  session je jen `basic` - při `require_mfa = true` skončí uživatel na
  `/setup-mfa` a zaregistruje povolenou metodu.
- Kdo má passkey a WebAuthn je konfiguračně nedostupný (rozbité `app.url`),
  se přihlásí přes TOTP nebo e-mailové OTP, pokud je má. Bez jakéhokoliv jiného
  druhého faktoru vrací přihlášení `503 passkeys_unavailable` - nikdy nepropadne
  na samotné heslo. Řešením je opravit `app.url`, jinak `reset-mfa.php`.
- Totéž platí pro step-up při vydání API tokenu: zaregistrované TOTP se vyžaduje
  bez ohledu na `allowed_mfa_methods`.

Neznámá hodnota v seznamu (například `email_otp`, které sem nepatří) start
aplikace neshodí: použije se výchozí `['passkey', 'totp']` a přihlášený správce
uvidí na health endpointu warning `mfa_methods_configuration`.

> [!WARNING]
> Povolení TOTP vyžaduje validní `app.secret_encryption_key` (32B base64).
> Health endpoint na chybnou konfiguraci upozorní; viz
> [§ 999 Řešení problémů](999_Reseni_problemu.md).

#### 101.11.2.6 E-mailové ověření pro účet bez silného faktoru

Pro uživatele, kteří nechtějí (nebo neumí) authenticator aplikaci - typicky
externí účetní - lze zapnout **e-mailové OTP** jako druhý faktor. Kdo nemá
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
  faktor chybí - nebo jako záchranná cesta pro účet s passkey, jejíž ověření
  instalace dočasně neumí (viz § 101.11.2.1).
- **Po heslu** se zobrazí pole pro kód z e-mailu + tlačítko *„Kód nedorazil?
  Odeslat znovu"* s odpočtem (cooldown). Kód je jednorázový a hashovaný v DB
  (sloupec `login_otps.code_hash`, nikdy plaintext).
- **„Zapamatovat toto zařízení na 30 dní"** (checkbox) vystaví cookie
  důvěryhodného zařízení; na něm se druhý faktor po danou dobu nevyžaduje.
  Heslo se vyžaduje vždy. Týká se jen e-mailového OTP, ne TOTP.
- **Brute-force.** Šestimístný kód je chráněn per-user lockoutem (10 selhání /
  10 min) stejně jako TOTP.

> [!WARNING]
> Vyžaduje funkční **SMTP**. Když e-maily nechodí, uživatelé bez TOTP se
> nepřihlásí - buď opravte SMTP, nebo nastavte `enabled => false`. Nouzově lze
> uživateli zrušit i důvěryhodná zařízení a čekající kódy:
> `php api/bin/reset-mfa.php <email>`.

#### 101.11.2.7 Serverový zámek session

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

> [!WARNING]
> Správcovská hodnota `session.lock_after_minutes > 0` platí pro **všechny**
> účty, i pro ty bez passkey - a ty pak zamčenou session jen odhlásí (rozepsaný
> formulář se ztratí). Typicky se to týká instalací, kde uživatelé jedou na
> e-mailovém OTP. Aplikace na to upozorní health warningem
> `session_lock_without_unlock_method`; buď uživatelům zaregistrujte passkey, nebo
> nechte `session.lock_after_minutes = 0` a osobní volbu na nich.

Aktivitu posouvají pouze skutečné vstupy do viditelné soukromé stránky, například
kliknutí, dotyk nebo klávesa. Polling, běžné API requesty, focus okna ani service
worker timeout neposouvají. Po dosažení limitu backend označí session jako
zamčenou a odmítne business API i v případě, že někdo odstraní frontendový
overlay.

Odemčení vyžaduje passkey a rotuje session ID i CSRF token, přičemž zachová
původní absolutní expiraci. TOTP existující zamčenou session přímo neodemkne;
tlačítko **Odhlásit** na zamykací obrazovce provede bezpečný logout a potom
stačí celé přihlášení.

Zámek omezuje náhodný přístup k odloženému odemčenému zařízení. Nechrání data,
která už přečetl malware nebo XSS během aktivní session. Webová PWA negarantuje
zákaz screenshotu ani skrytí Android Recents. Rozpracovaný formulář zůstane
zachovaný jen dokud prohlížeč stránku drží v paměti; po ukončení stránky
Androidem se neuložená data ztratí. Offline odemčení není možné, protože server
musí vydat a ověřit jednorázovou challenge.

#### 101.11.2.8 Nasazení změny autentizačního modelu

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
ignoruje - session vždy čte a zapisuje MariaDB. Klíč lze bez náhrady smazat.

Migrace `0145` přestavuje tabulku `sessions` (dvanáct nových sloupců, backfill
a tři indexy), takže po dobu jejího běhu je tabulka zamčená a přihlašování
nefunguje. Naměřeno na MariaDB 11.8: **~16 s na 300 000 session**, u běžných
instalací s jednotkami až stovkami řádků je to pod sekundu. Před upgradem se
vyplatí spustit `php api/bin/cron-cleanup.php`, ať se nepřestavují dávno
expirované řádky.

### 101.11.3 Brute-force ochrana

| Pokusy během | Akce |
|---|---|
| 5 selhání / 5 minut | CAPTCHA (Cloudflare Turnstile) |
| 10 selhání / 15 minut | Lockout 15 minut (per IP) |
| 30 selhání / 1 hodinu | Lockout 24 hodin + e-mail uživateli o pokusech |

Implementace: **Redis** pokud běží, jinak **MariaDB MEMORY engine** fallback.

### 101.11.4 IP allowlist (volitelné)

V `cfg.php → ip_allowlist.allow` můžete omezit přístup jen na vybrané IP /
CIDR rozsahy.

```php
'ip_allowlist' => [
    'enabled' => true,
    'mode' => 'block',           // 'block' = ne-allowlisted IP dostane 403
    'allow' => [
        '127.0.0.1',
        '203.0.113.42',          // vaše kancelářská WAN (IPv4)
        '2001:db8:1234::/48',    // IPv6 prefix
    ],
],
```

Doporučení v produkci:

- Vaše kancelářská IP
- VPN endpoint (pokud ho používáte)
- Rezervní mobilní hotspot pro nouzový přístup

> [!TIP]
> IP allowlist je v `cfg.php` (file-based config) → změna vyžaduje SSH /
> deploy. Není v UI **schválně** - v případě omylu byste se zablokovali
> a nemohli ho přes UI sundat.

#### 101.11.4.1 Za reverse proxy: `trusted_proxies` (důležité)

Pokud aplikace běží **za reverse proxy** (doporučené produkční nasazení - viz
[Instalace Docker](03_Instalace_Docker.md)), vidí všechny požadavky přicházet z IP proxy (např. brána Dockeru
`172.x.0.1`), ne od reálného klienta. Bez konfigurace pak:

- **IP allowlist** filtruje podle IP proxy - buď zablokuje všechny, nebo (když
  přidáte proxy do `allow`) pustí všechny → ochrana je neúčinná.
- **Brute-force lockout** (viz § 101.11.3) je fakticky **globální** - všechny pokusy
  vypadají ze stejné IP.
- **Audit log** loguje IP proxy místo reálného klienta (ztráta forenzní hodnoty).

Proto za reverse proxy uveďte proxy do `trusted_proxies` - aplikace pak vezme
skutečnou klientskou IP z hlavičky `X-Forwarded-For`:

```php
'ip_allowlist' => [
    'trusted_proxies' => [
        '172.16.0.0/12',         // Docker bridge sítě
        // '10.0.0.0/8',         // nebo konkrétní IP/rozsah vaší proxy
    ],
    'header' => 'X-Forwarded-For', // výchozí; odkud číst reálnou IP (jen za trusted proxy)
],
```

> [!WARNING]
> Do `trusted_proxies` patří **jen** IP/rozsahy proxy, kterým věříte -
> klient za nedůvěryhodnou proxy by jinak mohl `X-Forwarded-For` podvrhnout.
> Aplikace hlavičku respektuje pouze tehdy, když `REMOTE_ADDR` odpovídá
> `trusted_proxies`.

#### 101.11.4.2 Edge proxy MUSÍ `X-Forwarded-For` přepisovat, ne appendovat

Tohle je **nejčastější a nejzávažnější chyba** v nasazení za proxy. `X-Forwarded-For`
je obyčejná klientská hlavička - kdokoli ji může poslat s libovolným obsahem:

```
curl -H 'X-Forwarded-For: 203.0.113.42' https://vas-server/api/...
```

Aplikace chain prochází **zprava** a odloupává známé trusted hopy, takže podvržené
položky *nalevo* jsou neškodné. Ale to je bezpečné **jen tehdy, když edge proxy
klientskou hodnotu zahodí**. Když ji jen appenduje (nebo ji nesahá vůbec), zůstane
v chainu obsah od útočníka a ten si může zvolit, jakou IP aplikace uvidí →
**obejití IP allowlistu**, obejití brute-force lockoutu a **podvržené auditní logy**.

Edge proxy = ta, která jako **první** přijímá provoz z internetu. Musí být
nastavená takto:

| Proxy | Správně (přepisuje) | Špatně (appenduje) |
|---|---|---|
| nginx | `proxy_set_header X-Forwarded-For $remote_addr;` | `proxy_add_x_forwarded_for` |
| Apache `mod_proxy` | `RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}s"` (před `ProxyPass`) | výchozí chování `mod_proxy_http` |
| HAProxy | `option forwardfor header X-Forwarded-For if-none` **+** `http-request del-header X-Forwarded-For` před ním | samotné `option forwardfor` |
| Traefik | `forwardedHeaders.trustedIPs` (mimo seznam se hlavička zahazuje) | `forwardedHeaders.insecure = true` |
| Cloudflare | přepisuje automaticky (nebo použijte `CF-Connecting-IP`) | - |

> [!WARNING]
> **Řetězíte-li víc proxy**, tohle pravidlo platí jen pro tu **nejkrajnější**.
> Vnitřní hopy smí appendovat - musí ale být všechny uvedené v `trusted_proxies`,
> aby je aplikace uměla odloupnout.

**Ověření** (z internetu, ne z LAN):

```bash
curl -H 'X-Forwarded-For: 1.2.3.4' https://vas-server/api/health
```

V audit logu (`Systém → Log`) musí být vaše **reálná** IP, ne `1.2.3.4`.
Pokud vidíte `1.2.3.4`, edge proxy hlavičku nepřepisuje a máte otevřený bypass.

##### Dodávaný Docker image

Image tenhle problém řeší i **bez** `trusted_proxies`: nginx uvnitř kontejneru
předává PHP skutečnou IP TCP peera v parametru `MYUCTO_CLIENT_IP`
(`fastcgi_param` **bez** prefixu `HTTP_`). Klientské hlavičky se do FastCGI vždy
mapují jako `HTTP_*`, takže tenhle parametr **nelze zvenčí podvrhnout** a
aplikace ho preferuje před `X-Forwarded-For`.

Když je ale před kontejnerem ještě další proxy, je „TCP peer" právě ona. Pak v
`docker/nginx.conf` odkomentujte blok `set_real_ip_from` a vyjmenujte rozsahy té
proxy - teprve tím se `MYUCTO_CLIENT_IP` přepočítá na reálného klienta:

```nginx
set_real_ip_from  173.245.48.0/20;   # rozsahy vaší edge proxy
real_ip_header    X-Forwarded-For;
real_ip_recursive on;
```

U IP omezení API tokenu aplikace zobrazuje IP aktuálního prohlížeče tak, jak
ji vyhodnocuje při kontrole přístupu. U privátní, lokální nebo sdílené adresy
upozorní na možné chybějící předávání IP. V interní síti může být tato adresa
správná; integrace na jiném serveru může používat jinou IP než prohlížeč.

Upravený nginx konfigurák je nutné připojit také do služby aplikace v souboru
Docker Compose (vedle ostatních `volumes`):

```yaml
volumes:
  - ./docker/nginx.conf:/etc/nginx/nginx.conf:ro
```

Použijte soubor odpovídající nasazenému image a po změně mountu znovu vytvořte
kontejner aplikace. Do `set_real_ip_from` patří pouze skutečné důvěryhodné proxy,
nikdy celý internet (`0.0.0.0/0` nebo `::/0`). Edge proxy musí příchozí klientské
hlavičky bezpečně přepsat; postup ověření je výše.

### 101.11.5 RBAC (role-based access)

Klientské role mohou mít samostatná oprávnění **Prohlížet banku**, **Nahrávat výpisy**, **Párovat platby** a **Ignorovat platby**. Administrátor je přiděluje jednotlivě, ve výchozím stavu jsou vypnutá. Zapnutí akčního oprávnění zároveň zapne čtení banky; odebrání čtení odebere i bankovní akce. Pro párování a jeho zrušení musí mít klient také čtení všech dotčených faktur a zápis do jejich úhrad: `invoices.mark_paid` u vydaných, `purchase_invoices.transition` u přijatých. Samotné čtení dokladů nestačí. Nahrávání výpisů navíc vyžaduje zápis do obou oprávnění k úhradám, jinak by automatické párování při importu obešlo kontrolu ručního párování. Párování a import mohou spustit automatické účetní zpracování podle nastavení firmy; úhrady a účetní zápisy vznikají pod přihlášeným klientským uživatelem. Tato klientská omezení nemění oprávnění interních rolí.

Role se spravují v `Systém → Role a oprávnění`. Každý modul a významná akce mají jednu
ze tří úrovní: **neviditelné**, **pouze čtení** nebo **zápis**. Zápis zahrnuje
čtení; chybějící nebo neznámé oprávnění znamená zákaz.

Role typu **staff** jsou pro interní pracovníky. Role typu **client** mohou
dostat jen katalogem povolené funkce klientského portálu. Předdefinované role
**Admin** a **Admin Plus** stojí mimo editovatelnou matici a mají pevný plný
přístup k přiděleným firmám. Admin Plus navíc zakládá firmy, ke kterým
automaticky získá práva Admin. **Superadmin** má plný přístup ke všem firmám
a jako jediný spravuje uživatele, role a globální administraci.

Každý non-superadmin potřebuje explicitní membership firmy. U jedné firmy může
mít kompatibilní přepis role; role se nesčítají. Neaktivní role, neplatný přepis
nebo prázdný membership jsou vždy fail-closed.

#### 101.11.5.1 Jak je to vynucené

1. **Backend** mapuje každou neveřejnou routu na konkrétní permission klíč a
   minimální úroveň. Nezmapovaná routa je odmítnuta; stavové, tenant a vlastnické
   guardy se kontrolují navíc.
2. **API token (PAT)** má průnik oprávnění vlastníka pro aktuální firmu a scope
   tokenu. Scope `read` nikdy nepovolí zápis; odebrání firmy nebo snížení role se
   projeví existujícímu tokenu okamžitě.
3. **UI** používá stejnou efektivní matici pro menu, přímé URL a skrytí akcí.
   Po přepnutí firmy stará práva zahodí a před vykreslením načte nová.

### 101.11.6 CSRF + Origin check

Každý mutating request (POST / PUT / PATCH / DELETE) musí mít:

1. **Origin header** se shodující s přesným originem bezpečně rozpoznané domény
2. **X-CSRF-Token** header se shodující s tokenem v session

Na canonical hostu je očekávaný origin odvozený z `app.url`; na aktivní vlastní
doméně je to výhradně `https://<její-hostname>`. Jiný port, koncová tečka,
podvržený `Host`, neaktivní alias ani origin jiné firmy neprojde.

Bez nich → 403 `csrf_failed` / `origin_mismatch`. UI to obsluhuje
automaticky (token v Pinia store, header v axios interceptoru).

### 101.11.7 Activity log

Každá mutace (vytvoření / změna / vystavení / smazání) se loguje. Záznamy
obsahují:

- Akce (`invoice.created`, `invoice.issued`, `client.updated`, `auth.login_success`,
  `auth.login_failed`, `bank.statement_imported`, `currency.updated`, …)
- Uživatel (NULL pro neautentizované akce jako neúspěšné login)
- Entita (typ + ID)
- IP adresa (binární `VARBINARY(16)` - IPv4 i IPv6)
- User-Agent
- Payload - JSON s relevantními detaily (např. fields=`['email', 'name']`
  u `client.updated`)
- Datum + čas

Viz [96. Nastavení](96_Nastaveni.md) pro UI.

#### 101.11.7.1 Co log NEUKLÁDÁ

- **Hesla** - ani staré, ani nové
- **PII klientů** mimo to, co bylo změněno (jen fields seznam, ne hodnoty)
- **Bankovní transakce** - log obsahuje jen ID importovaného výpisu

#### 101.11.7.2 Jak se do logu zapisuje IP adresa

Aplikace bere IP klienta z **IP síťového spojení** (`REMOTE_ADDR`). Když běží
**za reverse proxy** (Docker, nginx, Cloudflare…), je tím spojením proxy - bez
konfigurace by se proto do auditu zapisovala **IP proxy**, ne reálného klienta
(typicky uvidíte pořád stejnou IP, např. bránu Dockeru `172.x.0.1`).

Reálnou IP přečte aplikace z hlavičky `X-Forwarded-For` **pouze tehdy**, když
`REMOTE_ADDR` odpovídá rozsahu v `cfg.ip_allowlist.trusted_proxies` (viz
§ 101.11.4.1). Z hlavičky se bere **první** adresa (původní klient). Bez nastavené
`trusted_proxies` se `X-Forwarded-For` ignoruje (ochrana proti podvržení).

> [!TIP]
> Stejná logika se zjišťování IP používá i pro **brute-force lockout**
> (viz § 101.11.3). Za reverse proxy bez `trusted_proxies` proto lockout počítá
> pokusy podle IP proxy = fakticky globálně. Po nastavení `trusted_proxies`
> začnou audit log i lockout pracovat s reálnou klientskou IP.

### 101.11.8 DKIM podpis e-mailů

Pro **deliverabilitu** (aby gmail / o365 / seznam vaše maily nepoznačily jako
spam) doporučujeme aktivovat DKIM:

1. Vygenerujte RSA klíč: `openssl genrsa -out private/dkim/myucto.pem 2048`
2. Public key → DNS TXT záznam `myucto._domainkey.vase-domena.cz`
3. V `cfg.php → smtp.dkim.enabled => true`
4. Restart služby

Detaily v `README.md` v rootu repa.

### 101.11.9 Klávesové zkratky

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
[Přehledu](10_Prehled.md#101011-klavesove-zkratky).

### 101.11.10 Anonymizovaná kopie pro testovací instanci

Pro ladění, školení nebo předání dat vývojáři se hodí kopie skutečné
databáze, ve které ale nejsou osobní ani obchodní údaje. Vyrobí ji příkaz
`anonymize-clone`:

```bash
# Linux / Docker
cmd/anonymize-clone.sh --from=myucto --to=myucto_anon
# Windows
cmd\anonymize-clone.ps1 --from=myucto --to=myucto_anon
```

Originální databáze se jen čte. Kopie vznikne jako **nová databáze** na témž
serveru; do databáze, se kterou instance pracuje, zapsat nejde. Existující
cíl se přepíše jen volbou `--replace`, a to jen tehdy, když je to předchozí
anonymizovaná kopie.

**Co se v kopii změní:**

| Údaj | Náhrada |
|---|---|
| názvy partnerů, jména osob, adresy | vymyšlené, právní forma (s.r.o., a.s.) a rod příjmení zůstávají |
| IČO, DIČ | jiné, platné podle kontrolní číslice; DIČ navazuje na nové IČO |
| rodná čísla | datum narození a pohlaví zůstávají, koncovka je jiná a číslo platné |
| čísla účtů, IBAN | jiná platná čísla, kód banky a předčíslí zůstávají |
| e-maily, telefony, datové schránky | vymyšlené (e-maily v doméně `example.invalid`) |
| texty dokladů, poznámky, protokoly převodů, auditní stopa | tatáž jména a čísla nahrazená týmiž pseudonymy |
| přílohy a výpisy uložené v databázi | zástupný soubor (prázdné PDF, obrázek 1×1 px, text) |
| hesla, API klíče, tokeny, certifikáty, šifrované archivy podání | zneplatněné |
| relace, přihlašovací kódy, fronta odchozí pošty, licence | vyprázdněné |

Pseudonym je v rámci jednoho běhu **stejný všude**: partner má v kontaktu,
na faktuře, v bankovní transakci i v textu úhrady tentýž nový název a IČO,
takže párování plateb a výkazy fungují jako v originále. Částky, data, čísla
dokladů a vazby se nemění - rozvaha, výsledovka i přiznání k DPH dávají stejná
čísla. Veřejné účty institucí (finanční úřad, ČSSZ, pojišťovny, platební brány)
zůstávají, aby aplikace dál poznala platby odvodů.

Klíč pseudonymizace je pro každý běh náhodný a nikam se neukládá, takže
z kopie nejde originál dopočítat. Volba `--seed=TEXT` dá stejné pseudonymy
i v dalším běhu, ale se známým seedem jde pseudonym zpětně dohledat -
používejte ji jen na vývojovém stroji.

**Po vytvoření kopie:**

- Odchozí integrace jsou vypnuté (banky, e-mailové profily, ISDS, e-shopy)
  a přístupové údaje k nim smazané. Testovací instance proto nic neodešle.
- Hesla uživatelů jsou nepoužitelná. Nové nastaví
  `MYINVOICE_DB_NAME=myucto_anon php api/bin/set-password.php <e-mail>`,
  nebo rovnou volba `--password=…` (všem uživatelům stejné heslo).
  Přihlašovací e-maily kopie vypíše příkaz na konci běhu.
- Dvoufázové ověření je vypnuté; licenci je nutné aktivovat pro testovací
  instanci zvlášť.
- Šifrované mzdové údaje jsou znovu zapečetěné klíčem instance, na které
  příkaz běžel. Testovací instance s jiným `secret_encryption_key` je
  nerozšifruje.
- Archivní snímky podání (mzdová podání, EPO) jsou smazané, jejich otisky
  zůstaly - archiv podání v kopii proto hlásí nečitelné snímky. Auditní stopa
  je po pseudonymizaci zapečetěná znovu a dokazuje jen integritu kopie.

**Soubory a dump:**

- `--files-out=ADRESÁŘ` vytvoří zrcadlo úložiště (`storage/`) se stejnou
  strukturou, ve kterém je místo každé přílohy a skenu zástupný soubor téhož
  typu. Cesty v kopii databáze na ně sedí. Adresář pak nastavte testovací
  instanci jako `MYINVOICE_DATA_DIR/storage`.
- `--dump=SOUBOR` po dokončení uloží SQL dump kopie (potřebuje
  `mariadb-dump`, jinou cestu zadá `--dump-bin=…`).

Co se s kterým sloupcem stane, určuje seznam v
`api/src/Service/Anonymization/AnonymizationPolicy.php`. Příkaz odmítne běžet,
když databáze obsahuje textový sloupec, o kterém seznam nerozhoduje - nová
funkce tak nemůže osobní údaje do kopie propašovat nepozorovaně. Na konci běhu
příkaz vypíše sloupce, ve kterých zůstala hodnota shodná s originálem
(typicky zachované účty institucí), aby šly zkontrolovat.

### 101.11.11 Tipy

- **Vždycky 2FA pro admin** - pokud admin účet padne, padá vše. Žádná výmluva.
- **Pravidelně obměňujte hesla** každých 6-12 měsíců.
- **IP allowlist** v produkci pro non-veřejné použití (B2B accounting).
- **Activity log review** - alespoň 1× za měsíc projděte podezřelé login
  selhání nebo neočekávané force-edit.
- **Backup `cfg.php` + `private/dkim/`** mimo repo - není v gitu, ztrátou
  přijdete o pepper a nepřihlásíte se ke starým heslům.

> [!TIP]
> **Vypršení licence vaše data neohrozí.** Bezplatné funkce původního
> MyInvoice zůstávají plně funkční včetně zápisu. Komerční moduly se skryjí
> i pro čtení a API, jejich data ale zůstávají beze změny ve vlastní databázi
> a po obnovení licence se znovu zpřístupní. Detail v
> [105. Licence a aktivace](105_Licence_a_aktivace.md).

## 101.12 Související kapitoly

- [Přihlášení](08_Prihlaseni.md)
- [Nastavení](96_Nastaveni.md)
- [Licence a aktivace](105_Licence_a_aktivace.md)
- [Řešení problémů](999_Reseni_problemu.md)
