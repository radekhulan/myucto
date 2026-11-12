# 22. Upomínky po splatnosti

> Návod, jak upomenout klienta, který nezaplatil včas: ručně, hromadně nebo
> automaticky, jak upravit text upomínky a jak vystavit penalizační fakturu na
> úrok z prodlení. Pro každého, kdo hlídá pohledávky.

## 22.1 Kdy to potřebujete

- Faktura je po splatnosti a chcete klientovi poslat upomínku (e-mail typu
  „Vaše faktura č. XXX byla splatná YY dní zpět, prosíme o úhradu“).
- Máte po splatnosti víc faktur a chcete upomenout všechny najednou.
- Chcete, aby upomínky odcházely samy každý pracovní den.
- Chcete upravit text upomínky nebo ji vyzkoušet, než ji uvidí klient.
- Upomínky nepomohly a chcete vyúčtovat zákonný úrok z prodlení.
- Potřebujete zjistit, zda se upomínka opravdu odeslala.

Upomínky lze posílat **třemi způsoby**:

1. **Ručně** z detailu jedné faktury.
2. **Hromadně** ze [Seznamu faktur](14_Faktury.md).
3. **Automaticky** z plánované úlohy (cronu).

## 22.2 Než začnete

Aby šla upomínka odeslat, faktura musí:

- být typu **Faktura** nebo **Zálohová faktura (proforma)**, nikoli dobropis či storno,
- mít kladnou částku k úhradě a způsob úhrady **Bankovní převod**,
- být ve stavu vystavená, odeslaná nebo upomenutá,
- být **po splatnosti** (splatnost starší než dnešek),
- mít k dispozici e-mail klienta (hlavní e-mail, kontakty nebo fakturační
  e-maily zakázky),
