# 97. Datová schránka

> Návod, jak z MyÚčta odesílat mzdová a daňová podání datovou schránkou, načítat doručené zprávy, nahrávat
> doručenky a evidovat výzvy k odstranění vad. Pro účetní a každého, kdo za firmu pracuje s datovou schránkou.

## 97.1 Kdy to potřebujete

Kapitolu otevřete, když:

- máte připravený přehled nebo hlášení pro zdravotní pojišťovnu, ČSSZ nebo exekutora a potřebujete ho odeslat datovou schránkou,
- čekáte na doručenku k odeslanému podání nebo na odpověď úřadu,
- potřebujete načíst doručené zprávy ze schránky,
- zpráva nebo odeslání skončilo v nejistém stavu a nevíte, zda odešla,
- dostali jste výzvu k odstranění vad podání a hlídáte lhůtu,
- potřebujete zjistit, kam které zprávy odchází.

Nastavení datové schránky je vždy svázané s právě vybranou firmou. Otevřete ho přes `Mzdy → Datová schránka` (v nabídce
hned za Podáními a hlášeními). Produkční a testovací prostředí mají oddělené přístupy, uložené údaje i historii.

Na stránce jsou záložky:

- **Přístup**: způsob přihlášení k datové schránce a ruční načtení doručených zpráv,
- **Odchozí podání**: stav podání vytvořených v MyÚčtu,
- **Příchozí zprávy**: ručně načtená doručená pošta,
- **Příjemci**: ID datových schránek institucí,
- **Výzvy k odstranění vad**: evidence výzev a lhůt navázaných na podání.

Když v odchozích podáních něco čeká na odeslání nebo na doručenku, stránka se otevře rovnou na záložce **Odchozí
podání**. Delší vysvětlivky (doručenky, doručení fikcí, archiv a ochrana příchozích zpráv, výzvy) jsou sbalené pod
**Vysvětlení**.

