<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

final class SicknessException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $extra strojově čitelné upřesnění pro
     *        obrazovku (např. měsíce rozhodného období, ke kterým chybí
     *        podklad), podle něhož nabídne proklik na místo nápravy
     */
    public function __construct(
        public readonly string $validationCode,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Kód, věta a upřesnění jedné chyby tak, jak je obrazovka případů čte
     * v `error.problems`.
     *
     * @return array<string,mixed>
     */
    public function problem(): array
    {
        return ['code' => $this->validationCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
