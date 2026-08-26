# 92. Nastavení

V hlavním menu **Systém** je rozbalovací podmenu se sekcemi pro konfiguraci
aplikace:

- **Dodavatelé** — viz [91. Multi-supplier](91_Multi_supplier.md)
- **Číselníky** — DPH sazby, země, jednotky a další pomocné seznamy
- **Uživatelé** — správa lidí, kteří se přihlašují
- **E-mail šablony** — texty automatických e-mailů
- **Activity log** — kdo co změnil
- **Exporty** — viz [20. Exporty](20_Exporty.md)

Správa opakovaně používaných fakturačních položek je kvůli návaznosti na
vystavování dokladů v menu **Prodej → Ceník**.

## 92.1 Číselníky

**Systém → Číselníky**.

![Číselníky — Měny](img/15_ciselniky_meny.webp)

4 záložky:

### 92.1.1 Měny

Měny a bankovní účty aktuálního dodavatele jsou soustředěné na stránce
**Peníze → Bankovní účty** (viz [29. Bankovní účty](29_Bankovni_ucty.md)).
Každý řádek představuje jeden bankovní účet v dané
měně; pokud máš víc účtů pro stejnou měnu, založ více řádků se stejným kódem
měny.

| Pole | Význam |
|---|---|
| Kód | ISO 4217 — `CZK`, `EUR`, `USD`, `GBP` |
| Označení | „CZK — KB", „EUR — Fio" — pro UI rozlišení (víc účtů per měna) |
| Symbol | `Kč`, `€`, `$`, `£` |
| Název CS / EN | „Koruna" / „Crown" |
| Decimals | Počet desetinných míst (2 typicky) |
| Aktivní | Vypnutá měna nelze pro nové faktury |
| Default pro kód | Pokud máš víc účtů per měna (např. 2× CZK), který je default |
| **Účet** (CZK) | Číslo účtu (např. `1000000005`) + bank kód (`0100`) + název banky |
| **Účet** (EUR) | IBAN + BIC + název banky |

> ⚠️ Po **změně bankovního účtu** se **automaticky invaliduje PDF cache**
> všech faktur, které renderují bank info live (drafty + faktury bez
> snapshotu). Faktury v stavu `issued+` mají immutable `bank_snapshot`.

Na stejné stránce je i konfigurace **bankovních e-mailových avíz**: IMAP účty,
mapování bankovní účet → IMAP účet → parser, parser provideri a přehled
zpracovaných e-mailů. Detail je v [§ 28.7 Bankovní e-mailová avíza](28_Banka.md).

### 92.1.2 Sazby DPH

![Číselníky — DPH](img/15_ciselniky_dph.webp)

| Pole | Význam |
|---|---|
| Kód | `CZ-21`, `CZ-12`, `CZ-0`, `CZ-RC`, pro OSS např. `PL-23` |
| Sazba | `21`, `12`, `0`, `0` |
| Stát | Kód země, ve které sazba platí — `CZ` u tuzemských, `PL` / `SK` / `HU` … u sazeb členských států |
| Popisek CS / EN | Pro UI / PDF |
| Default | Která sazba se předvyplní v editoru |
| Reverse charge | Zatrhneme pro `CZ-RC` |
| Platnost od | Pro historické faktury (15 % v roce 2023) |

Pro OSS založ sazby jednotlivých členských států se správným kódem země,
například `SK-23`. V editoru faktury se zahraniční sazby nabídnou na řádku
označeném jako OSS; běžný tuzemský řádek dál používá domácí sazby.

> [!WARNING]
> **Pole Stát formulář předvyplňuje na `CZ`.** U zahraniční sazby ho musíš přepsat.
> Sazba pojmenovaná `PL-23`, která má ve sloupci Stát `CZ`, je pro systém česká
> sazba 23 % — a takovou ČR nezná. Import zahraničních dokladů se v takovém případě
> **zastaví** a v reportu řekne, u které sazby a na jaký stát zemi opravit. Je to
> záměrná pojistka: kdyby se sazba se špatnou zemí použila, skončila by cizí daň
> v českém přiznání k DPH. Zemi zkontroluj dřív, než spustíš import nebo hromadnou
> úpravu OSS.

### 92.1.3 92.1.2a OSS a daňové nastavení

V **Nastavení → Daně a účetnictví** je karta **Režim OSS (One Stop Shop)** —
čtvrtá v řadě za kartami *Vést účetnictví*, *Vést mzdy* a *Vést skladovou
evidenci*. Po zaškrtnutí se odkryje země identifikace (typicky `CZ`), měna
podání (typicky `EUR`) a volitelně datum začátku a konce registrace. Zapnutý
režim zobrazí OSS pole v editoru položek a v menu **Daně → OSS přiznání**
kvartální přehled a XML export.

Zařazení jednotlivých řádků do OSS už řešit nemusíš — odvozuje se automaticky
ze sazby, země odběratele a číselníku sazeb členských států, a to ve všech
kanálech včetně importu a API. Sporné případy aplikace do OSS zařadí (nebo
nechá v tuzemsku, podle kanálu) a označí je k ručnímu posouzení. Aplikace
celounijní práh 10 000 EUR sleduje orientačně a upozorní na jeho blížící se
překročení, ale režim sama nezapne.
Podrobnosti — odvození řádku, plnění k ručnímu posouzení, hromadná úprava,
účtování, podání a evidence — má vlastní kapitola
[40. Režim OSS (One Stop Shop)](40_OSS.md).

### 92.1.4 92.1.2b Sazby států OSS

**Cesta: `Nastavení → Číselníky → Sazby států OSS`.**

