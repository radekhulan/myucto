<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Anthropic Claude API client pro AI extraction z PDF faktur.
 *
 * BYOK — per-tenant API klíč (uživatel platí sám). Default model:
 * claude-haiku-4-5 (~$0.001/faktura), pro lepší kvalitu lze přepnout
 * na Sonnet 4.6 (~$0.005/faktura).
 *
 * Cena za extrakci PDF s ~5 řádkami:
 *   Haiku 4.5:  ~3000 input tokens (PDF base64) + ~500 output tokens
 *               = $0.0006 input + $0.0025 output = ~$0.003
 *   Sonnet 4.6: ~$0.012 (4× dráž)
 *
 * Strict JSON output přes structured response — anti-hallucination.
 */
final class AnthropicClient implements LlmGatewayInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT = 120; // PDF extraction trvá 10-30s typicky
    private const MAX_PDF_BYTES = 32 * 1024 * 1024; // 32 MiB hard limit (Anthropic limit)
    private const MAX_RETRIES = 3;
    private const MAX_RETRY_SLEEP = 65; // seconds — pokrývá 1-minute token bucket reset
    // Throttle: pokud `remaining_input_tokens` z headerů klesne pod tuto hranici,
    // další volání počká do `reset` timestampu. Drží řadu PDF v batchi z toho,
    // aby cumulativně přefoukla 50k token/min limit a způsobila 429.
    private const RATE_LIMIT_THROTTLE_THRESHOLD = 5000;

    private Client $http;

    /** @var array{remaining_input:int, reset_at:int}|null */
    private ?array $rateLimitState = null;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = new Client([
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array{api_key:string, default_model:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT anthropic_api_key_enc, anthropic_default_model FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['anthropic_api_key_enc'])) return null;
        try {
            $key = $this->crypto->decrypt((string) $row['anthropic_api_key_enc']);
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic API key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return [
            'api_key'       => $key,
            'default_model' => (string) ($row['anthropic_default_model'] ?? LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL),
        ];
    }

    public function setCredentials(int $supplierId, string $apiKey, ?string $defaultModel = null): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $model = $defaultModel ?: LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL;
        $this->db->pdo()->prepare(
            'UPDATE supplier SET anthropic_api_key_enc = ?, anthropic_default_model = ?
              WHERE id = ?'
        )->execute([$enc, $model, $supplierId]);
    }

    public function updateDefaultModel(int $supplierId, string $defaultModel): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET anthropic_default_model = ? WHERE id = ?'
        )->execute([$defaultModel, $supplierId]);
    }

    /**
     * Test connectivity — pošle minimalistický prompt, ověří 200 OK.
     */
    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'API key nenastaven'];
        }
        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model' => $creds['default_model'],
                'max_tokens' => 10,
                'messages' => [['role' => 'user', 'content' => 'Reply OK']],
            ], $creds['api_key']);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }
            return ['ok' => true, 'model' => $body['model'] ?? null, 'usage' => $body['usage'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * F7 §3.3 — provider si sám určí upgrade na silnější model. Anthropic: haiku →
     * sonnet (hoist hard-coded upgradu z AiPdfExtractor). Vrací null, když upgrade
     * nedává smysl. Odstranění duplicity z AiPdfExtractor je Commit 2.
     */
    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        return $this->capabilities($supplierId)->strongerModel($currentModel);
    }

    /**
     * F7 §3.2 — descriptor schopností Anthropic providera pro daného tenanta.
     * Anthropic default region = 'us' (EU jen dedikovaným endpointem, mimo Commit 1).
     */
    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        return LlmProviderCapabilities::anthropic('us');
    }

    /**
     * Extrahuje strukturovaná data z PDF faktury pomocí Claude vision.
     *
     * Workflow:
     *   1. PDF → base64
     *   2. Strict system prompt s JSON schema definicí
     *   3. POST /messages s document content block (type=document, source.type=base64, source.data=...)
     *   4. Parse response.content[0].text jako JSON
     *   5. Validate proti hallucinations (caller zodpovědný)
     *
     * @return array{ok:bool, data?:array<string,mixed>, error?:string, model?:string, usage?:array<string,int>}
     */
    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Anthropic API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF (chybí %PDF header).'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $base64Pdf = base64_encode($pdfBytes);

        // Načti tenant info (název + IČ + DIČ) abychom AI mohli explicitně říct,
        // že tenant je odběratel (customer), NIKDY dodavatel. Bez tohoto AI občas
        // zamění vendor↔customer u faktur kde má dodavatel velkou vlastní hlavičku
        // (NC Auto / BMW Service / mobilní operátoři) a tenanta dá do vendor pozice.
        $tenantBlock = $this->buildTenantContextBlock($supplierId);

        $systemPrompt = $tenantBlock . <<<'EOT'
Jsi expert na extrakci dat z českých a slovenských faktur. Z PDF přílohy vytáhneš strukturovaná data ve striktním JSON formátu.

PRAVIDLA:
- Vrátíš JEN platný JSON (žádný markdown, žádný komentář před/po).
- Pokud pole neexistuje v PDF, použij null. NEVYMÝŠLEJ data.
- Datumy ve formátu ISO YYYY-MM-DD.
- Částky čísla bez měny (přidej zvlášť do `currency`).
- IČ/DIČ ořež na čísla (CZ12345678 → "12345678"), pokud má prefix země ponech v `dic` jak je.
- VAT rate jako desetinné číslo (21.0, 15.0, 12.0, 10.0, 0.0).

JSON schema:
{
  "vendor": {
    "company_name": string,
    "ic": string|null,
    "dic": string|null,
    "vat_dic": string|null,
    "street": string|null,
    "city": string|null,
    "zip": string|null,
    "country_iso2": "CZ"|"SK"|...,
    "email": string|null,
    "phone": string|null,
    "web": string|null,
    "is_vat_payer": boolean|null
  },
  "customer": {
    "company_name": string|null,
    "ic": string|null,
    "dic": string|null
  },
  "payment": {
    "bank_account": string|null,
    "iban": string|null,
    "variable_symbol": string|null,
    "method": "bank_transfer"|"direct_debit"|"card"|"cash"|"cash_on_delivery"|"offset"|"other"|null,
    "method_confidence": number
  },
  "vendor_invoice_number": string|null,
  "corrected_invoice_number": string|null,
  "varsymbol": string|null,
  "document_kind": "invoice"|"credit_note"|"advance"|"receipt"|"tax_document",
  "issue_date": "YYYY-MM-DD",
  "tax_date": "YYYY-MM-DD"|null,
  "due_date": "YYYY-MM-DD"|null,
  "currency": "CZK"|"EUR"|"USD"|...,
  "items": [
    {
      "description": string,
      "quantity": number,
      "unit": string,
      "unit_price_without_vat": number,
      "line_total_without_vat": number|null,
      "vat_rate": number,
      "expense_kind": "service"|"material"|"small_asset"|"fixed_asset"|null,
      "expense_kind_confidence": number,
      "expense_kind_reasoning": string|null
    }
  ],
  "unit_prices_include_vat": boolean,
  "unit_prices_stated": boolean|null,
  "total_without_vat": number|null,
  "total_with_vat": number|null,
  "total_with_vat_rounded": number|null,
  "vat_recap": [
    { "rate": number, "base": number, "vat": number }
  ],
  "already_paid": boolean,
  "advance_reference": string|null,
  "supply_nature": "goods"|"services"|"mixed"|null
}

