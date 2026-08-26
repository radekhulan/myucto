# 6. Převod dat z MyInvoice do MyÚčto

Tento postup přenese existující instalaci MyInvoice do nové instalace MyÚčto.
Zachová uživatele, jejich členství ve firmách, firmy, klienty, ceník, faktury,
přijaté faktury, bankovní výpisy, párování a ostatní data uložená v databázi.
Nové účetní tabulky MyÚčta se doplní následnými migracemi a backfilly.

> [!CAUTION]
> Převod prováděj nad zálohou a mimo běžný provoz. Zdrojová databáze MyInvoice
> musí zůstat beze změny. Při jakékoli chybě převod zastav a nezačínej pracovat
> v částečně naplněné cílové databázi.

Celý převod dělá jediný příkaz. `migrate.php` spouštět ručně netřeba —
`MyInvoiceMigrate.php` si schéma připraví, data přenese a migrace MyÚčta dojede
sám, ve správném pořadí.

## 6.1 Předpoklady

- Databázový účet z `cfg.php` smí číst zdroj a zapisovat do cíle.
- Zdroj MyInvoice má aplikované všechny své migrace.
- Máš samostatnou zálohu obou databází a souborů ze `storage/`.

`MyInvoiceMigrate.php` přenáší databázové řádky, nikoli uložená PDF, přílohy,
loga ani jiné soubory. Potřebné soubory přenes samostatně se zachováním jejich
relativních cest a přístupových práv.

> [!CAUTION]
> **`app.pepper` a `app.secret_encryption_key` v cílovém `cfg.php` musí být
> shodné se zdrojovou instalací MyInvoice.** Nikoli nově vygenerované.
> Pepper vstupuje do `password_hash`, `secret_encryption_key` šifruje TOTP
> secrety přes AES-256-GCM. S novými hodnotami převod i všechny migrace
> proběhnou bez jediné chybové hlášky, ale přenesená hesla nejdou ověřit
> a TOTP secrety dešifrovat — chyba se projeví až tím, že se nikdo nepřihlásí.
> Je-li `secret_encryption_key` ve zdroji prázdný, nech ho prázdný i v cíli
> (odvozuje se HKDF z pepperu).

## 6.2 Proč to nejde jedním `migrate.php`

MyÚčto přidává vlastní migrace `1000+`. Některé z nich nejen vytvářejí tabulky,
ale také doplňují data již existujících uživatelů a firem — například role,
oprávnění a historii účetního režimu.

Kdyby se všechny migrace spustily před importem, tyto datové kroky by proběhly
nad prázdnou databází. Po importu se už neopakují, protože jsou v tabulce
`migrations` označené jako dokončené.

Správné pořadí proto je:

1. připravit upstream schéma MyInvoice,
2. importovat data,
3. teprve potom aplikovat rozšíření MyÚčta.

Skript to řeší za tebe. Pozor na jednu past, kterou hlídá: MyÚčto některé
upstream featury přečíslovalo nad `1000` — `user_suppliers` má migraci `1000`,
ceník `1121`. Kdyby se před importem postavilo jen schéma pod `1000`, tabulky by
v cíli nebyly a jejich data by se tiše zahodila. Skript si proto dohledá, které
migrace `1000+` jsou čistě DDL (nic nedoplňují nad daty), a spustí je už před
importem.

## 6.3 Příprava cílové databáze

V databázové administraci vytvoř cílovou databázi a nastav ji v `cfg.php`.
Pečlivě ověř, že zdrojová databáze, například `myinvoice`, není současně
nastavena jako cíl.

Cíl může být:

- **prázdná databáze** — doporučený stav,
- **databáze s už proběhlým `migrate.php`** — skript ji rozpozná a schéma
  postaví znovu ve správném pořadí (tabulky zahodí a vytvoří odznova).

Obojí vede ke stejnému výsledku. Pokud cíl už obsahuje data, která nechceš
ztratit, převod nespouštěj — cílové tabulky se přepisují.

## 6.4 Převod

