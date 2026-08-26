# 17. Pravidelné fakturace (Recurring invoices)

Šablony pro automatické generování faktur v pravidelných intervalech. Hodí se
pro paušální platby (hosting, předplatné, retainer …), kde se fakturuje stále
stejná částka stejnému klientovi.

Šablona drží konfiguraci (periodicita, položky, klient, dodavatel) a cron
`cron-generate-recurring-invoices.php` (běží denně) podle ní vytváří nové
faktury. Volitelně je rovnou **vystaví** (přidělí číslo faktury) a/nebo
**odešle klientovi e-mailem**.

## 17.1 Kdy použít

- Pravidelný měsíční / čtvrtletní / pololetní / roční paušál
- Stejné položky a stejné částky (drobné posouvání měsíce v popisech řeší
  jeden přepínač — viz dále)
- Fakturuješ tomu stejnému klientovi opakovaně

Pro **jednorázové znovuvystavení** stávající faktury (např. „udělej ze
faktury 5/2026 fakturu 6/2026") slouží klasický **klon faktury** v detailu
faktury — ne pravidelná šablona.

## 17.2 Vytvoření šablony

V menu **Prodej → Pravidelné fakturace** klikni **+ Nová šablona**, nebo
v detailu existující faktury tlačítko **Vytvořit šablonu z této faktury**
(předvyplní klienta, položky, měnu, jazyk i payment method).

### 17.2.1 Sekce „Periodicita"

- **Periodicita** — Měsíčně / Čtvrtletně / Pololetně / Ročně
- **Den v měsíci** — 1–28 (28 je nejvyšší možná hodnota; cap kvůli únoru)
- **Poslední den měsíce** — pokud je zaškrtnuto, den v měsíci se ignoruje
  a faktura se vystaví vždy poslední den měsíce (28/29/30/31 dynamicky podle
  délky měsíce). Hodí se pro „vždy poslední den čtvrtletí".
- **Datum prvního vystavení** — kdy má vyjít první faktura. Šablona se po
  uložení rovnou „naplánuje" na tento den (`next_run_date`).
- **Datum ukončení** (volitelné) — po překročení tohoto data se šablona
  automaticky pozastaví (status **Vypršela**) a cron ji přeskakuje.

### 17.2.2 Sekce „Faktura"

Tady nastavíš metadata, která se zkopírují na každou vygenerovanou fakturu:

- **Typ dokladu** — Faktura nebo Zálohová faktura (proforma)
- **Měna** — určuje bankovní spojení a CNB kurz (u neCZK měn)
- **Jazyk** — `cs` nebo `en` (jazyk PDF + e-mailu)
- **Způsob úhrady** — Bankovní převod / Platební karta / Hotově / Jiný.
  U non-bank-transfer se v PDF i e-mailu nezobrazí QR kód ani bankovní
  spojení.
- **Splatnost** — počet dnů od vystavení
- **Sleva z celé faktury** — procentuální sleva (0–100 %), kterou zdědí každá
  vygenerovaná faktura. Na faktuře se projeví jako záporná položka „Sleva X %"
(po sazbách DPH) — viz § 15.4.1.
- **Kategorie tržby** — pevná kategorie tržby pro všechny
  faktury z této šablony (typicky domény, hosting, licence, paušály). Bez
  výběru (*dle zakázky / zákazníka*) se při generování použije výchozí
  kategorie zakázky, případně zákazníka — tedy hodnota platná **v okamžiku
  vystavení**; pevná kategorie šablony naproti tomu drží zařazení stabilní
  i při pozdější změně těchto defaultů. Kategorie se na fakturu ukládá jako
  snapshot — změna šablony už vygenerované faktury nemění.
- **Ceny s DPH / bez DPH** — režim, ve kterém jsou zadané ceny
  položek šablony. „s DPH" (brutto) počítá daň „shora" koeficientem a propisuje
  se na každou vygenerovanou fakturu — viz [§ 15.2.6](15_Faktura_editor.md#1526-ceny-s-dph-vs-bez-dph-brutto-netto-rezim).
- **DUZP** *(plátci DPH)* — režim, kterým se počítá datum uskutečnění
  zdanitelného plnění z `issue_date`:
    - **Stejné jako datum vystavení** *(výchozí)* — DUZP = vystavení.
    - **Poslední den předchozího měsíce** — typický CZ scénář „fakturuji
      1.6. za květnové služby". Faktura má vystavení 1.6.2026, ale DUZP
      31.5.2026. Měsíc v popiscích položek se synchronizuje k DUZP, takže
      „Hosting 05/2026" zůstane „05/2026" i když je vystavena 1.6.

### 17.2.3 17.2.2a Sekce „Poznámky"

Stejná dvě pole jako u běžné faktury — **Poznámka nad položkami** a
**Poznámka pod položkami**. Text se 1:1 přenáší na každou vygenerovanou
fakturu (tiskne se nad, resp. pod tabulkou položek). Hodí se na opakované
informace typu období poskytované služby, podmínky pronájmu nebo doplňující
sdělení pro zákazníka. Obě pole podporují **placeholdery období** (viz
§ 17.2.3) — vyhodnotí se při každém generování vůči DUZP (u proformy vůči
datu vystavení), takže např. „Vyúčtování za období {BOM} – {EOM}" se na
faktuře propíše jako konkrétní rozsah měsíce.

### 17.2.4 Položky

Položky šablony se 1:1 kopírují na každou vygenerovanou fakturu (popis, mn.,
cena/j, sazba DPH). Sazba se bere podle vybraného `vat_rate_id` ze šablony.

Řádek může být zadaný ručně nebo napojený na **ceníkovou položku**. U napojené
položky zvolíš také zdroj popisu a cenovou politiku:

Napojení na jednoduchý ceník je dostupné jen bez aktivního skladu/e-shopu.
Po zapnutí skladu se napojené řádky přestanou přeceňovat z ceníku a
generování pokračuje z posledního uloženého snapshotu. Při následném uložení
šablony se takový řádek převede na běžnou ruční položku.

| Politika | Chování |
|---|---|
| **Pevná cena ze šablony** | Při výběru se uloží cena, jednotka, DPH a případný kurz. Pozdější změna ceníku ani zákaznické ceny šablonu nepřecení. |
| **Vždy aktuální cena** | Při každém generování se znovu použije aktuální zákaznická nebo obecná cena. Chybějící měna se při povoleném přepočtu vypočte kurzem k DUZP, u proformy k datu vystavení. |
| **Při změně vyžadovat kontrolu** | Změna zdrojové ceny, jednotky, DPH nebo zdroje ceny zastaví generování. V editoru použij **Převzít aktuální údaje**. Samotný pohyb kurzu kontrolu nevyžaduje. |

Volba **Popis z ceníku** přebírá aktuální ceníkový popis podle zvolené politiky.
Volba **Vlastní popis šablony** dovolí text upravit nezávisle. Placeholdery
období fungují v obou případech až při vytvoření konkrétní faktury.

Archivovaná položka může dál sloužit pevnému snapshotu. Politiky používající
aktuální údaje skončí s chybou, dokud položku neobnovíš, nenahradíš nebo
nepřevedeš na ruční položku. Změnu měny nebo režimu s/bez DPH nelze u pevného
snapshotu uložit bez jeho výslovného obnovení.

> ⚠️ **Změna sazby DPH státem** — sazba je v šabloně přišpendlená na konkrétní
> řádek číselníku. Když se sazba změní (např. 21 % → 22 %), vznikne v `vat_rates`
> **nový řádek** a starý dostane konec platnosti. Šablona pak ukazuje na vypršelou
> sazbu — generování se **zastaví s jasnou chybou** (viz banner v § 17.3) a ty
> ve šabloně vybereš aktuální sazbu. Tím se nikdy tiše nevystaví doklad se starou
> sazbou. (Totéž hlídá i klonování faktury.)

> 💡 **Neplátce DPH** — pokud je dodavatel neplátce, pravidelná fakturace se chová
> stejně jako jednorázové vystavení: výběr sazby DPH se v šabloně skryje a každá
> vygenerovaná faktura je **bez DPH** (0 % „Osvobozeno"). Pokud šablona obsahuje
> nominální sazbu, generátor ji při vystavení sám sjednotí
> na 0 %.

#### Režim OSS na položce šablony

Má-li firma [zapnutý režim OSS](40_OSS.md#4031-zapnuti-rezimu-a-platnost-registrace),
je u každého řádku šablony zaškrtávátko **OSS**. Po zaškrtnutí se pod řádkem otevře
proužek se **státem spotřeby**, **typem sazby** a **typem plnění** — přesně jako na
řádku faktury. Sazba DPH pak nabízí i sazby cizích států, aby OSS řádek mohl nést
sazbu státu spotřeby; tuzemský řádek zůstává u českých sazeb.

Co šablona **záměrně nemá**: kurz, přepočtené částky ani „opravu období". To jsou
vlastnosti konkrétního dokladu k jeho datu plnění a dopočítá je až generátor.

- **Stát spotřeby je povinný.** Bez něj se řádek uloží jako tuzemský — položku
  s OSS a bez země by cron při každém běhu vyrobil neplatnou. Formulář to zachytí
  ještě před uložením.
- **Prázdný typ sazby** je legitimní stav. Při generování se ho systém pokusí
  doplnit z číselníku, ale jen tehdy, když odvození mluví o **témže státu spotřeby**.
  Jinak řádek do OSS přiznání nepůjde.

> ⚠️ **Šablona žije roky, registrace do OSS ne.** Uložené OSS rozhodnutí má při
> generování přednost před automatickým odvozením, ale **jen pokud má firma
> k datu plnění vygenerované faktury platnou registraci do OSS**. Jakmile
> registrace skončí (nebo se režim vypne), řádek se vystaví jako **tuzemský**
> a povinně dostane příznak **k ručnímu posouzení** — přeřazení proti rozhodnutí
> člověka nesmí být tiché. Bez toho by řádek nespadl do žádného přiznání: z OSS
> podání by ho vyřadila platnost registrace, z tuzemského přiznání OSS příznak.
> Podrobně [§ 40.3.5](40_OSS.md#4045-rozdily-mezi-kanaly).

**Placeholdery období** — do popisu položky (a do poznámek nad/pod
položkami šablony) lze vložit tokeny, které se při **každém vygenerování** faktury
nahradí podle **DUZP** (u proformy podle data vystavení). Šablona se nikdy nemění,
do faktury jde vyhodnocený text. Inline přehled je přímo v editoru šablony
(rozbalovací nápověda nad položkami). Pro DUZP 15. 5. 2026:

| Token | Výsledek | Poznámka |
|---|---|---|
| `{YYYY}`, `{YY}` | 2026, 26 | rok; posun po letech: `{YYYY+1}` → 2027, `{YY-1}` → 25 |
| `{M}`, `{MM}` | 5, 05 | měsíc; posun po **měsících** vč. přetečení roku: `{MM+8}` → 01 |
| `{MMMM}` | květen | název měsíce **dle jazyka dokladu** (cs/en); `{MMMM+1}` → červen |
| `{Q}` | 2 | čtvrtletí 1–4; posun po čtvrtletích: `{Q+1}` → 3 |
| `{D}`, `{DD}` | 15, 15 | den; posun po dnech: `{D+14}` → 29 |
| `{DATE}` | 15. 5. 2026 | celé ref. datum, formát dle jazyka dokladu (en: May 15, 2026) |
| `{DATE+1Y-1D}` | 14. 5. 2027 | datová aritmetika — kombinace `±N` jednotek `D`/`M`/`Y`, zleva doprava |
| `{BOM}`, `{EOM}` | 1. 5. 2026, 31. 5. 2026 | začátek/konec měsíce (celé datum); posun po měsících: `{EOM+1}` → 30. 6. 2026, `{EOM-1}` → 30. 4. 2026 |

Typický příklad (prodloužení domény na rok):

```text
Prodloužení domény example.cz na období {DATE} - {DATE+1Y-1D}
→ Prodloužení domény example.cz na období 15. 5. 2026 - 14. 5. 2027
```

Další ukázky: `sezóna {YY}/{YY+1}` → „sezóna 26/27", `servis {Q}Q/{YYYY}` →
„servis 2Q/2026", `úklid za {MMMM} {YYYY}` → „úklid za květen 2026",
`služby za období {BOM} - {EOM}` → „služby za období 1. 5. 2026 - 31. 5. 2026".

> 💡 **Přetečení měsíce je ošetřené.** Posun po měsících/letech v `{DATE±…}`
> se ořezává na poslední den cílového měsíce (jako MySQL `DATE_ADD`):
> 31. 1. `{DATE+1M}` → **28. 2.** (ne 3. 3., jak by dalo holé PHP), 29. 2. 2028
> `{DATE+1Y}` → 28. 2. 2029. Posun po dnech (`{DATE+30D}`) zůstává exaktní.
> Měsíční tokeny (`{M}`, `{MMMM}`, `{EOM}`…) jsou kotvené na měsíc, takže
> přetečení u nich nehrozí vůbec (31. 1. `{M+1}` → 2).

> 💡 Tokeny se píší **velkými písmeny**. Cokoli nerozpoznaného (`{foo}`, `{yyyy}`,
> obyčejné závorky v textu) zůstává beze změny — existující šablony fungují
> beze změny a nic není potřeba escapovat. Placeholdery fungují nezávisle na
> volbě „Synchronizovat měsíc" níže (lze kombinovat; obojí míří na stejné
> referenční datum).

### 17.2.5 Sekce „Automatizace"

- **Synchronizovat měsíc v popiscích položek s DUZP** — pokud je v popisu
  vzorec `M/YYYY` (např. „Hosting 03/2026"), automaticky se **nahradí**
  měsícem/rokem z DUZP (`tax_date`) generované faktury — případně z
  `issue_date` u proform, které DUZP nemají. Sync je idempotentní:
  šablonový popis „Hosting 03/2026" generuje „Hosting 05/2026" pokud DUZP
  spadá do 5/2026, a „Hosting 06/2026" pokud do 6/2026 — bez kumulativního
  driftu. Pattern detektor zvládá `M/YYYY`, `YYYY-MM`, `M.YYYY`, `M-YYYY`
  a varianty; plná data typu `2026-05-15` chrání lookaround a nemění je.
  Přednostně používej **placeholdery období** (viz § 17.2.3) — jsou
  explicitnější (`{MM}/{YYYY}`) a umí víc (roky, čtvrtletí, celá data);
  volba synchronizace se hodí pro šablony s prostým `M/YYYY` v textu.
- **Po vygenerování rovnou vystavit** — cron rovnou přidělí číslo faktury
  z šablony číslování dodavatele a zafixuje snapshoty klienta/dodavatele/
  bankovního spojení (status = `issued`). Pokud vypneš, vygeneruje se jen
  draft a ty ho potom musíš ručně zkontrolovat a vystavit.
- **Po vystavení rovnou odeslat klientovi e-mailem** — automatický send PDF
  + e-mailu na klienta a fakturační e-maily zakázky. Vyžaduje předchozí
  volbu (nelze odeslat draft).
- **Kdy vytvořit koncept** — viz [§ 17.2.5](#1726-otevreny-koncept-prubezny-vykaz-vicepraci).
- **Připomenout dní před vystavením** — jen u režimu „Na začátku období";
  počet dní předem, kdy ti přijde e-mailová připomínka doplnit vícepráce
  (0 = neposílat).

**Výchozí nastavení šablony** má obojí (vystavit + odeslat) zapnuté a režim
konceptu „Až při vystavení" → plně automatická pravidelná fakturace.

### 17.2.6 Otevřený koncept (průběžný výkaz víceprací)

Řeší fakturaci typu **fixní SLA + nepravidelné vícepráce**: část faktury je
stálý paušál, ke kterému během měsíce přibývá proměnný seznam víceprací.

Přepínač **„Kdy vytvořit koncept"** má dvě hodnoty:

- **Až při vystavení** *(výchozí)* — faktura vznikne až
  v den vystavení (`next_run_date`) a podle automatizace se rovnou vystaví.
- **Na začátku období** — cron vytvoří **koncept** faktury (s fixními
  položkami ze šablony) **1. den fakturovaného měsíce**. Koncept pak celý
  měsíc zůstává ve stavu *draft* a ty do něj průběžně píšeš **vícepráce přes
  výkaz práce** (výkaz je editovatelný jen u konceptu). **Den po**
  `next_run_date` (typicky 1. den dalšího měsíce) cron koncept automaticky
  **uzavře, přepočítá včetně víceprací, vystaví a odešle**. Uzávěrka se posune
  o den za konec období schválně — aby se do faktury stihla započítat i práce
  z **posledního dne** období.

  Datum vystavení i DUZP konceptu jsou přitom od začátku nastavené na
  **plánovaný konec období** (`next_run_date` + zvolený režim DUZP) a při
  vystavení se nemění — faktura nese datum konce období, i když fyzicky vznikla
  o den později.

**Podmínky režimu „Na začátku období":**

- jen pro **měsíční** periodicitu,
- vyžaduje zapnuté **„Po vygenerování rovnou vystavit"** (koncept se na konci
  období uzavře sám).

**Typický scénář (fakturace za červen, vystavení a DUZP ke konci měsíce):**

1. Šablona: měsíčně, *Poslední den měsíce*, DUZP *Stejné jako datum
   vystavení*, režim konceptu *Na začátku období*, vystavit + odeslat zapnuto.
2. **1.6.** cron otevře koncept s fixním SLA řádkem (datum vystavení i DUZP
   = 30.6.).
3. **Během června** doplňuješ vícepráce do výkazu práce na tom konceptu.
4. **29.6.** (1 den předem) ti přijde e-mailová připomínka; **30.6.** zůstává
   koncept otevřený, takže do něj stihneš zapsat i práci z posledního dne.
5. **1.7.** cron v jednom běhu nejdřív **uzavře červnový koncept** — vystaví
   (SLA + vícepráce) a odešle klientovi, faktura nese datum vystavení i DUZP
   **30.6.** (konec období) — a hned poté **otevře nový koncept na červenec**
   (datum vystavení i DUZP 31.7.), takže do něj můžeš zase celý měsíc psát.

> Pokud koncept během měsíce vystavíš ručně, cron to pozná a v den vystavení
> už nic nevytvoří — jen posune rozvrh na další měsíc.

## 17.3 Lifecycle šablony

Šablona má tři stavy:

- **Active** — cron ji každý den kontroluje; jakmile `next_run_date <= dnes`,
  vygeneruje fakturu a posune `next_run_date` o jeden cyklus
- **Paused** — cron ji přeskakuje (manuální *Vygenerovat teď* dál funguje)
- **Expired** — `next_run_date` překročil `end_date`; cron i UI ji odmítají
  spustit, dokud nezvýšíš `end_date`

V seznamu (a na detailu) šablony jsou tlačítka **Pozastavit / Obnovit**,
**Vygenerovat teď** a **Vygenerovat koncept** (jednorázový manuál run —
užitečné pro testování i pro ruční vytvoření dokladu mimo rozvrh).

- **Vygenerovat teď** — respektuje nastavení šablony: u `auto_issue=true`
  fakturu rovnou vystaví (a případně odešle). Otevře modal s **date pickerem**
  (default dnešní datum); u budoucího data upozorní žlutým warningem, že daňově
  by `issue_date` mělo odpovídat reálnému datu vystavení.
- **Vygenerovat koncept** — vytvoří **koncept** i u šablony s automatickým
  vystavením (nevystaví, neodešle) — ručně ho pak zkontroluješ a vystavíš.
  U režimu *Na začátku období* vytvoří přesně ten koncept, který by jinak
  otevřel cron 1. dne (idempotentní, k plánovanému datu, neposouvá rozvrh) —
  datum se proto nevybírá a varování o budoucím datu se nezobrazuje (budoucí
  DUZP je tu záměr, koncept se edituje celý měsíc).

> ⚠️ **Banner „Generování selhalo"** — když poslední automatické (cronové)
> generování selže (typicky kvůli vypršelé sazbě DPH nebo nekladné částce),
> uloží se poslední chyba a zobrazí se jako červený banner na detailu šablony
> a odznak v seznamu. Po úspěšném (ručním i cronovém) vygenerování banner zmizí.

## 17.4 Cron

Skript `api/bin/cron-generate-recurring-invoices.php` — spouštěj ho **jednou
denně**:

```cron
0 6 * * * cd /var/www/myucto.cz && php api/bin/cron-generate-recurring-invoices.php
```

Pro testy se hodí `--dry-run` (vypíše, co by se vygenerovalo, ale nic
nevytvoří).

Cron v jednom běhu zvládá tři fáze:

1. **Otevření konceptu** — u šablon v režimu *Na začátku období*, kde už začalo
   fakturované období, vytvoří koncept (idempotentně — jednou za období).
2. **Vystavení** — u šablon po `next_run_date` vystaví (režim *Na začátku
   období* uzavře otevřený koncept **den po** konci období; ostatní režimy
   vygenerují a vystaví přímo v `next_run_date`). U režimu *Na začátku
   období* cron hned po uzávěrce **rovnou otevře koncept dalšího období**, pokud
   už začalo — takže 1. den měsíce proběhne „uzavři minulé → otevři nové"
   v jednom běhu.
3. **Připomínka** — pošle e-mailové připomínky k otevřeným konceptům, kterým
   se blíží vystavení (viz „Připomenout dní před vystavením").

**Catch-up:** pokud cron několik dní nešel, generuje jen **jednu** fakturu
za cyklus a posune o jeden krok — zbytek backlog se doplní postupně další
dny. Tím se zabrání tomu, aby po výpadku cron vygeneroval naráz 30 faktur
za poslední měsíc.

## 17.5 Kill-switch (Nastavení → Dodavatel)

V **Nastavení → Můj dodavatel** je přepínač **„Generovat pravidelné
fakturace cronem"**. Pokud je vypnutý, cron tohoto dodavatele úplně
přeskočí — všechny šablony se zastaví, dokud ho zase nezapneš. Manuální
tlačítko **Vygenerovat teď** funguje nezávisle.

## 17.6 Vazba na vygenerované faktury

Každá faktura vytvořená šablonou má vazbu `recurring_template_id` (sloupec
v `invoices`). V detailu faktury se zobrazí badge **↻ Pravidelná** s odkazem
na šablonu, ze které pochází.

Když šablonu smažeš, vygenerované faktury zůstanou (databáze má `ON DELETE
SET NULL` — vazba se vyčistí, faktura zůstane platná).

## 17.7 Activity log

Vše se zaznamenává:

- `recurring.created` / `updated` / `deleted`
- `recurring.paused` / `resumed`
- `recurring.draft_opened` — cron otevřel koncept na začátku období
  (režim *Na začátku období*)
- `recurring.generated` — když cron nebo *Vygenerovat teď* udělal fakturu
  (payload: `invoice_id`, `next_run`, `auto_issue`, `auto_send`, `sent_to`)
- `recurring.reminder_sent` — odeslána připomínka k otevřenému konceptu
- `cron.generate_recurring` — sumář jednoho běhu cronu (počet otevřených,
  vygenerovaných, vystavených, odeslaných, připomínek, chyb)

## 17.8 REST API

Pravidelné fakturace mají vlastní REST endpointy pod `/api/recurring/*`:

| Endpoint | Akce |
| --- | --- |
| `GET    /api/recurring` | seznam (filtry: `client_id`, `status`) |
| `POST   /api/recurring` | vytvořit šablonu |
| `GET    /api/recurring/{id}` | detail |
| `PUT    /api/recurring/{id}` | update |
| `DELETE /api/recurring/{id}` | smazat |
| `POST   /api/recurring/{id}/pause` | pozastavit |
| `POST   /api/recurring/{id}/resume` | obnovit |
| `POST   /api/recurring/{id}/run-now` | manuální spuštění (volitelně `issue_date`) |

Detailní schémata najdeš v aplikačních rozhraních
[`/api/reference`](/api/reference) (Redoc) nebo
[`/api/docs`](/api/docs) (Swagger UI, Try it out).
