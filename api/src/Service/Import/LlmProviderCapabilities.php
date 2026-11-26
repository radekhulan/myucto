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
    public const ANTHROPIC_DEFAULT_MODEL = 'claude-haiku-5-5';
    public const ANTHROPIC_SONNET_MODEL = 'claude-sonnet-5-5';
    public const ANTHROPIC_MODELS = [
        self::ANTHROPIC_DEFAULT_MODEL,
        self::ANTHROPIC_SONNET_MODEL,
        'claude-opus-5-5',
        'claude-fable-5-2',
        'claude-fable-5-1',
        'claude-fable-5',
        'claude-opus-5',
        'claude-opus-4-8',
        'claude-opus-4-7',
        'claude-opus-4-6',
        'claude-sonnet-5',
        'claude-sonnet-4-6',
        'claude-haiku-4-5-20251001',
    ];

    public const OPENAI_DEFAULT_MODEL = 'gpt-6-luna';
    private const OPENAI_ASSISTANT_DEFAULT_MODEL = 'gpt-6.2-sol';
    public const OPENAI_MODELS = [
        self::OPENAI_DEFAULT_MODEL,
        self::OPENAI_ASSISTANT_DEFAULT_MODEL,
        'gpt-6.2-astra',
        'gpt-6.1-terra',
        'gpt-5.4-mini',
        'gpt-6.1-sol',
        'gpt-6-sol',
        'gpt-6-astra',
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-5.5',
        'gpt-5.4',
        'gpt-5.2',
    ];

    public const GEMINI_DEFAULT_MODEL = 'gemini-3.5-flash-lite';
    private const GEMINI_ASSISTANT_DEFAULT_MODEL = 'gemini-3.8-flash';
    public const GEMINI_MODELS = [
        self::GEMINI_DEFAULT_MODEL,
        self::GEMINI_ASSISTANT_DEFAULT_MODEL,
        'gemini-3.6-flash',
        'gemini-4-hydrogen',
        'gemini-4-carbon',
        'gemini-4-neon',
        'gemini-4-argon',
        'gemini-3.1-flash-lite',
        'gemini-2.5-flash-lite',
        'gemini-3.1-pro-preview',
        'gemini-3-flash-preview',
    ];

    /**
     * Volba „rychle vs. přesně" (per tenant, sloupec `supplier.ai_effort`).
     * `default` = neposílat providerovi nic navíc, tedy chování před zavedením volby.
     */
    public const EFFORT_DEFAULT  = 'default';
    public const EFFORT_FAST     = 'fast';
    public const EFFORT_ACCURATE = 'accurate';
    public const EFFORTS = [self::EFFORT_DEFAULT, self::EFFORT_FAST, self::EFFORT_ACCURATE];

    private const MODEL_RETIREMENT_DATES = [
        'anthropic' => [
            'claude-sonnet-4-5-20250929' => '2026-11-30',
            'claude-opus-4-5-20251101' => '2027-01-22',
        ],
        'gemini' => ['gemini-3.1-flash-lite' => '2027-05-07'],
    ];

    /**
     * Stupně eskalace od nejslabšího po nejsilnější. Při shodě více tokenů
     * vyhrává první stupeň (např. flash-lite patří k lite). null je stupeň
     * pro modely bez odpovídajícího tokenu.
     *
     * @var array<string, list<list<string>|null>>
     */
    private const ESCALATION_LADDERS = [
        'anthropic'    => [['haiku'], ['sonnet'], ['opus'], ['fable']],
        'openai'       => [['nano'], ['mini', 'luna', 'terra'], null, ['astra']],
        'azure_openai' => [['nano'], ['mini'], null],
        'gemini'       => [['lite'], ['flash', 'hydrogen', 'argon'], ['pro', 'carbon', 'neon']],
    ];

    public function __construct(
        public string $id,               // 'anthropic' | 'azure_openai' | 'openai' | 'gemini' | 'ollama'
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

    public static function anthropic(string $dataRegion = 'us', ?string $defaultModel = null): self
    {
        $model = self::resolveModel('anthropic', $defaultModel);
        return new self(
            id: 'anthropic',
            label: 'Anthropic Claude',
            models: self::withConfiguredModel(self::ANTHROPIC_MODELS, $model, self::isAnthropicPdfModel($model)),
            defaultModel: $model,
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
        $model = self::resolveModel('openai', $defaultModel);
        return new self(
            id: 'openai',
            label: 'OpenAI',
            models: self::withConfiguredModel(self::OPENAI_MODELS, $model, self::isOpenaiPdfModel($model)),
            defaultModel: $model,
            maxPdfBytes: 20 * 1024 * 1024,
            dataRegion: $region,
            residencyLabel: $region === 'eu' ? 'EU (OpenAI)' : 'US (OpenAI)',
            supportsPdfDocumentBlock: false,
            requiresStructuredOutputJsonMode: true,
        );
    }

    public static function isOpenaiPdfModel(string $model): bool
    {
        return strlen($model) <= 128
            && preg_match('/\Agpt-(?:4(?:\.1|o)|(?:[5-9]|[1-9]\d+)(?:\.\d+)?)(?:-(?:astra|sol|terra|luna|mini|nano))?(?:-\d{4}-\d{2}-\d{2})?\z/', $model) === 1;
    }

    public static function isAnthropicPdfModel(string $model, ?string $today = null): bool
    {
        return strlen($model) <= 64
            && preg_match('/\Aclaude-(?:haiku|sonnet|opus|fable)-(?:[4-9]|[1-9]\d+)(?:-\d{1,2})?(?:-\d{8})?\z/', $model) === 1
            && !self::isRetiredModel('anthropic', $model, $today);
    }

    public static function isGeminiPdfModel(string $model, ?string $today = null): bool
    {
        return self::geminiPdfModelId($model) && !self::isRetiredModel('gemini', $model, $today);
    }

    private static function geminiPdfModelId(string $model): bool
    {
        return strlen($model) <= 128
            && preg_match('/\Agemini-(?:(?:2\.5|3(?:\.\d+)?)-(?:flash(?:-lite)?|pro)|4-(?:carbon|neon|hydrogen|argon))(?:-preview(?:-\d{2}-\d{2})?)?\z/', $model) === 1;
    }

    public static function resolveModel(string $provider, ?string $model, ?string $today = null, bool $forAssistant = false): string
    {
        $defaultModel = match ($provider) {
            'anthropic' => $forAssistant ? self::ANTHROPIC_SONNET_MODEL : self::ANTHROPIC_DEFAULT_MODEL,
            'openai' => $forAssistant ? self::OPENAI_ASSISTANT_DEFAULT_MODEL : self::OPENAI_DEFAULT_MODEL,
            'gemini' => $forAssistant ? self::GEMINI_ASSISTANT_DEFAULT_MODEL : self::GEMINI_DEFAULT_MODEL,
            default => '',
        };
        if ($model === null || $model === '') {
            return $defaultModel;
        }
        $today ??= gmdate('Y-m-d');
        return self::isRetiredModel($provider, $model, $today)
            ? self::retiredModelReplacement($provider, $model, $defaultModel, $today, $forAssistant)
            : $model;
    }

    private static function isRetiredModel(string $provider, string $model, ?string $today): bool
    {
        $date = self::MODEL_RETIREMENT_DATES[$provider][$model] ?? null;
        if ($date !== null && ($today ?? gmdate('Y-m-d')) >= $date) {
            return true;
        }
        return match ($provider) {
            'anthropic' => preg_match('/\Aclaude-(?:3(?:[-.]|$)|opus-4-1(?:-|$)|(?:opus|sonnet)-4-20250514\z)/', $model) === 1,
            'openai' => preg_match('/\A(?:o1(?:-|$)|o[34]-mini(?:-|$)|gpt-(?:4|3\.5-turbo)(?:-|$))/', $model) === 1
                || preg_match('/-(?:codex(?:-|$)|chat-latest\z)/', $model) === 1
                || preg_match('/\Agpt-5\.[245]-pro(?:-\d{4}-\d{2}-\d{2})?\z/', $model) === 1,
            'gemini' => preg_match('/\Agemini-(?:2\.0(?:-|$)|2\.5-(?:pro|flash)(?:\z|-preview(?:-|$))|3-(?:flash\z|pro-preview(?:-|$))|3\.1-flash-lite-preview(?:-|$)|3\.[57]-flash\z)/', $model) === 1,
            default => false,
        };
    }

    private static function retiredModelReplacement(string $provider, string $model, string $defaultModel, string $today, bool $forAssistant): string
    {
        if ($provider === 'gemini' && !$forAssistant) {
            return $defaultModel;
        }
        $catalog = match ($provider) {
            'anthropic' => self::ANTHROPIC_MODELS,
            'openai' => self::OPENAI_MODELS,
            'gemini' => self::GEMINI_MODELS,
        };
        $ladder = self::ESCALATION_LADDERS[$provider];
        $tier = self::tierIndex($model, $ladder);
        if ($tier === null || $ladder[$tier] === null) {
            return $defaultModel;
        }
        foreach ($catalog as $candidate) {
            if (self::tierIndex($candidate, $ladder) === $tier && !self::isRetiredModel($provider, $candidate, $today)) {
                return $candidate;
            }
        }
        return $defaultModel;
    }

    /**
     * @param list<string> $catalog
     * @return list<string>
     */
    private static function withConfiguredModel(array $catalog, string $model, bool $supported): array
    {
        if ($supported && !in_array($model, $catalog, true)) {
            $catalog[] = $model;
        }
        return $catalog;
    }

    /**
     * Google Gemini (AI Studio přímé API) descriptor. v1: přímé API = us
     * (EU jen přes Vertex regional endpoint, mimo rozsah v1).
     */
    public static function gemini(?string $defaultModel): self
    {
        $model = self::resolveModel('gemini', $defaultModel);
        $catalog = array_values(array_filter(self::GEMINI_MODELS, self::isGeminiPdfModel(...)));
        return new self(
            id: 'gemini',
            label: 'Google Gemini',
            models: self::withConfiguredModel($catalog, $model, self::isGeminiPdfModel($model)),
            defaultModel: $model,
            maxPdfBytes: 20 * 1024 * 1024,
            dataRegion: 'us',
            residencyLabel: 'US (Gemini)',
            supportsPdfDocumentBlock: false,
            requiresStructuredOutputJsonMode: true,
        );
    }

    /**
     * Lokální Ollama descriptor. Model vybírá admin firmy ze seznamu toho, co Ollama
     * na stroji má (`/api/tags`), takže whitelist = jen ten jeden. Region dodává
     * {@see \MyInvoice\Service\Ai\OllamaEndpointGuard} z resolvované IP; cokoli jiného
     * než 'eu' je fail-closed 'us'.
     */
    public static function ollama(?string $model, string $region): self
    {
        $model  = trim((string) $model);
        $region = $region === 'eu' ? 'eu' : 'us';
        return new self(
            id: 'ollama',
            label: 'Ollama',
            models: $model !== '' ? [$model] : [],
            defaultModel: $model,
            maxPdfBytes: 20 * 1024 * 1024,
            dataRegion: $region,
            residencyLabel: $region === 'eu' ? 'Lokální (Ollama)' : 'Vzdálená (Ollama)',
            supportsPdfDocumentBlock: false,
            requiresStructuredOutputJsonMode: true,
        );
    }

    /**
     * Další stupeň na žebříčku {@see ESCALATION_LADDERS} — co použít, když extrakce
     * na aktuálním modelu neprojde. Anthropic jde haiku → sonnet → opus → fable,
     * OpenAI levné → běžné → Astra, Gemini Lite → Flash → Pro nebo Carbon.
     *
     * Přeskočí stupeň, který v tenantově whitelistu nemá žádný model, takže eskalace
     * nespadne jen proto, že prostřední tier chybí. Vrací null, když upgrade nedává
     * smysl (už je na vrcholu žebříčku, model mimo žebříček, provider žebříček nemá,
     * nebo current je null). Upgrade zůstává ve STEJNÉM regionu — router ho vynucuje
     * přes ResidencyPolicy (§3.5).
     */
    public function strongerModel(?string $current): ?string
    {
        if ($current === null) {
            return null;
        }
        $ladder = self::ESCALATION_LADDERS[$this->id] ?? null;
        if ($ladder === null) {
            return null;
        }
        $tier = self::tierIndex($current, $ladder);
        if ($tier === null) {
            return null;
        }
        for ($i = $tier + 1, $n = count($ladder); $i < $n; $i++) {
            $candidate = $this->firstModelInTier($ladder, $i);
            if ($candidate !== null) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Přeloží volbu rychle/přesně na provider-nativní fragment payloadu. Vrací prázdné
     * pole, když se nemá poslat nic — buď je volba `default`, nebo daný model knob
     * neumí. To druhé je podstatné: poslat ho modelu, který ho nezná, je tvrdá 400,
     * ne degradace. Ověřeno živě 2026-08-26 (private/scripts/probe_llm_effort.php):
     *
     *   - anthropic  `output_config.effort` — umí celý whitelist KROMĚ claude-haiku-4-5
     *                (ten hlásí „does not support the effort parameter").
     *   - openai     `reasoning_effort` — jen gpt-5 a novější plus řada o*; gpt-4.x
     *                hlásí „Unrecognized request argument".
     *   - gemini     `thinkingConfig` — u Gemini 3 a 4 `thinkingLevel`, u gemini-2.5
     *                `thinkingBudget` (starší API tvar). POZOR: fragment patří dovnitř
     *                `generationConfig`, ne do kořene payloadu.
     *   - azure      nic — deployment může nést libovolný model a rozpoznat ho z názvu
     *                spolehlivě nejde, takže fail-safe mlčíme.
     *
     * @return array<string,mixed>
     */
    public function effortPayload(string $model, string $effort): array
    {
        if ($effort === self::EFFORT_DEFAULT || !in_array($effort, self::EFFORTS, true)) {
            return [];
        }
        $accurate = $effort === self::EFFORT_ACCURATE;

        return match ($this->id) {
            'anthropic' => self::anthropicSupportsEffort($model)
                ? ['output_config' => ['effort' => $accurate ? 'high' : 'low']]
                : [],
            'openai' => self::openaiSupportsReasoningEffort($model)
                ? ['reasoning_effort' => $accurate ? 'high' : 'low']
                : [],
            'gemini' => self::geminiThinkingPayload($model, $accurate ? 'high' : 'low', $accurate ? 8192 : 512),
            // `think` posílá OllamaClient jen modelu, který v /api/show hlásí capability
            // `thinking` — jinak Ollama vrací 400.
            'ollama' => ['think' => $accurate],
            default => [],
        };
    }

    /** @return array<string,array<string,string|int>> */
    public static function geminiThinkingPayload(string $model, string $level = 'low', int $budget = 1024): array
    {
        return match (true) {
            preg_match('/\Agemini-[34](?:[.-]|\z)/', $model) === 1 => ['thinkingConfig' => ['thinkingLevel' => $level]],
            str_starts_with($model, 'gemini-2.5-') => ['thinkingConfig' => ['thinkingBudget' => $budget]],
            default => [],
        };
    }

    /**
     * Rozdíly generací Claude, které mění tvar požadavku (dokumentace Anthropic, ověřeno
     * živě 2026-10-11). Model se rozpozná z rodiny a verze v id (`claude-haiku-5-5`).
     *
     * - `output_config.effort`: neumí Haiku 4.x a starší řada claude-3.
     * - `temperature`: generace 5 ho odmítá (HTTP 400), posílá se jen claude-3 a 4.x.
     * - Přemýšlení zapnuté bez vyžádání: celá generace 5. Odpověď pak může začínat
     *   blokem `thinking` a přemýšlení se počítá do `max_tokens`.
     * - Vynucený `tool_choice` (any/tool) odmítají Opus a Sonnet od 5.5 a Fable/Mythos
     *   od 5.1 — strukturovaný výstup u nich musí jít přes `output_config.format`.
     * - Structured outputs (`output_config.format`) neumí Sonnet 4.6, Opus 4.7/4.6 a starší.
     */
    public static function anthropicSupportsEffort(string $model): bool
    {
        return !str_starts_with($model, 'claude-haiku-4') && !str_starts_with($model, 'claude-3');
    }

    public static function anthropicAcceptsTemperature(string $model): bool
    {
        return preg_match('/^claude-(3|[a-z]+-4)/', $model) === 1;
    }

    public static function anthropicThinksByDefault(string $model): bool
    {
        [$family, $version] = self::anthropicVersion($model);
        return $family !== null && $version >= 5.0;
    }

    public static function anthropicRejectsForcedToolChoice(string $model): bool
    {
        [$family, $version] = self::anthropicVersion($model);
        return match ($family) {
            'opus', 'sonnet' => $version >= 5.5,
            'fable', 'mythos' => $version >= 5.1,
            'haiku' => $version >= 6.0,
            default => false,
        };
    }

    public static function anthropicSupportsStructuredOutputs(string $model): bool
    {
        [$family, $version] = self::anthropicVersion($model);
        return match ($family) {
            'haiku' => $version >= 4.5,
            'sonnet' => $version >= 5.0,
            'opus' => $version >= 4.8,
            'fable', 'mythos' => true,
            default => false,
        };
    }

    /** @return array{0:?string, 1:float} rodina a verze (`claude-opus-4-8` → ['opus', 4.8]) */
    private static function anthropicVersion(string $model): array
    {
        if (preg_match('/^claude-([a-z]+)-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?$/', $model, $m) !== 1) {
            return [null, 0.0];
        }
        return [$m[1], (float) ($m[2] . '.' . ($m[3] ?? '0'))];
    }

    /**
     * gpt-5 a novější (včetně pojmenovaných variant jako gpt-5.6-sol) a řada o1/o3/o4
     * `reasoning_effort` berou; gpt-4.x ne. Verze se porovnává číselně, aby budoucí
     * gpt-6 nespadlo na tom, že nezačíná „gpt-5".
     */
    private static function openaiSupportsReasoningEffort(string $model): bool
    {
        if (preg_match('/^o[1-9]/', $model) === 1) {
            return true;
        }
        if (preg_match('/^gpt-(\d+)(?:\.(\d+))?/', $model, $m) !== 1) {
            return false;
        }
        return (int) $m[1] >= 5;
    }

    /** @param list<list<string>|null> $ladder */
    private static function tierIndex(string $model, array $ladder): ?int
    {
        $fallback = null;
        foreach ($ladder as $i => $tier) {
            if ($tier === null) {
                $fallback = $i;
            } else {
                foreach ($tier as $token) {
                    if (str_contains($model, $token)) {
                        return $i;
                    }
                }
            }
        }
        return $fallback;
    }

    /**
     * První model whitelistu, který patří právě do daného stupně. Whitelist je seřazený
     * nejnovějším napřed, takže v rámci stupně padne volba na aktuální generaci.
     *
     * @param list<list<string>|null> $ladder
     */
    private function firstModelInTier(array $ladder, int $tier): ?string
    {
        foreach ($this->models as $m) {
            if (self::tierIndex($m, $ladder) === $tier) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Validace API klíče (null = ok, jinak chybová hláška). Per-provider heuristika.
     */
    public function validateKey(#[\SensitiveParameter] string $key): ?string
    {
        return match ($this->id) {
            'anthropic' => (!str_starts_with($key, 'sk-ant-') || strlen($key) > 256 || preg_match('/\s/', $key) === 1)
                ? 'api_key má neplatný formát (musí začínat "sk-ant-").'
                : null,
            'openai' => (!str_starts_with($key, 'sk-') || strlen($key) < 20 || strlen($key) > 256 || preg_match('/\s/', $key) === 1)
                ? 'api_key má neplatný formát (musí začínat "sk-").'
                : null,
            'gemini' => (strlen($key) < 20 || strlen($key) > 512 || preg_match('/\s/', $key) === 1)
                ? 'api_key má neplatný formát.'
                : null,
            'azure_openai' => ($key === '' || strlen($key) > 256)
                ? 'api_key je povinné.'
                : null,
            // Klíč je u Ollamy volitelný (Bearer pro reverse proxy); prázdný se sem nedostane.
            'ollama' => (strlen($key) > 512 || preg_match('/\s/', $key) === 1)
                ? 'api_key má neplatný formát.'
                : null,
            default => $key === '' ? 'api_key je povinné.' : null,
        };
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
