# 83. Aktivace podvojného účetnictví

**Cesta: `Nástroje → Aktivace a doúčtování`**

Aktivační průvodce převede firmu z daňové evidence na podvojné účetnictví a
řízeně doplní účetní stopu historických dokladů. Položku menu a všechny mutace
vidí jen administrátor s oprávněním `accounting.periods.manage`.

Aktivace není pouhé přepnutí přepínače. Obsahuje datum přechodu, účtový rozvrh,
otevírací rozvahu, kontrolní běh a dávkové doúčtování. Až po úspěšném dokončení
se `accounting_mode` změní na `double_entry`.

## 83.1 Stav a pět kroků

Průvodce rozlišuje stavy:

- `none` — aktivace nebyla zahájena,
- `draft` — datum a počáteční stavy se připravují,
- `running` — ostrý backfill běží,
- `completed` — podvojné účetnictví je aktivní,
- `failed` — běh skončil chybou; po opravě lze spustit znovu.

V hlavičce je vidět počet čekajících vydaných a přijatých faktur, pokladních
dokladů a bankovních transakcí, zámek účtování k datu a poslední úloha.

## 83.2 Krok 1 — datum zahájení

Datum `accounting_starts_on` určuje:

- první den, od kterého se historické doklady doplní do účetního deníku,
- datum otevíracího zápisu,
- hranici, před kterou starší doklady zůstanou v přechodovém můstku.

Datum musí být platné a nejvýše rok v budoucnosti. Aktivaci nelze znovu zahájit,
pokud už běží jiná úloha. Při uložení server idempotentně naseeduje účtový
rozvrh a systémové předkontace a nastaví stav `draft`.

Volbu data dělej podle schváleného přechodového postupu. Příliš časné datum
zahrne doklady, které už představují jen počáteční saldo; příliš pozdní může
naopak vynechat transakce, jež mají být v deníku.

## 83.3 Krok 2 — otevírací rozvaha

Každý řádek obsahuje aktivní účet, stranu MD/Dal, kladnou částku a poznámku.
Účet 701 se nezadává: systém jej při účtování doplní jako protiúčet každého
počátečního zůstatku.

Stejný účet nesmí být na stejné straně dvakrát. Součet MD a Dal musí být
vyrovnaný na haléř. Uložení nahrazuje celý koncept v jedné transakci a vrací
hash normalizovaných řádků. Tento hash váže pozdější dry-run k přesné verzi
počátečních stavů.

Zamítnutá rozvaha se nevrací jako obecná chyba: server pojmenuje důvod (nulová
částka, účet mimo osnovu, dvakrát tentýž účet na téže straně) a označí řádek,
který ji způsobil. Průvodce na ten řádek přelistuje a zvýrazní ho.

Přeskočení rozvahy je vedlejší akce s potvrzením, ne hlavní tlačítko. Prázdný
výsledek předvyplnění neznamená, že firma počáteční stavy nemá: předvyplnění umí
najít jen zůstatky vedené v této aplikaci, takže u firmy přecházející z jiného
programu nenajde nic, i když počáteční stavy existují.

### 83.3.1 Předvyplnění z přechodového můstku

Akce **Předvyplnit** sestaví návrh k dni předcházejícímu zahájení:

| Účet | Datový zdroj |
|---|---|
| 311 MD | Neuhrazené pohledávky z daňové evidence |
| 321 Dal | Neuhrazené závazky |
| 314 MD | Poskytnuté zálohy |
| 324 Dal | Přijaté zálohy |
| 132 MD | Zásoby z přechodového můstku |
| `211.xxx` MD/Dal | Stav zaúčtovaných pokladních dokladů, samostatný řádek za každou pokladnu |
| `221.xxx` MD/Dal | Stav bankovních transakcí, samostatný řádek za každý vlastní účet |

Do návrhu se vloží jen účty, které v osnově existují a jsou aktivní. Bankovní
zůstatek respektuje tenantové vlastnictví výpisu a počítá jen CZK pohyby do
rozhodného dne. Návrh nenahrazuje inventuru. Administrátor musí doplnit ostatní
aktiva, pasiva, oprávky, kapitál, daně a další zůstatky podle průkazných podkladů.