Nejprve spusť migrátor bez automatického potvrzení. Zobrazí zdroj, cíl, stav
cílové databáze a plán jednotlivých kroků:

```powershell
php api/bin/MyInvoiceMigrate.php myinvoice
```

Pokud plán souhlasí, převod potvrď slovem `ANO`. Pro neinteraktivní běh:

```powershell
php api/bin/MyInvoiceMigrate.php myinvoice --yes
```

Skript projde tyto fáze:

1. **Kontrola cíle** — zjistí, co v cílové databázi je.
2. **Příprava schématu** — `migrate.php --below=1000` plus čistě DDL migrace
   `1000+` pro featury, které zdroj už má.
3. **Preflight** — ověří, že cíl má kam uložit *všechna* data zdroje.
4. **Přenos dat.**
5. **Dokončení** — zbylé migrace `1000+` včetně backfillů nad přenesenými daty.

Výstup musí skončit hlášením `HOTOVO — data přenesena a schéma MyÚčta
dokončeno.` a bez sekce `CHYBY`.

### 6.4.1 Preflight zastavil převod

Skript se zastaví, dokud by se měla ztratit byť jediná hodnota. Vypíše
konkrétně, o co jde:

```
✗ PŘEVOD ZASTAVEN: cíl 'myucto' nemá kam uložit tato data zdroje:
     - tabulka webauthn_credentials — 4 řádků by se ztratilo
     - sessions.auth_method — 21 vyplněných hodnot by se ztratilo
```

Nejčastější příčina je nekompatibilní nebo neaktuální cílová instalace MyÚčta
vůči zdrojové instalaci MyInvoice. Aktualizuj MyÚčto a spusť převod znovu.
Prázdné tabulky a sloupce plné `NULL` převod nezastaví — vypíšou se jen
informativně.

Vědomé pokračování se ztrátou uvedených dat: `--allow-missing`.

### 6.4.2 Užitečné přepínače

| Přepínač | K čemu |
|---|---|
| `--yes` | bez interaktivního potvrzení |
| `--tables=a,b,c` | přenést jen vyjmenované tabulky |
| `--no-truncate` | nepromazávat cílové tabulky před kopií |
| `--allow-missing` | nezastavit se na datech, pro která cíl nemá kam |
| `--no-prepare` | nepřipravovat schéma cíle (cíl je připravený ručně) |
| `--no-finalize` | nespouštět po importu dokončovací migrace `1000+` |
| `--keep-schema` | nepřestavovat cíl, i když už má migrace `1000+` |
| `--stream` | vynutit proudovou kopii i pro zdroj na témže serveru |
| `--batch=N` | velikost dávky při proudové kopii (výchozí 2000 řádků) |

> [!WARNING]
> `--no-truncate` **není opatrnější varianta běžného převodu.** Používej ho jen
> tehdy, když cíl obsahuje vlastní data, která musí zůstat zachovaná. Nad
> čerstvě založenou databází vede k opaku: `migrate.php` už naplnil číselníky
> (`countries`, `units`, `vat_rates`, `vat_classifications`,
> `bank_email_notice_providers` …) výchozími řádky a import ze zdroje je vloží
> znovu se stejnými ID:
>
> ```
> Integrity constraint violation: 1062 Duplicate entry
> ```
>
> Řešení je vytvořit cílovou databázi znovu prázdnou a převod zopakovat bez
> tohoto přepínače.

## 6.5 Převod v Dockeru

Když zdroj a cíl nejsou na témže databázovém serveru, zadej zdroj jako URL.
Skript se připojí druhým spojením a data přenese proudově po dávkách.

### 6.5.1 MyInvoice v jiném Docker stacku (Docker → Docker)

Zdrojový kontejner musí být dosažitelný ze sítě, v níž běží `app` kontejner
MyÚčta. To za tebe vyřeší připravený wrapper — zdrojový kontejner do sítě
dočasně připojí a po dokončení zase odpojí:

