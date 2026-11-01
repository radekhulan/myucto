# 105. MCP server (napojení AI asistenta)

> Návod, jak připojit AI asistenta (Claude, ChatGPT, Codex, Gemini, Copilot)
> k datům vaší firmy: zapnutí vzdáleného serveru, připojení asistenta, lokální
> varianta se souborem `.mjs`, odvolání přístupu a kontrola logu. Na konci jsou
> pravidla, co asistent smí a nesmí.

MCP server propojí **AI asistenta** s daty vaší firmy. Po zprovoznění se ptáte
běžnou češtinou („kolik zaplatíme na DPH", „kdo nám dluží", „jaký byl loni
zisk") a asistent si sám vybere správný nástroj a zavolá ho přes
[REST API](103_API.md).

Nastavení je v aplikaci: `Firma → MCP server`. Stránka má dvě záložky:
**Připojit online** pro vzdálený MCP server běžící přímo v této instalaci a
**Spustit lokálně (.mjs)** pro server na vašem počítači. Každá instalace má
vlastní adresu; žádný společný server MyÚčta není potřeba.

## 105.1 Kdy to potřebujete

- Chcete se asistenta ptát na faktury, pohledávky, DPH, zakázky, dokumenty,
  sklad nebo mzdy.
- Chcete, aby asistent připravil koncept faktury, přijaté faktury, zakázky
  nebo skladové příjemky.
- Asistent hlásí, že server neodpovídá, nebo nevidí žádné nástroje.
- Chcete asistentovi přístup odebrat.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde |
|---|---|---|
| jednou po instalaci | Superadmin zapne vzdálený server | `Firma → MCP server`, **Zapnout server** |
| pro každého asistenta | Připojit konektor | [§ 105.4](#1054-krok-za-krokem-pripojeni-asistenta-online) |
| na počítači bez internetového přístupu k instalaci | Spustit lokální soubor `.mjs` | [§ 105.5](#1055-krok-za-krokem-lokalni-server-mjs) |
| po zkušebním dotazu | Zkontrolovat log volání | [§ 105.6](#1056-krok-za-krokem-odvolani-pristupu-a-kontrola-logu) |
| při změně pracovníka nebo asistenta | Odvolat přístup | stejná stránka, **Aktivní připojení** |

## 105.2 Než začnete

1. **Model dat.** MCP server nevytváří vlastní kopii dat. Výsledek nástroje
   ale dostane připojený AI asistent a může jej podle svého provozního modelu
   odeslat poskytovateli AI. Citlivost dotazu posuzujte stejně jako při ručním
   vložení údajů do daného asistenta.
2. **Právo.** Asistent má jen schválený přístup. U online připojení ho omezuje
   souhlas uživatele, aktuálně dostupné firmy, rozsah a role. U lokálního
   připojení platí rozsah, vazba na firmu, omezení podle IP a oprávnění role
   vydaného API tokenu.
3. **Adresa instalace.** Pro online připojení musí mít doména veřejný DNS
   záznam, být dostupná z internetu přes HTTPS a mít certifikát od veřejně
   důvěryhodné autority. Lokální adresa ani certifikát vlastní autority
   nestačí: ChatGPT i Claude se připojují ze svých serverů.
4. **Superadmin.** Vzdálený MCP server po instalaci **vypnutý** zapíná a vypíná
   superadmin instalace.
5. **Node.js** (pro lokální soubor `.mjs`, Node 20 nebo novější; Windows:
   `winget install --id OpenJS.NodeJS.LTS --exact`, macOS: `brew install node`).
   Pro online připojení není Node na vašem zařízení potřeba.

> [!WARNING]
> Do účetnictví a daní asistent nezapisuje. Zaúčtovat doklad, uzavřít období,
> zaevidovat opravu podle § 46 / § 74b ani odeslat podání na EPO nemůže.
> Mzdy: může jen číst a zapisovat připravované vstupy, nikdy mzdy nepočítá,
> neschvaluje, neodesílá. Podrobnosti v [§ 105.8.2](#10582-rozsah-co-asistent-umi).

## 105.3 Krok za krokem: zapnutí vzdáleného serveru (superadmin)

1. Otevřete `Firma → MCP server`, záložku **Připojit online**.
2. Zkontrolujte stav v části **Vzdálený MCP server**. Je-li **Vypnuto**,
   klikněte na **Zapnout server**.
3. Chybí-li Node.js v prostředí, které spouští MCP, stránka ukáže upozornění a
   zapnutí nedovolí. Doinstalujte Node.js, ověřte jeho dostupnost pro aplikaci
   a stránku načtěte znovu.
4. Klikněte na **Otestovat nástroje**.

**Jak poznáte, že je hotovo:** Stav je **Zapnuto** a test hlásí „Server nabízí
{počet} nástrojů pro čtení". Adresa serveru je v poli **Adresa vzdáleného MCP
serveru** (končí `/mcp`).

> [!TIP]
> Hlásí-li Claude při přidávání konektoru, že na adrese žádný server
> neodpověděl, zkontrolujte nejdřív tento přepínač. Vypnutý `/mcp` vrací
> `404 mcp_disabled`. Docker, IIS a spravovanou instalaci řeší
> [§ 105.8.3.5](#105835-vzdalene-pripojeni-bez-stahovani).

## 105.4 Krok za krokem: připojení asistenta online

1. Na záložce **Připojit online** klikněte na **Kopírovat adresu** a
   zkopírujte adresu MCP serveru vaší instalace (například
   `https://ucto.vase-firma.cz/mcp`). Zadávejte adresu **své** instalace.
2. **Claude:** na webu nebo v desktopové aplikaci otevřete **Přizpůsobit →
   Konektory**. U osobního účtu zvolte **+ → Přidat vlastní konektor**; v
   organizaci jej nejprve přidá vlastník. Vložte adresu, konektor přidejte a
   tlačítkem **Připojit** dokončete přihlášení do MyÚčta.
3. **ChatGPT:** v **Nastavení → Integrace → Pluginy** zvolte **Přidat →
   Přidat server MCP** a typ **Streamovatelné HTTP**. Vyplňte název a adresu,
   pole **Proměnná prostředí tokenu nositele** a záhlaví nechte prázdné. Server
   uložte; zobrazí-li se **Authenticate (Ověřit)**, klikněte na něj.
4. V okně MyÚčta se přihlaste. Zvolte v poli **Udělit přístup** **Pouze čtení**
   (výchozí), nebo **Čtení a zápis**. Máte-li zapnuté MFA, ověřte se passkey
   nebo novým kódem z ověřovací aplikace. Potvrďte a vraťte se do asistenta
   (případně **Pokračovat do asistenta**).
5. V nové konverzaci konektor zapněte (Claude: **+ → Konektory**) a napište:
   „Ověř připojení k MyÚčtu a vypiš dostupné firmy."

**Jak poznáte, že je hotovo:** Asistent vrátí účet a firmy (nástroje `whoami`
a `list_suppliers`). Připojení je v `Firma → MCP server` v části **Aktivní
připojení**.

> [!WARNING]
> Pro běžné dotazy stačí čtení. Čtení a zápis schvalujte jen tehdy, když má
> asistent opravdu měnit data. I při povoleném zápisu platí oprávnění vašeho
> účtu. Rozsah již uděleného připojení změníte jeho odvoláním a novým
> připojením.

Mobilní aplikace Claude používá konektor přidaný k účtu. Ručně přidaný server
v ChatGPT je vázaný na desktopového hostitele a do běžného mobilního chatu se
nepřenáší (viz [§ 105.8.3.5](#105835-vzdalene-pripojeni-bez-stahovani)).

### 105.4.1 Připojení klienta na vlastní doméně firmy

Správce může klientské roli výslovně přidělit oprávnění **Připojit AI asistenta (MCP)**.
Úroveň **Čtení** dovolí připojení pouze pro čtení, úroveň **Zápis** umožní schválit
čtení a zápis. Jednotlivé operace nadále vyžadují odpovídající oprávnění dané agendy.
Výchozí klientské role toto oprávnění nemají.

1. Otevřete aktivní klientskou doménu své firmy a v části **Firma → MCP server**
   zkopírujte adresu, například `https://portal.firma.cz/mcp`.
2. Adresu vložte do asistenta podporujícího vzdálené MCP s OAuth.
3. Přihlaste se na hlavní doméně MyÚčta a potvrďte firmu, klientskou doménu
   a rozsah. Při zapnutém MFA ověřte passkey nebo nový kód ověřovací aplikace.
4. V asistentovi ověřte připojení a dostupnou firmu.

Stránka **Firma → MCP server** obsahuje návod pro přidání konektoru v ChatGPT a Claude, přihlášení a ověření připojení. Do asistenta zadávejte adresu serveru končící `/mcp`; stránka `/portal/mcp` slouží ke správě připojení. Pro klientské připojení použijte aktivní HTTPS doménu firmy. Cloudový asistent potřebuje veřejně dostupnou doménu s důvěryhodným certifikátem; na `127.0.0.1` ani `localhost` vašeho počítače se nepřipojí.

Blok **MCP server této instalace** zobrazuje adresu vrácenou serverem a tlačítko **Kopírovat adresu**. Adresu lze zkopírovat i při vypnutém MCP; samotné připojení vyžaduje jeho zapnutí. Na hlavní doméně se zobrazuje adresa hlavní instalace s upozorněním, že pro přístup omezený na jednu firmu je potřeba otevřít její klientskou doménu.

Toto připojení platí pouze pro firmu a doménu uvedenou v souhlasu. Ani pozdější
přidělení další firmy jeho rozsah nerozšíří. Seznam nástrojů je klientský výběr
fakturace, kontaktů, zakázek, přijatých dokladů, pravidelné fakturace, knihy jízd,
ceníku a čtení skladových položek; konkrétní nabídka závisí na právech a rozsahu.
Účetní a mzdové nástroje se klientským připojením nezpřístupňují.

Na stejné stránce v klientském portálu vidíte svá připojení pro tuto firmu
na této doméně a můžete je odvolat i po odebrání oprávnění k novým připojením.
Odebrání oprávnění nebo členství, vypnutí domény či její převedení na jinou
firmu zablokuje další použití i obnovu tokenu. Přístupový token tohoto
připojení nelze použít na jiné doméně ani přímo proti REST API.

Připojení vyžaduje zapnuté vzdálené MCP, dostupný serverový most a aktivní
ověřenou doménu s klientským rozhraním. Doména pouze pro veřejné odkazy nestačí.
Klientské MCP nevytváří oprávnění ke správě obecných API tokenů.

## 105.5 Krok za krokem: lokální server (.mjs)

Použijte, když nechcete, nebo nemůžete použít online připojení (například
Codex CLI, Claude Code).

1. **Token.** V `Firma → API tokeny` vytvořte nový token (viz
   [Kapitola 103](103_API.md)). Pro zkoušení zvolte rozsah **čtení**, rozsah
   **čtení a zápis** až tehdy, když má asistent opravdu vystavovat doklady nebo
   měnit ceny. Token omezte na svou IP adresu.
2. **Server.** Použijte přiložený soubor `MCP/dist/myucto-mcp.mjs` (jediný
   soubor bez externích balíčků, doporučeno), nebo vývojovou variantu
   `MCP/src/index.mjs` po `npm install` v adresáři `MCP`.
3. **Registrace.** Na stránce `Firma → MCP server`, záložka **Spustit lokálně
   (.mjs)**, vyberte svého asistenta; zobrazí se hotová konfigurace s adresou
   vaší instance. Například pro Claude Code:

   ```bash
   claude mcp add myucto \
     --env MYUCTO_API_URL=https://vase-instance.cz/api/v1 \
     --env MYUCTO_API_TOKEN=mi_pat_vas_token \
     -- node /cesta/k/myucto.cz/MCP/dist/myucto-mcp.mjs
   ```

4. **Ověření.** Napište asistentovi „ověř připojení k MyÚčtu". Zavolá nástroj
   `whoami` a vrátí uživatele, roli a firmu.

**Jak poznáte, že je hotovo:** Volání se hned objeví v logu na stránce MCP
serveru. Konfigurace pro další asistenty a proměnné prostředí jsou v
[§ 105.8.3](#10583-zprovozneni) a [§ 105.8.4](#10584-nastaveni).

## 105.6 Krok za krokem: odvolání přístupu a kontrola logu

1. Otevřete `Firma → MCP server`, záložku **Připojit online**.
2. V části **Aktivní připojení** klikněte u aplikace na **Odvolat přístup** a
   potvrďte. Přístupový token i obnova se zneplatní okamžitě. Lokální token
   zrušíte v `Firma → API tokeny`.
3. Sjeďte na **Log volání** a zkontrolujte volání: čas, token, metodu, cestu,
   kód, dobu a IP; u MCP navíc název nástroje. Filtrujte podle tokenu, metody,
   cesty, zdroje a chyb.

**Jak poznáte, že je hotovo:** Připojení zmizelo ze seznamu a asistent hlásí
chybu přihlášení. Záznamy se drží 90 dní.

## 105.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Server nenaběhne, hlásí chybnou konfiguraci | `MYUCTO_API_URL` nekončí `/api/v1`, nebo token nezačíná `mi_pat_` | Opravte konfiguraci |
| Asistent hlásí, že **server neodpovídá** | Častou příčinou je nedůvěryhodný HTTPS certifikát; nebo je vzdálený server vypnutý | Viz [§ 105.8.13](#105813-vlastni-https-certifikat); ověřte dostupnost API a přepínač serveru |
| `401 invalid_token` | Token je zrušený nebo expirovaný | Vygenerujte nový |
| `403 token_ip_forbidden` | Token má omezení podle IP a tahle adresa mezi nimi není | Přidejte IP v omezení tokenu |
| `403 insufficient_scope` | Token má jen rozsah čtení, operace vyžaduje zápis | Vytvořte token s rozsahem čtení a zápis |
| `403 token_write_forbidden` | Zápis do účetnictví nebo daní přes API nejde (viz [§ 103.9.7](103_API.md#10397-scopes)) | Úkon proveďte v aplikaci; výjimkou jsou koncepty ostatních pohledávek a závazků a analytické účty osnovy ([§ 105.8.8.5](#105885-ostatni-pohledavky-a-zavazky)) |
| `403 other_items.error.auto_post_session_only` | Automatické účtování opakování nebo koncept, který by se automaticky zaúčtoval | Řeší se ve webovém rozhraní |
| `403 stock_disabled` | Skladový a e-shopový modul není pro firmu zapnutý | Zapněte ho v nastavení firmy |
| `409` u mazání zboží, výrobce, kategorie, skladu | Záznam je někde použitý | Archivujte ho (`archived`), případně zboží či sklad jen deaktivujte |
| „Neplatné argumenty nástroje …" | Asistent poslal neznámý parametr nebo hodnotu špatného typu či mimo rozsah | Hláška jmenuje parametr; asistent obvykle volání opraví sám |
| „NEPROVEDENO - chybí potvrzení" | Není chyba: takto vypadá náhled nevratné operace | Zkontrolujte výpis a řekněte asistentovi, ať to potvrdí |
| `429` | Překročen limit | Snižte `MYUCTO_MAX_RPS` |
| Asistent nástroje nevidí | Asistent načetl starý seznam | Restartujte aplikaci asistenta; u Gemini CLI ověřte příkazem `/mcp`; u online konektoru obnovte seznam nástrojů a začněte nový chat |
| V logu nejsou žádná volání | Server se nespustil | Zkontrolujte cestu k `index.mjs` a že proběhlo `npm install` |
| Test nástrojů hlásí chybu | Node most nenabízí nástroje | Zkontrolujte Node.js, PHP CLI a `proc_open` ([§ 105.8.3.5](#105835-vzdalene-pripojeni-bez-stahovani)) |

## 105.8 Podrobnosti a pravidla

### 105.8.1 Co je MCP

**Model Context Protocol** je otevřený standard pro připojení nástrojů k AI
modelům. Server běží buď přímo v instalaci MyÚčta, nebo jako malý program na vašem
počítači. Asistentovi nabízí sadu pojmenovaných **nástrojů**
(`list_unpaid_invoices`, `vat_return_preview`, `trial_balance`, …).

Podstatné vlastnosti:

- **MCP server nevytváří vlastní kopii dat.** Pracuje přímo s vaší instancí.
  Výsledek nástroje ale dostane připojený AI asistent a může jej podle
  svého provozního modelu odeslat poskytovateli AI. Citlivost dotazu proto
  posuzujte stejně jako při ručním vložení údajů do daného asistenta.
- **Asistent má jen schválený přístup.** U online připojení ho omezuje souhlas
  uživatele, aktuálně dostupné firmy, rozsah a role. U lokálního připojení platí rozsah,
  vazba na firmu, omezení podle IP a oprávnění role vydaného API tokenu.
- **Všechno je vidět v logu.** Každé volání se zapíše včetně názvu nástroje.

### 105.8.2 Rozsah - co asistent umí

| Oblast | Rozsah |
|---|---|
| Fakturace | čtení, založení, úprava a smazání konceptu (hlavička i jednotlivé položky), vystavování, odesílání a příjemci, storno a dobropis, kopie dokladu, vyúčtování a propojení zálohy, penalizační faktura, evidence a mazání úhrad, daňový doklad k platbě, platební kalendář s rozpisem plateb, ISDOC, upomínky jednotlivě i hromadně |
| Pravidelná fakturace | čtení šablon a vygenerovaných faktur, založení, úprava, smazání, pozastavení, obnovení, přeplánování a ruční vygenerování faktury |
| Odběratelé | vyhledání, založení a úprava karty, dotažení údajů z ARES |
| Přijaté faktury | čtení, založení a úprava konceptu (hlavička i jednotlivé položky), přijetí, úhrada, storno, zálohy, zakázka, druh nákladu, účet dodavatele, příprava příkazu k úhradě; zaúčtování a odeslání do banky ne (viz [§ 105.8.8.4](#105884-prijate-faktury)) |
| Výkazy práce a materiálu | přidání a odebrání řádků u konceptu faktury, automatická hodinová sazba |
| Zakázky | **čtení i zápis** - založení, úprava, archivace, rozpočty a ziskovost |
| Dokumenty | metadata, fulltext a omezené čtení vytěženého textu; úprava tagů a vazeb; nahrání a stažení originálu |
| Soubory a přílohy | stažení PDF a ISDOC vydané faktury, příloh, PDF přijaté faktury a originálu dokumentu; nahrání přílohy faktury, PDF přijaté faktury, dokumentu a obrázku ke zboží; založení přijaté faktury ze souboru ISDOC (viz [§ 105.8.8.6](#105886-soubory-a-prilohy)) |
| Kniha jízd | **čtení i zápis** - vozidla, jízdy a tankování; daňový souhrn jen ke čtení |
| Pohledávky a závazky | zaplacené / nezaplacené / po splatnosti, stáří pohledávek |
| Daně | odhad DPH za měsíc i kvartál, kontrolní a souhrnné hlášení, daň z příjmů, daňový kalendář - **jen čtení** |
| Účetnictví | obratovka, rozvaha, výsledovka, hlavní kniha, saldo, deník: **jen čtení**; výjimkou jsou koncepty ostatních pohledávek a závazků a založení či úprava analytického účtu v osnově |
| Ostatní pohledávky a závazky | čtení dokladů, úhrad, opakování a splátek; **zápis jen konceptů**: založení, úprava, smazání, splátkový kalendář (i vyčtený ze smlouvy), opakování bez automatického účtování, dimenze a připojení smlouvy. Nic se nezaúčtuje ([§ 105.8.8.5](#105885-ostatni-pohledavky-a-zavazky)) |
| Dimenze | typy a hodnoty, zisk, roční a měsíční vývoj, manažerské cash flow, rozvaha a výsledovka s filtrem dimenze, přiřazení na dokladech a kontrola pravidel - **jen čtení** |
| Statistika | tržby, zisk, trendy, top odběratelé a dodavatelé, cash flow, platební morálka, koncentrace, riziko odchodu |
| Všechny firmy | manažerské součty přístupných firem za přesné období, měsíční vývoj, roční predikce, cashflow, zůstatky a rizika přes `group_dashboard`; původní měny i samostatný přepočet CZK |
| E-shop a sklad | **kompletní správa včetně zápisu** - zboží, obsah karet, ceny, dodavatelé, média, kategorie, číselníky, sklady, příjemky a výdejky, inventury (viz [§ 105.8.9](#10589-e-shop-a-sklad)) |
| Objednávky u dodavatele | **čtení i zápis** - založení, odeslání, potvrzení, uzavření, storno, příjemka z objednávky a hromadné objednání podle návrhu doplnění zásob ([§ 105.8.9](#10589-e-shop-a-sklad)) |
| Mzdy | čtení zaměstnanců, pracovních podmínek a výsledků; změna sjednané mzdy, mzdové vstupy, přesčasy a absence; řízení mzdového běhu, platby, podání a dokumenty jsou zakázané |
| Hledání | globální vyhledávání napříč odběrateli a doklady |

V režimu jen pro čtení (`MYUCTO_READ_ONLY=1`, [§ 105.8.4](#10584-nastaveni))
server nezveřejňuje nástroje, které mění data. Každé použití nástroje dále omezují
aktuální práva uživatele. Přesný počet vypíše server při startu do `stderr`
([§ 105.8.3](#10583-zprovozneni), krok 4).

#### Dotazy podle dimenzí

Asistent nejprve načte dostupné typy a hodnoty nástrojem `list_dimensions`.
Pak se ho můžete zeptat například:

- „Jaký je zisk střediska Servis za rok 2026? Ukaž i náklady a výnosy po účtech.“
- „Porovnej měsíční vývoj projektů v roce 2026 s minulým rokem.“
- „Ukaž rozvahu a výsledovku k 30. červnu pro projekt Stavba A.“
- „Jaké je cash flow tohoto projektu za první pololetí?“
- „Sečti zisk této globální dimenze přes všechny firmy skupiny, ke kterým mám přístup.“

Filtr `dimension_value_id` používá ID hodnoty z přehledu dimenzí. Standardně
zahrnuje i podřízené hodnoty; `dimension_descendants=false` vybere pouze přesnou
hodnotu. Filtr podporují rozvaha, obě výsledovky, výkazy po účtech, obratovka,
hlavní kniha, účetní deník a dimenzní cash flow.

`dimension_profit` a `dimension_analytics` s `scope=group` sčítají globální
typ dimenze přes dostupné firmy jeho skupiny. U `dimension_cash_flow` je pro
souhrn skupiny potřeba vybrat hodnotu globální dimenze. Přístup vázaný na jedinou
firmu tento rozsah nerozšíří. Rozvaha a výsledovka se sestavují pro jednu firmu
a její vlastní účetní období.

Cash flow podle dimenze je manažerská sestava **nepřímou metodou**, která
vychází ze zisku a změn rozvahových účtů. Ukazuje také skutečný pohyb peněz,
nepřiřazený rozdíl a kontrolu shody. Úhrada bez dimenze proto není vydávána za
peněžní pohyb konkrétního projektu.

> [!WARNING]
> **Do účetnictví a daní asistent nezapisuje.** Zaúčtovat doklad, uzavřít období,
> zaevidovat opravu podle § 46 / § 74b ani odeslat podání na EPO nemůže. Je to
> agenda s daňovou odpovědností, kde chyba znamená opravné podání - dělá ji člověk
> v aplikaci. Zákaz vynucuje server, ne jen MCP: i token s právem zápisu dostane
> na takovou operaci `403 token_write_forbidden` (viz [§ 103.9.7](103_API.md#10397-scopes)).
>
> Výjimkou jsou **koncepty ostatních pohledávek a závazků** a **analytické účty
> účtové osnovy** ([§ 105.8.8.5](#105885-ostatni-pohledavky-a-zavazky)). Koncept
> nemá zápis v deníku, potvrdí a zaúčtuje ho účetní v aplikaci. Založení
> analytiky nic nezaúčtuje.
>
> **U mezd asistent pracuje jen s personálními údaji a připravovanými vstupy.**
> Může změnit sjednanou mzdu od zadaného data, zadat přesčas, absenci nebo odměnu.
> Starší mzdová období přitom zachovají původní sjednanou částku. Nemůže spustit
> výpočet, schválení docházky, absence nebo mzdového běhu, zaúčtování, přípravu
> plateb, uzavření, podání ani mzdové dokumenty. Nová absence zůstane ve stavu
> k posouzení a schválí ji člověk v aplikaci.

### 105.8.3 Zprovoznění

Pro online připojení použijte [§ 105.8.3.5](#105835-vzdalene-pripojeni-bez-stahovani).
Následující čtyři kroky popisují lokální soubor `.mjs`.

#### 105.8.3.1 Krok 1 - API token

V `Firma → API tokeny` vytvořte nový token. Zobrazí se **jen jednou**, hned si
ho zkopírujte.

- Pro zkoušení zvolte rozsah **čtení**. Rozsah **čtení a zápis** dávejte až tehdy,
  když má asistent opravdu vystavovat doklady nebo měnit ceny.
- Token rovnou **omezte na svou IP adresu** (sloupec *IP omezení* u tokenu).

#### 105.8.3.2 Krok 2 - příprava serveru

Server vyžaduje **Node 20 nebo novější**. Ve vydané distribuci je už připravený
hotový build; nic nemusíte sestavovat ani instalovat. Máte dvě možnosti.

Pokud Node nemáte: Windows - `winget install --id OpenJS.NodeJS.LTS --exact`;
macOS - `brew install node`.

**A) Hotový build z distribuce (doporučeno).** Použijte přiložený soubor:

```text
MCP/dist/myucto-mcp.mjs
```

Jde o jediný soubor bez externích balíčků. Můžete ho nechat v instalaci nebo
zkopírovat kamkoliv, třeba na jiný počítač. V konfiguraci asistenta pak jen
nastavíte jeho úplnou cestu. V artefaktech vydání je navíc ke stažení také jako
samostatný soubor MCP serveru.

Server při startu na pozadí ověří poslední stabilní vydání na GitHubu a případnou
novější verzi oznámí v diagnostickém výstupu asistenta. Kontrolu lze vyvolat také
nástrojem `check_update`, například dotazem „Je dostupná aktualizace MCP serveru?“.
Výsledek obsahuje běžící a nejnovější verzi, odkaz na poznámky k vydání a přímo na
soubor `myucto-mcp-<verze>.mjs`. Staženým souborem nahraďte původní MCP server při
zachování cesty v konfiguraci a restartujte asistenta. Aktualizace se neinstaluje sama.
Pokud je GitHub nedostupný, server dál normálně funguje.

**B) Vývoj ze zdrojáků.** Hodí se, když si chcete nástroje upravovat:

```bash
cd MCP
npm install
```

Server pak běží z `MCP/src/index.mjs` a potřebuje vedle sebe `node_modules`.

> [!TIP]
> **Sestavení neodstraňuje potřebu Node.** Výsledek je pořád JavaScript, jen bez
> externích závislostí - Node musí být nainstalovaný v obou případech. Odpadá
> jen `npm install` a adresář `node_modules`.

#### 105.8.3.3 Krok 3 - registrace u asistenta

Na stránce **Firma → MCP server** vyberte v kroku 3 svého asistenta; zobrazí se
hotová konfigurace i s adresou vaší instance, kterou stačí zkopírovat.

| Asistent | Kam konfigurace patří |
|---|---|
| **Claude Code** (CLI i desktop) | příkaz `claude mcp add` |
| **Claude Desktop** | `claude_desktop_config.json` (Settings → Developer → Edit Config) |
| **ChatGPT přes Codex CLI** | `~/.codex/config.toml` |
| **Gemini CLI** | `~/.gemini/settings.json` |
| **VS Code (Copilot)** | `.vscode/mcp.json` |
| **Cursor** | `.cursor/mcp.json` |

Například pro Claude Code:

```bash
claude mcp add myucto \
  --env MYUCTO_API_URL=https://vase-instance.cz/api/v1 \
  --env MYUCTO_API_TOKEN=mi_pat_vas_token \
  -- node /cesta/k/myucto.cz/MCP/dist/myucto-mcp.mjs
```

Na stránce v aplikaci se dá přepnout, jestli má konfigurace ukazovat na hotový
`MCP/dist/myucto-mcp.mjs`, nebo na vývojový `MCP/src/index.mjs` - cesta se změní
ve všech ukázkách naráz.

> [!TIP]
> **Tento lokální soubor nelze přidat do ChatGPT na webu.** ChatGPT používá
> vzdálený MCP server. Pro připojení bez souboru zvolte záložku **Připojit online**,
> případně pro lokální soubor použijte **Codex CLI**.

#### 105.8.3.4 Krok 4 - ověření

Napište asistentovi „ověř připojení k MyÚčtu“. Zavolá nástroj `whoami` a vrátí
uživatele, roli a firmu. Volání se hned objeví v logu na stránce MCP serveru.

#### 105.8.3.5 Vzdálené připojení bez stahování

V záložce **Připojit online** zkopírujte adresu MCP serveru své instalace, například
`https://ucto.vase-firma.cz/mcp`. Do asistenta zadávejte adresu **své** instalace,
ne `dev.myucto.cz` ani adresu jiné firmy. Doména musí mít veřejný DNS záznam,
být dostupná z internetu a mít HTTPS certifikát od veřejně důvěryhodné autority.
Lokální vývojová adresa nebo certifikát vlastní CA nestačí. ChatGPT a Claude se připojují ze svých
serverů, nikoli přímo z počítače nebo telefonu, na kterém používáte jejich aplikaci.

Vzdálený server je po instalaci **vypnutý**. V záložce se ukazuje jeho stav a
**superadmin** ho zapne tlačítkem **Zapnout server**. Před zapnutím musí být v
prostředí, které spouští MCP, dostupný **Node.js**; když chybí, stránka ukáže
upozornění a zapnutí nedovolí. Nainstalujte Node.js do tohoto prostředí, ověřte jeho dostupnost pro aplikaci a stránku
znovu načtěte. Na počítači ani telefonu uživatele Node.js potřeba není.
Stejným přepínačem může superadmin server později vypnout.
V `cfg.php` se server nezapíná. V Dockeru a na IIS lze stav řídit proměnnou
`MYINVOICE_MCP_ENABLED=1` nebo `0`; při jejím
nastavení je přepínač ve webu jen informativní. Bez této proměnné platí nastavení
uložené v aplikaci. Dockerový obraz už Node.js obsahuje.
Ve spravované SaaS instalaci (`app.managed = true`) záleží na provozovateli.
Dokud most s Node.js nepřipraví, je serverový MCP vypnutý bez ohledu na proměnnou
prostředí i uložený přepínač, záložka Připojit online se nezobrazuje a dostupný
zůstává lokální postup se souborem `.mjs`. Jakmile ho připraví, záložka se objeví
a superadmin server zapíná stejným přepínačem jako jinde.
Pokud Claude při přidávání konektoru hlásí, že na adrese žádný server neodpověděl,
zkontrolujte nejdřív tento přepínač. Vypnutý `/mcp` vrací chybu `404 mcp_disabled`
a klient proto nemůže zjistit přihlašovací údaje OAuth.
MCP obsluhuje REST API přes samostatný PHP CLI proces na stejném serveru. Nevyžaduje
další síťový port ani dostupnost vlastní veřejné domény z Docker kontejneru.
Na IIS a Apache musí mít PHP povolenou funkci `proc_open` a účet webového
serveru musí umět spustit Node.js. Pokud Node není v jeho `PATH`, nastavte
`MYINVOICE_MCP_NODE_BINARY` na úplnou cestu k `node.exe` nebo binárnímu souboru
Node na daném serveru. Na IIS s PHP přes FastCGI tuto proměnnou přidejte v
**Správce IIS → server → Nastavení FastCGI → php-cgi.exe → Proměnné prostředí**.
Vyberte položku `php-cgi.exe`, kterou používá mapování handleru daného webu, změnu
uložte a recyklujte jeho aplikační pool. Sdílí-li stejnou položku FastCGI více webů,
proměnnou dostanou všechny jejich PHP procesy. Server potřebuje také PHP CLI; není-li ve standardním
adresáři PHP, nastavte `MYINVOICE_MCP_PHP_BINARY` na úplnou cestu k `php.exe`.
Na počítači uživatele Node pro online připojení není třeba.

Na IIS nastavte u webu **Authentication → Anonymous Authentication → Edit →
Application pool identity**. Výchozí účet `IUSR` může při spuštění Node z PHP
FastCGI způsobit `Access is denied` u potomkových procesů a pád Node s hláškou
`ncrypto::CSPRNG(nullptr, 0)`. Pro instalaci použijte vlastní aplikační pool,
aby nastavení identity nezasáhlo jiné weby. Na poolu ponechte
**ApplicationPoolIdentity** a zapněte **Load User Profile**. Práva k adresáři
aplikace a `node.exe` musí této identitě umožnit čtení a spuštění.

1. Přidejte adresu jako **vlastní vzdálený MCP konektor** v asistentovi.
2. Při připojení se v prohlížeči přihlaste se do MyÚčta. Připojení platí pro všechny
   firmy, ke kterým máte aktuálně práva, včetně firem přidělených později. Když asistent
   požaduje čtení a zápis, můžete v poli **Udělit přístup** zvolit **Pouze čtení**
   (výchozí volba) nebo **Čtení a zápis**. Pokud asistent požaduje jen čtení,
   širší přístup mu udělit nelze. I při povolení zápisu platí oprávnění vašeho účtu.
   Rozsah již uděleného připojení změníte jeho odvoláním a novým připojením.
   Pokud máte zapnuté MFA, ověřte se passkey nebo novým kódem ověřovací
   aplikace. Bez MFA stačí přístup potvrdit. Kód použitý při přihlášení nelze
   znovu použít. Pro běžné dotazy stačí **čtení**; **čtení a zápis** schvalujte jen
   tehdy, když má asistent opravdu měnit data. Po schválení se prohlížeč vrátí
   do asistenta. Když se nepřesměruje automaticky, zvolte **Pokračovat do asistenta**.
3. Konektor zapněte v konverzaci a napište „Ověř připojení k MyÚčtu a vypiš dostupné firmy“.
   Nástroje `whoami` a `list_suppliers` vrátí účet a firmy, ke kterým máte přístup.
   V dalších dotazech asistent předává `supplier_id` vybrané firmy. Pokud jste
   připojení schválili dříve jen pro jednu firmu, odvolejte ho a připojte asistenta znovu.

Server odešle celý katalog dostupný pro schválený rozsah přístupu v jedné
odpovědi. Asistent může v konkrétní konverzaci nabídnout jen nástroje relevantní
pro aktuální úkol. Katalog se zatím nefiltruje podle jednotlivých práv role.
Nástroj mimo její oprávnění se může zobrazit, ale API jeho volání odmítne.

Pro online připojení **nevytváříte ani nekopírujete API token** a na svém zařízení
neinstalujete Node ani soubor `.mjs`. Každé volání ověřuje aktuální členství ve
zvolené firmě, rozsah a oprávnění účtu. Odebrání firmy nebo práv se projeví při
dalším volání. Aktivní povolení se zobrazují ve stejné záložce pod
stavem serveru. Tlačítko **Odvolat přístup** okamžitě zneplatní přístupový token
i možnost jeho obnovy. Konektor můžete navíc odebrat v nastavení asistenta.
Superadmin (správce instance) může zaškrtnout **Všichni uživatelé** a uvidí povolení
všech uživatelů instance i se jménem a e-mailem uživatele, který je udělil. Odvolat
může i cizí povolení, odvolání se zapíše do auditního logu včetně vlastníka povolení.
Uživatel s rolí pouze pro čtení může připojení povolit jen pro čtení. Pro sdílení
samotných statistik mu nastavte roli s přístupem pouze k příslušným přehledům
a právem číst správu vlastních API tokenů.
Správce může tlačítkem **Otestovat nástroje** ověřit, že serverový Node most
skutečně nabízí nástroje. Pokud asistent hlásí nedostupný konektor nebo nula
nástrojů, spusťte tento test a zkontrolujte uvedenou chybu.

Online MCP se aktualizuje spolu s MyÚčtem na této instalaci. Adresa `/mcp`
i schválené připojení zůstávají stejné; na zařízení se nic nestahuje.
Server při připojení hlásí verzi instalace. Po přidání nebo změně nástrojů
obnovte jejich seznam v asistentovi a začněte nový chat. V ChatGPT připojeném
ve vývojářském režimu použijte u serveru akci **Refresh**.

**ChatGPT:** V **Nastavení → Integrace → Pluginy** zvolte **Přidat → Přidat server MCP**
a typ **Streamovatelné HTTP**. Zadejte název a adresu své instalace končící
`/mcp`. Pole **Proměnná prostředí tokenu nositele** a záhlaví nechte prázdné.
OAuth se v tomto formuláři nevybírá. Server uložte; v desktopové aplikaci ji
případně restartujte. Pokud se v seznamu serverů zobrazí **Authenticate
(Ověřit)**, zvolte tuto akci a dokončete souhlas v MyÚčtu. Pak server zapněte v
konverzaci. Pokud ověření chybí, zkontrolujte, že je vzdálený MCP server v MyÚčtu
zapnutý. Při vypnutém serveru vrací `/mcp` odpověď 404 a klient OAuth nezahájí.

> [!WARNING]
> Ručně přidaný server je uložený u desktopového hostitele. Do běžného mobilního
> chatu se nepřenáší. Z mobilní aplikace jej lze použít přes funkci **Remote**,
> která ovládá připojený počítač; ten musí být zapnutý a dostupný. Dostupnost
> Remote závisí na účtu a pracovním prostoru. Samostatné mobilní připojení touto
> cestou zatím není ověřené.

**Claude:** Na webu nebo v desktopové aplikaci otevřete
**Customize → Connectors**. U osobního účtu zvolte **+ → Add custom connector**,
vložte adresu `/mcp`, přidejte konektor a tlačítkem **Connect** dokončete přihlášení.
V organizaci musí vlastní konektor nejprve přidat vlastník v nastavení organizace.
V konverzaci jej zapněte přes **+ → Connectors**. Anthropic potvrzuje, že jednou
přidaný [vzdálený konektor funguje také v mobilní aplikaci Claude](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp).

### 105.8.4 Nastavení

Lokální server `.mjs` se konfiguruje proměnnými prostředí:

| Proměnná | Výchozí | Význam |
|---|---|---|
| `MYUCTO_API_URL` | - | **Povinné.** Adresa API, musí končit `/api/v1`. |
| `MYUCTO_API_TOKEN` | - | **Povinné.** Token `mi_pat_…`. |
| `MYUCTO_SUPPLIER_ID` | - | Firma, se kterou pracovat. Jen u tokenů nevázaných na jednu firmu. |
| `MYUCTO_READ_ONLY` | `0` | `1` = zápisové nástroje se asistentovi vůbec nenabídnou. |
| `MYUCTO_MAX_RPS` | `8` | Nejvýš tolik požadavků za sekundu. |
| `MYUCTO_MAX_CONCURRENT` | `3` | Nejvýš tolik souběžných volání. |
| `MYUCTO_TIMEOUT_MS` | `30000` | Timeout jednoho požadavku. |
| `MYUCTO_MAX_FILE_MB` | `10` | Největší soubor, který asistent stáhne nebo nahraje, v MB (nejvýš 50). |
| `MYUCTO_SYSTEM_CA` | `1` | Načíst certifikační autority z operačního systému. `0` = nenačítat. |
| `MYUCTO_INSECURE_TLS` | `0` | `1` = vůbec neověřovat HTTPS certifikát. **Jen pro vývojovou instanci.** |

`MYUCTO_READ_ONLY=1` je užitečná pojistka i u tokenu, který právo zápisu má -
zápisové nástroje se v takovém režimu asistentovi ani nezobrazí, takže si
nenaplánuje postup, který by stejně nedokončil.

Stropy `MAX_RPS` a `MAX_CONCURRENT` nejsou kosmetika: API sdílí PHP procesy
s běžícím webem, takže asistent bez omezení zpomalí i běžné uživatele.
Přebytečná volání čekají ve frontě. Nezávisle na nich platí serverový
[rate limit](103_API.md) tokenu.

### 105.8.5 Příklady dotazů

**Fakturace a pohledávky**

- „Které faktury jsou po splatnosti a kdo nám dluží nejvíc?“
- „Najdi fakturu pro ACME z června a ukaž, jestli je zaplacená.“
- „Vystav fakturu firmě ACME na 10 hodin konzultací po 1 500 Kč.“ *(token čtení a zápis)*

**Úprava konceptu faktury** *(token čtení a zápis)*

- „Na konceptu pro ACME změň cenu druhé položky na 1 800 Kč.“
- „Přidej na koncept položku Doprava, 1 ks, 350 Kč.“
- „Odeber poslední položku, je tam omylem.“
- „Posuň splatnost konceptu o 14 dní a doplň poznámku.“

Podrobnosti v [§ 105.8.7.4](#105874-uprava-konceptu-faktury).

**Další práce s vydanými fakturami** *(token čtení a zápis)*

- „Fakturu 2026042 jsme vystavili omylem, stornuj ji.“
- „K faktuře 2026042 připrav dobropis, zákazník vrátil zboží.“
- „Udělej kopii zářijové faktury pro ACME s datem 1. října.“
- „Záloha pro ACME je zaplacená, připrav finální fakturu.“
- „Kolik by byl úrok z prodlení u faktury 2026031? Pokud víc než 100 Kč, připrav penalizační fakturu.“
- „Zaeviduj k faktuře 2026042 hotovostní platbu 5 000 Kč z dnešního dne.“
- „Z přiložené smlouvy udělej splátkový kalendář na nájem a nastav rozpis plateb.“
- „Pošli upomínky ke všem fakturám, které jsou víc než 30 dní po splatnosti.“

Podrobnosti v [§ 105.8.7.5](#105875-dalsi-prace-s-vydanymi-fakturami).

**Pravidelná fakturace** *(token čtení a zápis)*

- „Založ pro ACME měsíční paušál 5 000 Kč za správu serveru, vždy k 1. dni v měsíci.“
- „Zvyš paušál pro ACME od příští faktury na 5 500 Kč.“
- „Pozastav pravidelnou fakturaci pro ACME, dokud se nedohodneme.“
- „Vygeneruj fakturu z paušálu pro ACME hned, jen jako koncept.“

Podrobnosti v [§ 105.8.7.6](#105876-pravidelna-fakturace).

**Přijaté faktury** *(token čtení a zápis)*

- „Zapiš fakturu od Dodavatel s.r.o. číslo FA-118 na 2 000 Kč bez DPH, sazba 21 %, DUZP 9. září, splatnost 24. září.“
- „U té faktury oprav číslo dokladu na FA-2026-118 a přidej položku Doprava 350 Kč.“
- „Přijmi ji.“ *(asistent nejdřív ukáže, co přijetí udělá, a čeká na potvrzení)*
- „Spáruj fakturu od Dodavatele se zálohou, kterou jsme platili v srpnu.“
- „Připrav příkaz k úhradě na všechny faktury splatné tento týden.“

Podrobnosti v [§ 105.8.8.4](#105884-prijate-faktury).

**Odběratelé**

- „Založ klienta podle IČO 45274649.“
- „Najdi v ARES firmu s IČO 12345678 a ukaž mi její adresu.“
- „Uprav Prazdroji telefon na +420 123 456 789.“
- „Přenačti údaje ACME z ARES, přestěhovali se.“

**Výkazy práce a materiálu**

- „Přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru.“
- „Kolik hodin je zatím ve výkazu na téhle faktuře?“
- „Přidej do výkazu 5 metrů kabeláže po 120 Kč.“
- „Smaž poslední řádek z výkazu, zadal jsem ho omylem.“

Podrobnosti v [§ 105.8.7](#10587-koncept-faktury-vykazy-prace-a-materialu).

**Zakázky, dokumenty a kniha jízd**

- „Založ zakázku pro ACME s rozpočtem 200 000 Kč a splatností 14 dní.“ *(token čtení a zápis)*
- „Jaká je ziskovost zakázek od začátku roku a které mají nezaúčtované doklady?“
- „Najdi ve smlouvách zmínku o výpovědní lhůtě a ukaž text dokumentu.“
- „Připoj tu smlouvu k zakázce Web 2026.“ *(token čtení a zápis)*
- „Přidej dnešní služební jízdu Praha–Kolín, 120 km.“ *(asistent si nechá vybrat kategorii; token čtení a zápis)*
- „Zapiš tankování 40 litrů za 1 520 Kč.“ *(token čtení a zápis)*

Podrobnosti v [§ 105.8.8](#10588-zakazky-dokumenty-a-kniha-jizd).

**Soubory a přílohy**

- „Stáhni PDF faktury 2026015 a shrň mi, co na ní je.“
- „Pošli mi ISDOC k faktuře pro ACME.“
- „Přilož tuhle objednávku v PDF k faktuře 2026015.“ *(token čtení a zápis)*
- „Tady je ISDOC od dodavatele, založ z něj přijatou fakturu.“ *(token čtení a zápis)*
- „Nahraj tuhle smlouvu do Dokumentů, označ ji tagem smlouvy a připoj ji k ACME.“ *(token čtení a zápis)*

Podrobnosti v [§ 105.8.8.6](#105886-soubory-a-prilohy).

**Daně**

- „Kolik letos v červenci zaplatíme na DPH?“
- „Jak vychází DPH za tenhle kvartál a co se ještě změní z konceptů?“
- „Z jakých dokladů se skládá DPH za červen?“
- „Kolik letos odvedeme na dani z příjmů a jak jsme na tom se zálohami?“

**Účetnictví**

- „Ukaž obratovou předvahu za letošní období.“
- „Jak se zaúčtovala faktura číslo 2026001?“
- „Co visí v saldu - komu jsme nespárovali platby?“

**Ostatní pohledávky a závazky** *(zápis jen s tokenem čtení a zápis)*

- „Jaké máme otevřené závazky z úvěrů a nájmů a kdy jsou splatné?“
- „Založ koncept závazku: nájem kanceláře na říjen, 15 000 Kč, splatnost 15. 10., protiúčet 518.“
- „Z přiložené úvěrové smlouvy založ koncept závazku na jistinu a nastav splátkový kalendář.“
- „Založ analytiky 378.800 Zápůjčka a 378.810 Úroky ze zápůjčky.“
- „Nahraj tuhle smlouvu o zápůjčce, založ k ní koncept pohledávky a smlouvu k němu připoj.“
- „Opakuj ten nájem každý měsíc do konce roku.“
- „Pozastav opakování pojistného.“

Podrobnosti v [§ 105.8.8.5](#105885-ostatni-pohledavky-a-zavazky).

**Statistika**

- „Ukaž trend obratu a zisku po měsících za poslední rok.“
- „Kde nám utíkají peníze - rozpad nákladů podle kategorií.“
- „Jak moc jsme závislí na největších zákaznících?“
- „Co bych měl dneska řešit?“

**E-shop a sklad**

- „Které zboží je pod minimální zásobou a mělo by se doobjednat?“
- „Kolik máme uloženo ve skladu k dnešnímu dni?“
- „Založ zboží Kabel HDMI 2 m, sazba 21 %, minimální zásoba 10.“ *(token čtení a zápis)*
- „Zdraž všechno zboží značky Acme o pět procent.“ *(token čtení a zápis)*
- „Nasklaď 20 kusů kabelu na hlavní sklad za 89 Kč a příjemku zaúčtuj.“ *(token čtení a zápis)*

Celá kapitola: [§ 105.8.9](#10589-e-shop-a-sklad).

**Mzdy a zaměstnanci**

- „Jakou má Jana Nováková sjednanou hrubou mzdu?“
- „Kolik vyšla Janě Novákové čistá mzda za srpen?“
- „Zvyš Janě mzdu od září na 55 000 Kč.“ *(token čtení a zápis)*
- „Zadej Janě neschopenku od 3. do 12. září.“ *(token čtení a zápis)*
- „Zapiš Janě dvě hodiny přesčasu dnes od 17 do 19 hodin.“ *(token čtení a zápis)*
- „Přidej Janě mimořádnou odměnu 5 000 Kč za září.“ *(token čtení a zápis)*

Mzdový běh tím nevznikne ani se neposune do dalšího stavu. Výpočet, kontrola,
schválení, zaúčtování, platby, uzavření a odeslání zůstávají na člověku v aplikaci.

### 105.8.6 Odběratelé a ARES

Nového odběratele stačí zadat IČEM:

> „Založ klienta podle IČO 45274649.“

Asistent si vytáhne z **ARES** název, adresu, DIČ i registraci k DPH a kartu
založí. Cokoli řeknete navíc („…a e-mail fakturace@firma.cz“) má přednost před
tím, co vrátí rejstřík - může jít o změnu, která se do ARES ještě nepropsala.

Bez IČO je potřeba název, ulice, město a PSČ; asistent si o ně řekne.

#### 105.8.6.1 Ochrana proti duplicitám

Před založením se kontroluje, jestli odběratel se stejným **IČO nebo DIČ** už
neexistuje. Pokud ano, **nic se nezaloží** a asistent ukáže stávající kartu.
Druhou kartu téže firmy lze vytvořit jen vědomě, na výslovné potvrzení.

#### 105.8.6.2 Úprava

Stačí říct, co se má změnit - zbytek karty zůstane. Asistent si ji načte,
změnu do ní vloží a uloží celou zpět, takže se nic nevynuluje.

Když se firma přestěhuje nebo přejmenuje, jde údaje přenačíst z rejstříku:

> „Přenačti údaje ACME z ARES.“

Když je ARES nedostupný, u úpravy se **nic nemění** (raději nic než půlka
starých a půlka nových údajů). U zakládání se použijí údaje ze zadání, pokud
stačí - asistent do odpovědi napíše, odkud data vzal.

### 105.8.7 Koncept faktury, výkazy práce a materiálu

Výkaz je navázaný na **koncept faktury** - přesně jako v aplikaci. Stačí tedy říct:

> „Přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru.“

Asistent zakázku dohledá, najde její koncept faktury a řádek přidá. Existující
řádky zůstanou beze změny.

#### 105.8.7.1 Jak se určí hodinová sazba

Sazbu zadávat nemusíte. Doplní se v tomhle pořadí a první nenulová vyhraje:

1. **poslední řádek výkazu** - když už se výkaz jednou vyplnil, nová hodina má
   sedět s ním, ne s ceníkem;
2. **hodinová sazba zakázky**;
3. **hodinová sazba odběratele**;
4. **výchozí hodinová sazba firmy** (Nastavení firmy).

Když sazbu nemá nikdo, asistent to řekne a požádá o ni - netipuje. Vlastní sazbu
lze samozřejmě určit („…3 hodiny po 1 800 Kč“).

#### 105.8.7.2 Který doklad se použije

- Pokud řeknete číslo faktury, použije se ta.
- Pokud jmenujete jen zakázku nebo odběratele, hledá se jeho **koncept** faktury.
- **Je-li konceptů víc, asistent nehádá** - vypíše je a nechá vás vybrat. Zapsat
  hodiny na cizí doklad by bylo horší než se doptat.
- Vystavená faktura je uzamčená; do jejího výkazu se zapsat nedá.

#### 105.8.7.3 Materiál

Řádky materiálu fungují stejně (množství, jednotka, cena za jednotku). Jediný
rozdíl: **sazbu DPH materiálu si asistent nevymýšlí.** Převezme ji z už
existujícího výkazu, jinak si o ni řekne - špatná sazba by se propsala do
přiznání k DPH.

#### 105.8.7.4 Úprava konceptu faktury

Koncept, který asistent připravil, nemusíte opravovat ručně v editoru. Stačí mu
říct, co změnit, a koncept upraví:

| Nástroj | Co dělá |
|---|---|
| `update_invoice` | změní hlavičku: data, splatnost, měnu, jazyk, způsob úhrady, poznámky, zakázku, slevu, variabilní symbol |
| `add_invoice_item` | přidá položku na konec nebo na zvolené místo; s `kind: "text"` přidá textový řádek jen s popisem |
| `update_invoice_item` | změní jednu položku (text, množství, jednotku, cenu, sazbu DPH, časové rozlišení); u textového řádku jen text |
| `remove_invoice_item` | odebere jednu položku, jen s potvrzením ([§ 105.8.9.4](#105894-potvrzovani-nevratnych-kroku)) |

Platí přitom:

- **Mění se jen to, co řeknete.** Ostatní pole hlavičky i ostatní položky zůstanou,
  včetně údajů, které v rozhovoru nevidíte: časové rozlišení, vazba na sklad
  a majetek, nastavení OSS a pořadí položek.
- Položku určíte pořadím, jak ji vidíte na dokladu („druhá položka“), nebo jejím
  ID. Řádek se slevou z celé faktury se nepočítá, ten dopočítává aplikace sama.
- **Sazbu DPH si asistent nevymýšlí.** U nové položky se na ni zeptá, u upravované
  ji nechá, dokud ji výslovně nezměníte. Po změně sazby, data zdanitelného plnění
  nebo přenesené daňové povinnosti si aplikace zařazení položek pro přiznání
  k DPH a OSS odvodí znovu.
- Doklad se dohledá stejně jako u výkazu práce ([§ 105.8.7.2](#105872-ktery-doklad-se-pouzije)):
  má-li odběratel víc konceptů, asistent je vypíše a nechá vás vybrat.
- Součty a DPH spočítá aplikace; asistent vrátí upravený doklad.
- **Upravit jde jen koncept.** Vystavenou fakturu asistent odmítne změnit,
  ta se opravuje dobropisem nebo stornem v aplikaci.
- Jedinou položku dokladu odebrat nejde, doklad musí nějakou mít. Textové řádky se
  mezi položky nepočítají ([§ 15.9.3](15_Faktura_editor.md#1593-polozky)).

V režimu jen pro čtení se tyto nástroje nenabízejí; vyžadují token
s oprávněním čtení a zápis.

#### 105.8.7.5 Další práce s vydanými fakturami

Kromě konceptu umí asistent s vydanými doklady totéž, co detail faktury v aplikaci:

| Nástroj | Co dělá |
|---|---|
| `delete_invoice_draft` | smaže koncept; vystavený doklad odmítne |
| `cancel_invoice` | interní storno, nebo koncept dobropisu se zápornými položkami |
| `uncancel_invoice` | zruší interní storno omylem stornované faktury nebo zálohy |
| `clone_invoice` | založí koncept jako kopii dokladu |
| `create_final_invoice_from_proforma` | ze zaplacené zálohy založí koncept finální faktury s odečtenou zálohou |
| `list_invoice_advance_drawdown_candidates`, `apply_invoice_advance_drawdown`, `remove_invoice_advance_drawdown` | postupné čerpání jedné zálohy víc fakturami (milníky): na koncept faktury odečte zadanou část zálohy, nebo odpočet odebere |
| `list_invoice_advance_candidates`, `list_proforma_final_candidates`, `link_invoice_advance`, `unlink_invoice_advance` | ruční propojení faktury se zálohou a jeho zrušení |
| `preview_invoice_penalty`, `create_invoice_penalty` | výpočet úroku z prodlení a koncept penalizační faktury |
| `add_invoice_payment`, `delete_invoice_payment`, `unmark_invoice_paid` | evidence částečné i celé úhrady, smazání úhrady, vrácení stavu zaplaceno |
| `create_payment_tax_document` | koncept daňového dokladu k přijaté platbě zálohy |
| `set_invoice_payment_schedule` | rozpis plateb splátkového kalendáře (`rent_calendar`, splátky s vlastním DUZP) nebo platebního kalendáře (`advance_calendar`, bez DUZP) |
| `get_invoice_isdoc` | ISDOC XML vystaveného dokladu jako text |
| `get_invoice_recipients` | komu by se doklad nebo upomínka poslaly |
| `send_invoice_reminders_bulk` | upomínky k více fakturám naráz, nejvýš 50 |

Platí přitom:

- **Storno a dobropis jsou daňové kroky.** Asistent je udělá jen na váš výslovný
  pokyn a vždy až po potvrzení ([§ 105.8.9.4](#105894-potvrzovani-nevratnych-kroku)).
  Interní storno je pro doklad, který odběratel nepřevzal: faktura dostane stav
  stornovaná a vypadne z evidence DPH. Pokud doklad odběratel už má, patří k němu
  dobropis. Ten vznikne jako koncept, zkontrolujete ho a vystavíte stejně jako fakturu.
- Storno, zrušení storna ani nové doklady s datem v uzavřeném nebo uzamčeném období
  (po podání přiznání k DPH) aplikace nepovolí.
- Finální faktura ze zálohy, dobropis, kopie, penalizační faktura i daňový doklad
  k platbě vznikají jako **koncepty**. Daňový dopad mají až po vystavení.
- **Splátkový kalendář** k nájmu (§ 31 ZDPH) a **platební kalendář** záloh (§ 31a ZDPH)
  jsou pro odběratele jeden daňový doklad s rozpisem plateb. Splátkový kalendář potřebuje
  nájemní smlouvu a jeho splátky mají vlastní DUZP, platební kalendář DUZP nemá.
  Rozpis může asistent sestavit z podkladu, který mu dáte (smlouva, tabulka splátek),
  a před uložením vám ho ukáže. Součet základu, DPH i celkové částky splátek musí přesně
  sedět na doklad; když nesedí, asistent nic neuloží a řekne rozdíl. Úhrady pak asistent
  eviduje na dokladech splátek, ne na kalendáři
  ([§ 15.9.2](15_Faktura_editor.md#1592-hlavicka)).
- Platby z bankovního výpisu se párují v bance. Ručně se evidují hotovost,
  zápočet nebo platba, kterou banka nespárovala.
- Upomínka i poděkování za platbu jsou e-maily odběrateli, posílají se jen na váš pokyn.
- Zaúčtování dokladu asistent neumí, to zůstává v aplikaci.

#### 105.8.7.6 Pravidelná fakturace

Šablony pravidelné fakturace asistent čte (`list_recurring_invoices`,
`get_recurring_invoice`, `recurring_invoice_history`) a umí je i spravovat:

| Nástroj | Co dělá |
|---|---|
| `create_recurring_invoice` | založí šablonu: odběratel, periodicita, den v měsíci, položky |
| `update_recurring_invoice` | změní zadaná pole; ostatní i položky zůstanou, pokud položky nezadáte celé znovu |
| `pause_recurring_invoice`, `resume_recurring_invoice` | pozastaví a obnoví generování |
| `reschedule_recurring_invoice` | posune datum příští faktury |
| `run_recurring_invoice_now` | vygeneruje fakturu hned, mimo rozvrh |
| `delete_recurring_invoice` | smaže šablonu; už vygenerované faktury zůstanou |

Platí přitom:

- Bez automatického vystavení vznikají z šablony **koncepty** ke kontrole.
  Automatické vystavení a odeslání e-mailem asistent zapne jen na výslovný pokyn.
- Ruční vygenerování faktury vyžaduje potvrzení. U šablony s automatickým
  vystavením se faktura rovnou vystaví (a případně odešle), proto asistent předem
  řekne, co se stane. Na požádání vytvoří jen koncept.
- Úprava šablony se týká až faktur, které z ní teprve vzniknou.

### 105.8.8 Zakázky, dokumenty a kniha jízd

#### 105.8.8.1 Zakázky

Asistent umí zakázku založit, upravit, archivovat i bezpečně smazat, pokud ještě
nemá doklady. Při úpravě nejdřív načte současný stav a zachová všechna nezadaná
pole. Změna výchozí kategorie tržby může doplnit tuto kategorii i do dosavadních
faktur zakázky; proto ji zadávejte výslovně.

Přehled ziskovosti je **jen ke čtení**. V podvojném účetnictví vychází z deníku,
v daňové evidenci z dokladů, a upozorní i na nezaúčtované doklady. Asistent přes
něj nic nezaúčtuje ani neopraví.

#### 105.8.8.2 Dokumenty

MCP umí dokumenty vypsat, hledat v názvu, popisu i vytěženém textu, přečíst
omezený úsek textu, upravit název, popis a tagy a připojit dokument k odběrateli,
dokladu nebo zakázce. Dlouhý text se vrací po částech nejvýše 50 000 znaků.
Platí stejná firemní a osobní oprávnění jako v aplikaci.

Soubor umí asistent do Dokumentů i nahrát a originál stáhnout, postup a limity
popisuje [§ 105.8.8.6](#105886-soubory-a-prilohy). Pro práci s obsahem dokumentu je
ale levnější vytěžený text než celý soubor. Odpojení vazby vyžaduje potvrzení,
dokument samotný ale nemaže.

#### 105.8.8.3 Kniha jízd

Asistent umí spravovat vozidla, přidávat a upravovat jízdy a tankování a číst
roční souhrn kilometrů a spotřeby. U nové jízdy vyžaduje vozidlo, datum,
vzdálenost nebo oba stavy tachometru a hlavně **výslovně vybranou kategorii**.
Soukromou či služební povahu cesty nikdy neodhaduje - pokud kategorii neřeknete,
nejdřív nabídne číselník a doptá se.

Smazání vozidla, jízdy nebo tankování vyžaduje potvrzení. Používané vozidlo
nelze smazat; lze ho pouze archivovat. Roční daňový souhrn je dostupný jen ke
čtení a žádný účetní zápis z MCP nevytváří.

#### 105.8.8.4 Přijaté faktury

Došlý doklad od dodavatele asistent zapíše jako **koncept**. Do evidence DPH,
závazků a účetnictví vstoupí až přijetím, do té doby jde koncept opravit nebo
smazat.

| Nástroj | Co dělá |
|---|---|
| `create_purchase_invoice` | založí koncept: dodavatel, číslo dokladu dodavatele, data, měna, druh dokladu, odpočet DPH, kategorie nákladu, zakázka, položky |
| `update_purchase_invoice` | změní hlavičku konceptu, ostatní pole i položky zůstanou |
| `add_purchase_invoice_item`, `update_purchase_invoice_item`, `remove_purchase_invoice_item` | přidá, změní nebo odebere jednu položku konceptu |
| `delete_purchase_invoice` | smaže koncept |
| `receive_purchase_invoice` | přijme doklad; také zruší označení úhrady nebo obnoví stornovaný doklad |
| `mark_purchase_invoice_paid` | zaeviduje úhradu, která nepřišla z výpisu ani z pokladny |
| `cancel_purchase_invoice` | stornuje doklad |
| `set_purchase_invoice_document_kind`, `set_purchase_invoice_project`, `set_purchase_invoice_expense_kinds` | změní druh dokladu, zakázku nebo druh nákladu po položkách |
| `set_purchase_invoice_exchange_rate` | nastaví ruční kurz konceptu v cizí měně |
| `list_purchase_advance_candidates`, `link_purchase_advance`, `unlink_purchase_advance` | spáruje konečnou fakturu se zálohou, nebo vazbu zruší |
| `get_purchase_invoice_payment`, `set_purchase_invoice_payment_account`, `verify_purchase_payment_account` | platební údaje, změna účtu dodavatele a jeho ověření v registru plátců DPH |
| `list_purchase_payment_candidates`, `create_purchase_payment_order`, `list_purchase_payment_orders`, `delete_purchase_payment_order` | příprava a přehled příkazů k úhradě |
| `get_purchase_invoice_activity`, `list_expense_categories` | historie dokladu a číselník kategorií nákladů |

Platí přitom:

- **Sazbu DPH a DUZP si asistent nevymýšlí.** Bere je z dokladu, a když na
  dokladu nejsou, zeptá se nebo je vynechá. DUZP a datum přijetí dokladu
  rozhodují o období, ve kterém se uplatní odpočet DPH; bez data přijetí se
  použije datum vystavení.
- U cizí měny se kurz načte z ČNB k DUZP, a když DUZP chybí, k datu vystavení.
  Ruční kurz zadává asistent jen na výslovný pokyn.
- **Mění se jen to, co řeknete.** Při úpravě hlavičky i položek zůstanou ostatní
  údaje beze změny, včetně těch, které v rozhovoru nevidíte: druh nákladu,
  nákladový účet, časové rozlišení, vazba na sklad a pořadí položek.
- Po změně dodavatele nebo přenesené daňové povinnosti si aplikace zařazení
  položek pro přiznání k DPH odvodí znovu. Změna data zařazení nemění.
- Doklad s ruční rekapitulací DPH podle dokladu upravujte v aplikaci; asistent
  jeho položky nemění, protože by rekapitulace přestala sedět.
- **Upravit jde jen koncept.** Přijatý doklad opravuje účetní v aplikaci.
- **Přijetí může doklad rovnou zaúčtovat.** Má-li firma zapnuté automatické
  účtování přijatých faktur, aplikace doklad při přijetí zaúčtuje; u úhrady
  hotově z pokladny založí výdajový pokladní doklad. Asistent to před přijetím
  řekne a čeká na potvrzení.
- **Zaúčtovat doklad asistent neumí.** Ruční zaúčtování je účetní úkon a přes
  token nejde. U zaúčtovaného dokladu proto odmítne i změnu druhu dokladu,
  druhu nákladu, kurzu a spárování se zálohou, protože by se doklad rozešel
  s deníkem. Zakázku přiřadit jde, je to jen analytický údaj.
- Zálohová faktura se zakládá jako druh „záloha“, daňový doklad k zaplacené
  záloze jako „daňový doklad k záloze“. Konečnou fakturu se zálohou spáruje
  `link_purchase_advance`; náklad se pak nepočítá dvakrát.
- **Příkaz k úhradě asistent jen připraví.** Do banky nic neodesílá a faktury
  neoznačí jako uhrazené. Soubor pro banku stáhnete a odešlete v aplikaci.
- PDF dokladu nahrajete, stáhnete nebo z ISDOC založíte doklad nástroji ze [§ 105.8.8.6](#105886-soubory-a-prilohy).

V režimu jen pro čtení se zápisové nástroje nenabízejí; vyžadují token
s oprávněním čtení a zápis.

#### 105.8.8.5 Ostatní pohledávky a závazky

Nájem, půjčka nebo úvěr, leasing, kauce, pojistné a poplatky se evidují jako
ostatní pohledávky a závazky (viz [kapitola 52](52_Ucetni_denik.md)). Asistent
je umí číst i připravit, ale **jen jako koncept**. Koncept nemá zápis v deníku;
potvrdí a zaúčtuje ho účetní v aplikaci, kde vidí kontext a krok potvrzuje.

| Co | Nástroje |
|---|---|
| Čtení | `list_other_items`, `get_other_item`, `list_other_item_kinds`, `list_other_item_allocations`, `other_item_payment_candidates`, `list_other_item_schedules`, `get_other_item_schedule`, `get_other_item_installments` |
| Koncept | `create_other_item`, `update_other_item`, `delete_other_item` |
| Splátkový kalendář | `set_other_item_installments`, `clear_other_item_installments` |
| Opakování | `create_other_item_schedule`, `set_other_item_schedule_status` |
| Analytické účty | `chart_of_accounts` (čtení), `create_analytic_account`, `update_analytic_account` |
| Smlouva a další dokumenty | `upload_document` nebo `link_document` s typem `other_item`, `list_entity_documents`, `unlink_document` |
| Dimenze (středisko, projekt) | `list_dimensions`, `get_document_dimensions` (typ `other-items`), `set_other_item_dimensions` |

**Koncept.** Kontaci zadáte buď jedním protiúčtem, nebo několika protiřádky,
jejichž součet musí přesně odpovídat částce dokladu. Agenda je jen v Kč. Při
úpravě asistent načte současný stav a pošle celý doklad, takže nezadaná pole se
nezmění. Jediný protiřádek se při změně částky přepočte, u více protiřádků se
asistent zeptá na nové rozdělení. Upravit a smazat lze jen koncept.

**Smlouva u dokladu.** Analyzovaný soubor (např. PDF smlouvy o zápůjčce)
asistent nahraje nástrojem `upload_document` a koncept založí s `document_id`,
takže se smlouva připojí hned. Jde to i opačně: `upload_document` s typem
`other_item` a ID už založeného dokladu, nebo `link_document` u dokumentu, který
v aplikaci už je. Dokument i doklad musí patřit téže firmě a k připojení je
potřeba oprávnění zápisu k ostatním položkám. Připojené soubory vypíše
`list_entity_documents`.

**Dimenze.** Středisko, projekt nebo jinou dimenzi hlavičky nastaví asistent
nástrojem `set_other_item_dimensions`, jen u konceptu. Pošle vždy celou sadu
(typ, který chybí, se odebere). U potvrzeného či zaúčtovaného dokladu a u
konceptu z opakování s automatickým účtováním se dimenze mění jen v aplikaci.
Firma musí mít dimenze zapnuté.

**Úroky.** Samostatnou evidenci úroků agenda nemá. Úrok ze zápůjčky asistent
založí jako další koncept (např. na analytice `378.810`), případně jako
opakování, a jistinu drží ve splátkovém kalendáři původního dokladu.

**Splátkový kalendář** může asistent vyčíst z dokumentu, třeba z úvěrové nebo
leasingové smlouvy či splátkového kalendáře banky, a poslat ho jako data. Před
zápisem zkontroluje, že:

- splátek je 2 až 120 a termíny jdou vzestupně, nejdříve od data vzniku dokladu,
- částky jsou kladné a na haléře,
- součet splátek přesně odpovídá částce dokladu,
- doklad zatím nemá spárovanou úhradu.

U úvěru a leasingu patří do kalendáře jen jistina; úroky a poplatky nejsou
součástí částky dokladu. Uložení **nahradí celý dosavadní kalendář**, proto
asistent posílá vždy všechny splátky. Zrušení celého kalendáře vyžaduje
potvrzení. Splátky slouží k plánu cashflow a do deníku nic nezapisují.

**Opakování** vytvoří z dokladu pravidelnou řadu (měsíčně, čtvrtletně, ročně).
Aplikace další doklady zakládá dopředu jako koncepty. Automatické účtování
přes asistenta zapnout nejde: opakování vzniká vždy bez něj a asistent smí
opakování pozastavit nebo obnovit jen tehdy, když automatiku nemá. Koncept
z automaticky účtovaného opakování asistent neupraví ani nesmaže: úpravu by
aplikace zaúčtovala bez další kontroly a smazáním by zaúčtování tiše zmizelo.
Vypnout automatiku asistent smí, účtování tím jen zastaví.

**Analytické účty.** Chybí-li v účtové osnově analytika, na kterou má koncept
mířit (třeba `378.800` Zápůjčka a `378.810` Úroky pod syntetickým účtem `378`),
asistent ji založí nástrojem `create_analytic_account`. Syntetický účet odvodí
z čísla před tečkou, nebo ho zadáte výslovně. Analytika převezme typ účtu
i stranu po syntetice a číslo bez tečky se doplní (`378800` → `378.800`).
Nástrojem `update_analytic_account` asistent analytiku přejmenuje, deaktivuje
nebo znovu aktivuje. Číslo účtu se změnit nedá, syntetické účty asistent jen
čte a nepoužitou analytiku smažete jen v aplikaci. Funguje to u firmy
s podvojným účetnictvím a s oprávněním zápisu do účetnictví.

Přes asistenta **nejde**: potvrdit nebo zaúčtovat doklad, stornovat ho,
přeúčtovat, spárovat nebo odpojit úhradu ani ručně vygenerovat doklady
z opakování. Tyto kroky dělá účetní v aplikaci.

#### 105.8.8.6 Soubory a přílohy

Asistent umí soubory stahovat i nahrávat. Soubor přitom **jde celý přes
asistenta**: obsah se přenáší v konverzaci a model s ním pracuje stejně jako se
souborem, který mu přiložíte sám. Proto si o soubor řekněte jen tehdy, když ho
opravdu potřebujete. Na otázku, co je v dokumentu napsané, stačí vytěžený text
(`get_document`), který je mnohem menší.

| Agenda | Stažení | Nahrání a mazání |
|---|---|---|
| **Vydané faktury** | PDF faktury, ISDOC (jen vystavená faktura), přílohy | přidat přílohu, smazat přílohu |
| **Přijaté faktury** | archivované PDF originálu | nahrát PDF nebo fotku dokladu, smazat PDF, založit fakturu ze souboru ISDOC |
| **Dokumenty** | originál dokumentu i další soubory dokladu | nahrát soubor do složky, rovnou s názvem, popisem, tagy a vazbou na záznam |
| **E-shop** | nic | nahrát obrázek nebo PDF ke kartě zboží |

**Velikost.** Lokální server přenese soubor do 10 MB (mění se proměnnou
`MYUCTO_MAX_FILE_MB`, [§ 105.8.4](#10584-nastaveni)), serverový MCP do 5 MB, protože
tam soubor putuje několika procesy aplikace. Větší soubor nástroj odmítne se
zprávou `file_too_large`; stáhněte nebo nahrajte ho přímo v aplikaci. Platí i limity
samotné agendy, například u přílohy faktury nejvýš 10 MB na soubor a 20 MB na
všechny přílohy jedné faktury.

**Podporované typy při nahrání.**

| Kam | Typy |
|---|---|
| Příloha vydané faktury | PDF, Word, Excel, PowerPoint, OpenDocument, TXT, CSV, JPG, PNG, GIF, WEBP, HEIC, ZIP |
| PDF přijaté faktury | PDF; fotku JPG nebo PNG aplikace převede na PDF |
| Založení přijaté faktury | ISDOC, ISDOCX a PDF s vloženým ISDOC |
| Dokumenty | PDF, ISDOC a XML, obrázky, Word, Excel, PowerPoint, OpenDocument, TXT, CSV, ZIP |
| Obrázky zboží | JPG, PNG, WEBP, GIF a PDF |

Přípona souboru musí odpovídat jeho typu a aplikace typ znovu ověří z obsahu.
HTML, SVG, skripty a spustitelné soubory přes MCP nahrát nejde. Z názvu souboru se
odstraní cesta i znaky, které do názvu nepatří.

**Založení přijaté faktury ze souboru** je deterministický import strukturovaných
dat, nepoužívá AI vytěžení. Když PDF vložený ISDOC nemá, asistent dostane chybu
`no_embedded_isdoc`; doklad pak založí ručně a PDF k němu nahraje jako originál.
Stejný doklad, který už v evidenci je, se podruhé nezaloží.

**Výsledek stažení.** Obrázek se vrátí jako obrázek, PDF jako vložený soubor
a ISDOC jako text. Na požádání vrátí asistent obsah i jako base64, třeba když ho
chce předat dalšímu nástroji.

Pro nahrání musí mít token oprávnění čtení a zápis a v režimu jen pro čtení se
nahrávací nástroje nenabízejí. Doklad v uzavřeném období aplikace změnit
nedovolí stejně jako v aplikaci. Smazání přílohy nebo PDF a náhrada už
archivovaného PDF vyžadují potvrzení ([§ 105.8.9.4](#105894-potvrzovani-nevratnych-kroku)).

### 105.8.9 E-shop a sklad

Na rozdíl od účetnictví je e-shopová a skladová agenda **obousměrná** - asistent
umí katalog nejen číst, ale i zakládat, upravovat a mazat. Důvod je prostý:
skladový pohyb je dohledatelný ve skladové knize a zaúčtovaný doklad jde
stornovat protidokladem, takže se chyba dá v aplikaci napravit. Účetní dopad
vzniká až v účetní vrstvě, která zůstává jen ke čtení.

> [!TIP]
> Celá tahle agenda je **volitelný modul**. Když ho firma nemá zapnutý,
> nástroje vracejí `403 stock_disabled` - zapíná se v nastavení firmy.

#### 105.8.9.1 Co asistent umí

| Oblast | Čtení | Zápis |
|---|---|---|
| **Zboží - skladová karta** | seznam, našeptávač, detail, skladová kniha (pohyby) | založit, upravit (SKU, název, MJ, sazba DPH, minimální zásoba, aktivita), smazat |
| **Zboží - obsah pro e-shop** | karta i s kategoriemi, štítky a parametry; jazykové verze | výrobce, záruka, dodací lhůta, hmotnost, publikace, překlady, kategorie, štítky, parametry, poplatky |
| **Ceny** | ceny po měnách, marže; individuální ceny zákazníků a ceny v cenových hladinách na kartě; nacenění řádků pro konkrétního odběratele, měnu, datum a balení | uložit cenotvorbu (přirážka / pevná cena / zaokrouhlení), vynutit přepočet |
| **Balení a šarže** | balení karty (poměr, EAN balení, výchozí prodejní jednotka), šarže a sériová čísla s expirací a historií | - |
| **Dodavatelé zboží** | seznam s nákupní cenou a dodací lhůtou | nahradit seznam dodavatelů zboží |
| **Nabídky dodavatelů („u dodavatele")** | přehled dvojic zboží × dodavatel napříč katalogem - nákupní cena a měna, kód u dodavatele, dodací lhůta, minimální odběr, balení a množství hlášené dodavatelem | založit a upravit nabídku (upsert podle dvojice zboží × dodavatel), odebrat nabídku |
| **Média** | seznam obrázků a příloh | nahrání obrázku nebo PDF, popisky, pořadí, hlavní obrázek, smazání |
| **Kategorie** | strom, detail, překlady | založit, upravit, přesunout v stromu, uložit překlady, smazat |
| **Číselníky** | výrobci, štítky, typy poplatků, parametry i jejich hodnoty; balení, cenové hladiny i s pravidly, jazyky a prodejní měny | výrobci, štítky, typy poplatků a parametry: založit / upravit / smazat |
| **Sklady** | seznam, detail, hodnota zásob, skladové lokace | založit, upravit, smazat |
| **Prodejní objednávky** | seznam se stavem obchodu, platby a expedice, detail s rezervacemi, fronta objednávek čekajících na zboží | - |
| **Cyklické inventury** | seznam, detail s řádky | - |
| **Zásoby** | stav, dostupnost s rezervacemi, sestava stavu, ocenění k datu | - |
| **Množstevní pohledy** | všechny čtyři veličiny najednou (skladem, rezervováno, prodejné, na cestě), rozpad „na cestě" na konkrétní objednávky a rozpad rezervací na konkrétní faktury | - |
| **Doplnění zásob** | návrh, co a kolik doobjednat (zboží pod minimem) | hromadně z návrhu založit objednávky seskupené po dodavatelích |
| **Objednávky u dodavatele** | seznam se stavem a plněním (objednáno / přijato / zbývá), detail s řádky | založit koncept, upravit, odeslat, potvrdit, uzavřít zbytek, stornovat, znovu otevřít, smazat koncept, vytvořit příjemku |
| **Příjemky, výdejky, převodky** | seznam, detail s řádky | založit koncept, upravit, zaúčtovat, stornovat, smazat koncept |
| **Inventury** | seznam, detail s rozdíly | založit, spustit, zapsat napočítané množství, uzavřít |

#### 105.8.9.2 Dávkové čtení katalogu

Když asistent potřebuje více karet najednou, použije `get_products_batch`
nebo `get_product_prices_batch` místo stovek jednotlivých dotazů. Oba nástroje
jsou čtecí a fungují s tokenem v režimu `read` i při
`MYUCTO_READ_ONLY=1`.

`get_products_batch` přijme až 500 unikátních ID. Odpověď zachová jejich
pořadí; cizí nebo chybějící karta je vždy `unavailable` s `data: null`, takže
asistent nerozliší chybějící kartu od karty jiné firmy. Bez výběru polí dostane
SKU, název, EAN a aktivitu. Může si vyžádat jen potřebné části, například
překlady, ceny nebo dostupnost, a omezit je na konkrétní jazyky, měny a sklady.
Nákladové ocenění (`costs`) není dostupné tokenu jen pro čtení: vyžaduje
oprávnění `stock.items.write`.

`get_product_prices_batch` vrací platnou cenu pro každou kombinaci karty a
množství. Množství MCP vyžaduje jako kladný desetinný řetězec, aby se
nezaokrouhlilo při předání; API sice při přímém volání dovoluje jeho vynechání
a použije `"1"`, MCP ho vyžaduje záměrně. Měna je výchozí CZK a cena, která pro
kartu a měnu neexistuje, je `null`, ne nula.

`list_products` umožňuje výběr podle výrobce, dodavatele, kategorie,
štítků, dostupnosti a chybějících údajů. `get_catalog_facets` vrací počty
hodnot filtrů nad celou odpovídající množinou. `get_catalog_job` ukáže
průběh a souhrnný výsledek úlohy, ke které má uživatel oprávnění.

#### 105.8.9.3 Ceny pro konkrétního odběratele

Na otázku „za kolik to prodáme firmě ACME“ asistent odpoví nástrojem
`quote_product_prices`. Ten spočítá cenu stejně jako faktura: nejdřív
individuální cena zákazníka, pak cenová hladina odběratele, jinak standardní
cena; akční cena vyhraje, jen když je levnější. Počítá se v zadané měně a k datu
a řádek může být i v balení, třeba „10 kartonů“. Nic se neukládá, stačí token
jen pro čtení.

> „Kolik zaplatí ACME za 10 kartonů kabelu k 1. říjnu?“
> „Které cenové hladiny máme a jakou slevu dává Gold?“
> „Má tahle karta nějaké smluvní ceny zákazníků?“

Individuální ceny zákazníků, cenové hladiny, balení a číselník balení asistent
jen čte. Nastavují se v aplikaci na kartě zboží a v číselnících e-shopu.

Firmy bez skladového modulu mají místo cen na kartách **ceník služeb**.
Asistent z něj umí vypsat položky, jejich ceny po měnách, individuální ceny
zákazníků i výslednou cenu pro konkrétního odběratele a měnu dokladu. Ceník také
jen čte.

#### 105.8.9.4 Potvrzování nevratných kroků

Mazání, storno dokladu, uzavření inventury, odebrání položky z konceptu
faktury, smazání konceptu ostatní pohledávky nebo závazku, zrušení celého
splátkového kalendáře, smazání přílohy faktury nebo PDF přijaté faktury a náhrada
už archivovaného PDF vyžadují **výslovné potvrzení**. U vydaných faktur
a pravidelné fakturace jde o smazání konceptu, storno a dobropis, zrušení storna,
smazání úhrady, vrácení stavu zaplaceno, hromadné upomínky, smazání šablony
pravidelné fakturace a ruční vygenerování faktury ze šablony. U přijatých faktur
navíc přijetí dokladu (může ho rovnou zaúčtovat), storno, smazání konceptu,
odebrání položky, zrušení vazby na zálohu, změna účtu dodavatele a smazání
příkazu k úhradě. Potvrzovací výpis u přijaté faktury vždy uvádí dodavatele,
číslo dokladu dodavatele a částku.
První volání takového nástroje záměrně **nic neprovede** - jen vrátí, čeho by se
změna týkala:

> **NEPROVEDENO - chybí potvrzení.** Smazat se má výrobce: `ACME - Acme s.r.o.`
> Operace je nevratná. Ukaž to uživateli a teprve po jeho souhlasu zavolej
> nástroj znovu s `confirm: true`.

Funguje to tedy jako **suchý běh**: uvidíte konkrétní záznam včetně kódu a názvu,
ne jen to, co si asistent myslí, že maže. Teprve druhé volání s potvrzením
operaci provede. U médií a hodnot parametrů se navíc kontroluje, že záznam
opravdu patří ke zboží (resp. parametru), které jste uvedli - překlep v čísle tak
nesmaže fotku cizímu zboží.

Praktický dopad: **asistent se vás před smazáním vždycky zeptá.** Řetězec „ukliď
nepoužívané štítky“ neproběhne jedním vrzem, ale jako výpis a dotaz.

#### 105.8.9.5 Kolekce se nahrazují celé

Ceny, dodavatelé, jazykové verze, kategorie, štítky, parametry a řádky
skladového dokladu se ukládají **jako celek** - co v uloženém seznamu není, to se
smaže. Není to nedostatek nástroje, ale způsob, jakým to ukládá i aplikace.

Nástroje na to asistenta upozorňují a jeho správný postup je: nejdřív si stav
načíst, do něj vložit změnu a poslat zpátky **kompletní** seznam. Když si nejste
jistí, řekněte si o vypsání současného stavu předem:

> „Ukaž ceny toho zboží, pak k nim přidej eurovou cenu s marží 25 %.“

#### 105.8.9.6 Skladové doklady mají dvě fáze

Příjemka, výdejka i převodka vznikají jako **koncept**, který se stavem skladu
nedělá nic - teprve zaúčtování pohyb provede, přidělí dokladu číslo a doklad
uzamkne. Nástroje ty dvě fáze schválně nespojují: asistent má doklad připravit
a nechat si ho zkontrolovat, než se zásoby pohnou.

> „Nasklaď 20 kusů kabelu na hlavní sklad za 89 Kč za kus.“
> → asistent založí koncept příjemky a ukáže vám ho.
> „Souhlasím, zaúčtuj.“
> → teprve teď se zásoba zvýší.

Zaúčtovaný doklad už upravit ani smazat nejde, jen **stornovat** - vznikne k němu
opačný protidoklad v původních cenách a oba zůstanou ve skladové knize.

Server sám odmítne (`409`) výdej do minusu, jakýkoli pohyb na skladu
s rozběhnutou inventurou a doklad do uzavřeného účetního období.

#### 105.8.9.7 Objednávky u dodavatele

Asistent umí celý životní cyklus objednávky
([§ 37.12.11](37_Sklad.md#371211-objednavky-u-dodavatele)) - a drží se v něm stejných
pravidel jako aplikace:

- **Nová objednávka vzniká jako koncept.** Nedostane číslo a do „na cestě" se
  nezapočítá. Teprve *odeslat* jí přidělí číslo řady OBJ a zboží se začne počítat
  jako objednané.
- **Úprava objednávky nahrazuje i řádky celé** - platí tu totéž pravidlo jako
  u ostatních kolekcí (viz výše). Upravovat jde jen koncept.
- **Zavřít zbytek, stornovat a smazat vyžadují potvrzení** (jsou nevratné).
  Storno projde jen do doby, než se z objednávky cokoli přijme; potom server
  odmítne s doporučením použít *zavřít zbytek*.
- **Příjem z objednávky založí příjemku jako koncept** - skladem pohne teprve
  její zaúčtování, stejně jako u ručně pořízeného dokladu.
- **Doplnění zásob umí objednat hromadně**: z plochého seznamu zboží a množství
  vznikne **jedna objednávka na dodavatele**, vždy jako koncept. Položky, které
  nešly zařadit (chybí dodavatel, neplatné množství, neznámé zboží), asistent
  dostane zpátky vypsané i s důvodem - nikdy se nezahodí tiše.

Množstevní pohledy jsou jen ke čtení a odpovídají [§ 37.12.9](37_Sklad.md#37129-skladem-rezervovano-na-ceste-u-dodavatele): `stock_quantities`
vrací u každé karty **skladem, rezervováno, prodejné a na cestě**,
`stock_in_transit` rozpad na konkrétní objednávky a `stock_reservations` rozpad
na konkrétní faktury.

> „Kolik máme kabelů volných k prodeji a co z toho je jen rezervované?"
> „Co je potřeba doobjednat a od koho?"
> → asistent přečte množstevní pohledy a návrh doplnění, objednávky ale založí
> jako koncepty, které si odsouhlasíte.

#### 105.8.9.8 Inventura

Postup kopíruje aplikaci: založit → spustit (udělá se snímek očekávaných stavů
a **sklad se zablokuje** pro zaúčtování dokladů) → zapsat napočítané množství →
uzavřít. Uzavření vygeneruje rozdílovou příjemku na přebytky a výdejku na manka,
rovnou zaúčtované - proto vyžaduje potvrzení a proto asistent před ním hlásí,
kolik řádků zůstalo nespočítaných (ty se přeskočí).

#### 105.8.9.9 Co přes MCP nejde

- **Hromadný import zboží z XLSX/CSV** ani **import ceníku dodavatele**. Oba
  importy mají v aplikaci vlastní průvodce s náhledem.
  Jednotlivé nabídky dodavatelů ale asistent zakládat i upravovat umí.
- **Stáhnout PDF nebo XLSX** skladového dokladu, inventurního soupisu či sestavy.
  Data sestav asistent přečte, hotový soubor si stáhnete v aplikaci.

### 105.8.10 Log volání

Stránka **Firma → MCP server** má dole **Log volání** - každé volání vašich API
tokenů včetně zamítnutých. U volání z MCP serveru je vidět i **název nástroje**,
takže poznáte, co asistent dělal, ne jen jaké URL zavolal.

Filtruje se podle tokenu, metody, cesty, zdroje a na samotné chyby. Volání přes
online připojení nese štítek **OAuth** a místo technického názvu tokenu název
aplikace, které přístup patří. Superadmin může zaškrtnout **Všichni uživatelé**:
log pak ukazuje volání všech uživatelů instance se sloupcem **Uživatel** (jméno
a e-mail). Podrobnosti jsou v [§ 103.9.9](103_API.md#10399-log-volani-api).

### 105.8.11 Bezpečnost

Následující pravidla pro API tokeny se týkají lokálního souboru `.mjs`.
Online připojení používá přihlášení a souhlas popsané v [§ 105.8.3.5](#105835-vzdalene-pripojeni-bez-stahovani).

- Token se ukládá jen jako **SHA-256 hash**; plaintext se zobrazí jednou.
- **Omezte token na IP** - uniklý token je pak mimo vaši síť k ničemu.
- **Rozsah `čtení`** stačí na drtivou většinu dotazů; zápis dávejte vědomě.
- Bearer token má přístup **jen k veřejnému API**. Správa uživatelů, rolí,
  citlivá nastavení a podpisové profily jsou pro něj nedostupné bez ohledu
  na roli uživatele, který token vydal.
- Token **nedávejte do souborů, které commitujete** do gitu (týká se hlavně
  `.vscode/mcp.json` a `.cursor/mcp.json` v projektu).
- Nepoužívaný token **zrušte**. Historie volání v logu zůstane.
- **Mazání a storna se neprovedou napoprvé.** Nástroje, které nejdou vzít zpět,
  vyžadují potvrzení a při prvním zavolání jen vypíšou, čeho by se změna týkala
  (viz [§ 105.8.9](#10589-e-shop-a-sklad)). Je to pojistka proti tomu, aby asistent
  smazal něco, co si domyslel - ne náhrada za `MYUCTO_READ_ONLY=1`, který je
  u nedozorovaného provozu pořád ta správná volba.
- **Argumenty se ověřují proti schématu nástroje.** Lokální i serverový MCP
  odmítne volání s neznámým parametrem, špatným typem nebo hodnotou mimo
  povolený rozsah dřív, než cokoli odejde do aplikace. ID záznamu musí být celé
  číslo, takže podvržená hodnota typu `../../invoices/123` nesměruje nástroj
  na jiný záznam. Chyba uvede název parametru a asistent volání opraví.

### 105.8.13 Vlastní HTTPS certifikát

Instance s certifikátem od firemní nebo vlastní autority (typicky testovací
prostředí) je zvláštní případ: **Node má vlastní seznam kořenových autorit
a úložiště operačního systému ve výchozím stavu nečte.** Adresa, která
v prohlížeči funguje bez varování, tedy asistentovi spadne - a protože `fetch`
takovou chybu hlásí jako obyčejné selhání spojení, vypadá to, jako by server
neběžel. Přesně tohle je za hláškou *„server momentálně neodpovídá“*.

Server proto **při startu autority ze systému načte sám**. Nainstalovaný root
certifikát tak stačí a nic dalšího nastavovat nemusíte. Co načetl, vypíše na svůj
chybový výstup:

```
MyÚčto MCP připojen - nástroje načteny, API https://…/api/v1; TLS: systémové certifikáty načteny
```

Když spojení i tak selže na certifikát, dostanete konkrétní hlášku s postupem.
Nejčastější zbylé příčiny:

- **Neúplný řetěz certifikátů.** Server neposílá mezilehlý certifikát -
  projeví se jako `unable to verify the first certificate`. Náprava je na straně
  webserveru, ne klienta.
- **Node starší než 22.15**, který runtime načtení autorit neumí. Přidejte do
  konfigurace asistenta `NODE_OPTIONS=--use-system-ca`, případně
  `NODE_EXTRA_CA_CERTS=/cesta/k/ca.pem`.
- **Certifikát není vydaný nainstalovanou autoritou** (jiný self-signed).

Jako poslední možnost - a **výhradně proti vývojové instanci** - jde ověřování
vypnout přes `MYUCTO_INSECURE_TLS=1`. Server na to při startu hlasitě upozorní.
Na produkci to nepoužívejte: bez ověření certifikátu jde spojení odposlechnout
i podvrhnout, a token v hlavičce je to první, co útočník získá.

### 105.8.14 Účetní kontext k datu

Nástroj `get_accounting_context` čte účetní rámec, metodu, role účtů a
identifikátory metadat k povinnému `date`. Zachovává `complete`, `findings`,
`temporal_source` i `null`; neúplná historie není nula ani neplatný účet.
Používá současnou znalost evidence. Nepřijímá `known_at`, neinicializuje
osnovu nebo dimenze a nemění účetnictví. Podrobnosti jsou v kapitole
[REST API](103_API.md).

## Čtecí nástroje služeb a financování vlastníků

`list_property_service_lifecycles` a `get_property_service_lifecycle` čtou ověřené účetní případy služby. `property_service_reconciliation` porovnává native podklady s hlavním deníkem k výslovnému datu. Jejich znalost je současná a historický `known_at` se nenabízí.

`list_property_fund_operations` čte operace financování vlastníků včetně storen. `property_fund_balances` vrací doložené zůstatky, ledger a rozdíly k datu; volitelný `known_at` omezuje skutečně zaznamenané a zaúčtované podklady. Částky, neúplnost a nepokryté řádky zachovejte. Žádný z těchto nástrojů nepotvrzuje právní titul ani neprovádí účetní nebo platební operaci.
Granty a dary zpřístupňují pouze čtecí nástroje `list_nonprofit_funding`
a `get_nonprofit_funding`. Nástroj `get_nonprofit_funding_evidence` čte
z potvrzeného posouzení J6 ověřitelné podklady jednoho zdroje: přijetí
a rozhodnutí o osvobození (`purpose`), souhrn čerpání (`usage`) a čerpané
náklady (`uses`). Slouží k přípravě potvrzení o daru nebo zprávy
poskytovateli externím nástrojem; stránky procházejte podle `next_cursor`.
Investiční zdroje a fondy zpřístupňují
`list_nonprofit_capital`, `get_nonprofit_capital` a
`list_nonprofit_capital_events`. Zobrazují nativní zaznamenané částky a
číselné vazby bez osobních a právních textů. Zachovávají `null` a výslovné
neúplné pending pokrytí. Nemění účetnictví a nepotvrzují finanční operace.

## 105.9 Související kapitoly

- [REST API](103_API.md) - tokeny, limity a práva
- [Bezpečnost](101_Bezpecnost.md) - passkeys a TOTP při schvalování připojení
- [Sklad](37_Sklad.md)
- [Dimenze](114_Dimenze.md)
- [Řešení problémů](999_Reseni_problemu.md)
