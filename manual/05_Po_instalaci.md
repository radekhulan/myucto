# 5. Po instalaci a CLI nástroje

> Návod pro správce instalace: co udělat hned po nainstalování MyÚčta, jak
> naplánovat pravidelné úlohy (cron) a jak ověřit, že zálohy skutečně vznikají
> a dají se stáhnout. Platí pro Docker i nativní instalaci.

## 5.1 Kdy to potřebujete

Kapitolu otevřete, když:

- jste právě dokončili instalaci ([Docker](03_Instalace_Docker.md) nebo
  [nativní](04_Instalace_Nativni.md)) a aplikace ještě nemá žádného uživatele,
- potřebujete naplánovat zálohy, párování plateb, upomínky a další úlohy na pozadí,
- chcete ověřit, že plánované úlohy opravdu běží,
- chcete stáhnout zálohu z aplikace, aniž byste se přihlašovali na server,
- hledáte CLI příkaz pro migrace, ukázková data nebo reset.

Převádíte-li existující instalaci MyInvoice, neprocházejte nejdřív setup
wizardem. Použijte postup [Převod dat z MyInvoice do MyÚčto](06_Prevod_z_MyInvoice.md),
který zachová správné pořadí importu a migrací.

## 5.2 Než začnete

- Instalace dokončená a databáze dostupná (kapitoly 3 nebo 4).
- U Dockeru aplikace odpovídá na **http://localhost:8080**, u nativní instalace
  na adrese podle vašeho web serveru.
- Přístup k serveru, kde můžete nastavit Task Scheduler (Windows) nebo crontab
  (Linux), případně k hostiteli Dockeru.

## 5.3 Krok za krokem: první spuštění

1. Otevřete aplikaci v prohlížeči. Naskočí **setup wizard**.
2. Projděte průvodce: založíte první administrátorský účet, volitelně prvního
   dodavatele a základní konfiguraci. Postup je v kapitole
   [První spuštění (setup wizard)](07_Setup_wizard.md).
3. Po dokončení vás aplikace přihlásí a otevře Přehled.

**Jak poznáte, že je hotovo:** jste přihlášeni a vidíte Přehled. Wizard se už
znovu nezobrazí, objeví se až po `reset.php`.

## 5.4 Krok za krokem: nastavení hned po prvním přihlášení

1. **Dodavatel.** Otevřete `Firma → Nastavení` a na záložkách **Údaje firmy** a
   **Fakturace** doplňte IČO, DIČ, adresu a výchozí údaje faktur. Logo nahrajte
   v `Firma → Branding`, bankovní účty založte v `Peníze → Bankovní účty` na záložce **Měny a účty**
   (viz [Nastavení](96_Nastaveni.md)).
2. **Odchozí pošta (SMTP).** V `Systém → E-maily a certifikáty` na záložce
   **Odesílací profily** nastavte SMTP, aby fungovalo odesílání faktur a upomínek.
3. **Daňové nastavení.** Jste-li plátce DPH, v `Firma → Nastavení` na záložce
   **Daně a účetnictví** zadejte typ poplatníka, periodu DPH a kód finančního
   úřadu (viz [Výkazy DPH](41_Vykazy_DPH.md)).
4. **Zabezpečení.** Zapněte 2FA, případně IP allowlist, a v `Systém → Role a oprávnění`
   nastavte role uživatelů (viz [Bezpečnost](101_Bezpecnost.md)).
