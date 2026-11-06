# 6. Převod dat z MyInvoice do MyÚčto

> Návod pro správce, jak přenést existující instalaci MyInvoice do nové instalace
> MyÚčto. Převod zachová uživatele, jejich členství ve firmách, firmy, klienty,
> ceník, faktury, přijaté faktury, bankovní výpisy, párování a ostatní data
> uložená v databázi. Nové účetní tabulky MyÚčta se doplní následnými migracemi
> a backfilly.

## 6.1 Kdy to potřebujete

- Provozujete MyInvoice a chcete přejít na MyÚčto se všemi daty.
- Máte MyInvoice v jiném Docker stacku nebo na hostiteli a MyÚčto v Dockeru.
- Převod skončil chybou a potřebujete ho zopakovat.
- Po převodu chcete zapnout podvojné účetnictví a doúčtovat historii.

> [!WARNING]
> Převod provádějte nad zálohou a mimo běžný provoz. Zdrojová databáze MyInvoice
> musí zůstat beze změny. Při jakékoli chybě převod zastavte a nezačínejte
> pracovat v částečně naplněné cílové databázi.

Celý převod dělá jediný příkaz. `migrate.php` spouštět ručně netřeba:
`MyInvoiceMigrate.php` si schéma připraví, data přenese a migrace MyÚčta dojede
sám, ve správném pořadí.

## 6.2 Než začnete

1. Databázový účet z `cfg.php` smí číst zdroj a zapisovat do cíle.
2. Zdroj MyInvoice má aplikované všechny své migrace.
3. Máte samostatnou zálohu obou databází a souborů ze `storage/`.
4. Soubory ze `storage/` (PDF, přílohy, loga a další) máte připravené k
   samostatnému přenosu. `MyInvoiceMigrate.php` přenáší databázové řádky, nikoli
   soubory. Přeneste je se zachováním relativních cest a přístupových práv.
5. V cílovém `cfg.php` jsou **shodné** hodnoty `app.pepper` a
   `app.secret_encryption_key` jako ve zdrojové instalaci.

> [!WARNING]
> `app.pepper` a `app.secret_encryption_key` v cílovém `cfg.php` musí být
> shodné se zdrojovou instalací MyInvoice, nikoli nově vygenerované.
> Pepper vstupuje do `password_hash`, `secret_encryption_key` šifruje TOTP
> secrety přes AES-256-GCM. S novými hodnotami převod i všechny migrace
> proběhnou bez jediné chybové hlášky, ale přenesená hesla nejdou ověřit
> a TOTP secrety dešifrovat. Chyba se projeví až tím, že se nikdo nepřihlásí.
> Je-li `secret_encryption_key` ve zdroji prázdný, nechte ho prázdný i v cíli
> (odvozuje se HKDF z pepperu).

## 6.3 Krok za krokem: převod dat

1. V databázové administraci vytvořte cílovou databázi a nastavte ji v `cfg.php`.
   Pečlivě ověřte, že zdrojová databáze (například `myinvoice`) není současně
   nastavena jako cíl.
2. Spusťte migrátor bez automatického potvrzení. Zobrazí zdroj, cíl, stav cílové
   databáze a plán jednotlivých kroků:

   ```powershell
   php api/bin/MyInvoiceMigrate.php myinvoice
   ```

3. Souhlasí-li plán, potvrďte převod slovem `ANO`.
4. Pro neinteraktivní běh použijte přepínač `--yes`:

   ```powershell
   php api/bin/MyInvoiceMigrate.php myinvoice --yes
   ```

5. Počkejte na konec výstupu a zkontrolujte ho.

**Jak poznáte, že je hotovo:** výstup končí hlášením `HOTOVO — data přenesena a
schéma MyÚčta dokončeno.` a neobsahuje sekci `CHYBY`. Návratový kód je `0`.

Cíl může být prázdná databáze (doporučený stav) nebo databáze, ve které už proběhl
`migrate.php`. Pokud cíl obsahuje data, která nechcete ztratit, převod nespouštějte,
cílové tabulky se přepisují.

## 6.4 Krok za krokem: převod v Dockeru

Když zdroj a cíl nejsou na témže databázovém serveru, zadejte zdroj jako URL.
Skript se připojí druhým spojením a data přenese proudově po dávkách.

Před převodem aktualizujte image MyÚčta příkazem `cmd/docker-update.{ps1,sh}`.

> [!WARNING]
> Image MyÚčta musí být aktuální. Starý image má starší sadu migrací a preflight
> převod správně zastaví, protože by neměl kam uložit novější data MyInvoice.

### 6.4.1 MyInvoice v jiném Docker stacku (Docker → Docker)

