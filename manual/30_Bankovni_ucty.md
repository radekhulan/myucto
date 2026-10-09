# 30. Bankovní účty, přímé napojení a e-mailová avíza (IMAP)

> Návod, jak v MyÚčtu založit bankovní účty firmy, napojit banku přímo přes API,
> odeslat platební příkaz a načítat bankovní avíza, PDF výpisy a PDF faktury
> z e-mailové schránky. Pro správce firmy, účetní a administrátory.
> Cesta: `Peníze → Bankovní účty`.

Stránka `Peníze → Bankovní účty` má tyto záložky:

<!-- cols: 34 66 -->
| Záložka | K čemu slouží |
|---|---|
| **Bankovní výpisy** | Import výpisů a párování plateb (viz [Banka](29_Banka.md)). |
| **Všechny pohyby** | Přehled všech transakcí napříč výpisy, účty a roky s filtry a párováním. Vidí ji každá firma (viz [Banka § 29.13.3](29_Banka.md#29133-vsechny-pohyby)). |
| **K zaúčtování**, **Kontace účtů** | Účetní automatika. Vidí je jen firma s podvojným účetnictvím (viz [§ 30.11.2](#30112-kontace-uctu-analytika-221-jen-podvojne-ucetnictvi) a [Banka](29_Banka.md)). |
| **Měny a účty** | Seznam bankovních účtů a přímé napojení na banku. Vidí ji ten, kdo smí číst nastavení bankovních účtů. |
| **Stavy na účtech** | Aktuální zůstatky a jejich vývoj. |
| **Bankovní avíza a PDF z e-mailu** | Nastavení IMAP, mapování avíz, parsery. Vidí ji administrátor. |

Bankovní avízo je e-mail od banky s údaji o platbě. MyÚčto ho umí pravidelně načítat, vytěžit z něj variabilní symbol, částku, měnu, datum a vlastní účet a vytvořit z něj bankovní transakci stejně jako z [výpisu](29_Banka.md).

## 30.1 Kdy to potřebujete

- Zakládáte firmu v MyÚčtu a potřebujete zadat bankovní účty pro PDF faktury, QR platby a výpisy.
- Chcete, aby se pohyby z banky stahovaly samy, bez ručního nahrávání GPC.
- Chcete z aplikace poslat platební příkaz bance.
- Banka posílá avíza o platbách e-mailem a vy je chcete automaticky párovat s fakturami.
- Banka posílá PDF výpisy nebo dodavatelé posílají faktury do stejné schránky a vy je chcete načíst bez ručního nahrávání.
- Potřebujete vidět, kolik peněz je na kterém účtu.
- V podvojném účetnictví potřebujete každému účtu přidělit analytiku 221 a dokladovou řadu.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Přidat bankovní účty | záložka **Měny a účty**, [§ 30.3](#303-krok-za-krokem-pridani-bankovniho-uctu) |
| jednou při zavádění (podvojné účetnictví) | Zkontrolovat analytiku 221 a dokladovou řadu | záložka **Kontace účtů**, [§ 30.4](#304-krok-za-krokem-analytika-221-a-dokladova-rada) |
| jednou za účet | Napojit banku přímo | záložka **Měny a účty**, sekce **Přímé napojení na banku**, [§ 30.5](#305-krok-za-krokem-prime-napojeni-banky) |
| podle potřeby | Odeslat platební příkaz | `Nákup → Platební příkazy`, [§ 30.6](#306-krok-za-krokem-odeslani-platebniho-prikazu-do-banky) |
| jednou za schránku | Nastavit IMAP a mapování avíz | záložka **Bankovní avíza a PDF z e-mailu**, [§ 30.7](#307-krok-za-krokem-nacitani-bankovnich-aviz-z-e-mailu) |
| průběžně | Podívat se na zůstatky | záložka **Stavy na účtech** |

## 30.2 Než začnete

1. **Oprávnění.** Správa napojení a ruční načítání vyžadují právo zápisu k bankovním účtům. Odeslání příkazu vyžaduje právo zápisu k bankovním účtům i platebním příkazům. Záložku s IMAP a parsery vidí administrátor. Záložky účetní automatiky vidí jen firma s podvojným účetnictvím.
2. **Smlouva s bankou.** Pro přímé napojení potřebujete u banky aktivovanou službu API (podle banky token, certifikát nebo souhlas). Co konkrétně, najdete v [§ 30.11.5](#30115-podporovane-banky-a-jejich-pozadavky).
3. **Šifrovací klíč serveru.** Přístupové údaje k bance se ukládají šifrovaně. Správce instalace musí mít nastavený samostatný šifrovací klíč serveru (viz [§ 30.11.4](#30114-prime-napojeni-spolecna-pravidla)).
4. **IMAP schránka** pro avíza a PDF: server, uživatel a heslo schránky, do které banka avíza posílá.
5. **Podvojné účetnictví** pro analytiku 221, kontace a automatické zaúčtování.

## 30.3 Krok za krokem: přidání bankovního účtu

Sekce **Měny + bankovní účty** je čistý seznam účtů firmy. Účet zde nastavujete stejně pro PDF faktury, QR platby a GPC výpisy.

1. Otevřete `Peníze → Bankovní účty`, záložku **Měny a účty**.
2. Klikněte na **Nový bankovní účet**. Pro více účtů ve stejné měně použijte totéž tlačítko a vyberte stejný kód měny.
3. Vyplňte **Měna**, **Název účtu**, **Číslo účtu** a **Kód banky**, případně **Název banky**, **IBAN** a **BIC**.
4. Zapněte **Aktivní účet**. Chcete-li, aby byl účet výchozí pro měnu, zaškrtněte **Výchozí účet pro tuto měnu** (pro jeden kód měny může být jen jeden).
5. Uložte.

**Jak poznáte, že je hotovo:** Zobrazí se hláška „Bankovní účet uložen“ (nebo „přidán“) a účet je v seznamu. Neaktivní účet se v nabídkách nepoužije. Smazat účet jde ikonou u řádku po potvrzení.

> [!TIP]
> Slovenský účet můžete zadat slovenským IBANem, se správným kódem banky a měnou EUR.

## 30.4 Krok za krokem: analytika 221 a dokladová řada

Jen pro podvojné účetnictví. Každý bankovní účet má vlastní analytiku syntetického účtu 221 (221.100, 221.200 …), která se přidělí automaticky.

1. Otevřete `Peníze → Bankovní účty`, záložku **Kontace účtů**. Účty se v ní objeví po importu prvního výpisu.
2. U každého účtu zkontrolujte **Název**, **Druh** (běžný účet, spořicí účet, termínovaný vklad), **Analytika**, **Dokladová řada** a **Aktivní**. Účet kreditní karty (231) se tu nenastavuje, spravuje ho stránka Kreditní karty.
3. Potřebujete-li účet namapovat na konkrétní analytiku, kterou už v osnově používáte (například termínovaný vklad na 221.100), přepište číslo za tečkou (1 až 6 číslic). Vedle se ukáže výsledný účet.
4. Chcete-li změnit dokladovou řadu zápisů (například `BCR`), přepište ji. Smí obsahovat písmena A-Z a číslice, nejvýše 10 znaků, a jedna řada patří vždy jen jednomu účtu.
5. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Zobrazí se hláška „Bankovní účty byly uloženy.“ a u každého účtu je vidět jeho analytika (například `221.200`). Po změně řady aplikace ukáže počet přečíslovaných bankovních zápisů v otevřených obdobích.

> [!WARNING]
> Analytiku, kterou už používá jiný účet, systém odmítne. Jednou přidělenou analytiku nelze zrušit, jen změnit na jiné číslo. Zápisy zaúčtované před přidělením analytiky zůstávají na syntetice 221 (viz [§ 30.11.2](#30112-kontace-uctu-analytika-221-jen-podvojne-ucetnictvi)).

## 30.5 Krok za krokem: přímé napojení banky

Přímé napojení stahuje pohyby z banky samo. Podrobnosti pro každou banku (certifikáty, tokeny, souhlasy) jsou v [§ 30.11.5](#30115-podporovane-banky-a-jejich-pozadavky). Postup je u všech bank podobný:

1. Otevřete `Peníze → Bankovní účty`, záložku **Měny a účty**. Pod seznamem účtů je sekce **Přímé napojení na banku**.
2. U účtu klikněte na **Napojení banky** a vyberte banku.
3. Vyplňte údaje podle banky (například **API token** u Fio a MONETA, **Číslo smlouvy ČSOB CEB** a certifikát u ČSOB, **Client ID aplikace** a certifikát u Raiffeisenbank). Účet musí mít zapnuté **Aktivní účet**.
4. Klikněte na **Ověřit a uložit**. U bank s OAuth (Česká spořitelna, KB+) pokračujte do banky tlačítkem a udělte souhlas, pak se aplikace sama vrátí na záložku **Měny a účty**.
5. Klikněte na **Načíst pohyby**. Prázdné pole **Od data** navazuje na předchozí načítání. Pro ruční načtení vyplňte obě data, nejvýše 31 dní včetně krajních dnů.

**Jak poznáte, že je hotovo:** Zobrazí se hláška „Načtení dokončeno“ s počtem nových, spárovaných a přeskočených pohybů a odkaz **Otevřít načtený výpis**. Napojení má stav **Napojení je aktivní**.

Vypnutím napojení ho pozastavíte. **Odpojit** odstraní uložený token, načtené pohyby a historie odeslaných příkazů zůstanou. Při změně nastavení nechte pole tokenu prázdné, chcete-li ho zachovat.

> [!TIP]
> Pravidelné načítání obstará cron (viz [§ 29.13.20 Přímé napojení na banku](29_Banka.md#291320-prime-napojeni-na-banku-api)). Ruční tlačítko **Načíst pohyby** nepotřebujete, když cron běží.

## 30.6 Krok za krokem: odeslání platebního příkazu do banky

Přímé předání příkazu bance je určené pro tuzemské příkazy v CZK. Přijetí bankou je jen předání k autorizaci, ne úhrada.

1. Otevřete `Nákup → Platební příkazy`, vyberte tuzemské faktury a účet plátce v CZK.
2. Klikněte na **Připravit příkaz pro banku**. Příkaz se uloží a nabídne v sekci **Odeslání platebního příkazu do banky** pod přehledem faktur (vybrat lze i dříve uložený příkaz z historie).
3. Zkontrolujte napojení účtu a klikněte na **Odeslat do banky**. V potvrzení zkontrolujte účet, počet plateb a částku.
4. V internetovém bankovnictví příkaz zkontrolujte a autorizujte.

**Jak poznáte, že je hotovo:** Aplikace ukáže výsledek odeslání a referenci banky. Skutečnou úhradu prokáže až bankovní pohyb.

> [!WARNING]
> Přijatý, odmítnutý i nejasný výsledek blokuje další odeslání stejného příkazu. Při výpadku spojení nebo nejasném výsledku nejdřív ověřte příkaz v internetovém bankovnictví, než vytvoříte jakoukoli další platbu.

## 30.7 Krok za krokem: načítání bankovních avíz z e-mailu

Aby se avíza načítala, potřebujete IMAP účet, mapování na bankovní účet a parser.

1. Otevřete záložku **Bankovní avíza a PDF z e-mailu** a rozbalte nastavení.
2. V části **Vaše nastavení pro načítání bankovních avíz a PDF z e-mailů** klikněte na **Nový IMAP účet**. Vyplňte **Název**, **Host**, **Port**, **Šifrování**, **Uživatel**, **Heslo** a **Složka** (nebo klikněte na **Procházet** a vyberte ze serveru).
3. Zapněte **Povolit zpracování bankovních avíz z tohoto účtu** a **Načítat bankovní avíza**. Zkontrolujte **Vyžadovat ověření autenticity (DKIM/DMARC)** a vyplňte **Důvěryhodný authserv-id** (viz [§ 30.11.9](#30119-overeni-autenticity-e-mailu-dkimdmarc)).
4. Klikněte na **Test**, pak na **Uložit IMAP účet**.
5. V části **Mapování bankovních avíz** u každého bankovního účtu vyberte **IMAP účet**, **Parser** (nebo nechte **Automatický výběr**) a **Toleranci**. Klikněte na **Uložit mapování**.
6. Klikněte na **Spustit scan**.

**Jak poznáte, že je hotovo:** Hláška „Scan bankovních avíz dokončen“ a v přehledu **Zpracované e-maily** je u zprávy stav a navázaná transakce. Avízo se zobrazí jako transakce stejně jako pohyb z výpisu.

> [!WARNING]
> Nové mapování začíná volbou **Žádný IMAP účet**. Takový řádek se při scanu nepoužije, dokud nezvolíte konkrétní IMAP účet nebo vědomě nepovolíte **Všechny IMAP účty**.

Nezpracuje-li se vaše avízo, vyzkoušejte parser v sekci **Parser provideri**: vložte text e-mailu, odesílatele a předmět a klikněte na **Otestovat parser**. Systémový parser se přímo neupravuje, použijte u něj **Duplikovat** (viz [§ 30.11.12](#301112-parser-provideri)).

Pravidelný scan zařídí cron `cmd/cron-bank-email-notices.sh` každých 30 minut (viz [§ 30.11.17](#301117-cron-pro-e-mailova-aviza)).

## 30.8 Krok za krokem: načítání PDF faktur a výpisů z příloh

U každého IMAP účtu zapnete dvě samostatné větve zpracování příloh.

1. U IMAP účtu zapněte **Načítat PDF faktury z příloh** (výchozí je vypnuto). Doklady adresované vaší firmě se založí do `Nákup → Příchozí doklady`.
2. Případně zapněte **Načítat PDF výpisy z příloh** (výchozí je vypnuto). Výpis banky, kterou aplikace umí číst a který patří k účtu vaší firmy, se naimportuje včetně párování plateb s fakturami.
3. Chodí-li vám od banky avízo i PDF výpis na tentýž pohyb, vypněte **Načítat bankovní avíza**, ať se pohyby nezdvojí.
4. Výsledek každé přílohy najdete v tabulce **PDF doklady z příloh** pod přehledem zpracovaných zpráv.

**Jak poznáte, že je hotovo:** U příloh je výsledek **Přidáno do příchozích dokladů** (faktura) nebo **Naimportován bankovní výpis** (výpis). Příloha mimo vás skončí jako **Není doklad pro nás** nebo **Výpis k cizímu účtu** a nic se nezaloží.

> [!TIP]
> Skenované PDF bez textové vrstvy aplikace nerozpozná (nemá OCR). Takový doklad nahrajte do fronty příchozích dokladů ručně.

## 30.9 Krok za krokem: kontrola stavů na účtech

1. Otevřete záložku **Stavy na účtech**.
2. V tabulce **Aktuální stav na účtech** zkontrolujte u každého účtu **Aktuální stav**, **Stav v CZK** a datum **K datu**. Štítek **z API**, **z avíza** nebo **z převodu** říká, odkud údaj pochází.
3. Pod tabulkou je graf měsíčních konečných zůstatků každého účtu v jeho měně a graf **Celkový vývoj v CZK** s řadou **Celkem**.

**Jak poznáte, že je hotovo:** Zůstatek sedí na poslední výpis nebo na internetové bankovnictví. Vychází z posledního oficiálního GPC nebo PDF výpisu (viz [§ 30.11.1](#30111-stavy-na-uctech)).

## 30.10 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Bankovní účet je v MyÚčto neaktivní“ | Účet nemá zapnuté **Aktivní účet** | V seznamu **Měny a účty** otevřete úpravu účtu, zapněte **Aktivní účet** a uložte. |
| „Žádný z vašich účtů zatím nemá podporované přímé napojení“ | Kód banky účtu nemá konektor | Pohyby nahrávejte jako GPC nebo PDF výpis (viz [Banka](29_Banka.md)). |
| Server nedokázal přeložit adresu banky (DNS), nemůže se připojit nebo komunikace překročila časový limit | Síťový problém na straně serveru | Správce ověří síť, firewall a proxy. Pak akci zopakujte. |
| Bankovní server vrátil neúspěšnou HTTP odpověď | Chybné přístupové údaje, neaktivní služba | Ověřte přístupové údaje, aktivaci služby u banky a dostupnost banky. |
| „Zkontroluj Fio API token…“ | Token vypršel nebo nemá oprávnění | Vytvořte token v bance znovu a vyměňte ho. |
| Zkontrolujte Client ID, certifikát PKCS#12 a heslo | Chybný certifikát nebo heslo (největší velikost 24 kB) | Zadejte údaje znovu. |
| Server potřebuje PHP cURL s podporou klientského certifikátu | Chybí rozšíření na serveru | Správce doplní PHP cURL s OpenSSL. |
| Správce musí zkontrolovat šifrovací klíč | Chybí nebo se změnil šifrovací klíč | Správce nastaví `MYINVOICE_SECRET_KEY` a zkontroluje klíč, kterým byl token uložen. |
| Banka omezila četnost požadavků, nebo na účtu probíhá jiná operace | Limit banky nebo souběžné načítání | Počkejte alespoň 30 sekund a akci zopakujte. |
| „Zvol kratší období s oběma daty, nejvýše 31 dní“ | Příliš dlouhý interval nebo konec v budoucnosti | Zvolte kratší období. |
| Načítání nabízí potvrdit shody pohybů | Nejednoznačné dvojice s dřívějším importem | Porovnejte datum, částku, popisy a platební údaje a potvrďte jen shodné platby (viz [§ 30.11.4](#30114-prime-napojeni-spolecna-pravidla)). |
| Příkaz má datum v minulosti | Datum splatnosti se při přenosu neposouvá | Připravte nový příkaz s aktuálním datem. |
| Odeslání příkazu je zablokované | Výsledek odeslání už existuje, nebo není aktivní ověřené napojení | Ověřte stav v internetovém bankovnictví. Napojení nastavte v Bankovních účtech. |
| E-mail skončí ve stavu `security_rejected` | Chybí hlavička `Authentication-Results` nebo nesedí **Důvěryhodný authserv-id** | Zkontrolujte authserv-id přijímacího serveru (viz [§ 30.11.9](#30119-overeni-autenticity-e-mailu-dkimdmarc)). |
| Avízo se nezpracuje | Odesílatel provideru není ve whitelistu, nebo mapování není nastavené | Vyplňte **Odesílatele**, nastavte mapování a otestujte parser. |
| Regex provider nezpracuje nic | Prázdný odesílatel | Vyplňte pole **Odesílatel** (viz [§ 30.11.12](#301112-parser-provideri)). |
| Výpis z přílohy skončí jako **Výpis k cizímu účtu** | Číslo účtu nesedí na žádný účet firmy | Doplňte účet v záložce **Měny a účty**, výpis se při dalším scanu doimportuje. |

## 30.11 Podrobnosti a pravidla

### 30.11.1 Stavy na účtech

Záložka **Stavy na účtech** zobrazuje každý bankovní účet samostatně podle čísla účtu, kódu banky a měny. Aktuální stav vychází z posledního oficiálního GPC nebo PDF výpisu, novějšího API výpisu s dostupným zůstatkem nebo e-mailového avíza s disponibilním zůstatkem. U bankovního API se použije také zůstatek vypočtený z předchozího bankovního výpisu a navazujících pohybů. Datum odpovídá použitému výpisu a údaj je označen **z API**. Stejný zůstatek se promítá do měsíčního vývoje i přepočtu do CZK. Neověřitelný výpočet nenahrazuje poslední známý stav; při shodném datu má GPC nebo PDF přednost.

Pod tabulkou je pro každý účet samostatný graf měsíčních konečných zůstatků v jeho vlastní měně. Graf **Celkový vývoj v CZK** zobrazuje jednotlivé účty i řadu **Celkem**. Cizoměnové účty se pro tento graf přepočítávají kurzem ČNB ke konci příslušného měsíce.

Pokud mají dva účty stejné číslo před lomítkem, rozlišují se kódem banky. Starý výpis bez uloženého kódu banky se při více možných bankách do zůstatku nezapočte, protože jej nelze bezpečně přiřadit.

### 30.11.2 Kontace účtů: analytika 221 (jen podvojné účetnictví)

Záložka **Kontace účtů** drží účetní pohled na tytéž účty. **Každý bankovní účet má vlastní analytiku syntetického účtu 221**: 221.100, 221.200, 221.300 … (tečkovaný zápis, viz [§ 66.8.4](66_Ucetni_osnova.md#6684-teckovany-zapis-analytik)). Číslo se přiděluje **automaticky** (první volné, které v [účtovém rozvrhu](66_Ucetni_osnova.md) nekoliduje s už existujícím účtem) a analytika se zároveň založí v rozvrhu pod 221.

Proč to tak je:

- **zůstatek analytiky sedí na výpis** konkrétního účtu - na ploché 221 leží několik reálných účtů najednou a zůstatek pak neodpovídá ničemu,
- **inventarizace k rozvahovému dni** (§ 29-30 zákona o účetnictví) se dá doložit výpisem daného účtu,
- **cizoměnové účty se přeceňují automaticky** - jednoměnová analytika už nemíchá měny, takže ji [uzávěrkové přecenění](72_Uzaverka.md) nabídne samo,
- **převod mezi vlastními účty** je v deníku vidět (obě nohy jsou různé účty).

V tabulce u každého účtu nastavíte:

| Pole | Význam |
|---|---|
| **Název** | Vlastní pojmenování účtu (použije se i jako název analytiky v rozvrhu) |
| **Druh** | Běžný účet / spořicí účet / termínovaný vklad |
| **Analytika** | Číslo za tečkou - vedle se hned ukáže výsledný účet (např. `221.200`) |
| **Dokladová řada** | Řada, pod kterou se bankovní zápisy účtu číslují v deníku (např. `BCR`) |
| **Aktivní** | Neaktivní účet se do kontací nenabízí, ale své číslo si drží |

Číslo můžete **přepsat**, typicky když už některý účet v rozvrhu vedete pod konkrétní analytikou a chcete na ni bankovní účet **namapovat** (například termínovaný vklad na 221.100). Analytiku, kterou už používá jiný účet, systém odmítne, a jednou přidělenou analytiku **nelze zrušit**, jde ji jen změnit na jiné číslo. Analytika, na které už leží účetní zápisy, se sama nikdy nepřidělí, aby nový účet nezdědil cizí zůstatek.

> [!WARNING]
> Zápisy zaúčtované dřív, než účet analytiku dostal, zůstávají na syntetice 221. Jejich přesun je **účetní reklasifikace k datu**: účtuje se ručně jako interní doklad (`221.xxx` / 221) v **otevřeném** období. Do uzavřených a schválených let se nezasahuje a rozvahový řádek „Peněžní prostředky na účtech“ se rozpadem uvnitř 221 stejně nemění.

### 30.11.3 Dokladová řada bankovních zápisů

Bankovní zápis má v deníku, hlavní knize i opisu účtu jako **číslo dokladu** dokladovou řadu účtu a pořadové číslo měsíčního výpisu: `BCR-08` je srpnový výpis účtu s řadou BCR. Za rok má každý účet dvanáct čísel, rok určuje účetní období. Číslo se odvozuje z měsíce data zaúčtování pohybu, nikoli z konkrétního výpisu, takže je stejné, ať pohyb přišel z denního načtení přes přímé napojení, z měsíčního výpisu, nebo po smazání a novém načtení výpisu. Storno nese číslo stornovaného zápisu s předponou `STORNO`. Stejné číslo jako zápis platby dostane i vypořádání platby kartou s dokladem, protože vychází ze stejného výpisu. Bankovní zápisy převzaté z jiného účetního programu si nechávají číslo dokladu z původního programu.

Výchozí řadu dostane každý účet automaticky podle druhu a měny:

| Účet | Řada |
|---|---|
| Běžný účet v Kč | `BCR` |
| Běžný účet v cizí měně | `BC` + první písmeno měny (EUR → `BCE`) |
| Spořicí účet | `BCS` |
| Termínovaný vklad | `BCT` |
| Kreditní karta | `BCK` |

Má-li firma víc účtů se stejnou výchozí řadou, další dostanou pořadové číslo (`BCR2`, `BCR3` …). Řadu můžete přepsat na libovolnou kombinaci písmen A-Z a číslic (nejvýše 10 znaků), jedna řada patří vždy jen jednomu účtu.

Po uložení nové řady se **přečíslují bankovní zápisy účtu v otevřených obdobích** a aplikace ukáže jejich počet. Uzavřená a schválená období si ponechají čísla, se kterými byla uzavřena. Původní identifikátor pohybu z banky se neztrácí: deník ho ukazuje drobně pod číslem dokladu a pole **Číslo dokladu** i fulltext podle něj dál vyhledávají.

Stejné přečíslování jde spustit i z příkazové řádky, například po převzetí dat: `php api/bin/bank-document-series-backfill.php` vypíše, co by se změnilo, s `--apply` změny zapíše (volitelně `--supplier=<id>` a `--from-date=RRRR-MM-DD`). Po aktualizaci aplikace ho spouští automaticky i `php api/bin/migrate.php`.

### 30.11.4 Přímé napojení: společná pravidla

Pohyby ukládané přes bankovní API se zobrazují v jednom měsíčním výpisu pro danou firmu, číslo účtu, kód banky a měnu. Opakované načtení doplní stejný měsíc; překrývající se pohyby se nezapočítají podruhé. Pohyby z odpovědi přesahující více měsíců se rozdělí podle data zaúčtování. Prázdné načtení nezakládá další výpis téhož měsíce. Původní odpovědi banky zůstávají uložené jako podklady a původní odkazy na pohyby zůstávají platné. Měsíční přehled se průběžně doplňuje a nelze jej samostatně smazat. Úplný GPC nahraný pro tentýž účet a měsíc jej nahradí jako hlavní výpis se zachováním odkazu, párování a zaúčtování. Nové pohyby se doplní. Pokud GPC některé evidované pohyby neobsahuje, zůstanou v měsíčním přehledu; neúplný dokument je uložený jako podklad.

Na záložce **Měny a účty** je pod seznamem účtů sekce **Přímé napojení na banku** (shrnutí možností je v [§ 29.13.20 Přímé napojení na banku](29_Banka.md#291320-prime-napojeni-na-banku-api)). U názvů bank se nezobrazuje označení dostupnosti; seznam účtů obsahuje pouze účty podporované konektorem a přehled bank i celý box zůstávají viditelné i bez podporovaných účtů. Každý měnový účet má vlastní nastavení a přístupové údaje.

Výsledek načítání ukáže počet nových, spárovaných a přeskočených duplicitních pohybů a odkaz na výpis. Pohyby vstupují do stejného párování a účtování jako ručně nahrané výpisy, včetně převzetí vazeb z odpovídajících e-mailových avíz. Překrývající se období můžete načíst znovu.

Měsíční GPC při jednoznačné shodě použije již načtený pohyb z API. Zachová jeho ID, párování faktur, mzdové vazby i účetní zápisy a připojí k němu další zdrojový výpis. Pohyb je vidět také v detailu GPC, v účetní evidenci však existuje pouze jednou. Původní API údaje se nepřepisují méně podrobným GPC. Stejná ochrana platí i při následném načtení API po GPC. Dosud nespárované pohyby se znovu zkusí spárovat.

Shoda vyžaduje stejnou firmu, vlastní účet, banku, měnu, den a částku se znaménkem. Kontrolují se dostupné symboly a protiúčet. Automatické spojení rozpozná společnou bankovní referenci, shodný protiúčet s neprázdným variabilním symbolem nebo shodný dostatečně podrobný popis platby. U popisu se ignorují mezery a interpunkce; rozpozná se také zpráva doplněná názvem před oddělovačem. Každá platba musí mít jediný protějšek. Tyto shody automaticky zpracuje i cron a uloží jejich vazbu pro další načítání bez zásahu uživatele. Samotná shoda částky a dne nestačí, protože může jít o další skutečnou platbu.

Při nedostatku dalších údajů načítání nabídne jednoznačné dvojice ke kontrole (**Potvrzení shod bankovních pohybů**, tlačítko **Potvrdit shody (počet) a načíst znovu**). Porovnejte datum, částku, popisy a dostupné platební údaje s původním výpisem. Potvrďte je pouze tehdy, když jde o stejné platby. Potvrzení připojí nový výpis k existujícím pohybům a další překrývající se načítání už tyto vazby pozná. Při zrušení se nový výpis neuloží. Automatické načítání samo slabé shody nepotvrzuje; vyřešte je ručním načtením se stejným rozsahem nebo s prázdným datem **Od data**. Pokud nelze odlišit několik stejných plateb, import se zastaví bez možnosti hromadného potvrzení a pohyby je potřeba jednotlivě prověřit. Již zaúčtované pohyby se tím nemění. Historické duplicity vytvořené staršími importy se automaticky nemažou.

Selhání načítání aktivního účtu se zobrazí také v přehledu **Akce pro tebe** uživateli s oprávněním spravovat bankovní účty. Upozornění rozliší nejednoznačné shody od ostatních chyb a otevře nastavení konkrétního účtu. Po úspěšném načtení samo zmizí.

Seznam i detail výpisu ukazují také připojené pohyby z jiného importu, včetně stavu párování a zaúčtování. Sdílený pohyb je v účetních součtech stále jen jednou.

Vypnutím napojení pozastavíte jeho používání. **Odpojit** odstraní uložený token; načtené pohyby a historie odeslaných příkazů zůstanou zachované. Správa napojení a ruční načítání vyžadují právo zápisu k bankovním účtům.

**Šifrování.** Token se ukládá šifrovaně a nelze jej zpětně zobrazit. Správce instalace musí mít nastavený samostatný šifrovací klíč serveru (`app.secret_encryption_key` v konfiguraci nebo proměnná prostředí `MYINVOICE_SECRET_KEY`, 32 náhodných bajtů v base64). Klíč bezpečně zálohujte, bez něj uložené tokeny nelze přečíst. Při změně nastavení nechte token prázdný, pokud jej chcete zachovat; nový token původní nahradí. Po vypršení platnosti vytvořte token v bance znovu a zde jej vyměňte.

### 30.11.5 Podporované banky a jejich požadavky

Načítání pohybů podporuje Fio ČR (kód **2010**) i Fio SR (kód **8330**) přes stejné API.

#### 30.11.5.1 ČSOB (0300)

ČSOB používá službu CEB Business Connector. V CEB aktivujte službu, získejte komunikační certifikát a povolte mu požadovaná oprávnění ke smlouvě. V MyÚčtu vyplňte **Číslo smlouvy ČSOB CEB**, vyberte certifikát `.p12` nebo `.pfx` a zadejte jeho heslo, pokud je chráněný. Účet musí mít zapnuté **Aktivní účet**. Ověření kontroluje certifikát a přístup ke smlouvě, nikoli existenci pohybů. Připojit lze i nový účet bez výpisů. Samotný přístup ke smlouvě nepotvrzuje bankovní oprávnění ke konkrétnímu účtu; číslo účtu a měna se kontrolují při importu každého výpisu nebo avíza. Prázdný seznam nevytváří žádný umělý výpis.

Datum při načítání určuje vytvoření souboru v bance. V CEB povolte vytváření a stahování výpisů ve formátu GPC a průběžných avíz ve formátu BBF. Oba formáty načítá tlačítko **Načíst výpisy a avíza** i pravidelná synchronizace. Avíza doplňují pohyby a průběžný zůstatek, denní výpis je následně potvrdí. Předané platební příkazy je nutné zkontrolovat a autorizovat v bankovnictví.

#### 30.11.5.2 Česká spořitelna (0800)

Česká spořitelna používá Premium Accounts API v3. Na [Erste Developer Portal](https://developers.erstegroup.com) vytvořte aplikaci, přidejte Premium Accounts API a OAuth2 Authorization Code. V jejím nastavení zaregistrujte přesnou návratovou adresu z formuláře MyÚčta (**Návratová adresa pro registraci aplikace**). Produkční přístup musí být schválený a služba aktivovaná u banky. Zadejte produkční **WEB-API-key**, **OAuth Client ID** a **OAuth Client Secret** a pokračujte do banky k udělení souhlasu (**Udělit nebo obnovit souhlas v bance**). MyÚčto vybere pouze účet se shodným číslem a měnou, další účty připojte samostatně. Přístupové údaje jsou šifrované, přístup se automaticky obnovuje refresh tokenem. Po vypršení nebo odvolání souhlasu připojení zopakujte. Správce má vypnout logování citlivých parametrů návratu z banky; samotné upozornění připojení neblokuje.

Pro testování nastavte v `cfg.php` volbu `bank_connectors.csas.sandbox` na `true`:

```php
'bank_connectors' => [
    'csas' => ['sandbox' => true],
],
```

Výchozí hodnota je `false` (produkce). Přepínač společně mění adresy Accounts API, OAuth přihlášení a obnovování tokenů; připravená adresa Payments API používá stejné prostředí, jeho odesílání ale zatím není implementováno. Sandbox je v nastavení účtu výrazně označený (**Sandbox České spořitelny: testovací data**) a vyžaduje sandboxový WEB-API-key, Client ID a Client Secret. V registraci aplikace připojte Accounts API v3 a nastavte scope `siblings.accounts` jako required. Použijte uvedenou callback adresu a samostatnou testovací firmu s číslem účtu ze sandboxu. Sandbox vrací statická data, ne pohyby vašeho skutečného účtu. Po změně prostředí připojte účet znovu: uložený token z jiného prostředí se nikdy neodešle do banky. Starší uložené přístupy bez označení prostředí se považují za produkční.

Konektor načítá zaúčtované pohyby (`BOOK`) jako `bank_api` do společné evidence výpisů a automatického načítání. Informační položky (`INFO`) se neúčtují. Prázdný účet lze připojit. Identita účtu se ověřuje podle IBANu, nikoli podle proměnlivého systémového ID. Načítání má bezpečnostní limit 10 000 pohybů; při jeho překročení zvolte kratší interval. Zůstatek se z pohybů neodhaduje. Přímé odesílání příkazů, notifikace a originální soubory výpisů tento konektor zatím nepodporuje. Pro platby zůstává export KPC/PDF.

#### 30.11.5.3 Raiffeisenbank (5500)

Raiffeisenbank používá Premium API. Nejdříve kontaktujte bankéře, který ověří dostupnost pro daný účet a nastaví službu i oprávnění **import hromadných plateb a stažení výpisů**. Služba může být zpoplatněna. Viz [postup RB](https://www.rb.cz/podnikatele/ucty-a-platebni-styk/prime-bankovnictvi/premium-api) a [návod k certifikátu](https://www.rb.cz/attachments/podnikatele/vytvoreni-certifikatu-premium-api-rbcz.pdf). Pokud generování certifikátu v bankovnictví chybí, ověřte aktivaci a oprávnění s bankéřem. Samotná registrace aplikace nedává přístup k bankovnímu účtu. V portálu [developers.rb.cz](https://developers.rb.cz/premium/documentation/01rbczpremiumapi) zaregistrujte aplikaci a získejte Client ID. V nastavení internetového bankovnictví vytvořte certifikát pro Premium API, povolte přístup k účtu a stáhněte soubor `.p12`. V napojení zadejte **Client ID aplikace**, certifikát a jeho heslo. Ověření kontroluje číslo účtu včetně předčíslí a aktivní měnovou složku. Certifikát i heslo se ukládají šifrovaně; soukromý klíč se při komunikaci předává pouze v paměti.

RB poskytuje pohyby nejvýše 90 dní zpětně. Aplikace načte všechny stránky požadovaného období; původní odpověď lze stáhnout jako JSON (**Stáhnout původní API data (JSON)**). Tento přehled pohybů neobsahuje konečný zůstatek výpisu. Certifikát je potřeba pravidelně odblokovat v bankovnictví. Při zachování přístupu nechte všechna pole prázdná; při výměně zadejte znovu všechny přístupové údaje.

#### 30.11.5.4 KB Business (KB+, 0100)

KB Business napojuje bankovní produkt **Extra služba API Business**. KB+ je název bankovnictví, nikoli API služby. Nezaměňujte jej se starším **KB Business API** pro MojeBanku / MojeBanku Business.

Pro podnikatelský či firemní účet KB požaduje placený bankovní tarif **Standard Business, Komfort Business nebo Exclusive Business** a zvlášť sjednanou Extra službu API Business. Samotné KB+ nebo Start Business nestačí. KB ve svém FAQ uvádí také možnost pro nepodnikající osoby s aktivní službou Premium; konkrétní dostupnost ověřte u banky.

Bankovní tarif Business není totéž co varianta API Business **Basic / Plus / Pro**. Sjednanou variantu nastavte v napojení KB+ v přepínači **Varianta Extra služby API Business**; u připojeného účtu se volba uloží hned a banku to nekontaktuje.

- **Basic** je zdarma a poskytuje jen výpisy z účtu. MyÚčto je stahuje službou **STATDA** ve formátu **KM** (GPC) za předchozí obchodní dny, nejdéle do včerejška. KB povoluje nejvýše 50 stažení měsíčně, automatické načítání proto běží jednou denně. Odesílání příkazů není dostupné. Varianta potřebuje registraci i souhlas s oprávněním `statda`. Po přepnutí z Plus sekce napojení ukáže, zda stačí **Zahájit nové ověření v KB+**, nebo je třeba **Zadat klíče znovu** a zaregistrovat aplikaci pro výpisy.
- **Plus** čte pohyby službou **ADAA** včetně dnešních, automaticky nejvýše jednou za 61 minut, a umožňuje odesílat hromadné příkazy. Zvolte ji i při variantě **Pro** (KB u ní povoluje interval 10 minut, aplikace drží odstup jako u Plus).

U čtení se do limitů započítávají datové stránky. Cena a rozsah podléhají aktuálním podmínkám banky. Když KB přístup k účtu odmítne, zkontrolujte nejdřív zvolenou variantu a souhlas v KB+.

Nejprve v KB+ vyberte variantu API služby a uzavřete smlouvu, potom pokračujte v MyÚčtu. Již udělené souhlasy spravujete přes **Nastavení → Nastavení služeb → Přístupy k účtům → Přístupy třetích stran**. Chybějící volbu či oprávnění řešte s KB na `kbplus@kb.cz`. [Podmínky a varianty](https://www.kb.cz/cs/kbapi/extra-sluzba-api-business), [FAQ a správa souhlasů](https://www.kb.cz/cs/kbapi/caste-dotazy-rozcestnik/caste-dotazy-extra-sluzba-api-business), [limity ADAA](https://www.kb.cz/cs/kbapi/extra-sluzba-api-business/primy-pristup-k-uctu-v-kb).

U varianty Plus konektor čte pohyby přes **ADAA** a příkazy odesílá přes **BATCHDA**. U varianty Basic stahuje výpisy ve formátu KM přes **STATDA** a čísla účtů z vnitřního formátu KB převádí na běžný tvar. Notifikace **NOTDA** zde nejsou implementované. Registrace aplikace a následné udělení přístupu k účtu probíhá přes OAuth. Technické klíče nenahrazují smlouvu o API službě ani souhlas majitele účtu. Před prvním připojením správce na developer portálu KB připraví API klíče pro služby **Client Registration**, **OAuth** a **ADAA** (účty a pohyby). U varianty Basic předplaťte navíc **Statements Direct API (STATDA)**; samostatný klíč se pro výpisy nezadává, autorizuje je přístupový token se scope `statda`. Platební dávky (**BATCHDA**) samostatný API klíč nepotřebují, autorizuje je přístupový token se scope `bpisp`. Chcete-li odesílat příkazy, zaškrtněte při připojení **Chci i hromadné platby**; bez toho připojení slouží jen k načítání pohybů a odeslání příkazu aplikace odmítne dřív, než cokoli předá bance. Hromadné platby jde doplnit později přes **Zadat klíče znovu** (rozšíří registraci o `bpisp`) nebo, má-li registrace `bpisp` už povolený, přes **Zahájit nové ověření v KB+**. Podrobně v [§ 29.13.20 Přímé napojení na banku](29_Banka.md#291320-prime-napojeni-na-banku-api). Potřebujete také kvalifikovaný certifikát v souboru `.p12` nebo `.pfx` včetně soukromého klíče a jeho heslo, pokud je chráněný. Samotné číslo účtu nebo jeden API klíč k připojení nestačí.

Správce musí předem nastavit veřejnou HTTPS adresu aplikace (`app.url`), serverový šifrovací klíč a platný kontaktní e-mail (`smtp.from_email`, nejvýše 43 znaků). Pro oba návratové endpointy KB musí zajistit, že webový server, reverzní proxy ani aplikační logy neukládají parametry URL obsahující registrační nebo autorizační údaje:

- `/api/settings/bank-connections/kb-plus/registration/callback`
- `/api/settings/bank-connections/kb-plus/oauth/callback`

Aplikace na riziko logování upozorňuje, ale připojení kvůli němu neblokuje. Žádný potvrzovací příznak pro logování není vyžadován. Upozornění samo nastavení logů nemění; doporučujeme citlivé parametry nelogovat nebo anonymizovat.

U účtu otevřete napojení KB+, vyplňte požadované údaje a zvolte **Pokračovat do KB+**. Na stránkách banky dokončete registraci a udělte souhlas s přístupem ke správnému účtu. Bankovní návrat zpracuje server a vrátí vás do záložky **Měny a účty**; žádné kódy ani návratové URL ručně nekopírujte. Připojení je dokončené až po ověření účtu a měny bankou. Pokud už má firma aplikaci zaregistrovanou, znovu nezadáváte API klíče ani certifikát a pokračujete rovnou udělením přístupu k dalšímu účtu. Při přerušení použijte **Obnovit stav připojení**. Nové ověření zneplatní předchozí nedokončený odkaz; vypršelý postup je nutné zahájit znovu.

#### 30.11.5.5 Banka CREDITAS (2250)

CREDITAS vyžaduje bezpečnostní klíč (**Bearer token**) pro konkrétní účet a jeho systémový identifikátor. Přístup pomocí ručně vygenerovaného klíče lze použít bez klientského certifikátu. Certifikát pro vzájemnou TLS autentizaci (**mTLS**) je volitelný, pokud jej banka vyžaduje pro konkrétní typ přístupu.

V internetovém bankovnictví vytvořte klíč s potřebnými oprávněními. V MyÚčtu zadejte jeho 64 písmen a číslic, **Account ID** z detailu účtu u aktivního API klíče a zvolte typ **Běžný účet** nebo **Spořicí účet**. Account ID je systémový identifikátor banky, nikoli číslo účtu ani IBAN. Pokud váš přístup vyžaduje certifikát, vyberte `.p12` nebo `.pfx` včetně soukromého klíče a případně zadejte heslo. Po volbě **Ověřit a uložit připojení** aplikace kontroluje shodu účtu a měny. Pro odesílání příkazů musí klíč navíc dovolovat zadávání plateb.

Uložené údaje zůstávají skryté. Pozastavení nebo opětovné zapnutí nevyžaduje jejich nové zadání. Při volbě **Změnit přístupové údaje** vyplňte znovu celou sadu přístupových údajů. Certifikát přiložte jen při použití mTLS; jeho vynechání při změně údajů přepne CREDITAS na přístup bez certifikátu. Certifikáty pro KB+ a CREDITAS lze vybrat do velikosti 24 KiB. Přístupové údaje se ukládají na serveru šifrovaně, nikoli do úložiště prohlížeče.

#### 30.11.5.6 MONETA Money Bank (0600)

MONETA se připojuje přes MONETA API jen tokenem, který si vytvoříte sami v Internet Bance. Registrace u banky ani certifikát nejsou potřeba.

1. V Internet Bance MONETA otevřete **Nastavení / Ostatní / Správa API tokenů / Vytvořit token** a token potvrďte ve Smart Bance. Při vytváření zapněte automatické prodlužování, jinak token po uplynutí platnosti přestane fungovat.
2. U účtu v MyÚčtu otevřete **Napojení banky**, zvolte **MONETA Money Bank**, vložte token a zvolte **Ověřit a uložit**.

Token platí pro všechny vaše účty u MONETY. Aplikace si podle IBANu nastaveného účtu najde odpovídající účet a z tokenu nikdy nečte jiný. Pro další účet (například EUR) stačí vložit stejný token. Načítají se jen zaúčtované pohyby, blokace karet se objeví až po zaúčtování. MONETA vydá pohyby nejvýše 2 roky zpět a starší období může vyžadovat dvoufázové ověření v Internet Bance. Odeslaný hromadný příkaz (nejvýše 200 plateb) najdete v Internet Bance v sekci **Zprávy a oznámení**, kde ho podepíšete. Bez podpisu se neprovede.

#### 30.11.5.7 Fio banka (2010, 8330)

1. Založte účet se správným číslem, kódem banky a měnou.
2. Ve Fio internetovém bankovnictví vytvořte API token pro tento konkrétní účet. Pro načítání pohybů stačí právo číst. Pro odesílání příkazů musí token umožňovat i import plateb. Token má omezenou platnost podle nastavení v bance.
3. U účtu otevřete **Napojení banky**, vložte token a zvolte **Ověřit a uložit**. Aplikace při ukládání ověří, že bankovní účet odpovídá připojení.
4. Klikněte na **Načíst pohyby**. Prázdné datum **Od data** automaticky navazuje na předchozí načítání; pole **Do data** je v tomto režimu vypnuté a nepoužívá se. Pro ruční načtení vyplňte obě data, nejvýše 31 dní včetně krajních dnů. Starší historii může být potřeba dočasně odemknout u tokenu v internetovém bankovnictví.

### 30.11.6 Odeslání příkazu do banky: pravidla

Akce **Připravit příkaz pro banku** uloží příkaz a nabídne jej v sekci **Odeslání platebního příkazu do banky** pod přehledem faktur. Tento způsob přípravy faktury neoznačí jako zaplacené ani při zaškrtnuté volbě pro ruční označení úhrady. Vybrat lze i dříve uložený příkaz z historie.

Pro přímé odeslání musí existovat aktivní ověřené napojení stejného účtu plátce v CZK s podporou příkazů: Fio ČR (2010), ČSOB (0300), Raiffeisenbank (5500), Banka CREDITAS (2250), MONETA Money Bank (0600) nebo KB+ (0100). Přímé odesílání slovenských EUR příkazů zatím není implementované; přímé předání je určené pro tuzemské CZK příkazy. Příkaz, který už při vytvoření označil faktury jako zaplacené, se tímto způsobem znovu neposílá. Odeslání vyžaduje právo zápisu k bankovním účtům i platebním příkazům.

Po **Odeslat do banky** zkontrolujte potvrzení s účtem, počtem plateb a částkou. Přijetí bankou znamená pouze předání příkazu k autorizaci. Příkaz musíte dále zkontrolovat a potvrdit v internetovém bankovnictví. Skutečná úhrada se prokáže bankovním pohybem, samotné odeslání ji nepotvrzuje.

Aplikace ukládá výsledek odeslání a referenci banky. Pokud banka vrátí počty přijatých a odmítnutých položek, zobrazí se u výsledku také tyto počty. Částečné přijetí příkazu vyžaduje kontrolu v bance. **Načíst uložený stav** znovu načte tuto evidenci aplikace, ne stav autorizace nebo provedení v bance. Přijatý, odmítnutý i nejasný výsledek blokuje další odeslání stejného příkazu. Při výpadku spojení nebo nejasném výsledku nejdříve ověřte příkaz v internetovém bankovnictví, než vytvoříte jakoukoli další platbu.

Stav zahájeného importu znamená pouze převzetí dávky ke zpracování, nikoli dokončenou kontrolu jednotlivých příkazů, autorizaci nebo provedení platby. Výsledek importu i následnou autorizaci zkontrolujte v bankovnictví. Datum splatnosti se při přenosu automaticky neposouvá.

### 30.11.7 Mapování bankovních avíz

Sekce **Mapování bankovních avíz** určuje, jak se vytěžený e-mail napojí na konkrétní bankovní účet firmy. Vazba je bankovní účet → IMAP účet → parser.

<!-- cols: 26 74 -->
| Sloupec | Význam |
|---|---|
| **Bankovní účet** | Účet z měn firmy, proti kterému se porovnává cílový účet v e-mailu |
| **IMAP účet** | Konkrétní schránka, ze které se má avízo pro tento účet brát; „Žádný IMAP účet“ = výchozí stav bez skenování, „Všechny IMAP účty“ = neomezeno |
| **Parser** | Konkrétní parser provider; „Automatický výběr“ = systém zkusí všechny aktivní providery |
| **Tolerance** | Povolená odchylka částky při párování faktury, např. `1.00` pro ±1 Kč |
| **Aktivní** | Vypnutý řádek se při scanování nepoužije |

Mapování se vyhodnocuje až po úspěšném vytěžení e-mailu. Pokud e-mail přijde z jiného IMAP účtu nebo ho zpracoval jiný parser, než je v mapování nastaveno, řádek se nepoužije. Nové nebo nenastavené mapování začíná volbou **Žádný IMAP účet**. Takový řádek se při scanování nepoužije, dokud nezvolíte konkrétní IMAP účet nebo vědomě nepovolíte variantu **Všechny IMAP účty**.

### 30.11.8 Nastavení IMAP účtu

Každá firma může mít více IMAP účtů, typicky jeden pro každou banku.

<!-- cols: 30 70 -->
| Pole | Význam |
|---|---|
| **Název** | Popisek v UI, např. „RB avíza“ |
| **Host**, **Port**, **Šifrování** | Připojení k IMAP serveru |
| **Uživatel**, **Heslo** | Přístup ke schránce; heslo se ukládá šifrovaně |
| **Složka** | IMAP složka, např. `INBOX` nebo `INBOX.Banka` |
| **Procházet** | Ověří připojení a nabídne složky ze serveru |
| **Max. zpráv na běh** | Kolik nejnovějších e-mailů cron načte při jednom běhu |
| **Zpracovat od data** | Starší e-maily se ignorují i když spadnou do limitu |
| **Vyžadovat ověření autenticity (DKIM/DMARC)** | Zpracují se jen e-maily, u kterých přijímací server potvrdil DKIM/DMARC; **zapnuto** |
| **Důvěryhodný authserv-id** | Povinné při zapnutém ověření autenticity; přesný identifikátor přijímacího serveru z jeho hlavičky `Authentication-Results` (např. `mx.mojedomena.cz`) |
| **Načítat bankovní avíza** | Z těla e-mailu se čte avízo o pohybu a zakládá se z něj bankovní transakce. Vypněte, když vám banka posílá i PDF výpisy a avíza by pohyby zdvojovala; přílohy se dál zpracují podle přepínačů níže; **zapnuto** |
| **Přijímat přeposlaná (FW) avíza** | Rozpozná banku i z těla e-mailu, když avíza chodí do schránky přeposlaná (odesílatel je vaše adresa, ne banka) |
| **E-mail přeposílatele (volitelně)** | Volitelné omezení, od koho smí přeposlaná avíza chodit - adresa (`jan@firma.cz`) nebo doména (`firma.cz`); prázdné = libovolný |
| **Načítat PDF faktury z příloh** | Vedle avíz se z každé zprávy posoudí i PDF přílohy a doklady adresované vaší firmě se založí do Nákup → Příchozí doklady; **vypnuto** |
| **Načítat PDF výpisy z příloh** | Vedle avíz se z každé zprávy posoudí i PDF přílohy a bankovní výpis k některému z účtů vaší firmy se rovnou naimportuje včetně párování plateb; **vypnuto** |
| **Po úspěchu** | Co udělat se zpracovanou zprávou: **Neměnit zprávu**, **Přidat flag**, **Přesunout**, **Označit jako přečtené** |

Pokud do schránky chodí avíza **přeposlaná** (například z firemní schránky na sběrnou adresu), zapněte **Přijímat přeposlaná (FW) avíza**. U přímého avíza se banka pozná podle odesílatele, ale přeposláním se odesílatelem stáváte vy, proto se pak banka hledá i z těla e-mailu. Volitelně omezte **E-mail přeposílatele**, ať se zpracují jen avíza od vaší adresy.

Polling zprávy standardně **neoznačuje jako přečtené**. Systém si úspěšně zpracované e-maily pamatuje v databázi podle `Message-ID` / UID / fallback hashe, takže funguje i s účtem, kde aplikace nemůže zprávy přesouvat nebo označovat. Pokud má účet zápis povolený, můžete zvolit doplňkovou akci po úspěchu: neměnit zprávu, přidat flag, přesunout do jiné složky nebo označit jako přečtené.

### 30.11.9 Ověření autenticity e-mailu (DKIM/DMARC)

Odesílatel e-mailu se dá podvrhnout, takže samotná adresa v poli *Od* nic negarantuje. **Vyžadovat ověření autenticity** je proto u nových účtů zapnuté: systém sám podpisy nepřepočítává, ale věří verdiktu, který k doručené zprávě připsal váš přijímací server do hlavičky `Authentication-Results`. Proto musíte zároveň vyplnit jeho přesné **Důvěryhodný authserv-id**. Zpracuje se jen e-mail, jehož první hlavička má právě toto authserv-id a obsahuje `dmarc=pass` s doménou `header.from` zarovnanou na odesílatele, nebo `dkim=pass` se stejně zarovnanou doménou `header.d`.

**Chybějící hlavička nebo authserv-id je odmítnutí, ne výjimka.** Když zprávě hlavička chybí, první hlavičku nepřidal připnutý server nebo verdikt nesedí, avízo se nezpracuje a v přehledu zpracovaných zpráv skončí ve stavu `security_rejected` s uvedeným důvodem. Pokud váš poštovní server hlavičku `Authentication-Results` vůbec nepřidává, kontrolu u daného účtu vypněte. Je to ale vědomé snížení ochrany, po kterém stačí k označení faktury za zaplacenou jediný podvržený e-mail.

Hlavičku `Authentication-Results` si umí do těla zprávy vložit kdokoli. Důvěryhodná je jen první, kterou přidal váš server. Server musí při přijetí zvenčí odstranit podvržené hlavičky se svým authserv-id a vlastní výsledek vložit navrch. Hodnota v poli **Důvěryhodný authserv-id** se porovnává celá, bez částečné shody, a systém při neúspěchu nehledá jiný výsledek v nižších hlavičkách. U přeposlaných avíz platí, že přeposláním původní podpis banky zaniká, takže se ověření vztahuje na přeposílatele, ne na banku.

### 30.11.10 Načítání PDF faktur z příloh

Do schránky, kam chodí bankovní avíza, obvykle posílají faktury i dodavatelé. Přepínač **Načítat PDF faktury z příloh** proto u daného IMAP účtu zapne druhou, nezávislou větev zpracování: u každé nové zprávy se projdou PDF přílohy a ty, které projdou rozpoznáním, se založí jako podání ve frontě [Nákup → Příchozí doklady](23_Prijate_faktury.md).

Příloha projde, jen když splní **obě** podmínky:

1. **Je to doklad** - v textu PDF je označení dokladu: faktura, daňový doklad, zálohová faktura, dobropis, účtenka, vyúčtování, splátkový kalendář, invoice.
2. **Je adresovaný vám** - v textu je vaše **IČO** (shoda na všechny číslice; mezery uvnitř čísla nevadí), vaše **DIČ**, nebo **název vaší firmy** shodný nejméně na 70 % (bez ohledu na diakritiku a právní formu).

Výjimka: PDF se strojově čitelným ISDOC uvnitř (PDF/A-3) se bere jako doklad bez dalšího zkoumání, data v něm jsou průkazná sama o sobě.

Co se z přílohy **nestane**: nic se neúčtuje ani nezakládá jako přijatá faktura. Vznikne jen podání ve frontě příchozích dokladů, které účetní zpracuje stejně jako doklad z klientského portálu. Rozpoznání dat z dokladu (ISDOC nebo AI) se spouští až tam, takže se za nepřečtenou přílohu neplatí žádné AI volání.

Výsledek posouzení každé přílohy, včetně zamítnuté, najdete v tabulce **PDF doklady z příloh** pod přehledem zpracovaných zpráv:

| Výsledek | Význam |
|---|---|
| Přidáno do příchozích dokladů | Vzniklo podání ve frontě |
| Duplicita | Stejný soubor už systém zná (podle otisku obsahu) |
| Není doklad pro nás | Chybí označení dokladu, nebo identita vaší firmy |
| Odmítnuto | Příloha není PDF, nebo je větší než 20 MiB |
| Chyba | Založení podání selhalo, důvod je ve sloupci Důvod |

Skenované PDF bez textové vrstvy rozpoznat nejde (aplikace nemá OCR) a skončí jako *Není doklad pro nás* s vysvětlením. Takový doklad nahrajte do fronty ručně.

Jednou posouzená příloha se už znovu neposuzuje, takže změna nastavení zpětně nepřehodnotí staré zprávy; projeví se až na nově načtených e-mailech.

E-mail s fakturou samozřejmě není bankovní avízo, takže ho parser avíz odmítne. Pokud z něj vzniklo podání, zpráva skončí ve stavu `attachment_imported` a post-processing (přesun, příznak) s ní zachází jako s úspěšně zpracovanou, do složky chyb se nepřesune.

Přílohy se posuzují **až po** ověření autenticity e-mailu ([§ 30.11.9](#30119-overeni-autenticity-e-mailu-dkimdmarc)). Zpráva zamítnutá jako `security_rejected` do fronty dokladů nedostane nic.

### 30.11.11 Načítání PDF výpisů z příloh

Banky bez přímého API posílají výpisy e-mailem jako PDF. Komerční banka navíc umí **denní výpis při pohybu**: za každý den, kdy se na účtu něco stalo, jedno PDF, a zvlášť za každou měnu účtu. Samotná avíza jde vypnout přepínačem **Načítat bankovní avíza**. Hodí se, když od banky chodí avízo i výpis na tentýž pohyb: vypnutím avíz zůstane jako zdroj jen výpis a pohyby se nezdvojí. Vypnout celý účet by znamenalo přijít i o ty výpisy.

Přepínač **Načítat PDF výpisy z příloh** u IMAP účtu zapne třetí větev zpracování: u každé nové zprávy se PDF přílohy zkusí přečíst jako bankovní výpis a ten, který k vaší firmě patří, se naimportuje - stejnou cestou jako ruční *Nahrát PDF*, tedy **včetně párování plateb s fakturami**.

Aby se výpis naimportoval, musí splnit obě podmínky:

1. **Je to výpis banky, kterou umíme přečíst** - Komerční banka, ČSOB, MONETA Money Bank, Raiffeisenbank, Banka CREDITAS. Rozhoduje textová vrstva PDF, ne název souboru.
2. **Je k účtu vaší firmy** - číslo účtu z hlavičky výpisu musí sedět na právě jeden bankovní účet firmy (záložka *Měny a účty*). U víceměnového účtu se sdíleným číslem rozhoduje ještě měna výpisu.

Cizí výpis se tím nikdy nestane vaším dokladem: kdyby vám někdo poslal do schránky výpis jiné firmy, skončí jako *Výpis k cizímu účtu* a nic nezaloží.

#### 30.11.11.1 Denní výpisy se skládají do měsíčního

Kdyby se každý denní výpis ukázal v přehledu samostatně, byl by seznam výpisů po měsíci nepoužitelný. Denní výpisy jednoho účtu se proto **skládají do jednoho měsíčního výpisu**, úplně stejně jako pohyby z přímého bankovního API:

- v přehledu je jeden řádek za měsíc a účet, označený `PDF-RRRR-MM`,
- **počáteční zůstatek** měsíce je počáteční zůstatek prvního načteného dne, **konečný zůstatek** konečný zůstatek posledního, obojí je údaj banky,
- jednotlivé dny zůstávají jako podklady měsíce: v detailu výpisu je najdete pod tlačítkem „…“ (otevřít den, stáhnout jeho původní PDF),
- když některý den chybí, přepočet zůstatků to ohlásí jako rozdíl proti bankovnímu výpisu, nechybí vám tedy pohyby potichu.

Každý výpis se navíc před uložením sám kontroluje: součet pohybů musí na haléř sedět na rozdíl počátečního a konečného zůstatku z hlavičky, a v denním výpisu musí všechny pohyby patřit dni výpisu. Když to nesedí, výpis se nenaimportuje a důvod zůstane v logu příloh; částečná nebo posunutá data se do banky nedostanou.

U karetních plateb pozor na dvě data: nákup se mohl stát v neděli a banka ho zúčtovala v pondělí. Do výpisu pohyb patří **dnem zúčtování**, a tak ho aplikace také eviduje; původní částka v cizí měně a kurz zůstávají v popisu pohybu.

Výsledek posouzení přílohy najdete ve stejné tabulce jako u faktur:

| Výsledek | Význam |
|---|---|
| Naimportován bankovní výpis | Vznikl výpis (a u denního i měsíc, do kterého patří) |
| Duplicita | Stejný výpis už v systému je |
| Výpis k cizímu účtu | Číslo účtu nesedí na žádný bankovní účet vaší firmy |
| Chyba | Výpis se nepodařilo přečíst, důvod je ve sloupci Důvod |

Příloha, která výpisem není, se tím nezdrží: posoudí ji větev PDF faktur ([§ 30.11.10](#301110-nacitani-pdf-faktur-z-priloh)). Naopak výpis, který se naimportuje, se už jako faktura neposuzuje; bez toho by mohl skončit ve frontě příchozích dokladů, protože na sobě má jméno i adresu vaší firmy. Stav *Výpis k cizímu účtu* se při dalším scanu posuzuje znovu, takže když účet do nastavení firmy doplníte, výpis se doimportuje.

### 30.11.12 Parser provideri

Provider říká, jak poznat e-mail dané banky a jak z něj vytěžit platební údaje.

Typy providerů:

- **Systémový provider** - dodaný aplikací, např. Raiffeisenbank, UniCredit Bank, ČSOB, Česká spořitelna, Fio banka, Banka CREDITAS, MONETA Money Bank nebo Air Bank.
- **Regex provider** - vlastní provider firmy, konfigurovaný v UI (**Nový regex provider**).

Předpřipravený společný provider **Česká spořitelna** je zapnutý a má vyplněný whitelist odesílatelů (`csas.cz`), stejně jako banky s vlastním parserem, které si odesílatele ověřují samy. Prázdný whitelist nepustí nic (viz [§ 30.11.12.1](#3011121-odesilatel-je-povinny)). Posílá-li vaše ČS avíza z jiné adresy, klikněte na **Duplikovat**, v kopii adresu upravte a přepněte na ni mapování účtu.

Systémový provider se přímo needituje (je společný pro všechny). Když ho chcete upravit, použijte u něj tlačítko **Duplikovat** - vytvoří se editovatelná kopie, ve které si doladíte vzory a otestujete ji přes **Test parseru**. V mapování účtu pak přepnete účet z původního providera na svou kopii. Duplikovat lze i vlastní regex provider.

Tlačítko **Vypnout pro firmu** u společného provideru platí **jen pro vaši firmu** a ostatních se nedotkne. Hodí se, když nechcete, aby se společný provider vůbec pokoušel vaše e-maily zpracovat; stejným tlačítkem (**Zapnout pro firmu**) ho zapnete zpátky. Vzory se u společného provideru měnit nedají - pokus o to skončí hláškou s odkazem na **Duplikovat**.

Systémový provider Raiffeisenbank rozlišuje směr převodu podle úvodního textu o příchozí nebo odchozí platbě; u starší či odlišné šablony použije jako záložní údaj znaménko částky. U odchozí úhrady je vlastním účtem pole **Z účtu** a protiúčtem pole **Na účet**; u příchozí úhrady je to opačně. Díky tomu se odchozí avízo mapuje na účet, ze kterého byla platba skutečně odepsána. U karetní transakce se vlastní účet načte z pole **Účet** a obchodník z pole **Detaily**; chybějící variabilní symbol ani bankovní protiúčet importu nebrání.

U Air Bank se avíza zapínají v internetovém bankovnictví pod **Účty a karty → Možnosti → Info o dění na účtu** (odesílatel `info@airbank.cz`, předměty „Zvýšení/Snížení zůstatku“). Nastavení v IB není úplně intuitivní, praktický postup je například v návodu FAPI [Nastavení zasílání e-mailů o příchozích platbách z Air Bank](https://napoveda.fapi.cz/article/40-nastaveni-zasilani-e-mailu-o-prichozich-platbach-z-air-bank) (místo FAPI adresy uveďte mailbox napojený v MyÚčtu).

Detekce e-mailu i vytěžení polí pracují **tolerantně k diakritice**: pokud avízo dorazí v jiném kódování nebo s rozbitou diakritikou (typicky u přeposlaných zpráv), vzory `Směr platby` a `Smer platby` se vyhodnotí stejně. Když přesto nějaký provider zlobí, můžete si vzory napsat rovnou bez diakritiky.

U regex provideru nastavujete:

| Pole | Význam |
|---|---|
| **Název** / **Kód** | Interní identifikace provideru |
| **Odesílatel** | **Povinný** whitelist odesílatelů, např. `info@rb.cz` |
| **Regex předmětu** | Volitelný pattern pro subject, např. `Pohyb\s+na\s+účtě` |
| **Regex těla** | Volitelný pattern, který musí být v těle e-mailu |
| **Vytěžená pole** | Regexy pro VS, částku, měnu, datum, cílový účet atd. |

#### 30.11.12.1 Odesílatel je povinný

Pole **Odesílatel** vyplňte vždy. Regex provider s prázdným odesílatelem **nezpracuje nic** - prázdná hodnota neznamená „přijmout od kohokoli“. Vzory předmětu a těla samy o sobě nechrání: text avíza si dokáže napsat kdokoli a čísla účtu i variabilní symbol jsou vytištěné na každé vydané faktuře, takže bez whitelistu by stačil jeden podvržený e-mail do sledované schránky k označení faktury za zaplacenou.

Whitelist může obsahovat víc položek oddělených mezerou, čárkou nebo středníkem a rozlišuje dva tvary:

- **adresa** (`info@rb.cz`) - musí sedět přesně; funguje i tvar `Název <info@rb.cz>`,
- **doména** (`rb.cz`) - projde libovolná adresa v této doméně i v jejích subdoménách (`noreply@mail.rb.cz`), ale ne `info@rb.cz.podvod.example`.

Doménový tvar použijte u bank, které rozesílají avíza z několika adres. Odesílatel je ale jen první filtr, skutečnou ochranu dělá **Vyžadovat ověření autenticity** u IMAP účtu a povinné mapování cílového účtu avíza na váš bankovní účet.

Povinná vytěžená pole: `variable_symbol`, `amount`, `currency`, `posted_at`, `recipient_account`. Volitelná pole:

- `counterparty_account`
- `counterparty_name`
- `constant_symbol`
- `message`
- `bank_ref`
- `balance` (disponibilní zůstatek účtu z avíza - zobrazí se v detailu měsíčního avízo-výpisu a promítne se do přehledu **Stavy na účtech**)

Regex parser používá první zachycenou skupinu nebo pojmenovanou skupinu se stejným názvem jako pole. Pro částku umí formáty typu `+1.234,56`, datum například `01. 06. 2026 10:15`.

### 30.11.13 Příklad regex provideru pro Raiffeisenbank

Následující příklad je **anonymizovaný**. Čísla účtů, variabilní symbol, název protistrany i zpráva jsou fiktivní. Do manuálu nikdy nedávejte reálné e-maily z banky s osobními údaji, zůstatky nebo skutečnými čísly účtů.

Testovací text e-mailu může vypadat například takto:

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

> [!TIP]
> Do UI zadávejte regex bez krajních oddělovačů (`/.../`). Parser je doplní sám.

### 30.11.14 Test parseru a zpracované e-maily

V sekci **Parser provideri** můžete vložit testovací e-mail, odesílatele a předmět a kliknout na **Otestovat parser**. Test ukáže, který provider se použil a jaká pole se vytěžila.

Sekce **Zpracované e-maily** je debug přehled:

- zobrazuje `Message-ID` / fallback hash,
- IMAP účet,
- datum a čas zpracování,
- stav zpracování,
- použitý provider,
- vytěžené platební údaje,
- navázanou transakci nebo fakturu.

Hlavní stav se průběžně odvozuje z aktuálního párování transakce. Pokud byla platba úspěšně spárovaná, případná chyba následného přesunu nebo označení e-mailu v IMAP už nezobrazuje párování jako neúspěšné; původní post-processing chyba zůstane viditelná jako upozornění.

Smazání záznamu (**Smazat záznam**) zde nemaže transakci ani fakturu. Maže jen deduplikační záznam, takže je možné stejný e-mail znovu zpracovat při dalším scanu. Používejte to jen jako emergency/debug akci.

### 30.11.15 Když k avízu dorazí GPC výpis

Zdroj pravdy je GPC. Když se importuje transakce, která už předtím přišla avízem a je spárovaná, aplikace **párování převezme z avíza na GPC transakci**: platba se přepojí, avízo zůstane rozpárované a nevznikne dvojí započtení. Je to důležité i účetně: **avízo se nikdy neúčtuje**, takže dokud platba visí na něm, nedostane se platební noha (u cizoměnové faktury včetně kurzového rozdílu) do deníku vůbec.

Převzetí je záměrně opatrné - proběhne jen při **právě jednom** kandidátovi se shodou účtu, měny, částky na haléř a data v okně ±5 dní. Identita se hledá takto:

| Situace | Podmínka převzetí |
|---------|-------------------|
| GPC má variabilní symbol | VS musí číselně sedět s VS avíza |
| GPC nemá VS, ale avízo nese VS nebo protiúčet | musí sedět protiúčet |
| Ani jedna strana nemá VS ani protiúčet (karetní platba, „Blokace“) | stačí výše uvedená shoda účtu, měny, částky a data |

Poslední řádek pokrývá karetní úhrady, které VS ani protiúčet nenesou. Pokud by avízo bylo bez identity, ale GPC protistranu znal, jde nejspíš o běžný převod a k převzetí nedojde. Dvě stejné blokace ve stejném okně jsou nejednoznačné - aplikace nechá párování na vás.

> [!WARNING]
> Karetní **blokace** se může od finálně zúčtované částky lišit. Pak se částky neshodují, převzetí neproběhne a platbu je potřeba přepárovat ručně.

### 30.11.16 Nespárovaná avíza

Karetní výdaje, bankovní poplatky a výběry se nemají s čím párovat, takže zůstanou v avízu jako nespárované a předchozí odstavec na ně nedosáhne - převzít u nich není co. Aby tentýž pohyb nezůstal v seznamu dvakrát, označí je import výpisu (a stejně tak tlačítko **Přepárovat výpis**) za **nahrazené oficiálním výpisem**: avízo dostane stav *Ignorováno* s poznámkou a v evidenci zůstane jediný pohyb - ten z výpisu. Kolik avíz se takhle odklidilo, ukáže hlášení po přepárování.

Podmínky jsou přísnější než u převzetí, protože tu chybí doklad, na kterém by se dvojice potkala:

- částka musí sedět **na haléř** (žádná tolerance na kurz blokace),
- avízo nesmí nic nést - žádnou evidovanou úhradu, mzdový signál ani zálohu na daň,
- den avíza musí spadat do **období, které importovaný výpis opravdu pokrývá** (jinak by výpis od 3. do 30. „nahradil“ avízo z 1., které v něm vůbec není),
- kandidát musí být **právě jeden**; dvě nerozlišitelná avíza zůstanou obě.

Nic se nepřepojuje, takže je to vratné: v detailu pohybu zrušte ignorování a avízo se vrátí mezi nespárované.

### 30.11.17 Cron pro e-mailová avíza

Pro automatické zpracování nastavte samostatný cron:

```bash
cmd/cron-bank-email-notices.sh   # každých 30 minut
```

Skript spustí `php api/bin/cron-bank-email-notices.php`, projde aktivní IMAP účty firmy, načte nejnovější zprávy podle limitu a zapíše heartbeat do plánovaných úloh.

### 30.11.18 Automatické zaúčtování bankovních transakcí (jen podvojné účetnictví)

Bankovní výpis ([Banka](29_Banka.md)) i e-mailové avízo řeší jen **párování na faktury**. Transakce, které s fakturou nesouvisí - bankovní poplatky, úroky, odvody sociálního a zdravotního pojištění, splátky leasingu, převody mezi vlastními účty - potřebují vlastní **účetní zápis** (MD/D dle [Předkontace](73_Ucetni_nastroje.md#73113-predkontace-kurzy-a-repo-sazba)). O to se stará fronta **K zaúčtování** na téže stránce a pravidla účtování, která se spravují v `Nástroje → Šablony účtování` na záložce **Pravidla účtování**. Viditelné jsou jen firmě s **podvojným účetnictvím**.

Kompletní popis (pravidla, režim **Návrh** a **Automaticky**, schvalování návrhů, založení pravidla přímo z platby) je v kapitole Banka, [§ 29.13.14 Pravidla účtování opakovaných plateb](29_Banka.md#291314-pravidla-uctovani-opakovanych-plateb) a [§ 29.13.14.1 Schvalování návrhů](29_Banka.md#2913141-schvalovani-navrhu).

## 30.12 Související kapitoly

- [Banka](29_Banka.md) - import výpisů, párování plateb, automatické zaúčtování a přímé napojení v souhrnu.
- [Platební karty](31_Platebni_karty.md) - karty k bankovním účtům.
- [Kreditní karty](112_Kreditni_karty.md) - úvěrové účty ke kreditním kartám.
- [Příchozí doklady a přijaté faktury](23_Prijate_faktury.md) - fronta příchozích dokladů z IMAP příloh.
- [Účtová osnova](66_Ucetni_osnova.md) - analytiky 221 a rozvrh.
- [Uzávěrka](72_Uzaverka.md) - přecenění cizoměnových účtů.
