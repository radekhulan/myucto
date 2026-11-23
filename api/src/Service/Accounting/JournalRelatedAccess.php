<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Co smí volající v panelu „Souvisí“ a v odznaku deníku vidět z agend s vlastním právem.
 *
 * Ostatní položky jsou samostatná agenda (právo other_items). Bez něj graf hranu na
 * položku vůbec nevrátí, ani pro odznak. Úhrady položky (bankovní pohyb, pokladní
 * doklad) vrací jen s právem bank, resp. cash. Hrany faktur a jejich úhrad se tím
 * neřídí, u nich proklik hlídá frontend podle pole `permission`.
 */
final readonly class JournalRelatedAccess
{
    public function __construct(
        public bool $otherItems,
        public bool $bank,
        public bool $cash,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self(
            RequestAuthorization::allows($request, 'other_items', AccessLevel::READ),
            RequestAuthorization::allows($request, 'bank', AccessLevel::READ),
            RequestAuthorization::allows($request, 'cash', AccessLevel::READ),
        );
    }

    public static function all(): self
    {
        return new self(true, true, true);
    }

    /** Smí vidět úhradu ostatní položky daného druhu ('bank' | 'cash')? */
    public function seesPayment(string $kind): bool
    {
        return $kind === 'bank' ? $this->bank : ($kind === 'cash' && $this->cash);
    }
}