Zdrojový kontejner musí být dosažitelný ze sítě, v níž běží `app` kontejner
MyÚčta. Připravený wrapper ho do sítě dočasně připojí a po dokončení zase odpojí.

1. Ve Windows spusťte:

   ```powershell
   .\cmd\docker-migrate-from-myinvoice.ps1 -SourceContainer myinvoice-db-1 `
       -SourceDb myinvoice -SourceUser root -SourcePassword tajne -Yes
   ```

   Na Linuxu:

   ```bash
   cmd/docker-migrate-from-myinvoice.sh --source-container myinvoice-db-1 \
       --source-db myinvoice --source-user root --source-password tajne --yes
   ```

2. Zkontrolujte výstup jako v [§ 6.3](#63-krok-za-krokem-prevod-dat).

Zdrojový stack zůstává beze změny, zapisuje se pouze do cílové databáze MyÚčta.

### 6.4.2 MyInvoice na hostiteli, MyÚčto v Dockeru

```powershell
.\cmd\docker-migrate-from-myinvoice.ps1 -SourceHost host.docker.internal `
    -SourceDb myinvoice -SourceUser root -SourcePassword tajne -Yes
```

### 6.4.3 Ručně, bez wrapperu

```bash
docker compose exec --user www-data app php api/bin/MyInvoiceMigrate.php \
    "mysql://root:tajne@myinvoice-db:3306/myinvoice" --yes
```

Heslo lze místo argumentu předat proměnnou `MYINVOICE_SOURCE_URL`, ať se
neobjeví v historii shellu.

## 6.5 Krok za krokem: základní kontrola importu

Před zapnutím účetnictví ověřte:

1. Přihlášení původním administrátorským účtem.
2. Seznam firem a přístup uživatelů k jednotlivým firmám.
3. Orientační počty klientů, vydaných a přijatých faktur.
4. Bankovní výpisy a existující párování.
5. Příkaz `php api/bin/migrate.php --status`: všechny migrace musí být `[x]`.
6. Dostupnost PDF, příloh, log a dalších souborů ze `storage/`.

**Jak poznáte, že je hotovo:** všechny body sedí. Některé počty se po importu
záměrně liší od zdroje, protože dokončovací migrace deduplikují párování plateb
a doplňují číselníky i členství uživatelů ve firmách.

U více firem provádějte následující účetní kroky samostatně pro každé ID firmy.

> [!TIP]
> Po převodu je účetní nadstavba vypnutá. MyInvoice je fakturační aplikace,
> takže se převedená firma chová stejně jako předtím: fakturace, klienti, banka,
> dokumenty a DPH. Účetnictví (ani daňová evidence) se samo nezapíná, jinak byste
> hned po převodu měli v menu desítky stránek nad prázdnými tabulkami. Zapíná se
> přepínačem **Vést účetnictví** v `Firma → Nastavení` na záložce **Daně a účetnictví**.
> Je to licencovaný modul (viz [104. Licence a aktivace](104_Licence_a_aktivace.md)),
> na nové instalaci ale prvních 60 dní zdarma.

Právnická osoba (s.r.o., a.s.) zůstává po převodu v daňové evidenci, dokud
účetnictví nezapnete. Aplikace vás u toho neblokuje: e-mail, číselné řady ani
cokoli dalšího v nastavení uložíte i s nepřepnutým režimem.

## 6.6 Krok za krokem: zapnutí podvojného účetnictví a doúčtování historie

### 6.6.1 Zapnutí podvojného účetnictví

1. Otevřete `Firma → Nastavení` a na záložce **Daně a účetnictví** zapněte
   **Vést účetnictví**.
2. Na téže záložce přepněte **Režim účetnictví** na **Podvojné účetnictví**
   se správným datem účinnosti. Změna může být účinná pouze k 1. lednu. Datum
   musí odpovídat skutečnému začátku vedení účetnictví, nikoli automaticky dni
   převodu.
3. Klikněte na **Uložit nastavení firmy**.
4. Poznamenejte si ID firmy, které bude v příkazech nahrazovat `<ID>`.

**Jak poznáte, že je hotovo:** v menu přibyly sekce **Účetnictví** a **Nástroje**.

### 6.6.2 Doúčtování historie

1. Zkontrolujte předpisy faktur dry-runem a potom spusťte ostrý účetní backfill:

   ```powershell
   php api/bin/backfill-accounting.php --supplier=<ID> --dry-run
   php api/bin/backfill-accounting.php --supplier=<ID>
   ```

2. Bankovní backfill je ve výchozím stavu dry-run. Bez `--rules` zpracovává
   existující párování a pouze oboustranně jednoznačné historické platby.
   Nespáruje doklady jen podle podobné částky:

   ```powershell
   php api/bin/backfill-bank-posting.php --supplier=<ID>
   php api/bin/backfill-bank-posting.php --supplier=<ID> --apply
   ```