- mít zapnutý přepínač **Posílat automatické upomínky** - jen pro
  automatické odesílání, ruční i hromadné odeslání funguje vždy (viz
  [§ 22.10.6](#22106-tri-urovne-vypnuti-a-prah-dni)).

## 22.3 Krok za krokem: upomínka jedné faktuře

1. Otevřete [detail faktury](16_Faktura_PDF.md) po splatnosti.
2. Klikněte na **Odeslat upomínku**.

   ![Tlačítko upomínka](img/12_upominka_btn.webp)

3. V potvrzovacím dialogu zkontrolujte příjemce (vidíte u nich i zdroj).
   Můžete doplnit další adresy (oddělené čárkou) nebo přes **+ CC / BCC**
   přidat kopii, třeba stavbyvedoucímu nebo nákupčímu, který platbu schvaluje.
   Uložené kontakty klienta ani faktura se tím nemění.
4. Potvrďte odeslání.

**Jak poznáte, že je hotovo:** faktura má stav **Upomínka**, zvýšil se počet
odeslaných upomínek a v aktivitě faktury je záznam o upomínce s počtem dní po
splatnosti.

Příjemci: kontakty klienta s účelem **Upomínky** (viz
[§ 18.4](18_Klienti.md#184-krok-za-krokem-e-mailove-kontakty-podle-ucelu)),
bez nich kontakty **Doklady**, bez kontaktů hlavní e-mail klienta plus
fakturační e-maily zakázky.

### 22.3.1 Test upomínky

Na detailu faktury otevřete nabídku **Další akce** a v části **Pokročilé**
klikněte na **Test upomínky**. Test se odešle na e-mail aktuální firmy;
pokud není platný, použije se výchozí odesílací adresa instalace
(`smtp.from_email`). Stav faktury a počítadlo upomínek se nemění. Náhled
lze poslat i u konceptu a před splatností; tehdy používá 7 dní prodlení. Hodí se pro:

- vyzkoušení šablony před odesláním klientovi,
- ověření, že SMTP funguje,
- náhled HTML verze e-mailu ve vašem poštovním klientovi.

**Jak poznáte, že je hotovo:** upomínka dorazí do vaší schránky. Stav faktury ani
počet upomínek se testem nemění.

> [!TIP]
> Test upomínky vždy udělejte před prvním ostrým během cronu. Nešťastné je
> posílat klientovi rozbitý HTML e-mail.

## 22.4 Krok za krokem: hromadná upomínka

1. Otevřete `Prodej → Vydané faktury` a nastavte filtr **Po splatnosti**.
2. Zaškrtněte faktury, které chcete upomenout.
3. V liště klikněte na **Odeslat upomínky (N)** a potvrďte.

![Hromadná upomínka](img/12_upominka_bulk.webp)

**Jak poznáte, že je hotovo:** zobrazí se hláška o výsledku (počet odeslaných
a případné chyby u jednotlivých faktur) a odeslané faktury mají stav
**Upomínka**.

Aplikace u každé faktury zkontroluje předpoklady z [§ 22.2](#222-nez-zacnete),
odešle e-mail a změní stav. Hromadná akce **neuplatňuje ochrannou lhůtu**
(cooldown): pošle upomínku každé vybrané fakturě, která splňuje předpoklady,
i když už jednou upomenuta byla.

## 22.5 Krok za krokem: automatické upomínky

1. Zapněte automatické upomínky na všech třech úrovních (dodavatel, klient,
   faktura, viz [§ 22.10.6](#22106-tri-urovne-vypnuti-a-prah-dni)). Cron pošle
   upomínku, jen když ji dovolí všechny tři.
2. V `Firma → Nastavení`, záložce **Fakturace**, zapněte **Posílat automatické
   upomínky**.
3. V poli **Po kolika dnech po splatnosti poslat první upomínku** zvolte
   **3 dny**, **Týden (7 dní)**, **Měsíc (30 dní)**, nebo **Vlastní…** počet
   dní (1 až 365).
4. Správce serveru nastaví plánovanou úlohu (viz
   [§ 22.10.1](#22101-cron-parametry)). Doporučení: 1x denně v pracovní dny.
5. Před ostrým nasazením úlohu vyzkoušejte s `--dry-run`
   (viz [§ 22.10.3](#22103-dry-run-pred-nasazenim)).

**Jak poznáte, že je hotovo:** upomínky odcházejí samy, v
`Systém → E-maily a certifikáty` na záložce **Odeslané e-maily** vidíte
odeslání připsaná „Systému“ a faktury mají stav **Upomínka**.

Upomínku u jedné faktury vypnete v [editoru faktury](15_Faktura_editor.md)
přepínačem **Posílat automatické upomínky** (box **Datumy**, pod polem
Splatnost). Cron tuto fakturu pak přeskočí, ruční i hromadné odeslání funguje.

## 22.6 Krok za krokem: úprava šablony upomínky

1. Otevřete `Systém → E-maily a certifikáty` a záložku **E-mail šablony**.
2. Vyberte šablonu **Upomínka faktury**.

   ![Editor šablony upomínky](img/12_sablona.webp)

3. Upravte **Předmět**, **HTML tělo** a **Plain text tělo** (záloha pro
   klienty bez HTML). Použít můžete placeholdery z
   [§ 22.10.4](#22104-placeholdery-v-sablone).
4. Uložte a vyzkoušejte přes **Test upomínky** (viz
   [§ 22.3.1](#2231-test-upominky)).

**Jak poznáte, že je hotovo:** testovací e-mail vypadá, jak chcete.

## 22.7 Krok za krokem: penalizační faktura (úrok z prodlení)

Když upomínky nepomohly, můžete dlužníkovi vystavit penalizační fakturu na
zákonný úrok z prodlení dle nařízení vlády č. 351/2013 Sb.

1. Otevřete [detail faktury](16_Faktura_PDF.md) po splatnosti (typu Faktura).
2. Klikněte na **Penalizační faktura**.
3. V okně zkontrolujte náhled výpočtu: jistinu (zbývající dlužnou částku),
   počet dní prodlení, rozpad dnů přes hranici kalendářního roku (období, dny,
   roční sazba, úrok) a celkový úrok.
4. Potvrďte. Vznikne **koncept** penalizační faktury na jeden řádek s
   vypočteným úrokem.
5. Fakturu běžně vystavte a odešlete.

**Jak poznáte, že je hotovo:** hláška „Penalizační faktura vytvořena (koncept)“
a v seznamu faktur koncept typu Penalizační faktura.

Jak se úrok počítá, viz [§ 22.10.7](#22107-jak-se-urok-pocita). Ke stejné
faktuře lze penalizaci vystavit opakovaně, vždy za dosud nevyúčtované dny
(viz [§ 22.10.8](#22108-navazujici-penalizace)).

## 22.8 Krok za krokem: kontrola, co se opravdu odeslalo

1. Otevřete `Systém → E-maily a certifikáty` a záložku **Odeslané e-maily**.
2. Filtrem stavu (**Vše / Odesláno / Neodesláno**) nebo zkratkou
   **Neodesláno: N** najdete neúspěšná odeslání.
3. U červeného řádku přečtěte text chyby a odeslání zopakujte (například
   z detailu faktury).

**Jak poznáte, že je hotovo:** u upomínky je stav **Odesláno**.

Přehled obsahuje **všechny** e-maily aplikace: odeslání faktur, upomínky,
schvalovací upomínky, poděkování za úhradu, připomínky konceptů, odkazy na
nastavení hesla (pozvánka nového uživatele i znovuposlání), obnovu zapomenutého
hesla a testovací odeslání. Automatická (cron) odeslání jsou připsána „Systému“.
Zapisují se i neúspěšná odeslání (nedostupný SMTP, odmítnutý příjemce, chyba při
generování PDF) červeným řádkem se stavem **Neodesláno** a textem chyby.

> [!WARNING]
> „Odesláno“ znamená, že e-mail **převzal SMTP server**. Nezaručuje doručení do
> schránky, odmítnutí mailserverem příjemce ani spam filtr aplikace netrackuje.

## 22.9 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Tlačítko **Odeslat upomínku** chybí | Doklad není po splatnosti, nemá kladnou částku k úhradě, je placen jinak než převodem nebo nejde o běžnou fakturu či proformu v přípustném stavu | Zkontrolujte typ, stav, částku k úhradě, způsob úhrady a splatnost |
| Cron fakturu přeskočil | Automatické upomínky jsou vypnuté u dodavatele, klienta nebo faktury, nebo je faktura méně dní po splatnosti než práh, nebo poslední upomínka byla před méně než cooldown dny | Zkontrolujte [§ 22.10.6](#22106-tri-urovne-vypnuti-a-prah-dni) a použijte `--dry-run` |
| Upomínka se nevyskytuje v přehledu chyb, přesto neodešla | Cron ji přeskočil kvůli předpokladům (například klient nemá e-mail), to není selhání odeslání | Doplňte e-mail klienta, viz [§ 18.4](18_Klienti.md#184-krok-za-krokem-e-mailove-kontakty-podle-ucelu) |
| Červený řádek **Neodesláno** | Nedostupný SMTP, odmítnutý příjemce nebo chyba PDF | Přečtěte text chyby v **Odeslané e-maily** a odešlete znovu |
| Penalizaci aplikace odmítla | Celé aktuální období prodlení je už pokryté dřívější penalizací | Není co nově vyúčtovat, viz [§ 22.10.8](#22108-navazujici-penalizace) |
| Hromadná akce poslala upomínku i nedávno upomenuté faktuře | Hromadná akce nemá cooldown | Vybírejte faktury pečlivě |

## 22.10 Podrobnosti a pravidla

### 22.10.1 Cron parametry

Skript `cmd/cron-send-reminders.sh` (spouští `php api/bin/cron-send-reminders.php`)
doporučujeme pouštět 1x denně, například v 9:00 od pondělí do pátku.

| Parametr | Výchozí | Význam |
|---|---|---|
| `--days=N` | (podle dodavatele) | Faktura musí být po splatnosti alespoň N dní. Bez parametru se čte **práh nastavený u dodavatele** (výchozí 3), `--days` ho pro daný běh přebije. |
| `--cooldown=N` | `7` | Minimální počet dní mezi dvěma upomínkami stejné faktury |
| `--dry-run` | - | Jen vypíše, co by udělal, **bez odeslání** |

Ochranná lhůta (cooldown) platí jen u automatického odesílání, ruční a hromadné
odeslání ji neuplatňuje.

### 22.10.2 Doporučené nastavení

```cron
# Po-Pá v 9:00 - upomínat faktury 5+ dní po splatnosti, max 1x za 14 dní
0 9 * * 1-5  /var/www/myucto.cz/cmd/cron-send-reminders.sh --days=5 --cooldown=14
```

`--days=5` je rozumná odkladná lhůta: klient mohl mít dovolenou, bankovní
poplatek, nebo jste zapomněli naimportovat výpis. Kratší cooldown než 7 dní
by byl agresivní. Cron nepouštějte o víkendu: klient e-maily nečte, vyřeší je
až v pondělí a ve statistikách to vypadá divně.

### 22.10.3 Dry-run před nasazením

```bash
php api/bin/cron-send-reminders.php --days=5 --dry-run
```

Vypíše například:

```
[dry-run] Faktura #2604012 (ACME s.r.o., 12 dní po splatnosti) - by se odeslala na 3 adresy
[dry-run] Faktura #2604015 (Studio Fialka, 7 dní po splatnosti) - by se odeslala na 1 adresu
[dry-run] Faktura #2604008 - přeskočena (poslední upomínka před 4 dny < cooldown 7)
[dry-run] CELKEM: 2 by se odeslaly, 1 přeskočena.
```

### 22.10.4 Placeholdery v šabloně

Předmět lze složit s placeholderem `{{ varsymbol }}`, tělo je šablona Twig.

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol faktury |
| `{{ amount }}` | Částka k úhradě, formátovaná |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Datum splatnosti |
| `{{ days_overdue }}` | Počet dní po splatnosti |
| `{{ client_name }}` | Jméno klienta |
| `{{ supplier_name }}` | Jméno dodavatele |
| `{{ payment_link }}` | (volitelné) odkaz na platební bránu |
| `{{ reminder_count }}` | Počet již odeslaných upomínek (1 = první, 2 = druhá, …) |

Eskalaci tónu uděláte pomocí `{{ reminder_count }}` a logiky Twig, například
`{% if reminder_count >= 3 %}poslední výzva{% endif %}`.

Pro každou šablonu existují **4 varianty**: `cs.html`, `cs.txt`, `en.html`
a `en.txt`. Vybere se podle jazyka klienta.

### 22.10.5 Co odeslání upomínky změní

Odeslání upomínky nastaví stav faktury na **Upomínka**, zapíše čas poslední
upomínky a zvýší počet upomínek o jedna. Šablona je **Upomínka faktury** (`invoice_reminder`)
(CZ / EN podle jazyka klienta).

### 22.10.6 Tři úrovně vypnutí a práh dní

Cron pošle upomínku, jen když dovolí **všechny tři** úrovně:

| Úroveň | Kde | Význam |
|---|---|---|
| Dodavatel | `Firma → Nastavení`, záložka **Fakturace** | Globální přepínač pro celého dodavatele, u nově založené firmy je vypnutý |
| Klient | Úprava klienta, volba **Posílat automatické upomínky** | Vypnutí pro všechny faktury daného klienta |
| **Faktura** | Editor faktury | Vypnutí pro jedinou fakturu |

Přepínač na faktuře je v pravém boxu **Datumy** pod polem Splatnost, výchozí
stav je zapnuto. U dobropisů se nezobrazuje (dobropisy se neupomínají). Ruční
i hromadné odeslání funguje vždy.

Práh „po kolika dnech po splatnosti poslat první upomínku“ je hodnota na
dodavateli, kterou cron čte automaticky. Parametr `--days=N` ji pro daný běh
přebije, hodí se pro mimořádný nebo ruční běh.

### 22.10.7 Jak se úrok počítá

Roční sazba úroku = **2týdenní repo sazba ČNB** platná k **prvnímu dni
kalendářního pololetí, ve kterém prodlení VZNIKLO**, zvýšená o **8 procentních
bodů**:

```
úrok = jistina × (repo sazba k počátku prodlení + 8) / 100 × počet dní prodlení / (365 nebo 366)
```

Prodlení běží ode dne následujícího po splatnosti do rozhodného dne (dnešek).
Sazba se **fixuje k okamžiku vzniku prodlení a dál se nemění**. I když
prodlení trvá přes další pololetí s jinou sazbou ČNB, počítá se pořád
počáteční sazbou (§ 2 NV č. 351/2013 Sb.). Přesahuje-li prodlení přes hranici
**kalendářního roku**, aplikace dny na této hranici rozdělí, protože se mění
jmenovatel (365, resp. 366 v přestupném roce). Sazba zůstává v obou částech
stejná.

### 22.10.8 Navazující penalizace

Když ke stejné faktuře už dřív vznikla penalizace, další penalizace **počítá
úrok jen za dny, které ještě nebyly vyúčtované**. Aplikace si u každé
penalizační faktury pamatuje, do kterého dne prodlení pokrývá, a navazující
výpočet začíná až den poté. V náhledu se to projeví hláškou „Navazuje na
dřívější penalizaci - počítá se jen období od …“.

Je-li celé aktuální období prodlení už pokryté (typicky zadáte stejné nebo
starší rozhodné datum jako u předchozí penalizace), aplikace vytvoření nové
faktury **odmítne**, protože není co nově vyúčtovat.

> [!TIP]
> Stornovaná penalizační faktura se do „už pokrytého období“ nepočítá. Po jejím
> stornování začne navazující výpočet znovu od původního počátku prodlení.

### 22.10.9 Účtování a DPH

- Úrok z prodlení je **mimo předmět DPH** (§ 2 ZDPH, není plnění), penalizační
  faktura se proto **nezahrnuje** do Knihy DPH, přiznání DPHDP3 ani do
  kontrolního hlášení.
- V podvojném účetnictví se účtuje **311 / 644** (Smluvní pokuty a úroky z
  prodlení) bez řádku DPH (343). Předkontaci lze upravit v
  [kontačních pravidlech](96_Nastaveni.md) (`invoice.penalty.issued`).

### 22.10.10 Číselník repo sazby ČNB

V `Nástroje → Účetní nastavení → Repo sazba ČNB` je tabulka historických repo
sazeb. Každý řádek má **Platnost od** (typicky 1. den pololetí) a **sazbu
v % p.a.** Aplikace při výpočtu vezme poslední sazbu s datem platnosti
nejpozději k rozhodnému dni. Číselník je společný pro celou instalaci. Řádky
může doplňovat, opravovat a mazat pouze superadministrátor.

### 22.10.11 Tipy

- Po druhé upomínce zvažte osobní telefonát. Automatika neřeší vztahy,
  e-mailová upomínka je jen formalita.
- Cooldown výchozích 7 dní lze přes `--cooldown=N` zvýšit, například na 14.

## 22.11 Související kapitoly

- [14. Faktury](14_Faktury.md)
- [16. Faktura - PDF, QR platba, odeslání e-mailem](16_Faktura_PDF.md)
- [18. Klienti](18_Klienti.md)
- [19. Zakázky](19_Zakazky.md)
- [96. Nastavení](96_Nastaveni.md)
