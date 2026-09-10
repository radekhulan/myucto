# Platební karty

Sekce **Peníze → Platební karty** vede firemní platební karty a jejich držitele.
Z bankovních výpisů pak aplikace pozná, kterou kartou se platilo, spáruje
platbu s účtenkou nebo fakturou téže karty a ukáže, ke kterým platbám kartou
ještě chybí doklad.

## Co se o kartě eviduje

Aplikace **nikdy neukládá celé číslo karty**. Stačí poslední čtyři číslice
(koncovka), podle nich se karta pozná ve výpisu. Vložíte-li do pole koncovky
celé číslo, uloží se jen poslední čtyři číslice a aplikace vás na to upozorní.
Pole Název, Jméno držitele a Poznámka celé číslo karty odmítnou.

| Pole | Význam |
|---|---|
| Název karty | vaše pojmenování, např. „Tankovací karta obchod" |
| Poslední 4 číslice | koncovka z maskovaného čísla karty |
| Typ karty | debetní, kreditní, předplacená, palivová, jiná |
| Karetní asociace | Visa, Mastercard, Maestro, American Express, jiná (nepovinné) |
| Bankovní účet | měnový účet firmy, ze kterého se platby kartou strhávají |
| Držitel | jméno držitele, případně zaměstnanec nebo uživatel aplikace |
| Platnost od–do | období, kdy karta platí; prázdné = bez omezení |
| Aktivní karta | neaktivní karta zůstává v evidenci, jen se nepoužívá |

Držitel se zobrazuje u bankovních pohybů a v přehledu plateb bez dokladu.
Pokud vyberete zaměstnance nebo uživatele, musí patřit do stejné firmy.
Smažete-li zaměstnance, karta zůstane a vazba na něj se zruší; jméno držitele
zůstává. Totéž platí pro vozidlo, jehož byl řidičem. Kartu, jejíž uložený
držitel už do firmy nepatří, jde dál upravovat — vazba se při uložení uvolní.

### Platnost a archivace

Dvě karty se stejnou koncovkou nesmí u jedné firmy platit ve stejném období,
jinak by nešlo určit, čí platba to byla. Novou kartu se stejnou koncovkou
proto založte s platností navazující na tu původní.

Kartu, která se už nepoužívá, **archivujte** (akce Archivovat v detailu karty).
Archivace kartu nemaže: platby z minulosti jí zůstanou přiřazené. Pokud karta
neměla vyplněnou platnost do, doplní se dnešním datem. Archivovanou kartu
zobrazíte zaškrtnutím „Zobrazit archivované" a můžete ji obnovit.

## Koncovka karty v bankovních pohybech

Při importu výpisu se z maskovaného čísla karty (například `PK: 000000******1234`)
uloží koncovka k pohybu. Funguje to pro GPC výpisy (doplňující řádky 078/079),
PDF výpisy CREDITAS a KB, bankovní API a e-mailová avíza, pokud maskované číslo
obsahují. Jméno obchodníka se u karetních plateb doplní do protistrany.
Maskovaný IBAN nebo číslo účtu (`CZ** **** … 1234`, `******1234/0100`) se za
kartu nepovažuje.

V detailu výpisu se u platby kartou zobrazí koncovka, a je-li karta
v evidenci, i její držitel nebo název.

Pohyby importované dřív se doplní samy při aktualizaci aplikace (krok
auto-backfill příkazu `php api/bin/migrate.php`). Ručně lze doplnění spustit
příkazem `php api/bin/backfill-card-last4.php --apply`.

## Párování plateb kartou

Platba kartou se páruje s přijatou fakturou nebo účtenkou podle koncovky karty,
částky a data:

- **Doklad se stejnou koncovkou** a sedící částkou se spáruje automaticky.
  Doklad přitom musí být vystavený nejvýš 7 dní před zaúčtováním platby
  v bance a nejvýš 2 dny po něm.
- **Doklad placený kartou bez uvedené koncovky** je jen návrh ke kontrole,
  a to pouze tehdy, když si ho nemůže nárokovat platba jiné karty se stejnou
  částkou.
- **Doklad jiné karty se nespáruje nikdy**, ani podle shody částky a data. Dvě
  stejné platby dvěma kartami téhož dne se proto nespárují křížem.
- Dvě stejné platby toutéž kartou k jednomu dokladu jdou ke kontrole, aplikace
  nehádá, která z nich to byla.

Koncovku nese doklad vzniklý nahráním účtenky z přehledu plateb bez dokladu
(viz níže), účtenka z AI importu a doklad, ke kterému se připojil sken účtenky
s koncovkou karty.

