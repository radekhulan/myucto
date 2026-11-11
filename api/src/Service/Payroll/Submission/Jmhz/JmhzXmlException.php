<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzXmlException extends \DomainException
{
    /**
     * @param list<array<string,mixed>> $blockers nálezy blokace pro klienta
     *        (jméno a odkaz na opravu, viz {@see JmhzBlockerRemediationPath::payload()})
     * @param array<string,mixed> $context
     */
    public function __construct(
        public readonly string $validationCode,
        string $message,
        public readonly array $blockers = [],
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function clientDetails(): array
    {
        $details = $this->context;
        if ($this->blockers !== []) {
            $details['blockers'] = $this->blockers;
        }

        return $details === [] ? [] : ['details' => $details];
    }
}