```powershell
.\cmd\docker-migrate-from-myinvoice.ps1 -SourceContainer myinvoice-db-1 `
    -SourceDb myinvoice -SourceUser root -SourcePassword tajne -Yes
```

Na Linuxu:

```bash
cmd/docker-migrate-from-myinvoice.sh --source-container myinvoice-db-1 \
    --source-db myinvoice --source-user root --source-password tajne --yes
```

Zdrojový stack zůstává beze změny; zapisuje se pouze do cílové databáze MyÚčta.

#### Kolize DNS aliasu `db` mezi dvěma Compose stacky

Jakmile je `app` kontejner MyÚčta připojený současně do sítě staré instalace
MyInvoice, existují v dosahu **dva různé hostname `db`** — Docker dává každé
service automatický síťový alias podle jejího jména a obě instalace mají
databázi pojmenovanou `db`. Embedded DNS pak může `db` přeložit na databázi
druhého stacku a aplikace se hlásí do cizí databáze.

Příznak je zavádějící, protože vypadá jako špatné heslo:

```
Access denied for user 'myucto'@'172.18.0.4' (using password: YES)
```

V `cfg.docker.php` proto po dobu převodu neuváděj `db`, ale **jméno kontejneru**,
které je napříč Dockerem jedinečné:

```php
'db' => [
    'host' => 'myucto-db-1',
],
```

Totéž platí pro zdroj v migračním URL — `mysql://root:tajne@myinvoice-db-1:3306/myinvoice`.

#### Po změně `cfg.docker.php` nestačí `restart`

Produkční image má z výkonových důvodů `opcache.validate_timestamps=0`, takže
PHP nekontroluje časová razítka souborů a drží zkompilovanou verzi configu
v paměti. `docker compose restart app` proces PHP-FPM uvnitř kontejneru
zachová, takže aplikace dál běží nad **starým** `cfg.php` a úprava se zdánlivě
neprojeví. Kontejner je potřeba vytvořit znovu:

```powershell
docker compose up -d --force-recreate app
```

### 6.5.2 MyInvoice na hostiteli, MyÚčto v Dockeru

```powershell
.\cmd\docker-migrate-from-myinvoice.ps1 -SourceHost host.docker.internal `
    -SourceDb myinvoice -SourceUser root -SourcePassword tajne -Yes
```

### 6.5.3 Ručně, bez wrapperu

```bash
docker compose exec app php api/bin/MyInvoiceMigrate.php \
    "mysql://root:tajne@myinvoice-db:3306/myinvoice" --yes
