# Datová schránka

Nastavení datové schránky je vždy svázané s právě vybranou firmou. Otevřete je přes **Firma → Datová schránka**. Produkční a testovací prostředí mají oddělené přístupy, uložené údaje i historii.

Na stránce najdete pět záložek:

- **Přístup** — způsob přihlášení pro ruční načtení doručených zpráv,
- **Odchozí podání** — stav podání vytvořených v MyÚčtu,
- **Příchozí zprávy** — ručně načtená doručená pošta,
- **Příjemci** — ID datových schránek institucí,
- **Výzvy k odstranění vad** — evidence výzev a lhůt navázaných na podání.

## 93.1 Dvě oddělené cesty

MyÚčto rozlišuje odesílání a načítání doručené pošty:

1. **Odeslání přes bránu ISDS** přesměruje uživatele na oficiální přihlašovací stránku ISDS. Přihlašovací údaje se zadávají tam a MyÚčto je nepřijímá ani neukládá. Dostupné metody určuje ISDS podle účtu a aktuálního nastavení služby.
2. **Ruční načtení doručené pošty** používá přímé přihlášení z této stránky. MyÚčto načte zprávy jen po výslovném pokynu uživatele a podle zvolené metody.

Obě cesty jsou oddělené proto, že **brána doručenou poštu číst neumí**. Umí jen
vložit koncept, který uživatel po přihlášení v ISDS odešle; ke čtení schránky
by potřebovala přihlašovací údaje uživatele, a ty perimetr ISDS ani nesmí
opustit. Doručenku i odpověď proto vždy načtete nebo nahrajete zvlášť.

Globální registraci odesílací brány spravuje provozovatel systému v kapitole [Odesílací brána ISDS](94_Odesilaci_brana_ISDS.md). Firma její certifikát ani tajné údaje nevidí.

> [!NOTE]
> Datovou schránkou z MyÚčta chodí **mzdová podání** — přehledy a hlášení
> zdravotním pojišťovnám, měsíční hlášení zaměstnavatele ČSSZ (JMHZ) a
> součinnost exekutorům. **Daňová podání jdou přes EPO**, ne datovkou. Přiznání
> k DPH, kontrolní hlášení, souhrnné hlášení ani přiznání k dani z příjmů se
> odsud neodesílají. Odešlete-li daňové podání datovkou vlastní cestou,
> nedostanete potvrzení s podacím číslem, jen dodejku.

## 93.2 Přístup pro ruční načtení doručené pošty

### 93.2.1 Mobilní klíč eGovernmentu

Zadejte uživatelské jméno datové schránky a komunikační nebo aplikační heslo určené pro tento způsob přihlášení. Po spuštění přihlášení potvrďte požadavek v aplikaci Mobilní klíč eGovernmentu.

Přístup lze uložit šifrovaně pro kombinaci **firma + uživatel + prostředí**. Uložený profil jedné účetní není dostupný jiné účetní ani jiné firmě. Profil můžete kdykoli odstranit.

### 93.2.2 Uživatelské jméno a heslo

Heslo server přijme pouze pro právě spuštěné načtení. Požadavek proběhne synchronně a heslo se neukládá do databáze ani do nastavení firmy.

### 93.2.3 Uživatelské jméno, heslo a SMS kód

První krok zahájí krátkodobý jednorázový proces. Heslo zůstane po omezenou dobu šifrované na serveru; prohlížeč dostane jen náhodný neprůhledný token. Po zadání SMS kódu se proces atomicky spotřebuje. Má omezený počet pokusů a po vypršení jej musíte zahájit znovu. SMS kód se neukládá.

### 93.2.4 Systémový certifikát firmy

Nahrajte soubor **PFX/P12** obsahující klientský certifikát i odpovídající soukromý klíč. MyÚčto soubor při uložení ověří a odmítne balíček bez soukromého klíče. Certifikát a jeho heslo ukládá šifrovaně pro vybranou firmu a prostředí.

Technická dostupnost přihlašovací metody sama o sobě nepotvrzuje, že ji smíte použít pro konkrétní schránku nebo úkon. Oprávnění a interní pravidla organizace si ověřte u správce datové schránky.

## 93.3 Ruční načtení příchozích zpráv

Příchozí zprávy se nenačítají automaticky ani na pozadí. Postupujte takto:

1. Zvolte produkční nebo testovací prostředí.
2. Vyberte přihlašovací metodu.
3. Potvrďte upozornění, že přístup do schránky může způsobit doručení zpráv a začátek běhu právních lhůt.
4. Spusťte načtení a dokončete případné potvrzení v Mobilním klíči nebo zadání SMS kódu.
5. Zkontrolujte výsledek a nově uložené zprávy.

Server při jednom načtení projde dostupné stránky až do bezpečného limitu a duplicitní zprávy znovu neuloží. Pokud se nepodaří stáhnout nebo uložit všechny zprávy, MyÚčto zobrazí neúplný výsledek jako chybu. Načtení zopakujte až po kontrole stavu; prázdný seznam není důkazem, že ve schránce žádná zpráva není.

Kontrola stavu přihlášení Mobilním klíčem sleduje jen právě zahájený požadavek. Nejde o automatické sledování schránky.

## 93.4 Odchozí podání

Podání vytvořené v MyÚčtu nejprve získá stav **Připraveno**. Tento stav neznamená, že bylo odesláno.

### 93.4.1 Odeslání přes bránu ISDS