Pokladna se stejně jako banka nepředvyplňuje jedním souhrnem: každý pokladní
doklad patří konkrétní pokladně a ta nese svou analytiku (**211.001**,
**211.002** …, viz kapitola *Pokladna*) — tutéž, na kterou pak padají její
pohyby. Díky tomu sedí pokladní kniha každé pokladny s hlavní knihou od prvního
dne účetnictví. Poznámka řádku pokladnu pojmenuje. U valutové pokladny jde do
rozvahy CZK ekvivalent dokladů, kurzem se nepřepočítává podruhé.

Doklad, u kterého pokladnu dohledat nejde, i pokladna, jejíž analytiku máte
v osnově vypnutou, spadnou na syntetiku **211** s výzvou k ručnímu rozúčtování
v poznámce — stejně jako u banky níže.

Banka se nepředvyplňuje jedním souhrnem: každý výpis nese číslo vlastního účtu,
takže počáteční stav jde rovnou na analytiku toho účtu (**221.100**, **221.200** …,
viz kapitola *Banka*) — tutéž, na kterou pak padají jeho pohyby. Díky tomu sedí
zůstatek analytiky na výpis od prvního dne účetnictví.

Výpis, u kterého vlastní účet dohledat nejde (číslo účtu chybí v Nastavení nebo
byl účet zrušen), se **nerozděluje odhadem** — jeho zůstatek zůstane na syntetice
**221** a v poznámce řádku je výzva rozúčtovat ho ručně. Rozpad opravíš tak, že
účet doplníš v Nastavení a dáš **Předvyplnit** znovu, nebo řádek 221 ručně
rozepíšeš na analytiky.

### 83.3.2 Zaúčtování počátečních stavů

Ostrý běh zajistí otevřené období pro datum zahájení a vytvoří jediný zápis se
zdrojem `opening`. Pro řádek MD vytvoří `účet MD / 701 Dal`, pro řádek Dal
`701 MD / účet Dal`. Číslo dostane z řady otevíracích zápisů.

Pokud předchozí den patří uzavíranému, uzavřenému nebo schválenému období,
otevření už vlastní uzávěrka předchozího období a aktivační zápis se odmítne.
Opakovaný běh používá stejné `source_type + source_id`, takže zápis neduplikuje.

### 83.3.3 Doplnění rozvahy po dokončené aktivaci

Kroky průvodce jsou obousměrné: na krok **Otevírací rozvaha** se dostaneš
kliknutím ve stepperu, dokud je období zahájení otevřené. Platí to i po dokončené
aktivaci — protokol navíc nabídne tlačítko **Doplnit otevírací rozvahu**, pokud
firma otevírací zápis nemá.

Postup doplnění: zadej řádky, ulož, spusť znovu kontrolu nanečisto a doúčtování.
Vznikne otevírací zápis; existující zápisy se díky idempotentnímu zdrojovému
klíči nezdvojí. Změna rozvahy mění hash, takže ostrý běh bez nové kontroly
skončí na `dry_run_required`.

Cestu zpět zavírá až uzavřené (uzavírané, schválené) období: pak otevření vlastní
uzávěrka předchozího roku a počáteční stavy patří do ní. Krok **Datum zahájení**
se po dokončené aktivaci nezpřístupní vůbec — přepsal by datum přechodu a vrátil
stav na `draft`.

## 83.4 Krok 3 — kontrola nanečisto

Dry-run vytvoří background úlohu a projde stejné fáze jako ostrý běh, ale nic
nezaúčtuje. Ověřuje i předpoklady, na kterých by ostrý běh spadl — u neprázdné
rozvahy zejména to, že období zahájení je otevřené a že otevření nepatří
uzávěrce předchozího roku:

1. kontrola otevírací rozvahy,
2. vydané a přijaté doklady,
3. pokladna,
4. banka,
5. kontrola pokrytí a rovnosti deníku.

Report uvádí očekávané, zpracované, přeskočené a chybné položky, důvody
přeskočení a konkrétní problémy dokladů. U dokumentů se navíc porovná počet
očekávaných kandidátů s počtem skutečně obsloužených; chybějící i neočekávaný
řádek je chyba úplnosti.

Úspěšný dry-run musí mít `failed_total = 0`, úplné pokrytí a vyrovnaný deník.
Jakákoli změna data nebo počátečních stavů změní hash a před ostrým během je
nutné kontrolu zopakovat.

## 83.5 Krok 4 — ostré doúčtování

Ostrý job běží ve workeru `api/bin/accounting-backfill-worker.php`. Web stav
pravidelně načítá a zobrazuje fázi, počet zpracovaných položek, log a závěrečný
report.