```

Heslo lze místo argumentu předat proměnnou `MYINVOICE_SOURCE_URL`, ať se
neobjeví v historii shellu.

> [!IMPORTANT]
> Image MyÚčta musí být aktuální. Starý image má starší sadu migrací a preflight
> převod správně zastaví, protože by neměl kam uložit novější data MyInvoice.
> Před převodem spusť `cmd/docker-update.{ps1,sh}`.

## 6.6 Základní kontrola importu

Před zapnutím účetnictví ověř:

- přihlášení původním administrátorským účtem,
- seznam firem a přístup uživatelů k jednotlivým firmám,
- orientační počty klientů, vydaných a přijatých faktur,
- bankovní výpisy a existující párování,
- `php api/bin/migrate.php --status` — všechny migrace musí být `[x]`,
- dostupnost PDF, příloh, log a dalších souborů ze `storage/`.

Některé počty se po importu záměrně liší od zdroje — dokončovací migrace
deduplikují párování plateb a doplňují číselníky i členství uživatelů ve firmách.

U více firem prováděj následující účetní kroky samostatně pro každé ID firmy.

> 🛈 **Po převodu je účetní nadstavba vypnutá.** MyInvoice je fakturační
> aplikace, takže se převedená firma chová stejně jako předtím: fakturace,
> klienti, banka, dokumenty a DPH. Účetnictví (ani daňová evidence) se samo
> nezapíná — jinak bys hned po převodu měl v menu desítky stránek nad prázdnými
> tabulkami. Zapíná se přepínačem **Vést účetnictví** v Nastavení → Daně
> a účetnictví; je to licencovaný modul (viz [100. Licence a aktivace](100_Licence_a_aktivace.md)),
> na nové instalaci ale prvních 60 dní zdarma.

Právnická osoba (s.r.o., a.s.) zůstává po převodu v daňové evidenci, dokud
účetnictví nezapneš. Aplikace tě u toho neblokuje: e-mail, číselné řady ani
cokoli dalšího v nastavení uložíš i s nepřepnutým režimem.

## 6.7 Zapnutí podvojného účetnictví

V nastavení firmy zapni **Vést účetnictví** a v Daňovém nastavení přepni
**Režim účetnictví** na *Podvojné účetnictví* se správným datem účinnosti.
Změna může být účinná pouze k 1. lednu. Datum musí odpovídat skutečnému začátku
vedení účetnictví, nikoli automaticky dni převodu.

Poznamenej si ID firmy, které bude v příkazech nahrazovat `<ID>`.

## 6.8 Doúčtování historie

Nejdřív zkontroluj předpisy faktur dry-runem, potom spusť ostrý účetní backfill:

```powershell
php api/bin/backfill-accounting.php --supplier=<ID> --dry-run
php api/bin/backfill-accounting.php --supplier=<ID>
```

Bankovní backfill je ve výchozím stavu dry-run. Bez `--rules` zpracovává
existující párování a pouze oboustranně jednoznačné historické platby. Nespáruje
doklady jen podle podobné částky:

```powershell
php api/bin/backfill-bank-posting.php --supplier=<ID>
php api/bin/backfill-bank-posting.php --supplier=<ID> --apply
```

Pokladní historii nejprve jen zkontroluj. Ostrý příkaz je potřeba pouze tehdy,
pokud dry-run najde pokladní doklady k doúčtování:

```powershell
php api/bin/backfill-cash-accounting.php --supplier=<ID> --dry-run
php api/bin/backfill-cash-accounting.php --supplier=<ID>
```

Nakonec spusť účetní backfill ještě jednou. Po zaúčtování banky a pokladny tím
doplní zúčtování přijatých a poskytnutých záloh `324/311` a `321/314`:

```powershell
php api/bin/backfill-accounting.php --supplier=<ID>
```

Všechny backfilly jsou idempotentní; opakovaný běh nesmí vytvářet duplicity.

## 6.9 Závěrečná kontrola

Spusť čtecí kontrolu integrity deníku:

```powershell
php api/bin/cron-journal-integrity-check.php --supplier=<ID> --dry-run
```

V aplikaci potom zkontroluj:

- Účetnictví → Účetní deník,
- hlavní knihu a obratovou předvahu,
- Účetnictví → Saldo pro účty `311`, `321`, `314` a `324`,
- že plně zaplacené finální faktury ze záloh nezůstávají otevřené,
- že účetní rozdíly nejsou tvořené nepotvrzenými bankovními avízy nebo ručně
  označenými platbami bez bankovního či pokladního zápisu.

E-mailové bankovní avízo potvrzuje očekávanou platbu, ale samo se neúčtuje jako
bankovní výpis. Dočasný rozdíl v saldu proto může zmizet až po importu skutečného
výpisu a jeho spárování.

## 6.10 Obnova při chybě

Pokud import nebo některá migrace skončí chybou, nepokračuj v cílové databázi.
Zapiš si chybový výstup, cílovou databázi znovu vytvoř jako prázdnou a celý
postup zopakuj. Zdroj MyInvoice zůstává po celou dobu nedotčený.

Návratové kódy `MyInvoiceMigrate.php`:

| Kód | Význam |
|---|---|
| `0` | hotovo |
| `1` | chyba argumentů nebo připojení |
| `2` | import skončil s chybami; dokončovací migrace neproběhly |
| `3` | preflight zastavil převod, aby se neztratila data |
| `4` | příprava schématu cíle selhala |
| `5` | data jsou přenesená, ale dokončovací migrace selhaly |