Doklad nemusí být v aplikaci dřív než platba. Když přijde později, spáruje se
s platbou své karty sám, jakmile je přijatý: po AI importu účtenky, kterou import
rovnou označil jako zaplacenou, po připojení skenu s koncovkou karty a po přijetí
konceptu dokladu. Nezaúčtovaný doklad se přitom zaúčtuje, má-li firma zapnuté
automatické účtování přijatých dokladů.

## Platby kartou bez dokladu

Záložka **Platby bez dokladu** ukazuje odchozí platby kartou za zvolené období,
ke kterým zatím není spárovaný žádný doklad. Platby jsou seskupené podle karty
a držitele; karty, které nejsou v evidenci, jsou na konci seznamu.

U každé platby jsou akce:

- **Nahrát účtenku** — nahrajete PDF nebo fotografii účtenky. Doklad se vytěží
  stejně jako při AI importu přijaté faktury, dostane formu úhrady „karta"
  a koncovku karty z platby. Otevřete ho, zkontrolujte a potvrďte.
- **Spárovat** — po potvrzení dokladu spustí párování platby znovu. Spárovaná
  platba se hned zaúčtuje i s vypořádáním (viz Účtování plateb kartou).
- **Uzavřít bez dokladu** a **K tíži držitele** — jen u platby zaúčtované přes
  mezičlen karty, ke které doklad nebude (viz níže).
- **Výpis** — otevře bankovní výpis s platbou.

