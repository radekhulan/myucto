# 31. Platební karty

> Návod, jak vést firemní platební karty a jejich držitele, jak k platbám kartou
> doložit účtenku nebo fakturu a jak je účtovat. Pro účetní a každého, kdo
> za firmu platí kartou. Úvěrový účet ke kreditní kartě a jeho výpisy vede
> kapitola [Kreditní karty](112_Kreditni_karty.md).

## 31.1 Kdy to potřebujete

- Firma dostala novou platební kartu a chcete poznat, kdo s ní platí.
- Karta se vyměnila, skončila platnost, nebo ji už nepoužíváte.
- V bankovním výpisu jsou platby kartou, ke kterým chybí účtenka nebo faktura.
- Zaměstnanec zaplatil kartou soukromý nákup.
- Nákup kartou nemá doklad a nikdy mít nebude.
- Chcete, aby zůstatek mezičlenu vždy ukazoval platby kartou bez dokladu.
- Zaměstnanec tankuje kartou a tankování se má přiřadit jeho vozidlu.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| nová karta | Založit kartu s koncovkou a držitelem | `Peníze → Platební karty`, **Nová karta** |
| po načtení bankovního výpisu | Projít platby kartou bez dokladu | záložka **Platby bez dokladu** |
| jednou měsíčně | Dořešit platby starší než nastavená lhůta | záložka **Platby bez dokladu**, upozornění v měsíční kontrole |
| výměna nebo konec karty | Archivovat kartu, novou založit s navazující platností | záložka **Karty** |
| import výpisu založil neověřenou kartu | Doplnit název a držitele a kartu uložit | záložka **Karty** |

## 31.2 Než začnete

