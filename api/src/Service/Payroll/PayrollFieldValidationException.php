<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Validační chyba vázaná na jedno pole těla podmínek pracovního vztahu.
 *
 * Akce ji vrací jako 422 s `details.field` (a případně `details.code`), aby
 * formulář uměl chybu ukázat přímo u pole, které ji způsobilo. Hláška sama
 * zůstává stejná jako u obecného `\InvalidArgumentException`.
 */
final class PayrollFieldValidationException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $field,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array{field:string,code?:string} */
    public function details(): array
    {
        return $this->errorCode === null
            ? ['field' => $this->field]
            : ['field' => $this->field, 'code' => $this->errorCode];
    }

    /**
     * Doplněk chybové obálky pro `Json::error()`: u chyby vázané na pole
     * `details`, u ostatních nic — jejich odpověď zůstává beze změny.
     *
     * @return array<string,mixed>
     */
    public static function responseExtra(\Throwable $e): array
    {
        return $e instanceof self ? ['details' => $e->details()] : [];
    }
}
