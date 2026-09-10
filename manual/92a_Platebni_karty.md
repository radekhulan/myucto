# Platební karty

Sekce **Firma → Platební karty** vede firemní platební karty a jejich držitele.
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
(viz níže).

## Platby kartou bez dokladu

Záložka **Platby bez dokladu** ukazuje odchozí platby kartou za zvolené období,
ke kterým zatím není spárovaný žádný doklad. Platby jsou seskupené podle karty
a držitele; karty, které nejsou v evidenci, jsou na konci seznamu.

U každé platby jsou akce:

- **Nahrát účtenku** — nahrajete PDF nebo fotografii účtenky. Doklad se vytěží
  stejně jako při AI importu přijaté faktury, dostane formu úhrady „karta"
  a koncovku karty z platby. Otevřete ho, zkontrolujte a potvrďte.
- **Spárovat** — po potvrzení dokladu spustí párování platby znovu.
- **Výpis** — otevře bankovní výpis s platbou.

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
k párování bankovních pohybů.

## Účtování

Spárovaná platba kartou se účtuje stejně jako jiná úhrada přijatého dokladu
z bankovního účtu, ke kterému je karta vedená.
