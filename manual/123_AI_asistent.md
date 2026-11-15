# 123. AI asistent

> Asistent v `Účetnictví → AI asistent` odpovídá na otázky k účtování, k datům vaší firmy
> a k postupům v aplikaci. Data firmy si sám dohledá s vašimi právy a jen ke čtení,
> postupy ověří v tomto manuálu a na zdroje odkáže. Navrhne zápisy do deníku, interní
> doklady a ostatní pohledávky a závazky, nic ale sám nezaúčtuje ani nezapíše.

## 123.1 Kdy to potřebujete

Asistenta otevřete, když:

- nevíte, jak účetní případ zaúčtovat, a chcete návrh zápisu s konkrétními účty,
- potřebujete rychle zjistit údaj z dat firmy, třeba kolik dluží konkrétní odběratel nebo které faktury jsou po splatnosti,
- hledáte příčinu rozdílu, například proč nesedí saldo účtu 324 nebo co chybí do přiznání k DPH,
- nevíte, kde se v aplikaci něco dělá, a chcete postup s odkazem do manuálu a na správnou stránku,
- máte smlouvu, notářský zápis nebo jiný doklad a chcete z něj založit ostatní pohledávku, závazek nebo interní doklad.

Asistent je dostupný firmám v podvojném účetnictví.

## 123.2 Než začnete