U platby zaúčtované přes mezičlen se pod obchodníkem zobrazí analytika karty,
na které platba čeká na doklad (například „Mezičlen 378.101"). Uzavřená platba
z přehledu zmizí.

U platby na čerpací stanici (podle obchodníka, např. název sítě stanic nebo
pohonné hmoty v popisu) se pod obchodníkem zobrazí **vozidlo držitele karty**,
pokud ho lze určit jednoznačně. Řídí-li držitel víc vozidel, aplikace to napíše
a vozidlo neurčí.

## Vozidlo podle karty

Karta s držitelem vybraným ze zaměstnanců slouží i [knize jízd](32_Kniha_jizd.md):
tankování zaplacené kartou (účtenka nebo faktura s koncovkou karty, bankovní
pohyb kartou, import tankování se sloupcem karty) se přiřadí vozidlu, jehož
řidičem je držitel karty. Rozhoduje karta platná k datu tankování, takže
historická tankování zůstanou u tehdejšího držitele i po výměně karty.

Vozidlo se podle karty přiřadí jen tehdy, když na dokladu není SPZ a držitel
řídí právě jedno aktivní vozidlo. Podrobnosti viz kapitola Kniha jízd, oddíl
Přiřazení vozidla.

## Oprávnění

Evidenci karet vidí a upravují uživatelé s oprávněním ke správě bankovních
účtů firmy. Přehled plateb bez dokladu vyžaduje přístup k bance, nahrání
účtenky oprávnění k nahrávání přijatých dokladů a spárování oprávnění
k párování bankovních pohybů. Nastavení účtování karet, změna analytiky karty
a uzavření platby bez dokladu vyžadují oprávnění k zaúčtování bankovních
pohybů (stejné jako nastavení GoPay).

## Účtování plateb kartou

Bez zapnutého režimu mezičlenu se spárovaná platba kartou účtuje stejně jako
jiná úhrada přijatého dokladu z bankovního účtu, ke kterému je karta vedená
(MD 321 / D 221).

V podvojném účetnictví lze platby kartou účtovat **přes mezičlen s analytikou
pro každou kartu**. Zapíná se v záložce **Nastavení účtování** na stránce
Platební karty. Platba kartou se pak z výpisu zaúčtuje hned, i když k ní ještě
není doklad, a zůstatek analytiky karty vždy ukazuje platby, ke kterým doklad
chybí.

### Předkontace

| Situace | Kdy se účtuje | Zápis |
|---|---|---|
| Platba kartou z výpisu | při zaúčtování bankovního pohybu, automaticky | MD 378.x / D 221 |
| Předpis přijatého dokladu | beze změny | MD 5xx (+ 343) / D 321 |
| Vypořádání s dokladem | při spárování platby kartou s přijatým dokladem | MD 321 / D 378.x |
| Kurzový rozdíl | ve vypořádání, když se platba v Kč liší od předpisu dokladu v cizí měně | MD 563 nebo D 663 |
| Haléřový rozdíl | ve vypořádání, když se platba liší od dokladu do 1 Kč | MD 548 nebo D 648 |
| Vratka na kartu | při zaúčtování příjmu kartou a jeho spárování s dobropisem | MD 221 / D 378.x, pak MD 378.x / D 321 |
| Uzavření bez dokladu | akce Uzavřít bez dokladu | MD 548 (nedaňová analytika) / D 378.x |
| K tíži držitele karty | akce K tíži držitele | MD 335 / D 378.x |
| Poplatek za kartu | beze změny, pravidlem bankovního poplatku | MD 568 / D 221 |
| Výběr hotovosti kartou | beze změny, převodem přes peníze na cestě | MD 261 / D 221, MD 211 / D 261 |

Vypořádání je **samostatný zápis** (v deníku zdroj „Vypořádání platby
kartou"). Bankovní zápis platby se kvůli dokladu nikdy nepřepisuje: spárování
přidá vypořádání, zrušení párování vypořádání stornuje a zůstatek analytiky
karty se vrátí. Stejně jako u zrušení zaúčtování bankovního pohybu platí, že
v uzavřeném nebo zamčeném období storno nejde provést.

Dorazí-li doklad až k platbě, kterou jste mezitím uzavřeli bez dokladu,
uzavření se samo stornuje a zaúčtuje se vypořádání.

### Analytika karty

Každá karta dostane vlastní analytiku mezičlenu **postupně** (378.101, 378.102
…), nikoli podle koncovky. Analytika vznikne automaticky u první platby karty
a jmenuje se „Karta ****1234 (název karty)". V detailu karty je vidět analytika,
její zůstatek (platby bez dokladu) a odkaz na obraty účtu. Jinou existující
analytiku mezičlenu lze kartě vybrat ručně; analytiku jiné karty převzít nejde.

Platba kartou, kterou firma neeviduje, založí **neověřenou kartu** s vlastní
analytikou. Neověřené karty stránka Platební karty zvýrazní — doplňte název
a držitele a kartu uložte, nebo ji ověřte akcí Ověřit kartu. Když je
zakládání karet vypnuté, nebo kartu nejde k datu platby jednoznačně určit,
jde platba na záchrannou analytiku **378.199 Neevidované karty**.

Karta vedená k cizoměnovému účtu má analytiku vedenou v měně účtu: zápisy nesou
cizoměnovou částku i kurz dne platby.

### Nastavení účtování

| Pole | Význam |
|---|---|
| Účtovat přes mezičlen | zapnutí režimu |
| Platí od | datum účinnosti; výchozí je začátek prvního otevřeného období |
| Mezičlen | syntetika 378 (výchozí), 261 nebo 395; 325 nabídnout nejde, je to saldokonto |
| Uzavřít bez dokladu | výchozí nedaňová analytika 548 |
| Pohledávka za držitelem | výchozí 335 |
| Kurzová ztráta a zisk | výchozí 563 a 663 |
| Haléřový rozdíl | výchozí 548 a 648 |
| Zakládat neznámé karty | vypnuto = platba jde na záchrannou analytiku 199 |
| Upozornit po (dnech) | lhůta měsíční kontroly plateb bez dokladu |

**Historie se nepřeúčtovává.** Platby kartou před datem účinnosti zůstávají
zaúčtované tak, jak byly (321/221, 548/221 …). Zaúčtovaná platba drží režim,
ve kterém se zaúčtovala: zapnutí režimu, jeho vypnutí, změna mezičlenu ani
změna analytiky karty už zaúčtované platby nemění a platí jen pro nové zápisy.
Nese-li původní analytika zůstatek, uložení změny chce potvrzení a zůstatek
na původním účtu vypořádáte ručně.

### Kontroly

- **Měsíční kontrola** hlásí platby kartou bez dokladu starší než lhůta
  z nastavení (jen od data účinnosti režimu). Řádek vede do přehledu plateb bez
  dokladu.
- **Předuzávěrková kontrola** „Mezičlen plateb kartou se zůstatkem" porovná
  zůstatek každé analytiky karty s konkrétními nevypořádanými platbami
  a zvlášť ukáže rozdíl, který platbami vysvětlit nejde (typicky ruční zápis
  na analytiku karty). Nevypořádané platby uzavřete v přehledu plateb bez
  dokladu.
- Analytiky karet pod 261 nebo 395 se nehlásí v kontrole peněz na cestě,
  vnitřního zúčtování ani průběžných účtů — hlídá je jen kontrola mezičlenu.
- Výkaz peněžních toků bere platbu kartou jako výdaj v den platby, i když je
  mezičlen pod 261.

### Daňová evidence

Firma v daňové evidenci účty nemá. Platba kartou je v ní bankovní výdaj
v peněžním deníku a mezičlen se nepoužívá; neznámé karty se nezakládají.
Přehled plateb bez dokladu slouží jako výzva k doložení výdaje.