Pokud je pro zvolené prostředí aktivní brána:

1. Otevřete připravené podání a spusťte odeslání přes ISDS.
2. MyÚčto vytvoří krátkodobý koncept a přesměruje Vás na oficiální stránku ISDS.
3. Přihlaste se metodou, kterou Vám nabídne ISDS, zkontrolujte zprávu a odeslání tam výslovně potvrďte.
4. Po návratu do MyÚčta zkontrolujte výsledek a identifikátor datové zprávy.

Samotné přesměrování ani návrat na callback není potvrzením odeslání. Pokud je výsledek neurčitý, zprávu neposílejte znovu, dokud neověříte stav v datové schránce.

### 93.4.2 Odeslání přímo z aplikace v relaci Mobilního klíče

Vedle brány umí MyÚčto odeslat datovou zprávu **přímo**, ale jen za jedné
podmínky: musí to udělat **v živé relaci, kterou jste právě sám schválil**.
Prakticky to znamená přihlášení **Mobilním klíčem eGovernmentu** (jméno,
komunikační kód a potvrzení konkrétní relace v mobilu) nebo **SMS kódem**.
Potvrzení člověka je tady součástí přihlášení, takže odeslání v takové relaci
není odeslání bez jeho vědomí.

> ⚠️ Pozor: **systémový certifikát firmy ani uložené heslo odesílání
> neotevírají.** U obou by u odeslání nikdo nestál, proto je odesílací cesta pro
> ně uzavřená a aplikace odmítne dřív, než cokoli opustí server, větou, že
> odeslat lze jen v relaci potvrzené v Mobilním klíči nebo SMS kódem. Pro
> **čtení** schránky zůstávají certifikát i heslo použitelné dál; mění se jen
> odesílání. Zahájené, ale nedokončené přihlášení Mobilním klíčem se za živou
> relaci nepovažuje.

**Vypršelá relace se sama neobnovuje.** Skončí-li platnost během akce, aplikace
odeslání zastaví a vyzve Vás, ať se přihlásíte znovu a akci zopakujete. Novou
relaci si sama nevyrobí, protože by to nebyla ta, kterou jste schválil. Je to
bezpečná chyba: ISDS odmítne už v přihlášení, takže je **prokazatelné, že zpráva
neodešla**, a zopakování nehrozí duplicitou. Naproti tomu přerušené spojení
uprostřed odesílání je stav „nevím" - aplikace ho označí za nejistý, sama
neopakuje a upozorní, ať zprávu neposíláte znovu ručně, dokud se stav
nedohledá.

Relace nikde neleží uložená: žije jen po dobu jednoho požadavku, je vázaná na
firmu, uživatele a prostředí a po použití se zahazuje. Uložit lze nanejvýš
přihlašovací profil Mobilního klíče, ne samotnou relaci.

Před odesláním aplikace ověří schránku příjemce a odmítne znepřístupněnou,
zrušenou nebo vyřazenou. Proti dvojímu odeslání se nejdřív dohledá, jestli
zpráva se stejnou spisovou značkou už v posledních dvou dnech neodešla; pokud
ano, druhá se neposílá a vrátí se identifikátor té první.

Po odeslání dostanete **ID datové zprávy**. **Doručenka se nestahuje sama** ani
na pozadí: podání zůstane ve stavu odesláno bez doručenky, dokud ji sám
nenahrajete, nebo dokud ručně nenačtete příchozí zprávy. Doručení do schránky
příjemce navíc pořád nevypovídá o tom, jak úřad podání vyřídil.

> 🛈 Pozn: V tomto vydání je přímé odeslání zapnuté v jádře aplikace, ale
> obrazovka, která do něj živou relaci předá, teprve přijde. Do té doby Vás
> potvrzení podání dovede na bránu ISDS nebo na ruční postup níž.

### 93.4.3 Ruční odeslání

Když brána není dostupná, zůstává ruční postup:

1. Stáhněte přílohu z připraveného podání.
2. V klientu datové schránky ověřte správného příjemce a vložte korelační identifikátor do pole pro naši spisovou značku nebo referenci.
3. Zprávu odešlete a v MyÚčtu zapište ID datové zprávy.
4. Stáhněte doručenku ve formátu ZFO a nahrajte ji k podání.

MyÚčto vede zvlášť stav dopravy datové zprávy a věcný stav formuláře u cílové instituce. Doručená datová zpráva proto ještě neznamená, že instituce podání přijala bez výhrad. Nahraná doručenka se eviduje jako podklad; aplikace nezaručuje kryptografické ověření jejího podpisu.

## 93.5 Příjemci a výzvy

V záložce **Příjemci** zkontrolujte ID datové schránky cílové instituce. Výchozí adresář zdravotních pojišťoven lze přepsat pro konkrétní firmu; jiné instituce doplňte podle jejich aktuálních údajů. Před každým odesláním příjemce ověřte.

Výzvu k odstranění vad můžete založit z příchozí zprávy nebo ručně. Zapište vazbu na původní podání, datum doručení, lhůtu a výsledek opravy. Evidence výzvy sama žádnou odpověď neodešle.

## 93.6 Související kapitoly

- [Odesílací brána ISDS](94_Odesilaci_brana_ISDS.md) — globální registrace a provoz brány,
- [Podání a hlášení](68_Podani_a_hlaseni.md) — mzdové formuláře, jejich stavy a opravy,
- [Nastavení](92_Nastaveni.md) — ostatní nastavení firmy a systému.