1. **Poskytovatel AI.** V `Firma → AI nastavení` musí být nastavený poskytovatel, klíč a potvrzená zpracovatelská
   smlouva (DPA). Postup je v [§ 25.3](25_AI_extrakce.md#253-krok-za-krokem-nastavit-poskytovatele-ai). Vyžaduje-li firma
   zpracování dat v EU, asistent pracuje jen s poskytovatelem, který ho splňuje.
2. **Právo na účetnictví.** Stránku otevře role s právem číst Účetnictví. Data firmy asistent čte s vašimi právy:
   co nevidíte v aplikaci, nedostane ani asistent.
3. **Node.js pro data firmy.** Čtení dat firmy používá stejný katalog nástrojů jako [MCP server](105_MCP_server.md)
   a potřebuje na serveru Node.js a PHP CLI dostupné aplikaci (Dockerový obraz je obsahuje). Serverový MCP pro
   externí AI přitom zapnutý být nemusí. Bez Node.js asistent odpovídá jen z manuálu a z přiložených dokladů.

## 123.3 Krok za krokem: zeptat se asistenta

1. Otevřete `Účetnictví → AI asistent`.
2. Napište dotaz vlastními slovy. Na prázdné stránce můžete kliknout na některý z příkladů, vloží se do pole dotazu.
3. Podle potřeby přiložte soubory tlačítkem **Přiložit soubory** nebo dokumenty tlačítkem **Přiložit z Dokumentů**.
   Volba **Přiložit účtový rozvrh firmy včetně analytik** pošle asistentovi váš účtový rozvrh, aby navrhoval
   konkrétní analytické účty.
4. Odešlete tlačítkem **Odeslat** nebo klávesami Ctrl+Enter. Během zpracování se ukazuje, co asistent právě dělá.
   Dotaz nad daty firmy obvykle trvá několik desítek sekund.
5. Přečtěte odpověď. Pod ní najdete:
   - **Zdroje z manuálu**: oddíly manuálu, ze kterých odpověď vychází; odkaz otevře manuál v nové záložce,
   - **Použitá data a nástroje**: rozbalovací seznam toho, co asistent četl (hledání v manuálu, přehled faktur,
     saldo účtu…), včetně zadaných filtrů a toho, zda čtení uspělo,
   - návrhy zápisů, interních dokladů a ostatních pohledávek a závazků, které po kontrole zaúčtujete nebo založíte sami.
6. Na odpověď můžete navázat dalším dotazem. Konverzace se ukládá do **Historie dotazů** vlevo.

Odkazy na stránky aplikace v odpovědi otevřou danou stránku přímo v aplikaci.

## 123.4 Krok za krokem: dotaz nad daty firmy

Příklady dotazů:

- „Které vydané faktury jsou po splatnosti a kolik celkem?“
- „Kolik nám dluží odběratel Alfa s.r.o.?“
- „Proč nesedí saldo účtu 324?“
- „Co ještě chybí do přiznání k DPH za minulý měsíc?“

1. Napište dotaz co nejkonkrétněji: uveďte období, účet nebo partnera.
2. Asistent si vybere vhodné nástroje (seznamy faktur, saldokonto, výkazy, kontroly DPH…) a přečte z nich data
   aktuální firmy. Čísla bere jen z výsledků, nic neodhaduje.
3. V seznamu **Použitá data a nástroje** zkontrolujte, z čeho odpověď vychází. U nástroje, ke kterému nemáte právo,
   je uvedena chyba a asistent v odpovědi řekne, že k těm datům přístup nemáte.

## 123.5 Krok za krokem: jak se v aplikaci dělá…

1. Zeptejte se například „Jak udělám ruční zápis do deníku?“ nebo „Jak spáruji platbu z banky s fakturou?“.
2. Asistent vyhledá postup v manuálu, odpoví konkrétními kroky a odkáže na příslušný oddíl manuálu i na stránku
   aplikace, kde akci provedete.
3. Odkazy z odpovědi jsou i v části **Zdroje z manuálu**.

## 123.6 Když něco nejde

| Příznak | Příčina | Řešení |
|---|---|---|
| Stránka hlásí, že AI asistent není k dispozici | Chybí poskytovatel, klíč, potvrzení DPA nebo poskytovatel nesplňuje požadavek na zpracování v EU | Doplňte nastavení podle [§ 25.3](25_AI_extrakce.md#253-krok-za-krokem-nastavit-poskytovatele-ai) |
| Asistent odpovídá jen obecně a data firmy nepoužije | Na serveru chybí Node.js nebo PHP CLI pro katalog nástrojů | Ověřte Node.js a PHP CLI jako u [serverového MCP](105_MCP_server.md#105835-vzdalene-pripojeni-bez-stahovani) |
| U nástroje je v seznamu chyba oprávnění | Vaše role k těm datům nemá přístup | Požádejte správce o právo, nebo se zeptejte uživatele, který přístup má |
| Poskytovatel Ollama data firmy nečte | Lokální modely asistent používá jen pro jedno volání bez nástrojů | Pro dotazy nad daty zvolte jiného poskytovatele |
| Odpověď trvá dlouho nebo skončí chybou poskytovatele | Velký dotaz, mnoho příloh nebo přetížený poskytovatel | Zužte dotaz (období, partner, účet) a zkuste to znovu |

## 123.7 Podrobnosti a pravidla

### 123.7.1 Co asistent smí

- **Jen čte.** Data firmy čte nástroji, které nic nemění. Nic nezaúčtuje, nezaloží ani nesmaže. Návrhy zápisů,
  interních dokladů a ostatních pohledávek a závazků zaúčtujete nebo založíte sami tlačítkem u návrhu.
- **Jen aktuální firma.** Čte data firmy, ve které právě pracujete, s právy vaší role v této firmě.
- **Stejný katalog jako MCP.** Používá stejné čtecí nástroje jako [MCP server](105_MCP_server.md) pro externí AI.
  Nové nástroje katalogu má asistent k dispozici automaticky.

### 123.7.2 Co se posílá poskytovateli AI

Poskytovateli AI se posílá dotaz, kontext firmy (dnešní datum, způsob vedení účetnictví, plátcovství DPH), volitelně
účtový rozvrh, přílohy a výsledky nástrojů, které asistent pro odpověď přečetl. Platí stejná pravidla jako pro ostatní
funkce AI: zpracovatelská smlouva (DPA) a požadavek na zpracování v EU se ověřují před každým krokem dotazu.
Text dokladů a výsledky nástrojů dostává model jako data, ne jako pokyny.

### 123.7.3 Limity jednoho dotazu

- nejvýše 12 čtení dat nebo manuálu a 6 kol práce s nástroji,
- výsledek jednoho nástroje se zkrátí na 12 000 znaků (asistent pak dotaz zúží filtrem),
- po zhruba 75 sekundách asistent odpoví z toho, co už zjistil.

Spotřebu tokenů hradíte poskytovateli AI. Dotaz nad daty firmy je dražší než jednoduchá otázka, protože model
pracuje ve více krocích.

### 123.7.4 Poskytovatelé

Data firmy a manuál asistent čte u poskytovatelů Anthropic, OpenAI, Azure OpenAI a Google Gemini. U lokální Ollamy
odpovídá jedním voláním bez nástrojů, jen z kontextu a příloh.

### 123.7.5 Auditní stopa

Každý dotaz, při kterém asistent použil nástroje, se zapíše do protokolu činnosti jako `ai.assistant_tools`
s názvy použitých nástrojů a výsledkem. Obsah dotazu, zadané filtry ani přečtená data se do protokolu nezapisují.

## 123.8 Související kapitoly

- [AI extrakce](25_AI_extrakce.md): nastavení poskytovatele AI a zpracovatelské smlouvy
- [Účetní deník](52_Ucetni_denik.md): ruční zápisy, interní doklady a ostatní pohledávky a závazky z návrhů asistenta
- [MCP server](105_MCP_server.md): stejný katalog nástrojů pro externí AI