1. **Bankovní účet.** Karta se váže na měnový účet firmy, ze kterého se platby strhávají. Účty vedete v `Peníze → Bankovní účty` (viz [Bankovní účty](30_Bankovni_ucty.md)).
2. **Načtené výpisy.** Platby kartou aplikace pozná z bankovních výpisů, proto je nejdřív načtěte (viz [Banka](29_Banka.md)).
3. **Oprávnění.** Evidenci karet vidí a upravují uživatelé s oprávněním ke správě bankovních účtů firmy. Přehled plateb bez dokladu vyžaduje přístup k bance, nahrání účtenky oprávnění k nahrávání přijatých dokladů a spárování oprávnění k párování bankovních pohybů. Zaúčtovat nebo uzavřít platbu bez dokladu a změnit nastavení účtování smí jen ten, kdo smí zaúčtovat bankovní pohyby.
4. **Podvojné účetnictví** pro účtování přes mezičlen. Firma v daňové evidenci kartu vede, ale mezičlen nepoužívá (viz [§ 31.9.8](#3198-danova-evidence)).

> [!WARNING]
> Aplikace **nikdy neukládá celé číslo karty**. Stačí poslední čtyři číslice. Vložíte-li do pole **Poslední 4 číslice** celé číslo, uloží se jen poslední čtyři číslice a aplikace vás na to upozorní. Pole **Název karty**, **Jméno držitele** a **Poznámka** celé číslo karty odmítnou.

## 31.3 Krok za krokem: založení karty

1. Otevřete `Peníze → Platební karty` a na záložce **Karty** klikněte na **Nová karta**.
2. V části **Karta** vyplňte **Název karty** (například „Tankovací karta obchod“), **Poslední 4 číslice** z maskovaného čísla karty, **Typ karty** a případně **Karetní asociaci**.
3. V části **Držitel** zadejte **Jméno držitele**, případně vyberte **Zaměstnanec** nebo **Uživatel aplikace**. Vybraný zaměstnanec nebo uživatel musí patřit do stejné firmy.
4. V části **Účet a platnost** vyberte **Bankovní účet** a vyplňte **Platnost od** a **Platnost do**. Prázdná platnost znamená bez omezení.
5. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Karta je v seznamu se stavem **Aktivní** a koncovkou ve tvaru `•••• 1234`. U platby kartou ve výpisu se zobrazí její název nebo držitel.

Kartu, kterou nechcete používat, ale chcete ji v evidenci ponechat, vypněte volbou **Aktivní karta** (neaktivní karta zůstane v evidenci, jen se nepoužívá).

## 31.4 Krok za krokem: výměna nebo archivace karty

Dvě karty se stejnou koncovkou nesmí u jedné firmy platit ve stejném období, jinak by nešlo určit, čí platba to byla.

1. Otevřete kartu, která končí, a vyplňte **Platnost do**. Klikněte na **Uložit**.
2. Novou kartu se stejnou koncovkou založte s platností navazující na tu původní (**Platnost od** den po skončení původní).
3. Starou kartu, kterou už nepoužijete, otevřete a klikněte na **Archivovat**. Potvrďte.

**Jak poznáte, že je hotovo:** Archivovaná karta zmizela ze seznamu. Zobrazíte ji zaškrtnutím **Zobrazit archivované** a tlačítkem **Obnovit** ji vrátíte. Platby z minulosti zůstávají přiřazené původní kartě.

> [!TIP]
> Archivace kartu nemaže. Pokud karta neměla vyplněnou platnost do, doplní se dnešním datem.

## 31.5 Krok za krokem: doložení platby kartou bez dokladu

Platby kartou, ke kterým zatím není spárovaný doklad, najdete na záložce **Platby bez dokladu**. Jsou seskupené podle karty a držitele, karty mimo evidenci jsou na konci.

1. Otevřete `Peníze → Platební karty`, záložku **Platby bez dokladu**.
2. Zvolte období (**Od**, **Do**) a klikněte na **Načíst**.
3. U platby klikněte na **Nahrát účtenku** a vyberte PDF nebo fotografii účtenky. Doklad se vytěží stejně jako při AI importu přijaté faktury, dostane formu úhrady „karta“ a koncovku karty z platby.
4. Klikněte na **Otevřít doklad**, vytěžený doklad zkontrolujte a potvrďte.
5. Vraťte se na záložku **Platby bez dokladu** a u platby klikněte na **Spárovat**.

**Jak poznáte, že je hotovo:** Platba z přehledu zmizela a zobrazí se hláška „Platba spárována s dokladem“. Je-li zapnutý mezičlen, platba se zaúčtuje i s vypořádáním.

Co dělat, když účtenka nejde vytěžit nebo AI není zapnutá: účtenka se uloží do [Příchozích dokladů](23_Prijate_faktury.md) (v Dokumentech složka Příchozí doklady / rok / měsíc) navázaná na platbu a doklad z ní založíte tam. Použijte odkaz **Otevřít příchozí doklady**.

> [!TIP]
> Doklad nemusí být v aplikaci dřív než platba. Když přijde později, spáruje se s platbou své karty sám, jakmile je přijatý (viz [§ 31.9.3](#3193-parovani-plateb-kartou)).

## 31.6 Krok za krokem: uzavření platby, ke které doklad nebude

Použijte, když doklad nikdy nedorazí. Tři akce (**Bez dokladu, nedaňově**, **Bez dokladu, daňově**, **K tíži držitele**) se nabízejí jen u platby zaúčtované přes mezičlen karty (podvojné účetnictví) a s oprávněním zaúčtovat bankovní pohyby.

1. Na záložce **Platby bez dokladu** najděte platbu.
2. Vyberte podle situace:
   - **Bez dokladu, nedaňově** pro výdaj, který nejde uplatnit (chybí průkazný doklad, pokuta, výdaj bez souvislosti s podnikáním),
   - **Bez dokladu, daňově** jen tam, kde výdaj prokážete jinak než přijatým dokladem (smlouva, potvrzení objednávky, výpis služby),
   - **K tíži držitele** pro soukromý nákup kartou firmy.
3. V dialogu zkontrolujte datum, obchodníka a částku. Chcete-li, vyberte jiný **Účet** než výchozí.
4. Potvrďte.

**Jak poznáte, že je hotovo:** Platba z přehledu zmizela, v účetním deníku je zápis s číslem **KARTA-** a číslem pohybu. Zápis má datum platby; spadá-li platba do uzavřeného období, má první den otevřeného období.

> [!TIP]
> Uzavření není konečné. Když k platbě později dorazí doklad a platba se s ním spáruje, uzavření se samo stornuje. Omylem uzavřenou platbu vrátíte stornem zápisu KARTA-… v účetním deníku; platba se pak v přehledu znovu objeví.

U **K tíži držitele** vznikne jen účetní zápis (pohledávka za držitelem), žádný doklad ani srážka ze mzdy. Vrácení peněz držitelem zaúčtujete běžně z banky nebo pokladny proti stejnému účtu.

## 31.7 Krok za krokem: zapnutí účtování přes mezičlen

V podvojném účetnictví můžete platby kartou účtovat **přes mezičlen s analytikou pro každou kartu**. Platba se pak z výpisu zaúčtuje hned, i když k ní ještě není doklad, a zůstatek analytiky karty vždy ukazuje platby, ke kterým doklad chybí.

1. Otevřete `Peníze → Platební karty`, záložku **Nastavení účtování**.
2. Zapněte **Účtovat platby kartou přes mezičlen**.
3. Zkontrolujte **Platí od** (výchozí je začátek prvního otevřeného období) a **Mezičlen** (výchozí je 378).
4. Zkontrolujte účty pro uzavření bez dokladu, pohledávku za držitelem a kurzové a haléřové rozdíly (viz [§ 31.9.7.3](#31973-nastaveni-uctovani)).
5. Klikněte na **Uložit nastavení**.

**Jak poznáte, že je hotovo:** Nové platby kartou se z výpisu zaúčtují hned na analytiku karty (například 378.101). V detailu karty vidíte její analytiku a zůstatek.

> [!WARNING]
> Historie se nepřeúčtovává. Platby před datem účinnosti zůstávají zaúčtované tak, jak byly. Zaúčtovaná platba drží režim, ve kterém se zaúčtovala. Nese-li původní analytika zůstatek, uložení změny chce potvrzení a zůstatek na původním účtu vypořádáte ručně.

## 31.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Zadané číslo bylo delší, uložili jsme jen poslední čtyři číslice.“ | Do pole koncovky jste vložili celé číslo karty | Zkontrolujte, že uložená koncovka odpovídá kartě. |
| Karta se nedá uložit, překrývá se s jinou | Dvě karty se stejnou koncovkou platí ve stejném období | Upravte **Platnost do** původní karty nebo **Platnost od** nové. |
| Platba kartou se nespárovala s dokladem | Doklad jiné karty, jiná částka, nebo je doklad mimo časové okno | Zkontrolujte koncovku na dokladu a datum. Pak klikněte na **Spárovat** (pravidla v [§ 31.9.3](#3193-parovani-plateb-kartou)). |
| „Nalezen možný doklad. Potvrďte ho v detailu výpisu.“ | Podobný doklad je jen návrh | Klikněte na **Výpis** a návrh potvrďte. |
| „Doklad k platbě zatím nenalezen.“ | Doklad ještě není přijatý | Nahrajte účtenku, nebo počkejte na doklad. |
| Tlačítka **Bez dokladu** a **K tíži držitele** chybí | Platba není zaúčtovaná přes mezičlen, nebo nemáte oprávnění zaúčtovat bankovní pohyby | Zapněte mezičlen (viz [§ 31.7](#317-krok-za-krokem-zapnuti-uctovani-pres-meziclen)), nebo požádejte o oprávnění. |
| Platba není na záložce **Platby bez dokladu** | Je to nákup kreditní kartou, nebo je platba už uzavřená | Nákupy kreditní kartou řešte v detailu kreditní karty ([Kreditní karty](112_Kreditni_karty.md)). |
| Karta označená **Neověřená** | Import výpisu našel koncovku, kterou jste neevidovali | Doplňte název a držitele a kartu uložte, nebo klikněte na **Ověřit kartu**. |
| Platba na analytice 378.199 Neevidované karty | Zakládání neznámých karet je vypnuté, nebo se karta nedala k datu platby určit | Založte kartu s platností k datu platby, nebo zapněte **Zakládat neznámé karty automaticky**. |
| Storno uzavření nebo vypořádání nejde provést | Období je uzavřené nebo zamčené | Otevřete období, nebo se obraťte na toho, kdo ho uzavřel. |
| U platby na čerpací stanici není vidět vozidlo | Držitel řídí víc vozidel, nebo žádné | Na platbě je to napsané, vozidlo se neurčí (viz [§ 31.9.6](#3196-vozidlo-podle-karty)). |

## 31.9 Podrobnosti a pravidla

### 31.9.1 Co se o kartě eviduje

<!-- cols: 30 70 -->
| Pole | Význam |
|---|---|
| **Název karty** | vaše pojmenování, např. „Tankovací karta obchod" |
| **Poslední 4 číslice** | koncovka z maskovaného čísla karty |
| **Typ karty** | debetní, kreditní, předplacená, palivová, jiná |
| **Karetní asociace** | Visa, Mastercard, Maestro, American Express, jiná (nepovinné) |
| **Bankovní účet** | měnový účet firmy, ze kterého se platby kartou strhávají |
| **Držitel** | jméno držitele, případně zaměstnanec nebo uživatel aplikace |
| **Platnost od**, **Platnost do** | období, kdy karta platí; prázdné = bez omezení |
| **Aktivní karta** | neaktivní karta zůstává v evidenci, jen se nepoužívá |

Držitel se zobrazuje u bankovních pohybů a v přehledu plateb bez dokladu. Smažete-li zaměstnance, karta zůstane a vazba na něj se zruší; jméno držitele zůstává. Totéž platí pro vozidlo, jehož byl řidičem. Kartu, jejíž uložený držitel už do firmy nepatří, jde dál upravovat, vazba se při uložení uvolní.

Dvě karty se stejnou koncovkou nesmí u jedné firmy platit ve stejném období. Novou kartu se stejnou koncovkou proto založte s platností navazující na tu původní. Archivovaná karta se zobrazí zaškrtnutím **Zobrazit archivované** a jde obnovit.

### 31.9.2 Koncovka karty v bankovních pohybech

Při importu výpisu se z maskovaného čísla karty (například `PK: 000000******1234`) uloží koncovka k pohybu. Funguje to pro GPC výpisy (doplňující řádky 078/079), PDF výpisy CREDITAS a KB, bankovní API a e-mailová avíza, pokud maskované číslo obsahují. Jméno obchodníka se u karetních plateb doplní do protistrany. Maskovaný IBAN nebo číslo účtu (`CZ** **** … 1234`, `******1234/0100`) se za kartu nepovažuje.

V detailu výpisu se u platby kartou zobrazí koncovka, a je-li karta v evidenci, i její držitel nebo název.

Pohyby importované dřív se doplní samy při aktualizaci aplikace (krok auto-backfill příkazu `php api/bin/migrate.php`). Ručně lze doplnění spustit příkazem `php api/bin/backfill-card-last4.php --apply`.

### 31.9.3 Párování plateb kartou

Platba kartou se páruje s přijatou fakturou nebo účtenkou podle koncovky karty, částky a data. V přehledu plateb bez dokladu otevře kliknutí na **Nespárováno** ruční párování. Doklad můžete dohledat podle dodavatele, čísla, VS nebo přesné částky. Po úspěšném spárování se přehled obnoví. Tato akce vyžaduje čtení banky a oprávnění k párování.

- **Doklad se stejnou koncovkou** a sedící částkou se spáruje automaticky. Doklad přitom musí být vystavený nejvýš 7 dní před zaúčtováním platby v bance a nejvýš 2 dny po něm.
- **Doklad placený kartou bez uvedené koncovky** se spáruje automaticky, když částka sedí na haléř ve stejné měně a o stejnou částku v okolí 7 dní nesoupeří žádná jiná nespárovaná platba kartou. Doklad si přitom koncovku karty zapamatuje. Sedí-li částka jen přibližně, je doklad jen návrh ke kontrole.
- **Doklad bez formy úhrady kartou** (například faktura za předplatné služby s formou převod) se spáruje, když se dodavatel shoduje s obchodníkem z platby. Shoda se pozná podle významného slova názvu, i když ho obchodník píše dohromady (`ELEVENLABS.IO` a „Eleven Labs Inc."). Pozná se i podle dřívějšího ručního spárování: jakmile platbu obchodníka jednou ručně přiřadíte k dokladu dodavatele, aplikace si vazbu zapamatuje a další platby téhož obchodníka u tohoto dodavatele najde sama (například `www.cd.cz/eshop/` a „České dráhy").
- **Doklad v cizí měně placený kartou z korunového účtu** se porovná přepočtem kurzem dokladu. Karetní schéma k němu přidává přirážku, proto platí u karty kurzová tolerance 6 % (u převodu 4 %). Rozdíl se zaúčtuje jako kurzový na 563/663.
- **Doklad v jiné cizí měně, než je měna účtu** (dolarová služba zaplacená z eurového účtu) se spáruje podle původní částky nákupu, kterou banka u platby uvádí (CREDITAS). Závazek se odúčtuje v měně dokladu kurzem předpisu, banka v měně účtu kurzem dne úhrady a rozdíl je kurzový.
- **Doklad jiné karty se nespáruje nikdy**, ani podle shody částky a data. Dvě stejné platby dvěma kartami téhož dne se proto nespárují křížem.
- Dvě stejné platby toutéž kartou k jednomu dokladu jdou ke kontrole, aplikace nehádá, která z nich to byla.

K platbě, ke které aplikace našla doklad ke kontrole, AI asistent kontaci nenavrhuje. Taková platba se účtuje přes doklad.

Koncovku nese doklad vzniklý nahráním účtenky z přehledu plateb bez dokladu, účtenka z AI importu a doklad, ke kterému se připojil sken účtenky s koncovkou karty.

Doklad nemusí být v aplikaci dřív než platba. Když přijde později, spáruje se s platbou své karty sám, jakmile je přijatý: po AI importu účtenky, kterou import rovnou označil jako zaplacenou, po připojení skenu s koncovkou karty a po přijetí konceptu dokladu. Nezaúčtovaný doklad se přitom zaúčtuje, má-li firma zapnuté automatické účtování přijatých dokladů.

### 31.9.4 Akce u plateb bez dokladu

Nákupy kreditní kartou (pohyby z výpisu úvěrového účtu) na záložce **Platby bez dokladu** nejsou, ani když nesou koncovku karty. Řeší se v detailu kreditní karty, sekce **Nákupy bez dokladu** (viz [Kreditní karty](112_Kreditni_karty.md)).

- **Nahrát účtenku**: PDF nebo fotografie se vytěží stejně jako při AI importu přijaté faktury. Bez AI (nebo když vytěžení selže) se účtenka neztratí: uloží se do [Příchozích dokladů](23_Prijate_faktury.md), navázaná na platbu kartou.
- **Spárovat**: spustí párování platby znovu, typicky po potvrzení dokladu z účtenky. Hledá mezi přijatými doklady (ne mezi soubory v Dokumentech): podle variabilního symbolu, podle koncovky karty a data (doklad s formou úhrady karta, datem zdanitelného plnění nebo vystavení 7 dní před až 2 dny po zaúčtování platby) a nakonec podle částky a podobného názvu dodavatele. Najde-li jeden doklad, platba se hned spáruje a zaúčtuje i s vypořádáním (viz [§ 31.9.7](#3197-uctovani-plateb-kartou)). Podobný doklad nabídne jen jako návrh, který potvrdíte v detailu výpisu. Když nic nenajde, platba zůstane v přehledu.
- **Bez dokladu, nedaňově**: uzavře platbu do nedaňového nákladu (výchozí analytika účtu 548 z nastavení účtování).
- **Bez dokladu, daňově**: uzavře platbu do daňového nákladu (výchozí 518) bez odpočtu DPH. Daňovou uznatelnost určuje vybraný účet v účtové osnově.
- **K tíži držitele**: platba se přeúčtuje na pohledávku za držitelem karty (výchozí 335, na výběr i 355 u společníka nebo 378).
- **Výpis**: otevře bankovní výpis rovnou na této platbě.

Dialog ukáže datum, obchodníka a částku a nabídne účet: výchozí podle nastavení účtování, nebo jiný účet z osnovy (u uzavření náklad třídy 5, u držitele 335, 355 nebo 378). DPH se u uzavření bez dokladu neodpočítává. Zápis dostane číslo **KARTA-**číslo pohybu a datum platby; spadá-li platba do uzavřeného období, zapíše se k prvnímu dni otevřeného období. Dorazí-li doklad až k platbě, kterou jste mezitím uzavřeli bez dokladu, uzavření se samo stornuje a zaúčtuje se vypořádání.

U platby zaúčtované přes mezičlen se pod obchodníkem zobrazí analytika karty, na které platba čeká na doklad (například „Mezičlen 378.101“). Uzavřená platba z přehledu zmizí.

U platby na čerpací stanici (podle obchodníka, např. název sítě stanic nebo pohonné hmoty v popisu) se pod obchodníkem zobrazí **vozidlo držitele karty**, pokud ho lze určit jednoznačně. Řídí-li držitel víc vozidel, aplikace to napíše a vozidlo neurčí.

### 31.9.5 Oprávnění

Evidenci karet vidí a upravují uživatelé s oprávněním ke správě bankovních účtů firmy. Přehled plateb bez dokladu vyžaduje přístup k bance, nahrání účtenky oprávnění k nahrávání přijatých dokladů a spárování oprávnění k párování bankovních pohybů. Nastavení účtování karet, změna analytiky karty a uzavření platby bez dokladu vyžadují oprávnění k zaúčtování bankovních pohybů (stejné jako nastavení GoPay).

### 31.9.6 Vozidlo podle karty

Karta s držitelem vybraným ze zaměstnanců slouží i [knize jízd](36_Kniha_jizd.md): tankování zaplacené kartou (účtenka nebo faktura s koncovkou karty, bankovní pohyb kartou, import tankování se sloupcem karty) se přiřadí vozidlu, jehož řidičem je držitel karty. Rozhoduje karta platná k datu tankování, takže historická tankování zůstanou u tehdejšího držitele i po výměně karty.

Vozidlo se podle karty přiřadí jen tehdy, když na dokladu není SPZ a držitel řídí právě jedno aktivní vozidlo. Podrobnosti viz kapitola Kniha jízd, oddíl Přiřazení vozidla.

### 31.9.7 Účtování plateb kartou

Bez zapnutého režimu mezičlenu se spárovaná platba kartou účtuje stejně jako jiná úhrada přijatého dokladu z bankovního účtu, ke kterému je karta vedená (MD 321 / D 221).

V podvojném účetnictví lze platby kartou účtovat **přes mezičlen s analytikou pro každou kartu**. Zapíná se na záložce **Nastavení účtování**. Platba kartou se pak z výpisu zaúčtuje hned, i když k ní ještě není doklad, a zůstatek analytiky karty vždy ukazuje platby, ke kterým doklad chybí.

#### 31.9.7.1 Předkontace

| Situace | Kdy se účtuje | Zápis |
|---|---|---|
| Platba kartou z výpisu | při zaúčtování bankovního pohybu, automaticky | MD 378.x / D 221 |
| Předpis přijatého dokladu | beze změny | MD 5xx (+ 343) / D 321 |
| Vypořádání s dokladem | při spárování platby kartou s přijatým dokladem | MD 321 / D 378.x |
| Kurzový rozdíl | ve vypořádání, když se platba v Kč liší od předpisu dokladu v cizí měně | MD 563 nebo D 663 |
| Haléřový rozdíl | ve vypořádání, když se platba liší od dokladu do 1 Kč | MD 548 nebo D 648 |
| Vratka na kartu | při zaúčtování příjmu kartou a jeho spárování s dobropisem | MD 221 / D 378.x, pak MD 378.x / D 321 |
| Uzavření bez dokladu nedaňově | akce Bez dokladu, nedaňově | MD 548 (nedaňová analytika) / D 378.x |
| Uzavření bez dokladu daňově | akce Bez dokladu, daňově | MD 518 / D 378.x |
| K tíži držitele karty | akce K tíži držitele | MD 335 / D 378.x |
| Poplatek za kartu | beze změny, pravidlem bankovního poplatku | MD 568 / D 221 |
| Výběr hotovosti kartou | beze změny, převodem přes peníze na cestě | MD 261 / D 221, MD 211 / D 261 |

Vypořádání je **samostatný zápis** (v deníku zdroj „Vypořádání platby kartou"). Bankovní zápis platby se kvůli dokladu nikdy nepřepisuje: spárování přidá vypořádání, zrušení párování vypořádání stornuje a zůstatek analytiky karty se vrátí. Stejně jako u zrušení zaúčtování bankovního pohybu platí, že v uzavřeném nebo zamčeném období storno nejde provést.

#### 31.9.7.2 Analytika karty

Každá karta dostane vlastní analytiku mezičlenu **postupně** (378.101, 378.102 …), nikoli podle koncovky. Analytika vznikne automaticky u první platby karty a jmenuje se „Karta ****1234 (název karty)“. V detailu karty je vidět analytika, její zůstatek (platby bez dokladu) a odkaz **Obraty účtu**. Jinou existující analytiku mezičlenu lze kartě vybrat ručně (**Změnit analytiku**); analytiku jiné karty převzít nejde.

Platba kartou, kterou firma neeviduje, založí **neověřenou kartu** s vlastní analytikou. Neověřené karty stránka Platební karty zvýrazní. Doplňte název a držitele a kartu uložte, nebo ji ověřte akcí **Ověřit kartu**. Když je zakládání karet vypnuté, nebo kartu nejde k datu platby jednoznačně určit, jde platba na záchrannou analytiku **378.199 Neevidované karty**.

Karta vedená k cizoměnovému účtu má analytiku vedenou v měně účtu: zápisy nesou cizoměnovou částku i kurz dne platby.

#### 31.9.7.3 Nastavení účtování

| Pole | Význam |
|---|---|
| **Účtovat platby kartou přes mezičlen** | zapnutí režimu; ve výchozím stavu je vypnutý |
| **Platí od** | datum účinnosti; výchozí je začátek prvního otevřeného období |
| **Mezičlen** | syntetika 378 (výchozí), 261 nebo 395; 325 nabídnout nejde, je to saldokonto |
| **Uzavřít bez dokladu** | výchozí nedaňová analytika 548 |
| **Pohledávka za držitelem** | výchozí 335 |
| **Kurzová ztráta**, **Kurzový zisk** | výchozí 563 a 663 |
| **Haléřový rozdíl** (náklad, výnos) | výchozí 548 a 648 |
| **Zakládat neznámé karty automaticky** | vypnuto = platba jde na záchrannou analytiku 199 |
| **Upozornit na platbu bez dokladu po (dnech)** | lhůta měsíční kontroly plateb bez dokladu |

**Historie se nepřeúčtovává.** Platby kartou před datem účinnosti zůstávají zaúčtované tak, jak byly (321/221, 548/221 …). Zaúčtovaná platba drží režim, ve kterém se zaúčtovala: zapnutí režimu, jeho vypnutí, změna mezičlenu ani změna analytiky karty už zaúčtované platby nemění a platí jen pro nové zápisy. Nese-li původní analytika zůstatek, uložení změny chce potvrzení a zůstatek na původním účtu vypořádáte ručně.

#### 31.9.7.4 Kontroly

- **Měsíční kontrola** hlásí platby kartou bez dokladu starší než lhůta z nastavení (jen od data účinnosti režimu). Řádek vede do přehledu plateb bez dokladu.
- **Předuzávěrková kontrola** „Mezičlen plateb kartou se zůstatkem“ porovná zůstatek každé analytiky karty s konkrétními nevypořádanými platbami a zvlášť ukáže rozdíl, který platbami vysvětlit nejde (typicky ruční zápis na analytiku karty). Nevypořádané platby uzavřete v přehledu plateb bez dokladu.
- Analytiky karet pod 261 nebo 395 se nehlásí v kontrole peněz na cestě, vnitřního zúčtování ani průběžných účtů, hlídá je jen kontrola mezičlenu.
- Výkaz peněžních toků bere platbu kartou jako výdaj v den platby, i když je mezičlen pod 261.

### 31.9.8 Daňová evidence

Firma v daňové evidenci účty nemá. Platba kartou je v ní bankovní výdaj v peněžním deníku a mezičlen se nepoužívá; neznámé karty se nezakládají. Přehled plateb bez dokladu slouží jako výzva k doložení výdaje.

## 31.10 Související kapitoly

- [Kreditní karty](112_Kreditni_karty.md) - úvěrové účty ke kreditním kartám a jejich výpisy.
- [Banka](29_Banka.md) - načtení výpisů, ze kterých se platby kartou poznají.
- [Bankovní účty](30_Bankovni_ucty.md) - účty, ze kterých se platby kartou strhávají.
- [Přijaté faktury](23_Prijate_faktury.md) - doklady k platbám kartou.
- [Kniha jízd](36_Kniha_jizd.md) - tankování podle karty.
- [Účetní deník](52_Ucetni_denik.md) - zápisy KARTA- a vypořádání.
