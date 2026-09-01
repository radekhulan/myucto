# 23. Přijaté faktury (nákupy)

**Přijaté faktury** jsou doklady, které **dostáváš od svých dodavatelů** — peníze
odcházejí z firmy. Oproti vystaveným fakturám:

| | Vystavené (faktura) | Přijaté (purchase invoice) |
|---|---|---|
| Směr peněz | Klient → my (příjem) | My → dodavatel (výdaj) |
| Protistrana | Zákazník (`is_customer=1`) | Dodavatel (`is_vendor=1`) — stejná tabulka klientů, jiný flag |
| DPH role | Sbíráme od klientů (výstupní DPH) | Odečítáme z dodavatelských (vstupní DPH) |
| Číslování | Naše `2605001` | Číslo dodavatele (na originálu) + naše interní `PF2605001` (dle daňového typu, viz § 23.2.4) |
| Status flow | draft → issued → sent → paid | draft → received → booked → paid |
| Schvalování / odesílání | Ano, klient potvrdí | Ne, doklad jen evidujeme |

V hlavním menu **Přijaté faktury**.

> [!TIP]
> Samostatné kapitoly k nákupní agendě: [Export přijatých faktur](24_Export_prijatych.md)
> (naše PDF / ISDOC / Pohoda) a [AI extrakce](25_AI_extrakce.md) (import z PDF přes nastaveného poskytovatele AI).

## 23.1 Stavy přijaté faktury

| Stav | Význam | Co lze |
|---|---|---|
| **Koncept** (draft) | Rozpracovaný — ještě jsi nepotvrdil že to je platná faktura | Upravit, smazat, přejít na Přijatá |
| **Přijatá** (received) | Doklad potvrzený jako platný — visí na nezaplacených | Označit jako zaúčtovaná, uhrazená, stornovat |
| **Zaúčtovaná** (booked) | Předala se účetní / poslala do účetnictví | Označit jako uhrazená, stornovat |
| **Uhrazená** (paid) | Zaplaceno (manuálně nebo automaticky z bankovního výpisu) | — (terminal) |
| **Stornovaná** (cancelled) | Stornovaný doklad — necháváme pro audit | — (terminal) |

Smazat jde **jen koncept**. Pro pozdější stavy použij Stornovat (zachová auditní stopu).

### 23.1.1 Příchozí doklady

Originály čekají na zpracování v **Nákup → Příchozí doklady**. Nejsou to koncepty
přijatých faktur: do okamžiku kontroly nevstupují do nákladů, závazků, cashflow ani
evidence DPH. Účetní má u originálu náhled a může jej zpracovat přes ISDOC/AI,
přepsat ručně, odmítnout nebo klienta požádat o náhradu.

Do fronty vede několik cest:

| Cesta | Kdo ji použije |
| --- | --- |
| **Portál → Doklady pro účetní** — dávka až 20 souborů | klient, spontánně |
| Nahrání dokladu k **vyžádanému požadavku** | klient, jako odpověď účetní |
| **Uložit a předat účetní** v editoru přijaté faktury | klient, když se doklad nevytěžil sám |
| **Nahrát do fronty** přímo na stránce Příchozí doklady | účetní u dokladů, které přišly mimo portál (e-mailem, papírově) |

