# 29. Bankovní účty a e-mailová avíza (IMAP)

**Cesta: `Peníze → Bankovní účty`**

Stránka sdružuje záložku **Bankovní výpisy** (import GPC a párování plateb —
viz [28. Banka](28_Banka.md)), dvě záložky účetní automatiky — **K zaúčtování**
a **Pravidla účtování** (viz [§ 29.8](#298-automaticke-zauctovani-bankovnich-transakci-jen-podvojne-ucetnictvi),
vidí je jen dodavatel s **podvojným účetnictvím**) — a tři administrátorské
záložky: **Měny a účty**, **Stavy na účtech** a **Bankovní avíza z e-mailu**.

Tato kapitola popisuje správu **bankovních účtů dodavatele** (pro PDF faktury,
QR platby a GPC výpisy) a **bankovních e-mailových avíz přes IMAP**. Bankovní avízo je
e-mail od banky s údaji o platbě — MyÚčto ho umí pravidelně načítat, vytěžit
z něj VS, částku, měnu, datum a vlastní účet a vytvořit z něj bankovní transakci
stejně jako z [výpisu](28_Banka.md).

## 29.1 Bankovní účty

Sekce **Měny + bankovní účty** je čistý seznam účtů dodavatele. Účet zde
nastavuješ stejně jako pro PDF faktury, QR platby a GPC výpisy:

- měna a označení účtu,
- české číslo účtu + kód banky,
- případně IBAN/BIC,
- výchozí účet pro danou měnu,
- aktivní/neaktivní stav.

Nastavení bankovních avíz je oddělené níže, aby se běžné bankovní údaje
nemíchaly s parsery a IMAP účty.

### 29.1.1 Stavy na účtech

Záložka **Stavy na účtech** zobrazuje každý bankovní účet samostatně podle
čísla účtu, kódu banky a měny. Aktuální stav vychází z posledního oficiálního
GPC nebo PDF výpisu; pokud je dostupné novější e-mailové avízo s disponibilním
zůstatkem, použije se novější údaj.

Pod tabulkou je pro každý účet samostatný graf měsíčních konečných zůstatků
v jeho vlastní měně. Graf **Celkový vývoj v CZK** zobrazuje jednotlivé účty
i řadu **Celkem**. Cizoměnové účty se pro tento graf přepočítávají kurzem ČNB
ke konci příslušného měsíce.

Pokud mají dva účty stejné číslo před lomítkem, rozlišují se kódem banky.
Starý výpis bez uloženého kódu banky se při více možných bankách do zůstatku
nezapočte, protože jej nelze bezpečně přiřadit.

### 29.1.2 Kontace účtů — analytika 221 (jen podvojné účetnictví)

Záložka **Kontace účtů** drží účetní pohled na tytéž účty. **Každý bankovní
účet má vlastní analytiku syntetického účtu 221** — 221.100, 221.200, 221.300 …
(tečkovaný zápis, viz [§ 81.3.1](81_Ucetni_osnova.md#8131-teckovany-zapis-analytik))
Číslo se přiděluje **automaticky** (první volné, které v
[účtovém rozvrhu](81_Ucetni_osnova.md) nekoliduje s už existujícím účtem) a
analytika se zároveň založí v rozvrhu pod 221.

Proč to tak je:

- **zůstatek analytiky sedí na výpis** konkrétního účtu — na ploché 221 leží
  několik reálných účtů najednou a zůstatek pak neodpovídá ničemu,
- **inventarizace k rozvahovému dni** (§ 29–30 zákona o účetnictví) se dá doložit
  výpisem daného účtu,
- **cizoměnové účty se přeceňují automaticky** — jednoměnová analytika už
  nemíchá měny, takže ji [uzávěrkové přecenění](87_Uzaverka.md) nabídne samo,
- **převod mezi vlastními účty** je v deníku vidět (obě nohy jsou různé účty).

V tabulce u každého účtu nastavíš:

| Pole | Význam |
|---|---|
| Název | Vlastní pojmenování účtu (použije se i jako název analytiky v rozvrhu) |
| Druh | Běžný účet / spořicí účet / termínovaný vklad |
| Analytika | Číslo za tečkou — vedle se hned ukáže výsledný účet (např. `221.200`) |
| Aktivní | Neaktivní účet se do kontací nenabízí, ale své číslo si drží |

Číslo můžeš **přepsat** — typicky když už některý účet v rozvrhu vedeš pod
konkrétní analytikou a chceš na ni bankovní účet **namapovat** (např.
termínovaný vklad na 221.100). Analytiku, kterou už používá jiný účet,
systém odmítne, a jednou přidělenou analytiku **nelze zrušit** — jde ji jen
změnit na jiné číslo. Analytika, na které už leží účetní zápisy, se sama
nikdy nepřidělí, aby nový účet nezdědil cizí zůstatek.

> [!NOTE]
> Zápisy zaúčtované dřív, než účet analytiku dostal, zůstávají na syntetice 221.
> Jejich přesun je **účetní reklasifikace k datu** — účtuje se ručně jako interní
> doklad (`221.xxx` / 221) v **otevřeném** období; do uzavřených a schválených let se
> nezasahuje a rozvahový řádek „Peněžní prostředky na účtech" se rozpadem uvnitř
> 221 stejně nemění.

## 29.2 Mapování bankovních avíz

Sekce **Mapování bankovních avíz** určuje, jak se vytěžený e-mail napojí na
konkrétní bankovní účet dodavatele.

| Sloupec | Význam |
|---|---|
| Bankovní účet | Účet z měn dodavatele, proti kterému se porovnává cílový účet v e-mailu |
| IMAP účet | Konkrétní schránka, ze které se má avízo pro tento účet brát; „Žádný IMAP účet" = výchozí stav bez skenování, „Všechny IMAP účty" = neomezeno |
| Parser | Konkrétní parser provider; „Automatický výběr" = systém zkusí všechny aktivní providery |
| Tolerance | Povolená odchylka částky při párování faktury, např. `1.00` pro ±1 Kč |
| Aktivní | Vypnutý řádek se při scanování nepoužije |

Mapování se vyhodnocuje až po úspěšném vytěžení e-mailu. Pokud e-mail přijde
z jiného IMAP účtu nebo ho zpracoval jiný parser, než je v mapování nastaveno,
řádek se nepoužije.

Nové nebo nenastavené mapování začíná volbou **Žádný IMAP účet**. Takový řádek
se při scanování nepoužije, dokud nezvolíš konkrétní IMAP účet nebo vědomě
nepovolíš variantu **Všechny IMAP účty**.

## 29.3 IMAP účty pro bankovní avíza

Každý dodavatel může mít více IMAP účtů, typicky jeden pro každou banku.

| Pole | Význam |
|---|---|
| Název | Popisek v UI, např. „RB avíza" |
| Host / port / šifrování | Připojení k IMAP serveru |
| Uživatel / heslo | Přístup ke schránce; heslo se ukládá šifrovaně |
| Složka | IMAP složka, např. `INBOX` nebo `INBOX.Banka` |
| Procházet | Ověří připojení a nabídne složky ze serveru |
| Max. zpráv na běh | Kolik nejnovějších e-mailů cron načte při jednom běhu |
| Zpracovat od data | Starší e-maily se ignorují i když spadnou do limitu |
| Vyžadovat ověření autenticity | Zpracují se jen e-maily, u kterých přijímací server potvrdil DKIM/DMARC; **zapnuto** |
| Důvěryhodné authserv-id | Volitelné připnutí serveru, jehož verdiktu se věří (např. `mx.mojedomena.cz`) |
| Přijímat přeposlaná (FW) avíza | Rozpozná banku i z těla e-mailu, když avíza chodí do schránky přeposlaná (odesílatel je tvoje adresa, ne banka) |
| E-mail přeposílatele | Volitelné omezení, od koho smí přeposlaná avíza chodit — adresa (`jan@firma.cz`) nebo doména (`firma.cz`); prázdné = libovolný |
| Po úspěchu | Co udělat se zpracovanou zprávou |

### Ověření autenticity e-mailu (DKIM/DMARC)

Odesílatel e-mailu se dá podvrhnout, takže samotná adresa v poli *Od* nic
negarantuje. **Vyžadovat ověření autenticity** je proto u nových účtů zapnuté:
systém sám podpisy nepřepočítává, ale věří verdiktu, který k doručené zprávě
připsal tvůj přijímací server do hlavičky `Authentication-Results`. Zpracuje se
jen e-mail s `dmarc=pass`, nebo s `dkim=pass` a doménou zarovnanou na odesílatele.

**Chybějící hlavička je odmítnutí, ne výjimka.** Když zprávě hlavička chybí nebo
verdikt nesedí, avízo se nezpracuje a v přehledu zpracovaných zpráv skončí ve
stavu `security_rejected` s uvedeným důvodem. Pokud tvůj poštovní server
hlavičku `Authentication-Results` vůbec nepřidává, kontrolu u daného účtu vypni —
je to ale vědomé snížení ochrany, po kterém stačí k označení faktury za zaplacenou
jediný podvržený e-mail.

Hlavičku `Authentication-Results` si umí do těla zprávy vložit kdokoli. Důvěryhodná
je jen ta, kterou přidal tvůj server. Když jich v cestě je víc, připni v poli
**Důvěryhodné authserv-id** název svého serveru — pak se hodnotí právě jeho řádek.
U přeposlaných avíz platí, že přeposláním původní podpis banky zaniká, takže se
ověření vztahuje na přeposílatele, ne na banku.

Pokud do schránky chodí avíza **přeposlaná** (např. z firemní schránky na sběrnou
adresu), zapni **Přijímat přeposlaná (FW) avíza**. U přímého avíza poznává banku
podle odesílatele, ale přeposláním se odesílatelem stáváš ty — proto se pak banka
hledá i z těla e-mailu. Volitelně omez **E-mail přeposílatele**, ať se zpracují
jen avíza od tvé adresy.

Polling zprávy standardně **neoznačuje jako přečtené**. Systém si úspěšně
zpracované e-maily pamatuje v databázi podle `Message-ID` / UID / fallback
hashe, takže funguje i s účtem, kde aplikace nemůže zprávy přesouvat nebo
označovat. Pokud má účet zápis povolený, můžeš zvolit doplňkovou akci po
úspěchu: neměnit zprávu, přidat flag, přesunout do jiné složky nebo označit
jako přečtené.

## 29.4 Parser provideri

Provider říká, jak poznat e-mail dané banky a jak z něj vytěžit platební údaje.

Typy providerů:

- **Systémový provider** — dodaný aplikací, např. Raiffeisenbank, UniCredit Bank, ČSOB, Česká spořitelna, Fio banka, Banka CREDITAS, MONETA Money Bank nebo Air Bank.
- **Regex provider** — vlastní provider dodavatele, konfigurovaný v UI.

Předpřipravený společný provider **Česká spořitelna** je zapnutý a má vyplněný
whitelist odesílatelů (`csas.cz`) — stejně jako banky s vlastním parserem, které si
odesílatele ověřují v kódu. Dřív byl dodáván s prázdným whitelistem, a protože
prázdný whitelist nepustí nic (viz *Odesílatel je povinný* níže), nedokázal
naparsovat vůbec žádné avízo; kdo na to narazil, musel si udělat vlastní kopii.
Posílá-li tvoje ČS avíza z jiné adresy, klikni na **Duplikovat**, v kopii adresu
uprav a přepni na ni mapování účtu.

Systémový provider se přímo needituje (je společný pro všechny). Když ho chceš
upravit, použij u něj tlačítko **Duplikovat** — vytvoří se editovatelná kopie,
ve které si dolaď vzory a otestuj ji přes **Test parseru**. V mapování účtu pak
přepneš účet z původního providera na svou kopii. Duplikovat lze i vlastní regex
provider.

Tlačítko **Vypnout pro firmu** u společného provideru platí **jen pro tvoji firmu**
a ostatních se nedotkne. Hodí se, když nechceš, aby se společný provider vůbec
pokoušel tvoje e-maily zpracovat; stejným tlačítkem ho zapneš zpátky. Vzory se
u společného provideru měnit nedají — pokus o to skončí hláškou s odkazem na
**Duplikovat**.

Systémový provider Raiffeisenbank rozlišuje směr převodu podle úvodního textu
o příchozí nebo odchozí platbě; u starší či odlišné šablony použije jako záložní
údaj znaménko částky. U odchozí úhrady je vlastním účtem pole **Z účtu** a
protiúčtem pole **Na účet**; u příchozí úhrady je to opačně. Díky tomu se odchozí
avízo mapuje na účet, ze kterého byla platba skutečně odepsána. U karetní
transakce se vlastní účet načte z pole **Účet** a obchodník z pole **Detaily**;
chybějící variabilní symbol ani bankovní protiúčet importu nebrání.

U Air Bank se avíza zapínají v internetovém bankovnictví pod **Účty a karty →
Možnosti → Info o dění na účtu** (odesílatel `info@airbank.cz`, předměty
„Zvýšení/Snížení zůstatku“). Nastavení v IB není úplně intuitivní — praktický
postup je např. v návodu FAPI
[Nastavení zasílání e-mailů o příchozích platbách z Air Bank](https://napoveda.fapi.cz/article/40-nastaveni-zasilani-e-mailu-o-prichozich-platbach-z-air-bank)
(místo FAPI adresy uveď mailbox napojený v MyÚčtu).

Detekce e-mailu i vytěžení polí pracují **tolerantně k diakritice**: pokud avízo
dorazí v jiném kódování nebo s rozbitou diakritikou (typicky u přeposlaných
zpráv), vzory `Směr platby` a `Smer platby` se vyhodnotí stejně. Když přesto
nějaký provider zlobí, můžeš si vzory napsat rovnou bez diakritiky.

U regex provideru nastavuješ:

| Pole | Význam |
|---|---|
| Název / kód | Interní identifikace provideru |
| Odesílatel | **Povinný** whitelist odesílatelů, např. `info@rb.cz` |
| Regex předmětu | Volitelný pattern pro subject, např. `Pohyb\s+na\s+účtě` |
| Regex těla | Volitelný pattern, který musí být v těle e-mailu |
| Vytěžená pole | Regexy pro VS, částku, měnu, datum, cílový účet atd. |

### Odesílatel je povinný

Pole **Odesílatel** vyplň vždy. Regex provider s prázdným odesílatelem
**nezpracuje nic** — prázdná hodnota neznamená „přijmout od kohokoli". Vzory
předmětu a těla samy o sobě nechrání: text avíza si dokáže napsat kdokoli a čísla
účtu i variabilní symbol jsou vytištěné na každé vydané faktuře, takže bez
whitelistu by stačil jeden podvržený e-mail do sledované schránky k označení
faktury za zaplacenou.

Whitelist může obsahovat víc položek oddělených mezerou, čárkou nebo středníkem
a rozlišuje dva tvary:

- **adresa** (`info@rb.cz`) — musí sedět přesně; funguje i tvar `Název <info@rb.cz>`,
- **doména** (`rb.cz`) — projde libovolná adresa v této doméně i v jejích
  subdoménách (`noreply@mail.rb.cz`), ale ne `info@rb.cz.podvod.example`.

Doménový tvar použij u bank, které rozesílají avíza z několika adres. Odesílatel
je ale jen první filtr — skutečnou ochranu dělá **Vyžadovat ověření autenticity**
u IMAP účtu a povinné mapování cílového účtu avíza na tvůj bankovní účet.

Povinná vytěžená pole:

- `variable_symbol`
- `amount`
- `currency`
- `posted_at`
- `recipient_account`

Volitelná pole:

- `counterparty_account`
- `counterparty_name`
- `constant_symbol`
- `message`
- `bank_ref`
- `balance` (disponibilní zůstatek účtu z avíza — zobrazí se v detailu
  měsíčního avízo-výpisu a promítne se do přehledu **Stavy na účtech**)

Regex parser používá první zachycenou skupinu nebo pojmenovanou skupinu se
stejným názvem jako pole. Pro částku umí formáty typu `+1.234,56`, datum např.
`01. 06. 2026 10:15`.

## 29.5 Příklad regex provideru pro Raiffeisenbank

Následující příklad je **anonymizovaný**. Čísla účtů, variabilní symbol, název
protistrany i zpráva jsou fiktivní. Do manuálu nikdy nedávej reálné e-maily
z banky s osobními údaji, zůstatky nebo skutečnými čísly účtů.

Testovací text e-mailu může vypadat např. takto:

```text
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Firma Test s.r.o.
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo s.r.o.
Variabilní symbol
2606001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2606001
Disponibilní zůstatek po pohybu
+99.999,99 CZK
```

Základní nastavení provideru:

| Pole | Hodnota |
|---|---|
| Název | `Raiffeisenbank regex test` |
| Kód | `raiffeisenbank_regex` |
| Aktivní provider | Ano |
| Odesílatel | `info@rb.cz` |
| Regex předmětu | viz níže |
| Regex těla | `Variabilní\s+symbol` |
| Normalizer config | `{}` |

Regex předmětu:

```text
Pohyb\s+na\s+účtě|Pohyb\s+na\s+ucte
```

Regexy pro vytěžená pole:

| Pole | Regex |
|---|---|
| Datum platby | `Datum\s+a\s+čas\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2})` |
| Cílový účet | `Na\s+účet\s*([0-9-]+/[0-9]{4})` |
| Částka | `Částka\s+v\s+měně\s+účtu\s*([+\-]?[0-9 .]+,[0-9]{2})\s*[A-Z]{3}` |
| Měna | `Částka\s+v\s+měně\s+účtu\s*[+\-]?[0-9 .]+,[0-9]{2}\s*([A-Z]{3})` |
| Protiúčet | `Z\s+účtu\s*([0-9-]+/[0-9]{4})` |
| Název protistrany | `Z\s+účtu\s*[0-9-]+/[0-9]{4}\s*([^\n]+?)\s*Variabilní\s+symbol` |
| Variabilní symbol | `Variabilní\s+symbol\s*([0-9]+)` |
| Konstantní symbol | `Konstantní\s+symbol\s*([0-9]+)` |
| Zpráva | `Zpráva\s+pro\s+příjemce\s*(.*?)\s*Disponibilní\s+zůstatek` |
| Reference banky | prázdné |
| Disponibilní zůstatek | `Disponibilní\s+zůstatek(?:\s+po\s+pohybu)?\s*([+\-]?[0-9 .]+,[0-9]{2})` |

> 🛈 Do UI zadávej regex bez krajních oddělovačů (`/.../`). Parser je doplní
> sám.

## 29.6 Test parseru a zpracované e-maily

V sekci **Parser provideri** můžeš vložit testovací e-mail, odesílatele a
předmět. Test ukáže, který provider se použil a jaká pole se vytěžila.

Sekce **Zpracované e-maily** je debug přehled:

- zobrazuje `Message-ID` / fallback hash,
- IMAP účet,
- datum a čas zpracování,
- stav zpracování,
- použitý provider,
- vytěžené platební údaje,
- navázanou transakci nebo fakturu.

Hlavní stav se průběžně odvozuje z aktuálního párování transakce. Pokud byla
platba úspěšně spárovaná, případná chyba následného přesunu nebo označení
e-mailu v IMAP už nezobrazuje párování jako neúspěšné; původní post-processing
chyba zůstane viditelná jako upozornění.

Smazání záznamu zde nemaže transakci ani fakturu. Maže jen deduplikační záznam,
takže je možné stejný e-mail znovu zpracovat při dalším scanu. Používej to jen
jako emergency/debug akci.

### Když k avízu dorazí GPC výpis

Zdroj pravdy je GPC. Když se importuje transakce, která už předtím přišla
avízem a je spárovaná, aplikace **párování převezme z avíza na GPC transakci**
— platba se přepojí, avízo zůstane rozpárované a nevznikne dvojí započtení.
Je to důležité i účetně: **avízo se nikdy neúčtuje**, takže dokud platba visí
na něm, nedostane se platební noha (u cizoměnové faktury včetně kurzového
rozdílu) do deníku vůbec.

Převzetí je záměrně opatrné — proběhne jen při **právě jednom** kandidátovi se
shodou účtu, měny, částky na haléř a data v okně ±5 dní. Identita se hledá
takto:

| Situace | Podmínka převzetí |
|---------|-------------------|
| GPC má variabilní symbol | VS musí číselně sedět s VS avíza |
| GPC nemá VS, ale avízo nese VS nebo protiúčet | musí sedět protiúčet |
| Ani jedna strana nemá VS ani protiúčet (karetní platba, „Blokace") | stačí výše uvedená shoda účtu, měny, částky a data |

Poslední řádek pokrývá karetní úhrady, které VS ani protiúčet nenesou. Pokud
by avízo bylo bez identity, ale GPC protistranu znal, jde nejspíš o běžný
převod a k převzetí nedojde. Dvě stejné blokace ve stejném okně jsou
nejednoznačné — aplikace nechá párování na tobě.

> 🛈 Karetní **blokace** se může od finálně zúčtované částky lišit. Pak se
> částky neshodují, převzetí neproběhne a platbu je potřeba přepárovat ručně.

## 29.7 Cron pro e-mailová avíza

Pro automatické zpracování nastav samostatný cron:

```bash
cmd/cron-bank-email-notices.sh   # každých 30 minut
```

Skript spustí `php api/bin/cron-bank-email-notices.php`, projde aktivní IMAP
účty dodavatele, načte nejnovější zprávy podle limitu a zapíše heartbeat do
plánovaných úloh.

## 29.8 Automatické zaúčtování bankovních transakcí (jen podvojné účetnictví)

Bankovní výpis ([kapitola 28](28_Banka.md)) i e-mailové avízo (§ 29.2–29.7) řeší jen **párování na
faktury**. Transakce, které s fakturou nesouvisí — bankovní poplatky, úroky,
odvody sociálního a zdravotního pojištění, splátky leasingu, převody mezi
vlastními účty — potřebují vlastní **účetní zápis** (MD/D dle [Předkontace](88_Ucetni_nastroje.md#883-predkontace)).
O to se stará **PostingService** a dvě záložky na téže stránce **Peníze →
Bankovní účty**, viditelné jen dodavateli s **podvojným účetnictvím**:

- **K zaúčtování** — fronta návrhů zápisů čekajících na schválení.
- **Pravidla účtování** — naučená pravidla, podle kterých se opakující se
  platby (odvody, poplatky, úroky) rozpoznají a zaúčtují samy.

### 29.8.1 Pravidla účtování

Tlačítkem **Nové pravidlo** (nebo přímo z platby přes hint „Podobná platba se
opakuje" — viz § 29.8.4) založíš pravidlo:

| Pole | Význam |
|---|---|
| Název | Popisek pravidla v seznamu |
| Směr | **Příchozí** / **Odchozí** — platba na účet dodavatele, nebo z něj |
| Protiúčet + kód banky | Číslo protiúčtu, na které/z kterého platba chodí |
| Variabilní symbol | Přesná shoda VS (číslice) |
| Fragment zprávy | Podřetězec v popisu/zprávě transakce (bez ohledu na velikost písmen a diakritiku) |
| Rozsah částky (od–do) | Interval absolutní částky, ve kterém se pravidlo použije |
| MD účet / D účet | Kontace zápisu — účty musí existovat v [účtovém rozvrhu](81_Ucetni_osnova.md) |
| Režim | **Návrh**, nebo **Automaticky** (§ 29.8.2) |

Pravidlo musí mít **aspoň jedno kritérium** (protiúčet, VS nebo fragment zprávy)
— samotný rozsah částky nestačí. Kontace má dvě záměrná omezení:

- **Bankovní strana musí být účet 221** (dle směru — příchozí = MD 221,
  odchozí = D 221). Pravidlo vždy účtuje proti bankovnímu účtu. Zadává se
  syntetika **221**; při zaúčtování ji MyÚčto samo nahradí **analytikou účtu,
  ze kterého je výpis** ([§ 29.1.2](#2912-kontace-uctu-analytika-221-jen-podvojne-ucetnictvi)),
  takže jedno pravidlo funguje pro všechny bankovní účty firmy.
- **Druhá strana nesmí být saldokontní účet** (311, 321, 314, 324, 325) —
  platby faktur se **párují** (§ 24.4), ne účtují pravidlem.

> [!TIP]
> Tlačítkem **Otestovat na historii** ověříš návrh pravidla proti transakcím
> za posledních 12 měsíců, ještě než ho uložíš — uvidíš, kolika transakcím
> odpovídá a kolik z nich je už zaúčtovaných. Pokud test najde nezaúčtované
> historické shody, nabídne se zaškrtávátko **„Navrhnout zaúčtování N
> historických plateb"**, které pro ně rovnou založí návrhy k schválení.

### 29.8.2 Režim Návrh vs. Automaticky

Nové pravidlo je vždy v režimu **Návrh** — každá shoda jen vytvoří položku v
záložce **K zaúčtování**, kterou musíš ručně **Schválit** nebo **Odmítnout**.
Po pěti potvrzeních za sebou beze změny, bez odmítnutí a s vyplněným rozsahem
částky se u pravidla objeví **Povýšit na automatiku**. Režim se nikdy nepřepne
sám — povýšení vždy potvrdí člověk. V automatickém režimu se zápis do deníku
vytvoří **rovnou při shodě**, bez čekání na schválení. Tlačítko **Historie**
ukazuje potvrzení, korekce, povýšení i případný návrat pravidla na návrhy.

Pokud transakci odpovídá **víc aktivních pravidel najednou**, systém nikdy
neúčtuje automaticky — vytvoří jen návrh podle pravidla s nejvyšší
úspěšností a označí ho jako konfliktní. Když se u transakce **nenajde žádné
pravidlo**, zkusí ještě rozpoznat vzor podle **historie** — pokud se stejný
protiúčet a směr v minulosti opakovaně účtovaly na stejnou dvojici účtů,
nabídne se návrh označený jako „naučeno", i bez existujícího pravidla.

Automatické zaúčtování se **nikdy neprovede do uzavřeného účetního období** —
místo zápisu vznikne jen návrh s poznámkou o uzavřeném období, který doplníš
ručně po otevření období.

### 29.8.3 Schvalování návrhů a záložka „K zaúčtování"

Záložka **K zaúčtování** má čtyři podzáložky — **K zaúčtování** (čekající),
**Zaúčtováno automaticky**, **Schválené** a **Odmítnuté**. U čekajícího návrhu
vidíš datum, částku, protistranu, navrhované pravidlo a **kontaci** (MD/D):

- **Schválit** — vytvoří zápis do deníku (viz [Předkontace](88_Ucetni_nastroje.md#883-predkontace)
  pro logiku sestavení zápisu) a návrh přejde do stavu Schváleno.
- **Odmítnout** — návrh se zahodí; ke stejné transakci a pravidlu se už
  znovu nenabídne. **Tři odmítnutí stejného pravidla po sobě** ho automaticky
  **deaktivují** (na to upozorní hláška) — pravidlo zjevně přestalo sedět.
- Ikonou ozubeného kola u řádku **přepíšeš MD/D účty** ještě před schválením,
  aniž bys musel(a) upravovat pravidlo.
- Upravená kontace se uloží jako učicí signál. Další naučený návrh u stejného
  protějšku ukáže, kdy a z jakých účtů byla kontace změněna.
- Víc řádků najednou vyřídíš přes **hromadné Schválit vybrané**.

Transakce v **cizí měně** se automaticky ani návrhem neúčtují (chybí
řešení kurzových rozdílů) — takové řádky nesou štítek **Cizí měna** a řeší se
ručně v [Předkontace](88_Ucetni_nastroje.md#883-predkontace).

U už **zaúčtovaných** položek (podzáložka **Zaúčtováno automaticky**) vidíš
odkaz na zápis v deníku a tlačítko **Stornovat** — vytvoří opravný (storno)
zápis a transakci vrátí mezi nezaúčtované, aniž by se mazala historie.
Pokud zápis vytvořilo automatické pravidlo, storno ho zároveň vrátí do režimu
**Návrh**. Nezvyšuje tím počet odmítnutí.

### 29.8.4 Založení pravidla přímo z platby

Když se v posledním roce objeví **podobná platba víckrát** (stejný protiúčet
nebo VS) a ještě pro ni neexistuje pravidlo, nabídne se u transakce ve výpisu
(§ 24) hláška **„Podobná platba se opakuje (N× za poslední rok)"** s
tlačítkem **Vytvořit pravidlo** — otevře formulář pravidla předvyplněný podle
dané transakce (protiúčet, VS, fragment zprávy, rozsah částky ±10 % okolo
částky) i s vybranou kontací, kterou stačí zkontrolovat a uložit.

> [!WARNING]
> Kontaci volí uživatel — systém nikdy nedosadí účty sám bez potvrzení.
> Pravidlo, které nesedí, radši nastav na **Návrh** a chvíli sleduj v záložce
> **K zaúčtování**, než ho přepneš na **Automaticky**.
