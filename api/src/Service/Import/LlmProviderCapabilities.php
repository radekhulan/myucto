<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Readonly value object popisující schopnosti jednoho LLM providera pro daného
 * tenanta (F7 §3.2 / §13.1). Sem se centralizuje VEŠKERÉ provider string-coupling —
 * whitelist modelů, validace klíče, maxPdfBytes, dataRegion, strongerModel,
 * supportsPdfDocumentBlock.
 *
 * Provider set v1 = anthropic + azure_openai + openai + gemini. Každý má vlastní
 * factory metodu; router/klienti resolvují descriptor přes ně.
 */
final readonly class LlmProviderCapabilities
{
    /**
     * Whitelisty modelů. Pořadí je VÝZNAMOVÉ — {@see strongerModel()} bere PRVNÍ
     * vyhovující model odshora, takže nejnovější/nejsilnější patří nahoru. Starší
     * generace se drží kvůli tenantům, kteří na nich mají zaparkovaný default.
     * Ověřeno proti reálným katalogům providerů 2026-08-26
     * (private/scripts/probe_llm_models.php + probe_llm_models_call.php).
     */
    public const ANTHROPIC_DEFAULT_MODEL = 'claude-haiku-4-5';
    public const ANTHROPIC_MODELS = [
        'claude-haiku-4-5',
        'claude-sonnet-5',
        'claude-sonnet-4-6',
        'claude-fable-5',
        'claude-opus-5',
        'claude-opus-4-8',
        'claude-opus-4-7',
    ];

    public const OPENAI_DEFAULT_MODEL = 'gpt-5.4-mini';
    public const OPENAI_MODELS = [
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-5.5',
        'gpt-5.4',
        'gpt-5.1',
        'gpt-5',
        'gpt-4.1',
        'gpt-4o',
        'gpt-5.4-mini',
        'gpt-5.4-nano',
        'gpt-5-mini',
        'gpt-4.1-mini',
        'gpt-4o-mini',
    ];

    /** gemini-2.5-flash vyřazen — provider ho novým účtům vrací 404 s odkazem na 3.6-flash. */
    public const GEMINI_DEFAULT_MODEL = 'gemini-3.7-flash';
    public const GEMINI_MODELS = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-3.1-pro-preview',
        'gemini-2.5-pro',
    ];

    public function __construct(
        public string $id,               // 'anthropic' | 'azure_openai' | 'openai' | 'gemini'
        public string $label,            // human-readable
        /** @var list<string> whitelist model/deployment id */
        public array  $models,
        public string $defaultModel,
        public int    $maxPdfBytes,      // anthropic 32 MiB; ostatní per-provider
        public string $dataRegion,       // 'eu' | 'us' (fyzická rezidence endpointu)
        public string $residencyLabel,   // FE badge text
        public bool   $supportsPdfDocumentBlock, // anthropic true; azure/openai = input_file; gemini inline_data
        public bool   $requiresStructuredOutputJsonMode,
    ) {}

    /**
     * Anthropic Claude descriptor. Whitelist modelů drží {@see ANTHROPIC_MODELS} —
     * {@see \MyInvoice\Action\Admin\Import\AnthropicCredentialsAction} i
     * {@see \MyInvoice\Action\Admin\Import\AiProviderCredentialsAction} z něj čtou,
     * takže se allowlisty nemohou rozejít.
     */
    public static function anthropic(string $dataRegion = 'us'): self
    {
        return new self(
            id: 'anthropic',
            label: 'Anthropic Claude',
            models: self::ANTHROPIC_MODELS,
            defaultModel: self::ANTHROPIC_DEFAULT_MODEL,
            maxPdfBytes: 32 * 1024 * 1024,
            dataRegion: $dataRegion,
            residencyLabel: $dataRegion === 'eu' ? 'EU (Anthropic)' : 'US (Anthropic)',
            supportsPdfDocumentBlock: true,
            requiresStructuredOutputJsonMode: false,
        );
    }

    /**
     * Azure OpenAI descriptor (F7 §13.1). Modely = per-tenant deployment(y).
     * dataRegion se odvozuje VÝHRADNĚ z hostname endpointu (fail-closed): EU jen když
     * hostname nese známý EU Azure region-token, jinak us. Volný `ai_data_region` label
     * NEsmí rezidenci povýšit (self-attestation US resource + label='eu' by klasifikoval
     * EU) — `$declaredRegion` se zachovává jen pro zpětnou kompatibilitu signatury (FE
     * badge), ale region NEřídí.
     */
    public static function azureOpenai(?string $endpoint, ?string $deployment, string $declaredRegion = 'eu'): self
    {
        $region = self::azureRegion($endpoint, $declaredRegion);
        $models = ($deployment !== null && $deployment !== '') ? [$deployment] : [];
        return new self(
            id: 'azure_openai',
            label: 'Azure OpenAI',
            models: $models,
            defaultModel: (string) ($deployment ?? ''),
            maxPdfBytes: 20 * 1024 * 1024,
            dataRegion: $region,
            residencyLabel: $region === 'eu' ? 'EU (Azure OpenAI)' : 'US (Azure OpenAI)',
            supportsPdfDocumentBlock: false,
            requiresStructuredOutputJsonMode: true,
        );
    }

    /**
     * OpenAI (přímé API) descriptor. EU jen když `openai_base_url` je EU
     * data-residency endpoint (eu.api.openai.com), jinak us.
     */
    public static function openai(?string $baseUrl, ?string $defaultModel): self
    {
        $region = self::openaiRegion($baseUrl);
        return new self(
            id: 'openai',
            label: 'OpenAI',
            models: self::OPENAI_MODELS,
            defaultModel: ($defaultModel !== null && $defaultModel !== '') ? $defaultModel : self::OPENAI_DEFAULT_MODEL,
            maxPdfBytes: 20 * 1024 * 1024,
            dataRegion: $region,
            residencyLabel: $region === 'eu' ? 'EU (OpenAI)' : 'US (OpenAI)',
            supportsPdfDocumentBlock: false,
            requiresStructuredOutputJsonMode: true,
        );
    }

    /**
     * Google Gemini (AI Studio přímé API) descriptor. v1: přímé API = us
     * (EU jen přes Vertex regional endpoint, mimo rozsah v1).
     */
    public static function gemini(?string $defaultModel): self
    {
        $resolvedDefault = in_array($defaultModel, self::GEMINI_MODELS, true)
            ? $defaultModel
            : self::GEMINI_DEFAULT_MODEL;
        return new self(
            id: 'gemini',
            label: 'Google Gemini',
            models: self::GEMINI_MODELS,
            defaultModel: $resolvedDefault,
            maxPdfBytes: 20 * 1024 * 1024,
            dataRegion: 'us',
            residencyLabel: 'US (Gemini)',
            supportsPdfDocumentBlock: false,
            requiresStructuredOutputJsonMode: true,
        );
    }

    /**
     * Provider-specifický upgrade na silnější model (haiku/fable→sonnet, mini/nano→full,
     * flash/lite→pro). Vrací null, když upgrade nedává smysl (už je na silném modelu,
     * provider upgrade nemá, nebo current je null). Upgrade zůstává ve STEJNÉM
     * regionu — router ho vynucuje přes ResidencyPolicy (§3.5).
     */
    public function strongerModel(?string $current): ?string
    {
        if ($current === null) {
            return null;
        }
        return match ($this->id) {
            'anthropic'    => self::hasAnyToken($current, ['haiku', 'fable']) ? $this->firstModelContaining('sonnet') : null,
            'openai'       => self::hasAnyToken($current, ['mini', 'nano']) ? $this->firstStrongModel(['mini', 'nano']) : null,
            'azure_openai' => self::hasAnyToken($current, ['mini', 'nano']) ? $this->firstStrongModel(['mini', 'nano']) : null,
            'gemini'       => self::hasAnyToken($current, ['flash', 'lite']) ? $this->firstModelContaining('pro') : null,
            default        => null,
        };
    }

    /**
     * Validace API klíče (null = ok, jinak chybová hláška). Per-provider heuristika.
     */
    public function validateKey(string $key): ?string
    {
        return match ($this->id) {
            'anthropic' => (!str_starts_with($key, 'sk-ant-') || strlen($key) > 256)
                ? 'api_key má neplatný formát (musí začínat "sk-ant-").'
                : null,
            'openai' => (!str_starts_with($key, 'sk-') || strlen($key) < 20 || strlen($key) > 256)
                ? 'api_key má neplatný formát (musí začínat "sk-").'
                : null,
            'gemini' => (strlen($key) < 20 || strlen($key) > 512 || preg_match('/\s/', $key) === 1)
                ? 'api_key má neplatný formát.'
                : null,
            'azure_openai' => ($key === '' || strlen($key) > 256)
                ? 'api_key je povinné.'
                : null,
            default => $key === '' ? 'api_key je povinné.' : null,
        };
    }

    private function firstModelContaining(string $needle): ?string
    {
        foreach ($this->models as $m) {
            if (str_contains($m, $needle)) {
                return $m;
            }
        }
        return null;
    }

    /** @param list<string> $tokens */
    private static function hasAnyToken(string $model, array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (str_contains($model, $t)) {
                return true;
            }
        }
        return false;
    }

    /**
     * První model whitelistu, který nenese ANI JEDEN ze slabých tokenů (mini/nano).
     * @param list<string> $weakTokens
     */
    private function firstStrongModel(array $weakTokens): ?string
    {
        foreach ($this->models as $m) {
            if (!self::hasAnyToken($m, $weakTokens)) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Odvodí rezidenci Azure endpointu (MEDIUM ze security auditu). Pravidla:
     *  1. Rezidence se počítá JEN pro OVĚŘENÝ Azure host (allowlist suffixů;
     *     shodný s validací při ukládání credentials v AiProviderCredentialsAction).
     *     Neplatný / neAzure host → fail-closed 'us' (crafted `x-swedencentral.attacker.tld`
     *     se sem už nedostane, protože ho odmítne validace endpointu).
     *  2. Pokud hostname nese známý EU Azure region-token → 'eu' (hard signal).
     *  3. Standardní Azure endpointy ({resource}.openai.azure.com) region v hostname
     *     NENESOU → rezidence = ADMINEM DEKLAROVANÝ `ai_data_region` pro jeho vlastní
     *     Azure resource. Host je ověřený Azure host, takže nejde o spoof cizího hostu;
     *     jde o self-attestation regionu vlastního resource (Azure ARM region z URL
     *     zjistit nelze bez management API — pro BYOK je deklarace legitimní mechanismus).
     */
    private static function azureRegion(?string $endpoint, string $declaredRegion = 'us'): string
    {
        $host = strtolower((string) parse_url((string) $endpoint, PHP_URL_HOST));
        $azureSuffixes = ['.openai.azure.com', '.cognitiveservices.azure.com', '.azure-api.net'];
        $isAzureHost = false;
        foreach ($azureSuffixes as $suffix) {
            if ($host !== '' && str_ends_with($host, $suffix)) {
                $isAzureHost = true;
                break;
            }
        }
        if (!$isAzureHost) {
            // Neověřený / neAzure host → fail-closed 'us'.
            return 'us';
        }
        $euTokens = [
            'swedencentral', 'westeurope', 'francecentral', 'germanywestcentral',
            'norwayeast', 'northeurope', 'switzerlandnorth', 'polandcentral',
            'italynorth', 'spaincentral', 'uksouth', 'ukwest',
        ];
        foreach ($euTokens as $t) {
            if (str_contains($host, $t)) {
                return 'eu';
            }
        }
        // Ověřený Azure host bez region-tokenu → deklarovaný region resource.
        return strtolower(trim($declaredRegion)) === 'eu' ? 'eu' : 'us';
    }

    /**
     * OpenAI rezidence z base_url: EXACT-host match (ne substring — `eu.api.openai.com.evil.tld`
     * nesmí projít). host == eu.api.openai.com → eu; host == api.openai.com (nebo prázdné =
     * default) → us; cokoliv jiného → fail-closed us.
     */
    private static function openaiRegion(?string $baseUrl): string
    {
        $base = trim((string) $baseUrl);
        if ($base === '') {
            return 'us'; // default endpoint api.openai.com
        }
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        return $host === 'eu.api.openai.com' ? 'eu' : 'us';
    }
}
