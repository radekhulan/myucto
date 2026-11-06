# 104. Licence a aktivace

> Návod pro správce instalace: jak zakoupit předplatné MyÚčta, aktivovat
> licenční klíč, navýšit počet uživatelů, přenést licenci na jinou instalaci
> a zrušit automatické prodlužování. Na konci je výklad licenčního modelu a
> stavů licence.

MyÚčto.cz je nástupcem open-source systému MyInvoice. Všechny funkce MyInvoice
zůstávají v MyÚčto navždy zdarma; rozšířená účetní nadstavba je komerční
produkt na předplatné.

## 104.1 Kdy to potřebujete

Kapitolu otevřete, když:

- končí 60denní zkušební období a komerční moduly (účetnictví, mzdy, sklad)
  mají zůstat k dispozici,
- jste zaplatili předplatné a máte licenční klíč z e-mailu,
- aplikace hlásí překročení rozsahu licence (víc uživatelů nebo firem, než
  licence pokrývá),
- potřebujete víc uživatelů, vyšší tarif nebo Mzdy,
- stěhujete aplikaci na nový server nebo ji přeinstalováváte,
- nechcete, aby se předplatné dál automaticky prodlužovalo.

<!-- cols: 26 40 34 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| před koncem 60 dní zdarma | Zakoupit předplatné | `Systém → Zakoupení`, **Zakoupit předplatné** |
| po zaplacení | Ověřit, že se licence aktivovala; jinak vložit klíč z e-mailu | `Systém → Zakoupení`, **Aktivace licenčního klíče** |
| při překročení rozsahu | Navýšit počet uživatelů nebo tarif do 14 dnů | `Systém → Zakoupení`, **Navýšit počet uživatelů** |
| při stěhování instalace | Deaktivovat klíč na staré instalaci a aktivovat na nové | `Systém → Zakoupení` |
| před koncem zaplaceného období | Zrušit automatické prodlužování, pokud ho nechcete | `Systém → Zakoupení`, **Automatické prodlužování** |

## 104.2 Než začnete

- **Role.** Správu licence (stránku **Zakoupení**) vidí a ovládá jen
  administrátor. Běžný uživatel stránku otevře, ale operace nejsou dostupné.
- **Internet.** Instalace musí být online, aby mohla ověřit licenci na
  myucto.cz.
- **Platební karta** pro předplatné a pro změny rozsahu za běhu.
- **Záloha před zrušením hostingu.** U spravovaného hostingu si před zrušením
  prodlužování stáhněte `Systém → Kompletní export dat`.

Správa licence je v menu `Systém` rozdělená do tří položek:

<!-- cols: 30 70 -->
| Položka | Co obsahuje |
|---|---|
| **Licence** | Přehled bezplatných a komerčních funkcí, zkušebního období a tarifů. |
| **Obchodní podmínky** | Shrnutí hlavních článků podmínek předplatného (závazné je plné znění na myucto.cz). |
| **Zakoupení** | Provozní stránka: aktuální stav licence, zakoupení předplatného, aktivace klíčem, navýšení uživatelů, zrušení automatického prodlužování a deaktivace. |

## 104.3 Krok za krokem: zakoupení předplatného

1. Otevřete `Systém → Zakoupení` a klikněte na **Zakoupit předplatné**.
2. Aplikace otevře objednávku na myucto.cz s předvyplněnými fakturačními údaji
   firmy. Na webu zvolte:
   - **tarif** podle počtu firemních agend: **Jedna firma** (1 agenda),
     **Účetní kancelář** (až 10 agend) nebo **Neomezeně** (bez limitu firem),
   - **počet uživatelů**,
   - volitelně **Mzdy**, mzdové pásmo a počet mzdových uživatelů,
   - **období**: měsíční, nebo roční.
3. Zaplaťte první platbu.
4. Web vás vrátí přímo do aplikace. Ta bezpečně vyzvedne nový klíč a licenci
   sama aktivuje.

**Jak poznáte, že je hotovo:** Aplikace ukáže hlášku, že platba je potvrzená
a licence byla automaticky aktivována. Karta stavu na stránce **Zakoupení**
ukazuje stav **Aktivní**. Potvrzení a licenční klíč zároveň přijdou e-mailem.

> [!TIP]
> Roční předplatné je 10 měsíčních plateb (dva měsíce zdarma).

