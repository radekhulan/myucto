<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

/**
 * Rozpracovaná vícekolová konverzace s nástroji (AI asistent). Zprávy jsou v nativním
 * tvaru poskytovatele a jen se přidávají na konec: odpověď modelu (včetně bloků
 * přemýšlení a podpisů) se vrací beze změny, jinak by ji poskytovatel v dalším kole
 * odmítl. Tvar zpráv i požadavku řeší {@see AiProviderHttpClient}.
 */
final class AiToolConversation
{
    /** @var list<array<string,mixed>> */
    public array $messages = [];

    /**
     * @param list<array{name:string, description:string, parameters:array<string,mixed>}> $tools
     * @param array<string,mixed> $schema schéma závěrečné odpovědi
     */
    public function __construct(
        public readonly int $supplierId,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $system,
        public readonly array $tools,
        public readonly array $schema,
        public readonly int $maxOutputTokens,
    ) {}
}
