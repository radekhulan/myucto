# 68. Aktivace podvojného účetnictví

> Návod, jak převést firmu z daňové evidence na podvojné účetnictví: zvolit datum zahájení, zadat
> otevírací rozvahu, zkontrolovat historii nanečisto a doúčtovat doklady. Pro administrátora s oprávněním
> ke správě účetních období.

## 68.1 Kdy to potřebujete

Kapitolu otevřete, když:

- firma dosud vede daňovou evidenci a má začít účtovat podvojně,
- na hlavním panelu nebo v horní liště vidíte hlášku **Historie není doúčtována - sestavy jsou neúplné**,
- po dokončené aktivaci potřebujete doplnit otevírací rozvahu nebo doúčtovat chybějící zápisy,
- aktivace skončila chybou a chcete ji opravit a zopakovat.

Aktivace není pouhé přepnutí přepínače. Obsahuje datum přechodu, účtový rozvrh, otevírací rozvahu, kontrolní
běh a dávkové doúčtování. Až po úspěšném dokončení se firma přepne do podvojného účetnictví.

## 68.2 Než začnete

1. **Oprávnění.** Položku menu `Nástroje → Aktivace a doúčtování` a všechny akce vidí jen administrátor s oprávněním ke správě účetních období (zápis). Stav čte uživatel s oprávněním k firemnímu nastavení.
2. **Schválený přechodový postup.** Datum zahájení volte podle něj (viz [§ 68.3](#683-krok-za-krokem-datum-zahajeni)).
3. **Podklady k počátečním stavům.** Připravte zůstatky ke dni před zahájením: ostatní aktiva, pasiva, oprávky, kapitál, daně. Předvyplnění je jen návrh.
4. **Účetní období a účtový rozvrh.** Období zahájení musí být otevřené. Rozvrh a systémové předkontace se založí při zahájení sami.
5. **Záloha databáze** před ostrým během.

Na stránce vidíte nahoře počet čekajících vydaných a přijatých faktur, pokladních dokladů a bankovních
transakcí, zámek účtování k datu a poslední úlohu. Průvodce má pět kroků: **Datum zahájení**,
**Otevírací rozvaha**, **Kontrola**, **Spuštění** a **Protokol**.

## 68.3 Krok za krokem: datum zahájení

1. Otevřete `Nástroje → Aktivace a doúčtování`.
2. Zadejte **Datum zahájení podvojného účetnictví**. Doklady s datem před tímto dnem se nedoúčtují, vstoupí jen do otevírací rozvahy.
3. Klikněte na **Pokračovat**.

Datum určuje první den, od kterého se historické doklady doplní do účetního deníku, datum otevíracího zápisu
a hranici, před kterou starší doklady zůstanou v přechodovém můstku. Musí být platné a nejvýše rok
v budoucnosti. Aktivaci nelze znovu zahájit, pokud už běží jiná úloha. Při uložení aplikace idempotentně
založí účtový rozvrh a systémové předkontace.

**Jak poznáte, že je hotovo:** Průvodce přejde na krok **Otevírací rozvaha**.

> [!WARNING]
> Příliš časné datum zahrne doklady, které už představují jen počáteční saldo. Příliš pozdní může naopak
> vynechat transakce, jež mají být v deníku. Pokud období do zvoleného data je uzamčené, tyto zápisy se
> přeskočí a zůstanou v protokolu.

## 68.4 Krok za krokem: otevírací rozvaha

1. V kroku **Otevírací rozvaha** klikněte na **Předvyplnit z daňové evidence**. Aplikace sestaví návrh k dni před zahájením (viz [§ 68.9.1](#6891-predvyplneni-z-prechodoveho-mustku)).
2. Zkontrolujte řádky. Další přidáte tlačítkem **Přidat řádek**. Každý řádek má **Účet**, **Stranu** (MD/Dal), kladnou **Částku** a **Poznámku**. Účet 701 nezadávejte: protiúčet doplní systém sám.
3. Doplňte ostatní aktiva, pasiva, oprávky, kapitál, daně a další zůstatky podle průkazných podkladů.
4. Sledujte stav bilance: **✓ vyrovnáno**, nebo **Σ MD ≠ Σ D - opravte rozvahu**.
5. Klikněte na **Uložit a pokračovat**.

Nemá-li firma žádné počáteční stavy, můžete použít **Pokračovat bez počátečních stavů** a potvrdit dotaz.
Prázdný výsledek předvyplnění neznamená, že firma počáteční stavy nemá: předvyplnění umí najít jen zůstatky
vedené v této aplikaci, takže u firmy přecházející z jiného programu nenajde nic.

**Jak poznáte, že je hotovo:** Rozvaha je vyrovnaná a průvodce přejde na krok **Kontrola**.

> [!WARNING]
> Stejný účet nesmí být na stejné straně dvakrát. Součet MD a Dal musí být vyrovnaný na haléř. Návrh
> nenahrazuje inventuru. Přeskočení rozvahy je vedlejší akce s potvrzením, ne hlavní tlačítko.

## 68.5 Krok za krokem: kontrola nanečisto

1. V kroku **Kontrola** klikněte na **Spustit kontrolu (nic se nezapíše)**.
2. Počkejte na dokončení úlohy na pozadí. Postup vidíte ve fázi, počtech zpracovaných položek a logu.
3. Přečtěte si výsledek. Při chybách se zobrazí **Kontrola našla N chyb. Opravte je a spusťte ji znovu.** Report
   položky rozdělí do tří seznamů:
   - **K opravě** jsou doklady, které nejde zaúčtovat. U každého je odkaz na doklad, datum a konkrétní příčina.
     Patří sem i zápočty proti účtu, které se nepodařilo doúčtovat.
   - **Nezaúčtované bankovní pohyby** jsou pohyby, které doúčtování přeskočilo, s odkazem do detailu výpisu
     a důvodem (například pohyb nespárovaný s dokladem nebo číslo účtu výpisu, které patří více firmám).
     Aktivaci neblokují.
   - **Přeskočené doklady** jsou doklady v uzamčeném nebo uzavřeném období a doklady, které nemají co účtovat.
     U každého je uvedeno, zda **Zápis zůstal beze změny** (doklad už platný zápis má), nebo **Doklad zůstal
     nezaúčtovaný**. Aktivaci neblokují.
4. Opravte chyby u dokladů, účtů, kurzů, předkontací nebo počátečních stavů a klikněte na **Spustit kontrolu znovu**.
5. Až kontrola skončí bez chyb, pokračujte.

Kontrola projde stejné fáze jako ostrý běh, ale nic nezaúčtuje: otevírací rozvahu, vydané a přijaté doklady,
pokladnu, banku a pokrytí a rovnost deníku. Ověřuje i předpoklady, na kterých by ostrý běh spadl, zejména že
období zahájení je otevřené a že otevření nepatří uzávěrce předchozího roku.

**Jak poznáte, že je hotovo:** Zobrazí se **Kontrola nenašla žádné chyby**, **Deník je vyrovnaný** a
**Všechny doklady bez zápisu prošly doúčtováním**.

> [!TIP]
> Jakákoli změna data nebo počátečních stavů změní kontrolní otisk. Před ostrým během je pak nutné kontrolu
> zopakovat.

## 68.6 Krok za krokem: ostré doúčtování a aktivace

1. V kroku **Spuštění** zaškrtněte potvrzení **Rozumím, že se doúčtuje N dokladů a poté se zapne podvojné účetnictví**.
2. Klikněte na **Doúčtovat a aktivovat**.
3. Sledujte průběh: fázi, počet zpracovaných položek, log a závěrečný protokol. Stránka stav průběžně načítá.
4. Po skončení zkontrolujte **Protokol**.

**Jak poznáte, že je hotovo:** V protokolu stojí **Podvojné účetnictví je aktivní od** zvoleného data. Pomocí
**Otevřít deník** a **Otevřít předvahu** zkontrolujte výsledek. Tlačítkem **Skrýt z menu** můžete položku
Aktivace a doúčtování z menu odebrat.

> [!WARNING]
> Před ostrým během uložte zálohu databáze a odsouhlaste přechodovou rozvahu. Idempotence chrání před
> duplicitami, nikoli před věcně chybným počátečním zůstatkem.

## 68.7 Krok za krokem: oprava selhání a doplnění po aktivaci

**Aktivace skončila chybou:**

1. Otevřete stránku a v protokolu najděte chybnou fázi a doklady.
2. Opravte konkrétní doklad, účet, kurz, předkontaci nebo počáteční stav.
3. Spusťte znovu kontrolu a poté ostrý běh. Tlačítko **Opravit a opakovat** používá tutéž idempotentní exekuci, nejde o jiný silový režim.

**Vynucené dokončení přes zbývající chyby:** Pokud některé položky ze seznamu **K opravě** nyní opravit nejde
a aktivace je potřeba k datu zahájení, může ji správce firmy dokončit vynuceně.

1. Po neúspěšném doúčtování klikněte v protokolu na **Vynutit dokončení**. Po kontrole nanečisto, která našla
   chyby, je k dispozici **Vynutit doúčtování**: spustí ostré doúčtování a aktivaci dokončí i přes chyby položek.
2. Dialog ukáže počet chyb a seznam toho, co zůstane nedoúčtované.
3. Vyplňte **Důvod vynucení**, zaškrtněte potvrzení a akci potvrďte.

Podvojné účetnictví se zapne a protokol ukáže, kdo a kdy aktivaci vynutil a s jakým důvodem. Vynucení i důvod
se zapíšou do auditního logu. Položky ze seznamu **K opravě** opravte a doúčtujte tlačítkem **Doúčtovat
chybějící zápisy**.

> [!WARNING]
> Vynutit nejde nevyrovnaný deník, nevyrovnanou otevírací rozvahu ani běh, který skončil dřív, než prošel
> všechny zdroje (například neotevřené období zahájení). Vynucení je také vázané na otevírací rozvahu a datum
> zahájení, se kterými běh proběhl. Po jejich změně spusťte kontrolu znovu.

**Chybějící zápisy po dokončené aktivaci:** klikněte na **Doúčtovat chybějící zápisy** a potvrďte. Zaúčtují se
jen jednoznačné položky v otevřených obdobích, existující zápisy se nezdvojí.

**Chybějící otevírací rozvaha po dokončené aktivaci:**

1. V protokolu klikněte na **Doplnit otevírací rozvahu**. Kroky průvodce jsou obousměrné, na krok **Otevírací rozvaha** se dostanete i kliknutím v pruhu kroků, dokud je období zahájení otevřené.
2. Zadejte řádky a uložte.
3. Spusťte znovu kontrolu a doúčtování. Vznikne otevírací zápis; existující zápisy se díky idempotentnímu klíči nezdvojí.

**Zrušení běžící úlohy:** tlačítkem **Zrušit**. Zrušení zastaví další zpracování a vrátí aktivaci na rozpracovaný
stav. Již potvrzené transakce se hromadně nemažou. Před opakováním projděte protokol; hotové položky při
opakování nevzniknou znovu, protože každá má svůj idempotentní zdrojový klíč.

**Jak poznáte, že je hotovo:** Protokol neukazuje chyby a deník je vyrovnaný.

## 68.8 Když něco nejde

<!-- cols: 34 32 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Rozvaha je nevyrovnaná | Součet MD a Dal se liší | Doplňte protistranu nebo opravte částku. |
| Rozvaha je prázdná | Žádný řádek | Doplňte řádky, nebo potvrďte **Pokračovat bez počátečních stavů**. |
| Období zahájení není otevřené | Období je uzavírané, uzavřené nebo schválené | Otevřete ho v `Nástroje → Uzávěrka`. |
| Otevírací zápis patří uzávěrce předchozího roku | Předchozí období je uzavřené a otevření dělá jeho uzávěrka | Počáteční stavy patří do uzávěrky předchozího roku, aktivační zápis se odmítne. |
| Období už má otevírací zápis z jiného zdroje | Převzaté počáteční stavy by se zdvojily | Použijte stávající zápis. |
| Je potřeba znovu spustit kontrolu | Změnila se data nebo rozvaha | Spusťte kontrolu nanečisto znovu. |
| Úloha už běží | Aktivní může být jediná úloha firmy | Počkejte, nebo ji řízeně zrušte. |
| Zdrojová data nebo období se od náhledu změnily | Doklad, zámek nebo období se mezitím upravily | Spusťte kontrolu znovu. |
| Worker se nespustil | Služba na pozadí nenaběhla | Zkontrolujte serverový log. |
| Deník není vyrovnaný | Některá fáze skončila chybou | Najděte chybnou fázi v protokolu. Režim se nepřepnul. |
| Kontrola úplnosti hlásí chybějící doklady | Některé doklady bez zápisu doúčtování nezpracovalo | Aktivaci nelze dokončit, opravte doklady a spusťte kontrolu znovu. |
| Přeskočené doklady s důvodem „Datum je v uzamčeném období“ | Období je zamčené podaným přiznáním nebo ručním zámkem | Aktivaci to neblokuje. Doklad se zápisem si zápis ponechá; nezaúčtovaný doklad zaúčtujte po posunutí zámku. |
| Chyba v seznamu **K opravě** nejde nyní opravit | Doklad je potřeba opravit později | Správce firmy může aktivaci vynutit, viz *Vynucené dokončení přes zbývající chyby*. |

## 68.9 Podrobnosti a pravidla

### 68.9.1 Předvyplnění z přechodového můstku

Akce **Předvyplnit z daňové evidence** sestaví návrh k dni předcházejícímu zahájení:

<!-- cols: 26 74 -->
| Účet | Datový zdroj |
|---|---|
| 311 MD | Neuhrazené pohledávky z daňové evidence |
| 321 Dal | Neuhrazené závazky |
| 314 MD | Poskytnuté zálohy |
| 324 Dal | Přijaté zálohy |
| 132 MD | Zásoby z přechodového můstku |
| `211.xxx` MD/Dal | Stav zaúčtovaných pokladních dokladů, samostatný řádek za každou pokladnu |
| `221.xxx` MD/Dal | Stav bankovních transakcí, samostatný řádek za každý vlastní účet |

Do návrhu se vloží jen účty, které v osnově existují a jsou aktivní. Bankovní zůstatek respektuje vlastnictví
výpisu firmou a počítá jen CZK pohyby do rozhodného dne. Návrh nenahrazuje inventuru. Administrátor musí
doplnit ostatní aktiva, pasiva, oprávky, kapitál, daně a další zůstatky podle průkazných podkladů.

**Pokladna** se nepředvyplňuje jedním souhrnem: každý pokladní doklad patří konkrétní pokladně a ta nese svou
analytiku (**211.001**, **211.002** a další, viz kapitola *Pokladna*), tutéž, na kterou pak padají její pohyby.
Díky tomu sedí pokladní kniha každé pokladny s hlavní knihou od prvního dne účetnictví. Poznámka řádku
pokladnu pojmenuje. U valutové pokladny jde do rozvahy CZK ekvivalent dokladů, kurzem se nepřepočítává podruhé.
Doklad, u kterého pokladnu dohledat nejde, i pokladna, jejíž analytiku máte v osnově vypnutou, spadnou na
syntetiku **211** s výzvou k ručnímu rozúčtování v poznámce.

**Banka** se nepředvyplňuje jedním souhrnem: každý výpis nese číslo vlastního účtu, takže počáteční stav jde
rovnou na analytiku toho účtu (**221.100**, **221.200** a další, viz kapitola *Banka*), tutéž, na kterou pak
padají jeho pohyby. Díky tomu sedí zůstatek analytiky na výpis od prvního dne účetnictví. Výpis, u kterého
vlastní účet dohledat nejde (číslo účtu chybí v Nastavení nebo byl účet zrušen), se **nerozděluje odhadem**.
Jeho zůstatek zůstane na syntetice **221** a v poznámce řádku je výzva rozúčtovat ho ručně. Rozpad opravíte tak,
že účet doplníte v Nastavení a dáte **Předvyplnit z daňové evidence** znovu, nebo řádek 221 ručně rozepíšete
na analytiky.

### 68.9.2 Zaúčtování počátečních stavů

Ostrý běh zajistí otevřené období pro datum zahájení a vytvoří jediný zápis se zdrojem otevření. Pro řádek MD
vytvoří `účet MD / 701 Dal`, pro řádek Dal `701 MD / účet Dal`. Číslo dostane z řady otevíracích zápisů.

Pokud předchozí den patří uzavíranému, uzavřenému nebo schválenému období, otevření už vlastní uzávěrka
předchozího období a aktivační zápis se odmítne. Opakovaný běh používá stejný zdrojový klíč, takže zápis
neduplikuje.

Zamítnutá rozvaha se nevrací jako obecná chyba: aplikace pojmenuje důvod (nulová částka, účet mimo osnovu,
dvakrát tentýž účet na téže straně) a označí řádek, který ji způsobil. Průvodce na ten řádek přelistuje
a zvýrazní ho. Uložení nahrazuje celý koncept v jedné transakci a vrací kontrolní otisk normalizovaných
řádků. Tento otisk váže pozdější kontrolu nanečisto k přesné verzi počátečních stavů.

### 68.9.3 Doplnění rozvahy po dokončené aktivaci

Cestu zpět zavírá až uzavřené (uzavírané, schválené) období: pak otevření vlastní uzávěrka předchozího roku
a počáteční stavy patří do ní. Krok **Datum zahájení** se po dokončené aktivaci nezpřístupní vůbec, přepsal by
datum přechodu a vrátil stav na rozpracovaný. Změna rozvahy mění kontrolní otisk, takže ostrý běh bez nové
kontroly skončí chybou a vyžádá si ji.

### 68.9.4 Stavy aktivace

Průvodce rozlišuje stavy: aktivace nebyla zahájena, datum a počáteční stavy se připravují, ostrý běh běží,
podvojné účetnictví je aktivní, běh skončil chybou (po opravě lze spustit znovu).

### 68.9.5 Kontrola nanečisto: kritéria

Kontrola projde fáze: otevírací rozvaha, vydané a přijaté doklady, pokladna, banka, kontrola pokrytí a rovnosti
deníku. Report uvádí očekávané, zpracované, přeskočené a chybné položky, důvody přeskočení a konkrétní
problémy dokladů. U faktur se navíc porovná počet faktur bez platného zápisu s počtem, který z nich běh skutečně
zpracoval (zaúčtoval, přeskočil nebo nahlásil jako chybu). Chybějící i neočekávaný doklad je chyba úplnosti.
Faktury, které už zápis mají, průvodce jen přepisuje a do porovnání nevstupují; řádek kontroly je uvádí zvlášť.
Kontrola nanečisto naráží na zámek k datu stejně jako ostrý běh, takže doklady v zamčeném období ukáže jako
přeskočené už předem.

Úspěšná kontrola nesmí mít žádné chybné položky, musí mít úplné pokrytí a vyrovnaný deník.

### 68.9.6 Ostré doúčtování

Ostrý job běží ve workeru `api/bin/accounting-backfill-worker.php`. Web stav pravidelně načítá a zobrazuje
fázi, počet zpracovaných položek, log a závěrečný report.

**Doklady.** Doúčtování používá stejnou účetní cestu jako běžné zaúčtování. Zpracuje doklady od data zahájení,
které podle stavů mají patřit do deníku, a využije jejich položky, sazby DPH, měnu a předkontace. Již
existující idempotentní zápis aktualizuje nebo přeskočí; nevytváří druhý předpis. Po bankovní fázi následuje
ještě zúčtování záloh. Je záměrně až za platbami, protože převod proformy na finální doklad musí znát
skutečné spárování.

**Pokladna.** Bere zaúčtované pokladní doklady bez deníku. Kontrola nanečisto používá čistý náhled řádků,
ostrý běh stejnou službu jako nový doklad. Zdrojový identifikátor zajistí idempotenci.

**Banka.** Bankovní fáze nejdřív zpracuje párování dokladů. Volitelné firemní pravidlo smí při historii vytvořit
jen **návrh**; automatický režim se během doúčtování degraduje na návrh. Již zaúčtovaný pohyb se nepřepíše.

Po každé fázi worker kontroluje požadavek na zrušení. Zrušení zastaví další zpracování a vrátí aktivační stav
na rozpracovaný; již potvrzené transakce se automaticky hromadně nemažou.

### 68.9.7 Dokončení

Po všech fázích se znovu sečtou všechny řádky deníku firmy v haléřích. Pokud MD ≠ Dal nebo report obsahuje
chyby, job skončí jako selhaný a režim se neaktivuje. Teprve úspěšný ostrý běh v jedné závěrečné transakci
přepne firmu do podvojného účetnictví, uloží dokončený stav, zapíše historii účetního režimu a auditní událost
a označí job jako dokončený.

Vynucené dokončení prochází touž závěrečnou transakcí. Před ní se znovu sečte deník; nevyrovnaný deník
vynucení odmítne. Report jobu dostane záznam o vynucení (kdo, kdy, důvod a počet zbývajících chyb) a historie
úloh ho označí jako **vynuceně** dokončený.

### 68.9.8 Historie úloh, zámky a souběh

Historie je stránkovaná a uchovává druh běhu, stav, fázi, parametry, report, log, poslední chybu a časy. Aktivní
může být nejvýše jedna úloha firmy; unikátní databázová podmínka chrání i dva souběžné požadavky.

Aplikace označí opuštěnou úlohu jako selhanou, pokud worker přestal aktualizovat stav. Požadavek **Zrušit**
nastaví příznak, který worker čte mezi dávkami. Pokud se worker nepodaří vůbec spustit, job i aktivace se
označí jako selhané a API vrátí chybu.

Zámek účtování k datu se během aktivace neobchází. Pokud datum doúčtování leží v zamčené části, účetní služba
zápis odmítne. Stejně se respektuje otevřenost období a aktivita účtů.

### 68.9.9 Oprávnění a chybové kódy

Stav může číst uživatel s oprávněním k firemnímu nastavení. Zahájení, počáteční stavy, úlohy a zrušení vyžadují
administrátorské oprávnění ke správě účetních období (zápis). Vynutit dokončení smí jen správce firmy,
delegovaná role se správou účetních období ne. Vše je omezené na aktuální firmu a auditované.

Chyby v protokolu a v logu mají tyto kódy:

<!-- cols: 36 64 -->
| Kód | Význam |
|---|---|
| `opening_unbalanced` | Rozvaha je nevyrovnaná; doplňte protistranu nebo opravte částku |
| `opening_empty` | Otevírací rozvaha neobsahuje žádný řádek |
| `period_not_open` | Období zahájení není otevřené |
| `opening_owned_by_closing` | Otevírací zápis patří uzávěrce předchozího roku |
| `opening_already_posted` | Období už má otevírací zápis z jiného zdroje (převzaté počáteční stavy) |
| `dry_run_required` | Změnila se data; spusťte kontrolu znovu |
| `job_already_running` | Počkejte na aktivní úlohu nebo ji řízeně zrušte |
| `remaining/period/lock` | Zdrojová data či období se od náhledu změnily |
| `worker_start_failed` | Worker se nespustil; zkontrolujte serverový log |
| `force_confirmation_required` | Vynucení nebylo výslovně potvrzeno |
| `force_reason_required` | U vynucení chybí důvod |
| `activation_force_fatal` | Běh skončil dřív, než prošel všechny zdroje; vynutit ho nejde |
| `activation_force_wrong_state` | Vynutit lze jen poslední neúspěšné doúčtování |
| `journal_unbalanced` | Deník není vyrovnaný; aktivaci nejde dokončit ani vynutit |

## 68.10 Související kapitoly

- [Účtový rozvrh](66_Ucetni_osnova.md)
- [Inventarizace účtů](69_Inventarizace_rozvahovych_uctu.md)
- [Uzávěrka](72_Uzaverka.md)
