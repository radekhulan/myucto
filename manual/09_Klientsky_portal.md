# 9. Klientský portál

> Návod, jak klientovi účetní firmy zřídit přístup, jak klient předává doklady
> a odpovídá na požadavky účetní a jak je účetní zpracuje. Pro superadmina,
> účetní a klienty (podnikatele, majitele firmy).

Klientský portál je zjednodušená domovská obrazovka pro roli **client**. Klient
se přihlašuje do stejného MyÚčto.cz jako účetní, jen vidí výrazně užší nabídku
menu. Smí vystavovat a upravovat vlastní doklady, dokud je účetní nezaúčtuje.

Administrátor může klientské roli zpřístupnit také **Peníze → Bankovní účty**. Čtení banky, nahrávání výpisů, párování a ignorování plateb jsou volitelná oprávnění; klient bez jejich přidělení banku neuvidí. Přístup se týká pouze jeho firmy, včetně přihlášení na klientské doméně. Podrobnosti popisuje kapitola [Banka](29_Banka.md).

Samostatně může správce povolit **Daně → EPO podání a archív**. Klient pak čte připravená přiznání k DPH,
kontrolní a souhrnná hlášení své firmy, stahuje archivované XML a potvrzení a vidí platební údaje DPH.
S dalším právem k podávání je může odeslat přes EPO nebo odesílací bránu datové schránky.
Výchozí klientská role tato práva nemá; postup a hranice přístupu popisuje
[Klientské podávání připravených výkazů](49_Archiv_podani_a_rekonciliace.md#4921-klientske-podavani-pripravenych-vykazu).

Superadmin má v nabídce **Dokumenty → Předat doklady účetní** také přístup
k předávání dokladů a odpovědím na požadavky účetní v právě vybrané firmě.
Zároveň mu zůstávají dostupné účetní funkce.

## 9.1 Kdy to potřebujete

Kapitolu otevřete, když:

- chcete klientovi zřídit přístup do aplikace,
- klient chce sám vystavit fakturu nebo nahrát přijatou fakturu,
- klient má účetní předat pytel účtenek a faktur a nechce nic přepisovat,
- vám chybí doklad k bankovní platbě a chcete ho po klientovi vyžádat,
- klient odpovídá na požadavek účetní o chybějící doklad,
- klient se diví, proč nemůže upravit fakturu (je zaúčtovaná),
- chcete klientovi dovolit upravit si e-maily a branding vlastní firmy,
- chcete klientovi přiřadit vlastní doménu, třeba `portal.klient.cz`.

<!-- cols: 22 40 38 -->
| Kdo | Co udělá | Kde v aplikaci |
|---|---|---|
| Superadmin | Založí klientský účet a přiřadí mu firmy | `Systém → Uživatelé` |
| Klient | Vystaví fakturu, nahraje přijatou fakturu, přidá kontakt | úvodní stránka **Přehled firmy**, rychlé odkazy |
| Klient, superadmin | Předá doklady účetní | `Dokumenty → Předat doklady účetní` |
| Klient | Odpoví na požadavek účetní | `Dokumenty → Chybějící doklady` |
| Účetní | Vyžádá chybějící doklad | `Firma → Chybějící doklady` |
| Účetní | Zpracuje předané doklady | `Nákup → Příchozí doklady` |
| Účetní, admin | Zkontroluje, co vidí klient | `Grafy → Přehled firmy` |

## 9.2 Než začnete

1. **Účet superadmina.** Klientské uživatele zakládá jen superadmin v `Systém → Uživatelé`.
2. **Aktivní role typu client.** Role se vybírá při zakládání uživatele. Role typu **readonly** je něco jiného: je to interní pracovník, který smí jen číst. Klient je externí osoba, která smí vystavovat a upravovat vlastní doklady, ale nevidí účetnictví, obecné reporty ani nastavení systému; banku a archiv připravených výkazů DPH, KH a SH vidí jen s výslovně přidělenými oprávněními (viz [§ 96.2.2 Role](96_Nastaveni.md#96164-role-a-opravneni)).
3. **Firma, ke které klient patří.** Bez přiřazené firmy klient po přihlášení uvidí jen prázdný stav. Přístup je záměrně zamčený: žádná firma mu není vidět, dokud ji superadmin nepřiřadí.
4. **Pro klientské nastavení firmy** musí mít jeho role právo **Zápis** alespoň u jedné provozní oblasti: odesílací profily, e-mailové šablony, branding, platební QR nebo firemní podpisové profily a PDF podpisy.
5. **Pro vlastní doménu** musí správce serveru zapnout funkci v konfiguraci (viz [§ 9.11.11](#91111-vlastni-domena-pravidla)).

## 9.3 Krok za krokem: Založení klientského účtu

Provede superadmin.

1. Otevřete `Systém → Uživatelé` a založte nového uživatele (formulář je stejný jako u ostatních rolí, viz [§ 96.2 Uživatelé](96_Nastaveni.md#964-krok-za-krokem-uzivatele-role-a-pristup-k-firmam)).
2. V poli **Role** zvolte aktivní roli typu **client**.
3. V sekci přiřazení firem našeptávačem přidejte firmy, ke kterým má klient přístup. Obvykle je to jedna, u víceoborových klientů víc.
4. U každé firmy ponechte výchozí klientskou roli, nebo vyberte jinou aktivní roli typu **client**. Interní roli typu **staff** aplikace odmítne.
5. Uložte.

**Jak poznáte, že je hotovo:** klient se po přihlášení dostane na **Přehled firmy** s údaji vaší firmy. U klienta s víc firmami přepíná firmy běžný přepínač ve spodní liště a celý portál se přepne s ním.

> [!TIP]
> Než klientovi předáte přístup, otevřete si `Grafy → Přehled firmy` na jeho firmě. Uvidíte přesně to, co klient, a ušetříte si dotazy typu „proč mi tohle nejde upravit".

Heslo, 2FA a profil (nabídka pod jménem vpravo nahoře) fungují pro klienta stejně jako pro ostatní role (viz [§ 96.3](96_Nastaveni.md#965-krok-za-krokem-muj-profil)). Klient si po prvním přihlášení může nastavit vlastní 2FA, pokud to instalace vyžaduje.

## 9.4 Krok za krokem: Předání dokladů účetní

Provede klient. Hodí se pro účtenky, faktury a další doklady, které nechce přepisovat.

1. Otevřete `Dokumenty → Předat doklady účetní`.
2. Klikněte na **Vybrat soubory** a vyberte soubory (najednou až 20, formáty PDF, JPG, PNG, ISDOC, XML nebo ISDOCX).
3. Podle potřeby doplňte **Poznámka pro účetní (nepovinné)** a **Typ dokladu (nepovinné)**. Používá-li firma střediska, vyberte i středisko, kterého se doklad týká.
4. Klikněte na **Předat účetní**.

**Jak poznáte, že je hotovo:** soubory se objeví v přehledu pod nadpisem **Čeká na vyřízení** se stavem **Předáno**. Aplikace potvrdí počet předaných souborů.

Každý soubor vytvoří samostatné podání. Originál se uloží do Dokumentů a nic se neúčtuje, dokud ho účetní nezpracuje.

Pokud chcete doklad sami vytvořit bez přepisování, můžete využít **Nákup → AI import**. Správce musí u vaší firmy
v **Firma → AI nastavení** zapnout **Povolit klientům AI vytěžování** a nastavit aktivního poskytovatele AI.
Vaše role musí umožňovat vytěžování i vytváření přijatých faktur. Nahrajete doklad, zkontrolujete vytvořený koncept
a na jeho detailu zvolíte **Označit jako přijaté**. Přijetí respektuje případné schvalování firmy. Teprve přijatý doklad
se podle pravidel přehledu promítne do nákladů; samotný koncept se nezapočítává. Postup je stejný jako při ručním zadání,
jen údaje přečte AI. Před nahráním vidíte měsíční čerpání společného limitu firmy pro vytěžování přijatých dokladů. Jeden platný soubor předaný ke zpracování
spotřebuje jeden pokus i při chybě, duplicitě nebo načtení ISDOC. Pokusy se nevracejí a čerpání se obnovuje každý měsíc.
Po vyčerpání můžete zadávat ručně nebo požádat účetní o zvýšení limitu. Použitý model nastavuje účetní. Podrobnosti najdete v [AI extrakci](25_AI_extrakce.md#254-krok-za-krokem-importovat-prijate-faktury-z-pdf).

Tlačítko **Náhled** otevře PDF nebo obrázek na široké obrazovce vpravo vedle přehledu, na menší obrazovce v dialogu. Výběrem jiného dokladu se náhled přepne. Vlastní předání ve stavu **Předáno** můžete přes **Smazat předání** odstranit, dokud účetní nezačala zpracování. Potvrzení vyřadí podání z přehledu a přesune originál do koše Dokumentů. Pokud doklad odpovídal na požadavek účetní, požadavek se znovu otevře.

Když účetní vyžádá čitelnější soubor, stav je **Čeká na doplnění**. Při odmítnutí uvidíte stav **Odmítnuto** a důvod účetní. V obou případech klikněte na **Nahrát náhradu** a vyberte nový soubor. Původní podání zůstane v historii a další náhradu už nenabízí.

### 9.4.1 Předání přímo z editoru přijaté faktury

Když v editoru přijaté faktury nahrajete běžné PDF nebo fotografii a údaje se nenačtou, můžete je vyplnit ručně. Nebo klikněte na **Uložit a předat účetní**. Formulář se nezaloží jako neúplná faktura a původní soubor skončí ve stejné podatelně jako při předání dokladů.

## 9.5 Krok za krokem: Odpověď na požadavek účetní

Provede klient.

1. Na úvodní stránce klikněte na pruh „Účetní čeká na … chybějící doklad(y)" (odkaz **Nahrát →**), nebo otevřete `Dokumenty → Chybějící doklady`.
2. U požadavku si přečtěte popis, částku, datum a termín (po termínu je červený).
3. Klikněte na **Nahrát doklad** a vyberte soubor (stejné formáty jako při předání dokladů).

**Jak poznáte, že je hotovo:** požadavek se přepne na **Čeká na kontrolu účetní** a aplikace potvrdí „Doklad byl předán účetní ke kontrole." Po vyřízení účetní požadavek přesune do sekce **Vyřízené**.

Samotné nahrání nic nezaúčtuje a faktura v té chvíli ještě nevzniká.

## 9.6 Krok za krokem: Vyžádání dokladu od klienta

Provede účetní.

1. Otevřete `Firma → Chybějící doklady` a klikněte na **Nový požadavek**.
2. Vyplňte popis (co chybí), volitelně částku, kontextové datum a termín.
3. Požadavek uložte.

Rychlejší cesta pro nespárovanou platbu:

1. V detailu bankovního výpisu (`Peníze → Bankovní účty`, výpis) najděte nespárovanou transakci.
2. V řádkových akcích klikněte na **Vyžádat doklad**. Popis se předvyplní z částky a data platby (např. „Chybí doklad k platbě 4 520 Kč z 12. 6.") a požadavek se naváže na tu transakci.

**Jak poznáte, že je hotovo:** požadavek je v seznamu ve stavu Vyžádáno a klient ho vidí v portálu.

Seznam lze filtrovat podle stavu (Vyžádáno, Nahráno - čeká na kontrolu, Vyřízeno). Po zpracování podání vede sloupec **Doklad** na vzniklou přijatou fakturu. Tlačítko **Uzavřít** potvrdí vyřízení i bez nahrání (třeba když doklad dorazil jinou cestou). Omylem uzavřený požadavek vrátíte tlačítkem **Znovu otevřít**.

## 9.7 Krok za krokem: Zpracování příchozích dokladů

Provede účetní.

1. Otevřete `Nákup → Příchozí doklady`. V seznamu můžete filtrovat podle stavu a u každého podání vidíte poznámku klienta, náhled PDF nebo obrázku a tlačítko pro stažení originálu.
2. Zvolte jednu z možností:
   - **Vytěžit a vytvořit** - ISDOC a ISDOCX se zpracují deterministicky, běžné PDF nebo fotografie mohou při přiděleném oprávnění pokračovat přes AI. Po vytvoření se otevře editor výsledného konceptu ke kontrole.
   - **Přepsat ručně** - vyplníte přijatou fakturu s originálem vedle formuláře.
   - Do pole **Zpráva klientovi** napište důvod a klikněte na **Vyžádat náhradu** nebo **Odmítnout**.
3. Zkontrolujte a uložte přijatou fakturu.

**Jak poznáte, že je hotovo:** originál je připojený k přijaté faktuře a podání má stav **Zpracováno**. Klient fakturu vidí, ale nemůže měnit její hlavičku, položky, přílohy ani stav.

Odmítnutí vždy vyžaduje důvod, který klient uvidí. Originál zůstane v Dokumentech i v auditní stopě. Odmítnout lze také podání čekající na doplnění. Samostatná akce **Správa originálu → Přesunout do koše** vyřadí nezpracované podání z fronty a přesune jeho originál do koše Dokumentů; vyžaduje oprávnění k mazání. Doklad navázaný na účetnictví ani vyřízený požadavek takto odstranit nelze. Na hromadné akce slouží tlačítka **Vytěžit označené**, **Odmítnout označené** a **Smazat označené z fronty**.

## 9.8 Krok za krokem: Nastavení firmy klientem

Provede superadmin a potom klient. Samostatná role Client Admin není potřeba.

1. Superadmin vytvoří nebo duplikuje běžnou roli typu **client** a zvolí **Zápis** u konkrétních provozních oprávnění, která chce delegovat (například **Branding firmy**).
2. Roli přiřadí klientovi. Lze ji přiřadit jako přepis jen u jedné firmy: ve firmě A může mít klient roli se zápisem, ve firmě B zůstane u běžné klientské role.
3. Klient otevře `Firma → Nastavení firmy` a upraví povolené oblasti. Firemní podpisové profily jsou dostupné v samostatné části této stránky; lze v ní nastavit podpis PDF a propojit profil s odesílacím profilem pro podepisování e-mailů.

**Jak poznáte, že je hotovo:** klientovi se v menu objeví sekce **Nastavení firmy**. Zobrazí se pouze povolené záložky; bez konkrétního oprávnění k zápisu se editor nezobrazí. Po přepnutí firmy se menu i oprávnění ihned přepočítají.

Co klient smí měnit, popisuje [§ 9.11.2](#9112-delegovane-nastaveni-firmy).

## 9.9 Krok za krokem: Vlastní doména portálu

Provede oprávněný správce firmy. Funkci musí nejdřív zapnout správce serveru.

1. Správce serveru v `cfg.php` nastaví `'domains' => ['enabled' => true]`. Dokud je funkce vypnutá, karta s doménami se nenabízí.
2. Správce firmy otevře `Firma → Nastavení`, na záložce **Údaje firmy** najde sekci **Klientské domény** a klikne na **Přidat doménu**.
3. Zadejte **Hostname** a **Účel**: **Klientské rozhraní**, **Veřejné odkazy**, nebo **Klientské rozhraní i veřejné odkazy**.
4. V DNS vytvořte zobrazený TXT záznam a nastavte reverse proxy tak, aby hostname obsloužil HTTPS s důvěryhodným certifikátem.
5. Klikněte na **Ověřit DNS a HTTPS** a potom na **Aktivovat**. Aktivaci potvrďte passkey nebo TOTP.

**Jak poznáte, že je hotovo:** doména má stav **Aktivní** a nově odeslané odkazy používají primární doménu.

Postup ověření a aktivace popisuje [§ 96.16 Vlastní domény](96_Nastaveni.md#9612-krok-za-krokem-vlastni-domeny-klientskeho-rozhrani). Pravidla domén jsou v [§ 9.11.11](#91111-vlastni-domena-pravidla).

## 9.10 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Váš účet zatím není propojen s žádnou firmou. Kontaktujte svou účetní." | Klient nemá přiřazenou firmu | Superadmin přidá firmu v `Systém → Uživatelé` (viz [§ 9.3](#93-krok-za-krokem-zalozeni-klientskeho-uctu)) |
| Zatím tu nejsou žádná data | Nová firma bez dokladů | Vystavte první fakturu nebo nahrajte přijatou, přehled se začne plnit |
| Badge **Zaúčtováno**, chybí úprava, storno a mazání | Doklad je zaúčtovaný nebo spadá do uzavřeného období | Změnu a storno vyřídí účetní (viz [§ 9.11.5](#9115-zamek-zauctovanych-dokladu)) |
| Klient nevidí Banku | Bankovní oprávnění jsou ve výchozím stavu vypnutá | Administrátor může v klientské roli povolit prohlížení banky a jednotlivé bankovní akce |
| Klient nevidí Sklad, Reporty ani Nastavení | Role client je záměrně omezená | Je to správně; přímý odkaz klienta přesměruje na portál |
| Klient nevidí **Nastavení firmy** | Role nemá **Zápis** u žádné provozní oblasti | Superadmin upraví roli (viz [§ 9.8](#98-krok-za-krokem-nastaveni-firmy-klientem)) |
| „Nově předáno: X, již evidováno: Y" | Stejný soubor už byl předán | Nic, shodný soubor se nerozmnoží |
| Soubor nejde nahrát jako náhrada | Shodný soubor nelze vydávat za opravený | Nahrajte opravený (jiný) soubor |
| Přijatou fakturu nejde převést do stavu **Zaúčtováno** | Tento přechod patří jen účetní a adminovi | Požádejte účetní |
| Doklad v příchozí frontě už nejde ručně zpracovat | Mezitím byl zpracován | Obnovte příchozí frontu |
| Tento formát nemá náhled v prohlížeči | Typ souboru (např. ISDOC) | Stáhněte si originál |
| Karta s doménami chybí | Funkce není zapnutá v `cfg.php` | Požádejte správce serveru (viz [§ 9.9](#99-krok-za-krokem-vlastni-domena-portalu)) |
| Nová doména nezobrazuje data firmy | Hostname je neznámý, neověřený, deaktivovaný nebo má jiný účel | Dokončete ověření a aktivaci, zkontrolujte účel domény |

## 9.11 Podrobnosti a pravidla

### 9.11.1 Menu klienta

Menu běžného klienta má pět sekcí:

- **Přehled** (domovská stránka),
- **Prodej** (vydané faktury a pravidelná fakturace),
- **Nákup** (přijaté faktury),
- **Kontakty** (klienti a dodavatelé),
- **Dokumenty** s položkami **Předat doklady účetní** a **Chybějící doklady**.

Na desktopu jsou sekce v horní liště s popup položkami, na mobilu v nabídce **☰**. Nápovědu otevírá ikona **?** v horní liště.

Cokoliv jiného klient v menu nevidí: účetnictví, bankovní výpisy, sklad, e-shop, reporty, Grafy, kniha jízd, DMS dokumenty, systémové nastavení a správu uživatelů. Při pokusu otevřít takovou stránku přes adresu ho aplikace přesměruje na portál. Omezení je vynucené na frontendu i v API, ruční úpravou adresy se obejít nedá.

Uvnitř povolených sekcí klient **není v režimu jen pro čtení**. Smí, pokud doklad ještě nebyl zaúčtovaný (viz [§ 9.11.5](#9115-zamek-zauctovanych-dokladu)):

- **Vydané faktury** - založení, editace, vystavení, odeslání e-mailem, přijaté platby, přílohy, storno, klonování, výkaz práce k faktuře.
- **Přijaté faktury** - založení, nahrání PDF nebo ISDOC, editace položek, přechod mezi stavy přijatá a zaplacená.
- **Pravidelná fakturace** - správa šablon (šablony nejsou účetní doklad, takže se nikdy nezamykají).
- **Kontakty** - zákazníci i dodavatelé, včetně vyhledání firmy přes ARES a VIES.

Citlivější podsekce zůstávají klientovi nedostupné: platební příkazy (bankovní ABO a KPC soubory, ověřování účtů), sken e-mailové schránky účetní a DMS dokumenty k přijatým fakturám. Zakázky a knihu jízd lze povolit jejich vlastními oprávněními. U skladu lze delegovat čtení, správu skladových karet a expedici; inventury a uzávěrky zůstávají interní správě. Bankovní výpisy se řídí samostatnými bankovními oprávněními. E-shop, daňová evidence a účetní deník zůstávají klientovi zavřené.

### 9.11.2 Delegované nastavení firmy

Každá provozní oblast má samostatné oprávnění **Zápis**. Klient vidí a spravuje pouze povolené záložky aktuální firmy:

- odesílací profily, odesílatele, Reply-To, DKIM a SMTP/IMAP nastavení firmy,
- firemní podpisové profily s podporovaným nativním backendem a šifrovaně uloženým heslem k certifikátu; klientský správce může nahrát P12/PFX, nastavit použití pro PDF a vybrat profil v odesílacím profilu pro S/MIME e-mail,
- obsah e-mailových šablon firmy v češtině a angličtině,
- branding komunikace, logo, barvy, patičky a brandingové profily,
- dvě samostatné volby, zda do nově generovaných platebních QR kódů vystavených a přijatých dokladů zahrnout datum splatnosti.

Firemní šablony otevřete na záložce **E-mailové šablony** v `Nastavení firmy`. U každého typu zprávy a jazyka vidíte, zda se používá vlastní firemní, společná nebo výchozí šablona. Úprava uloží vlastní kopii firmy; volba **Používat společnou šablonu** ji odstraní. Editor nabízí přehled proměnných a náhled na syntetických datech. Seznam typů a pravidla dědění popisuje [§ 96.6](96_Nastaveni.md#966-krok-za-krokem-e-mailove-sablony).

Zápis neotevře původní administrátorskou stránku nastavení ani obecné nastavení dodavatele. Klient proto nemůže měnit obchodní jméno, IČ, DIČ, bankovní účty, číslování dokladů, DPH, účetní režim, integrace, přístupové údaje AI, uživatele ani role. Osobní podpisové profily, osobní trezor certifikátů, nepodporované backendy a systémový `sendmail` zůstávají ve správě administrátora.

SMTP a IMAP hesla se po uložení nikdy nevracejí do prohlížeče, rozhraní ukáže jen informaci, zda je heslo nastavené. Změny i testovací odeslání se zapisují do historie akcí bez tajných hodnot a požadavky podléhají stejnému rate limitu jako ostatní mutace. Všechny operace používají firmu z ověřeného kontextu, ID firmy zaslané v těle požadavku rozsah nerozšíří.

### 9.11.3 Co portál zobrazuje

Úvodní stránka klienta je agregovaný přehled hospodaření aktuální firmy. Jsou na ní jen souhrnná čísla, **žádná jména zákazníků nebo dodavatelů ani čísla dokladů** (záměrné bezpečnostní omezení, platí i pro náhled účetní a admina). V záhlaví je název firmy, rozsah období (od 1. 1. do dneška) a poznámka, že jde o orientační přehled, ne účetní závěrku. Data se agregují živě při každém načtení stránky, nejde o cache.

#### KPI dlaždice

Karty ukazují **fakturováno / náklady / rozdíl** za pět období: **Tento měsíc**, **Minulý měsíc**, **Letos (YTD)**, **Loni do dneška** a **Posledních 12 měsíců**. Při více měnách má každá karta řádek za každou měnu zvlášť, částky se napříč měnami nesčítají.

#### Měsíční graf

Sloupcový graf fakturace a nákladů za posledních 12 měsíců. Při více měnách je nad grafem přepínač měny (výchozí CZK, jinak první dostupná).

#### Cashflow

Tři karty vedle sebe:

- **Pohledávky** - neuhrazené vydané faktury podle stáří: nesplatné, po splatnosti 1-30, 31-60, 61-90 a 90+ dní, s počtem dokladů a součtem za měnu.
- **Závazky** - totéž pro nezaplacené přijaté faktury.
- **Výhled 4 týdnů** - týdenní čistý cashflow (očekávané příjmy minus výdaje) na 4 týdny dopředu a součet za celé období, červeně nebo zeleně podle znaménka.

#### DPH a daňové termíny

U plátců DPH ukazuje karta **DPH** aktuální **Období**, daň na výstupu, daň na vstupu, výslednou povinnost (nebo nadměrný odpočet) a **Termín podání**. U neplátců se zobrazí „Firma není plátce DPH." Karta **Daňové termíny** vypisuje termíny v okně 35 dní dopředu, barevně podle závažnosti (viz [Výkazy DPH](41_Vykazy_DPH.md)). Bez blízkých termínů ukáže „Žádné blízké termíny."

#### Pruh „Účetní čeká na doklady"

Má-li klient otevřené požadavky, zobrazí se pod záhlavím pruh s počtem a odkazem na **Chybějící doklady**. Po termínu je červený, jinak žlutý. Zmizí, jakmile klient všechny otevřené požadavky vyřídí nebo je účetní uzavře jinak.

#### Prázdný stav

Klient bez přiřazené firmy vidí zprávu, že účet není propojen s žádnou firmou, s výzvou kontaktovat účetní (viz [§ 9.3](#93-krok-za-krokem-zalozeni-klientskeho-uctu)). Nová firma bez dokladů vidí stav „Zatím tu nejsou žádná data".

### 9.11.4 Rychlé akce

Odkazy **Vystavit fakturu**, **Nahrát přijatou fakturu** a **Přidat kontakt** vedou podle oprávnění na plné editory [Faktur](14_Faktury.md), [Přijatých faktur](23_Prijate_faktury.md) a [Klientů](18_Klienti.md).

### 9.11.5 Zámek zaúčtovaných dokladů

Jakmile účetní doklad zaúčtuje, stává se pro klienta needitovatelným. Doklad zamyká kterákoli z těchto podmínek:

- doklad má vyplněné **datum zaúčtování** (u vydaných faktur ho nastaví zaúčtování do deníku nebo účetní ručně, u přijatých odpovídá stavu **Zaúčtovaná**),
- existuje aktivní zápis v účetním deníku vázaný na doklad,
- firma vede podvojné účetnictví a datum dokladu spadá do **uzavřeného** nebo **schváleného** účetního období,
- probíhá závěrka období - to zamyká **jen klienta**, účetní s doklady v té fázi dál pracuje,
- přijatá faktura vznikla z originálu, který klient předal přes podatelnu - zůstává ve správě účetní i jako koncept.

Zamčený doklad ukazuje badge **Zaúčtováno** s textem „Doklad je zaúčtovaný - změny a storno vyřídí vaše účetní." (u uzavřeného období obdobný text). Akce úprava, storno, smazání, přidání nebo zrušení platby a napojení zálohy z menu detailu zmizí (nejsou jen zašedlé). Dostupné zůstávají akce, které doklad nemění (zobrazení, PDF, odeslání e-mailem, přidání přílohy) nebo vytvářejí nový doklad (klonování, daňový doklad k platbě).

U přijatých faktur smí klient přepnout stav na **Zaplaceno** i zpět i bez zaúčtování, protože přeznačení „zaplaceno" není účetní úkon. Přechod do stavu **Zaúčtováno** klient nikdy neudělá, ten patří účetní a adminovi.

> [!WARNING]
> Zámek je serverová záležitost, badge ve frontendu je jen informativní. Požadavek na úpravu zamčeného dokladu API odmítne i mimo běžné UI. Jakmile účetní zaúčtování stornuje (zápis zruší), doklad se klientovi znovu odemkne.

Pro účetní a admina funguje zámek jinak. Otevřené období smí upravovat vždy. U uzavřeného období dostanou informativní chybu místo tichého zamítnutí a admin si může úpravu vynutit (s automatickým záznamem do historie akcí). Detaily vynucené editace řeší [§ 55 Bezpečnost - RBAC](101_Bezpecnost.md).

### 9.11.6 Náhled portálu pro účetní a admina

V menu `Grafy → Přehled firmy` vidí admin a účetní u zvolené firmy totéž, co klient. Slouží to jako kontrola „co vidí klient" i jako samostatný přehled hospodaření. Náhled nemá vlastní zámek ani omezení navíc a nijak neomezuje, co smí účetní nebo admin jinde.

### 9.11.7 Omezení a tipy

- Přepnutí firmy v horní liště (u víceoborového klienta) přenačte portál i všechny povolené stránky.
- Klient nemá přístup k osobním přístupovým tokenům (API), ani kdyby našel odkaz na stránku, API vrátí zamítnuto.
- Klient může mít u konkrétní firmy jinou klientskou roli. Přepis musí být aktivní a stejného typu **client** jako výchozí role.

### 9.11.8 Podatelna dokladů

Podatelna podporuje oba směry: klient doklad předá spontánně (push), nebo odpoví na konkrétní požadavek účetní (pull). V obou případech se nejdřív uloží neměnný originál do Dokumentů a vznikne samostatné podání mimo účetnictví. Dokud ho účetní nezpracuje, nejde o přijatou fakturu, nevstupuje do nákladů, cashflow, DPH ani kontrolního hlášení.

Stavy podání: **Předáno**, **Zpracovává se**, **Čeká na doplnění**, **Zpracováno**, **Odmítnuto**. Originál lze vždy zobrazit nebo stáhnout. Původní podání zůstává po nahrazení v auditní stopě. Opakovaný upload bitově shodného souboru se podle SHA-256 nerozmnoží. Používá-li firma střediska ([§ 114 Dimenze](114_Dimenze.md#114116-dimenze-pri-vytezeni-a-nahrani-dokladu)), vybrané středisko dostane účetní rovnou do hlavičky vytěženého dokladu.

Po úspěšném zpracování se originál připojí k výsledné přijaté faktuře. Automatická extrakce je jen jedna z možností kontroly (viz [§ 25 AI extrakce přijatých faktur](25_AI_extrakce.md)).

### 9.11.9 Notifikace a upomínky

Dashboard účetní i domovská stránka klienta ukazují počet otevřených požadavků jako barevnou dlaždici nebo pruh (červeně, je-li aspoň jeden po termínu), s proklikem na příslušnou stránku. Pokud klient nereaguje, denní úloha `cron-document-request-reminders.php` pošle po výchozích 3 dnech e-mailovou urgenci (šablona **Chybí doklad** v e-mailových šablonách, upravitelná jako ostatní) a opakuje ji nejdřív po 7 dnech. Obojí lze při spuštění přenastavit parametry `--days` a `--cooldown`.

### 9.11.10 Oprávnění a izolace firem

Klient vidí vždy jen požadavky vlastní aktuálně zvolené firmy (stejný princip jako zbytek portálu, viz [§ 9.3](#93-krok-za-krokem-zalozeni-klientskeho-uctu)). Cizí požadavek vrátí 404, ne 403, aby se neprozrazovala ani jeho existence.

Oprávnění **Předávat doklady účetní** patří jen klientským rolím. Oprávnění **Příchozí doklady** je interní a odděluje čtení fronty od jejího zpracování. Přístup k frontě sám nenahrazuje oprávnění vytvořit přijatou fakturu ani použít AI extrakci.

### 9.11.11 Vlastní doména: pravidla

Každá firma může vedle výchozí adresy instalace (`app.url`) používat vlastní doménu. Pokud žádnou aktivní nemá, portál i veřejné odkazy fungují beze změny na výchozí adrese. Provozní důsledky zapnutí popisuje kapitola Nastavení, část o vlastních doménách klientského rozhraní.

Účely domény:

- **Klientské rozhraní** - celý rozsah stránek, které dovoluje klientská role: přehled, vydané a přijaté faktury, pravidelná fakturace, kontakty, předávání dokladů a osobní profil.
- **Veřejné odkazy** - webové faktury, schvalování a výkazy práce.
- **Klientské rozhraní i veřejné odkazy** - oba předchozí účely.

Firma může mít víc aktivních aliasů, ale pro každý účel nejvýše jednu primární doménu. Nově odesílané odkazy používají primární doménu, starší canonical odkazy na `app.url` zůstávají platné. Deaktivace poslední použitelné domény vrátí nové odkazy na `app.url`.

Aktivní vlastní doména jednoznačně určuje firmu. Přepínač firem se na ní nezobrazuje a firmu nelze podvrhnout hlavičkou, parametrem URL ani API tokenem. Uživatel musí mít k firmě stále platné přiřazení. Výjimkou je globální superadmin, který má přístup ke všem firmám. Na vlastní doménu firmy se dostane i bez přiřazení, předání přihlášení ale musí vždy výslovně potvrdit, i když se právě přihlásil. Jeho relace pak platí jen na této doméně, jen pro tuto firmu a jen pro klientské rozhraní. Stav a účel domény, vazba hostname na firmu, canonical přihlášení, PKCE a bezpečná návratová cesta se ověřují pro všechny stejně. Veřejný token jiné firmy na této doméně vrátí „nenalezeno", stejný token na canonical adrese zůstává funkční.

Vlastní doména zpřístupní jen klientské agendy podle skutečných oprávnění uživatele. Zakázky, platební příkazy, příchozí fronta účetní, API tokeny, systémová nastavení a ostatní interní agendy na ní nejsou dostupné, jejich přímý odkaz se otevře na canonical adrese `app.url`. Na canonical adrese se v novém panelu otevírá také uživatelský manuál.

Neznámý, neověřený, deaktivovaný nebo účelově nekompatibilní hostname nikdy nezobrazí data firmy.

### 9.11.12 Přihlášení a passkeys na vlastní doméně

Přihlášení se dokončuje na canonical adrese z `app.url`, kde jsou zaregistrované passkeys a WebAuthn RP ID. Po heslu, TOTP nebo passkey se prohlížeč vrátí na přesnou původně otevřenou stránku vlastní domény jednorázovým krátkodobým kódem svázaným s PKCE. Session token se v URL nepřenáší, cílová doména dostane novou host-only cookie, kterou jiná doména nemůže číst. Takto vzniklou relaci server přijme jen na této vlastní doméně, na hlavní adrese ani na jiné doméně neplatí. Na vlastní doméně naopak neplatí relace z hlavní adresy.

Přihlášení zahájené na vlastní doméně přihlásí uživatele do portálu. Pokud kvůli ověření vznikne dočasná relace na hlavní adrese, při předání do portálu se ukončí. Samostatné přihlášení na hlavní adrese, které existovalo už před otevřením portálu, zůstává zachované.

Po odhlášení portál zobrazí přihlašovací obrazovku a zůstane odhlášený i po obnovení stránky. Další přihlášení zahájí až tlačítko **Přihlásit se**. Pokud je uživatel stále samostatně přihlášený na hlavní adrese, může se toto přihlášení při návratu do portálu použít bez dalšího zadávání hesla.

Předání existujícího přihlášení z hlavní adresy ale vždy vyžaduje výslovné potvrzení. Hlavní adresa zobrazí obrazovku **Přihlásit se do klientského rozhraní** s přesným hostname vlastní domény a názvem firmy. Přihlášení se předá až po kliknutí na **Pokračovat**, tlačítko **Zrušit** požadavek zahodí a nechá uživatele na hlavní adrese. Pokračujte jen tehdy, když jste přihlášení do portálu na této adrese sami zahájili. Kdo ovládá DNS vlastní domény, může totiž odkaz na předání podstrčit, a bez potvrzení by po jediném kliknutí získal relaci portálu přihlášeného uživatele. Pokud se uživatel na hlavní adrese přihlašuje teprve v rámci tohoto předání (heslem, TOTP nebo passkey), potvrzením je samotné přihlášení a další krok se nezobrazuje. Výjimkou je superadmin bez přiřazení k firmě, kterému se potvrzení zobrazí vždy. Stejně tak server bez potvrzení odmítne předat relaci, která vznikla dřív než požadavek na přihlášení, a to i po odemčení zamčené relace.

Na canonical adrese probíhá také **správa přístupových klíčů** a vynucené první nastavení MFA. WebAuthn klíče jsou svázané s RP ID a originem z `app.url`, takže vlastní doména tyto obrazovky neotevře s nefunkčním bezpečnostním dialogem. Místo toho zahájí jednorázový přechod na canonical adresu a po dokončení se vrátí na předchozí bezpečnou stránku vlastní domény. Po úspěšném přidání klíče proběhne návrat automaticky a dočasná relace na hlavní adrese se ukončí. Bez přidání klíče se vraťte tlačítkem **Vrátit se do klientského rozhraní**. Pokud návrat selže, obrazovka zobrazí chybu a umožní jej zopakovat. Cílová firma i hostname jsou po celou dobu svázané se serverovým požadavkem a nelze je změnit parametrem návratové URL.

Při zamčení session server na vlastní doméně odmítne přímé WebAuthn options i verify. Zamykací obrazovka zahájí nové ověření na canonical originu a po jednorázovém PKCE návratu vytvoří novou host-only session, přičemž zachová aktuální klientskou stránku. Vlastní doména je originem celého klientského rozhraní, ne druhým originem interních agend účetní a správce. Povolení se neurčuje podle prefixu `/portal`, ale podle stejného katalogu klientských oprávnění, který řídí menu, router i API. Staré adresy `/exchange`, `/admin/export` a `/admin/import` se nejprve převedou na skutečný klientský cíl, prefix `/admin` jim tedy přístup ani nedává, ani nebere.

Postup ověření a aktivace popisuje [§ 96.16 Vlastní domény](96_Nastaveni.md#9612-krok-za-krokem-vlastni-domeny-klientskeho-rozhrani).

### 9.11.13 Banka a úhrady dokladů

Administrátor může klientské roli v `Systém → Role a oprávnění` samostatně přidělit **Prohlížet banku**, **Nahrávat výpisy**, **Párovat platby** a **Ignorovat platby**. Ve výchozím stavu jsou vypnutá. Bankovní akce vyžadují také prohlížení banky. Klient pak vidí výpisy, avíza, pohyby a zůstatky vlastní firmy i na její klientské doméně.

Párování vytvoří úhradu faktury, zrušení párování ji odstraní. Klient proto potřebuje čtení všech dotčených faktur a zápis do **Změnit úhradu** (`invoices.mark_paid`) u vydaných, případně **Změnit stav přijaté faktury** (`purchase_invoices.transition`) u přijatých. U sloučené úhrady se kontrolují všechny doklady. Nahrát výpis může klient jen tehdy, když má zápis do obou těchto oprávnění, protože import páruje a uhrazuje vydané i přijaté faktury automaticky. Import a párování zachovávají automatické zpracování podle nastavení firmy; mohou vytvořit úhrady a účetní zápisy pod přihlášeným klientským uživatelem.

Výpis lze nahrát jen k již založenému účtu vlastní firmy. Klient může s oprávněním ignorování odložit nespárovanou platbu s poznámkou a vrátit ruční ignorování. Obnova systémově potlačených pohybů, ruční účtování, správa bankovních účtů, napojení a pravidel i mazání výpisů zůstávají interním pracovníkům. Podrobný postup popisuje [Banka](29_Banka.md).

## 9.12 Související kapitoly

- [Faktury](14_Faktury.md) a [Přijaté faktury](23_Prijate_faktury.md) - doklady, které klient zakládá
- [AI extrakce přijatých faktur](25_AI_extrakce.md) - vytěžení předaných dokladů
- [Banka](29_Banka.md) - vyžádání dokladu k nespárované platbě
- [Nastavení](96_Nastaveni.md) - uživatelé, role, vlastní domény
- [Bezpečnost - RBAC](101_Bezpecnost.md) - vynucená editace a oprávnění
