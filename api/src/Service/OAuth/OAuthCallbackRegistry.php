<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/** Handlery společného OAuth callbacku podle domény; nová doména se registruje v Bootstrapu. */
final class OAuthCallbackRegistry
{
    /** @var array<string,OAuthCallbackHandler> */
    private array $handlers = [];

    /** @param iterable<OAuthCallbackHandler> $handlers */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            if (isset($this->handlers[$handler->domain()])) {
                throw new \LogicException('Doména OAuth callbacku je registrovaná dvakrát: ' . $handler->domain());
            }
            $this->handlers[$handler->domain()] = $handler;
        }
    }

    public function get(string $domain): ?OAuthCallbackHandler
    {
        return $this->handlers[$domain] ?? null;
    }
}
