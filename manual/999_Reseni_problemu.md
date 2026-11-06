# 999. Řešení problémů (FAQ)

> Rozcestník pro chvíli, kdy se něco chová jinak, než čekáte: přihlášení, faktury,
> e-maily, banka, exporty, plánované úlohy, výkon a diagnostika. Pro uživatele
> a hlavně pro správce instalace.

## 999.1 Kdy to potřebujete

- Nemůžete se přihlásit, ztratili jste heslo, passkey nebo TOTP zařízení.
- Aplikace hlásí chybu nebo varování a nevíte proč.
- Faktura, e-mail, bankovní výpis nebo export se nechová podle očekávání.
- Plánovaná úloha neběží nebo Diagnostika hlásí, že je zaseklá.
- Potřebujete problém nahlásit a připravit k tomu podklady.

<!-- cols: 38 36 26 -->
| Co se děje | Kam se podívat | Oddíl |
|---|---|---|
| Nedorazil e-mail pro obnovu hesla | Spam, nastavení SMTP, nouzový příkaz správce | [§ 999.5.1.1](#999511-zapomenute-heslo) |
| „Origin nesedí s app URL" | Adresa v konfiguraci neodpovídá adrese v prohlížeči | [§ 999.5.1.2](#999512-origin-nesedi-s-app-url) |
| „Aplikace ještě není inicializována" | Neproběhl setup wizard | [§ 999.5.1.3](#999513-aplikace-jeste-neni-inicializovana-http-423) |
| Zablokovaný přístup po chybných pokusech | Počkat, nebo reset správcem | [§ 999.5.1.4](#999514-lockout-po-brute-force) |
| Passkey nebo odemčení aplikace nefunguje | Hostname, HTTPS, zařízení | [§ 999.5.1.5](#999515-passkey-nefunguje-nebo-se-nezobrazuje-systemovy-dialog), [§ 999.5.1.6](#999516-odemceni-pwa-selze-nebo-je-zarizeni-offline) |
| Ztratili jste passkey nebo TOTP | Záložní kódy, reset správcem | [§ 999.5.1.7](#999517-ztratil-jsem-passkey-nebo-totp-zarizeni) |
| Varování v administraci o konfiguraci | Klíč, MFA, zámek session | [§ 999.5.1.9](#999519-varovani-secretencryptionkey-spatna-delka-klice) až [§ 999.5.1.12](#9995112-varovani-sessionlockconfiguration) |
| Nejde upravit vystavená faktura, chybí QR, špatné údaje v PDF | Vystavený doklad je neměnný | [§ 999.5.2](#99952-faktury) |
| Faktura klientovi nedorazila | Záznam o odeslání, SMTP, SPF a DKIM | [§ 999.5.3](#99953-e-maily) |
| Výpis se nenahrál, nesedí zůstatek, nepáruje se platba | Import výpisů a párování | [§ 999.5.4](#99954-banka) |
| Import do Pohody hlásí chybu | Kódy pro export | [§ 999.5.5](#99955-exporty) |
| Plánovaná úloha neběží | Systém → Diagnostika, Systém → Plánované úlohy | [§ 999.5.6](#99956-cron-a-automatika) |
| Pomalý dashboard nebo aplikace | Cache statistik, Redis, ladění | [§ 999.5.7](#99957-vykon) |
| Prázdný seznam klientů, hláška o jiném dodavateli | Data jsou oddělená po firmách | [§ 999.5.8](#99958-multi-supplier) |
| Nevíte, co je špatně | Diagnostika | [§ 999.3](#9993-krok-za-krokem-zjistit-pricinu-problemu) |
| Potřebujete kontaktovat podporu | Diagnostický balíček | [§ 999.4](#9994-krok-za-krokem-nahlasit-chybu) |
| Mzdová kontrola nebo výpočet se zastavil | Kontroly mzdové agendy | [§ 999.6.2](#99962-kontroly-mzdove-agendy) |

## 999.2 Než začnete

Připravte si:

1. **Přesný text hlášky** a čas, kdy se objevila.
2. **Oprávnění správce** pro kroky označené jako správcovské (Diagnostika,
   Plánované úlohy, Aktualizace, konfigurace). Běžný uživatel se při problému obrací
   na správce.
3. **Přístup k serveru** u příkazů příkazové řádky: soubory `cfg.php`
   a `cfg.local.php`, adresář `log/` a spouštění skriptů `php api/bin/…`.
4. Po ruce **kontakt na správce** a vědět, zda jde o provoz na Windows (IIS), Linuxu,
   nebo v Dockeru.

> [!TIP]
> Většinu problémů se správným provozem odhalí `Systém → Diagnostika`. Začněte tam,
> než budete upravovat konfiguraci.

## 999.3 Krok za krokem: zjistit příčinu problému

1. Otevřete `Systém → Diagnostika`. Stránka ukáže verdikt **vyhovuje**,
   **vyhovuje s výhradami** nebo **nevyhovuje**. U každého nálezu je dopad, náprava
   a odkaz do příslušné kapitoly manuálu.
2. Projděte nálezy shora dolů. Jsou seřazené od problémů k varováním, tedy v pořadí,
   v jakém dává smysl je řešit.
3. Podle nálezu postupujte podle odkazu. Nejčastější případy popisuje
   [§ 999.5](#9995-kdyz-neco-nejde).
4. Chování konkrétního uživatele dohledáte v `Systém → Log`.
5. Podrobnosti aplikačních chyb jsou v souboru `log/app-RRRR-MM-DD.log`. Je-li
   nastavené `MYINVOICE_DATA_DIR`, je log v jeho podsložce `log`.
6. Po opravě stránku Diagnostiky obnovte a ověřte, že verdikt je lepší.

**Jak poznáte, že je hotovo:** nález zmizel, nebo se přesunul mezi informace.
Co Diagnostika kontroluje, popisuje [§ 999.6.1](#99961-diagnostika).

## 999.4 Krok za krokem: nahlásit chybu

Pokud problém nevyřeší tato kapitola, kontaktujte:

- **GitHub Issues** repozitáře MyÚčto.cz,
- vývojáře, e-mail viz `cfg.php → smtp.from`,
- IT administrátora vaší organizace,
- podporu: `Systém → Podpora` je rozcestník, co je zdarma, co se platí, a odkaz na
  portál podpory, na kterém se placená instalace přihlásí sama (licenční klíč nikam
  nezadáváte).

Postup pro přípravu podkladů:

1. Otevřete `Systém → Diagnostika` a vytvořte **diagnostický balíček** (podrobnosti
   v [§ 999.6.1.1](#999611-diagnosticky-balicek)). Pokryje verzi, prostředí, stav
   migrací i plánovaných úloh najednou.
2. Chcete-li přiložit logy, zaškrtněte je a nejdřív si prohlédněte jejich obsah
   ([§ 999.6.1.2](#999612-logy-v-balicku)).
3. Přidejte popis kroků, jak chybu zopakovat, prohlížeč a operační systém
   a snímek obrazovky.
4. Balíček stáhněte a přiložte k incidentu na portálu podpory. Aplikace ho
   nikam neodesílá.

**Jak poznáte, že je hotovo:** incident na portálu obsahuje balíček, popis a čas
výskytu.

## 999.5 Když něco nejde

### 999.5.1 Přihlášení

#### 999.5.1.1 Zapomenuté heslo

Na přihlašovací stránce klikněte na **Zapomenuté heslo?**, zadejte e-mail a klikněte na
odkaz v e-mailu (platnost 1 hodina).

Pokud e-mail nedorazí:

- Zkontrolujte spam.
- Ověřte u správce, že je nakonfigurované SMTP (`cfg.php → smtp.*`).
- Krajní řešení: správce spustí `php api/bin/set-password.php vas@email.cz`.

#### 999.5.1.2 „Origin nesedí s app URL"

Kontrola CSRF selhala. Příčiny:

- **`cfg.php → app.url`** nesedí s adresou, na kterou chodíte. Příklad: chodíte na
  `http://localhost:8080`, ale v konfiguraci je `https://dev.example.com`. Opravte
  konfiguraci.
- Reverzní proxy nebo IIS bez správně nastavené hlavičky Host. Zkontrolujte, že
  server vidí původní hostname.
- **Docker z jiného hostu než `localhost`** (například LAN IP serveru
  `http://10.0.0.8:8080`). První setup je z libovolného hostu povolen a `app.url` se
  uloží automaticky podle adresy, kterou v průvodci použijete. Alternativa: spusťte
  kontejner s `-e MYINVOICE_APP_URL=http://10.0.0.8:8080`, nebo po `docker run`
  upravte `cfg.php` přímo v kontejneru.

#### 999.5.1.3 „Aplikace ještě není inicializována" (HTTP 423)

Setup wizard ještě neproběhl. Otevřete `/setup` v prohlížeči.

Pokud setup wizard nefunguje (špatně nakonfigurovaná databáze):

```bash
php api/bin/migrate.php --status     # zkontrolujte, že DB má migrace
php api/bin/setup.php                # interaktivní náhrada z příkazové řádky
```

#### 999.5.1.4 Lockout po brute-force

Po 10 neúspěšných pokusech za 15 minut jste zablokovaní na 15 minut. Po 30 za hodinu
na 24 hodin. Počkejte, nebo požádejte správce o reset z databáze:
`DELETE FROM login_attempts WHERE bucket_key LIKE '%vas_email%';`

#### 999.5.1.5 Passkey nefunguje nebo se nezobrazuje systémový dialog

Zkontrolujte:

- aplikaci otevíráte přes přesný hostname z `cfg.php → app.url`,
- v produkci používáte důvěryhodné HTTPS, pro lokální vývoj je povolené pouze
  `http://localhost`,
- prohlížeč a zařízení podporují WebAuthn a mají nastavený zámek obrazovky,
- dialog spouštíte explicitním tlačítkem na viditelné stránce.

Když se po kliknutí nic neděje a stránka vypadá zatuhle, čeká se na systémový dialog,
který se nemusel zobrazit. Otevřel se za oknem prohlížeče, na jiném monitoru, nebo si
volání převzal správce hesel (Keeper, 1Password, Bitwarden) a jeho okno se nevykreslilo.
Po několika sekundách se dole objeví panel **Čekám na potvrzení bezpečnostního
dialogu** s tlačítkem **Zrušit čekání**. Tím se akce ukončí hned a jde ji zopakovat nebo
přepnout na TOTP. I bez zásahu se čekání samo ukončí po asi 2 minutách chybovou
hláškou. Když panel hlásí, že WebAuthn obsluhuje rozšíření, zkuste ho pro tuto doménu
vypnout.

Passkey registrovaná na starém hostname nebude po změně domény fungovat. Přihlaste se
pomocí TOTP nebo jiné passkey dostupné pro původní origin a zaregistrujte nový klíč.
Pokud žádná cesta obnovy nezůstala, použijte nouzový postup správce níže
([§ 999.5.1.7](#999517-ztratil-jsem-passkey-nebo-totp-zarizeni)).

Přihlášený správce uvidí neplatnou WebAuthn konfiguraci také jako provozní upozornění
na stránce `Systém → Aktualizace`. Běžné přihlášení heslem a TOTP zůstává dostupné,
dokud se `app.url` neopraví.

#### 999.5.1.6 Odemčení PWA selže nebo je zařízení offline

Odemčení vyžaduje spojení se serverem pro vydání a ověření jednorázové výzvy. Zrušení
dialogu, neplatná passkey nebo offline stav ponechá session zamčenou. Zkontrolujte
připojení a akci zopakujte tlačítkem **Odemknout přístupovým klíčem**. Případně se
odhlaste tlačítkem **Odhlásit** a přihlaste se znovu. Aplikace nejprve bezpečně ukončí
zamčenou session.

Rozpracovaný formulář zůstane zachovaný jen dokud stránka zůstává v paměti. Pokud
Android stránku ukončil, neuložená data nelze ze zámku obnovit.

#### 999.5.1.7 Ztratil jsem passkey nebo TOTP zařízení

Použijte jinou passkey, TOTP nebo jeden z dříve uložených **záložních kódů**. Každý
záložní kód funguje jen jednou. Po přihlášení zkontrolujte zbývající počet a s funkčním
silným faktorem si v profilu případně vygenerujte novou sadu.

Pokud není dostupný žádný silný faktor ani záložní kód, správce spustí obnovu z příkazové
řádky:

```bash
php api/bin/reset-mfa.php vas@email.cz
```

Reset vypne TOTP, odvolá passkeys, smaže důvěryhodná zařízení, čekající ověřovací procesy
i záložní kódy, zruší API tokeny a MCP připojení a zneplatní všechny session. Detail včetně příkazů pro Docker je v
[§ 101.11.2.4](101_Bezpecnost.md#1011124-obnova-pristupu). Neupravujte jen sloupce TOTP
ručně v databázi: ponechali byste aktivní další faktory a session.

#### 999.5.1.8 Diagnostika `app.url`

V běžném provozu ověřujte kanonickou adresu přes přesný origin nastavený v `app.url`.
Například pro `app.url = https://faktury.example.cz`:

```bash
curl --fail --silent --show-error https://faktury.example.cz/api/v1/health
```

Pokud prázdný řetězec složený jen z mezer nebo jiná neprázdná neplatná hodnota zablokuje
běžné stránky (kontrola hostitele), lze stejný přesný endpoint dočasně zavolat přes
jiný hostname, který přijímá reverzní proxy, případně ze serveru či kontejneru. Tato
výjimka neplatí pro žádnou jinou aplikační cestu a neobchází zapnutý seznam povolených
IP adres. Během nedokončeného prvního setupu používejte `GET`, protože setup metodu
`HEAD` nepovoluje.

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
| `missing` | `app_url_missing` | Hodnota chybí, je přesně prázdná nebo obsahuje jen mezery. |
| `invalid` | `app_url_invalid_origin` | Hodnota není samostatný HTTP(S) origin. Starší rozpoznávání může uznat jen hostname, který z ní ještě bezpečně vyčte, nikdy ne libovolný hostitel požadavku. |
| `routing_only` | `app_url_webauthn_incompatible` | Běžné směrování funguje, WebAuthn ne. |
| `hostname_conflict` | `app_url_hostname_conflict` | Hostname z `app.url` je současně uložený jako vlastní doména firmy, běžné cesty jsou bezpečně odmítnuté. |
| `webauthn_ready` | `app_url_valid` | Hodnota vyhovuje směrování i WebAuthn. |

`app.url` nastavte na přesný origin: schéma `http` nebo `https`, hostname a volitelný
port. Nesmí obsahovat userinfo (`jmeno:heslo@`), cestu, query ani fragment. Běžné
rozhraní dál podporuje HTTP a LAN IP adresy. Passkeys mají užší pravidlo: vyžadují HTTPS
a DNS hostname, jediná výjimka pro HTTP je `http://localhost`.

Při `hostname_conflict` vraťte `app.url` na předchozí kanonický origin. Potom kolidující
záznam v sekci **Klientské domény** (`Firma → Nastavení`, záložka **Údaje firmy**) deaktivujte a smažte, nebo pro
`app.url` zvolte jiný hostname. Dokud kolize trvá, aplikace na kanonickém hostname
zpřístupní pouze přesné `GET` a `HEAD` zdravotní kontroly. Hostname ani údaje firmy se v
diagnostice nevracejí.

Při prvním setupu je chybějící, prázdná nebo jen mezerová hodnota v předběžné kontrole v
pořádku, průvodce ji doplní z adresy, přes kterou je otevřený. Stejně umí nahradit
známý distribuční zástupný text. Jinou explicitně neprázdnou neplatnou hodnotu kontrola
označí jako problém a setup ji nepřepíše. Po dokončení setupu je chybějící hodnota také
problém a musí se opravit ručně v `cfg.php`, `cfg.local.php` nebo přes
`MYINVOICE_APP_URL`.

Chybějící nebo přesně prázdná hodnota zachovává dosavadní náhradu, ve které se platný
hostname požadavku považuje za kanonický. Hodnota jen z mezer a jiná neprázdná
neplatná hodnota tuto náhradu nemá: kontrola hostitele pro ně přes cizí hostname
povoluje výhradně přesné `GET` a `HEAD` zdravotní kontroly. POST, jiné API, přihlášení
ani ostatní aplikační cesty výjimku nedostanou. Po opravě sledujte health znovu přes
hostname z `app.url`, ne přes adresu pro obnovu.

Stav s `routing_compatible: false` se v serverovém logu hlásí jako
`configuration.app_url_unusable` pouze se stabilními poli `state` a `reason_code`. Log
záměrně neobsahuje nastavenou URL ani žádnou její odvozenou část. Umístění logu určuje
`logging.path`, provozní souhrn je také v
[§ 101.11.2.1](101_Bezpecnost.md#provozni-diagnostika-canonical-appurl).

#### 999.5.1.9 Varování `secret_encryption_key` (špatná délka klíče)

Backend vrací v `GET /api/health` pole `warnings[]` a správce vidí upozornění i v
aplikaci (`Systém → Aktualizace`), pokud je problém s `app.secret_encryption_key`
(typicky omyl: 24 bajtů místo 32).

Opravte konfiguraci v `cfg.php` nebo `cfg.docker.php`:

```bash
openssl rand -base64 32
```

Vygenerovanou hodnotu uložte do `app.secret_encryption_key`. Klíč musí být base64, který
po dekódování dává přesně 32 bajtů.

#### 999.5.1.10 Varování `mfa_methods_configuration`

V `auth.allowed_mfa_methods` (nebo `MYINVOICE_AUTH_MFA_METHODS`) je neznámá hodnota.
Podporované jsou pouze `passkey` a `totp`. E-mailové OTP sem nepatří, zapíná se přes
`auth.email_otp.enabled`. Aplikace kvůli tomu nespadne, jen dočasně jede na výchozím
seznamu `['passkey', 'totp']`. Opravte seznam v `cfg.php`.

#### 999.5.1.11 Varování `session_lock_without_unlock_method`

`session.lock_after_minutes` je kladné, ale někteří aktivní uživatelé nemají passkey.
Zamčenou session jde odemknout **jen passkey**, takže se z ní dostanou pouze odhlášením
(a přijdou o rozepsaný formulář). Buď jim zaregistrujte passkey (**Profil → Přístupové
klíče**), nebo nastavte `session.lock_after_minutes = 0` a nechte volbu intervalu na
jednotlivých uživatelích.

#### 999.5.1.12 Varování `session_lock_configuration`

`session.lock_after_minutes` není celé číslo 0 až 1440. Výchozí automatický zámek je
proto vypnutý, osobní intervaly uživatelů platí dál.

### 999.5.2 Faktury

#### 999.5.2.1 Nemůžu editovat vystavenou fakturu

Je to záměr. Vystavená faktura je **neměnná** (uchovává údaje dodavatele, klienta
a banky v okamžiku vystavení). Potřebujete-li změnu:

- **Drobná chyba (překlep, špatná částka):** správce otevře detail faktury a klikne na
  **Upravit (force)**. Vyžaduje roli správce a zapíše se do logu (`Systém → Log`).
- **Klient ji ještě nedostal:** udělejte **Storno** (interní) a novou fakturu.
- **Klient ji už dostal:** udělejte **Dobropis** (oficiální oprava) a novou fakturu.

#### 999.5.2.2 Klonování nebo „Vystavit znovu" inkrementuje měsíc špatně

Inkrement funguje pro popisy obsahující vzor `M/RRRR` (například „Konzultace 3/2026"
→ „Konzultace 4/2026"). Máte-li vzor jiný (například „březen 2026"), musíte měsíc
upravit ručně.

#### 999.5.2.3 QR platba se na PDF nezobrazuje

Bankovní účet musí projít **kontrolou mod-11** (české účty) nebo **kontrolním součtem
IBAN** (EUR). Zkontrolujte v `Peníze → Bankovní účty`, záložka **Měny a účty**, sekce
**Měny + bankovní účty**, jestli máte platný účet. Příklad platného českého testovacího
účtu: `1000000005 / 0100`.

#### 999.5.2.4 Faktura má v PDF špatné údaje dodavatele

Vystavená faktura si uchovává údaje dodavatele z okamžiku vystavení. Pokud jste po
vystavení změnili údaje dodavatele (logo, adresa), faktura zůstává s původními. **Je to
zamýšlené**, vystavený doklad nelze měnit.

Potřebujete-li regenerovat PDF s novými údaji (například jste opravili překlep v názvu
firmy), použijte jako správce **Upravit (force)**.

### 999.5.3 E-maily

#### 999.5.3.1 Faktura odešla, ale klient ji nedostal

1. V `Systém → Log` zkontrolujte záznam o odeslání faktury, měl by být
   s adresou klienta.
2. Zkontrolujte log SMTP serveru (mailhog nebo SMTP relay).
3. Klient ať zkontroluje spam.
4. Pošlete **Test odeslání** na svůj e-mail. Pokud nedorazí, problém je v konfiguraci
   SMTP.

#### 999.5.3.2 „Test odeslání" funguje, ale klientovi nic nechodí

- E-mail klienta v MyÚčtu je špatně (překlep): upravte ho v detailu klienta.
- Klient má restriktivní spam filtr: zkontrolujte, jestli máte správně nastavený SPF,
  DKIM a DMARC pro doménu, ze které posíláte.

#### 999.5.3.3 DKIM podpis se nedaří aktivovat

1. Vygenerujte klíče, viz [§ 101.11.8](101_Bezpecnost.md#101118-dkim-podpis-e-mailu).
2. Publikujte DNS TXT a počkejte 5 až 60 minut na propagaci.
3. Ověřte DKIM přes [mxtoolbox.com](https://mxtoolbox.com/dkim.aspx).
4. Až DNS funguje, zapněte v `cfg.php → smtp.dkim.enabled => true`.

### 999.5.4 Banka

#### 999.5.4.1 GPC výpis se nenahraje („tento výpis už byl importovaný")

Otisk SHA-256 souboru se shoduje s dříve importovaným výpisem. Buď:

- Výpis už je skutečně naimportovaný (zkontrolujte `Peníze → Bankovní účty`, záložku
  **Bankovní výpisy**).
- Stáhli jste stejný výpis dvakrát. Použijte jiný (nebo si vyžádejte z banky export s
  jiným časovým rozsahem).

#### 999.5.4.2 PDF výpis se nenahraje nebo nesedí zůstatek

PDF import je deterministický a podporuje aktuální rozvržení výpisů **Banky CREDITAS,
ČSOB a KB**. Naskenovaný obrázek bez textové vrstvy, PDF jiné banky nebo nové neznámé
rozvržení se neodhaduje pomocí AI a import se odmítne.

- Ověřte, že jde o originální PDF stažené z bankovnictví, ne tisk do PDF nebo sken.
- Zkontrolujte, zda hlavička obsahuje číslo účtu, období, počáteční a konečný zůstatek.
- Pokud součet transakcí nesedí na zůstatky na haléř, systém výpis neuloží. Chybějící
  pohyby nedoplňujte ručně, stáhněte úplný výpis za stejné období.
- U neznámé varianty rozložení přiložte k hlášení anonymizovaný vzor bez citlivých údajů
  nebo přesný popis banky a rozložení. Originál s čísly účtů neposílejte do veřejného
  issue.

#### 999.5.4.3 Auto-matching nefunguje

- Otevřete **Všechny pohyby** nebo detail výpisu a rozbalte důvody skórovaného návrhu.
  Bez variabilního symbolu může pomoci číslo faktury ve zprávě, zbývající částka, název,
  datum nebo dříve potvrzený účet protistrany.
- Automatická shoda vyžaduje nejméně 85 %, deterministický signál a náskok 15
  procentních bodů. Kandidát od 35 % se jen nabízí, slabší se nezobrazuje.
- Nový účet protistrany se stane důvěryhodným až po třech bezchybných ručních shodách.
  Chybné párování zrušte, tím se účet znovu nepoužije naslepo.
- Částka neodpovídá (částečná platba, přeplatek, kurz nebo bankovní poplatek): potvrďte
  ruční nebo rozdělené párování a zkontrolujte vzniklou alokaci.
- Faktura je v jiné měně než platba (klient pošle EUR na CZK fakturu): spárujte ručně
  a doúčtujte kurzový rozdíl.

Překlep ve variabilním symbolu, přeplatek, poplatek, rozdílná měna a zálohová faktura se
nikdy nepotvrdí automaticky, i kdyby ostatní signály byly silné.

#### 999.5.4.4 Pohyb není v „K zaúčtování"

Záložka **K zaúčtování** je pracovní fronta, ne úplný archiv. Pohyb najdete v záložce
**Všechny pohyby**, která zahrnuje i zaúčtované a ignorované transakce napříč výpisy.
Pokud pro nezaúčtovaný pohyb nevznikl vůbec žádný návrh, objeví se také v
`Účetnictví → K doúčtování` s důvodem „bez pravidla“ nebo „nepodporovaná cizí měna“.

#### 999.5.4.5 Vlastní převod se nespároval nebo nezaúčtoval přes 261

- Oba účty musí být v nastavení banky evidované jako vlastní účty stejné firmy.
- Automaticky se zpracují jen převody ve stejné měně. Převod mezi CZK a EUR je kvůli
  kurzu a kurzovému rozdílu ruční.
- V nastavení automatiky musí být povoleny jak **Převody mezi vlastními účty**, tak
  **Rozpoznávání vlastních převodů**.
- Druhá noha může přijít v jiném výpisu nebo období. Do té doby je zůstatek 261
  legitimně „na cestě“. Nevytvářejte duplicitní ruční zápis.

#### 999.5.4.6 Odvod finančnímu úřadu nebo pojišťovně čeká na potvrzení

Rozpoznání účtu u ČNB/0710 samo nestačí. Automatické zaúčtování odvodu je povolené jen
proti existujícímu zaúčtovanému předpisu a nejvýše do jeho kreditního zůstatku. Nejdřív
zaúčtujte předpis daně, sociálního či zdravotního pojištění. Nejasný variabilní symbol,
neznámé předčíslí nebo nedostatečný zůstatek ponechá položku v Automatu k ruční kontrole.

#### 999.5.4.7 Bankovní účet z výpisu „nepatří aktuálnímu dodavateli"

Ochrana oddělení firem: výpis musí být z účtu, který je v sekci **Měny + bankovní účty**
(`Peníze → Bankovní účty`, záložka **Měny a účty**) aktuální firmy. Chcete-li nahrát výpis pro jinou firmu, **přepněte
na ni** přepínačem v horní liště.

#### 999.5.4.8 Přímé načítání Fio skončí chybou 502

Zkontrolujte v aplikačním logu `log/app-RRRR-MM-DD.log` záznam `fio_statement_failed`
a následný `bank_connection_operation_failed`. Je-li nastavené `MYINVOICE_DATA_DIR`, je
log v jeho podsložce `log`. Záznam Fio uvádí fázi `request`, `read` nebo `gpc`, kód chyby
a případně důvod odmítnutí struktury GPC, HTTP stav a délku odpovědi. Neobsahuje token,
číslo účtu ani obsah výpisu. V Dockeru se aplikační souborový log nemusí objevit ve
výstupu `docker logs`. Při výchozím nastavení Docker Compose zkopírujete log na svůj
počítač příkazem `docker compose cp app:/data/log/app-RRRR-MM-DD.log .` (datum nahraďte
dnem pokusu).

Na soukromé testovací instalaci lze dočasně vložit `MYINVOICE_APP_ENV=development` do
souboru `.env` vedle konfigurace Docker Compose a aplikaci znovu vytvořit příkazem
`docker compose up -d app`. Po dalším pokusu pak log obsahuje také `bank_http_completed`
s HTTP stavem, dobou požadavku a síťovou diagnostikou. U chybové odpovědi Fio přidává
typ obsahu, délku a rozpoznaný formát těla (HTML, XML, JSON nebo jiný). Tělo odpovědi ani
token se nezapisují. Pro běžný provoz vraťte prostředí na `production`.

Pro tento typ chyby nezapínejte na veřejně dostupném serveru `app.debug`. Konektor chybu
zachytí a vrátí řízenou odpověď, takže přepnutí ladicího režimu nepřidá podrobnosti o
bankovním formátu. K nahlášení stačí anonymizovaný diagnostický řádek, čas pokusu
a období výpisu. Token ani výpis neposílejte.

### 999.5.5 Exporty

#### 999.5.5.1 ISDOC import do Pohody hodí chybu

ISDOC je univerzální standard, ale Pohoda má vlastní zvláštnosti. Doporučujeme spíš
**Pohoda XML export** (nativní formát), pro který je import spolehlivější.

#### 999.5.5.2 Pohoda XML import vyžaduje kódy

Před exportem nastavte v `Firma → Nastavení` na záložce **Daně a účetnictví** v sekci **Pohoda XML export (volitelné)**
účet, středisko, činnost, zakázku a předkontaci. Bez toho Pohoda hlásí při importu
varování.

#### 999.5.5.3 Měsíční PDF ZIP je velký (nad 100 MB)

Je to běžné při asi 100 fakturách měsíčně s druhou stranou výkazu. Chcete-li menší ZIP,
exportujte menší rozsah období (týden místo měsíce).

### 999.5.6 Cron a automatika

#### 999.5.6.1 Cron upomínek odeslal víc upomínek za den

Buď je cron spuštěný dvakrát (zkontrolujte `crontab -l` nebo Plánovač úloh), nebo je
`--cooldown` moc krátký. Výchozích 14 dní by nemělo pouštět víc než jednu upomínku na
fakturu za 14 dní.

#### 999.5.6.2 Bank scan cron neimportuje nové výpisy

1. Zkontrolujte, že soubory v `private/bank-incoming/` mají správný formát (ABO/GPC nebo
   podporované PDF, jiné XML ani sken PDF se neimportují).
2. Zkontrolujte práva služby nad `private/bank-incoming/` a `private/bank-archive/`.
   Na Windows ověřte identitu IIS nebo Plánovače úloh, na Linuxu vlastníka a oprávnění
   adresářů.
3. Spusťte ručně `php api/bin/cron-bank-scan.php` a zkontrolujte konkrétní chybu.

#### 999.5.6.3 Diagnostika hlásí zaseklé plánované úlohy

Kontrola **Plánované úlohy** v `Systém → Diagnostika` posuzuje jen úlohy, které na
instalaci mají co dělat. Úloha je **neaktivní** a jako zaseklá se nehlásí, když:

| Úloha | Neaktivní, dokud |
|---|---|
| `cron-bank-scan`, `cron-scan-purchase-inbox` | není nastavený adresář, který skenují |
| `cron-bank-connections` | neexistuje zapnuté bankovní napojení |
| `cron-bank-email-notices` | není zapnutá IMAP schránka bankovních avíz |
| `cron-logbook-odometer-source` | není žádné auto napojené na zdroj automatického stavu tachometru |
| `cron-catalog-worker` | nečeká katalogová úloha (sklad, ceník, import) a není napojený e-shop |
| `cron-epo-status` | nečeká žádné přímé podání EPO na stav |
| `cron-jmhz-poll` | nejsou zapnuté mzdy, nebo žádné mzdové podání nečeká na protokol ČSSZ |
| `cron-jmhz-source-monitor`, mzdové úlohy | nejsou u žádné firmy zapnuté mzdy |
| `cron-ai-worker` | žádná firma nemá zapnutého AI asistenta |
| `cron-payroll-post`, `cron-vat-clearing` | žádná firma nevede podvojné účetnictví |
| `cron-storage-usage` | instalace neběží ve spravovaném provozu |

Seznam neaktivních úloh i s důvodem ukazuje kontrola jako informaci, na stránce
`Systém → Plánované úlohy` je pod tabulkou. Jakmile úloha práci dostane (připojíte
banku, odešlete podání), je znovu aktivní a hlídá se její interval. Povinné úlohy jako
zálohy, kurzy ČNB, upomínky nebo opakované faktury jsou aktivní vždy.

Když stojí **sám plánovač**, hlásí kontrola jediný problém **Plánovač úloh neběží**
a zaseklé úlohy uvede jen jako podrobnost. Náprava je jedna:

- v režimu **Jeden dispatcher** ověřte, že je každou minutu naplánovaný `cron-dispatch`
  a že jeho log v `log/cron` nekončí chybou. Ručně ho spustíte příkazem
  `php api/bin/cron-dispatch.php`,
- v režimu **Jednotlivé úlohy** ověřte, že běží služba cron (Linux) nebo Plánovač úloh
  (Windows) a že úlohy běží pod účtem s právem zápisu do `log/` a `storage/`.

#### 999.5.6.4 „K doúčtování" není prázdné, ale Automat ano

To je očekávané. **Automat** zobrazuje návrhy a blokace automatizačního motoru.
**K doúčtování** navíc inventarizuje bankovní pohyby bez jakéhokoli návrhu, nezaúčtované
vydané a přijaté doklady a otevřené žádosti o dokument. Otevřete akci na řádku, společná
fronta je jen pro čtení a sama zápis nevytváří.

#### 999.5.6.5 V reportu Úplnost dokladů chybí nebo přebývá položka

Report vychází z aktuálních vazeb. Bankovní pohyb zmizí po doložení a párování nebo po
vzniku aktivního bankovního zápisu, stornovaný zápis se za aktivní nepočítá. Zkontrolujte
nastavený práh dnů a směr příchozí a odchozí. Druhá část reportu vychází ze saldokonta
311/321 a ukazuje jen doklady po splatnosti s nenulovým zůstatkem, nikoli všechny
faktury ve stavu „nezaplaceno".

#### 999.5.6.6 Valutová pokladna nenabízí úhradu faktury nebo převod

Není to chyba oprávnění. Valutová pokladna podporuje PPD Prodej/Ostatní a VPD
Nákup/Ostatní s kurzem a CZK protihodnotou. Úhrada cizoměnové faktury přes 311/321
a valutový převod přes 261 nejsou podporované a systém je záměrně blokuje. Proveďte
doložený ruční zápis v deníku. V daňové evidenci je pokladna pouze korunová.

#### 999.5.6.7 AI kontace nic nenavrhla

Ověřte zapnutí AI asistence pro daný typ, přihlašovací údaje poskytovatele, potvrzenou
DPA, rezidenční politiku a denní limit. Nepoužitelná odpověď levného modelu může být
jednou zopakována silnějším modelem. Pokud ani ta neprojde, položka zůstane ruční. AI
nikdy nezaúčtuje položku sama. Pokus a jeho výsledek jsou uložené v auditní stopě návrhu.

#### 999.5.6.8 Úplné mzdy zastavily výpočet v ruční kontrole

Úplné mzdy jsou zkušební agenda. Stav **Ruční kontrola** je bezpečnostní výsledek, ne
technická porucha: pro rozhodné datum může chybět účinný a odborně schválený ruleset,
úplný personální podklad nebo podporovaný scénář. V `Mzdy → Legislativní pravidla mezd`
ověřte období účinnosti a stav všech dotčených oblastí, v detailu revize potom projděte
konkrétní blokery. Chybějící rok se nesmí nahradit nejbližší sadou pravidel. Výsledek
neopravujte ručním přepsáním vypočtených částek a nepoužívejte jej jako jediný podklad
pro výplatu nebo podání. Podrobný postup je v kapitolách
[Mzdové běhy](80_Mzdove_behy.md) a
[Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md).

#### 999.5.6.9 Odkaz do EPO po otevření zmizel

To je očekávané. Jednorázová adresa pro předání do EPO se spotřebuje prvním otevřením
na portálu, MyÚčto ji proto podruhé nenabídne. Pokud jste podání v otevřeném okně
nedokončili, v detailu snapshotu klikněte na **Vytvořit nový odkaz EPO**. Nový odkaz sám
nic neodesílá. Úspěšné otevření formuláře také není důkaz podání, rozhoduje až potvrzení
podatelny nahrané nebo převzaté do archivu.

### 999.5.7 Výkon

#### 999.5.7.1 Dashboard se otevírá pomalu

Možná chybí cache statistik. Spusťte `php api/bin/recompute-stats.php`, přepočítá
`project_revenue_cache` a `client_revenue_cache`.

#### 999.5.7.2 Aplikace pomalu reaguje pod zátěží

- Zapněte Redis (`cfg.php → redis.enabled => true`). Omezování počtu požadavků, ochrana
  proti hádání hesel a aplikační cache pak používají paměť místo databáze.
- Zkontrolujte `cfg.php → app.debug => false` v produkci (ladicí logy jsou drahé).
- Sledujte `log/app-RRRR-MM-DD.log` (pomalé dotazy jsou v logu jako `slow_query`).

### 999.5.8 Multi-supplier

#### 999.5.8.1 Po přepnutí dodavatele vidím prázdný seznam klientů

Klienti jsou po dodavatelích izolovaní. Buď se přepněte zpět na původního, nebo v
aktuálním dodavateli klienty vytvořte znovu (klienta nelze přesunout mezi dodavateli,
záměrně).

#### 999.5.8.2 Faktura mi nešla vystavit, hlásí „klient nepatří aktuálnímu dodavateli"

Ochrana oddělení firem. Buď se přepněte na dodavatele klienta, nebo v aktuálním
vytvořte téhož klienta (data jsou oddělená).

## 999.6 Podrobnosti a pravidla

### 999.6.1 Diagnostika

`Systém → Diagnostika` je první místo, kam se podívat, když se aplikace chová divně
a není jasné proč. Nejde o výpis hodnot, ale o verdikt (**vyhovuje**, **vyhovuje s
výhradami**, **nevyhovuje**) a u každého nálezu je dopad, náprava a odkaz do příslušné
kapitoly manuálu.

Kontroluje se verze PHP a povinná rozšíření, klíčové hodnoty `php.ini` (`memory_limit`,
limity nahrávání, časové pásmo, OPcache), verze a nastavení MariaDB, dostupnost Redisu,
volné a celkové místo na disku (na spravované instalaci ho hlídá provozovatel a kontrola se nezobrazuje) a práva zápisu, shoda časových pásem, stav databázových migrací, struktura
databáze proti migracím ([§ 999.6.1.3](#999613-struktura-databaze)), poslední běhy
plánovaných úloh, stav licence, dostupnost novější verze aplikace a šifrování mzdového
archivu ([§ 999.6.1.4](#999614-sifrovani-mzdoveho-archivu-a-rotace-klice)).

Nálezy jsou seřazené od problémů k varováním, takže shora dolů odpovídají pořadí, v
jakém má smysl je řešit.

V části **Nejednoznačné vlastnictví převzatých zápisů** uvidíte pro právě vybranou
firmu zápisy, které automatické doplnění vlastnictví vynechalo: převod doložil vazbu
jednoho způsobilého zápisu na více různých existujících vydaných či přijatých dokladů.
Kontrola zahrnuje Stereo NX, PREMIER, Money S3, Pohodu a ABRA Flexi. Ručně přidané
souvislosti, úhrady a otevírací zápisy se za kandidátní předpisy nepovažují.
Přehled ukazuje celkový počet, po 20 zápisech a nejvýše 20 kandidátů na zápis;
na další zápisy přejdete tlačítkem **Další zápisy**. Odkazy otevřou konkrétní zápis
a kandidátní doklady. Porovnejte je s původní evidencí a ověřené přiřazení předejte
správci nebo podpoře; samotná diagnostika částky ani vazby nemění. Zápis s již
určeným vlastníkem se mezi nevyřešenými případy nezobrazuje.

#### 999.6.1.1 Diagnostický balíček

Tlačítkem na téže stránce vznikne ZIP s podklady pro **placenou technickou podporu**.
Balíček se vytvoří u vás v instalaci a zůstane u vás, aplikace ho nikam neodesílá.
Stáhnete si ho a na portálu podpory ho k incidentu přiložíte stejně jako kterýkoli jiný
soubor.

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
| `config-sanitized.json` | výřez konfigurace pořízený podle seznamu povolených položek |

**Konfigurace prochází seznamem povolených položek**, ne filtrem na podezřelé názvy:
ven jde jen to, co je jmenovitě povolené. U hesel, klíčů a tokenů se přenáší pouze
informace, jestli jsou nastavené (`<set>` / `<empty>`), nikdy hodnota.

#### 999.6.1.2 Logy v balíčku

Logy aplikace v balíčku **ve výchozím stavu nejsou** a přidávají se zaškrtnutím. Před
vytvořením balíčku si jejich obsah můžete přímo na stránce prohlédnout, den po dni
a po stránkách.

Z výřezu se odstraňují navázané parametry databázových dotazů, stack trace a záznamy
o komunikaci se SMTP serverem. **Zbytek se neupravuje**, logy proto mohou obsahovat
osobní údaje třetích osob, například e-mailové adresy příjemců dokladů, jména a adresy
klientů nebo hodnoty z chybových hlášek databáze. Rozsah je ve výchozím stavu 7 dnů
a úroveň `WARNING` a výš, obojí lze změnit.

Balíček se z instalace automaticky smaže po 24 hodinách. Jeho vytvoření se zapisuje do
auditní stopy včetně otisku SHA-256, takže je vždy dohledatelné, co a kdy bylo předáno.

Velikost je omezená na 25 MB, což je limit přílohy na portálu podpory. Když ji rozsah
logů přesáhne, stránka to ohlásí ještě před vytvořením balíčku.

#### 999.6.1.3 Struktura databáze

Stav migrací říká, že každá migrace proběhla. Kontrola **Struktura databáze** říká, jestli
to, co migrace vytvořily, v databázi pořád je a vypadá tak, jak má. Porovnává tabulky,
sloupce, indexy, cizí klíče, kontroly CHECK, triggery, uložené procedury a auditní
historii deníku s referenčním otiskem, který vzniká z čisté databáze postavené jen
z migrací dané verze aplikace.

Typické příčiny odchylky:

- obnova databáze ze zálohy pořízené starší verzí aplikace,
- ruční úprava struktury (`ALTER TABLE`) mimo migrace,
- tabulka deníku bez auditní historie (`SYSTEM VERSIONING`), kdy se historie změn zápisů
  tiše neukládá.

| Závažnost | Co znamená |
|---|---|
| **Nevyhovuje** | chybí tabulka, sloupec, unikátní index, cizí klíč, kontrola CHECK, trigger, uložená procedura nebo auditní historie, případně má sloupec jiný typ |
| **S výhradami** | jiná collation tabulky nebo sloupce, výchozí hodnota nebo engine, obyčejný index, jiné tělo triggeru, objekty navíc |
| **Informace** | známý pozůstatek starší verze nebo jiná výchozí collation databáze, stav kontroly nezhoršuje |

**Výchozí collation databáze.** Hostingy zakládají databázi s vlastní výchozí collation
(MariaDB 11.8 například `utf8mb4_uca1400_ai_ci`). Aplikaci to nevadí: tabulky, sloupce
i proměnné triggerů a procedur mají collation určenou výslovně migracemi, takže se
výchozí hodnota databáze nikde nepoužije. Kontrola ji proto ukáže jen jako informaci.

**Pozůstatky starších verzí.** Některé objekty po sobě nechala dřívější historie migrací
a současná verze je nepoužívá. Kontrola je zná jménem a místo varování je ukáže jako
informaci „pozůstatek starší verze, lze bezpečně odstranit":

| Objekt | Co to je | Odstranění |
|---|---|---|
| záznam migrace `1110_tax_evidence_dpfo_audit.sql` | migrace starší řady, kterou nahradily pozdější | `DELETE FROM migrations WHERE filename = '1110_tax_evidence_dpfo_audit.sql';` |
| záznam migrace `1720_gopay_payout_account_no_default.sql` | krátce vydaná a vrácená migrace, efekt srovnává 1814 | `DELETE FROM migrations WHERE filename = '1720_gopay_payout_account_no_default.sql';` |
| tabulka `bank_statement_owners` | tabulka starší verze bankovních výpisů | `DROP TABLE IF EXISTS bank_statement_owners;` |

Tabulku aplikace sama nemaže, protože v ní můžou zůstat data. Před smazáním si udělejte
zálohu databáze. Dočasné procedury, které po sobě měly migrace uklidit (například
`sp_journal_versioning_selfheal`), odstraní migrace 1815 sama.

Dokud čekají nespuštěné migrace, kontrola se **přeskočí**: rozdíl by byl jen seznam toho,
co migrace teprve přinesou. Nejdřív proto spusťte `php api/bin/migrate.php`.

Stránka ukazuje prvních 50 nálezů, očekávanou a skutečnou definici uvidíte po najetí
myší. Úplný výpis dá příkaz:

```bash
php api/bin/check-schema.php          # návratový kód 1 při nálezu typu „nevyhovuje"
php api/bin/check-schema.php --json   # strojový výstup
```

Kontrola jen čte, nic neopravuje. Chybějící objekt vytvoří migrace, která ho měla
přinést (najdete ji podle názvu objektu v adresáři `db/migrations`). U migrace evidované
jako proběhlá to znamená smazat její řádek z tabulky `migrations` a spustit
`php api/bin/migrate.php` znovu, migrace jsou opakovatelně spustitelné. Druhou cestou je
obnova ze zálohy pořízené stejnou verzí aplikace.

#### 999.6.1.4 Šifrování mzdového archivu a rotace klíče

Kontrola **Nešifrované mzdové dokumenty** počítá výplatní pásky, mzdové listy a další
mzdová PDF, která vznikla před zavedením šifrování a leží na disku čitelně. Nad seznamem
kontrol se pak zobrazí panel s počtem souborů po firmách a tlačítkem **Zašifrovat
archiv**. Dialog nejdřív ukáže náhled (kolik souborů se zašifruje, kolik je bez vazby na
mzdový doklad), po potvrzení běží přešifrování po dávkách, dokud nezbude nic ke
zpracování.

Každý soubor se zašifruje klíčem osoby, které patří. Zapsaná kopie se hned přečte zpět
a porovná s otiskem originálu, originál se smaže teprve po shodě. Dokumenty jdou
stahovat dál beze změny.

- **Soubory bez vazby na mzdový doklad** se zašifrují klíčem firmy. Bez této volby zůstanou
  beze změny a kontrola je hlásí dál.
- **Dokumenty osob po výmazu** nejde zašifrovat, protože klíč osoby už neexistuje. Volba
  **Smazat nešifrované dokumenty osob po výmazu** je smaže. Smazání je nevratné, proto se
  potvrzuje ještě jedním zaškrtnutím.

Kontrola **Rotace šifrovacího klíče** se objeví, jakmile správce serveru nastaví nový
`app.secret_encryption_key` a původní klíč přesune do
`app.secret_encryption_previous_keys`. Ukazuje, kolik mzdových údajů je ještě
zašifrovaných starým klíčem. Tlačítko **Přebalit na nový klíč** je převede. Když už
starým klíčem není zašifrované nic, kontrola vyzve k odebrání starého klíče z konfigurace,
teprve tím rotace končí. Hodnota zašifrovaná klíčem, který v konfiguraci vůbec není, se
hlásí jako problém: bez původního klíče ji nejde přečíst ani převést.

Obě akce smí spustit jen správce instalace a zapisují se do logu `Systém → Log` (jen počty).
Totéž jde z příkazové řádky, bez omezení délky běhu:

```bash
php api/bin/payroll-archive-reencrypt.php --status            # stav bez zápisu
php api/bin/payroll-archive-reencrypt.php --dry-run           # náhled přešifrování
php api/bin/payroll-archive-reencrypt.php                     # zašifrovat archiv
php api/bin/payroll-archive-reencrypt.php --include-orphans   # včetně souborů bez vazby
php api/bin/payroll-archive-reencrypt.php --rewrap            # archiv + přebalení na aktuální klíč
```

Na Windows `cmd\payroll-archive-reencrypt.cmd`, na Linuxu
`cmd/payroll-archive-reencrypt.sh` se stejnými parametry.

#### 999.6.1.5 Globální číselníky

Kontrola **Globální číselníky** hlídá číselníky, které aplikace dostává s migracemi
a které nepatří žádné firmě: země, sazby DPH, měrné jednotky, výkazy, repo sazby ČNB,
sazby DPH členských států, státní svátky, katalog klíčových slov nákladů, globální
předkontace a klasifikace DPH, příjemce podání (ČSSZ, zdravotní pojišťovny) a systémové
role. Když některý z nich nemá ani jeden řádek, kontrola ho vypíše.

Prázdný číselník migrace samy nevrátí, protože jsou evidované jako proběhlé. Typickou
příčinou je obnova neúplné zálohy nebo vymazání dat starší verzí skriptu `reset.php`. Bez
svátků se například lhůty podání a odvodů počítají jen z pojistky v kódu.

Chybějící řádky doplní:

```bash
php api/bin/restore-global-seeds.php            # náhled, nic nezapíše
php api/bin/restore-global-seeds.php --apply    # doplní chybějící řádky
```

Na Windows `cmd\restore-global-seeds.ps1`, na Linuxu `cmd/restore-global-seeds.sh` se
stejnými parametry. Skript přehraje seedy z migrací, nic nemaže a údaje firem nechává
beze změny. Opakované spuštění nic nezdvojí. Sazby DPH členských států doplní
`php api/bin/migrate.php`. Číselník, který skript nevrátí, vypíše na konci, ten obnovíte
ze zálohy pořízené stejnou verzí aplikace.

### 999.6.2 Kontroly mzdové agendy

U mzdové kontroly nejprve přečtěte, koho a kterého období se týká. Tlačítko u konkrétní
osoby otevře příslušný pracovní vztah, sekci nebo pole. Má-li stejný problém více osob,
každá může mít vlastní odkaz. Po opravě podkladů se vraťte a obnovte kontrolu. Schválené
mzdy se opravují navazující revizí, nikoli přepsáním původního výsledku.

| Kontrola | Kam pokračovat a co ověřit |
|---|---|
| Podmínky vztahu, účtárna, pracovní doba nebo sleva | Tlačítkem otevřete zvýrazněné pole daného vztahu a ověřte jeho skutečnou hodnotu i platnost pro měsíc mzdy. |
| Registrace vztahu | Otevřete příslušnou registrační povinnost. Dokládejte skutečné přihlášení, potvrzení nevyplňujte jen pro odstranění varování. |
| Daň, pojištění a srážky | Projděte jednotlivé důvody u osoby. Odkaz směřuje do zákonné evidence, vyživovaných osob, exekucí, insolvence či příslušných vstupů. |
| Docházka | Ověřte vybraný vztah a měsíc. Odkaz na kalendář pouze najde ovládání, nevytváří automaticky rozvrh. |
| ELDP | Doplňte schválenou mzdu konkrétního měsíce nebo opravte uvedenou absenci či podmínky. Nepodporované případy předejte správci. |
| Průměr pro výstupní dokument | Otevřete průměry vztahu. Předvolený rok a čtvrtletí označují období použití průměru, rozhodné období je předchozí čtvrtletí. |
| Příprava plateb | Rozlište druh platby. Otevřete účet instituce, výplatní účet zaměstnance nebo aktuální schválenou revizi podle konkrétní zprávy. |
| Generování dokumentu nebo technická chyba pravidel | Rozbalte podrobnosti pro podporu. U dokumentu lze zkusit opakování, přetrvávající problém předejte podpoře. Kvůli chybě instalace neměňte sazby ani údaje zaměstnanců. |

U známé kompatibilní aktualizace specifikace JMHZ aplikace posoudí původní podklady
automaticky. Přijaté hlášení se kvůli samotné změně verze znovu neodesílá. Certifikátový
pokus a podání datovou schránkou jsou samostatné přenosy, proto jejich stavy mohou být
různé. Historie odeslání ukazuje oba.

Pokud zpráva uvádí, že konkrétní náprava není známá, použijte odkaz na podporu a předejte
období, číslo běhu či revize a technické podrobnosti. Aplikace v tom případě nemá dost
informací pro spolehlivou automatickou opravu.

## 999.7 Související kapitoly

- [Bezpečnost](101_Bezpecnost.md): obnova přístupu, `app.url`, DKIM.
- [Po instalaci](05_Po_instalaci.md): plánované úlohy a cron.
- [Aktualizace](102_Aktualizace.md): provozní upozornění na stránce Aktualizace.
- [Přihlášení](08_Prihlaseni.md): MFA, passkey a záložní kódy.
- [Multi-supplier](95_Multi_supplier.md): oddělení dat po firmách.
- [Mzdové běhy](80_Mzdove_behy.md) a [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md):
  mzdové kontroly.