Tenhle číselník obsahuje **sazby DPH členských států, proti kterým se OSS doklady
ověřují**. Je to kontrolní číselník, ne sazby pro doklad — ty se zakládají o kartu
vedle v [Sazbách DPH](#9212-sazby-dph). Rozdíl je podstatný: sazby DPH si zakládáš ty
(a můžeš v nich mít překlep), kdežto tenhle číselník je společný pro celou instanci
a slouží jako nezávislá autorita při rozhodování, jestli plnění patří do tuzemska,
nebo do OSS. Právě proto se ho aplikace ptá při importu, v editoru i při hromadné
úpravě.

| Sloupec | Význam |
|---|---|
| **Stát** | Dvoupísmenný kód členského státu |
| **Typ sazby** | Základní / Snížená / Druhá snížená / Parkovací |
| **Sazba** | Procento |
| **Platí od** / **Platí do** | Historie sazby; prázdné „Platí do" znamená, že sazba platí dosud |
| **Poznámka** | Volný text |
| **Původ** | `systémová` (dodaná s aplikací) nebo `vlastní` (přidal uživatel) |

Nahoře je filtr podle státu a zaškrtávátko **Zobrazit vyřazené**.

**Systémovou sazbu nelze přepsat.** Její data používá aktualizační migrace k rozpoznání,
co je vlastní záznam a co ne — přepsáním by se vlastní úprava při dalším upgradu ztratila.
Systémové sazbě jde jen **Zkrátit** platnost k datu a vedle ní založit novou. Vlastní
sazbu lze plnou měrou editovat i smazat. Obojí jde **Vyřadit** (schová se z výběru)
a zase **Vrátit**.

Číselník smí měnit jen správce instance, protože je společný pro všechny firmy v ní.

**Kdy do něj sáhnout.** Když některý členský stát změní sazbu, která v
systémovém číselníku ještě není, zkrať platnost systémové sazby ke dni před
účinností a založ vedle ní vlastní s novým procentem. Dokud to
neuděláš, bude aplikace u dokladů s novou sazbou hlásit, že sazba v číselníku k datu
plnění není.

> [!NOTE]
> Hláška **„Číselník v databázi není — chybí migrace"** znamená, že se po aktualizaci
> nespustily databázové migrace. Spusť je (`php api/bin/migrate.php`) — do té doby se
> neověřuje žádný stát a import zahraničních dokladů se vůbec nerozběhne. Není to totéž
> jako „stát v číselníku chybí"; aplikace ty dva stavy rozlišuje a hlásí každý zvlášť.

Ve stejném bloku zůstává vedle ID datové schránky také **typ datové schránky**
(`FO`, `PFO`, `PO`, `OVM`). Tento údaj MyÚčto zachovává pro EPO a další
elektronická podání; typ poplatníka se přitom nastavuje samostatně podle právní
formy firmy.

### 92.1.5 Země

Statický číselník — nemělo by být potřeba editovat. Obsahuje 200+ zemí podle
ISO 3166-1.

### 92.1.6 Jednotky

Číselník měrných jednotek pro položky faktury. Globální (sdílený mezi
dodavateli), nahrazuje volný textový vstup za dropdown.

| Pole | Význam |
|---|---|
| Kód | Krátký identifikátor (`h`, `ks`, `den`, `měs.`) |
| Popisek CS / EN | Co se zobrazí v UI / PDF (`hodina` / `hour`) |
| Default | Která jednotka se předvyplní při přidání nové položky (typicky `h`) |
| Pořadí | Číslo pro řazení v dropdownu |

> 💡 **Default = `hodina`** dává smysl, protože nová položka přebírá
> hodinovou sazbu z projektu/klienta. Pro jednorázové položky (paušál,
> licence, materiál) jednotku ručně přepneš.

> 🛈 **Auto-clean prázdných položek** — při uložení faktury se řádky bez
> popisu i bez ceny tiše smažou. Můžeš tedy v editoru přidat víc řádků na
> zásobu a nepoužité se neuloží.

### 92.1.7 Ceníkové položky

**Prodej → Ceník** (jen administrátor) spravuje ceník aktuálního dodavatele.
Každá položka má kód, název, fakturační popis, jednotku, sazbu DPH a povinnou
základní cenu v jedné měně. Kód je unikátní pouze v rámci dodavatele.
Přehled lze prohledávat a filtrovat podle měny a aktivního či archivovaného
stavu.

Tento jednoduchý ceník je dostupný jen firmám bez aktivního modulu
**Sklad**. E-shop je součástí skladového modulu a používá společné
skladové karty, ceny a zákaznické cenové výjimky; po zapnutí skladu se proto
samostatný Ceník nezobrazuje v menu ani v editorech dokladů.

Pro další aktivní měny lze zadat vlastní pevnou cenu. Když zapneš
**Povolit přepočet kurzem ČNB**, chybějící měnová cena se dopočte ze základní
ceny. Pevná cena v cílové měně má vždy přednost. Náhled ukazuje zdrojovou cenu,
výslednou částku, křížový kurz a skutečné datum použitého kurzovního lístku.

V sekci **Individuální ceny zákazníků** lze pro položku, zákazníka a měnu zadat
odlišnou cenu. Pořadí použití je:

1. individuální cena zákazníka v měně dokladu,
2. obecná pevná cena v měně dokladu,
3. individuální cena zákazníka v základní měně přepočtená kurzem,
4. obecná základní cena přepočtená kurzem.

U uložené individuální ceny se zobrazuje také počet opakovaných šablon daného
zákazníka, které jsou na položku napojené.

Ceníková položka určuje, zda jsou její ceny s DPH, nebo bez DPH. Do dokladu či
šablony ji lze vložit jen při shodném režimu. Používaná položka se při smazání
archivuje, aby zůstaly zachované vazby a pevné snapshoty šablon.

## 92.2 Uživatelé

**Systém → Uživatelé** (jen pro superadmina).

![Uživatelé](img/15_users.webp)

Tabulka uživatelů, kteří se mohou přihlásit. Tlačítko **+ Nový uživatel**.

### 92.2.1 Pole formuláře

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI |
| E-mail | Login |
| Heslo | Min. 12 znaků |
| Role | Výchozí aktivní role z číselníku rolí |
| Jazyk | `cs` / `en` |
| Aktivní | Vypnutý uživatel nemůže se přihlásit |

### 92.2.2 Role a oprávnění

**Systém → Role** nabízí databázový číselník rolí. Superadmin může vytvořit
interní roli typu **staff** nebo externí roli typu **client** a pro každou
nastavit oprávnění po modulech a významných akcích:

| Úroveň | Význam |
|---|---|
| **Neviditelné** | Modul ani akce se uživateli nezobrazí a server přístup odmítne. |
| **Pouze čtení** | Uživatel vidí seznamy, detaily, historii a povolené výstupy, ale nemění data. |
| **Zápis** | Zahrnuje čtení a dovoluje příslušnou změnu nebo akci. |

Role má po vytvoření neměnný typ. Klientské role nabízejí jen funkce bezpečné
pro klientský portál; interní účetnictví, banka a globální správa se jim
nepovolí. Systémová role **Superadmin** je uzamčená, nelze ji deaktivovat,
smazat ani upravit její matici a jako jediná smí spravovat uživatele a role.

Předávání originálů používá záměrně oddělená práva. Klientské
**Předávat doklady účetní** dovolí pouze vložit a sledovat vlastní podání aktuální
firmy. Interní **Příchozí doklady** dovolí účetní frontu číst nebo zpracovávat —
včetně nahrání dokladu, který přišel mimo portál; pro vznik faktury a případnou AI
extrakci jsou navíc potřeba jejich vlastní oprávnění.

**Trvale vyřadit z příchozí fronty** je samostatné právo a žádná systémová role
kromě správce ho nemá. Odmítnutí dokladu totiž originál záměrně nemaže (zůstává
v Dokumentech i v auditní stopě), takže úklid fronty i s originálem je vědomý zásah.
Komu ho chcete dát, přidejte ho v editoru rolí — role se dají kopírovat, takže
stačí jednou nastavit „správce podatelny" a dál z něj vycházet.

Roli lze duplikovat jako základ nové role. Používanou roli nelze smazat;
nejprve je nutné přeřadit uživatele a odstranit její přepisy u firem. Používanou
roli lze deaktivovat, ale její uživatelé tím okamžitě ztratí firemní oprávnění.
Při souběžné editaci systém odmítne starší změnu, aby si administrátoři navzájem
nepřepsali nastavení.

### 92.2.3 Přiřazení firem

Každý uživatel kromě superadmina má přístup pouze k firmám, které mu
superadmin výslovně přidá v editaci uživatele. Firma se vyhledá podle názvu,
IČO nebo ID; aplikace kvůli tomu nenačítá celý seznam firem.

U přiřazené firmy lze ponechat **Výchozí roli**, nebo vybrat jinou aktivní roli
stejného typu. Přepis se s výchozí rolí nesčítá — pro danou firmu ji úplně
nahradí. Prázdný seznam přiřazených firem znamená nulový přístup k firemním
datům; přihlášení, změna hesla, 2FA a odhlášení zůstávají dostupné.

Po přepnutí firmy aplikace načte oprávnění znovu. Změna role, její deaktivace
nebo odebrání firmy se proto projeví bez nového přihlášení a také u existujících
API tokenů.

> 🛈 Systém brání odebrání nebo deaktivaci posledního aktivního superadmina.

## 92.3 Můj profil

**Pravý horní roh → klik na jméno → Můj profil**. Stejná obrazovka jako
[§ 8.5 Můj profil](08_Prihlaseni.md) — viz screenshot tam.

Můžeš si změnit:

- **Jméno + jazyk**
- **Heslo** — vyžaduje původní heslo
- **TOTP** — zobrazit stav a aktivovat pomocí QR + ověřovacího kódu
- **Passkeys** — přidat, pojmenovat, přejmenovat a odvolat vlastní přístupové
  klíče
- **Zámek aplikace** — převzít interval správce nebo nastavit vlastní přísnější
  interval nečinnosti

Přidání nebo odvolání passkey vyžaduje čerstvý passkey/TOTP step-up; první
passkey účtu bez silného faktoru vyžádá aktuální heslo. Odvolání passkey
zneplatní ostatní session účtu. Při povinném MFA nelze odebrat poslední
povolený silný faktor.

V uživatelském menu je také akce **Zamknout**. Správce nastavuje výchozí
serverový zámek a současně horní limit osobní volby v `cfg.php`:

```php
'session' => [
    'lock_after_minutes' => 15, // kladná hodnota zámek zapne; výchozí je 0
],
```

Stejné nastavení lze předat přes
`MYINVOICE_SESSION_LOCK_AFTER_MINUTES`. Automatický zámek je ve výchozím stavu
vypnutý (`0`). Při této hodnotě jej může uživatel dobrovolně zapnout v profilu
v rozsahu 1 až 1440 minut. Kladná hodnota správce platí pro uživatele, kteří
zvolili **Použít nastavení správce**, a je nepřekročitelným maximem; vlastní
interval proto může být jen stejný nebo kratší. Ruční zamknutí zůstává dostupné
vždy. Podrobnosti jsou v [97. Bezpečnost](97_Bezpecnost.md).

## 92.4 E-mailové šablony

**Systém → E-mail šablony**.

![E-mail šablony](img/15_emails_list.webp)

Seznam šablon:

| Kód | Použití |
|---|---|
| `invoice_send` | Odeslání faktury klientovi |
| `invoice_reminder` | Upomínka po splatnosti |
| `proforma_reminder` | Připomínka nezaplacené zálohové faktury |
| `invoice_payment_thanks` | Poděkování za úhradu (viz § 33.5.5) — má i variantu pro zálohu |
| `invoice_approval` | Žádost o schválení výkazu víceprací zákazníkem |
| `recurring_draft_reminder` | Připomínka otevřeného konceptu pravidelné fakturace |
| `password_reset` | Reset hesla (system) |
| `login_otp` | Ověřovací kód pro přihlášení (system) |
| `welcome` | Uvítací e-mail novému uživateli |
| `test` | Pro Test odeslání (debug) |

### 92.4.1 Editor šablony

Klik na řádek → editor.

Záložky podle jazyka × formátu:

- **CS HTML** — česká verze, plný HTML
- **CS Text** — plain text fallback
- **EN HTML** — anglická verze
- **EN Text** — anglický plain text

Editor je **CodeMirror** s syntaxí Twig.

### 92.4.2 Předmět

Pole nahoře, podporuje placeholders (`{{ varsymbol }}`, …).

### 92.4.3 Test odeslání

Tlačítko **Test e-mail** dole — pošle vyplněnou šablonu na **tvůj** e-mail
(přihlášeného admina) s vzorovými daty (faktura `2605001`, klient „Test
Klient s.r.o.", …).

### 92.4.4 Placeholders

Závisí na typu šablony. `invoice_new`:

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol |
| `{{ amount }}` | Částka (formátovaná) |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Splatnost |
| `{{ client_name }}` | Klient |
| `{{ supplier_name }}` | Dodavatel |
| `{{ pdf_url }}` | Odkaz pro stažení PDF (pokud máš public link) |

## 92.5 Activity log

**Systém → Activity log**.

![Activity log](img/15_activity.webp)

Audit všech mutací — kdo a kdy co změnil. Lze filtrovat:

| Filtr | Hodnoty |
|---|---|
| Akce | `invoice.created`, `invoice.issued`, `invoice.sent`, `invoice.paid`, `client.updated`, … |
| Uživatel | Dropdown se všemi |
| Entita | Typ (`invoice` / `client` / `project` / …) + ID |
| IP | IPv4 / IPv6 |
| Období | Měsíc / vlastní rozsah |
| Dodavatel | Per-dodavatel filtrování |

Použití:

- **Audit chyby** — „Kdo upravil fakturu 2605007?" → filter `entity_type=invoice, entity_id=N`
- **Bezpečnostní audit** — „Bylo to z očekávané IP?" → filter `ip`
- **Outage timeline** — všechny akce v intervalu

> 🛈 Activity log se **nepromazává vůbec** — cron `cron-cleanup.sh` se ho nedotýká a žádné
> nastavení retence pro něj neexistuje. Je to záměr: auditní stopa nad účetními doklady
> podléhá stejné povinnosti uchovávat jako doklady samotné (§ 31 ZoÚ), takže by ji rotace
> po několika měsících znehodnotila. Přehled retenčních lhůt najdete v
> **Nástroje → Účetní nastavení → Archiv účetnictví** (viz
> [Retence a právní zadržení](88_Ucetni_nastroje.md#887-retence-a-pravni-zadrzeni-na-backendu)).

## 92.6 Elektronické podpisy

Elektronické podpisy mají vlastní stránku **Systém -> Elektronické podpisy**.
Aktuální konfigurace už není jeden certifikát dodavatele, ale sada
podpisových profilů a mapování pro jednotlivé výstupy.

## 92.7 Odesílací e-mailové profily

**Systém → E-maily → záložka Odesílací profily** definuje identitu, pod kterou
aplikace posílá odchozí e-maily aktuálního dodavatele.

Profil obsahuje:

- **From e-mail** a **From jméno** — adresa a jméno v hlavičce odesílatele,
- volitelnou volbu **Konfigurovat Reply-To** — po zapnutí lze vyplnit odpovědní
  adresu a jméno; když není zapnutá, profil hlavičku `Reply-To` do e-mailu
  nevkládá a odpovědi tak směřují na `From` z profilu,
- volitelný **S/MIME profil** — certifikát, který se použije pro podepsané
  e-mailové výstupy; formulář hlídá shodu certifikační e-mailové identity s
  `From e-mailem` a umí `From` z certifikátu předvyplnit,
- volitelnou volbu **Konfigurovat DKIM** — po zapnutí je nutné vyplnit DKIM
  doménu i selector pro tento profil; když není zapnutá, profil DKIM podpis
  nepoužije,
- **Transport** — výchozí globální `cfg.php`, vlastní SMTP účet nebo lokální
  `sendmail`; u vlastního SMTP lze nastavit server, port, šifrování, typ
  autentizace, TLS validaci, timeout a držení spojení, SMTP heslo/token se
  ukládá šifrovaně,
- volitelnou volbu **Ukládat kopii do IMAP složky odeslané pošty** — po
  úspěšném odeslání přes SMTP/transport se finální MIME zpráva uloží do zadané
  IMAP složky; IMAP heslo se ukládá šifrovaně; pole složky umí načíst seznam
  složek z aktuálně vyplněného IMAP účtu a ověřit připojení i cílovou složku
  včetně testovacího zápisu bez uložení profilu; lze nastavit timeout, označení
  uložené kopie jako přečtené a chování při chybě IMAP uložení,
- přepínače **Výchozí profil** a **Aktivní**.

Povinná pole jsou ve formuláři označená hvězdičkou. Před uložením i před
odesláním testu aplikace zkontroluje aktuálně zobrazené povinné položky
(`Reply-To`, DKIM, SMTP autentizace a IMAP podle zapnutých voleb) a bez jejich
vyplnění akci nespustí.

Ve formuláři profilu i u každého uloženého profilu je akce **Test**, která
pošle krátký testovací e-mail na e-mail přihlášeného uživatele, případně na
e-mail dodavatele nebo globální `cfg.php → smtp.from_email`. Test ve formuláři
použije aktuálně vyplněné hodnoty bez uložení do databáze. Test použije přímo
vybraný profil, i když není výchozí, takže ověřuje jeho `From`, `Reply-To`,
DKIM/S/MIME, transport i volitelné uložení do IMAP složky. Po testu formulář
zobrazí buď chybu vrácenou serverem, nebo informaci, že transport e-mail přijal,
včetně poslední SMTP/transport odpovědi, pokud ji backend získal. Pokud je
zapnuté IMAP ukládání, test zároveň zobrazí, zda se kopie uložila do zadané
složky. Při výchozí politice chyba IMAP uložení nemění fakt, že transport
e-mail přijal.
Pokud má profil nastaveno **Hlásit chybu archivace**, chyba uložení do IMAP se
zapíše jako chyba archivace po doručení. Aplikace ale e-mail znovu neposílá,
protože transport ho už přijal a opakování by mohlo vytvořit duplicitu u
příjemce.

Když existuje aktivní výchozí profil, používá ho `Mailer` pro všechny odchozí
e-maily daného dodavatele. Pokud žádný aktivní výchozí profil není, chování je
stejné jako bez profilů: `From` se bere z globální SMTP konfigurace a jméno
odesílatele z dodavatele. Fallback na e-mail dodavatele nebo globální
`cfg.php → smtp.reply_to_*` se pro `Reply-To` použije jen v tomto režimu bez
aktivního profilu. Stejně tak globální DKIM doména/selector z `cfg.php` platí
jen bez aktivního profilu; profil s vypnutým DKIM se nepodepisuje.
Ukládání do IMAP složky se také používá jen tehdy, když je zapnuté přímo v
aktivním profilu. Bez profilu ani při vypnuté volbě se žádný globální fallback
nepoužije.

Privátní DKIM klíč je stále globální v `cfg.php`. Odesílací profil může kromě
identity zprávy změnit i samotný transport, pokud je potřeba posílat pro různé
domény přes různé SMTP účty nebo lokální MTA.

## 92.8 SMTP log analýza

**Systém → E-maily → záložka SMTP log analýza**. Přístup pouze pro **admin**.

Zatímco *Odeslané e-maily* ukazují, co se aplikace pokusila poslat (z pohledu
aplikace), tahle záložka ukazuje, **co se reálně stalo na poštovním serveru** —
kam byla zpráva doručena a kde nastal problém. Čte přímo logy MTA (poštovního
serveru) a převádí je na přehledný seznam událostí. Jen čte; nic neodesílá ani
nemění.

### 92.8.1 Co uvidíš

- **Souhrnné karty** — počty doručovacích pokusů, doručeno / odloženo /
  odmítnuto a počet přijatých podání.
- **Cílové servery s problémy** — rychlé dlaždice serverů, kam se nedaří
  doručovat (klik nastaví filtr na daný server).
- **Tabulka událostí** s filtry (fulltext, typ, stav, rozsah dat). Každý řádek
  nese čas, stav, *od → komu*, cílový server + IP, předmět (pokud ho log nese)
  a doslovnou odpověď serveru.
- **Odkaz na fakturu** — pokud událost patří k e-mailu, který aplikace sama
  odeslala, doplní se klikací odkaz na příslušnou fakturu. Páruje se přes
  příjemce a čas odeslání (z interního auditu odeslané pošty); u serverů, které
  logují předmět, pomůže i číslo faktury v předmětu. Pošta, kterou neposlala
  aplikace (např. jiný systém na stejném serveru), se k faktuře neváže.

Druhy událostí (sloupec *typ*):

| Typ | Význam |
|---|---|
| **podání** | Zpráva vstoupila na server (klient/aplikace → MTA). Tady je vidět obálka tak, jak byla podána — pozná se tu např. chybějící příjemce. |
| **doručení** | Pokus o doručení na cílový MX. Nese výsledný stav a odpověď. |
| **událost** | Informativní/chybový záznam vázaný na zprávu (odložení, relay na smart host). |

Stavy:

| Stav | Význam |
|---|---|
| **Doručeno** | Cílový server zprávu přijal (2xx po DATA). |
| **Zařazeno** | Přijato k doručení (podání), zatím neodesláno dál. |
| **Odloženo** | Dočasné selhání (4xx) — greylisting, plná schránka, rDNS. Server to zkusí znovu. |
| **Odmítnuto** | Trvalé odmítnutí (5xx) — antispam politika, neexistující schránka, neověřený odesílatel. |
| **Chyba** | Neúplný dialog / chyba spojení. |

> 🛈 **Box „SMTP analýza" v detailu faktury.** Když je analýza zapnutá, najdeš
> u každé odeslané faktury (sekce pod historií PDF a aktivitou, jen pro admina)
> rozbalovací box, který na kliknutí dohledá v logu doručení právě této faktury —
> prohledá **den odeslání a následující den** pro její příjemce a ukáže per-příjemce
> stav (doručeno / odloženo / odmítnuto) i jednotlivé pokusy s odpovědí serveru.

### 92.8.2 Typické použití

- **„Došlo to klientovi?"** — fulltext na e-mail příjemce → uvidíš poslední stav
  doručení a odpověď jeho serveru.
- **Diagnostika odložení** — `450 4.7.1 cannot find your hostname` značí chybějící
  PTR/rDNS záznam tvé odchozí IP; `452 inbox out of storage` = plná schránka příjemce.
- **Diagnostika odmítnutí** — `541/554 antispam policy`, `550 unauthenticated`
  ukazují na problém s reputací / SPF / DKIM / DMARC.

### 92.8.3 Nastavení

Konfigurace je v `cfg.php` (vzor v `cfg.sample.php`) v sekci `smtp_log`:

| Klíč | Význam |
|---|---|
| `enabled` | `true` = záložka je aktivní. |
| `connector` | Parser pro konkrétní server: `hmailserver` nebo `mailenable`. |
| `path` | Glob vzor k log souborům (absolutní cesta). Hvězdička pokryje denní rotaci. |
| `max_files` | Strop počtu souborů (nejnovější dle data). |
| `max_bytes` | Strop velikosti čteného souboru; větší se čtou od konce. |

Příklady cest:

- **hMailServer** — `C:\Program Files (x86)\hMailServer\Logs\hmailserver_*.log`
- **MailEnable** — `C:\Program Files\Mail Enable\Logging\SMTP\SMTP-Activity-*.log`
  (čte se sada *SMTP-Activity*; *SMTP-Debug* a W3C `ex*` se ignorují)

> 🛈 Uživatelské rozhraní podporuje konektory `hmailserver` a `mailenable`.
> Pro jiný poštovní server analýzu nezapínej: jeho logy se bez odpovídajícího
> parseru nenačtou správně.

## 92.9 Uložené filtry a předvolby zobrazení

Na seznamech dokladů (Vydané i Přijaté faktury, Klienti, Sklad — položky i
doklady, Pokladna, Deník, Hlavní kniha, Majetek) si každý uživatel může
uložit vlastní **kombinace filtrů** a nastavit si, **jak má tabulka vypadat**
(které sloupce vidí, jak hustě jsou řádky rozložené a v jakém pořadí je
seřazeno). Obojí se ukládá **na uživatele** — každý přihlášený má svoje
vlastní filtry a rozvržení tabulky, nezávisle na kolezích.

### 92.9.1 Kde to najdeš

V horní liště nad tabulkou (vedle vyhledávání a filtrů dané stránky) jsou tři
ovládací prvky:

- **Uložené filtry** — rozbalovací nabídka s ikonou záložky,
- **Sloupce** — rozbalovací nabídka se seznamem sloupců k zaškrtnutí,
- **Hustota** — přepínač komfortního / kompaktního zobrazení řádků.

### 92.9.2 Uložené filtry

Tlačítko **Uložené filtry** rozbalí nabídku se třemi částmi:

- **Seznam uložených filtrů** — kliknutím na název filtr rovnou aplikuješ (přepíše
  aktuální filtry a řazení stránky). U aktivního filtru (tj. takového, jehož
  uložené hodnoty přesně odpovídají tomu, co má stránka teď nastavené) se jeho
  název zobrazí i jako štítek přímo na tlačítku.
- **Aktualizovat tímto nastavením** — zobrazí se jen když je nějaký uložený
  filtr právě aktivní; přepíše jeho uložené hodnoty aktuálním stavem filtrů
  na stránce (hodí se, když si oblíbenou kombinaci jen mírně doladíš).
- **Uložit aktuální filtry…** — pole pro název + zaškrtávátko **Výchozí** +
  tlačítko **Uložit**. Uloží se přesně to, co má stránka aktuálně nastavené
  (fulltext, hodnoty filtrů, případně řazení) pod zadaným názvem. Tlačítko je
  neaktivní, pokud stránka nemá nastavený žádný filtr nebo pokud není
  vyplněný název.

U každého uloženého filtru v seznamu jsou tři drobné ikony:

| Ikona | Akce |
|---|---|
| ⭐ hvězdička | Nastaví/zruší tento filtr jako **výchozí** pro danou stránku |
| ✏️ tužka | Přejmenuje uložený filtr |
| 🗑️ koš | Smaže uložený filtr (s potvrzením) |

> [!TIP]
> Filtr označený jako **výchozí** se **automaticky aplikuje** při otevření
> stránky — ale jen tehdy, když do stránky nepřicházíš už s vlastními filtry
> v URL (např. z odkazu z jiné kapitoly nebo ze záložky). Otevřeš-li stránku
> „na čisto" z menu, výchozí uložený filtr se sám nastaví za tebe.

Filtry jsou vázané na **konkrétní stránku** (uložený filtr pro Vydané faktury
se nenabízí na Klientech) a na **aktuálního dodavatele** — při [přepnutí
firmy](91_Multi_supplier.md) se nabídka filtrů načte znovu pro nově zvolenou
firmu. Sloupce, hustota a řazení (viz [§ 92.9.3](#9293-sloupce-hustota-radku-a-razeni))
naproti tomu **nejsou** vázané na dodavatele — jsou to čistě osobní
preference uživatele, platné napříč všemi firmami, ke kterým máš přístup.

### 92.9.3 Sloupce, hustota řádků a řazení

**Sloupce** — tlačítko rozbalí seznam všech dostupných sloupců tabulky se
zaškrtávátky. Některé sloupce (typicky číslo dokladu, částka, akce) jsou
**povinné** — jsou zašedlé a nejde je odškrtnout. Systém navíc nedovolí
odškrtnout **úplně poslední** viditelný nepovinný sloupec — tabulka musí mít
vždy aspoň jeden viditelný sloupec navíc k povinným. Tlačítko **Obnovit
výchozí** dole vrátí sloupce do stavu, v jakém je stránka nabízí ve výchozím
stavu.

> 🛈 Některé doplňkové sloupce jsou ve výchozím stavu skryté a
> zůstanou skryté, dokud si je sám/sama v nabídce **Sloupce** nezaškrtneš —
> a to i tehdy, pokud sis dřív na stránce sloupce už upravoval(a). Nový
> doplňkový sloupec se ti tedy sám od sebe „nevnutí" do už nastavené tabulky.

**Hustota** — přepínač mezi **komfortním** (výchozím) a **kompaktním**
zobrazením řádků tabulky; kompaktní režim zobrazí víc řádků na obrazovku bez
scrollování, na úkor menších odstupů.

**Řazení** — klik na hlavičku sloupce cykluje mezi vzestupným, sestupným a
výchozím řazením. Poslední zvolené řazení stránky se pamatuje stejně jako
sloupce a hustota.

Všechny tři volby (sloupce, hustota, řazení) se ukládají **automaticky** —
není potřeba nic potvrzovat tlačítkem, změna se krátce po kliknutí uloží na
pozadí a při příštím otevření stránky (i z jiného počítače) se obnoví.

### 92.9.4 Omezení a technické poznámky

- Na jednu stránku (a dodavatele) lze mít uloženo **max. 30 filtrů** na
  uživatele; po překročení limitu ukládání odmítne s hláškou o dosaženém
  limitu.
- Název uloženého filtru musí být v rámci stránky **unikátní** — duplicitní
  název uložení odmítne.
- Sloupce, hustota a řazení se ukládají pod stránku (např. `invoices`,
  `journal`, `general_ledger`), uložené filtry stejně tak — každá stránka má
  svůj vlastní, oddělený prostor předvoleb.

> [!TIP]
> Uložené filtry se hodí typicky pro opakující se pohledy — „nezaplacené
> faktury po splatnosti", „doklady k zaúčtování za tento měsíc" apod. Místo
> ručního nastavování filtrů pokaždé znovu si takový pohled ulož jednou a
> příště jen klikni na jeho název (nebo si ho nastav jako výchozí, ať se
> nabídne rovnou).

## 92.10 Tipy

- **Test šablony** vždy před produkčním nasazením — typo v Twig syntaxi by
  rozbilo odesílání všem klientům.
- **Role accountant** je dobrá pro externí účetní — vidí faktury, banku,
  exporty i daňové výkazy, ale nemůže upravit uživatele ani konfiguraci.
- **Role readonly** dej auditorovi nebo klientovi — vidí a exportuje totéž co
  účetní (vč. DPH podkladů), ale nemůže nic změnit.
- **Z Activity logu** zjistíš všechno — i kdo neúspěšně se zkoušel přihlásit
  (filter akce `auth.login_failed`).

## 92.11 Automatické zaúčtování při vystavení/přijetí dokladu

V sekci **Daňové nastavení** (jen u firem v režimu **podvojné účetnictví** —
u daňové evidence se doklady neúčtují, blok se vůbec nezobrazí) jsou dva
přepínače:

| Přepínač | Co dělá |
|---|---|
| **Automaticky účtovat vydané faktury** | Po **vystavení** faktury ji appka rovnou zaúčtuje do deníku — stejný mechanismus jako ruční tlačítko [Zaúčtovat](16_Faktura_PDF.md#1613-zauctovani-do-deniku). |
| **Automaticky účtovat přijaté faktury** | Po přechodu přijaté faktury na stav **Přijatá** ji appka rovnou zaúčtuje — viz [§ 23.3.3](23_Prijate_faktury.md#2334-filtr-a-tlacitko-zauctovat). Na další přechody stavu (uhrazená, stornovaná…) auto-post nereaguje. |

> [!NOTE]
> **Chyba zaúčtování vystavení/přijetí nezablokuje.** Pokud automatické
> zaúčtování selže (chybějící kurz, uzavřené období…), doklad se přesto
> normálně vystaví/přijme — jen zůstane nezaúčtovaný a zaúčtuješ ho ručně
> (chyba se zapíše do activity logu). Auto-post tak nikdy nemůže shodit
> vystavení faktury nebo příjem dokladu.

Oba přepínače jsou ve výchozím stavu **vypnuté** — bez zapnutí zůstává
zaúčtování ryze ruční (tlačítko na detailu / hromadná akce v seznamu).

Pod přepínači je box **Automatika účtování**, který řídí bankovní platby,
vlastní převody, odvody, pravidla a budoucí AI návrhy společnou policy. Preset
nastaví bezpečný výchozí rozsah:

| Preset | Chování |
|---|---|
| **Vypnuto** | Detektory ani pravidla nevytvářejí automatické zápisy. |
| **Jen návrhy** | Vše se zobrazí ke kontrole ve frontě. |
| **Asistovaná** | Automaticky mohou projít jednoznačné spárované platby, vlastní převody a vlastní pojistné; ostatní zůstává návrhem. |
| **Plná automatika** | Deterministické operace mohou po splnění všech guardů účtovat samy; naučené, nejasné a AI položky zůstávají návrhem. |

Jednotlivé typy operací můžeš pod presetem upravit na **vypnuto / návrh /
automaticky**. AI typy nelze nastavit na automatiku. Volitelný **denní limit
automatiky** po vyčerpání další položky pouze navrhne a přepínač **Ranní přehled
e-mailem** připravuje souhrn pro automatizační přehled.

Podrobné nastavení je rozdělené do skupin:

- **Platby a převody** — spárované bankovní platby, vlastní převody a jejich
  detektor,
- **Odvody** — jednotlivé druhy daní, sociální a zdravotní pojištění a
  rozpoznávání odvodů,
- **Banka** — poplatky, úroky, vlastní pravidla a naučené kontace,
- **AI** — návrhy bankovních plateb a dokladů; úroveň Automaticky je zde
  technicky zakázaná.

Výsledná úroveň je vždy nejvýše tak automatická, jak dovoluje preset a konkrétní
řádek. Nastavení **Vypnuto** detektor nebo typ operace nepustí, **Jen návrhy**
vyžaduje potvrzení a **Automaticky** pouze dovoluje motoru pokračovat k dalším
kontrolám. Není to příkaz „zaúčtuj za každou cenu“.

### 92.11.1 Bezpečnostní pořadí a limity

Před automatickým zápisem se postupně ověří firma a oprávnění, otevřené období,
jednoznačná vazba, povolený typ operace, limit pravidla, celofiremní denní limit,
duplicitní zápis a saldokontní předpis. Kterákoli nesplněná pojistka přesune
položku do návrhu nebo do **K doúčtování**; účetní doklad ani pohyb se neztratí.

Denní limit je korunový součet automaticky zpracovaných bankovních návrhů firmy
za den. Prázdná hodnota znamená bez tohoto globálního stropu, nikoli bez
ostatních pojistek.
Pravidlo banky může mít navíc vlastní rozsah částky a nižší **limit pro
automatiku**. Nad ním pravidlo stále může sedět, ale výsledek čeká na schválení.

Automaticky se nikdy neprovedou:

- AI návrhy a hromadné schválení AI položek,
- nejednoznačné nebo konfliktní párování,
- zápis do uzavřeného či zamčeného období,
- odvod bez existujícího dostatečného kreditního předpisu na 336/341/342/343/345,
- vlastní převod v různých měnách nebo operace s podezřením na již existující
  ruční zápis,
- operace nad denním či pravidlovým limitem a nepodporované cizoměnové případy.

Pro vlastní převody nestačí zapnout pouze jeden řádek: na Automaticky musí být
jak **Převody mezi vlastními účty**, tak **Rozpoznávání vlastních převodů**.
Účty musí být evidované jako vlastní a mít stejnou měnu; každá noha se účtuje
přes 261 a zůstává auditovatelná. Podrobnosti a práce s frontami jsou v
[Automatu účtování](46_Automat.md) a kapitole [Banka](28_Banka.md).

## 92.12 Brandingové profily

Brandingové profily jsou volitelný modul, který se zapíná v nastavení dodavatele.
Dokud je vypnutý, faktury a e-maily používají původní údaje a branding dodavatele.
Po zapnutí lze vytvořit profily pro různé obchodní značky. Profil může změnit
logo, zobrazovaný název, slogan, barvu, kontaktní údaje a patičku e-mailu. Právní
údaje dodavatele (firma, adresa, IČ a DIČ) zůstávají společné a profilem se nemění.

Výchozí profil se použije vždy, když faktura, pravidelná fakturace ani zákazník
neurčují jiný profil. Výchozí profil není povinný. Přepínač **Používat vlastní
branding** v jednotlivém profilu řídí zobrazení jeho loga a barev v e-mailech i PDF.

Každému profilu lze přiřadit také e-mailový profil odesílatele. Ten určuje
SMTP účet, adresu odesílatele, Reply-To a případné podepisování zpráv. Není-li
vybrán, použije se výchozí e-mailový profil dodavatele.

E-mailový profil, který používá některý brandingový profil, nelze smazat.
Chybová zpráva vypíše dotčené profily; nejprve jim nastav jiného odesílatele
nebo výchozí profil dodavatele.

Výchozí profil lze přiřadit zákazníkovi. Nový koncept faktury jej převezme a
při vystavení se použitá identita uloží do snapshotu dokladu. Pozdější změna
profilu nebo nahrání nového loga proto nezmění již vystavené faktury.

Akce **Náhled e-mailu** u profilu zobrazí jeho logo, barvu, zobrazovaný název,
kontakty i vlastní patičku. V náhledu lze přepínat mezi českou a anglickou
variantou. Původní konfigurace brandingu e-mailů se používá při vypnutém modulu.
Obsahové e-mailové šablony zůstávají společné pro dodavatele a spravují se
odděleně na stránce **E-mail šablony**.

## 92.13 Firma → Kategorie

**Cesta: `Firma → Kategorie`**. Stránka obsahuje číselníky platné jen pro
aktuální firmu:

| Záložka | Použití |
|---|---|
| **Kategorie nákladů** | Člení přijaté faktury a další náklady. U kategorie se zadává kód, název, pořadí a druh **fixní / variabilní** pro nákladové přehledy. |
| **Kategorie tržeb** | Člení tržby z vydaných faktur. Zadává se kód, název a pořadí. Volitelně i **vlastní číselná řada** — faktury s touto kategorií pak dostanou číslo z ní (vlastní řada zákazníka má přednost), viz [§ 91.5.3](91_Multi_supplier.md#9153-cislovani-faktur). |

U každé kategorie stránka ukazuje počet použití. Nepoužitou kategorii lze
smazat; použitá se kvůli zachování historie pouze archivuje a přestane se
nabízet pro nové doklady. Archivované kategorie zůstávají v přehledu označené
a lze je znovu upravit. Kód musí být v rámci dané firmy a druhu kategorie
jedinečný.

> [!NOTE]
> Kategorie nemění účetní předkontaci ani klasifikaci DPH. Slouží k provoznímu
> členění nákladů a tržeb v dokladech a přehledech.

## 92.14 Systém → Sazby a číselníky

**Cesta: `Systém → Sazby a číselníky`**. Stránka sdružuje systémové
číselníky **Sazby DPH**, **Klasifikace DPH**, **Země** a **Jednotky**.
Sazby, země a jednotky popisuje [§ 92.1](#921-ciselniky); pro výkazy je
zásadní také následující klasifikace DPH.

### 92.14.1 Klasifikace DPH

Klasifikační kód určuje směr použití (prodej, nákup nebo oba), řádek přiznání
DPHDP3, oddíl kontrolního hlášení, sazbu a zvláštní režimy, například reverse
charge, kód režimu KH, opravu nedobytné pohledávky nebo kód předmětu plnění.
Podle těchto hodnot backend zařazuje řádky dokladů do daňových sestav; nejde
jen o popisek v editoru.

Vestavěné systémové kódy jsou společné a nelze je upravit ani smazat. Pro
aktuální firmu lze vytvořit vlastní kód, upravit ho a později archivovat.
Změnu prováděj jen tehdy, když znáš její dopad na DPHDP3, kontrolní hlášení a
Knihu DPH. Význam jednotlivých polí je podrobně popsán v
[36. Výkazech DPH](36_Vykazy_DPH.md#3644-jak-funguji-vat-klasifikacni-kody), kde je
i kompletní tabulka vestavěných kódů.

**Kód předmětu plnění** (`kod_pred_pl`, jde do vět KH A.1 a B.1) přijímá jednu až dvě
číslice s volitelným písmenem — číselník MFČR obsahuje i hodnoty jako `1a` nebo `3a`.
Hodnotový výčet se záměrně nevaliduje: vlastní seznam by se s číselníkem rozešel
a odmítal by legitimní kódy.

**Vlastní kód přidávej pro režim, který vestavěné nepokrývají**, ne pro překlopení
existujícího na jiný řádek. Vestavěné varianty už pokrývají tuzemský přenos podle
režimu (§ 92c odpad, § 92d nemovitost, § 92e stavební práce), zvláštní režimy § 89
a § 90 i rozlišení vývozu zboží od služby do 3. země. Pokud přesto namapuješ kód na
jiný řádek přiznání, respektuje se to — přemapování podle skutečné sazby se spouští
jen při rozporu sazby kódu se sazbou řádku, ne proti tvému mapování.

**Daňové konstanty** už nejsou záložkou této stránky. Jsou samostatný bod
menu hned pod Sazbami a číselníky; podrobnosti popisuje kapitola
[Daňové konstanty](96_Danove_konstanty.md).

## 92.15 E-maily → Odeslané

**Cesta: `Systém → E-maily → Odeslané`**. Výchozí záložka zobrazuje
auditní přehled pokusů aplikace o odeslání zpráv. Zahrnuje e-maily k fakturám,
upomínky, žádosti o schválení, poděkování za úhradu, připomínky pravidelné
fakturace a testovací zprávy.

Přehled lze filtrovat podle typu a výsledku **Odesláno / Selhalo**. U záznamu
ukazuje čas, typ zprávy, související fakturu a zákazníka, příjemce, uživatele
nebo systém, který odeslání spustil, a u chyby také její text. Červený souhrn
umožňuje rychle zobrazit jen neúspěšné pokusy.

Záložka je pouze pro čtení: zprávu z ní nelze znovu odeslat ani smazat. Stav
**Odesláno** potvrzuje úspěch odesílacího kroku aplikace, nikoli přečtení nebo
doručení do schránky příjemce. Pro technickou diagnostiku SMTP komunikace
použij [SMTP log analýzu](#928-smtp-log-analyza).

## 92.16 Vlastní domény klientského rozhraní

> **Funkce je volitelná a ve výchozím stavu vypnutá.** Zapíná ji správce serveru
> v `cfg.php` (`'domains' => ['enabled' => true]`, případně ENV
> `MYINVOICE_DOMAINS_ENABLED=1`). Dokud je vypnutá, sekce s doménami se v
> Nastavení vůbec nenabízí a instalace se chová jako bez ní: na aplikaci se
> dostaneš přes libovolný hostname, který na ni webserver nasměruje.
>
> Po zapnutí se hostname stává hranicí firmy — a tím i tvrdým filtrem. Jakýkoli
> host, který není hostname z `app.url` ani aktivní doména některé firmy, dostane
> `421`. To zahrnuje i variantu s `www` a bez `www`, přístup přes IP adresu nebo
> staging jméno. Zapínej proto až ve chvíli, kdy reverse proxy posílá na aplikaci
> jen hostnames, o kterých víš.

**Cesta: `Nastavení → Firma → Vlastní domény`.** Sekce se zobrazí uživateli
s oprávněním **Vlastní domény** alespoň pro čtení; založení, ověření, aktivace
a deaktivace vyžadují zápis. Domény se vždy spravují pro právě vybranou firmu.
V hostované instalaci musí vlastní hostname, směrování hlavičky `Host` a TLS
výslovně podporovat provozovatel služby; samotné oprávnění v aplikaci tuto
provozní podporu nezajistí.

Při založení zadej jen hostname bez schématu, portu a cesty, například
`portal.klient.cz`. Wildcard není podporovaný. Vyber účel **Klientské rozhraní**,
**Veřejné odkazy** nebo **Klientské rozhraní i veřejné odkazy** a urči, zda má
být doména po aktivaci primární. Klientské rozhraní zahrnuje přehled, doklady,
kontakty, pravidelnou fakturaci i osobní profil podle oprávnění role client;
nejde jen o adresy pod `/portal`. Více aliasů je povolených; pro každý účel může
být primární nejvýše jeden aktivní hostname. Hostname, který používá výchozí
`app.url`, nelze současně založit jako vlastní doménu firmy; zadej jiný hostname.
Pokud správce změní `app.url` až později na už uloženou vlastní doménu, aplikace
odmítne běžný provoz na tomto hostname a v health diagnostice ohlásí kolizi.
Obnov původní canonical adresu, kolidující vlastní doménu deaktivuj a smaž,
nebo nastav jiný canonical hostname.

Přihlášení, správa passkeys a vynucené nastavení MFA používají canonical origin
z `app.url`, protože WebAuthn RP ID je svázané právě s ním. Při otevření správy
passkeys nebo nastavení MFA z vlastní domény aplikace provede krátkodobý PKCE
přechod na canonical adresu a následně vytvoří novou host-only session pro původní
doménu. Návrat vede jen na serverem ověřenou stránku klientského rozhraní a zachová
firmu určenou aktivním hostname; vlastní doména sama WebAuthn options ani verify
endpointy neobsluhuje. Stejná hranice platí pro výpis, přejmenování a odvolání
passkeys i pro WebAuthn odemčení zamčené session; běžné TOTP operace zůstávají
samostatné a na WebAuthn originu nezávisí.

### 92.16.1 Ověření a aktivace

Nová doména zobrazí přesný DNS TXT záznam ve tvaru:

```text
_myucto-challenge.portal.klient.cz TXT myucto-verification=<token>
```

Současně nasměruj A/AAAA nebo CNAME domény na reverse proxy MyÚčta, zachovej
původní hlavičku `Host` a připrav důvěryhodný TLS certifikát. Tlačítko
**Ověřit DNS a HTTPS** kontroluje TXT challenge i HTTPS odpověď z přesné domény.
Kontrola nepovoluje redirect, privátní cílovou IP ani nedůvěryhodný certifikát.
Dokud neprojde, hostname obslouží pouze svůj jednorázový ověřovací endpoint,
nikoli klientské rozhraní nebo firemní data.

Po úspěšném ověření aktivaci potvrď passkey nebo TOTP. Aktivace bezprostředně
zopakuje DNS i HTTPS kontrolu pro aktuální hostname a challenge; dříve uložený
stav **Ověřeno** sám nestačí. Pokud se challenge mezitím změnila nebo některá
kontrola už neprojde, doména zůstane neaktivní a aplikace zobrazí důvod. Po
opravě proveď ověření a aktivaci znovu. Tím se zabrání tomu, aby ukradená běžná
session nebo zastaralý výsledek kontroly přesměroval klienty na útočníkovu
doménu. Aktivace, změny challenge, ověření i deaktivace se zapisují do activity
logu.

| Stav | Význam |
|---|---|
| **Čeká na ověření** | Je nutné publikovat DNS TXT, routing a TLS. |
| **Ověřeno** | Poslední kontrola DNS a HTTPS prošla; lze aktivovat. |
| **Aktivní** | Doména smí obsluhovat svůj účel a uzamyká firmu podle hostname. |
| **Ověření selhalo** | Karta ukáže důvod; po opravě lze kontrolu zopakovat. |
| **Deaktivováno** | Hostname nevydává firemní data; lze jej znovu ověřit nebo smazat. |

Rotace challenge zneplatní předchozí TXT hodnotu a vrátí neaktivní doménu do
stavu čekání. Aktivní doménu nejdřív deaktivuj. Deaktivace se projeví okamžitě;
pokud nezůstane jiný aktivní alias daného účelu, nové odkazy použijí výchozí
`app.url`. Provozní nastavení proxy, certifikátů a Turnstile popisuje
[§ 3.8 HTTPS / TLS terminace](03_Instalace_Docker.md#38-https-tls-terminace).

## 92.17 Datová schránka

**Firma → Datová schránka** (`/admin/databox`) spravuje přístupy, příchozí
zprávy, příjemce, výzvy a odchozí podání právě vybrané firmy. Přehled všech
přihlašovacích metod, ručního inboxu a odeslání najdete v samostatné kapitole
[Datová schránka](93_Datova_schranka.md).

Globální registraci externí aplikace spravuje provozovatel v **Systém →
Odesílací brána ISDS** (`/admin/isds-gateway`); popisuje ji kapitola
[Odesílací brána ISDS](94_Odesilaci_brana_ISDS.md). Mzdové formuláře a jejich
věcný stav popisuje kapitola [Podání a hlášení](68_Podani_a_hlaseni.md).