> [!WARNING]
> Datovou schránkou z MyÚčta chodí **mzdová podání** (přehledy a hlášení zdravotním pojišťovnám, měsíční hlášení
> zaměstnavatele ČSSZ, součinnost exekutorům) a volitelně i **daňová podání**: přiznání k DPH, kontrolní hlášení,
> souhrnné hlášení a přiznání k dani z příjmů. Daňové podání se zařazuje ze stránky `Daně → EPO podání a archív`
> (viz [Podání datovou schránkou](49_Archiv_podani_a_rekonciliace.md#495-krok-za-krokem-podani-datovou-schrankou)).
> Datová schránka vrací jen doručenku, ne potvrzení s podacím číslem jako EPO.

## 97.2 Než začnete

1. **Oprávnění.** Stránka je dostupná uživatelům s oprávněním k podpisům a datové schránce. Firma s mzdami ji najde v nabídce Mzdy, každá firma v nabídce Daně.
2. **Správné prostředí.** Zvolte **Ostrý provoz**, nebo **Testovací prostředí**. Přístupy, uložené údaje i historie jsou oddělené.
3. **Způsob přihlášení.** Zvolte, jak se k schránce přihlásíte (viz [§ 97.3](#973-krok-za-krokem-nastavit-pristup)). Technická dostupnost metody sama o sobě nepotvrzuje, že ji smíte použít pro konkrétní schránku nebo úkon. Oprávnění a interní pravidla organizace si ověřte u správce datové schránky.
4. **Připravené podání.** Mzdové podání vzniká v `Mzdy → Podání a hlášení` (viz [Podání a hlášení](85_Podani_a_hlaseni.md)), daňové v `Daně → EPO podání a archív` (viz [EPO podání a archív](49_Archiv_podani_a_rekonciliace.md)).
5. **Příjemce s ID schránky.** Záložka **Příjemci** musí mít u cílové instituce vyplněné ID datové schránky. Schránky krajských finančních úřadů a Specializovaného finančního úřadu jsou v číselníku předvyplněné.

MyÚčto rozlišuje dvě oddělené cesty:

1. **Odeslání přes bránu ISDS** přesměruje uživatele na oficiální přihlašovací stránku ISDS. Přihlašovací údaje se zadávají tam a MyÚčto je nepřijímá ani neukládá. Dostupné metody určuje ISDS podle účtu a aktuálního nastavení služby.
2. **Ruční načtení doručené pošty** používá přímé přihlášení z této stránky. MyÚčto načte zprávy jen po výslovném pokynu uživatele a podle zvolené metody.

Obě cesty jsou oddělené proto, že **brána doručenou poštu číst neumí**. Umí jen vložit koncept, který uživatel po
přihlášení v ISDS odešle; ke čtení schránky by potřebovala přihlašovací údaje uživatele, a ty perimetr ISDS ani
nesmí opustit. Doručenku i odpověď proto vždy načtete nebo nahrajete zvlášť.

Globální registraci odesílací brány spravuje provozovatel systému v kapitole [Odesílací brána ISDS](98_Odesilaci_brana_ISDS.md).
Firma její certifikát ani tajné údaje nevidí.

## 97.3 Krok za krokem: nastavit přístup

Na záložce **Přístup** zvolte způsob přihlášení.

**Mobilní klíč eGovernmentu** (doporučeno):

1. Zadejte uživatelské jméno datové schránky a komunikační nebo aplikační heslo určené pro tento způsob přihlášení (komunikační kód, ne heslo do schránky).
2. Spusťte přihlášení tlačítkem **Požádat o potvrzení v klíči** a potvrďte relaci v aplikaci Mobilní klíč eGovernmentu.
3. Chcete-li přístup příště nezadávat, zaškrtněte **Zapamatovat šifrovaně pro tuto firmu a můj uživatelský účet**.

Přístup lze uložit šifrovaně pro kombinaci **firma + uživatel + prostředí**. Uložený profil jedné účetní není dostupný
jiné účetní ani jiné firmě. Profil můžete kdykoli odstranit tlačítkem **Zapomenout uložený přístup**.

**Jméno a heslo:** server heslo přijme pouze pro právě spuštěné načtení nebo odeslání. Požadavek proběhne synchronně
a heslo se neukládá do databáze ani do nastavení firmy.

**Jméno, heslo a SMS kód:** první krok zahájí krátkodobý jednorázový proces. Heslo zůstane po omezenou dobu šifrované
na serveru; prohlížeč dostane jen náhodný neprůhledný token. Po zadání SMS kódu se proces atomicky spotřebuje. Má
omezený počet pokusů a po vypršení jej musíte zahájit znovu. SMS kód se neukládá.

**Systémový certifikát firmy:**

1. V části **Systémový certifikát firmy** zvolte **Vybrat ze sdíleného trezoru** (certifikát nahraný v `Systém → E-maily a certifikáty`, záložka **Certifikáty a elektronické podpisy**, a povolený pro firmu), nebo **Nahrát soubor** (PFX/P12). Pokud firma ještě nemá uložený přístup a pro firmu je v trezoru povolený platný certifikát, aplikace ho předvybere sama. Když pro firmu v trezoru žádný certifikát povolený není, volba z trezoru nejde zvolit a pod ní se zobrazí vysvětlení.
2. Vyplňte **Označení**, **ID naší datové schránky** a **Heslo k certifikátu**.
3. Uložte. Přístup můžete odstranit tlačítkem **Smazat přístup**.

MyÚčto soubor při uložení ověří a odmítne balíček bez soukromého klíče (musí obsahovat klientský certifikát i odpovídající
soukromý klíč). Certifikát a jeho heslo ukládá šifrovaně pro vybranou firmu a prostředí. Uložený certifikát slouží
ke čtení schránky a po výslovném potvrzení i k odeslání podání (viz [§ 97.4](#974-krok-za-krokem-odeslat-podani)).

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Uloženo** a přístup je vidět na kartě Přístup (u certifikátu s datem platnosti).

## 97.4 Krok za krokem: odeslat podání

Podání vytvořené v MyÚčtu nejprve získá stav **Připraveno k odeslání**. Tento stav neznamená, že bylo odesláno.

Na záložce **Odchozí podání** klikněte u připraveného podání (i u podání, jehož předchozí pokus selhal) na **Odeslat**.
Rozbalí se výběr **Jak zprávu odeslat** s pěti cestami. Stejný výběr nabízí i odeslání daňového podání z Daně → EPO
podání a archív a mzdová podání, takže zprávu odešlete stejně odkudkoli. Doporučená cesta je označená štítkem
**Doporučeno**: odesílací brána, je-li pro zvolené prostředí zaregistrovaná, jinak Mobilní klíč.

| Cesta | Kdy je k dispozici | Co uděláte |
|---|---|---|
| **Odesílací brána** | Provozovatel aplikace bránu pro zvolené prostředí zaregistroval a zapnul. Jinak je volba zašedlá s vysvětlením. | Přihlášení a schválení proběhne v ISDS. |
| **Mobilní klíč eGovernmentu** | Vždy. | Potvrdíte odeslání v mobilu. |
| **Jméno a heslo** | Vždy. | Zadáte přihlašovací údaje pro toto jedno odeslání. |
| **Jméno, heslo a SMS kód** | Vždy; pro účty chráněné jednorázovým kódem z SMS. | Zadáte jméno a heslo, pak kód z SMS. |
| **Systémový certifikát** | Firma má na záložce **Přístup** uložený systémový certifikát. Jinak je volba zašedlá s odkazem **Nastavit přístup**. | Odeslání výslovně potvrdíte. |

**Odesílací brána:**

1. Zvolte **Odesílací brána** a klikněte na **Připravit v datové schránce**.
2. MyÚčto vytvoří krátkodobý koncept a ukáže, jak se v ISDS přihlásíte. Klikněte na **Pokračovat do ISDS** a budete
   přesměrováni na oficiální stránku ISDS.
3. Přihlaste se metodou, kterou vám nabídne ISDS, zkontrolujte zprávu a odeslání tam výslovně potvrďte.
4. Po návratu do MyÚčta (stránka **Návrat z datové schránky**) zkontrolujte výsledek a identifikátor datové zprávy.

Samotné přesměrování ani návrat na callback není potvrzením odeslání. Pokud je výsledek neurčitý, zprávu neposílejte
znovu, dokud neověříte stav v datové schránce.

**Mobilní klíč eGovernmentu:**

1. Zvolte **Mobilní klíč eGovernmentu**.
2. Zadejte **Uživatelské jméno ISDS** a **Komunikační kód / heslo pro aplikaci** (nebo zvolte **Použít uložené přihlášení
   této firmy**). Zaškrtnutím **Zapamatovat šifrovaně pro tuto firmu a můj uživatelský účet** si jméno a kód uložíte
   pro příští odeslání i čtení.
3. Klikněte na **Požádat o potvrzení v klíči** a potvrďte odeslání v mobilu.
4. Více podání najednou odešle hromadné tlačítko (viz [Podání a hlášení](85_Podani_a_hlaseni.md)).

**Jméno a heslo:**

1. Zvolte **Jméno a heslo**.
2. Zadejte **Uživatelské jméno ISDS** a **Heslo k datové schránce**.
3. Klikněte na **Přihlásit a odeslat**.

Přihlášení platí jen pro toto jedno odeslání. Heslo projde do ISDS a nikam se neukládá, proto ho zadáváte pokaždé
znovu. Uložené heslo k odeslání použít nejde.

**Jméno, heslo a SMS kód:**

1. Zvolte **Jméno, heslo a SMS kód**.
2. Zadejte **Uživatelské jméno ISDS** a **Heslo k datové schránce** a klikněte na **Odeslat SMS**. ISDS pošle kód na
   telefon registrovaný u účtu.
3. Opište **Kód z SMS** a klikněte na **Potvrdit a odeslat**. Když kód nedorazí nebo vyprší, klikněte na **Vyžádat nový
   SMS kód** a začněte znovu.

Mezi oběma kroky drží aplikace heslo jen šifrovaně v jednorázovém přihlášení, které platí pět minut a po použití se
zahodí. Po odeslání se aplikace z relace ISDS odhlásí.

**Systémový certifikát:**

1. Zvolte **Systémový certifikát**.
2. Zaškrtněte potvrzení, že zprávu odesíláte systémovým certifikátem firmy, a klikněte na **Odeslat certifikátem**.
   Za certifikátem nestojí člověk, proto odeslání potvrzujete vy a potvrzení se eviduje.

Odesílat datovou schránkou smí jen uživatel s oprávněním k elektronickým podpisům a datové schránce. Bez něj aplikace
při pokusu o odeslání vysvětlí, co chybí, a nabídne odkaz do odchozích podání, odkud zprávu odešle kolega s oprávněním
nebo ji tam zapíšete jako odeslanou ručně.

**Ruční odeslání** (když žádnou z cest v aplikaci použít nechcete):

1. Stáhněte přílohu z připraveného podání.
2. V klientu datové schránky ověřte správného příjemce a vložte korelační identifikátor (spisovou značku) do pole „Naše číslo jednací".
3. Zprávu odešlete.
4. V MyÚčtu klikněte u podání na **Označit jako odesláno ručně**, zapište **ID zprávy z datové schránky** a potvrďte **Zapsat odeslání**.
5. Stáhněte doručenku ve formátu ZFO a nahrajte ji tlačítkem **Nahrát doručenku**.

**Jak poznáte, že je hotovo:** Podání má stav **Odesláno, čeká na doručenku** a po doručence **Doručeno**. Doručená datová
zpráva ale ještě neznamená, že instituce podání přijala bez výhrad (viz [§ 97.10.1](#97101-stavy-podani-a-doruceni)).
Výjimkou jsou přehledy a hromadná oznámení zdravotním pojišťovnám: pojišťovna výsledek neposílá, proto je povinnost
splněná samotným doručením a podání ukazuje **Splněno doručením**.

> [!WARNING]
> Přímé odeslání vždy vyžaduje váš úkon u konkrétního podání: potvrzení relace v Mobilním klíči, zadání hesla pro
> toto jedno odeslání, ověření kódem z SMS, nebo výslovné potvrzení odeslání certifikátem. Samo, na pozadí ani z uloženého hesla aplikace
> nic neodešle. Zahájené, ale nedokončené přihlášení Mobilním klíčem se za přihlášení nepovažuje a aplikace odeslání
> odmítne dřív, než cokoli opustí server.

## 97.5 Krok za krokem: načíst doručenky a příchozí zprávy

**Doručenky ke všem čekajícím podáním:**

Načtením příchozích zpráv se v téže relaci stáhnou i doručenky čekajících podání. Když chcete jen doručenky, bez vyzvednutí schránky:

1. Na záložce **Odchozí podání** klikněte na **Načíst hromadně doručenky**. Aplikace si vyžádá dodejky ke všem odeslaným zprávám, které je ještě nemají.
2. Klikněte na **Přihlásit a stáhnout doručenky** (u SMS na **Ověřit SMS a stáhnout doručenky**).
3. Zkontrolujte shrnutí: připojeno, zatím bez dodejky, nepodařilo se zeptat.

Doručenku k jednomu podání nahrajete tlačítkem **Nahrát doručenku**. Nezařazené doručenky jsou uložené v části **Nezařazené
doručenky**: vyberte podání, ke kterému patří (**Nabídnout podání**, **Přiřadit k tomuhle podání**).

**Příchozí zprávy:**

Příchozí zprávy se nenačítají automaticky ani na pozadí. Postupujte takto:

1. Otevřete záložku **Příchozí zprávy**.
2. Zvolte produkční nebo testovací prostředí.
3. Vyberte přihlašovací metodu (**Mobilní klíč eGovernmentu**, **Jméno a heslo**, **Jméno, heslo a SMS kód**, nebo **Systémový certifikát firmy**).
4. Klikněte na **Vyzvednout nové zprávy** a potvrďte upozornění, že přístup do schránky může způsobit doručení zpráv a začátek běhu právních lhůt.
5. Dokončete případné potvrzení v Mobilním klíči nebo zadejte jednorázový SMS kód a klikněte na **Přihlásit a jednou načíst** (u SMS na **Ověřit SMS a jednou načíst**).
6. Zkontrolujte výsledek a nově uložené zprávy.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Staženo nových zpráv** s počtem a zprávy jsou v seznamu (sloupce
**Předmět**, **Odesílatel**, **Kategorie**, **Zařazení**, **Doručeno**). Zprávu otevřete tlačítkem **Otevřít zprávu
a přílohy**. Otevřená zpráva se označí jako přečtená.

**Kategorie, hledání a filtry příchozích zpráv:**

Každou zprávu MyÚčto zařadí do kategorie podle odesílatele a typu zprávy: **Finanční a celní správa**, **Sociální
zabezpečení**, **Zdravotní pojišťovny**, **Soudy a exekutoři**, **Ostatní úřady**, **Obchodní partneři**, **Systém
datových schránek**, **Vlastní podání a doručenky** a **Ostatní**. Rozhoduje ID schránky odesílatele z číselníku
příjemců, typ schránky (orgán veřejné moci, exekutor, firma), název odesílatele a rozpoznaný typ zprávy (protokol
ČSSZ, odpověď pojišťovny). U nového odesílatele aplikace založí pravidlo pro jeho schránku, takže jeho další zprávy
skončí ve stejné kategorii. Zprávy stažené dřív se zařadí samy při prvním otevření seznamu.

1. Na záložce **Příchozí zprávy** vyberte vlevo kategorii (na telefonu nad seznamem). U každé kategorie je počet
   zpráv a počet nepřečtených. Prázdné kategorie se v přehledu neukazují.
2. Do pole **Hledat ve zprávách** napište část věci, jména odesílatele, čísla jednacího, spisové značky nebo názvu
   přílohy a potvrďte klávesou Enter. Na velikosti písmen ani diakritice nezáleží; více slov musí najít všechna.
3. Pod tlačítkem **Filtry** zúžíte seznam podle odesílatele, typu zprávy, směru (**Přijaté**, **Odeslané a
   doručenky**), přečtení a příloh. Rychlý filtr **měsíc a rok** zůstává nad seznamem.
4. Řazení změníte kliknutím na záhlaví sloupce **Předmět**, **Odesílatel**, **Kategorie** nebo **Doručeno** (druhé
   kliknutí obrátí směr). Na telefonu je řazení v nabídce **Řadit podle**.
5. Zprávu do jiné kategorie přesunete tlačítkem **Přeřadit**. Když zaškrtnete **Zařazovat sem i další zprávy od
   odesílatele**, vznikne pravidlo a přeřadí se i ostatní zprávy téhož odesílatele.
6. Tlačítkem **Kategorie a pravidla** otevřete správu: systémové kategorie přejmenujete (prázdný název vrátí výchozí),
   vlastní kategorie založíte nebo smažete a u pravidel změníte cílovou kategorii nebo přidáte nové pravidlo podle
   schránky odesílatele, části jména odesílatele nebo části věci. Přejmenování a přesměrování pravidel uložíte
   tlačítkem **Uložit změny**.

Zvolené filtry jsou součástí adresy stránky, takže výběr (například nepřečtené zprávy od finanční správy za březen)
můžete uložit do záložek nebo poslat kolegovi. Stav přečtení je společný pro celou firmu.

> [!NOTE]
> Ručně přeřazenou zprávu pravidla nepřesunou. Pravidlo podle věci má přednost před pravidlem podle schránky a to
> před pravidlem podle jména odesílatele. Smazáním vlastní kategorie se její zprávy zařadí znovu automaticky.

Server při jednom načtení projde dostupné stránky až do bezpečného limitu a duplicitní zprávy znovu neuloží. Pokud se
nepodaří stáhnout nebo uložit všechny zprávy, MyÚčto zobrazí neúplný výsledek jako chybu. Načtení zopakujte až po
kontrole stavu; prázdný seznam není důkazem, že ve schránce žádná zpráva není. Kontrola stavu přihlášení Mobilním
klíčem sleduje jen právě zahájený požadavek, nejde o automatické sledování schránky.

## 97.6 Krok za krokem: když není jisté, zda zpráva odešla

1. Na záložce **Odchozí podání** najděte podání se stavem **Nevíme, jestli odešlo**.
2. Klikněte na **Dohledat, jestli odešlo**.
3. Podle výsledku: zpráva v odeslaných našla (podání je odesláno), nebo tam není (podání neodešlo a můžete ho bezpečně zařadit znovu), nebo se nepodařilo dovolat (zkuste později).

**Jak poznáte, že je hotovo:** Stav podání je jednoznačný (odesláno, nebo neodesláno).

> [!WARNING]
> Zprávu neodesílejte znovu ručně, dokud se stav nedohledá, mohlo by vzniknout dvojí podání.

## 97.7 Krok za krokem: zrušit nebo smazat odchozí zprávu, filtrovat seznamy

**Zrušit** stáhne zpátky odchozí zprávu, která ještě neodešla:

1. Na záložce **Odchozí podání** klikněte u zprávy na **Zrušit**.
2. Podání tím nezmizí. Hlášení dál není podané a čeká na odeslání, jen se přestane nabízet v této frontě. U zrušené zprávy je vidět věta, které agendy a období se to týká, a odkaz **Otevřít podání**.

**Smazat** zrušenou zprávu můžete jen tehdy, když **nikdy neopustila aplikaci**. Tlačítko **Smazat** se nabídne pouze
u zprávy bez jediné stopy po odeslání: bez ID datové zprávy, bez doručenky, bez navázané příchozí zprávy, bez záznamu
v historii pokusů, bez relace odesílací brány a bez navazující výzvy či podání. Když některá z těch stop existuje,
tlačítko se nenabídne vůbec a u řádku je jednou větou napsané proč: doklad o skutečně podaném podání smazat nelze.
Smazání se zapisuje do auditní stopy včetně agendy, období a spisové značky, aby bylo zpětně poznat, co zmizelo
a kdo to smazal. Smazáním se povinnost nesplní. Podání se vrátí mezi nesplněné přesně tak jako po zrušení a fronta
mzdových podání ho zase nabídne k zařazení.

**Listování a filtr podle měsíce.** Odchozí podání ani příchozí zprávy se nemažou, takže obojí seznam roste každý
měsíc. Zobrazuje se proto po stránkách a nad každým seznamem je rychlý filtr **měsíc a rok**. Rok je předvolený na
aktuální, aby bylo poznat, o který měsíc jde. Vybraný samotný rok zobrazí celý rok, samotný měsíc týž měsíc napříč
roky. Volbou **Zrušit filtr** se vrátíte na celou historii. V nabídce roků jsou jen roky, ve kterých firma opravdu
něco má. Stejný filtr je i v přehledu mzdových podání (`Mzdy → Podání a hlášení`, záložka **Odesláno**), takže se
hledá všude stejně.

**Jak poznáte, že je hotovo:** Zpráva je ve stavu **Zrušeno**, případně zmizela ze seznamu.

## 97.8 Krok za krokem: příjemci a výzvy k odstranění vad

**Příjemci:**

1. Otevřete záložku **Příjemci** a zkontrolujte **ID datové schránky** cílové instituce.
2. Chcete-li výchozího příjemce (například zdravotní pojišťovnu) upravit pro firmu, klikněte na **Upravit pro firmu**. Uložením vznikne firemní varianta, která překryje systémový výchozí záznam. Smazáním firemní varianty se obnoví aktuální výchozí hodnota.
3. Nového příjemce přidáte tlačítkem **Přidat příjemce**.

Výchozí adresář zdravotních pojišťoven (všech sedm je předvyplněno z oficiálního číselníku VZP) lze přepsat pro
konkrétní firmu; jiné instituce doplňte podle jejich aktuálních údajů. Záznamy bez ID datové schránky jsou označené
a nejde na ně odeslat podání, dokud ID nedoplníte. Před každým odesláním příjemce ověřte.

**Výzva k odstranění vad:**

1. Otevřete záložku **Výzvy k odstranění vad**.
2. Klikněte na **Zaevidovat výzvu**, nebo u příchozí zprávy na **Zaevidovat jako výzvu**.
3. Vyplňte **Číslo jednací**, **Jaká vada (§ 74 odst. 1)**, **Den doručení výzvy**, **Do kdy reagovat (datum z výzvy)** nebo **Nebo počet dnů uvedený ve výzvě** a poznámku, a klikněte na **Uložit výzvu**.
4. Až vadu odstraníte, klikněte na **Zaznamenat odstranění vady**, zadejte **Kdy vada odstraněna** a uložte.

**Jak poznáte, že je hotovo:** Výzva je v seznamu se stavem (**Lhůta běží**, **Odstraněno ve lhůtě**…). Evidence výzvy
sama žádnou odpověď neodešle. Zapište také vazbu na původní podání.

## 97.9 Když něco nejde

<!-- cols: 34 32 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Volba **Odesílací brána** je zašedlá | Provozovatel ji pro prostředí nezaregistroval nebo neaktivoval | Použijte jinou cestu nebo ruční odeslání. Správce viz [Odesílací brána ISDS](98_Odesilaci_brana_ISDS.md). |
| Volba **Systémový certifikát** je zašedlá | Na záložce **Přístup** není pro toto prostředí uložený systémový certifikát firmy | Uložte certifikát ([§ 97.3](#973-krok-za-krokem-nastavit-pristup)), nebo odešlete jinou cestou. |
| Odeslání skončí hláškou o chybějícím oprávnění | Účet nemá oprávnění k elektronickým podpisům a datové schránce | Požádejte správce o oprávnění, nebo zprávu zapište v odchozích podáních jako odeslanou ručně. |
| SMS kód ISDS odmítl nebo vypršel | Kód platí krátce a přihlášení snese jen pět pokusů | Klikněte na **Vyžádat nový SMS kód** a začněte znovu. |
| „Přihlášení k datové schránce není dokončené, takže se odesílat nesmí.“ | Přihlášení Mobilním klíčem nebylo potvrzené | Potvrďte přihlášení a odeslání spusťte znovu. |
| Relace vypršela během odeslání | Relace Mobilního klíče žije jen pár minut | Přihlaste se znovu a akci zopakujte. Zpráva prokazatelně neodešla, duplicita nehrozí. |
| Stav **Nevíme, jestli odešlo** | Spojení se přerušilo uprostřed odesílání | Použijte **Dohledat, jestli odešlo** ([§ 97.6](#976-krok-za-krokem-kdyz-neni-jiste-zda-zprava-odesla)). |
| Doručenka se nenačetla | Úřad zprávu ještě nepřevzal, nebo jste od odeslání schránku nenačetli | Načtěte příchozí zprávy nebo klikněte na **Načíst hromadně doručenky**, případně ji nahrajte ručně. |
| Prázdný seznam příchozích zpráv | Neúplné načtení nebo schránka nedostupná | Zopakujte načtení po kontrole stavu. Prázdný seznam není důkazem, že zprávy nejsou. |
| Neúplné načtení: u některých zpráv se stažení nezdařilo | Chyba stažení nebo uložení | Načtení zopakujte. |
| Příjemce nejde zvolit | U záznamu chybí ID datové schránky | Doplňte ID v záložce **Příjemci**. |
| Smazat se nenabízí | Zpráva opustila aplikaci nebo má stopy po odeslání | Doklad o skutečně podaném podání smazat nelze. |
| Odeslání skončilo neurčitě po návratu z ISDS | ISDS výsledek nepotvrdilo | Zprávu neposílejte znovu, dokud neověříte stav v datové schránce. |

## 97.10 Podrobnosti a pravidla

### 97.10.1 Stavy podání a doručení

MyÚčto vede zvlášť stav dopravy datové zprávy a věcný stav formuláře u cílové instituce. Doručená datová zpráva proto
ještě neznamená, že instituce podání přijala bez výhrad. Nahraná doručenka se eviduje jako podklad; aplikace
nezaručuje kryptografické ověření jejího podpisu.

### 97.10.2 Přímé odeslání z relace

Vedle brány umí MyÚčto odeslat datovou zprávu přímo. Odeslání nabízí stejné způsoby přihlášení jako čtení schránky
a vždy za ním stojí váš úkon u konkrétního podání:

- **Mobilní klíč eGovernmentu** (jméno, komunikační kód a potvrzení konkrétní relace v mobilu) nebo **jméno, heslo
  a SMS kód** (ISDS vydá relaci až po ověření jednorázového kódu): potvrzení člověka je součástí přihlášení, takže
  odeslání v takové relaci není odeslání bez jeho vědomí. Relaci ověřenou SMS kódem aplikace po odeslání hned
  ukončí odhlášením.
- **Jméno a heslo**: heslo zadáváte pro jedno odeslání, ISDS ověří každý požadavek zvlášť a heslo se nikam neukládá.
- **Systémový certifikát firmy**: za certifikátem nestojí člověk, proto odeslání výslovně potvrzujete v aplikaci
  (podobně jako u EPO) a potvrzení jde doložit.

Bez potvrzení nebo bez kompletních přihlašovacích údajů aplikace odeslání odmítne dřív, než cokoli opustí server.

**Vypršelá relace se sama neobnovuje.** Skončí-li platnost během akce, aplikace odeslání zastaví a vyzve vás, ať se
přihlásíte znovu a akci zopakujete. Novou relaci si sama nevyrobí, protože by to nebyla ta, kterou jste schválili.
Je to bezpečná chyba: ISDS odmítne už v přihlášení, takže je prokazatelné, že zpráva neodešla, a zopakování nehrozí
duplicitou. Naproti tomu přerušené spojení uprostřed odesílání je stav „nevím": aplikace ho označí za nejistý, sama
neopakuje a upozorní, ať zprávu neposíláte znovu ručně, dokud se stav nedohledá.

Relace nikde neleží uložená: žije jen po dobu jednoho požadavku, je vázaná na firmu, uživatele a prostředí a po
použití se zahazuje. Uložit lze nanejvýš přihlašovací profil Mobilního klíče, ne samotnou relaci.

Před odesláním aplikace ověří schránku příjemce a odmítne znepřístupněnou, zrušenou nebo vyřazenou. Proti dvojímu
odeslání se nejdřív dohledá, jestli zpráva se stejnou spisovou značkou už v posledních dvou dnech neodešla; pokud
ano, druhá se neposílá a vrátí se identifikátor té první.

Po odeslání dostanete **ID datové zprávy**. Doručenka se nestahuje sama ani na pozadí: podání zůstane ve stavu odesláno
bez doručenky, dokud ji sami nenahrajete, nebo dokud ručně nenačtete příchozí zprávy. Doručení do schránky příjemce
navíc pořád nevypovídá o tom, jak úřad podání vyřídil.

### 97.10.3 Příjemci a výzvy

Výzvu k odstranění vad lze založit z příchozí zprávy nebo ručně. Zapište vazbu na původní podání, datum doručení,
lhůtu a výsledek opravy. Lhůtu si aplikace nedomýšlí: zákon žádnou délku nestanoví, určuje ji správce daně ve výzvě.

## 97.11 Související kapitoly

- [Odesílací brána ISDS](98_Odesilaci_brana_ISDS.md): globální registrace a provoz brány,
- [Podání a hlášení](85_Podani_a_hlaseni.md): mzdové formuláře, jejich stavy a opravy,
- [Nastavení](96_Nastaveni.md): ostatní nastavení firmy a systému.
