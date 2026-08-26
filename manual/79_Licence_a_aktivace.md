# 79. Licence a aktivace

MyÚčto.cz je nástupcem open-source systému MyInvoice. Všechny funkce MyInvoice
zůstávají v MyÚčto navždy zdarma; rozšířená účetní nadstavba je komerční
produkt na předplatné. Tato kapitola popisuje, jak licence funguje, kde ji
spravuješ, co se děje po vypršení a jak předplatné zakoupíš, aktivuješ,
navýšíš nebo přeneseš na jinou instalaci.

## 79.1 Licenční model

MyÚčto stojí na dvou vrstvách:

- **Bezplatný základ MyInvoice.** Veškeré koncové funkce původního projektu
  [MyInvoice](https://github.com/radekhulan/myinvoice) lze používat navždy
  zdarma, včetně vytváření a úprav dat. Původní zdrojový kód zůstává pod MIT.
- **Komerční nadstavba (source-available).** Účetnictví (podvojné i daňová
  evidence), mzdy, sklad s napojením e-shopu a režim OSS jsou proprietární
  moduly a vyžadují komerční licenci sjednanou **předplatným na myucto.cz**.
  Totéž platí pro věci, které se o ně opírají: účetní nástroje a uzávěrky,
  evidence majetku, automatizace, přehled firem, EPO podání a archív
  a rozšířené opravy DPH podle § 74b, § 43, § 46 a § 79.

Čtyři modulové přepínače v **Nastavení → Daně a účetnictví** (Vést účetnictví,
Vést mzdy, Vést skladovou evidenci, Režim OSS) jsou proto v jednom rámečku:
mají společnou podmínku. Bez licence zůstávají zamčené a moduly se z menu
schovají; jejich data se ale nemažou a po aktivaci se vrátí i s historií.

Zdarma tedy zůstává celá fakturace: vydané i přijaté faktury, klienti, ceník,
banka a pokladna, dokumenty, přiznání k DPH, kontrolní hlášení a souhrnné
hlášení, a samozřejmě celé nastavení firmy.

Zdrojový kód komerční části je sice viditelný, ale jeho zpřístupnění samo o sobě
nezakládá právo produkt jako celek provozovat bez licence.

> 🛈 **60 dní zdarma, bez registrace.** Novou instalaci lze prvních 60 dní od
> prvního spuštění používat v plném rozsahu bezplatně, bez registrace i platby.
> Teprve po uplynutí zkušebního období vyžaduje komerční část aktivaci
> licenčním klíčem.

Cena předplatného je **za jednoho aktivního uživatele a měsíc**; celková cena
je násobkem tarifu a počtu aktivních uživatelů (viz [§ 79.3](#793-zkusebni-obdobi-a-stavy-licence)
ke způsobu započítání). Úplné znění licenčního ujednání je v souboru
`LICENCE.txt` v rootu instalace a na <https://myucto.cz/licence>; podmínky
prodeje předplatného upravují obchodní podmínky na
<https://myucto.cz/obchodni-podminky>.

## 79.2 Kde licenci spravovat

Správa licence je v menu **Systém → Zakoupení**. Související informace jsou
rozdělené do tří stránek:

| Stránka | Co obsahuje |
|---|---|
| **Licence** | Přehled bezplatných a komerčních funkcí, zkušebního období a tarifů. |
| **Obchodní podmínky** | Shrnutí hlavních článků podmínek předplatného (závazné je plné znění na myucto.cz). |
| **Zakoupení** | Provozní stránka — aktuální stav licence, zakoupení předplatného, aktivace klíčem, navýšení uživatelů, zrušení automatického prodlužování a deaktivace. |

> 🛈 Správu licence (stránku **Zakoupení**) vidí a ovládá jen **administrátor**.
> Běžný uživatel stránku otevře, ale operace nejsou dostupné.

## 79.3 Zkušební období a stavy licence

Stav licence se počítá při každém přihlášeném požadavku a promítá se do banneru
v aplikaci i do karty stavu na stránce **Systém → Zakoupení**.

| Stav | Význam | Provoz |
|---|---|---|
| **Zkušební období** | Bez klíče, méně než 60 dní od prvního spuštění. Ukazuje se odpočet do konce. | Plný, bez limitů |
| **Zkušební období skončilo** | Bez klíče, po 60 dnech. | Bezplatné funkce plně; komerční moduly nedostupné |
| **Aktivní** | Platný klíč, předplatné běží. | Plný, do počtu licencovaných uživatelů a firem |
| **Překročen rozsah (overage)** | Víc aktivních uživatelů nebo firem, než licence pokrývá. | Plný provoz + výzva, ale nelze zakládat další uživatele/firmy |
| **Komerční funkce nedostupné (degraded)** | Předplatné neobnoveno (po ochranné lhůtě), případně chybí/neplatný podpis tokenu. | Bezplatné funkce plně; komerční moduly nedostupné |

**Kdo se počítá do limitu uživatelů.** Do počtu licencovaných míst se počítají
**aktivní uživatelé**, kterým alespoň jedna aktivní aplikační nebo klientská
role dovoluje zápis do obchodních dat. Počítá se i zapisovací role přiřazená
jen pro jednu firmu. Klientská role tedy není automaticky zdarma; rozhodují
její skutečná oprávnění. Bezplatná je role pouze pro čtení a také self-service
role, která dovoluje měnit jen vlastní profil nebo vlastní přístupové tokeny.
Deaktivované účty se nepočítají. Vedle uživatelů se hlídá i **počet firem**
(dodavatelů) proti limitu tarifu; počítá se každá založená firma.

**Překročení rozsahu (overage).** Když aktivních uživatelů nebo firem přibude
nad rámec klíče, aplikace na to upozorní a poskytne lhůtu 14 dní na rozšíření
předplatného (nebo srovnání počtů). Provoz zůstává plný, jen nejde zakládat
další uživatele ani firmy. Po marném uplynutí lhůty se obnova licence pozastaví
a komerční nadstavba se vypne.

> ⚠️ **Bezplatná část zůstává plně funkční.** Lze dál vystavovat a přijímat
> doklady, spravovat kontakty, importovat bankovní výpisy, vést základní
> daňovou evidenci a používat ostatní funkce převzaté z MyInvoice. Nedostupný
> je celý **Sklad**, **Účetnictví** a **Nástroje**, evidence majetku, EPO podání
> a archív a opravy DPH podle § 74b, § 43, § 46 a § 79. Tyto komerční stránky
> nejdou bez licence ani zobrazit, exportovat nebo volat přes API.
>
> Data komerčních modulů se nemažou ani nemění; zůstávají ve vlastní databázi
> provozovatele. Aplikace je znovu zpřístupní po obnovení licence.

## 79.4 Zakoupení předplatného

Na stránce **Systém → Zakoupení** klikni na **Zakoupit předplatné**. Aplikace
vytvoří krátce platnou relaci svázanou právě s touto instalací a otevře
objednávku na myucto.cz s předvyplněnými fakturačními údaji firmy. Identifikátor
instalace ani licenční tajemství se nedají změnit formulářem. Na webu zvolíš:

- **tarif** podle počtu firemních agend — **Jedna firma** (1 agenda),
  **Účetní kancelář** (až 10 agend) nebo **Neomezeně** (bez limitu firem),
- **počet uživatelů**,
- **období** — měsíční, nebo roční.

> 💡 **Roční předplatné = 10 měsíčních plateb** (dva měsíce zdarma).

Po zaplacení první platby tě myucto.cz vrátí přímo do aplikace. Ta bezpečně
vyzvedne nový klíč a licenci sama aktivuje; klíč se nepřenáší v URL ani se
nekopíruje ručně. Potvrzení a licenční klíč zároveň přijdou e-mailem jako
záložní cesta. Když se automatický návrat nepodaří, lze klíč aktivovat ručně.

## 79.5 Aktivace licenčním klíčem

Klíč z e-mailu vlož na stránce **Systém → Zakoupení** do pole v sekci
**Aktivace licenčního klíče** a klikni **Aktivovat**. Aktivací se licence
**naváže na tuto instalaci** (jedinečný identifikátor vytvořený při prvním
spuštění). Jeden klíč smí být v jednom okamžiku aktivní na jedné instalaci.

Po aktivaci aplikace platnost licence **jednou denně online ověřuje** vůči
serveru myucto.cz a získává kryptograficky podepsané potvrzení s platností
14 dní. Krátkodobý výpadek internetu proto provoz neomezí. Ověřování běží
samo, na pozadí — **nevyžaduje žádné nastavení uživatele**.

> 🛈 **Co se při ověření přenáší.** Jen technické údaje — identifikátor
> instalace, licenční klíč, identifikace sestavení a souhrnné počty aktivních
> uživatelů a firem. **Žádná účetní ani osobní data** se na licenční server
> neposílají.

## 79.6 Změny kapacity a tarifu

Potřebuješ-li změnit tarif nebo počet licencovaných uživatelů během běžícího
období, není třeba zakládat nové předplatné — změna se dělá **přímo v aplikaci**.
V sekci **Navýšit počet uživatelů** (na stránce Zakoupení) zadej cílový počet,
nech si **Spočítat cenu** a potvrď. Server strhne jen **poměrný doplatek do
konce aktuálního období z uložené karty** a **místa naskočí ihned**. Od dalšího
cyklu se pak účtuje plná nová cena.

Stejné ovládání se nabídne i v případě, že jsi v překročeném rozsahu
(overage) — navýšením přečerpání odstraníš. Změna licence, tarifu a počtu
uživatelů funguje stejně u samostatného self-hosted předplatného i u
spravovaného SaaS hostingu.

Stejný postup platí pro vyšší tarif podle počtu firem a u spravovaného
hostingu také pro větší prostor. Nákup prostoru a hostingové akce se u
self-hosted licence nezobrazují. Nabídka ceny je krátkodobě platná a potvrzení
je svázané právě se zobrazenou částkou. Pokud platební brána platbu zpracovává
asynchronně, aplikace její stav průběžně ověřuje a nový rozsah zpřístupní
hned po potvrzení.

**Snížení počtu uživatelů, tarifu nebo prostoru** se neprojeví uprostřed už
zaplaceného období. Po potvrzení se naplánuje od začátku následujícího
fakturačního období a jeho nižší rozsah se použije pro další pravidelnou
platbu. Za současné období se neposkytuje vratka ani dobropis.

## 79.7 Přenos licence a přeinstalace

Licenci lze přesunout na jinou instalaci (nový server, přeinstalace) —
**nejvýše dvakrát za 30 dní**.

- **Řízený přenos.** Na staré instalaci klikni na **Deaktivovat** (uvolní vazbu)
  a na nové instalaci klíč běžně **aktivuj**.
- **Přenos po zániku instalace (takeover).** Když stará instalace už
  neexistuje a nešlo ji deaktivovat, aktivace klíče na nové instalaci nahlásí,
  že je klíč aktivní jinde. Aplikace pak nabídne tlačítko
  **Aktivovat na této instalaci (přenést)** — původní vazba se uvolní a licence
  se přiváže sem. I tento přenos se počítá do limitu 2 přenosů za 30 dní.

> 🛈 Deaktivace smaže klíč lokálně i tehdy, když je licenční server zrovna
> nedostupný — nezasekneš se. Vyčerpáš-li limit přenosů, další povolí poskytovatel
> na žádost (kontakt na myucto.cz).

## 79.8 Přehled dokladů a plateb

- **Daňový doklad** za každou platbu chodí **e-mailem**.
- **Opakované platby** (kartou přes platební bránu) běží automaticky v pevné
  výši, měsíčně nebo ročně, s tvým souhlasem. **Zrušit je můžeš kdykoli** ke
  konci zaplaceného období — buď přímo v aplikaci (viz níže), nebo přes odkaz
  v e-mailu. Přehled objednávek, změnu karty a fakturační údaje řeší web
  [myucto.cz](https://myucto.cz/).

**Zrušení automatického prodlužování v aplikaci.** Na stránce
**Systém → Zakoupení** je sekce **Automatické prodlužování**: vidíš v ní, zda
se licence prodlužuje sama a kdy je **další platba**. Tlačítkem
**Zrušit automatické prodlužování** obnovu vypneš.

> 🛈 **Není to deaktivace.** Licence běží dál až **do konce zaplaceného období**
> (datum *Platnost do*) a klíč zůstává navázaný na tuto instalaci — jen se už
> nestrhne další platba. Komerční funkce ani přístup k datům se zrušením
> prodlužování okamžitě nemění. Poměrná část se nevrací. Prodlužování se po
> zrušení nedá zapnout zpět; obnovíš ho **novým nákupem předplatného**. Detailní
> pravidla jsou v **Systém → Obchodní podmínky**.

> ⚠️ **U spravovaného SaaS hostingu zrušíš i budoucí provoz instance.** Po
> skončení zaplaceného období ztratíš přístup k hostingu a následně mohou být
> podle retenčních pravidel odstraněna i uložená data. Účetní doklady musíš
> uchovávat po zákonnou dobu, proto před zrušením použij **Systém → Kompletní
> export dat** a zálohu ulož mimo hosting. Toto upozornění se netýká samostatné
> self-hosted licence, protože její server a data provozuješ sám.

## 79.9 Řešení potíží

| Problém | Co s tím |
|---|---|
| **Klíč nejde aktivovat** | Zkontroluj, že jsi klíč zkopíroval celý (formát `MYU-XXXX-…`) a že je instalace online. Chyba serveru se vypíše přímo pod polem. |
| **„Tato licence je aktivní na jiné instalaci"** | Klíč běží jinde (typicky po přeinstalaci bez deaktivace). Použij **Aktivovat na této instalaci (přenést)** — viz [§ 79.7](#797-prenos-licence-a-preinstalace). |
| **Poslední kontrola selhala** | Krátký výpadek internetu nevadí — token platí 14 dní. Když výpadek trvá, ověř konektivitu na `myucto.cz`. |
| **Komerční moduly zmizely z menu** | Vypršelo předplatné (nebo skončil trial). Bezplatné funkce zůstávají plně dostupné. Komerční funkce obnovíš aktivací licence na stránce **Zakoupení**; jejich data zůstávají v databázi beze změny. |

Další diagnostika a časté chyby jsou v kapitole
[999. Řešení problémů](999_Reseni_problemu.md).
