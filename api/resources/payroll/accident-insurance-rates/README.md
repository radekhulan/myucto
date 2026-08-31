# Sazebník zákonného pojištění odpovědnosti zaměstnavatele

Připnutý přepis **přílohy č. 2 vyhlášky č. 125/1993 Sb.** — sazby pojistného
zákonného pojištění odpovědnosti zaměstnavatele za škodu při pracovním úrazu
nebo nemoci z povolání. Slouží k tomu, aby si účetní mohla sazbu **najít
a ověřit** přímo v aplikaci, místo aby ji opisovala odjinud.

## Připnutý stav

| Údaj | Hodnota |
|---|---|
| Předpis | **vyhláška č. 125/1993 Sb.**, ve znění účinném od 1. 1. 2012 (verze 6) |
| Příloha | č. 2 — „Sazby pojistného podle převažující činnosti vykonávané zaměstnavatelem" |
| Seznam činností | původní znění vyhlášky, účinné od **22. 4. 1993** (žádná z novel 43/1995, 98/1996, 74/2000 ani 365/2011 Sb. do něj nesáhla) |
| Sazby | **vyhláška č. 487/2001 Sb., čl. I bod 3**, účinná od **1. 1. 2002** |
| Klasifikace činností | **OKEČ** (zrušená k 31. 12. 2007) |
| Minimální pojistné | **100 Kč** za kalendářní čtvrtletí (poslední věta přílohy č. 2) |
| Sazbových skupin | **8** (50,4 / 10,5 / 9,8 / 8,4 / 7 / 5,6 / 4,2 / 2,8 ‰) |
| Pojmenovaných činností | **98** (+ 2 skupiny bez kódu) |
| Zdroj textu | <https://www.zakonyprolidi.cz/cs/1993-125> |
| Balíček | `cz-accident-insurance-annex-2-v1` |

Otisk manifestu je v `SHA256SUMS`. Text přílohy je **úřední dílo** podle § 3
písm. a) autorského zákona, takže je převzatý doslovně.

## Proč je to podklad, ne odpověď

**Číslo kódu se nikdy nepáruje.** Příloha člení činnosti podle OKEČ — doslova:
„Členění ekonomických činností bylo převzato z Odvětvové klasifikace
ekonomických činností (OKEČ) zpracované Českým statistickým úřadem." OKEČ byla
zrušena sdělením ČSÚ č. 244/2007 Sb. k 31. 12. 2007 a nahrazena CZ-NACE, jenže
vyhláška se od té doby nezměnila. Stejné číslo proto v obou klasifikacích
znamená něco jiného: **OKEČ 62 je „Letecká doprava", CZ-NACE 62 jsou činnosti
v oblasti informačních technologií.** Párování podle čísla by softwarové firmě
nabídlo sazbu letecké dopravy.

**Závazný převodník neexistuje.** Žádný právní předpis převod OKEČ → CZ-NACE
pro účely § 12 odst. 2 vyhlášky neupravuje. ČSÚ i obě pojišťovny (Kooperativa,
Generali Česká pojišťovna) publikují převodníky jako **metodickou pomůcku**,
a ani ten pojišťovnický není funkcí: několika kódům CZ-NACE v něm vycházejí dvě
různé sazby, protože do jednoho kódu NACE rev. 2 spadly činnosti z různých
sazbových skupin (u 20.59.0 dokonce 10,5 ‰ proti 5,6 ‰).

**Dvě z osmi skupin nemají kód vůbec.** Sazba 10,5 ‰ je daná věcným kritériem
(práce s výbušninami, radioaktivními látkami, radonem, infekčním materiálem,
jedy, práce ve velkých výškách nebo hloubkách) a 5,6 ‰ je zbytková skupina
„Ostatní ekonomické činnosti", do které spadne většina dnešních firem. Ani
dokonalý převodník kódů by tedy sazbu neurčil.

**Nikdo firmě sazbu nesděluje.** Pojistná smlouva se neuzavírá, pojišťovna
neposílá výměr ani předpis pojistného; § 12 odst. 2 ukládá výpočet i platbu
zaměstnavateli, a pojišťovna reaguje až při nesrovnalosti. „Ověřte proti
výměru" je tedy vedle — ověřuje se proti **skutečné převažující činnosti**
a případně proti převodníku pojišťovny.

Proto aplikace sazbu **nabízí k ověření a nikdy ji sama neuloží**. Uloženou
hodnotou zůstává to, co zadá účetní.

## Doslovnost přepisu

Tabulka je přepsaná verbatim včetně zjevné chyby předpisu: řádek
`24.12 Protektorování a opravy pryžových pneumatik` má v OKEČ číslo 25.12
(24.12 je „Výroba barviv a pigmentů") a stojí mimo číselné pořadí. Chyba je
shodně v úplném znění na zakonyprolidi.cz i v PDF MPSV, je tedy v úředním
textu. Opravovat vyhlášku nám nepřísluší.

Sazba `7 ‰` je v předpise zapsaná jako „7", ne „7,0"; v datech je normalizovaná
na `7.00`, aby všechny sazby měly stejný tvar jako sloupec `rate_per_mille`
v databázi (`DECIMAL(6,2)`).

## Proč soubor, a ne databáze

- Je to **vydání, ne provozní data**: sazby se naposledy měnily v roce 2002.
  Změna má být vidět v diffu jako změna otisku, ne jako migrace, kterou musí
  dohnat každá instalace zvlášť.
- **Žádný SQL dotaz sazebník nepotřebuje** — do `payroll_accident_insurance_rates`
  se ukládá jen číslo sazby, nikde se na sazebník nejoinuje.
- Data jsou **stejná pro všechny nájemce**.
- Stejný vzor už projekt používá u `api/resources/payroll/cz-isco/`,
  `api/resources/payroll/jmhz/` a `api/resources/ciselniky/okec.txt`.

## Obnova po novele vyhlášky

1. Ověř nové znění přílohy č. 2 proti Sbírce zákonů.
2. V `tools/AccidentInsuranceRateSchedulePackageBuilder.php` uprav konstanty
   `DECREE` (nová novela, účinnost) a `GROUPS`; při změně struktury zvyš
   `PARSER_VERSION` a `PACKAGE_KEY`.
3. `php tools/AccidentInsuranceRateSchedulePackageBuilder.php` — builder odmítne
   duplicitní kód, skupinu bez činností, skupinu bez kódu i bez popisu a
   neznámý druh skupiny.
4. Přepiš `AccidentInsuranceRateSchedule::DEFAULT_MANIFEST_SHA256` a očekávané
   počty v `api/tests/Unit/Payroll/AccidentInsuranceRateScheduleIntegrityTest.php`.

Builder i runtime jsou **fail-closed**: prázdný nebo osekaný manifest neprojde
kontrolou počtů, obsahového hashe ani otisku.