Účetní tak nemusí čekat na klienta: co dostane e-mailem nebo naskenuje, vloží do
fronty sama a zpracuje to stejným postupem. Podrobný průchod klientskou stranou je v
[§ 9.8 Klientský portál](09_Klientsky_portal.md#98-vyzadane-doklady-od-klienta).

> **Odmítnutí originál nemaže.** Přepne podání do stavu Odmítnuto a napíše klientovi
> důvod; samotný soubor zůstává v Dokumentech i v auditní stopě. Uklidit frontu i
> s originálem (omylem nahraná fotka, spam) jde tlačítkem **Smazat z fronty** — má
> vlastní oprávnění *Trvale vyřadit z příchozí fronty* a originál posílá do koše
> Dokumentů, odkud ho z disku odstraní až vysypání koše. U dokladu, který si účetní
> nahrála sama, se zpráva klientovi nevyžaduje — není komu ji psát.

Po zpracování se neměnný originál z Dokumentů připojí k výsledné faktuře. Faktura
zůstává pro klienta needitovatelná i ve stavu Koncept; účetní ji může dál opravit
a dokončit běžným stavovým postupem. Pokud účetní výsledný koncept smaže, původní
podání se bezpečně vrátí do příchozí fronty k novému zpracování.

> ⚠️ **Zaúčtování bez DUZP nejde.** Přechod Přijatá → Zaúčtovaná je zablokovaný, dokud
> doklad nemá vyplněné DUZP (datum uskutečnění zdanitelného plnění) — bez něj by se
> doklad dostal do podkladů DPH s nejistým obdobím. Blok platí jen pro NOVÝ přechod;
> doklady zaúčtované ještě před touto kontrolou (typicky z migrace historie) zůstávají
> beze změny a jdou dál normálně uhradit nebo stornovat.

V seznamu přijatých faktur je filtr **Neuhrazené k datu** — na rozdíl od checkboxu
„Nezaplacené" (dnešní stav) ukáže doklady vystavené do zvoleného dne, u kterých k
tomu dni nebyl uhrazen celý závazek. Doklad zaplacený až po tomto dni se proto ve
výpisu objeví, i když má dnes stav „Uhrazená". Stejná funkce a definice úhrady je
i u [vystavených faktur](14_Faktury.md#1411-filtry-vlevo) a sedí na
[Saldokonto](53_Saldokonto.md).

## 23.2 Nová přijatá faktura

V seznamu klikni **+ Nová přijatá faktura**. Otevře se formulář.

### 23.2.1 Drag & drop dokladu
Nad formulářem je **drag & drop zóna** pro PDF, fotku, ISDOC nebo ISDOCX:

- Samostatný **ISDOC**, **ISDOCX** nebo PDF/A-3 s **vloženým ISDOC** se naparsuje
  deterministicky bez AI. Systém ověří IČO odběratele, vyhledá nebo založí
  dodavatele, vytvoří předvyplněný koncept včetně položek, DPH a platebních údajů
  a rovnou ho otevře v editoru ke kontrole. Tato cesta je dostupná i klientské roli
  s oprávněním vytvářet přijaté faktury.
- Běžné PDF bez vloženého ISDOC nebo fotka se pouze připraví jako příloha. Pole
  vyplníš ručně a originál se po prvním uložení automaticky **archivuje** mimo
  webroot. Pro nestrukturované PDF lze podle oprávnění použít také
  [AI extrakci](25_AI_extrakce.md). Klient s právem předávat doklady může místo
  opisování kliknout **Uložit a předat účetní**: stejný originál se jedním krokem
  uloží do podatelny mimo účetnictví a objeví se účetní v Příchozích dokladech.
- **Strojově čitelný originál (ISDOC/ISDOCX) se uchová jako důkazní stopa.** U faktur importovaných ze strukturovaného zdroje (`.isdoc`, `.isdocx`, nebo ISDOC vložený v PDF/A-3) se vedle vizuálního PDF trvale archivuje i **původní strojový doklad**. Pro audit a kontrolu z finančního úřadu má při 10leté archivační lhůtě vyšší hodnotu než PDF render a umožňuje zpětnou rekonstrukci dat. V detailu faktury ho stáhneš přes badge **„ISDOC"** v hlavičce nebo akci **Zdrojový doklad** v menu. Bajty se ukládají tak, jak přišly — `.isdocx` se nerozbaluje (zachová podpis obálky), embedded ISDOC se uloží jako vytažené XML a originál se nikdy nepřepíše.

> 💡 ISDOC/ISDOCX se importuje při **zakládání nové faktury**. Dropzone na detailu
> nebo při editaci existující faktury slouží jen k doplnění PDF či fotografie; nový
> strukturovaný doklad by tam nečekaně založil druhou fakturu. Fotku
> (JPG/PNG/WEBP/HEIC) systém před archivací převede na PDF. Z `.isdocx` se vedle
> strojového originálu archivuje i zabalené PDF pro náhled.

> 👁️ **Náhled originálu je u konceptu s daty vytěženými AI otevřený automaticky.** U konceptu
> založeného ze strukturovaného zdroje (ISDOC/ISDOCX) zůstává náhled sbalený — data
> jsou tam přesná, ověřovat proti originálu není potřeba. U konceptu vytěženého AI
> z prostého PDF (nebo fotky) editor náhled otevře rovnou, ať máš originál na očích při
> kontrole vytěžených hodnot (sazba DPH, plátcovství dodavatele apod.). Jakmile doklad
> jednou potvrdíš (přejde z konceptu dál), náhled se při dalším otevření editoru
> nevnucuje — panel si můžeš kdykoli sbalit/otevřít tlačítkem nad ním.

Limity:
- Max 20 MiB per soubor
- Akceptujeme PDF, fotku (JPG/PNG/WEBP/HEIC) a ISDOC/ISDOCX (magic bytes se ověřují server-side)
- SHA-256 a dokladová deduplikace — při opakovaném importu se otevře existující doklad

> 💰 **Zaokrouhlení „k úhradě".** U dokladů se zaokrouhlením na celé koruny (typicky e‑faktury z e‑shopů) systém převezme zaokrouhlení přímo z dokladu — částka **K úhradě** pak sedí na haléř se skutečnou částkou na faktuře (a přesně se spáruje s platbou v bance). Základ a DPH zůstávají nezměněné (správně pro přiznání DPH a kontrolní hlášení); zaokrouhlení se vede jako samostatná položka a promítne se do „k úhradě", QR platby, platebního příkazu i vygenerovaného PDF (rekonstrukce „Náš PDF" zobrazí řádek *Zaokrouhlení*).

### 23.2.2 Povinná pole

| Pole | Význam |
|---|---|
| **Dodavatel** | Vyber z dropdownu (autocomplete). Pokud chybí, klikni „+ Vytvořit nového dodavatele" — využije ARES lookup podle IČO. |
| **Číslo dokladu dodavatele** | Tak jak je vytištěno na originálu (např. `FA-2026-001`). Max 50 znaků. Unique per (dodavatel, datum vystavení) — nelze importovat 2× stejnou. |
| **Naše interní číslo** | Volitelné. Pokud necháš prázdné, vygeneruje se automaticky podle **šablony** při přechodu na stav Přijatá. Výchozí šablona je `{PP}{YY}{MM}{CCC}` (např. `PF2602001`), prefix `{PP}` odpovídá daňovému typu (viz § 23.2.4): **PF/PN** plný nárok (uznatelný/ne), **KU/KN** krácený §75, **KR/RN** krácený §76, **NU/NN** bez nároku. Počítadlo je per měsíc (přeteče na 4+ místa nad 999 dokladů). Šablonu lze změnit v **Nastavení → Číslování faktur → Šablona pro přijatou fakturu** (např. `PF-{YYYY}{MM}-{CCCC}` → `PF-202605-0001`). Při ručním zadání čísla systém hlídá kolize (nepovolí duplicitu) a auto-generátor obsazená čísla přeskakuje. |
| **Typ dokladu** | Faktura / Doklad o úhradě / Dobropis / Záloha (pro filtrování v seznamu). |
| **Datum vystavení** | Z faktury. |
| **DUZP (datum uskutečnění zdanitelného plnění)** | Klíčové pro DPH období. Default = datum vystavení. U **reverse charge** se doklad zařazuje do DPH období právě podle DUZP (povinnost přiznat daň vzniká bez ohledu na doručení dokladu); u **pořízení zboží z EU** je DUZP dle § 25 ZDPH **15. den měsíce následujícího po dodání**, pokud doklad nebyl vystaven dříve — editor to připomene hintem. |
| **Splatnost** | Z platebních podmínek dodavatele. |
| **Datum přijetí** | Kdy jsi to fyzicky / e-mailem dostal. Default = dnes. |
| **Měna faktury** | Měna, ve které je doklad vystaven (USD, EUR, CZK…). |
| **Kurz k DUZP** | Pokud je měna ≠ CZK, **musíš zafixovat kurz**. Tlačítko „Načíst z ČNB" stáhne denní kurz k rozhodnému dni dokladu. Korunový doklad kurz nemá — když měnu přepneš na CZK, kurz i jeho datum se vyprázdní. Viz [§ 23.2.9](#2329-kurz-cizi-meny-a-jeho-prenacitani). |
| **Reverse charge** | Zaškrtni, pokud je doklad B2B s přenesenou daňovou povinností (pořízení zboží z EU, služby z EU/3. země, tuzemský §92a). Položkám nastav **tuzemskou sazbu** (typicky 21 %) a odpovídající klasifikační kód — daň na dokladu zůstane 0 (dodavatel ji neúčtuje), samovyměření i zrcadlový odpočet dopočítají výkazy DPH. Viz [§ 23.2.7](#2327-reverse-charge-z-eu-porizeni-zbozi-vs-sluzba). |

> [!NOTE]
> **Datum přijetí a období odpočtu DPH.** U ručně založené (tzn. **ne** importované)
> tuzemské přijaté faktury se datum přijetí počítá i do určení **období, ve kterém
> uplatníš nárok na odpočet DPH** (§ 73 odst. 1 písm. a ZDPH — nárok nelze uplatnit
> dřív, než doklad fyzicky držíš). Období odpočtu je pozdější z trojice **DUZP / datum
> vystavení / datum přijetí**. Typický případ: dodavatel pošle doklad s prosincovým
> DUZP až v lednu — pokud datum přijetí ručně nastavíš na leden, faktura spadne do
> lednové [Knihy DPH](37_Kniha_DPH.md) i přiznání, ne do prosincové. U faktur
> **importovaných** (AI extrakce, ISDOC, iDoklad/Fakturoid, bankovní avízo, scan
> inboxu) se datum přijetí do tohoto výpočtu nepočítá — import ho plní datem
> zpracování, ne skutečným datem přijetí, takže by zařazení jen zkreslilo.

### 23.2.3 Položky

Tlačítkem **+ Přidat položku** přidej řádek. Per řádek:

- Popis
- Množství (např. 1)
- Měrná jednotka (ks / hod / kus…)
- Cena za MJ bez DPH
- Sazba DPH (z číselníku — 21 % / 12 % / 0 %)
- (volitelně) MFČR DPH klasifikační kód — pro výkazy DPH (sekce Daně, auto-default podle sazby)

Souhrn dole se přepočítá automaticky po každé změně.

#### Druh výdaje po jednotlivých řádcích

U každé položky lze samostatně určit **Druh výdaje**. Tato volba popisuje, co
bylo pořízeno, a proto se nevyplňuje jen jednou za celou fakturu:

| Druh | Typické použití | Výchozí účetní směr |
|---|---|---|
| **Služba** | nájem, telefon, poradenství, pojištění | obvykle 518; konkrétní pravidlo může určit jiný účet |
| **Materiál** | spotřební materiál a zboží do spotřeby | obvykle 501 |
| **Drobný majetek** | samostatně evidovaný drobný majetek | obvykle 501 a evidence karty |
| **Dlouhodobý majetek** | pořízení určené k zařazení a odpisování | obvykle 042 |

Doklad tak může mít například jeden řádek jako službu a druhý jako dlouhodobý
majetek. Druh výdaje je osa **co položka je**; výsledný nákladový nebo majetkový
účet je osa **kam se účtuje**. Proto například pojistné zůstává druhem Služba,
ale pravidlo může navrhnout účet 548 místo obecného 518. Výslednou kontaci vždy
zkontroluj při zaúčtování dokladu nebo v Automatu.

U uložených řádků může systém zobrazit návrh se zdrojem, jistotou a stručným
důvodem. Návrh se do řádku zapíše až po kliknutí na **Použít**; nejasný nebo
rozporný návrh se sám neaplikuje. Ruční volba má přednost a u dobropisu se
zachová věcný druh původního výdaje — při zaúčtování se pouze obrátí strany.

> 💡 **Ceny „s DPH" (brutto režim)** — přepínačem **Ceny zadávám
> s DPH** (u DPH v hlavičce) lze zadat položky **včetně DPH** (typicky účtenka /
> paragon), takže celková částka sedí na haléř. DPH se pak počítá „shora"
> koeficientovou metodou (§37 ZDP). Zadání ceny do sloupce „Celkem s DPH" respektuje
> aktuální režim (nepřepíná ho); jednotková cena se v detailu i PDF zobrazuje jako
> netto. Funguje stejně jako u vystavených faktur — viz
> [§ 15.2.6](15_Faktura_editor.md#1526-ceny-s-dph-vs-bez-dph-brutto-netto-rezim).
> AI import účtenek režim nastaví sám.

### 23.2.4 Daňová uznatelnost a nárok na odpočet

V boxu **Klasifikace** jsou dva nezávislé příznaky řídící, jak faktura vstupuje do daňových výkazů:

| Příznak | Možnosti | Co ovlivňuje |
|---|---|---|
| **Nárok na odpočet DPH** | Plný / Bez nároku / Krácený §75 / Krácený §76 | DPH evidenci |
| **Daňově uznatelný náklad** | ano / ne | daň z příjmů (DPFO/DPPO) |

- **Nárok na odpočet DPH:**
  - **Plný** (výchozí) — standardní odpočet, faktura jde do Knihy DPH, DPHDP3 (ř. 40–45) i Kontrolního hlášení.
  - **Bez nároku** — faktura **vůbec nevstupuje** do DPH evidence (Kniha DPH, DPHDP3, KH); je to jen účetní náklad. Typicky reprezentace, osobní spotřeba.
  - **Krácený (poměrný §75)** — odpočet jen v poměrné výši (např. auto 70 % pro ekonomickou činnost). Po výběru zadáš **Odpočet %** a o toto procento se zkrátí základ i daň odpočtu v Knize DPH a DPHDP3 (ř. 40–45); zbytek je nedaňová část.
  - **Krácený (koeficientem §76)** — pro **společné vstupy** používané zároveň pro plnění s nárokem na odpočet i pro plnění osvobozená bez nároku (§ 51) — typicky nájem, energie, účetní služby u firem, které mají i osvobozené příjmy (pronájem, finanční nebo zdravotní služby). Na rozdíl od §75 se procento **nezadává na dokladu** — je to jeden **koeficient za celou firmu a rok**, spočtený z poměru zdanitelných a osvobozených plnění (podrobně viz [Výkazy DPH § Krácený odpočet § 76](36_Vykazy_DPH.md#kraceny-odpocet-76-koeficient)). Než administrátor/účetní pro daný rok nastaví **zálohový koeficient**, doklad s touto volbou nejde **ani zaúčtovat, ani zahrnout do přiznání** — systém to odmítne srozumitelnou chybou.
- **Daňově uznatelný náklad** — řídí pouze daň z příjmů. V podvojném účetnictví se
  náklad vždy normálně projeví ve výsledku hospodaření; když je příznak vypnutý,
  DPFO/DPPO jej přičte zpět jako nedaňový. Při automatickém zaúčtování aplikace
  zachová věcný druh nákladu a použije jeho nedaňovou analytiku: `501.990`,
  `511.990`, `518.990` nebo `548.990`. Konkrétní pravidlo či ruční účet má přednost,
  takže reprezentace zůstane na `513`, sociální náklad na `528` a dar na `543`.
  S DPH to nesouvisí (faktura může mít odpočitatelné DPH a být daňově
  neuznatelná, i naopak).

Oba příznaky jsou vidět i v **detailu** přijaté faktury (box Měna/DPH).

> [!NOTE]
> **Zaúčtování i u „Bez nároku", „Krácený (§75)" a „Krácený (§76)".** Zaúčtování
> přijaté faktury do [Účetního deníku](45_Ucetni_denik.md) umí zpracovat i doklady
> s nárokem **Bez nároku** (celá částka včetně DPH jde na nákladový účet, žádné 343) a
> **Krácený (§75)** (na účet 343 jde jen poměrná uplatněná část DPH, zbytek DPH jde
> spolu se základem do nákladu). U **Krácený (§76)** se do deníku zaúčtuje **celá** DPH
> na účet 343 — stejně jako u plného nároku — protože krácení koeficientem se
> jednotlivého zápisu netýká, řeší se souhrnně až v přiznání DPH (ř. 52/53, viz
> [Výkazy DPH](36_Vykazy_DPH.md#kraceny-odpocet-76-koeficient)). Kombinace
> **reverse charge** se **současně** omezeným nárokem (Bez nároku/Krácený §75/Krácený
> §76) se automaticky nezaúčtuje — takový doklad je nutné zaúčtovat ručním zápisem
> v deníku.

> 💡 **Interní číslo se řídí daňovým typem.** Prefix automaticky generovaného
> interního čísla (viz § 23.2.2) odpovídá těmto dvěma příznakům — **PF/PN** plný
> nárok (uznatelný/ne), **KU/KN** krácený §75, **KR/RN** krácený §76, **NU/NN** bez
> nároku. Když u už
> očíslované faktury daňové uplatnění **změníš**, přepíše se jen **prefix**
> (`PF2602001` → `NN2602001`); číselná řada `{YYMM}{CCC}` i ručně zadaná čísla
> zůstanou. Počítadlo je **sdílené per dodavatel a měsíc napříč všemi prefixy**,
> takže čísla jsou v rámci měsíce souvislá bez ohledu na daňový typ; případné
> mezery po smazaných konceptech jsou u interního označení neškodné (na rozdíl
> od vystavených faktur se u přijatých dokladů souvislá řada nevyžaduje).

#### Rekapitulace DPH dle dokladu (§ 73 ZDPH)

Pod položkami je box **Rekapitulace DPH** se základem a daní **za každou sazbu**. Hodnoty se dopočítají ze řádků, ale pokud doklad dodavatele uvádí kvůli zaokrouhlení jiný **základ** nebo **DPH**, můžeš je **přepsat** přesně podle dokladu. Důvod je daňový: nárok na odpočet je svázaný s **částkou daně uvedenou na dokladu** (§ 73 odst. 6 ZDPH), proto je primární shoda s dokladem, ne náš přepočet.

- Přepsat lze základ i DPH, samostatně pro každou sazbu. Ručně upravené pole je zvýrazněné; odkaz **Spočítat automaticky** vrátí vypočtenou hodnotu.
- Override se uloží do faktury a promítne se konzistentně do **DPH přiznání, kontrolního hlášení, knihy DPH** i do **daně z příjmů** a daňového optimalizátoru.
- Při **AI importu** se rekapitulace předvyplní automaticky dle dokladu (pro jednu i více sazeb), pokud sedí v toleranci.
- Box se nezobrazuje u **reverse-charge** (na dokladu zahraničního dodavatele není česká DPH).

##### Účetní alokace části dokladu

Pokud jedna faktura obsahuje podnikatelskou i přesně oddělitelnou osobní nebo
nedaňovou část, klikni v rekapitulaci na **Rozdělit odpočet a zaúčtování**.
Importované položky ani částky dodavatele se tím nemění. Pro každou sazbu vznikne
podnikatelský řádek a lze přidat další alokaci, například:

- podnikatelská část — plný odpočet, účet 518,
- osobní spotřeba společníka — bez nároku na odpočet, účet 355,
- osobní spotřeba zaměstnance — bez nároku na odpočet, účet 335,
- firemní nedaňový náklad — bez nároku, příslušný nákladový účet.

U osobní části zadej známou částku **Celkem s DPH**. Základ a DPH se rozdělí
podle rekapitulace sazby a podnikatelský řádek se dopočítá jako zbytek. Součet
alokací musí přesně odpovídat rekapitulaci každé sazby; jinak doklad nelze uložit.
Samostatně oddělená osobní položka není poměrný odpočet podle § 75: do DPH
evidence vstoupí jen podnikatelská alokace a příznak „Použit poměr“ zůstane **NE**.

Při zaúčtování systém vytvoří jeden závazek 321 za celý doklad, ale jednotlivé
alokace rozdělí na zvolené účty. Například kombinace podnikatelské služby a osobní
spotřeby společníka se zaúčtuje jako `518 + 343 + 355 / 321`. Účetní alokace
nejsou dostupné u reverse charge dokladů.

> [!IMPORTANT]
> **Druh výdaje a alokace DPH jsou dvě různé věci.** Druh se volí na položce
> podle toho, co bylo nakoupeno. Alokace rozděluje částku jedné sazby mezi
> podnikatelské, osobní a nedaňové použití a současně určuje nárok na odpočet.
> U více sazeb musí sedět každá sazba samostatně; systém nedovolí uložit rozpad,
> jehož součet se liší od rekapitulace dodavatele.

#### Dodavatel neplátce DPH → bez nároku na odpočet

Pokud je dodavatel **neplátce DPH**, na jeho dokladu žádná DPH není a **není co
odpočítat** — uplatnit odpočet by byla daňová chyba (neoprávněný odpočet v ř. 40
přiznání / sekci B kontrolního hlášení). MyÚčto proto plátcovství dodavatele
**sleduje a vynucuje**:

- **Zjištění plátcovství** — autoritativně z **ARES** podle IČO (CZ), u
  zahraničních EU subjektů z **VIES** podle DIČ. Ověří se online při výběru /
  editaci dodavatele ve formuláři (výsledek se cachuje 24 h).
- **Volba v editoru** — pod checkboxem „Reverse charge" je přepínač **„Dodavatel
  je plátce DPH"**. Nastaví se automaticky podle ARES/VIES, ale můžeš ho vědomě
  přepsat.
- **Vynucení u neplátce** — když je dodavatel neplátce, faktura se automaticky
  nastaví na **Nárok na odpočet = Bez nároku** (`vat_deduction='none'`), sazby
  řádků se vynulují na 0 % a zobrazí se varování. Doklad pak do DPH evidence
  nevstupuje (je to jen účetní náklad). Override je možný.
- **AI import** — extraktor plátcovství ověří a u neplátce (signál „DIČ:
  Neplátce DPH" / žádné DIČ + žádná DPH na řádcích, případně ARES) odpočet
  automaticky zakáže a doplní varování (viz [AI extrakce](25_AI_extrakce.md)).

Plátcovství dodavatele je vidět i ve **výpisu klientů/dodavatelů** jako badge
*Plátce DPH* (viz [§ 18.1](18_Klienti.md#181-seznam-klientu)).

> 🛠️ **Zpětná oprava existujících dodavatelů** — jednorázově
> spusť `php api/bin/backfill-vendor-vat-payer.php`. Skript podle ARES/VIES doplní příznak
> plátcovství a u neplátců opraví už zaevidované přijaté faktury (zakáže odpočet,
> sazby na 0 %, **celková částka beze změny**). Výchozí běh je **dry-run** (jen
> náhled); zápis až s `--apply`.

### 23.2.5 Platba v jiné měně (multi-currency)

Klikni na **„Platba v jiné měně než měna faktury"** pokud máš tento scénář:

> Faktura je v USD ($1000), ale platíš ji z CZK účtu (banka konvertuje na ~24 500 Kč
> s 1–2% spread / poplatkem).

V tomto bloku zadáš:

- Měna platebního účtu (např. CZK)
- Kurz platba → měna faktury (např. 0.0408 USD/CZK, nebo opačně dle UI)
- Kolik reálně odešlo z účtu (24 500 CZK)

Systém automaticky vypočte:

- **Ekvivalent v měně faktury** — pro spárování proti `amount_to_pay`
- **Kurzový rozdíl** — v základní měně (CZK). Záporný = kurzová ztráta, kladný = zisk. Zaznamenává se pro reporting a účetně se automaticky promítne do správných řádků DPH výkazů.

### 23.2.6 Klasifikace DPH — co doplní aplikace a kdy ji nechá prázdnou

Pole **Klasifikace DPH** v sekci *Klasifikace* můžeš nechat prázdné — kód doplní aplikace
při uložení podle sazby, **země dodavatele**, reverse charge a plátcovství tvé firmy k datu
dokladu. Hlavička dokladu kód jen přebírá z řádků; ručně vybraný kód nikdy nepřepíše.
Kompletní tabulka kódů i pravidel je ve [Výkazech DPH](36_Vykazy_DPH.md#3645-auto-default-klasifikace).

Tři situace, kdy zůstane **prázdná záměrně** — a aplikace ti to řekne upozorněním nad dokladem:

- **Poplatek orgánu veřejné moci** (soudní, správní, kolek, evropský platební rozkaz,
  `Gerichtskosten`, `court fee`). Orgán při výkonu veřejné správy není osobou povinnou k dani
  (§ 5 odst. 4 ZDPH), takže plnění není předmětem daně **ani u zahraničního soudu či úřadu**:
  nesamovyměřuje se podle § 9 odst. 1 a doklad nepatří do přiznání ani do kontrolního hlášení.
  Účetně je to běžný náklad, typicky 538 – Ostatní daně a poplatky. Jde-li přesto o běžnou
  přijatou službu, vyber klasifikaci ručně.
- **Nulová sazba od tuzemského dodavatele** (osvobozené plnění, nákup od neplátce). Nula sama
  nerozliší osvobození bez nároku od plnění mimo předmět daně, takže kód vybírá účetní.
- **Sazba, kterou český číselník nezná** (např. německých 19 %). Cizí daň nelze uplatnit jako
  odpočet, takže se nepřiřadí ani `40`, ani `41`. Upozornění `Doklad nese DPH, ale nemá
  klasifikaci` říká, že bez zásahu doklad do přiznání ani do KH nevstoupí — zkontroluj sazby.

> **Automatika je pomůcka, ne rozhodnutí.** Daňové zařazení dokladu zůstává na uživateli
> a jeho účetní; návrh je vždy přepsatelný.

### 23.2.7 Reverse charge z EU — pořízení zboží vs. služba

Typický případ: nákup **zboží od EU dodavatele** (např. auto z Německa) — doklad
je vystaven **bez DPH** (osvobozené intrakomunitární dodání) a daň si samovyměříš
v ČR. Správné zaevidování:

| Co | Zboží z EU (pořízení z JČS) | Služba z EU/3. země |
|---|---|---|
| **Sazba na řádcích** | tuzemská **21 %** (případně 12 %) | tuzemská **21 %** |
| **Klasifikační kód** | **23** „Pořízení zboží z JČS" | **24** „Přijetí služby" |
| **DPH přiznání** | ř. 3 (samovyměření) + ř. 43 (odpočet); u majetku navíc ř. 47 | ř. 5/12 + ř. 43 |
| **Kontrolní hlášení** | sekce **A.2** | — |
| **DUZP** | **§ 25**: 15. den měsíce po dodání, pokud doklad nebyl vystaven dříve | den uskutečnění služby |

Klíčové principy:

- **Sazba 0 % na řádku je chyba** — samovyměření by vyšlo nulové. Sazba na
  řádku je *nominální* (daň na dokladu zůstává 0, částka k úhradě se nemění),
  výkazy z ní dopočítají samovyměřenou daň i zrcadlový odpočet. Pojistka: pokud
  řádek s RC klasifikací přesto sazbu nemá, výkazy použijí sazbu klasifikačního
  kódu (21 %).
- **Doklad se do DPH období zařadí podle DUZP** — povinnost přiznat daň vzniká
  k DUZP bez ohledu na to, kdy faktura fyzicky dorazila (§ 25 odst. 1), a pozdní
  doklad neblokuje ani odpočet (§ 73 odst. 1 písm. b — nárok lze prokázat jiným
  způsobem, např. protokolem o převzetí + smlouvou + platbou). Pozdě vystavená
  faktura za zboží převzaté v dubnu tak patří do **května** (DUZP 15. 5.), ne do
  měsíce vystavení.
- **Kurz ČNB se váže k DUZP** (§ 4 odst. 8 — den vzniku povinnosti přiznat daň).
- **AI import tohle vše nastaví sám** — viz [AI extrakce](25_AI_extrakce.md).

> ⚠️ U **vybraných osobních automobilů** pohlídej limit odpočtu dle § 72
> (strop základu 2 000 000 Kč / DPH 420 000 Kč) — aplikace ho nehlídá.

### 23.2.8 Zaúčtování dobropisu

Přijatý dobropis (typ dokladu **Dobropis**, viz [§ 23.2.2](#2322-povinna-pole)) se
umí zaúčtovat do [Účetního deníku](45_Ucetni_denik.md) automaticky stejně jako běžná
faktura — systém pozná opravný doklad (typ Dobropis, nebo záporná celková částka) a
zápis automaticky **otočí strany MD/Dal** a použije absolutní částku, takže výsledný
zápis v deníku je čitelný (kladné částky na správné straně), ne matoucí záporná čísla.
Funguje stejně v CZK i v cizí měně.

V editoru dobropisu vyber také **Opravovanou přijatou fakturu** od stejného
dodavatele. Vazba je nepovinná, ale zajišťuje dohledatelnou návaznost v obou
detailech a správné promítnutí vráceného drobného majetku a kontrolního hlášení.
Jednu původní fakturu může opravovat více částečných dobropisů. Pokud vazbu
nevyplníš, automatika ji nesmí odhadnout podle samotné podobné částky.

### 23.2.9 Kurz cizí měny a jeho přenačítání

Kurz na dokladu se váže k **rozhodnému dni** — tím je DUZP, a když na dokladu není,
datum vystavení. Tentýž den používá i evidence DPH a přepočet do účetního deníku.

**Když rozhodný den nebo měnu změníš, aplikace kurz sama přenačte** — ale jen tehdy,
když ho původně sama odvodila z data. U dokladu s vyplněným DUZP se změnou data
vystavení rozhodný den nemění, takže se v takovém případě nic nepřepočítává.

Podle původu kurzu se rozhoduje takto:

| Původ kurzu na dokladu | Přenačte se? |
|---|---|
| Denní kurz ČNB (tlačítko **„Načíst z ČNB"**) | **Ano** — objednal sis kurz k datu dokladu, takže se k novému datu načte znovu |
| Pevný kurz období (§ 24/7 ZoÚ) | **Ano** — použije se pevný kurz nového období |
| Ručně zadaný kurz (vepsaný do pole) | Ne |
| Kurz z dokladu dodavatele nebo z importu (ISDOC, AI extrakce z PDF, iDoklad, Fakturoid) | Ne |
| Doklady z doby před zavedením evidence původu kurzu | Ne (původ není známý) |

Když se kurz nepřenačte, aplikace to po uložení **oznámí varováním** a napíše důvod —
kurz si pak zkontroluj (a případně přepiš ručně nebo znovu načti z ČNB). Stejné
varování dostaneš, když je ČNB nedostupná: doklad se uloží, kurz zůstane původní.

Kurz **úhrady v jiné měně** ([§ 23.2.5](#2325-platba-v-jine-mene-multi-currency)) se
přenačítáním nikdy nemění — rozdíl mezi kurzem předpisu a kurzem úhrady je legitimní
kurzový rozdíl, ne chyba.

U zaúčtovaného dokladu, který admin opravuje vynuceně (`?force=1`), se s novým kurzem
**přepočte i zápis v účetním deníku**, aby korunové částky odpovídaly dokladu.

### 23.2.10 Způsob úhrady a platba hotově z pokladny

Pole **Způsob úhrady** s volbou **Hotově** otevře výběr **Pokladna** — přijatou
fakturu tak zaplatíš z pokladny přímo z editoru, aniž bys přecházel/a do modulu
Pokladna a doklad tam vypisoval/a ručně.

- Výchozí volba je **„Nepoužít pokladnu"**; nabízejí se **jen korunové**
pokladny ([§ 30.1](30_Pokladna.md#301-ciselnik-pokladen)). Bez korunové
  pokladny se zobrazí hláška „Nemáte založenou žádnou korunovou pokladnu —
  doklad zůstane neuhrazený."
- Vyrovnání se spustí **při uložení faktury** a při přechodu do stavu
  **Přijatá** nebo **Zaúčtovaná** ([§ 23.1](#231-stavy-prijate-faktury)). U konceptu
  se volba jen uloží a čeká.

Vznikne **výdajový pokladní doklad (VPD)** s účelem „Úhrada přijaté faktury",
datem = datum vystavení faktury, popisem „Úhrada přijaté faktury {číslo} hotově"
a **celou částkou včetně DPH** — částečná hotovostní úhrada přijaté faktury
podporovaná není. Doklad se rovnou zaúčtuje (**MD 321 / D analytika pokladny**;
u zálohové přijaté faktury **MD 314 / D pokladna**) a faktura se překlopí na
**Uhrazená**. Doklad **nemá vlastní rozpad DPH** — daň už nese sama faktura,
úhrada ji neduplikuje.

Zrušení volby smaže pokladní doklad i jeho zápis a vrátí fakturu do předchozího
stavu; změna pokladny doklad přesune. Ručně vystaveného pokladního dokladu se
vyrovnání nikdy nedotkne. Podrobnosti, včetně chování při selhání a při stornu,
jsou u vydané faktury v
[§ 15.2.7](15_Faktura_editor.md#1527-zpusob-uhrady-a-platba-hotove) — u přijaté
faktury platí zrcadlově.

> [!NOTE]
> Nepodporuje se **cizoměnová faktura**, **valutová pokladna** a **daňový doklad
> k poskytnuté záloze (DDKP)** — ten se samostatně nehradí. V těchto případech
> se vyrovnání jen přeskočí (s informativní hláškou) a faktura se normálně uloží.

## 23.3 Detail přijaté faktury

Po uložení / přechodu na detail:

- Vidíš dodavatele (s IČO/DIČ), datumy, položky, DPH rozpis, totály, K úhradě.
- Karta **Daňové zařazení** shrnuje všechno, co jde nastavit v editoru: typ dokladu,
  reverse charge, plátcovství dodavatele, VAT klasifikaci (kód i popis), nárok na
  odpočet včetně procenta u kráceného, daňovou uznatelnost, dlouhodobý majetek
  a kategorii nákladu. Ceny včetně DPH najdeš v kartě Měna, u data přijetí je
  označené, jestli pochází z importu, nebo ho zadala účetní (viz [§ 23.2.4](#2324-danova-uznatelnost-a-narok-na-odpocet)).
- U položek je vidět **druh nákladu** (služba / materiál / drobný nebo dlouhodobý
  majetek), jejich vlastní VAT klasifikace a období časového rozlišení.
- Sekce **Originální PDF od dodavatele** — pokud jsi nahrál, můžeš stáhnout zpět.
- Badge **„ISDOC"** v hlavičce (a akce **Zdrojový doklad** v menu) — u faktur importovaných ze strukturovaného zdroje stáhne původní strojově čitelný originál (důkazní stopa, viz [§ 23.2.1](#2321-drag-drop-dokladu)).
- Tlačítka pro **přechod stavu** podle state-machine:
  - Z draft: Označit jako přijaté / Stornovat
  - Z received: Označit jako zaúčtované / uhrazené / Stornovat
  - Z booked: Označit jako uhrazené / Stornovat
- „**Označit jako uhrazené**" otevře modální okno s výběrem **data úhrady** (předvyplněno
  dneškem) a **způsobu úhrady** — viz [§ 23.3.4](#2331-zpusoby-uhrady-prijate-faktury).
- Tlačítko **Upravit** je dostupné jen u draft. Po označení jako přijatá je doklad immutable (kromě admin override `?force=1` u received).
- Tlačítko **Smazat** je dostupné jen u draft. Pro pozdější stavy použij Stornovat.
- Tlačítko **Zaplatit pomocí QR** (u nezaplacených faktur s kladnou částkou k úhradě) — zobrazí QR platbu dodavateli, viz [§ 23.3.2](#2333-zaplatit-pomoci-qr).

### 23.3.1 Způsoby úhrady přijaté faktury

Okno „Označit jako uhrazené" nabízí tři způsoby a liší se tím, co po nich zůstane v deníku:

| Způsob | Co vznikne | Částka |
|---|---|---|
| **Jen označit** | nic — doklad je uhrazený jen v evidenci, závazek na 321 zůstává otevřený | plná výše |
| **Hotově z pokladny** | výdajový pokladní doklad + zápis 321 MD / 211 D | plná výše |
| **Zápočtem proti účtu** | zápis **321 MD / zvolený účet D** | i **částečná** |

**Jen označit** je nouzová volba pro doklad, jehož úhradu do MyÚčta nedostaneš. Uzávěrková
kontrola takový doklad hlásí jako *zaplacená faktura s otevřeným saldem na 321* — a hlásí ho
právem, protože deník o úhradě neví a závazek by se do závěrky přenesl jako neuhrazený.

**Zápočtem proti účtu** je způsob, jak vyrovnat závazek bez peněz: proti pohledávce za
společníkem (355 / 365), proti mzdovému závazku (331), proti přijaté záloze u téhož
dodavatele (314) nebo proti čemukoli jinému, co v osnově dává smysl. Protiúčet se
předvyplní z kontačního pravidla `payment.payable.settlement`, ale rozhoduje ten, který
vybereš. Protiúčtem nesmí být týž účet, na kterém doklad visí — vznikl by zápis
„321 MD / 321 D", který nic nevyrovná.

Zápočet **může být částečný**: zbytek zůstane na dokladu otevřený, doklad zůstává ve stavu
Přijatá/Zaúčtovaná a do příkazu k úhradě i k dalšímu zápočtu vstupuje už jen svým zbytkem.
Na *Uhrazená* se překlopí teprve zápočet, který zbytek vynuluje. Zbytek se počítá ze všech
kanálů úhrady dohromady — banka, vzájemný zápočet ([§ 63](82_Zapocty.md)) i zápočty proti
účtu —, takže tutéž korunu nejde započíst dvakrát.

Zápočet jde **stornovat** (v přehledu úhrad v detailu dokladu). Storno vytvoří protizápis
a když po vrácení jeho částky zbytek zase vznikne, vrátí doklad ze stavu *Uhrazená* zpět.
Doklad doplacený jiným kanálem zůstane uhrazený.

Zatím jen doklady v **CZK**; v daňové evidenci se zápočet neúčtuje (deník tam není), ale
doklad vyrovná stejně.

### 23.3.2 Propojení zálohy s vyúčtovací fakturou (proti dvojímu započtení)

Když ti dodavatel pošle nejdřív **zálohovou fakturu** (typ dokladu *Záloha* / proforma)
a po zaplacení samostatnou **vyúčtovací (finální) fakturu**, máš v systému dva doklady
na tentýž náklad. Bez propojení by se náklad počítal **dvakrát** (Náklady, Zisk, daň
z příjmů). Proto je lze spárovat.

**Jak na to** — v detailu **finální** faktury je box **Zálohová faktura**:

- Pokud vazba není, klikni **Spárovat se zálohou** a vyber zálohu od stejného
  dodavatele. Propojit lze jen nestornovanou zálohu ve **stejné měně**; nabídka
  řadí zálohy s **nejbližší částkou**
  (porovnává hrubou částku faktury *před* odečtem zálohy, takže i faktura uhrazená
  zálohou „na 0 Kč" se napáruje správně).
- Po spárování se zobrazí odkaz na zálohu a tlačítko **Zrušit propojení**. Na finální
  fakturu se zároveň doplní odečet skutečně uhrazené části zálohy
  (`advance_paid_amount`), nejvýše do částky finální faktury, pokud byl nulový.
- V detailu **zálohy** vidíš reverzně, kterou fakturou je vyúčtována. Nevyúčtovanou
  zálohu lze spárovat i **odtud** — tlačítkem **Spárovat s fakturou** (nabídne
  nepropojené vyúčtovací faktury téhož dodavatele). *(Tlačítka se zobrazí jen když existuje vhodný protějšek.)*

Jedna záloha může být navázaná **jen na jednu** finální fakturu.

**Nákup zaplacený kartou (bez zálohové faktury).** Když dodavatel žádnou zálohovou
fakturu nevystaví a místo ní pošle rovnou **daňový doklad k platbě** (typ dokladu
*Daňový doklad k platbě*, § 28/8 ZDPH) — typicky u platby kartou — chová se tento
samostatný DDKP jako záloha: box **Zálohová faktura** ho na finální faktuře nabídne
mezi kandidáty stejně jako zálohovou fakturu, spáruje se stejným tlačítkem a
propojení funguje symetricky (z detailu DDKP i z detailu finální faktury). DDKP,
který už patří k jiné zálohové faktuře, se mezi kandidáty nenabízí — vyúčtovává se
přes tu zálohu, ne přímo.

**Špatně určený typ dokladu.** Běžná faktura mívá v hlavičce nadpis „Daňový doklad" —
to ještě není daňový doklad k platbě. Pokud takový doklad přesto skončí jako *Daňový
doklad k platbě*, přepni typ v editoru zpět na *Faktura* a ulož; u zaúčtovaného dokladu
se zároveň přeúčtuje účetní zápis. Změna projde jen u DDKP, na kterém nevisí vazba —
je-li navázaný na zálohovou fakturu nebo je jím už vyúčtovaná konečná faktura, uložení
skončí hláškou a nejdřív je potřeba zrušit tu vazbu.

Dokud propojení nevznikne, zůstává na DDKP viditelné **upozornění**, pokud je z něj
na účtu 314 otevřený zůstatek a od stejného dodavatele existuje nespárovaná faktura,
která k němu pravděpodobně patří — s odkazem na tu fakturu a rovnou spočítanou částkou
DPH (viz níže), aby nezůstal viset beze stopy.

> [!NOTE]
> **Zaúčtování zálohového cyklu.** Zaplacení zálohové přijaté faktury se do
> [Účetního deníku](45_Ucetni_denik.md) zaúčtuje jako **poskytnutá záloha** (MD 314
> Poskytnuté zálohy / D 221 banka nebo 211 pokladna) — ne jako běžný závazek 321,
> protože záloha není daňový doklad. Když pak zaúčtuješ finální (vyúčtovací) fakturu
> navázanou na tuto zálohu, zápis automaticky doplní i **zúčtovací řádek zálohy**
> (MD 321 / D 314) ve výši skutečně **zaplacené** zálohy, ne nominální částky
> zálohové faktury — takže i částečně zaplacená záloha se zúčtuje správně. Mimo
> automatiku zůstává vazba záloha↔víc než jedna finální faktura — takový případ
> zaúčtuj ručním zápisem.
>
> **Záloha (nebo samostatný DDKP) s vlastním daňovým dokladem k platbě.** Má-li
> navázaná záloha svůj DDKP — nebo je-li zálohou přímo samostatný DDKP — část účtu
> 314 už vyčerpala DPH (343/314), kterou DDKP uplatnil při platbě. Automatické
> zúčtování na plnou zaplacenou částku by pak 314 přečerpalo do minusu o tuhle už
> uplatněnou daň, takže se v tomto případě **nezaúčtuje automaticky**. Hláška u
> zaúčtování rovnou spočítá, kolik DPH z finální faktury zbývá doúčtovat na 343 nad
> rámec toho, co DDKP uplatnil už při platbě — zúčtování zálohy pak zapiš ručním
> zápisem podle této částky.

### 23.3.3 Zaplatit pomocí QR

U **nezaplacené** přijaté faktury (stav koncept / přijatá / zaúčtovaná) s kladnou
částkou k úhradě je v hlavičce detailu tlačítko **Zaplatit pomocí QR**. Otevře okno
s **QR platbou**, kterou naskenuješ v mobilní bankovní aplikaci — pro CZK doklady
ve formátu **QR Platba (SPAYD)**, pro doklady v cizí měně jako **SEPA (EPC)**.

QR sestavujeme z **platebního účtu dodavatele**, částky k úhradě a variabilního
symbolu. U CZK může obsahovat také skutečné datum splatnosti. Řídí ho samostatná
volba **Firma → Nastavení → Fakturace → Datum splatnosti v QR platbě → Přijaté
doklady**, která je ve výchozím stavu vypnutá. Po zapnutí se pole `DT` do nově
generovaného SPAYD kódu doplní. SEPA EPC datum splatnosti nepodporuje. Účet se získává v tomto pořadí:

1. **Z ISDOC** — pokud má PDF embedded ISDOC přílohu, vezme se z ní účet/IBAN i VS (zdroj „z ISDOC").
2. **AI rozpoznání** — když uložený účet není a doklad má PDF, lze ho jednorázově
   **rozpoznat z faktury** (krátký dotaz aktivnímu poskytovateli AI jen na platební údaje).
   Spustí se automaticky při otevření okna (vyžaduje nastavený API klíč — viz
   [AI extrakce](25_AI_extrakce.md)). Proběhne **jen jednou**; pokud účet na dokladu
   není, příště se už neptáme.
3. **Ručně** — účet vyplníš/upravíš přímo v okně (tlačítko **Upravit účet**) nebo
   v editoru faktury v boxu **Platební účet dodavatele**. Stačí buď **číslo účtu +
   kód banky**, nebo **IBAN** (u zahraničních dodavatelů).
4. **Obrázek QR z PDF** — když účet nelze získat, ale v PDF je obrázek, který vypadá
   jako QR kód (čtvercový, černobílý), zobrazí se jako **náhradní řešení** rovnou
   (kód nerozpoznáváme, jen ho ukážeme k naskenování). Nastavení data splatnosti
   takový převzatý obrázek změnit nemůže.

Známý účet se zobrazí i v **detailu** faktury (box *Platební účet dodavatele* vedle
měny) a předvyplní se v editoru i v okně QR.

> 💡 QR platbu uvidí i uživatel s rolí **jen pro čtení** (pokud je účet uložený);
> rozpoznání z faktury a ruční úpravu účtu může provést jen uživatel s právem zápisu.

**Co propojení (a zaplacení) ovlivní:**

| Oblast | Chování zálohy |
|---|---|
| **Náklady, Zisk — statistiky** | Spárovaná **nebo zaplacená** záloha se nepočítá (náklad nese vyúčtovací faktura). Nezaplacená a nespárovaná záloha se počítá jako očekávaný náklad. |
| **Daň z příjmů** | V **daňové evidenci DPFO** je zaplacená provozní záloha peněžním výdajem; při následném vyúčtování se už jednou zaplacená část nezapočte podruhé. V **podvojném účetnictví / DPPO** samotná záloha není nákladem — náklad nese až vyúčtování. |
| **Výkazy DPH** (Kniha DPH, DPHDP3, KH, souhrnné hlášení) | Záloha do nich **nevstupuje vůbec** (není daňový doklad; tím je až vyúčtovací faktura). |
| **Závazky / cashflow** | Nezaplacená záloha zůstává jako reálný závazek k úhradě. |

**AI návrh propojení** — když naimportuješ vyúčtovací fakturu přes AI extrakci z PDF
(viz 10.7) a ta odkazuje na zálohu (text typu *„zaplaceno zálohou č. X"*), systém
zkusí najít odpovídající zálohu a v detailu nabídne **návrh propojení**. Stačí ho
**Potvrdit** (nebo **Zamítnout**) — nic se nepáruje automaticky.

### 23.3.4 Filtr a tlačítko Zaúčtovat

> [!NOTE]
> **Stav „Zaúčtovaná" (§ 23.1) a zaúčtování do deníku jsou dvě různé věci.**
> Přechod na status **Zaúčtovaná** je jen pracovní workflow značka (visuálně
> říká „doklad je hotový, předán dál"). Skutečné **zaúčtování do podvojného
> účetnictví** — vznik zápisu v [Účetním deníku](45_Ucetni_denik.md) — je
> samostatný krok popsaný tady a řídí se vlastním příznakem `booked_at`, ne
> statusem dokladu. Klidně tak můžeš mít fakturu ve stavu **Přijatá**, ale už
> zaúčtovanou, nebo naopak ve stavu **Zaúčtovaná**, a v deníku zatím nic.

Tlačítko **Zaúčtovat** se zobrazí v hlavičce detailu jen firmám v režimu
**podvojné účetnictví**, u faktur ve stavu přijatá/zaúčtovaná/uhrazená, dokud
doklad nemá účetní ikonu **Zaúčtováno** ani aktivní zápis v deníku. U dokladu typu
**Záloha** se tlačítko nezobrazuje: zálohová výzva není účetní předpis závazku;
účtuje se až její skutečná úhrada z banky nebo pokladny na účet 314. Funguje stejně jako u [vydaných faktur](16_Faktura_PDF.md#1613-zauctovani-do-deniku)
— potvrzovací dialog, zápis podle [předkontace](88_Ucetni_nastroje.md#883-predkontace),
po úspěchu účetní ikona **Zaúčtováno** (s datem v tooltipu) + proklik **Zobrazit v deníku**. Stejná
tabulka chybových hlášek (chybějící kurz, uzavřené období, nevyvážený zápis,
chybějící účet v osnově…) platí i tady — viz [§ 16.1.3](16_Faktura_PDF.md#1613-zauctovani-do-deniku).
Zaúčtovat smí jen admin nebo účetní.

V [seznamu přijatých faktur](#231-stavy-prijate-faktury) je filtr
**Zaúčtování** (Vše / Zaúčtováno / Nezaúčtováno, jen podvojné účetnictví) —
jde do URL a uložených filtrů stejně jako u vydaných. Na rozdíl od vydaných
faktur ale seznam přijatých **nemá žádný CSV export** (jen ZIP export
originálních PDF, viz [Export přijatých](24_Export_prijatych.md)), takže
filtr se do žádného souboru nepromítá.

**Hromadné zaúčtování** — označ víc faktur a klikni **Zaúčtovat (N)**; nabídne
se jen z vybraných ty přijaté/zaúčtované/uhrazené, dosud nezaúčtované a jiné než
zálohové.
Doklady se účtují jeden po druhém (chyba jednoho neblokuje ostatní), na konci
souhrn *„Zaúčtováno {ok}, chyby: {err}"*. Max 500 dokladů na dávku.

**Automatické zaúčtování při přijetí** (volitelné, nastavuje admin — spustí se
při přechodu na stav Přijatá) — viz [§ 92.11](92_Nastaveni.md#9211-automaticke-zauctovani-pri-vystaveniprijeti-dokladu).

## 23.4 Scan inbox — automatický import z adresáře

Pokud máš dodavatele kteří ti **posílají PDF e-mailem** nebo máš složku
sdílených dokladů, nakonfiguruj **inbox adresář** v `cfg.php`:

```php
'purchase_invoice' => [
    'inbox_dir'         => 'C:/inetpub/wwwroot/myucto.cz/inbox',
    'inbox_recursive'   => true,
    'allowed_exts'      => ['pdf', 'isdoc', 'isdocx', 'xml'],
    'archive_storage'   => __DIR__ . '/storage/purchase-invoices',
],
```

V seznamu Přijaté faktury klikni **📥 Nascanovat inbox**:

- Systém rekurzivně projde nakonfigurovaný adresář.
- **Soubory se shodným základem jména bere jako jednu zásilku.** Dorazí-li faktura
  obvyklou dvojicí `faktura.pdf` + `faktura.isdoc` (nebo `.xml` / `.isdocx`), data se
  vezmou z ISDOC — jsou přesná a zadarmo — a PDF se k témuž dokladu jen archivuje jako
  čitelná podoba. **AI se v takovém případě nevolá vůbec** a nevzniká druhý koncept.
  Páruje se jen v rámci téhož adresáře a bez ohledu na velikost písmen.
- Pro každý soubor spočte SHA-256 — pokud už některý soubor zásilky v systému je
  (archivované PDF nebo strojový originál), přeskočí se celá zásilka.
- Z PDF s embedded ISDOC rozpozná data dodavatele a obsah.
- Samostatné `.isdoc` i `.isdocx` balíčky v inboxu rozbalí a naimportuje přímo (z `.isdocx`
  archivuje zabalené PDF pro náhled — pokud ale vedle leží PDF od dodavatele, použije se to).
- Plain PDF (bez ISDOC a bez datového sourozence) jde na AI extrakci, je-li nakonfigurovaná;
  jinak se přeskočí a doklad nahraj přes formulář.

Modal po skončení zobrazí přehled: vytvořeno / přeskočeno / chyby + per-soubor detail.

Pokud se u spárované dvojice nepodaří v textu PDF najít variabilní symbol z ISDOC **a**
zároveň nesedí ani celková částka, doklad i příloha přesto vzniknou, ale report u nich
ukáže **⚠ varování**, že PDF možná patří k jiné faktuře. Skenovaný obraz bez textové
vrstvy se neověřuje (nemá čím), takže u něj varování nikdy nevyskočí.

**Bezpečnost:** soubory mimo configured `inbox_dir` jsou odmítnuty (path traversal guard
přes `realpath()`). Maximum 500 souborů per běh (DoS protection na velké adresáře).

## 23.5 Klienti vs. dodavatelé

V adresáři protistran se používají dvě role:

- `is_customer` — klient, kterému fakturuješ
- `is_vendor` — dodavatel, od kterého přijímáš faktury

Některé firmy jsou **současně zákazník i dodavatel** (např. partnerská IT firma, kterou
fakturuješ za development a od níž kupuješ hosting) — jedna entita = jedna řádka,
**oba flagy = 1**. ARES synchronizace, kontakty, historie jsou sdílené.

V hlavním menu jsou samostatné položky **Klienti** a **Dodavatelé**. V adresáři
můžeš přepínat karty **Klienti / Dodavatelé / Vše**; při založení z dodavatelské
karty se role dodavatele předvyplní. Detail jedné protistrany sdílí kontakty,
ARES údaje i historii, ale přehledy vydaných a přijatých dokladů zůstávají
oddělené podle role.

## 23.6 Audit log

Akce s přijatými fakturami jsou logované v aktivním logu (Systém → Log):

- `purchase_invoice.created`
- `purchase_invoice.updated` / `force_updated`
- `purchase_invoice.items_updated`
- `purchase_invoice.exchange_rate_set`
- `purchase_invoice.transitioned` (s payloadem `{from, to}`)
- `purchase_invoice.extraction_warning_dismissed`
- `purchase_invoice.advance_linked` / `advance_unlinked` (propojení se zálohou)
- `purchase_invoice.advance_suggestion_dismissed` (zamítnutý AI návrh propojení)
- `purchase_invoice.deleted`
- `purchase_invoice.pdf_uploaded` / `pdf_downloaded`
- `purchase_invoice.our_pdf_downloaded`
- `purchase_invoice.isdoc_exported` / `pohoda_exported`
- `purchase_invoice.inbox_scanned`

## 23.7 REST API

Všechny operace jsou dostupné i přes REST API (`/api/v1/purchase-invoices/*`) —
viz [Swagger UI](/api/docs) nebo [Redoc](/api/reference). PAT token musí mít scope
`read_write` pro mutace.
