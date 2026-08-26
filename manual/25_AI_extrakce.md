# 25. AI extrakce faktur

[Přijaté](23_Prijate_faktury.md) i vydané faktury lze importovat z PDF pomocí
AI extrakce.
Extrakci provádí jeden ze čtyř podporovaných poskytovatelů AI (Anthropic Claude,
Azure OpenAI, OpenAI nebo Google Gemini) — volbu a přihlašovací údaje nastavíš
v [§ 25.7 Multi-provider AI brána](#257-multi-provider-ai-brana-vyber-poskytovatele).
Tato kapitola dále popisuje **kontrolu výsledků** extrakce a automatiky, které
doklad daňově připraví.

Při AI extrakci z PDF se po importu automaticky spustí **sanity check**: sečtou
se řádky bez DPH a porovnají s celkovým základem daně, který AI přečetla z PDF
„K úhradě". Pokud se hodnoty liší o víc než 2 %, faktura získá flag **„Ke
kontrole"** a uživatel by měl řádky před zaúčtováním ověřit.

### Indikátory v UI

- **Žluté zvýraznění řádku** + ikona ⚠ vedle čísla faktury v seznamu přijatých
  faktur (`/purchase-invoices`).
- **Filtr „Ke kontrole"** v topbaru seznamu — zobrazí jen faktury, kde je flag
  aktivní.
- **Žlutý warning banner** v detailu i editoru faktury s diagnostickým textem
  (např. *„součet řádků bez DPH (XX) je vyšší než AI-vrácený základ daně bez
  DPH (YY) — rozdíl Z %"*).

### Jak zrušit warning

- Tlačítko **Beru na vědomí** v banneru — pošle POST
  `/api/purchase-invoices/{id}/dismiss-extraction-warning` a flag se smaže.
- **Automaticky** při přechodu z draftu na další stav (received / booked /
  paid) — uživatel posunul stav = ověřil data.

### Auto-upgrade modelu

Pokud levnější model (Haiku 4.5) vrátí slabý výsledek (vendor se shoduje s
tenantem nebo součet řádků se výrazně liší od totalu), extractor automaticky
zkusí znovu se silnějším modelem (Sonnet 4.6, ~4× dráž za extract). Pokud máš
Sonnet/Opus jako default, retry se přeskočí.

### Katastrofální mismatch — placeholder

Když ani silnější model nezvládne rozparsovat řádky (typicky komplexní
multi-column servisní faktury) a součet řádků se liší od totalu o víc než
50 %, extractor:

1. Zachová **popisy řádků** z AI extraktu (jsou obvykle správně)
2. Vynuluje jejich **qty a unit_price** (0)
3. Přidá první řádek **KOREKCE** s AI totalem z „K úhradě", aby seděl celkový
   součet faktury

Uživatel pak postupně doplní qty/cenu k jednotlivým řádkům a nakonec smaže
korekční řádek.

### Dodatečná kontrola uložených faktur

CLI skript `php api/bin/recheck-ai-extracted-invoices.php` projde přijaté
faktury s PDF přílohou, re-spustí AI extrakci a porovná AI total s aktuálním
DB totalem. Při rozdílu nad práh (default 2 %) zapíše varování:

```
php api/bin/recheck-ai-extracted-invoices.php                    # dry-run
php api/bin/recheck-ai-extracted-invoices.php --apply            # zápis
php api/bin/recheck-ai-extracted-invoices.php --supplier-id=1
php api/bin/recheck-ai-extracted-invoices.php --threshold=0.05
```

### Dodavatel neplátce DPH

Při AI importu se ověří **plátcovství dodavatele** (ARES/VIES, případně signál
z dokladu „DIČ: Neplátce DPH"). U neplátce se automaticky nastaví **Bez nároku
na odpočet**, vynulují sazby a doplní varování — aby se neoprávněný odpočet
nedostal do přiznání. Detail viz [§ 23.2.4](23_Prijate_faktury.md#2324-danova-uznatelnost-a-narok-na-odpocet).

### Doklad bez jednotkových cen — založení ze souhrnné rekapitulace

Souhrnné doklady za období (typicky měsíční vyúčtování palivových karet) často
**jednotkovou cenu vůbec neuvádějí** — mají jen množství, částku za řádek a dole
daňovou rekapitulaci. Když je navíc doklad uvádí ve dvou variantách, před slevou
a po slevě, dopočítaná jednotková cena vyjde z těch nesprávných čísel a doklad
se rozejde s rekapitulací.

MyÚčto.cz proto takový doklad **neskládá z položek**, ale založí ho ze souhrnné
daňové rekapitulace: **jeden řádek na sazbu DPH** (`1 ks × základ daně`). Základ
i daň tím odpovídají dokladu přesně. Do popisu řádku se přenesou názvy plnění
z dokladu, takže zůstane poznat, o co šlo (PHM, poplatky).

Koncept nese **varování**, že položky nebyly vytěženy — pokud potřebuješ doklad
rozepsaný, doplň řádky ručně. Doklady, které jednotkové ceny uvádějí, se
extrahují **beze změny** i nadále včetně rozpadu na položky.

### Kontrola dat a identifikátorů při extrakci

- **Datum objednávky není datum vystavení.** Extraktor přijme jako datum
  vystavení jen údaj, který je tak na dokladu označený; datum objednávky,
  expedice, tisku nebo přijetí objednávky za něj nezamění.
- **Chybějící splatnost se neodhaduje.** Pokud na dokladu datum splatnosti není,
  výsledek ho nechá neurčené a doplníš ho při kontrole v editoru. Stejně tak se
  nesnaží „opravovat" DUZP jen proto, že předchází datu vystavení.
- **IČO s vedoucí nulou zůstává osmimístné.** Vyhledání i párování dodavatele
  používá normalizovaný osmimístný tvar, takže například `01234567` není
  zaměněno za jiný identifikátor ani uloženo bez úvodní nuly.

### Dodavatel se skupinovou registrací k DPH (dvě DIČ na dokladu)

Doklad odštěpného závodu nebo člena **skupinové registrace k DPH** má v hlavičce
dvě různá čísla: **DIČ** samotného subjektu (typicky `CZ` + IČO) a **DIČ k DPH**
skupiny (typicky ve tvaru `CZ699xxxxxx`). Extrakce je vytěží odděleně:

- podle **DIČ subjektu** se dohledá karta dodavatele v adresáři (proto se karta
  napáruje pořád stejně a nevznikají duplicity),
- podle **DIČ k DPH** se ověří **plátcovství** v registru plátců.

Bez toho by ARES podle IČO vrátil zaniklou registraci (vlastní registrace člena
skupiny vstupem do skupiny zaniká) a doklad by se vytěžil jako od neplátce —
tedy s nulovou daní a bez nároku na odpočet.

### Reverse charge ze zahraničí — automatika

Když extraktor detekuje **reverse charge** (zahraniční dodavatel + všechny řádky
bez DPH), doklad automaticky daňově připraví:

- AI klasifikuje **povahu plnění** (zboží / služba) přímo z dokladu (VIN a vozidlo
  → zboží; SaaS, licence, API → služba).
- Položky dostanou **tuzemskou sazbu 21 %** a klasifikační kód: **23** (zboží
  z EU → ř. 3 + ř. 43, KH A.2), **24** (služba), **25** (zboží ze 3. země).
  Částka k úhradě se nemění — daň zůstává na dokladu nulová, samovyměří se až
  ve výkazech.
- U **pořízení zboží z EU** se dopočítá zákonné **DUZP dle § 25** (15. den
  měsíce po dodání, pokud doklad nebyl vystaven dříve) a k němu se naváže
  **kurz ČNB** — pozdě vystavená faktura tak spadne do správného DPH období.
- Do dokladu se zapíše **informační varování** s rekapitulací, co se nastavilo
  — zkontroluj hlavně zboží vs. služba a případně změň kód (23 ↔ 24).

Detail daňové logiky viz [§ 23.2.7](23_Prijate_faktury.md#2327-reverse-charge-z-eu-porizeni-zbozi-vs-sluzba).

## 25.7 Multi-provider AI brána (výběr poskytovatele)

AI extrakce neběží natvrdo nad jedním modelem — MyÚčto.cz nabízí **AI bránu** se
čtyřmi poskytovateli, mezi kterými si každý dodavatel (tenant) vybere podle toho,
co už používá, kde chce mít API klíč a jaké má požadavky na rezidenci dat:

- **Anthropic Claude** — BYOK (vlastní klíč z `console.anthropic.com`), výchozí
  model `claude-haiku-4-5`; dále `claude-sonnet-5`, `claude-sonnet-4-6`,
  `claude-opus-5`, `claude-opus-4-8`, `claude-opus-4-7` a `claude-fable-5`.
  Výchozí volba, na kterou je AI extrakce v celém manuálu (viz výše) primárně
  odladěná — umí nativně číst PDF jako dokument (ne jen text/obrázek), takže má
  nejlepší přesnost na vícesloupcových a naskenovaných fakturách.
- **Azure OpenAI** — vlastní Azure resource (`endpoint` + `deployment` +
  `api_version`), hodí se, pokud firma už má Azure OpenAI smlouvu nebo
  potřebuje EU rezidenci dat se smluvním zajištěním od Microsoftu.
- **OpenAI** (přímé API) — BYOK klíč z platformy OpenAI, výchozí model
  `gpt-5.4-mini`; dále `gpt-5.6-sol`, `gpt-5.6-terra`, `gpt-5.6-luna`,
  `gpt-5.5`, `gpt-5.4`, `gpt-5.1`, `gpt-5`, `gpt-4.1`, `gpt-4o` a jejich
  `mini` / `nano` varianty. Modely řady `gpt-5` interně „přemýšlejí" — bývají
  proto výrazně pomalejší než ostatní brány (u běžné faktury desítky sekund
  místo jednotek), přesnost je srovnatelná.
- **Google Gemini** — BYOK klíč z Google AI Studio, výchozí model
  `gemini-3.7-flash`; dostupné jsou také `gemini-3.6-flash`,
  `gemini-3.5-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`,
  `gemini-3.1-pro-preview` a `gemini-2.5-pro`.

Nastavení je **per dodavatel** (celá firma/tenant sdílí jednoho aktivního
poskytovatele a jeho přihlašovací údaje, ne po jednotlivých uživatelích).

### 25.7.1 Kde se to nastavuje

Admin otevře **Firma → AI nastavení** (položka menu je viditelná jen adminům;
vede na `/admin/integrations?tab=ai`). Pokud AI přihlašovací údaje ještě nejsou
nastavené, sekce **Nastavení AI extrakční brány** je automaticky rozbalená;
jakmile je aktivní poskytovatel nakonfigurovaný, sbalí se a nahoře zůstane jen
zelené ✓ s jeho jménem (a případně štítek **EU data residency**).

V sekci nastavíš:

1. **Poskytovatele AI** — přepínač se čtyřmi tlačítky (Anthropic / Azure OpenAI
   / OpenAI / Gemini), zelené ✓ u tlačítka znamená, že ten poskytovatel má už
   uložené přihlašovací údaje. Klik na tlačítko jen přepne, které přihlašovací
   údaje a model se dole zobrazí/upravují — **neuloží** to ještě aktivní volbu.
2. **Vynutit EU rezidenci dat** (checkbox) a **Region dat** (EU/US) — viz
   [§ 25.7.2](#2572-eu-rezidence-dat-co-to-znamena-a-jak-se-vynucuje).
3. Tlačítko **Uložit nastavení brány** — teprve tím se aktivní poskytovatel a
   EU volba zapíší k dodavateli a od té chvíle je používá i "Import přijatých"
   / drag&drop PDF na této stránce i AI import v [Přijatých fakturách](23_Prijate_faktury.md).

Pod tím je formulář **Přihlašovací údaje — {poskytovatel}**:

- **Anthropic / OpenAI / Gemini** — jen pole **API klíč** (BYOK, write-only —
  po uložení se nikdy nezobrazí zpátky, jen placeholder "uloženo") a volitelně
  **Model** (výběr z povoleného whitelistu daného poskytovatele; prázdné =
  použije se výchozí model poskytovatele).
- **Azure OpenAI** — navíc **Azure endpoint**, **Deployment** (název nasazeného
  modelu v Azure resource) a **API verze**.
- Tlačítko **Test připojení** ověří klíč/endpoint reálným voláním a nahlásí,
  jaký model odpověděl (nebo chybu). Před uložením se kontroluje i základní
  formát klíče (Anthropic musí začínat `sk-ant-`, OpenAI `sk-`; Gemini přijímá
  podporované standardní i autorizační klíče z AI Studio) — ušetří to zbytečný
  test s očividně špatně vloženým klíčem.
- Tlačítko **koš** u nastaveného poskytovatele smaže jeho uložené přihlašovací
  údaje (po potvrzení) — pokud byl zrovna aktivní, extrakce přestane fungovat,
  dokud nenastavíš jiného poskytovatele nebo klíč nevložíš znovu.

U nakonfigurovaného poskytovatele vidíš i **počet dosud provedených extrakcí**
(počítadlo per poskytovatel, nezávislé na tom, jestli je zrovna aktivní) a jeho
**štítek rezidence** (např. *„EU (Azure OpenAI)"*).

> [!NOTE]
> Pokud brána ještě není pro dodavatele vůbec nakonfigurovaná a uživatel se
> pokusí o AI import v [Import přijatých](21_Importy.md#2113-import-prijatych-faktur), zobrazí se
> upozornění s odkazem přímo sem („→ AI nastavení").

### 25.7.2 EU rezidence dat — co to znamená a jak se vynucuje

Checkbox **Vynutit EU rezidenci dat** říká systému: *„tento dodavatel smí AI
extrakci posílat jen na servery fyzicky v EU, nikdy do USA."* Hodí se pro firmy
se zpřísněnými požadavky na GDPR/rezidenci dat (např. veřejná správa, citlivější
obory, interní compliance politika).

Ne každý poskytovatel to ale umí stejně:

| Poskytovatel | EU-schopný? | Jak se region určí |
|---|---|---|
| **Azure OpenAI** | Ano | Podle hostname Azure endpointu — pokud obsahuje token EU regionu (`westeurope`, `swedencentral`, `germanywestcentral`, `northeurope`, `francecentral`, `norwayeast`, `switzerlandnorth`, `polandcentral`, `italynorth`, `spaincentral`, `uksouth`, `ukwest`…), region = EU. Jinak platí deklarovaný region dodavatele, ale jen pro **ověřený Azure host** — neplatný/cizí host padá fail-closed na US. |
| **OpenAI** | Ano | Jen když je **Base URL** nastaveno přesně na `https://eu.api.openai.com` (OpenAI project data residency). Cokoli jiného (včetně prázdného pole = výchozí `api.openai.com`) = US. |
| **Anthropic Claude** | Ne | Přímé API nabízí jen US region. |
| **Google Gemini** | Ne | Přímá integrace přes AI Studio používá US; regionální endpoint Vertex AI není součástí této integrace. |

Pokud zaškrtneš **Vynutit EU rezidenci dat** u poskytovatele, který EU neumí
(Anthropic, Gemini, nebo Azure/OpenAI se špatně nastaveným endpointem), tlačítko
poskytovatele se v přepínači **znepřístupní** a pod přepínačem se zobrazí
červené upozornění *„Vybraný poskytovatel/konfigurace nepodporuje EU
rezidenci…"*. Volba **Region dat: US** je navíc v selectu zamčená, dokud je
vynucení EU zapnuté.

> [!WARNING]
> Tohle není jen kosmetika ve formuláři. Server vynucuje stejné pravidlo
> **fail-closed** i nezávisle na UI — každé volání extrakce (i test připojení,
> i automatický upgrade na silnější model) si znovu ověří skutečný region
> podle konfigurace poskytovatele. Pokud by se dostal EU-required dodavatel
> přesto na non-EU endpoint, volání skončí chybou `residency_conflict`,
> **žádná data se neodešlou** a žádný cross-provider ani cross-tenant fallback
> se nekoná — buď proběhne extrakce ve správném regionu, nebo neproběhne vůbec.

### 25.7.3 Výběr modelu a chování shodné napříč poskytovateli

Ať zvolíš kteréhokoli poskytovatele, chování zbytku extrakce zůstává stejné —
funkce popsané výše v této kapitole (sanity check, flag „Ke kontrole", auto-
upgrade modelu, katastrofální mismatch/placeholder, kontrola plátcovství DPH,
reverse charge automatika) fungují nad výsledkem **libovolného** aktivního
poskytovatele, ne jen nad Anthropic:

- **Výchozí model** (pole *Model* ve formuláři přihlašovacích údajů) se použije,
  pokud u konkrétního importu nezvolíš jiný. Whitelist modelů je uzavřený —
  nelze zapsat libovolný řetězec, jen jeden z nabízených.
- Auto-upgrade z [„Auto-upgrade modelu"](#auto-upgrade-modelu) platí analogicky
  u všech poskytovatelů a jde vždy o **jeden stupeň nahoru** po žebříčku:
  Anthropic `haiku → sonnet → opus → fable`, OpenAI/Azure
  `nano → mini → plný model`, Gemini `lite → flash → pro`. Stupeň, který nemá
  ve whitelistu žádný model, se přeskočí. Upgrade **nikdy nepřeskočí do jiného
  regionu** — zůstává u stejného poskytovatele a stejné rezidence dat.
- Výsledek extrakce nese **provenance badge** — u naimportované faktury vidíš,
  který poskytovatel a jaký region data zpracoval (užitečné pro audit/kontrolu,
  zvlášť když je zapnuté vynucení EU rezidence).
- Maximální velikost PDF k extrakci se liší poskytovatel od poskytovatele
  (Anthropic 32 MB, ostatní 20 MB) — u větších souborů extrakce vrátí chybu.

### 25.7.4 Ladění extrakce — poznámky a míra uvažování

Pod formulářem přihlašovacích údajů jsou dvě volby, které platí pro **celého
dodavatele napříč poskytovateli** — nepřenastavují se při přepnutí brány:

**Míra uvažování AI** rozhoduje, kolik práce si model dá, než odpoví:

| Volba | Co dělá |
|---|---|
| **Výchozí (podle modelu)** | Neposílá poskytovateli nic navíc — chování před zavedením volby. Doporučené. |
| **Rychle a levně** | Zkrátí uvažování. Nižší cena a latence, u složitých faktur na úkor přesnosti. |
| **Přesně (víc uvažování)** | Nechá model uvažovat déle. Vyšší přesnost na komplikovaných dokladech, ale pomalejší a dražší. |

Ne každý model to umí — `claude-haiku-4-5` a modely řady `gpt-4` volbu nemají
a prostě ji ignorují (extrakce běží dál, jen bez efektu). U Azure OpenAI se
volba neuplatní vůbec, protože pod deploymentem může být libovolný model.

**Poznámky k extrakci** jsou volný text (max 2000 znaků), který se připojí
k zadání pro AI. Patří sem to, co model nemá odkud vědět o *vašich* fakturách:

```
Dodavatel ACME píše variabilní symbol do pole "Reference", ne do VS.
Faktury z Irska jsou vždy bez DPH (reverse charge).
U Vodafonu ber částku z řádku "Celkem k úhradě", ne z rekapitulace.
```

Text jde do zadání jako **doplňující kontext, ne jako pravidlo** — nikdy
nepřebije JSON schéma ani kontrolní logiku popsanou výše v této kapitole. Když
si poznámka odporuje s pravidly extrakce, platí pravidla. Delší text se ořízne
na 2000 znaků.

> [!TIP]
> Poznámky piš konkrétně a k jednomu dodavateli, ne obecně. „Buď přesnější"
> modelu nepomůže; „faktury od ACME mají datum plnění v pravém horním rohu" ano.
> Když se stejná chyba opakuje u jednoho dodavatele, je to přesně případ pro
> poznámku.

Obojí se ukládá tlačítkem **Uložit ladění** (je aktivní jen při skutečné změně)
a projeví se okamžitě na další extrakci.

### 25.7.5 Omezení a tipy

- Aktivní poskytovatel je nastavení **celého dodavatele**, ne uživatele — změna
  se projeví pro všechny uživatele firmy okamžitě po uložení.
- API klíč je **write-only**: jakmile ho jednou uložíš, systém ho už nikdy
  nezobrazí zpět (ani adminovi) — jen potvrdí, že je nastavený. Pro změnu klíče
  ho zadej znovu celý, prázdné pole = zachovat stávající.
- Než přepneš poskytovatele naostro, použij **Test připojení** — ověří klíč
  i to, že vrácený model odpovídá whitelistu.
- Privacy: obsah nahraného PDF (položky, IČ/DIČ dodavatele, vlastní data) jde
  přes HTTPS na servery zvoleného poskytovatele. Pro obzvlášť citlivé doklady
  zvaž [ISDOC import](21_Importy.md#2113-import-prijatych-faktur) (data zůstanou lokálně, AI se
  vůbec nevolá).

> [!TIP]
> Nejsi si jistý, kterého poskytovatele zvolit? Anthropic Claude je výchozí a
> nejodladěnější volba (nativní čtení PDF, nejlepší přesnost na komplexních
> fakturách). Azure OpenAI zvol, pokud firma potřebuje EU rezidenci dat se
> smluvním zajištěním nebo už Azure OpenAI používá pro jiné účely.

## 25.8 AI import vydaných faktur

**Cesta: `Prodej → AI import`**. Položku vidí uživatel, který smí vytvářet
vydané faktury.

Stránka přijímá PDF, obrázek, ISDOC nebo ISDOCX a vždy vytváří jen **koncept
vydané faktury**. Pokud soubor obsahuje platný ISDOC, systém použije jeho
strukturovaná data a AI vůbec nevolá. Teprve když strukturovaná data chybí,
použije aktivního poskytovatele a model z [§ 25.7](#257-multi-provider-ai-brana-vyber-poskytovatele).

Z jednoho souboru systém vytěží odběratele, data dokladu, měnu, platební údaje
a položky. Odběratele vyhledá nebo založí, vytvoří koncept stejnou interní
cestou jako ruční editor a přepočítá jeho součty. Číslo z dokladu převezme jen
tehdy, pokud v aktuální firmě nekoliduje; jinak dostane koncept nové číslo až
při vystavení. U ISDOC navíc ověří, že dodavatelem je aktuálně zvolená firma.
Existující ISDOC se stejným variabilním symbolem vrátí jako duplicita místo
založení druhé faktury.

Po úspěchu klikni na odkaz do editoru a ověř zejména odběratele, typ dokladu,
DUZP, splatnost, režim cen s/bez DPH, sazby a text položek. Import sám fakturu
nevystaví, neodešle a nezaúčtuje.

Při přetažení více souborů vznikne dávka. Zpracovává se **postupně po jednom**,
aby nepřetížila limit poskytovatele; u každého řádku je samostatný výsledek
a odkaz na vytvořený koncept. Pro jednotlivý import i dávku lze dočasně vybrat
jiný model z whitelistu aktivního poskytovatele. Maximální velikost jednoho
uploadu je 32 MiB; konkrétní poskytovatel může mít nižší limit uvedený
v [§ 25.7.3](#2573-vyber-modelu-a-chovani-shodne-napric-poskytovateli).

Výsledek nese zdroj (`ISDOC`, `AI` nebo duplicita), poskytovatele, model, region
a případně spotřebu tokenů. Tyto údaje se spolu s názvem a velikostí souboru
zapisují do activity logu; samotné přihlašovací údaje ani obsah dokladu se do
něj nezapisují.