3. Pokladní historii nejprve jen zkontrolujte. Ostrý příkaz je potřeba pouze
   tehdy, pokud dry-run najde pokladní doklady k doúčtování:

   ```powershell
   php api/bin/backfill-cash-accounting.php --supplier=<ID> --dry-run
   php api/bin/backfill-cash-accounting.php --supplier=<ID>
   ```

4. Nakonec spusťte účetní backfill ještě jednou. Po zaúčtování banky a pokladny
   tím doplní zúčtování přijatých a poskytnutých záloh `324/311` a `321/314`:

   ```powershell
   php api/bin/backfill-accounting.php --supplier=<ID>
   ```

**Jak poznáte, že je hotovo:** všechny backfilly doběhly bez chyby. Jsou
idempotentní, opakovaný běh nesmí vytvářet duplicity.

## 6.7 Krok za krokem: závěrečná kontrola

1. Spusťte čtecí kontrolu integrity deníku:

   ```powershell
   php api/bin/cron-journal-integrity-check.php --supplier=<ID> --dry-run
   ```

2. V aplikaci zkontrolujte `Účetnictví → Účetní deník`, `Účetnictví → Hlavní kniha`
   a `Účetnictví → Obratová předvaha`.
3. V `Účetnictví → Saldokonto` zkontrolujte účty `311`, `321`, `314` a `324`.
4. Ověřte, že plně zaplacené finální faktury ze záloh nezůstávají otevřené.
5. Ověřte, že účetní rozdíly nejsou tvořené nepotvrzenými bankovními avízy nebo
   ručně označenými platbami bez bankovního či pokladního zápisu.

**Jak poznáte, že je hotovo:** kontrola integrity nehlásí chybu a salda sedí.

E-mailové bankovní avízo potvrzuje očekávanou platbu, ale samo se neúčtuje jako
bankovní výpis. Dočasný rozdíl v saldu proto může zmizet až po importu skutečného
výpisu a jeho spárování.