5. **Plánované úlohy.** Naplánujte cron podle [§ 5.5](#55-krok-za-krokem-naplanovani-uloh-cron).
6. **HTTPS a zálohy.** Viz [§ 5.9.1](#591-produkcni-doporuceni).

**Jak poznáte, že je hotovo:** můžete vystavit a odeslat zkušební fakturu a
stránka `Systém → Plánované úlohy` nehlásí chybějící úlohy.

## 5.5 Krok za krokem: naplánování úloh (cron)

V adresáři `cmd/` jsou připravené wrappery `.cmd` (Windows Task Scheduler) i
`.sh` (Linux cron). Zvolte **právě jeden** způsob plánování:

- **Jeden dispatcher.** Naplánujte pouze `cron-dispatch` každou minutu. Sám
  spouští úlohy v jejich časech a levnou kontrolou přeskočí ty, které nemají práci.
- **Jednotlivé úlohy.** Naplánujte každý potřebný wrapper samostatně podle
  tabulky v [§ 5.9.3](#593-tabulka-cron-skriptu).

Oba režimy nekombinujte, jinak by se některé úlohy spouštěly dvakrát.

1. Zvolte režim (doporučen je jeden dispatcher).
2. V Task Scheduleru nebo crontabu založte úlohu, která každou minutu spustí
   `cmd/cron-dispatch.cmd`, resp. `cmd/cron-dispatch.sh`.
3. U jednotlivých úloh zadejte frekvenci z tabulky.
4. Počkejte pár minut a otevřete `Systém → Plánované úlohy` (viz [§ 5.6](#56-krok-za-krokem-kontrola-ze-ulohy-bezi)).

**Jak poznáte, že je hotovo:** na stránce `Systém → Plánované úlohy` mají
doporučené úlohy čas posledního úspěšného běhu.

> [!TIP]
> Podrobnosti k wrapperům jsou v `cmd/README.md`. Cesty ukotvené relativně v
> `cfg.php` jsou popsané v [§ 5.9.7](#597-relativni-cesty-v-cfgphp).

## 5.6 Krok za krokem: kontrola, že úlohy běží

1. Otevřete `Systém → Plánované úlohy`.
2. U každé doporučené úlohy zkontrolujte, kdy naposled úspěšně proběhla.
3. Úlohy s varováním **Stáří**, **Selhává** nebo **Neběželo** opravte podle
   [§ 5.8](#58-kdyz-neco-nejde).

Každý cron skript si zapisuje vlastní záznam o běhu (start, konec, návratový
kód, JSON report). Stránka tak odhalí „cron vůbec není nastavený“ i „cron běží,
ale selhává“, bez ohledu na OS (crontab, Task Scheduler, Docker host).
Varování se zobrazí, pokud poslední běh chybí nebo je starší než
`max_age_hours` (typicky 36 hodin).

**Jak poznáte, že je hotovo:** žádná doporučená úloha nemá varování.

## 5.7 Krok za krokem: stažení záloh z aplikace

Hotové zálohy si správce instalace stáhne bez přístupu k serveru přes SSH nebo FTP.

1. Otevřete `Systém → Stažení záloh`.
2. Najděte sekci podle druhu zálohy (**Databáze**, **Dokumenty a přílohy**,
   **PDF doklady**, **Mzdy**, **Personální spisy**, **Ostatní**).
3. U souboru zkontrolujte čas pořízení a velikost a stáhněte ho.
4. Jsou-li zálohy šifrované, klikněte na **Zobrazit heslo** a znovu se ověřte
   (passkey, nebo přihlašovací heslo a kód z autentikátoru, máte-li ho zapnutý).

**Jak poznáte, že je hotovo:** stažený soubor má očekávanou velikost a otevře se
(šifrované archivy 7-Zipem, WinRARem nebo `unzip -P`).

> [!WARNING]
> Pokud zálohy šifrované nejsou, stránka to řekne nahlas. Soubor bez hesla
> otevře celé účetnictví komukoli, kdo se k němu dostane.

## 5.8 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Úloha má štítek **Neběželo** | Cron není naplánovaný nebo neběží | Zkontrolujte Task Scheduler nebo crontab, viz [§ 5.5](#55-krok-za-krokem-naplanovani-uloh-cron) |
| Úloha má štítek **Selhává** | Cron běží, ale úloha končí chybou | Otevřete její log a opravte příčinu (práva zápisu do `log/` a `storage/`, dostupnost databáze) |
| Úloha má štítek **Stáří** | Poslední běh je starší než povolený limit | Ověřte frekvenci plánování úlohy |
| Úloha (`cron-epo-status`, `cron-ai-worker`) ukazuje **Nemá práci** | Dispatcher ji nespouští, dokud není co dělat | Nic. Jde o neutrální stav, viz [§ 5.9.8](#598-stav-nema-praci) |
| Záloha se nevytvořila a úloha skončila chybou, přestože je nastavené `cron.backup.password` | PHP nepodporuje šifrování ZIP (libzip < 1.2) | Aktualizujte PHP/libzip. Nešifrovaná záloha se záměrně nevytvoří |
| Šifrovaný ZIP nejde otevřít v Průzkumníku Windows | Průzkumník AES-256 archivy neumí | Použijte 7-Zip, WinRAR nebo `unzip -P` |
| Import i vystavení odmítají každý doklad se sazbou vyšší než 0 % | Zmizel číselník sazeb členských států | Spusťte `php api/bin/migrate.php`, který ho sám opraví |

## 5.9 Podrobnosti a pravidla

### 5.9.1 Produkční doporučení

- Nasazujte za **HTTPS** (u Dockeru reverse proxy, viz
  [§ 3.8 HTTPS / TLS terminace](03_Instalace_Docker.md#38-krok-za-krokem-https-pres-reverse-proxy)).
- Zapněte **zálohy** a ověřte, že běží (`Systém → Plánované úlohy`).
- Pinujte konkrétní neměnný release tag image a sledujte [Aktualizace](102_Aktualizace.md).

### 5.9.2 CLI nástroje

```bash
php api/bin/migrate.php              # spustí pending migrace
php api/bin/migrate.php --status     # vypíše stav migrací
php api/bin/setup.php                # interaktivní úvodní zřízení
php api/bin/sample.php               # vygeneruje testovací data (po setupu)
php api/bin/sample.php --list        # vypíše firmy a jestli už data mají
php api/bin/sample.php --supplier=7  # testovací data do konkrétní prázdné firmy
php api/bin/reset.php                # smaže všechna user-data (vyžaduje "ANO")
php api/bin/recompute-stats.php      # přepočítá agregované statistiky
php api/bin/delete-company.php --id=7                   # náhled, co se smaže s firmou
php api/bin/delete-company.php --id=7 --confirm=<IČO>   # smaže firmu se všemi daty
```

`delete-company.php` smaže jednu firmu včetně dokladů, deníku a převodů, například
před zopakováním převodu do čisté firmy. Smazání potvrzuje IČO firmy a běží v jedné
transakci. `--files` smaže i soubory firmy v úložišti. Má-li firma mzdová podání
nebo jiné záznamy jen pro přidávání, je potřeba `--immutable`. Smazání nejde vrátit,
proto si předem zálohujte databázi.

> [!WARNING]
> `reset.php` maže **uživatelská data**, ne instalaci. Globální číselníky (země, sazby
> DPH, sazby členských států pro OSS, výkazy, příjemci podání) i provozní údaje
> instance (licence, režim plánovaných úloh, smlouva o zálohování) zůstávají. Po
> jejich smazání by je totiž nikdo nevrátil, protože je seedují migrace a ty jsou
> evidované jako proběhlé.
>
> `reset.php --keep-users-supplier` navíc ponechá účty, firmy a jejich konfiguraci
> (historii plátcovství DPH, režim a období účetnictví, účtovou osnovu a předkontace,
> nastavení mezd, podepisování a napojení) a smaže jen doklady a další data.
>
> Soubory v `storage/` maže reset jen ve složkách firem z resetované databáze. Když
> úložiště obsahuje složky firem, které databáze nezná (sdílí ho jiná instance),
> soubory nechá být, dokud ho nespustíte s `--force-files`. Na stroji s více
> instancemi nastavte každé vlastní `MYINVOICE_DATA_DIR`.
>
> Kdyby přesto číselník sazeb členských států kdykoli zmizel, vrátí ho
> `php api/bin/migrate.php`, má na to sebeopravný krok. Poznáte to podle toho, že
> import i vystavení odmítnou každý doklad se sazbou vyšší než 0 %.

### 5.9.3 Tabulka cron skriptů

| Skript | Doporučená frekvence |
|---|---|
| `cron-cleanup` | 1× denně 03:00 |
| `cron-retention` | 1× denně 03:15; úklid starých záloh, logů a dočasných souborů. Volitelný, zapíná se v `cfg.php` (viz [§ 5.9.5](#595-uklid-zaloh-a-logu)) |
| `cron-backup` | 4× denně (02:00, 08:00, 14:00, 20:00) |
| `cron-backup-pdf` | 1× denně 02:30 |
| `cron-backup-documents` | 1× denně 02:35 |
| `cron-backup-payroll` | 1× denně 02:40 |
| `cron-backup-personnel` | 1× denně 02:45; personální spisy zaměstnanců, záměrně samostatná záloha |
| `cron-bank-scan` | každých 30 min |
| `cron-bank-email-notices` | každých 30 min |
| `cron-scan-purchase-inbox` | každých 10 min |
| `cron-send-reminders` | 1× denně 09:00, Po-Pá |
| `cron-send-approval-reminders` | 1× denně 09:15, Po-Pá |
| `cron-purchase-approval-reminders` | 1× denně 09:20, Po-Pá |
| `cron-document-request-reminders` | 1× denně 09:30, Po-Pá |
| `cron-epo-status` | každou minutu; jednotlivé pokusy mají vlastní odstup |
| `cron-generate-recurring-invoices` | 1× denně 06:30 |
| `cron-automation-digest` | každou hodinu v ranním okně 06:00-08:00 |
| `cron-ai-worker` | každých 10 min; zpracuje frontu po zapnutí AI asistence |
| `cron-ai-rule-miner` | 1× denně 04:00; vytváří návrhová pravidla z korekcí |
| `cron-shoptet-orders` | každých 15 min; jen firmy se zapnutým automatickým stahováním objednávek ze Shoptetu, interval určuje firma ([§ 37.5](39_Shoptet.md)) |
| `cron-payroll-post` | 1× měsíčně 1. dne 04:00; zaúčtuje mzdy za předchozí měsíc |
| `cron-payroll-registration-changes` | 1× denně 05:00; jen firmy se zapnutými mzdami. Hledá změny hlásitelné do registru pojištěnců (ČSSZ) a zakládá návrh povinnosti s termínem, nic neodesílá. Denní běh stačí: lhůta je osm dnů ([§ 85.14.4](85_Podani_a_hlaseni.md#85144-fronta-k-odeslani)). Bez ní se změna zjistí jen tehdy, když někdo otevře kartu zaměstnance, a lhůta uteče |
| `cron-vat-clearing` | 1× měsíčně 1. dne 04:30; interní doklad zúčtování DPH za skončené období ([§ 84.3.3](66_Ucetni_osnova.md#6686-mesicni-zuctovani-dph)) |
| `cron-vat-status-apply` | 1× denně 00:30; aplikuje plánované změny plátcovství DPH v den účinnosti |
| `cron-journal-integrity-check` | 1× denně 02:30; čtecí kontrola integrity deníku |
| `cron-cnb-rates` | 1× denně 15:00; stahuje kurzovní lístek ČNB do kurzové historie a dohání mezery za posledních 30 dnů. Bez ní se kurzy plní jen náhodně při prvním dotazu a cizoměnová úhrada ke dni bez kurzu se nemá čím ocenit |
| `cron-license-renew` | každou hodinu v 15. minutě; server se běžně kontroluje 1× denně, kolem platby a při prodlení 1× za hodinu |
| `cron-maintenance-notice` | každých 15 minut; ohlášení plánované údržby od provozovatele (ve spravovaném provozu povinné, jinak volitelné přes `maintenance_notice.enabled`) |
| `cron-version-check` | 1× denně 06:00; kontrola dostupné aktualizace |
| `cron-dispatch` | každou minutu, pouze v režimu jednoho dispatcheru |

### 5.9.4 Šifrování záloh

Volitelné heslo `cron.backup.password` v `cfg.php` zašifruje všechny typy ZIP
záloh (DB dump, PDF dokladů, sekce Dokumenty, mzdové podklady) algoritmem
AES-256. Pro rozbalení použijte 7-Zip, WinRAR nebo `unzip -P`, vestavěný
Průzkumník Windows šifrované AES-256 archivy neumí otevřít. Šifruje se obsah
souborů, názvy souborů uvnitř archivu zůstávají čitelné. Pokud je heslo
nastavené a PHP šifrování nepodporuje (libzip < 1.2), záloha se záměrně
nevytvoří a úloha skončí chybou. Nešifrovaná záloha by vznikla jen omylem.

### 5.9.5 Úklid záloh a logů

Zálohovací úlohy samy drží 30 dnů denních záloh a k tomu zálohu z 1. dne
každého měsíce po dobu roku. Úloha `cron-retention` tuhle dobu zkracuje a
uklízí i logy a dočasné soubory, které jinak nemaže nic. Na vlastním serveru je
vypnutá, protože zálohy aplikace tam často bývají jedinou zálohou, kterou máte.
Zapnete ji v `cfg.php` volbou `cron.retention.enabled = true`. Výchozí limity
(každý lze v `cron.retention` změnit, hodnota 0 znamená „tuhle kategorii
neuklízet“):

| Co | Kolik se drží |
|---|---|
| Dumpy databáze | 7 dnů: z posledních 48 hodin všechny, ze starších dnů jen poslední dump dne |
| Zálohy PDF, Dokumentů a Mezd | 3 poslední od každého druhu (každá je úplný snímek) |
| Logy aplikace a cronů | 14 dnů; log, do kterého se pořád zapisuje, se nad 20 MB zkrátí |
| Dočasné soubory, diagnostické balíčky | 48 hodin |
| Zkompilované šablony PDF (Twig cache) | 30 dnů; potřebná šablona se při dalším tisku zkompiluje znovu |
| Archivy kompletního exportu | do konce jejich platnosti (`export.instance.ttl_days`, 14 dnů) |

Nejnovější záloha každého druhu zůstává vždy, i když je starší než limit.
Doklady, dokumenty, mzdové podklady, účetní archivy ani uzávěrkové balíčky
úloha nemaže nikdy. Co by smazala, vypíše bez mazání příkaz
`php api/bin/cron-retention.php --dry-run`.

### 5.9.6 Sekce stránky Stažení záloh

Stránka `Systém → Stažení záloh` ukazuje obsah adresáře se zálohami rozdělený
do sekcí podle toho, která úloha soubor vyrobila:

- **Databáze**: úplný dump databáze z `cron-backup`,
- **Dokumenty a přílohy**: nahrané soubory a bankovní výpisy z `cron-backup-documents`,
- **PDF doklady**: vygenerovaná PDF z `cron-backup-pdf`,
- **Mzdy**: mzdové podklady z `cron-backup-payroll`,
- **Personální spisy**: soubory personálních spisů zaměstnanců z `cron-backup-personnel`,
- **Ostatní**: soubory, které pojmenování automatických záloh neodpovídají,
  typicky ruční kopie bez data v názvu.

Sekce se pozná podle tvaru názvu souboru, ne podle jména databáze, takže zálohy
zůstanou ve správné sekci i po přejmenování databáze. V každé sekci je
k dispozici pět nejnovějších záloh. V záhlaví sekce je vidět, kolik jich na
disku leží celkem a kolik zabírají místa. Starší zálohy zůstávají na serveru,
dokud je nesmaže retence.

U každého souboru je čas pořízení a velikost. Pod seznamem je cesta k adresáři
na serveru, počet záloh a nastavená retence, tedy kolik souborů (nebo dnů)
zpět se drží, než je úloha smaže.

Heslo k šifrovaným zálohám se nezobrazuje samo od sebe ani přihlášenému správci.
Nejdřív se musíte znovu ověřit passkeyem, nebo přihlašovacím heslem a kódem
z autentikátoru, máte-li ho zapnutý. Každé odhalení se zapisuje do protokolu
činnosti (kdo a kdy, samotné heslo nikdy).

Stránka je doplněk **Kompletního exportu dat** (§ 88.6.2), ne jeho náhrada.
Export je jednorázový balíček aktuálního stavu jedné firmy na vyžádání, tady
leží historie automatických záloh celé instalace.

### 5.9.7 Relativní cesty v `cfg.php`

Cestové klíče (`cron.backup.output_dir`, `storage.*`, `logging.path`, archivy
přijatých/importovaných dokladů, DKIM) zadané relativně (např. `storage/backup`)
se ukotvují k **rootu aplikace**, ne k pracovnímu adresáři procesu. Záloha tak
skončí na očekávaném místě, i když cron běží pod Task Schedulerem nebo systémovým
cronem s jiným aktuálním adresářem. Absolutní cesty (včetně `C:\...` a UNC
`\\server`) i `MYINVOICE_DATA_DIR` zůstávají beze změny.

### 5.9.8 Stav „Nemá práci“

Platí jen v režimu jednoho dispatcheru. Dispatcher úlohy, které mají levnou
kontrolu práce (`cron-epo-status`, `cron-ai-worker`), vůbec nespouští, dokud pro
ně není co dělat, jejich poslední běh proto legitimně stárne. Že plánování
funguje, dokládá záznam o běhu samotného `cron-dispatch`, a takové úloze se
místo varování ukáže neutrální **Nemá práci**. Jakmile dispatcher sám zestárne
nebo začne selhávat, vrátí se u nich normální varování.

## 5.10 Související kapitoly

- [Instalace Docker](03_Instalace_Docker.md) a [Nativní instalace](04_Instalace_Nativni.md)
- [První spuštění (setup wizard)](07_Setup_wizard.md)
- [Převod dat z MyInvoice do MyÚčto](06_Prevod_z_MyInvoice.md)
- [Nastavení](96_Nastaveni.md), [Bezpečnost](101_Bezpecnost.md), [Aktualizace](102_Aktualizace.md)
