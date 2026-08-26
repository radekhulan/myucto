<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

final readonly class PayrollVerifiedReceiptFormError
{
    public function __construct(
        public int $code,
        public string $message,
        public string $origin,
        public ?int $controlId,
    ) {
        if ($code <= 0) {
            throw new \InvalidArgumentException('Kód chyby formuláře musí být kladný.');
        }
        if (!in_array($origin, ['platform', 'dis', 'cjmhz'], true)) {
            throw new \InvalidArgumentException('Původ chyby formuláře není podporovaný.');
        }
        if ($controlId !== null && $controlId <= 0) {
            throw new \InvalidArgumentException('ID kontroly formuláře musí být kladné.');
        }
    }

    /** @return array{code:int,message:string,origin:string,control_id:?int} */
    public function fingerprintData(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'origin' => $this->origin,
            'control_id' => $this->controlId,
        ];
    }
}