## 6.8 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| `PŘEVOD ZASTAVEN: cíl ... nemá kam uložit tato data zdroje` | Preflight zjistil, že by se ztratila data (nekompatibilní nebo neaktuální cílová instalace) | Aktualizujte MyÚčto a spusťte převod znovu. Vědomé pokračování se ztrátou: `--allow-missing`. Podrobnosti v [§ 6.9.3](#693-preflight-zastavil-prevod) |
| `Integrity constraint violation: 1062 Duplicate entry` | Převod byl spuštěn s `--no-truncate` nad čerstvou databází, číselníky už jsou naplněné | Vytvořte cílovou databázi znovu prázdnou a převod zopakujte bez tohoto přepínače |
| `Access denied for user 'myucto'@'172.18.0.4' (using password: YES)` při převodu mezi dvěma Docker stacky | Hostname `db` se přeložil na databázi druhého stacku | V `cfg.docker.php` uveďte jméno kontejneru, viz [§ 6.9.5](#695-kolize-dns-aliasu-db-mezi-dvema-compose-stacky) |
| Úprava `cfg.docker.php` se neprojevila | Produkční image drží zkompilovaný config v paměti, `restart` ho nenačte | Spusťte `docker compose up -d --force-recreate app` |
| Nikdo se po převodu nepřihlásí | Jiný `app.pepper` nebo `app.secret_encryption_key` než ve zdroji | Nastavte hodnoty shodné se zdrojem, viz [§ 6.2](#62-nez-zacnete) |
| Import nebo některá migrace skončila chybou | Převod se nepovedl celý | Nepokračujte v cílové databázi. Zapište si chybový výstup, vytvořte cíl znovu prázdný a celý postup zopakujte. Zdroj MyInvoice zůstává nedotčený |
| Návratový kód jiný než `0` | Viz tabulka | Viz [§ 6.9.7](#697-navratove-kody) |

## 6.9 Podrobnosti a pravidla

### 6.9.1 Proč to nejde jedním `migrate.php`

MyÚčto přidává vlastní migrace `1000+`. Některé z nich nejen vytvářejí tabulky,
ale také doplňují data již existujících uživatelů a firem, například role,
oprávnění a historii účetního režimu.

Kdyby se všechny migrace spustily před importem, tyto datové kroky by proběhly
nad prázdnou databází. Po importu se už neopakují, protože jsou v tabulce
`migrations` označené jako dokončené.

Správné pořadí proto je:

1. připravit upstream schéma MyInvoice,
2. importovat data,
3. teprve potom aplikovat rozšíření MyÚčta.

Skript to řeší za vás. Hlídá i jednu past: MyÚčto některé upstream featury
přečíslovalo nad `1000` (`user_suppliers` má migraci `1000`, ceník `1121`).
Kdyby se před importem postavilo jen schéma pod `1000`, tabulky by v cíli
nebyly a jejich data by se tiše zahodila. Skript si proto dohledá, které migrace
`1000+` jsou čistě DDL (nic nedoplňují nad daty), a spustí je už před importem.

### 6.9.2 Fáze převodu

1. **Kontrola cíle**: zjistí, co v cílové databázi je. Obsahuje-li už proběhlý
   `migrate.php`, skript schéma postaví znovu ve správném pořadí (tabulky
   zahodí a vytvoří odznova).
2. **Příprava schématu**: `migrate.php --below=1000` plus čistě DDL migrace
   `1000+` pro featury, které zdroj už má.
3. **Preflight**: ověří, že cíl má kam uložit *všechna* data zdroje.
4. **Přenos dat.**
5. **Dokončení**: zbylé migrace `1000+` včetně backfillů nad přenesenými daty.

### 6.9.3 Preflight zastavil převod

Skript se zastaví, dokud by se měla ztratit byť jediná hodnota. Vypíše
konkrétně, o co jde:

```
✗ PŘEVOD ZASTAVEN: cíl 'myucto' nemá kam uložit tato data zdroje:
     - tabulka webauthn_credentials — 4 řádků by se ztratilo
     - sessions.auth_method — 21 vyplněných hodnot by se ztratilo
```

Nejčastější příčina je nekompatibilní nebo neaktuální cílová instalace MyÚčta
vůči zdrojové instalaci MyInvoice. Aktualizujte MyÚčto a spusťte převod znovu.
Prázdné tabulky a sloupce plné `NULL` převod nezastaví, vypíšou se jen
informativně.

Vědomé pokračování se ztrátou uvedených dat: `--allow-missing`.

### 6.9.4 Užitečné přepínače

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
> `--no-truncate` **není opatrnější varianta běžného převodu.** Používejte ho jen
> tehdy, když cíl obsahuje vlastní data, která musí zůstat zachovaná. Nad
> čerstvě založenou databází vede k opaku: `migrate.php` už naplnil číselníky
> (`countries`, `units`, `vat_rates`, `vat_classifications`,
> `bank_email_notice_providers` ...) výchozími řádky a import ze zdroje je vloží
> znovu se stejnými ID (chyba `1062 Duplicate entry`). Řešení je vytvořit cílovou
> databázi znovu prázdnou a převod zopakovat bez tohoto přepínače.

### 6.9.5 Kolize DNS aliasu `db` mezi dvěma Compose stacky

Jakmile je `app` kontejner MyÚčta připojený současně do sítě staré instalace
MyInvoice, existují v dosahu **dva různé hostname `db`**. Docker dává každé
service automatický síťový alias podle jejího jména a obě instalace mají
databázi pojmenovanou `db`. Embedded DNS pak může `db` přeložit na databázi
druhého stacku a aplikace se hlásí do cizí databáze.

Příznak je zavádějící, protože vypadá jako špatné heslo:

```
Access denied for user 'myucto'@'172.18.0.4' (using password: YES)
```

V `cfg.docker.php` proto po dobu převodu neuvádějte `db`, ale **jméno kontejneru**,
které je napříč Dockerem jedinečné:

```php
'db' => [
    'host' => 'myucto-db-1',
],
```

Totéž platí pro zdroj v migračním URL: `mysql://root:tajne@myinvoice-db-1:3306/myinvoice`.

### 6.9.6 Po změně `cfg.docker.php` nestačí `restart`

Produkční image má z výkonových důvodů `opcache.validate_timestamps=0`, takže
PHP nekontroluje časová razítka souborů a drží zkompilovanou verzi configu
v paměti. `docker compose restart app` proces PHP-FPM uvnitř kontejneru
zachová, takže aplikace dál běží nad **starým** `cfg.php` a úprava se zdánlivě
neprojeví. Kontejner je potřeba vytvořit znovu:

```powershell
docker compose up -d --force-recreate app
```

### 6.9.7 Návratové kódy

Návratové kódy `MyInvoiceMigrate.php`:

| Kód | Význam |
|---|---|
| `0` | hotovo |
| `1` | chyba argumentů nebo připojení |
| `2` | import skončil s chybami; dokončovací migrace neproběhly |
| `3` | preflight zastavil převod, aby se neztratila data |
| `4` | příprava schématu cíle selhala |
| `5` | data jsou přenesená, ale dokončovací migrace selhaly |

## 6.10 Související kapitoly

- [Po instalaci a CLI nástroje](05_Po_instalaci.md)
- [Instalace Docker](03_Instalace_Docker.md)
- [Licence a aktivace](104_Licence_a_aktivace.md)
- [Aktualizace](102_Aktualizace.md)