### 83.5.1 Doklady

`DocumentBackfill` používá stejnou účetní cestu jako běžné zaúčtování. Zpracuje
doklady od data zahájení, které podle stavů mají patřit do deníku, a využije
jejich položky, sazby DPH, měnu a předkontace. Již existující idempotentní zápis
aktualizuje nebo přeskočí; nevytváří druhý předpis.

Po bankovní fázi následuje ještě zúčtování záloh. Je záměrně až za platbami,
protože převod proformy na finální doklad musí znát skutečné spárování.

### 83.5.2 Pokladna

`CashBackfill` bere zaúčtované pokladní doklady bez deníku. Dry-run používá
čistý náhled řádků, ostrý běh stejnou `CashDocumentService` jako nový doklad.
Zdrojové ID zajistí idempotenci.

### 83.5.3 Banka

Bankovní backfill nejdřív zpracuje párování dokladů. Volitelné firemní pravidlo
smí při historii vytvořit jen **návrh**; automatický režim se během backfillu
degraduje na `suggest`. Již zaúčtovaný pohyb se nepřepíše.

Po každé fázi worker kontroluje požadavek na zrušení. Zrušení zastaví další
zpracování a vrátí aktivační stav na `draft`; již potvrzené transakce se
automaticky hromadně nemažou. Před opakováním proto projdi report — idempotentní
zdrojové klíče zajistí, že hotové položky nevzniknou znovu.

## 83.6 Dokončení a oprava selhání

Po všech fázích se znovu sečtou všechny řádky deníku firmy v haléřích. Pokud
MD ≠ Dal nebo report obsahuje chyby, job skončí jako `failed` a režim se
neaktivuje.

Teprve úspěšný ostrý běh v jedné závěrečné transakci:

- přepne firmu do `double_entry`,
- uloží dokončený stav,
- zapíše historii účetního režimu a auditní událost,
- označí job jako dokončený.

Po chybě oprav konkrétní doklad, účet, kurz, předkontaci nebo počáteční stav,
spusť znovu dry-run a poté ostrý běh. Tlačítko opravy používá tutéž
idempotentní exekuci, nejde o jiný „silový“ režim.

## 83.7 Historie úloh, zámky a souběh

Historie je stránkovaná a uchovává druh běhu, stav, fázi, parametry, report,
log, poslední chybu a časy. Aktivní může být nejvýše jedna úloha firmy;
unikátní databázová podmínka chrání i dva souběžné požadavky.

Server označí opuštěnou úlohu jako selhanou, pokud worker přestal aktualizovat
stav. Požadavek **Zrušit** nastaví příznak, který worker čte mezi dávkami.
Pokud se worker nepodaří vůbec spustit, job i aktivace se označí jako selhané
a API vrátí chybu.

Zámek účtování k datu se během aktivace neobchází. Pokud datum backfillu leží
v zamčené části, účetní služba zápis odmítne. Stejně se respektuje otevřenost
období a aktivita účtů.

## 83.8 Oprávnění a nejčastější chyby

Stav může číst uživatel s oprávněním k firemnímu nastavení. Zahájení, počáteční
stavy, joby a zrušení vyžadují administrátorské
`accounting.periods.manage:write`. Vše je tenantově omezené a auditované.

| Chyba | Význam a řešení |
|---|---|
| `opening_unbalanced` | Doplň protistranu nebo oprav částku |
| `opening_empty` | Otevírací rozvaha neobsahuje žádný řádek |
| `period_not_open` | Období zahájení není otevřené; otevři je v účetních obdobích |
| `opening_owned_by_closing` | Otevírací zápis patří uzávěrce předchozího roku |
| `dry_run_required` | Změnila se data; spusť znovu kontrolu |
| `job_already_running` | Počkej na aktivní job nebo jej řízeně zruš |
| `remaining/period/lock` | Zdrojová data či období se od náhledu změnily |
| `worker_start_failed` | Worker se nespustil; zkontroluj serverový log |
| nevyrovnaný deník | Najdi chybnou fázi v reportu; režim se nepřepnul |

> [!IMPORTANT]
> Před ostrým během ulož zálohu databáze a odsouhlas přechodovou rozvahu.
> Idempotence chrání před duplicitami, nikoli před věcně chybným počátečním
> zůstatkem.