Když se automatický návrat nepodaří, aplikace to oznámí a klíč z e-mailu
aktivujete ručně podle [§ 104.4](#1044-krok-za-krokem-aktivace-licencnim-klicem).

## 104.4 Krok za krokem: aktivace licenčním klíčem

1. Otevřete `Systém → Zakoupení`.
2. V sekci **Aktivace licenčního klíče** vložte klíč z e-mailu (formát
   `MYU-XXXX-XXXX-XXXX`).
3. Klikněte na **Aktivovat**.

**Jak poznáte, že je hotovo:** Karta stavu ukazuje **Aktivní**, **Licenční
klíč** a datum **Poslední kontrola**. Komerční moduly se vrátí do menu i s
historií dat.

Aktivací se licence naváže na tuto instalaci. Jeden klíč smí být v jednom
okamžiku aktivní na jedné instalaci. Ověřování licence běží samo na pozadí
a nevyžaduje žádné nastavení (viz [§ 104.10.3](#104103-zkusebni-obdobi-a-stavy-licence)).

## 104.5 Krok za krokem: navýšení počtu uživatelů nebo změna tarifu

Změna za běhu předplatného se dělá přímo v aplikaci, nový nákup není potřeba.

1. Otevřete `Systém → Zakoupení`.
2. V sekci **Navýšit počet uživatelů** zadejte **Cílový počet uživatelů**
   a klikněte na **Spočítat cenu**.
3. Zkontrolujte **Doplatek do konce období** a klikněte na **Navýšit a
   zaplatit z uložené karty**. Potvrďte dotaz.
4. Vyšší tarif podle počtu firem změníte v sekci **Změna tarifu podle počtu
   firem**: zvolte **Cílový tarif** a postupujte stejně.
5. Mzdy zapnete nebo rozšíříte v sekci **Mzdový doplněk** (**Spočítat cenu
   a zapnout Mzdy**, případně **Spočítat změnu rozsahu Mezd**).

**Jak poznáte, že je hotovo:** Aplikace ukáže hlášku, že licence byla
navýšena, a údaj **Uživatelé: aktivní … / licencováno …** ukazuje nový počet.
Nový rozsah se projeví hned po zaplacení. Od dalšího cyklu se účtuje plná nová cena.

**Předplatné placené fakturou** (bez uložené karty): po potvrzení klikněte na
**Zaplatit kartou**. Kartou se zaplatí jen tato změna, předplatné se dál platí
fakturou.

> [!WARNING]
> Snížení počtu uživatelů, tarifu nebo prostoru se neprojeví uprostřed už
> zaplaceného období. Naplánuje se od začátku dalšího fakturačního období
> (**Naplánovat od dalšího období**). Za současné období není vratka ani
> dobropis.

## 104.6 Krok za krokem: přechod z měsíčního předplatného na roční

1. Otevřete `Systém → Zakoupení` a najděte sekci **Přechod na roční
   předplatné**.
2. Klikněte na **Spočítat cenu**. Aplikace ukáže celou roční částku, úsporu
   a datum, do kdy licence pak platí.
3. Potvrďte. Částka se strhne z uložené karty.

**Jak poznáte, že je hotovo:** Aplikace ukáže hlášku, že předplatné je roční,
a datum platnosti licence.

Roční období navazuje na konec už zaplaceného měsíce, takže o zaplacené dny
nepřijdete. Roční předplatné se platí za deset měsíců místo dvanácti. Nabídka
se nezobrazí u ročního předplatného ani tehdy, když je na předplatném
naplánovaná změna na další období (nižší tarif, méně uživatelů, menší prostor
nebo změna Mezd); tu je potřeba nejdřív zrušit.

## 104.7 Krok za krokem: přenos licence na jinou instalaci

Licenci lze přesunout nejvýše dvakrát za 30 dní.

**Řízený přenos** (stará instalace ještě běží):

1. Na staré instalaci otevřete `Systém → Zakoupení` a klikněte na
   **Deaktivovat**. Potvrďte dotaz.
2. Na nové instalaci vložte klíč do **Aktivace licenčního klíče** a klikněte
   na **Aktivovat**.

**Přenos po zániku instalace** (stará instalace už neexistuje):

1. Na nové instalaci vložte klíč a klikněte na **Aktivovat**.
2. Aplikace ohlásí, že licence je aktivní jinde, a ukáže **Zbývá přenosů**.
   Klikněte na **Aktivovat na této instalaci (přenést)** a potvrďte. Licence
   se odpojí od zaniklé instalace a přiváže se k nové. I tento přenos se
   počítá do limitu dvou přenosů za 30 dní.

**Jak poznáte, že je hotovo:** Nová instalace ukazuje stav **Aktivní**.

> [!TIP]
> Deaktivace smaže klíč lokálně i tehdy, když je licenční server nedostupný.
> Vyčerpáte-li limit přenosů, další povolí poskytovatel na žádost (kontakt na
> myucto.cz).

## 104.8 Krok za krokem: zrušení automatického prodlužování

1. Otevřete `Systém → Zakoupení` a najděte sekci **Automatické prodlužování**.
   Vidíte v ní, zda se licence prodlužuje sama a kdy je **Další platba**.
2. Klikněte na **Zrušit automatické prodlužování** a potvrďte dotaz.

**Jak poznáte, že je hotovo:** Sekce ukazuje **Automatické prodlužování je
vypnuté** a datum, do kdy licence běží.

> [!WARNING]
> U spravovaného SaaS hostingu zrušíte i budoucí provoz instance. Po skončení
> zaplaceného období ztratíte přístup k hostingu a podle retenčních pravidel
> mohou být odstraněna i uložená data. Účetní doklady musíte uchovávat po
> zákonnou dobu, proto před zrušením použijte `Systém → Kompletní export dat`
> a zálohu uložte mimo hosting. Upozornění se netýká samostatné self-hosted
> licence.

Zrušení lze vzít zpět: v téže sekci klikněte na **Obnovit předplatné**,
zadejte novou platební kartu (původní se při zrušení u brány zneplatnila)
a zaplaťte další období. Po ukončení provozu hostované instalace obnova z
aplikace není možná a nabídne se kontakt na podporu.

## 104.9 Když něco nejde

<!-- cols: 30 70 -->
| Co vidíte | Co udělat |
|---|---|
| **Klíč nejde aktivovat** | Zkontrolujte, že jste klíč zkopírovali celý (formát `MYU-XXXX-…`) a že je instalace online. Chyba serveru se vypíše přímo pod polem. |
| **Tato licence je aktivní na jiné instalaci.** | Klíč běží jinde (typicky po přeinstalaci bez deaktivace). Použijte **Aktivovat na této instalaci (přenést)**, viz [§ 104.7](#1047-krok-za-krokem-prenos-licence-na-jinou-instalaci). |
| **Překročili jste rozsah licence.** | Máte víc aktivních uživatelů nebo firem, než pokrývá klíč. Navyšte rozsah podle [§ 104.5](#1045-krok-za-krokem-navyseni-poctu-uzivatelu-nebo-zmena-tarifu), nebo počty srovnejte. Na rozšíření máte lhůtu 14 dní. |
| **Poslední kontrola selhala** | Krátký výpadek internetu nevadí, potvrzení platí 14 dní. Když výpadek trvá, ověřte konektivitu na `myucto.cz`. Můžete použít **Aktualizovat stav licence ze serveru**. |
| **Komerční moduly zmizely z menu** | Vypršelo předplatné nebo skončilo zkušební období. Bezplatné funkce zůstávají plně dostupné. Komerční funkce obnovíte aktivací licence na stránce **Zakoupení**; jejich data zůstávají v databázi beze změny. |
| Změna se nezobrazuje, stav je **Platba se zpracovává.** | Platební brána zpracovává platbu asynchronně. Stav aplikace průběžně ověřuje; změnu neobjednávejte znovu. |

Další diagnostika a časté chyby jsou v kapitole
[999. Řešení problémů](999_Reseni_problemu.md).

## 104.10 Podrobnosti a pravidla

### 104.10.1 Licenční model

MyÚčto stojí na dvou vrstvách:

- **Bezplatný základ MyInvoice.** Veškeré koncové funkce původního projektu
  [MyInvoice](https://github.com/radekhulan/myinvoice) lze používat navždy
  zdarma, včetně vytváření a úprav dat. Původní zdrojový kód zůstává pod MIT.
- **Komerční nadstavba (source-available).** Účetnictví (podvojné i daňová
  evidence), mzdy, sklad s napojením e-shopu, režim OSS a agenda SVJ jsou
  proprietární moduly a vyžadují komerční licenci sjednanou **předplatným na myucto.cz**.
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

> [!TIP]
> 60 dní zdarma, bez registrace. Novou instalaci lze prvních 60 dní od prvního
> spuštění používat v plném rozsahu bezplatně, bez registrace i platby.
> Teprve po uplynutí zkušebního období vyžaduje komerční část aktivaci
> licenčním klíčem.

Cena účetní licence je **za jednoho aktivního uživatele a měsíc**; celková cena
je násobkem tarifu a počtu aktivních uživatelů (viz
[§ 104.10.3](#104103-zkusebni-obdobi-a-stavy-licence) ke způsobu započítání).
Mzdy jsou samostatný volitelný doplněk s vlastní cenou za aktivního mzdového
uživatele. Úplné znění licenčního ujednání je v souboru `LICENCE.txt` v rootu
instalace a na <https://myucto.cz/licence>; podmínky prodeje předplatného
upravují obchodní podmínky na <https://myucto.cz/obchodni-podminky>.

### 104.10.2 Mzdové pásmo a mzdoví uživatelé

Mzdové pásmo se určuje podle součtu aktivních zaměstnanců všech firem v celé
instalaci. Nabídka obsahuje pásma do 25 zaměstnanců, do 50 zaměstnanců a
neomezený počet zaměstnanců. Do počtu mzdových uživatelů se počítají aktivní
uživatelé, kteří mají alespoň v jedné firmě se zapnutými Mzdami účinné právo
k zápisu do mzdových dat. Mzdy nejsou v objednávce předvolené.

U spravované instalace objednané rovnou s Mzdami se modul Mzdy při zřízení
zapne na první firmě, kterou objednávka založila. Další firmy si Mzdy zapínají
v nastavení firmy samy.

Bez aktivního mzdového nároku po zkušební době nelze Mzdy zapnout ani otevřít.
Aplikace v takovém případě nabídne zakoupení nebo rozšíření licence. Server při
změně rozsahu Mezd účtuje poměrný doplatek do konce období.

### 104.10.3 Zkušební období a stavy licence

Stav licence se počítá při každém přihlášeném požadavku a promítá se do banneru
v aplikaci i do karty stavu na stránce `Systém → Zakoupení`.

<!-- cols: 24 40 36 -->
| Stav | Význam | Provoz |
|---|---|---|
| **Zkušební období** | Bez klíče, méně než 60 dní od prvního spuštění. Ukazuje se odpočet do konce. | Plný, bez limitů |
| **Zkušební období skončilo** | Bez klíče, po 60 dnech. | Bezplatné funkce plně; komerční moduly nedostupné |
| **Aktivní** | Platný klíč, předplatné běží. | Plný, do počtu licencovaných uživatelů a firem |
| **Překročen rozsah (overage)** | Víc aktivních uživatelů nebo firem, než licence pokrývá. | Plný provoz + výzva, ale nelze zakládat další uživatele/firmy |
| **Komerční funkce nedostupné (degraded)** | Předplatné neobnoveno (po ochranné lhůtě), případně chybí/neplatný podpis tokenu. | Bezplatné funkce plně; komerční moduly nedostupné |

**Kdo se počítá do limitu uživatelů.** Do počtu licencovaných míst se počítají
**všichni aktivní uživatelé**, včetně klientů, technických správců a účtů
s vlastní čtecí rolí. Výjimkou je pouze účet používající výhradně vestavěnou
roli **Pouze pro čtení**, která nemá zápis mimo správu vlastních API tokenů.
Posuzuje se výchozí role i role přiřazené jednotlivým firmám; jiná role
u jediné firmy už licenční místo zabírá. Vlastní role se stejným názvem tuto
výjimku nemá. Vlastní profil dovoluje pouze čtení. API tokeny mají výchozí
oprávnění **Čtení**, správce může povolit **Zápis** pro jejich správu; token
přitom nepřekročí oprávnění svého uživatele.
Deaktivované účty se nepočítají. Vedle uživatelů se hlídá i **počet firem**
(dodavatelů) proti limitu tarifu; počítá se každá založená firma.

**Překročení rozsahu (overage).** Když aktivních uživatelů nebo firem přibude
nad rámec klíče, aplikace na to upozorní a poskytne lhůtu 14 dní na rozšíření
předplatného (nebo srovnání počtů). Provoz zůstává plný, jen nejde zakládat
další uživatele ani firmy. Po marném uplynutí lhůty se obnova licence pozastaví
a komerční nadstavba se vypne.

> [!WARNING]
> Bezplatná část zůstává plně funkční. Lze dál vystavovat a přijímat doklady,
> spravovat kontakty, importovat bankovní výpisy, vést základní daňovou
> evidenci a používat ostatní funkce převzaté z MyInvoice. Nedostupný je celý
> **Sklad**, **Účetnictví** a **Nástroje**, evidence majetku, EPO podání a
> archív a opravy DPH podle § 74b, § 43, § 46 a § 79. Tyto komerční stránky
> nejdou bez licence ani zobrazit, exportovat nebo volat přes API. Data
> komerčních modulů se nemažou ani nemění; zůstávají ve vlastní databázi
> provozovatele. Aplikace je znovu zpřístupní po obnovení licence.

**Ověřování licence.** Po aktivaci aplikace platnost licence běžně jednou denně
online ověřuje vůči serveru myucto.cz a získává kryptograficky podepsané
potvrzení s platností 14 dní. Od dvou hodin před další platbou do 24 hodin po
ní a při prodlení se stav kontroluje jednou za hodinu. Krátkodobý výpadek
internetu proto provoz neomezí. Ověřování běží samo na pozadí a nevyžaduje
žádné nastavení uživatele.

> [!TIP]
> Při ověření se přenášejí jen technické údaje: identifikátor instalace,
> licenční klíč, identifikace sestavení a souhrnné počty aktivních uživatelů,
> firem, zaměstnanců a mzdových uživatelů. Žádná účetní ani osobní data se na
> licenční server neposílají.

### 104.10.4 Pravidla změn kapacity

Stejný postup jako v [§ 104.5](#1045-krok-za-krokem-navyseni-poctu-uzivatelu-nebo-zmena-tarifu)
platí i při překročení rozsahu (overage): navýšením přečerpání odstraníte.
Změna licence, tarifu a počtu uživatelů funguje stejně u samostatného
self-hosted předplatného i u spravovaného SaaS hostingu.

U spravovaného hostingu stejně funguje i nákup většího prostoru. Nákup prostoru
a hostingové akce se u self-hosted licence nezobrazují. Nabídka ceny je
krátkodobě platná a potvrzení je svázané právě se zobrazenou částkou. Pokud
platební brána platbu zpracovává asynchronně, aplikace její stav průběžně
ověřuje a nový rozsah zpřístupní hned po potvrzení.

Instance s velkým objemem dat si u spravovaného hostingu může zapnout
**výkonnostní instanci** (`Předplatné a provoz`, sekce **Výkonnostní instance**):
1024 MB paměti a 8 PHP procesů místo standardních 512 MB a 4 procesů za
350 Kč s DPH měsíčně (u ročního předplatného 12 měsíců bez slevy). Zapnutí se
doplatí poměrně do konce zaplaceného období a projeví se, jakmile ho provede
poskytovatel hostingu. Vypnutí platí od konce zaplaceného období bez vratky;
do té doby ho tlačítkem **Ponechat zapnuté** zdarma zrušíte. Nad 100 000
faktur nebo 500 000 účetních zápisů v celé instanci aplikace nabídku označí
jako doporučenou.

Server účtuje při navýšení jen poměrný doplatek do konce aktuálního období
z uložené karty. U předplatného placeného fakturou (typicky roční) se kartou
zaplatí jen jednorázově tato změna; předplatné se dál platí fakturou a konec
zaplaceného období se nemění. Opakované potvrzení vede na tutéž platbu, takže
se nic nezaplatí dvakrát. Snížení se naplánuje od dalšího období bez vratky.

Z ročního předplatného zpátky na měsíční se z aplikace přejít nedá a už
zaplacenou roční licenci nelze prodloužit dopředu o další rok. Další rok se
naúčtuje sám řádnou obnovou na konci zaplaceného období.

### 104.10.5 Přehled dokladů a plateb

- **Daňový doklad** za každou platbu chodí e-mailem.
- **Opakované platby** (kartou přes platební bránu) běží automaticky v pevné
  výši, měsíčně nebo ročně, s vaším souhlasem. Zrušit je můžete kdykoli ke
  konci zaplaceného období, buď přímo v aplikaci ([§ 104.8](#1048-krok-za-krokem-zruseni-automatickeho-prodluzovani)),
  nebo přes odkaz v e-mailu. Přehled objednávek, změnu karty a fakturační
  údaje řeší web [myucto.cz](https://myucto.cz/).

Zrušení prodlužování není deaktivace. Licence běží dál až do konce zaplaceného
období (datum **Platnost do**) a klíč zůstává navázaný na tuto instalaci, jen
se už nestrhne další platba. Komerční funkce ani přístup k datům se zrušením
okamžitě nemění. Poměrná část se nevrací. Detailní pravidla jsou v
`Systém → Obchodní podmínky`.

## 104.11 Související kapitoly

- [Aktualizace](102_Aktualizace.md)
- [Řešení problémů](999_Reseni_problemu.md)
