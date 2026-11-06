<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzXmlException extends \DomainException
{
    /**
     * @param list<array<string,mixed>> $blockers nálezy blokace pro klienta
     *        (jméno a odkaz na opravu, viz {@see JmhzBlockerRemediationPath::payload()})
     */
    public function __construct(
        public readonly string $validationCode,
        string $message,
        public readonly array $blockers = [],
    ) {
        parent::__construct($message);
    }
}