DŮLEŽITÉ k DATŮM (`issue_date`, `tax_date`, `due_date`) — NEJDŮLEŽITĚJŠÍ, ČTI POZORNĚ:
- Na faktuře jsou typicky TŘI různá data. Přiřaď je VÝHRADNĚ podle POPISKU u data,
  NIKDY podle pořadí/pozice na stránce. Stejné datum se může opakovat u více popisků.
- `issue_date` = DATUM VYSTAVENÍ dokladu. Popisky: „Datum vystavení", „Vystaveno",
  „Datum vystavení dokladu", „Vystavená dňa" / „Dátum vystavenia" (SK), „Date of issue",
  „Invoice date", „Issued".
- POPISKY, ZE KTERÝCH SE `issue_date` BRÁT NESMÍ — TOHLE JE NEJČASTĚJŠÍ CHYBA:
  „Datum objednávky", „Datum přijetí objednávky", „Datum objednání", „Order date",
  „PO date", „Datum dodání", „Datum odeslání", „Datum expedice", „Datum tisku",
  „Datum vytištění", „Datum splatnosti", „Období". Doklad běžně nese datum objednávky
  HNED VEDLE data vystavení (typicky e-shop / dodavatel elektroniky: „Číslo objednávky"
  a „Datum objednávky" nad „Datum vystavení" a „DUZP") a liší se o dny i týdny —
  když si je popleteš, spadne doklad do jiného účetního období.
- DATUM NIKDY NEODVOZUJ Z ČÍSLA DOKLADU, z čísla objednávky, z variabilního symbolu ani
  z názvu souboru. I když v nich číselná sekvence vypadá jako datum (např. číslo dokladu
  „CZ260723-10226320" obsahuje „260723" = 23. 07. 2026), NENÍ to datové pole. Datum ber
  VÝHRADNĚ od jeho popisku; když popisek na dokladu chybí, vrať `null`.
- `tax_date` = DUZP = DATUM USKUTEČNĚNÍ ZDANITELNÉHO PLNĚNÍ. Popisky: „Datum uskutečnění
  zdanitelného plnění", „Datum uskut. zdaň. plnění", „Den uskut. zdaň. plnění", „DUZP",
  „Datum zdanitelného plnění", „Datum plnění", „Datum dodání", „Datum dodávky" /
  „Dátum dodania" (SK), „Date of supply", „Tax point". Pokud na dokladu NENÍ → vrať null
  (systém pak použije datum vystavení). DUZP je daňově zásadní — přiřaď ho PŘESNĚ podle
  tohoto popisku, nezaměňuj ho se splatností ani vystavením.
- `due_date` = DATUM SPLATNOSTI. Popisky: „Datum splatnosti", „Splatnost", „Splatno do",
  „Splatné do" (SK), „Zaplaťte do", „Úhrada do", „Due date", „Payment due", „Date due".
  Když doklad splatnost NEUVÁDÍ (typicky doklad hrazený kartou nebo předem), vrať `null` —
  NEOPISUJ do ní datum vystavení, datum objednávky ani jiné datum z dokladu.
- LOGICKÁ KONTROLA (týká se JEN splatnosti): splatnost je platební lhůta = vystavení + N dní,
  takže `due_date` je VŽDY ≥ `issue_date` (NIKDY dřív než vystavení). Pokud ti z přiřazení
  vyjde splatnost DŘÍVE než vystavení, spletl ses v popiscích — přečti data znovu; když si
  po druhém čtení nejsi jistý, vrať `due_date: null` místo nesmyslné hodnoty.
- POZOR, opačně to NEPLATÍ pro DUZP: `tax_date` PŘED `issue_date` je zcela legitimní a běžné
  (doklad vystavený 2. dne měsíce za plnění k poslednímu dni měsíce předchozího, souhrnné
  doklady za období). Takové DUZP NEOPRAVUJ a neposouvej k datu vystavení.

DŮLEŽITÉ k poli `document_kind`:
- Pokud nadpis / hlavička PDF obsahuje "Opravný daňový doklad", "Dobropis",
  "Opravná faktura", "Credit note", "Storno faktura", "Storno doklad",
  nebo doklad jinak signalizuje vrácení / opravu předchozí faktury
  (např. záporné částky, odkaz na opravovanou fakturu) → vrať `"credit_note"`.
- Pokud doklad je "Zálohová faktura", "Proforma", "Proforma faktura",
  "Zálohový list", "Výzva k platbě", "Advance invoice" → vrať `"advance"`. Toto je VÝZVA
  k zaplacení zálohy PŘED plněním a sama o sobě není daňový doklad.
- Pokud doklad je "Daňový doklad k přijaté platbě", "Daňový doklad k záloze",
  "Doklad o přijaté úhradě k záloze" nebo "Daňový doklad – přijatá platba" → vrať
  `"tax_document"`. Je to daňový doklad, který dodavatel vystavil PO přijetí zálohy jen
  na DPH z té zálohy (§28 ZDPH) — vyčísluje DPH ze zaplacené zálohy a odkazuje na
  zálohovou fakturu/proformu, ale NENÍ to konečné vyúčtování celého plnění. Neúčtuje se
  jako náklad, jen jako nárok na odpočet DPH.
- POZOR: samotný nadpis "Daňový doklad" (i "Faktura — daňový doklad", "Daňový doklad č. …",
  "Faktura/daňový doklad") `"tax_document"` NEZNAMENÁ. Takhle je nadepsaná úplně obyčejná
  faktura od operátora, energetiky nebo e-shopu. `"tax_document"` vracej JEN tehdy, když
  doklad výslovně mluví o PŘIJATÉ/PROVEDENÉ PLATBĚ nebo o ZÁLOZE ("k přijaté platbě",
  "k provedené platbě", "k záloze", "z přijaté úplaty") a vyčísluje DPH z už zaplacené
  zálohy. Bez toho vrať `"invoice"` — i když je slovo "daňový doklad" na dokladu několikrát.
- Pokud doklad je "Konečná faktura", "Vyúčtovací faktura" nebo "Konečné vyúčtování"
  (odečítá zaplacené zálohy a vyúčtovává celé plnění) → vrať `"invoice"`, NIKOLI
  `"tax_document"` ani `"advance"`.
- Pokud doklad je "Účtenka", "Paragon", "Pokladní doklad", "Receipt" → vrať `"receipt"`.
- Jinak (běžná faktura / daňový doklad) → vrať `"invoice"`.

DŮLEŽITÉ k poli `unit_prices_include_vat` (DPH v ceně položky):
- Na ÚČTENKÁCH / PARAGONECH (`document_kind = "receipt"`) jsou ceny u položek
  typicky uvedené VČETNĚ DPH (brutto). Poznáš to tak, že součet cen položek se
  rovná řádku "CELKEM" / "K úhradě" (s DPH), zatímco "Mezisoučet bez DPH" je NIŽŠÍ.
  Příklad: položka "Tonic 33,00 Kč" + "DPH 21 %", součet položek = CELKEM 344 Kč,
  ale "Mezisoučet bez DPH" = 284,30 Kč → ceny položek JSOU včetně DPH.
- Pokud jsou ceny položek VČETNĚ DPH → vrať `unit_prices_include_vat: true`
  a do `unit_price_without_vat` dej cenu **TAK JAK JE NA DOKLADU (včetně DPH)**.
  NEPŘEPOČÍTÁVEJ ji sám — přepočet na cenu bez DPH udělá náš systém dle `vat_rate`.
- Pokud jsou ceny položek bez DPH (běžná faktura, kde je DPH až v součtu) →
  vrať `unit_prices_include_vat: false` a `unit_price_without_vat` jako cenu bez DPH.
- Když si nejsi jistý, porovnej součet cen položek s "Mezisoučet bez DPH" vs
  "CELKEM/K úhradě": blíž k bez-DPH → false; blíž k celkem-s-DPH → true.
- Když na dokladu ŽÁDNÝ řádek "bez DPH" / "Mezisoučet bez DPH" NENÍ (typická
  jednoduchá účtenka, foto účtenky, nebo doklad od NEPLÁTCE DPH) → ceny položek
  jsou prakticky vždy s DPH → vrať `true`. Pole bez DPH na takovém dokladu nehledej.
- U dokladu od NEPLÁTCE DPH (není uvedena žádná sazba ani DPH) vrať `vat_rate: 0`
  a `unit_prices_include_vat: true` (cena je konečná, žádné DPH se neodečítá).

DŮLEŽITÉ k poli `unit_prices_stated` (UVÁDÍ doklad jednotkové ceny?):
- `true` = doklad má u položek sloupec s JEDNOTKOVOU cenou (cena za kus / za jednotku /
  za litr): „Jedn. cena", „Cena/MJ", „Cena za jednotku", „Cena za l", „Unit price",
  „Cena za MJ bez DPH".
- `false` = doklad jednotkové ceny NEUVÁDÍ VŮBEC — má jen množství a částku za řádek
  (např. „Množství 29,70 L" + „Základ daně po slevě 1 106,02"), případně jen souhrnnou
  daňovou rekapitulaci.
- `null` když to z dokladu nejde posoudit.
- Když je `false`, jednotkovou cenu si NEDOPOČÍTÁVEJ a NEVYMÝŠLEJ — vydělit částku
  množstvím znamená vyrobit číslo, které na dokladu není, a při slevách to vyjde ze
  špatného sloupce. Do `unit_price_without_vat` i `line_total_without_vat` dej
  ŘÁDKOVOU ČÁSTKU BEZ DPH tak, jak je na dokladu, a `quantity` vrať `1`.
  Množství v jednotkách napiš do `description` (např. „Efecta 95 (145,34 L)").

DŮLEŽITÉ k poli `supply_nature` (povaha plnění — zboží vs. služba):
- `"goods"` pokud doklad fakturuje FYZICKÉ ZBOŽÍ — vozidlo (VIN, SPZ, Fahrzeug),
  stroj, hardware, materiál, zboží s dodacím listem / přepravou.
- `"services"` pokud fakturuje SLUŽBY — SaaS/cloud/API předplatné, licence,
  poradenství, servisní práce, dopravu samotnou, nájem.
- `"mixed"` pokud doklad obsahuje obojí v podstatném poměru (např. zboží + montáž).
- `null` když to z dokladu nelze poznat.
- Pole je důležité hlavně u zahraničních dokladů bez DPH (reverse charge) —
  rozhoduje o zařazení do DPH přiznání (pořízení zboží z EU vs. přijetí služby).

DŮLEŽITÉ k poli `expense_kind` (druh nákladu, NA KAŽDÉM ŘÁDKU ZVLÁŠŤ):
- Je to NÁVRH pro účetní, nic se podle něj neúčtuje automaticky. Radši `null` než tip naslepo.
- Klasifikuj VÝHRADNĚ těmito hodnotami; význam je ZÁVAZNÝ, neřiď se vlastní představou
  o tom, co ta anglická slova obvykle znamenají:
  - `"service"`     = Služba. Plnění, po kterém nezůstane věc: doprava, poštovné, balné,
                      záruka, licence, předplatné, hosting, nájem/pronájem, tarif a
                      vyúčtování operátora, servis, oprava, školení, poradenství, montáž.
  - `"material"`    = Spotřební materiál. Věc, která se spotřebuje: PHM (natural, diesel,
                      benzin, nafta), toner, cartridge, papír, kancelářské potřeby, kabeláž.
                      POZOR: PHM je `"material"`, NIKDY `"small_asset"`.
  - `"small_asset"` = Drobný majetek. Samostatná věc dlouhodobého užívání pod hranicí
                      dlouhodobého majetku: notebook, tablet, mobilní telefon, monitor,
                      tiskárna, router, kávovar, skartovačka, sluchátka, flash disk.
  - `"fixed_asset"` = Dlouhodobý majetek. Hmotná věc s cenou za kus nad 80 000 Kč bez DPH
                      (vozidlo, stroj, sestava). Při pochybnosti o ceně vrať `"small_asset"`,
                      hranici si ohlídáme sami.
- ROZHODUJE POVAHA ŘÁDKU, NE DODAVATEL. Jedna faktura běžně míchá druhy: na faktuře z
  Alzy je notebook `"small_asset"`, brašna `"material"`, doprava a prodloužená záruka `"service"`.
- Když řádek zmiňuje službu (doprava, doručení, záruka, licence, pronájem, vyúčtování),
  NIKDY to není `"small_asset"`, ani kdyby v textu byl název zařízení.
- „telefon"/„mobil" samo o sobě nestačí: „mobilní telefon Samsung" (nákup přístroje) je
  `"small_asset"`, ale „Vyúčtování telefonních služeb" je `"service"`.
- Když si nejsi jistý → `expense_kind: null` a `expense_kind_confidence: 0`.
- `expense_kind_confidence` = tvoje jistota 0..1. `expense_kind_reasoning` = jedna krátká
  česká věta PROČ, s citací slova z dokladu (např. „řádek uvádí tablet Galaxy Tab").
  Nevkládej do zdůvodnění osobní údaje (jména, e-maily, telefonní čísla).

DŮLEŽITÉ k poli `vendor.is_vat_payer` (plátcovství dodavatele):
- `false` pokud doklad jasně značí, že DODAVATEL je neplátce DPH — typicky text
  „Neplátce DPH" / „Nejsem plátce DPH" u DIČ dodavatele, NEBO dodavatel nemá DIČ
  a na dokladu není žádná sazba/částka DPH.
- `true` pokud má dodavatel platné DIČ a/nebo je na dokladu vyčíslena DPH.
- `null` když to z dokladu nelze určit. (Systém ověří plátcovství i v registru ARES/VIES.)

DŮLEŽITÉ k polím `vendor.dic` a `vendor.vat_dic` (doklad může nést DVĚ RŮZNÁ DIČ):
- `dic` = DIČ SUBJEKTU, který doklad vystavil. Popisky: „DIČ", „IČ DPH", „VAT ID",
  „Tax ID". U českého subjektu má tvar CZ + IČO (např. IČ 27597075 → DIČ CZ27597075).
  Do `dic` patří VŽDY jen tohle DIČ — stojí na něm párování karty dodavatele.
- `vat_dic` = DIČ K DPH / DIČ SKUPINOVÉ REGISTRACE, které doklad uvádí NAVÍC vedle
  `dic`. Popisky: „DIČ k DPH", „DIČ pro DPH", „DIČ skupiny", „DIČ skupinové
  registrace", „Skupinové DIČ", „DIČ plátce DPH". V ČR má typicky tvar `CZ699xxxxxx`
  (skupinová registrace dle § 5a ZDPH), ale ROZHODUJE POPISEK, ne tvar čísla.
- Když doklad uvádí jen JEDNO DIČ → dej ho do `dic` a `vat_dic` vrať `null`.
- Když uvádí OBĚ a liší se (typicky odštěpný závod fakturující pod skupinovou
  registrací mateřské firmy) → `dic` = DIČ subjektu, `vat_dic` = DIČ k DPH.
  NEVYBÍREJ jen jedno z nich a NEZAMĚŇUJ je; skupinové DIČ do `dic` NIKDY nepatří.
- Když jsou obě uvedená a jsou STEJNÁ, vrať tutéž hodnotu v obou polích.

DŮLEŽITÉ k poli `vendor_invoice_number` (číslo dokladu):
- Vrať číslo dokladu/faktury/účtenky tak, jak je vytištěné (např. "3266011131",
  "2025/0042", u paragonu pořadové číslo účtenky / číslo dokladu pokud existuje).
- Účtenka / paragon NEMUSÍ mít žádné jednoznačné číslo dokladu. Pokud na dokladu
  ŽÁDNÉ použitelné číslo NENÍ → vrať `null`. NEVYMÝŠLEJ ho a NEPOUŽÍVEJ náhražky
  jako číslo pokladny, IČO, DIČ, datum nebo telefon — to číslo dokladu není.
- U DOBROPISU / opravného daňového dokladu (`document_kind = "credit_note"`) vrať do
  `vendor_invoice_number` VLASTNÍ číslo opravného dokladu — číslo u textu „Opravný
  daňový doklad č.", „Dobropis č.", „Číslo dobropisu", „Doklad č.". NIKDY sem nedávej
  číslo OPRAVOVANÉ faktury z odkazu („k faktuře č.", „Opravovaný doklad č.",
  „Původní doklad č.", „k dokladu č.") — to patří VÝHRADNĚ do `corrected_invoice_number`.

DŮLEŽITÉ k poli `corrected_invoice_number` (číslo opravované faktury — JEN u dobropisu):
- Číslo PŮVODNÍ (opravované) faktury, na kterou se dobropis odkazuje — text „k faktuře
  č.", „Opravovaný doklad č.", „Původní doklad č.", „k dokladu č.". U běžné faktury,
  účtenky i zálohy → `null`. NEVYMÝŠLEJ; když odkaz na opravovaný doklad NENÍ → `null`.

DŮLEŽITÉ k poli `payment` (platební údaje DODAVATELE pro QR platbu):
- `bank_account` = číslo bankovního účtu dodavatele v ČESKÉM formátu
  „[předčíslí-]číslo/kód_banky" TAK JAK JE NA DOKLADU (např. „2900123456/2010",
  „19-2000145399/0800"). Hledej u textu „Bankovní spojení", „Číslo účtu", „Účet",
  „Account", „Bank account".
- `iban` = IBAN dodavatele pokud je uveden (např. „CZ65 0800 0000 1920 0014 5399").
- `variable_symbol` = variabilní symbol platby (VS), typicky shodný s číslem faktury.
- VŽDY jde o účet PŘÍJEMCE PLATBY = DODAVATELE (vendor), NIKDY odběratele/tenanta.
- Pokud údaj na dokladu NENÍ → null. Nevymýšlej.

DŮLEŽITÉ k položkám u dobropisu (`document_kind = "credit_note"`):
- `quantity` a `unit_price_without_vat` vrať jako **kladná čísla** (jak jsou na PDF).
  Záporné znaménko si aplikuje importér automaticky podle `document_kind`.
- Stejně tak `total_without_vat`, `total_with_vat`, `total_with_vat_rounded`
  vrať jako **kladná čísla** (absolutní hodnoty z PDF).

DŮLEŽITÉ k řádkům se slevou / rabatem / discount (jen u `document_kind = "invoice"`):
- Pokud řádek běžné faktury reprezentuje slevu / rabat / bonus snižující fakturu
  (popis obsahuje "sleva", "rabat", "discount", "bonus", "%" sleva, "Roční sleva"
  apod.) A na PDF je jeho jednotková cena nebo celková částka uvedena se
  znaménkem **MÍNUS** (např. `-643,50`, `-7 722,00`) nebo v závorkách (např.
  `(643,50)`) → vrať `unit_price_without_vat` jako **ZÁPORNÉ** číslo
  (např. `-643.50`). Slevy MUSÍ mít záporné znaménko, jinak by se přičetly
  k faktuře místo aby ji snížily.
- POZOR: toto NEPLATÍ pro dobropisy (`credit_note`) — u nich vždy kladné absolutní
  hodnoty, sign aplikuje importér podle `document_kind`.
- SOUHRNNÝ BLOK SLEVY NENÍ POLOŽKA a do `items` NEPATŘÍ — to je něco jiného než
  slevový řádek výše. Poznáš ho podle toho, že je to SAMOSTATNÁ TABULKA pod položkami
  („Sleva", „Rabat", „Přehled slev") se sloupci „Před slevou s DPH", „Před slevou bez
  DPH", „Sleva bez DPH", „Sleva DPH", „Sleva s DPH" a vlastním řádkem „Celkem". Jen
  rekapituluje slevu, která je UŽ PROMÍTNUTÁ v částkách u položek — kdybys ho vrátil
  jako položku, odečetla by se sleva podruhé.
- Když doklad uvádí částky PŘED SLEVOU i PO SLEVĚ, závazné jsou VŽDY částky PO SLEVĚ
  (sloupce „…po slevě", „Základ daně po slevě", „DPH po slevě", „Cena s DPH po slevě",
  „Netto po slevě"). Předslevové částky do výstupu NEPATŘÍ — ani do `items`, ani do
  `total_without_vat` / `total_with_vat`, ani do `vat_recap`.

DŮLEŽITÉ k poli `already_paid`:
- Pokud PDF obsahuje text typu "NEPLAŤTE, JIŽ UHRAZENO", "ZAPLACENO",
  "UHRAZENO", "PAID", "ALREADY PAID", "PAYMENT RECEIVED", "Hradí se ze zálohy"
  nebo podobné indikátory že faktura už byla zaplacena → vrať `true`.
- Pokud žádný takový text není (default scénář) → vrať `false`.

DŮLEŽITÉ k poli `payment.method` (FORMA ÚHRADY) — ČTI POZORNĚ:
- Hledej VÝSLOVNÝ text o formě úhrady. Popisky: "Forma úhrady", "Způsob platby",
  "Způsob úhrady", "Forma platby", "Úhrada", "Platba", "Spôsob úhrady" (SK),
  "Payment method", "Method of payment".
- "Inkaso", "Inkasem", "Souhlas s inkasem", "Svolení k inkasu", "SIPO", "Direct debit",
  "bude uhrazena inkasem", "částka bude stržena z vašeho účtu", "Neplaťte" (ve smyslu
  „nezadávejte příkaz, strhneme si sami") → `"direct_debit"`.
- "Převodem", "Bankovním převodem", "Příkazem k úhradě", "Bank transfer" → `"bank_transfer"`.
- "Kartou", "Platební kartou", "Card" → `"card"`.
- "Hotově", "V hotovosti", "Cash" → `"cash"`.
- "Dobírka", "Na dobírku", "Cash on delivery", "COD" → `"cash_on_delivery"`.
- "Zápočtem", "Vzájemný zápočet", "Offset" → `"offset"`.
- VAROVÁNÍ — TOHLE JE NEJČASTĚJŠÍ CHYBA: inkasní doklad má TÉMĚŘ VŽDY uvedené i číslo
  účtu, variabilní symbol a konstantní symbol (často i QR kód). Slouží k IDENTIFIKACI
  platby, NE jako pokyn k převodu. Přítomnost bankovního spojení, VS, KS ani QR kódu
  NENÍ důkaz, že se platí převodem. Rozhoduje VÝHRADNĚ výslovný text o formě úhrady.
- Pokud forma úhrady na dokladu výslovně UVEDENÁ NENÍ → vrať `null`. NEHÁDEJ ji a
  NEODVOZUJ ji z přítomnosti bankovního spojení ani z typu dodavatele.
- `payment.method_confidence` = číslo 0..1, jak jistě jsi formu vyčetl (0 když vracíš null).

DŮLEŽITÉ k poli `advance_reference`:
- Pokud doklad odkazuje na zaplacenou zálohu / proformu (typicky "Odečet zálohy",
  "Zaplaceno zálohou č. ...", "Uhrazeno zálohovou fakturou ...", "k zálohové
  faktuře č. ...", "Hradí se ze zálohy ...", "paid by advance ...", "proforma
  no. ...") → vrať identifikátor té zálohy/proformy jak je uveden na dokladu
  (číslo faktury / variabilní symbol), např. `"2026/0042"` nebo `"PF2026001"`.
- U daňového dokladu k přijaté platbě (`document_kind="tax_document"`) sem VŽDY dej
  číslo zálohové faktury / proformy, ke které se doklad váže (číslo té zálohy / VS).
- Pokud žádný odkaz na zálohu není → vrať `null`. Nevymýšlej hodnoty.

DŮLEŽITÉ k zaokrouhlení:
- `total_with_vat` = přesný součet (např. 228.69)
- `total_with_vat_rounded` = zaokrouhlená částka pokud je na PDF uvedeno
  zaokrouhlení (např. "229.00 Kč", "K úhradě: 229").
- Rozdíl (229 - 228.69 = 0.31) půjde do pole `rounding` faktury.
- Pokud na PDF NENÍ explicitní zaokrouhlení, vrať `total_with_vat_rounded: null`.

DŮLEŽITÉ k poli `vat_recap` (rekapitulace DPH po sazbách):
- Opiš REKAPITULACI / REKAPITULACI DPH z dokladu — tabulku, kde je pro každou
  sazbu DPH uveden základ daně a daň. Typicky dole na faktuře:
  „Rekapitulace DPH" / „Rozpis DPH" / „DPH rozpis" / „VAT summary" se sloupci
  Sazba | Základ | DPH.
- Pro KAŽDOU sazbu vrať jeden objekt `{ "rate": sazba_v_%, "base": základ_bez_DPH,
  "vat": částka_DPH }` — VŠE jako kladná čísla TAK JAK JSOU NA DOKLADU (nepřepočítávej).
- Příklad rekapitulace na dokladu:
    Sazba   Základ      DPH
    21 %    1 000,00    210,00
    12 %      500,00     60,00
  → `vat_recap`: [{"rate":21,"base":1000.00,"vat":210.00},{"rate":12,"base":500.00,"vat":60.00}]
- Klíčové je věrně opsat hodnoty DPH dle dokladu (kvůli § 73 ZDPH — odpočet ve výši
  daně uvedené na dokladu); haléřové rozdíly oproti přepočtu jsou očekávané.
- U DOBROPISU vrať kladná čísla (sign aplikuje importér).
- Pokud doklad rekapitulaci DPH po sazbách NEMÁ (jednoduchá účtenka, neplátce,
  reverse-charge bez DPH) → vrať `vat_recap: []` (prázdné pole). Nevymýšlej hodnoty.
- DAŇOVÁ REKAPITULACE JE AUTORITA. Když ji doklad má (včetně varianty „Daňová
  rekapitulace (po slevě)"), opiš ji PŘESNĚ a beze změny — i kdyby se ti nesčítala
  s tím, co jsi vyčetl z položek. `total_without_vat` a `total_with_vat` musí
  odpovídat TÉTO rekapitulaci (součet základů, resp. součet částek s DPH), ne tvému
  dopočtu z položek.
- Když se položky s rekapitulací rozcházejí, NEUPRAVUJ rekapitulaci, aby seděla, ani
  nedopočítávej chybějící čísla — vrať rekapitulaci věrně. Rozpor vyřeší náš systém.

DŮLEŽITÉ k řádkům faktury (`items`):
- Vrať POUZE listové (atomické) položky — konkrétní práce, materiál, zboží.
  NIKDY agregační / subtotalové / součtové řádky.
- IGNORUJ jakýkoli řádek, který začíná nebo obsahuje (case-insensitive):
  "Celkem ", "Mezisoučet", "Subtotal", "Σ ", "Součet ", "Total " (pokud
  je to subtotal sekce, ne celková K úhradě), "Cena celkem za skupinu",
  "Cena celkem za sekci".
- U faktur s vícestupňovou strukturou (typicky autoservis — např. NC Auto
  s.r.o. / BMW Service: skupina práce → jednotlivé úkony → "Celkem Práce" →
  "Celkem <název skupiny>") vrať POUZE jednotlivé úkony s reálnými qty
  a unit_price. NIKDY součtové meziřádky — ty by ti při sečtení nafoukly
  celkovou částku 2-5× nad reálný total.
- Pokud na faktuře vidíš stejnou položku "Vyvážení kola" s qty 1 i jako
  součtový řádek "Celkem Vyvážení" s vypočtenou sumou — vrať POUZE ten s qty 1.

DŮLEŽITÉ k poli `line_total_without_vat` (řádková částka bez DPH):
- Pokud má řádek faktury vlastní sloupec s CELKOVOU částkou ZA ŘÁDEK BEZ DPH
  (typicky „Částka", „Celkem bez DPH", „Základ", „Základ daně", „Cena celkem", a na
  dokladu se slevou i „Základ daně po slevě"), opiš ho do
  `line_total_without_vat` PŘESNĚ tak, jak je na dokladu.
- Je to klíčové hlavně tam, kde `quantity × unit_price_without_vat` NEODPOVÍDÁ té
  částce — typicky autoservisy (NC Auto / BMW Service): sloupec „Cena" u položky NENÍ
  jednotková cena k násobení množstvím (např. „AW 8,29 × 1 980" má řádkovou částku
  1 980, ne 16 414). Náš systém pak vezme řádkovou částku jako pravdu.
- Pokud doklad takový sloupec NEMÁ (jen jednotková cena, nebo jen „Cena s DPH"),
  vrať `null`. NEVYMÝŠLEJ hodnotu a NEPŘEPOČÍTÁVEJ ji.
- Hodnota je vždy BEZ DPH. U dokladu, kde jsou ceny uvedené VČETNĚ DPH (účtenky,
  `unit_prices_include_vat=true`), vrať `null` — bez DPH částku tam nehledej.
- U dobropisu vrať kladnou absolutní hodnotu (sign aplikuje importér).

DŮLEŽITÉ k VÍCESTRÁNKOVÝM dokladům (faktura + příloha/rozpis):
- Některé doklady mají na PRVNÍ straně vlastní fakturu se sumarizovanými
  fakturačními řádky (to, co se reálně účtuje a sčítá do "K úhradě"), a na DALŠÍCH
  stranách PODROBNÝ ROZPIS / SPECIFIKACI / přílohu (rozpad té samé částky na
  detailní položky, položky po měsících, telefonní hovory, odečty měřidel apod.).
- Vrať VÝHRADNĚ fakturační řádky z HLAVNÍ faktury (typicky 1. strana), jejichž
  součet odpovídá "K úhradě". NIKDY nepřidávej řádky z podrobného rozpisu/přílohy
  — ty rozpadají TÉŽ částku znovu a jejich přidání by total zdvojnásobilo.
- Poznáš rozpis podle nadpisů jako "Rozpis", "Specifikace", "Podrobný rozpis",
  "Příloha", "Detailní výpis", "Vyúčtování položek", "Soupis", "Rozpis plnění"
  na druhé+ straně, nebo podle toho, že součet detailních řádků = součet
  fakturačních řádků z 1. strany (stejná částka rozepsaná jinak).
- Pravidlo zdravého rozumu: součet vrácených `items` (bez DPH) se musí blížit
  základu daně z hlavní faktury, NE jeho násobku. Když by řádky z rozpisu total
  zdvojily, ignoruj rozpis a ber jen hlavní fakturu.

DŮLEŽITÉ k poli `total_with_vat`:
- Hodnota MUSÍ pocházet výhradně z hlavního finálního "K úhradě" /
  "Celkem k úhradě" / "CZK k zaplacení" / "Total amount due" / "K platbě"
  — typicky úplně dole na faktuře, často zvýrazněně (tučně/větším fontem).
- NIKDY neber `total_with_vat` ze subtotalu jednotlivé sekce/skupiny prací,
  ani ze součtu mezi-skupin ("Celkem Práce", "Celkem Materiál").
- Pokud máš pochybnost mezi více čísly, vyber NEJMENŠÍ logické. Subtotaly
  jsou typicky větší než K úhradě jen kvůli zaokrouhlení; součet sekcí >
  K úhradě téměř vždy znamená, že čteš špatný řádek.
- POKUD si nejsi jistý finálním totalem (nevidíš jasné "K úhradě"), vrať
  NULL místo hádání.
- Když doklad finální „K úhradě" NEUVÁDÍ VŮBEC (typicky souhrnný doklad hrazený
  inkasem nebo platební kartou, kde je jen věta „Uhraďte na základě podkladu pro
  příkaz k úhradě"), ale MÁ daňovou rekapitulaci → vezmi celkem s DPH z rekapitulace.

Příklad — faktura NC Auto s.r.o. (BMW Service), struktura:
  Sekce A: Práce
    Diagnostika              1 ks  500.00 Kč  →  ITEM
    Výměna oleje             1 ks  800.00 Kč  →  ITEM
    Celkem Práce                 1 300.00 Kč  →  IGNORE (subtotal)
  Sekce B: Materiál
    Olej 5W30                4 l   180.00 Kč  →  ITEM
    Filtr olejový            1 ks  280.00 Kč  →  ITEM
    Celkem Materiál              1 000.00 Kč  →  IGNORE (subtotal)
  Celkem bez DPH             2 300.00 Kč      →  IGNORE (grand subtotal)
  DPH 21 %                     483.00 Kč
  K úhradě                   2 783.00 Kč      →  total_with_vat = 2783.00
Výsledek: items = 4 řádky (NE 6 a NE 7); total_with_vat = 2783.00.
EOT;

        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model' => $model,
                'max_tokens' => 4096,
                'system' => $systemPrompt,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => $base64Pdf,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON, bez markdown.',
                        ],
                    ],
                ]],
            ], $creds['api_key'], $supplierId);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }

            // Parse Claude's text response
            $text = (string) ($body['content'][0]['text'] ?? '');
            if ($text === '') {
                return ['ok' => false, 'error' => 'Prázdná odpověď od Claude'];
            }
            // Strip případné markdown code fences
            $text = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text);
            $data = json_decode((string) $text, true);
            if (!is_array($data)) {
                return ['ok' => false, 'error' => 'Claude vrátil invalid JSON: ' . substr($text, 0, 200)];
            }

            // Increment usage counter
            $this->db->pdo()->prepare(
                'UPDATE supplier SET anthropic_extractions_count = anthropic_extractions_count + 1 WHERE id = ?'
            )->execute([$supplierId]);

            return [
                'ok'    => true,
                'data'  => $data,
                'model' => $body['model'] ?? $model,
                'usage' => $body['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic extractInvoice failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Vytěží jednotlivá tankování z detailního výpisu benzínky (typicky str. 2 PDF),
     * když interní parser nedokáže poskládat řádky. Univerzální fallback pro Axigon
     * i jiné karetní společnosti.
     *
     * @return array{ok:bool, transactions?:list<array<string,mixed>>, error?:string, model?:string, usage?:array}
     */
    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Anthropic API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF.'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $base64Pdf = base64_encode($pdfBytes);

        $systemPrompt = <<<'EOT'
Jsi expert na čtení detailních výpisů tankování z faktur palivových karet (Axigon, CCS, Shell, Eurowag…).
Z PDF vytáhneš JEDNOTLIVÉ transakce (každé tankování / službu jako samostatný řádek) ve striktním JSON.

Schema:
{"transactions": [
  {
    "fueled_date": "YYYY-MM-DD",        // datum transakce
    "fueled_time": "HH:MM" | null,      // čas, pokud je uveden
    "fuel_type": "string",              // název zboží/paliva, např. "Prémiová nafta", "Natural 95", "Mytí vozu"
    "quantity": number | null,          // množství (litry), null pokud neuvedeno
    "unit_price": number | null,        // jednotková cena po slevě (za litr), null pokud neuvedeno
    "amount_without_vat": number | null,// cena bez DPH za řádek
    "amount_vat": number | null,        // DPH za řádek
    "amount_with_vat": number,          // celkem s DPH za řádek (POVINNÉ)
    "station": "string" | null,         // místo / síť, např. "Město, Ulice / Shell"
    "receipt_number": "string" | null,  // číslo účtenky, pokud je
    "is_fuel": true | false             // true = pohonná hmota; false = služba (mytí, plošná cena, poplatek, dálniční známka)
  }
]}

Pravidla:
- Vrať VŠECHNY řádky transakcí (palivo i služby), ne jen palivo. is_fuel rozliš podle názvu zboží.
- Čísla bez měny a bez oddělovačů tisíců (z "1 234,56 Kč" vrať 1234.56).
- Datum normalizuj na YYYY-MM-DD (z "27.05.2026" → "2026-05-27"; dvojmístný rok → 20xx).
- NEvracej souhrnné/CELKEM řádky ani hlavičky karet — jen jednotlivé transakce.
- Odpověz JEN samotným JSON, bez markdownu.
EOT;

        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model'      => $model,
                'max_tokens' => 8192,
                'system'     => $systemPrompt,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64Pdf]],
                        ['type' => 'text', 'text' => 'Vytáhni jednotlivé transakce tankování z detailního výpisu podle JSON schema. Odpověz JEN JSON.'],
                    ],
                ]],
            ], $creds['api_key'], $supplierId);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }
            $text = (string) ($body['content'][0]['text'] ?? '');
            if ($text === '') return ['ok' => false, 'error' => 'Prázdná odpověď od Claude'];
            $text = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text);
            $data = json_decode((string) $text, true);
            if (!is_array($data) || !isset($data['transactions']) || !is_array($data['transactions'])) {
                return ['ok' => false, 'error' => 'Claude vrátil invalid JSON: ' . substr((string) $text, 0, 200)];
            }
            $this->db->pdo()->prepare(
                'UPDATE supplier SET anthropic_extractions_count = anthropic_extractions_count + 1 WHERE id = ?'
            )->execute([$supplierId]);
            return [
                'ok'           => true,
                'transactions' => array_values($data['transactions']),
                'model'        => $body['model'] ?? $model,
                'usage'        => $body['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic extractFuelTransactions failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lightweight extrakce JEN total_with_vat z PDF — pro recheck / sanity check
     * scenarios kde nepotřebujeme items/klient/datumy. Vrátí jednu number, nebo
     * null pokud AI fail / nemůže najít K úhradě.
     *
     * Výhody vs extractInvoice():
     *   - max_tokens 100 místo 4096 (output ~10× kratší)
     *   - jednodušší prompt (kratší input tokens)
     *   - bez tenant context bloku (pro pure total extraction nepotřebné)
     *   - typicky 5-10× levnější per call (Haiku ~$0.0001 místo $0.001)
     *
     * Použití: jako AI fallback v `PdfTotalExtractor` když ISDOC + regex selžou.
     *
     * @return array{ok: bool, total?: ?float, error?: string, model?: string, usage?: array}
     */
    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Anthropic API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF.'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $base64Pdf = base64_encode($pdfBytes);

        // Minimalistický prompt — chceme jediné číslo, JSON s jedním polem.
        $systemPrompt = <<<'EOT'
Z PDF faktury vrátíš JEN finální částku k úhradě (= "K úhradě", "Celkem k platbě", "Total to pay")
ve formátu JSON. Žádný markdown, žádné komentáře.

Schema: {"total_with_vat": number}
- number je číslo bez měny (z 1 502,00 Kč vrať 1502.00)
- Pokud finální K úhradě nelze určit jednoznačně, vrať {"total_with_vat": null}
- U DOBROPISU vrať kladné číslo (znaménko si aplikujeme my)
- POZOR na sekce "Z minulého období" / "Nedoplatek z minulého období" / "Přijaté platby" —
  to NENÍ aktuální K úhradě. Hledej PROVĚŘOVANÉ K úhradě v hlavním souhrnu.
EOT;

        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model'      => $model,
                'max_tokens' => 100,
                'system'     => $systemPrompt,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'application/pdf',
                                'data'       => $base64Pdf,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Vrať K úhradě podle JSON schema.',
                        ],
                    ],
                ]],
            ], $creds['api_key'], $supplierId);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }

            $text = (string) ($body['content'][0]['text'] ?? '');
            $text = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text) ?? $text;
            $data = json_decode(trim($text), true);
            if (!is_array($data) || !array_key_exists('total_with_vat', $data)) {
                return ['ok' => false, 'error' => 'Claude vrátil invalid JSON: ' . substr($text, 0, 100)];
            }

            $total = $data['total_with_vat'];
            $total = is_numeric($total) ? (float) $total : null;

            // Increment usage counter (stejně jako extractInvoice, je to AI call)
            $this->db->pdo()->prepare(
                'UPDATE supplier SET anthropic_extractions_count = anthropic_extractions_count + 1 WHERE id = ?'
            )->execute([$supplierId]);

            return [
                'ok'    => true,
                'total' => $total,
                'model' => $body['model'] ?? $model,
                'usage' => $body['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic extractPdfTotal failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lightweight extrakce JEN platebního účtu dodavatele z PDF — pro lazy doplnění
     * účtu u starých faktur (importovaných ještě bez rozpoznávání účtu), když chce
     * uživatel „Zaplatit pomocí QR". Stejně levné jako extractPdfTotal (Haiku, krátký
     * prompt i výstup).
     *
     * @return array{ok: bool, bank_account?: ?string, iban?: ?string, variable_symbol?: ?string, error?: string, model?: string, usage?: array}
     */
    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Anthropic API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF.'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $base64Pdf = base64_encode($pdfBytes);

        $systemPrompt = <<<'EOT'
Z PDF faktury vrátíš JEN platební údaje DODAVATELE (příjemce platby) ve formátu JSON.
Žádný markdown, žádné komentáře.

Schema: {"bank_account": string|null, "iban": string|null, "variable_symbol": string|null}
- `bank_account` = číslo účtu dodavatele v ČESKÉM formátu „[předčíslí-]číslo/kód_banky"
  TAK JAK JE NA DOKLADU (např. „2900123456/2010", „19-2000145399/0800"). Hledej
  u textu „Bankovní spojení", „Číslo účtu", „Účet", „Account".
- `iban` = IBAN dodavatele pokud je uveden (např. „CZ6508000000192000145399").
- `variable_symbol` = variabilní symbol platby (VS), typicky shodný s číslem faktury.
- VŽDY jde o účet PŘÍJEMCE PLATBY = DODAVATELE, NIKDY odběratele.
- Pokud údaj na dokladu NENÍ → null. NEVYMÝŠLEJ.
EOT;

        try {
            ['code' => $code, 'body' => $body] = $this->postWithRetry([
                'model'      => $model,
                'max_tokens' => 200,
                'system'     => $systemPrompt,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'application/pdf',
                                'data'       => $base64Pdf,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Vrať platební údaje dodavatele podle JSON schema.',
                        ],
                    ],
                ]],
            ], $creds['api_key'], $supplierId);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }

            $text = (string) ($body['content'][0]['text'] ?? '');
            $text = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text) ?? $text;
            $data = json_decode(trim($text), true);
            if (!is_array($data)) {
                return ['ok' => false, 'error' => 'Claude vrátil invalid JSON: ' . substr($text, 0, 100)];
            }

            $this->db->pdo()->prepare(
                'UPDATE supplier SET anthropic_extractions_count = anthropic_extractions_count + 1 WHERE id = ?'
            )->execute([$supplierId]);

            $str = static fn ($v) => (is_string($v) && trim($v) !== '') ? trim($v) : null;
            return [
                'ok'              => true,
                'bank_account'    => $str($data['bank_account'] ?? null),
                'iban'            => $str($data['iban'] ?? null),
                'variable_symbol' => $str($data['variable_symbol'] ?? null),
                'model'           => $body['model'] ?? $model,
                'usage'           => $body['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic extractPaymentAccount failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sestaví prioritní hlavičku promptu s tenant info, aby AI vědělo, že
     * tato konkrétní firma je VŽDY odběratel (customer) — NIKDY dodavatel.
     *
     * Pomáhá u faktur kde dodavatel má dominantní hlavičku (autoservisy s logy,
     * mobilní operátoři s brandingem) a AI by jinak zaměnila vendor↔customer.
     *
     * Pokud tenant info nelze načíst (DB error / chybějící data), vrátí prázdný
     * string a prompt zůstane v původní podobě — žádný hard fail.
     */
    private function buildTenantContextBlock(int $supplierId): string
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT company_name, ic, dic, ai_extraction_notes FROM supplier WHERE id = ?'
            );
            $stmt->execute([$supplierId]);
            $t = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return '';
        }
        if ($t === false) {
            return '';
        }
        $notes = InvoiceExtractionPrompt::tenantNotes($t['ai_extraction_notes'] ?? null);
        if (empty($t['company_name']) && empty($t['ic'])) {
            return $notes;
        }
        $name = (string) ($t['company_name'] ?? '');
        $ic   = (string) ($t['ic'] ?? '');
        $dic  = (string) ($t['dic'] ?? '');
        $hint = [];
        if ($name !== '') $hint[] = "název \"{$name}\"";
        if ($ic !== '')   $hint[] = "IČO \"{$ic}\"";
        if ($dic !== '')  $hint[] = "DIČ \"{$dic}\"";
        $tenantHint = implode(', ', $hint);

        // Heredoc bez interpolace by tu nešel (potřebuji vložit $tenantHint).
        // Používám sprintf místo, aby šlo escape jednoduše.
        return sprintf(
            "DŮLEŽITÝ KONTEXT (čti jako první, předchází všechna ostatní pravidla):\n"
            . "- Toto je extrakce PŘIJATÉ faktury pro firmu: %s.\n"
            . "- Tato firma je VŽDY odběratel (customer) — NIKDY ne dodavatel (vendor).\n"
            . "- Pokud v PDF vidíš tuto firmu (matchuj IČO nebo název), vrať ji v poli `customer`, NIKDY v poli `vendor`.\n"
            . "- Dodavatel (vendor) je VŽDY ta druhá strana — ten, kdo fakturu vystavil.\n"
            . "- POZOR: na fakturách autoservisů, mobilních operátorů, hostingových firem apod. má dodavatel typicky velkou\n"
            . "  hlavičku s logem nahoře, zatímco odběratel je v adresním bloku níže. NEpodléhej tomu — odběratele\n"
            . "  pozná podle shody s firmou z tohoto kontextu, dodavatel je vždy ta DRUHÁ strana.\n"
            . "- Pokud bys vrátil tuto firmu jako vendor, znamená to že jsi špatně přečetl PDF — importér to detekuje a fakturu zamítne.\n\n",
            $tenantHint,
        ) . $notes;
    }

    /**
     * Zvolená míra uvažování tenanta ({@see LlmProviderCapabilities::EFFORTS}).
     * Fail-safe: cokoliv nečekaného (chybějící sloupec, DB error) znamená `default`,
     * tedy neposílat providerovi nic navíc.
     */
    private function tenantEffort(int $supplierId): string
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT ai_effort FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $v = (string) $stmt->fetchColumn();
        } catch (\Throwable) {
            return LlmProviderCapabilities::EFFORT_DEFAULT;
        }
        return in_array($v, LlmProviderCapabilities::EFFORTS, true)
            ? $v
            : LlmProviderCapabilities::EFFORT_DEFAULT;
    }

    private function authHeaders(string $apiKey): array
    {
        return [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ];
    }

    /**
     * POST /v1/messages s rate-limit obranou:
     *   1. **Throttle** — pokud poslední response ohlásil zbytek vstupních tokenů
     *      pod prahem, spí do `reset_at` (proaktivní vyhnutí se 429 v batchi).
     *   2. **Retry** — na HTTP 429 počká podle `retry-after` headeru a opakuje
     *      max `MAX_RETRIES`-krát s exponenciálním fallbackem.
     *
     * @param array<string,mixed> $payload
     * @return array{code:int, body:array<string,mixed>|null}
     */
    private function postWithRetry(array $payload, string $apiKey, ?int $supplierId = null): array
    {
        if ($supplierId !== null) {
            $payload += LlmProviderCapabilities::anthropic()
                ->effortPayload((string) ($payload['model'] ?? ''), $this->tenantEffort($supplierId));
        }
        $this->applyThrottle();
        $attempt = 0;
        while (true) {
            $resp = $this->http->post(self::API_URL, [
                'headers' => $this->authHeaders($apiKey),
                'json'    => $payload,
            ]);
            $code = $resp->getStatusCode();
            $body = json_decode((string) $resp->getBody(), true);
            $this->captureRateLimit($resp);

            if ($code !== 429 || $attempt >= self::MAX_RETRIES) {
                return ['code' => $code, 'body' => is_array($body) ? $body : null];
            }

            $sleep = $this->computeRetrySleep($resp, $attempt);
            $this->logger->info('Anthropic rate-limited, retrying', [
                'attempt' => $attempt + 1,
                'sleep'   => $sleep,
            ]);
            sleep($sleep);
            $attempt++;
        }
    }

    /**
     * Spí do `reset_at` pokud poslední response signalizoval, že zbývá málo
     * input tokenů. Cap na MAX_RETRY_SLEEP, aby se neudusila celá request.
     */
    private function applyThrottle(): void
    {
        if ($this->rateLimitState === null) return;
        if ($this->rateLimitState['remaining_input'] >= self::RATE_LIMIT_THROTTLE_THRESHOLD) return;
        $wait = $this->rateLimitState['reset_at'] - time();
        if ($wait <= 0) return;
        $wait = min(self::MAX_RETRY_SLEEP, $wait + 1);
        $this->logger->info('Anthropic throttle wait', [
            'seconds'         => $wait,
            'remaining_input' => $this->rateLimitState['remaining_input'],
        ]);
        sleep($wait);
        // Po čekání resetuj — header v další response nám stejně dá fresh hodnoty.
        $this->rateLimitState = null;
    }

    private function captureRateLimit(\Psr\Http\Message\ResponseInterface $resp): void
    {
        $remainingHdr = $resp->getHeaderLine('anthropic-ratelimit-input-tokens-remaining');
        $resetHdr     = $resp->getHeaderLine('anthropic-ratelimit-input-tokens-reset');
        if ($remainingHdr === '' || $resetHdr === '') return;
        try {
            $resetTs = (new \DateTimeImmutable($resetHdr))->getTimestamp();
        } catch (\Throwable) {
            return;
        }
        $this->rateLimitState = [
            'remaining_input' => (int) $remainingHdr,
            'reset_at'        => $resetTs,
        ];
    }

    private function computeRetrySleep(\Psr\Http\Message\ResponseInterface $resp, int $attempt): int
    {
        $retryAfter = (int) $resp->getHeaderLine('retry-after');
        if ($retryAfter <= 0) {
            // Fallback: 2, 4, 8 … s, capped.
            $retryAfter = (int) min(self::MAX_RETRY_SLEEP, 2 ** ($attempt + 1));
        }
        return (int) min(self::MAX_RETRY_SLEEP, max(1, $retryAfter));
    }
}
