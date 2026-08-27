<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Jediný seznam registračních interakcí, které tenhle core rozpoznává.
 *
 * Připnuté REGZEC25 XSD povoluje `employee/@act` v rozsahu 1..99, takže tento
 * katalog je hranice známých interakcí. Jejich aktuální business způsobilost
 * samostatně vynucuje `PayrollRegistrationBusinessMatrix`.
 */
final readonly class PayrollRegistrationInteraction
{
    /** @var array<string,array{document_type:string,action_code:int}> */
    public const SUPPORTED = [
        'limited_pre_registration' => [
            'document_type' => 'PREZEC26',
            'action_code' => 9,
        ],
        'pre_registration_no_show' => [
            'document_type' => 'PREZEC26',
            'action_code' => 10,
        ],
        'direct_full_registration' => [
            'document_type' => 'REGZEC25',
            'action_code' => 1,
        ],
        'full_registration_after_p1' => [
            'document_type' => 'REGZEC25',
            'action_code' => 1,
        ],
        'termination' => [
            'document_type' => 'REGZEC25',
            'action_code' => 2,
        ],
        'change' => [
            'document_type' => 'REGZEC25',
            'action_code' => 3,
        ],
        'correction' => [
            'document_type' => 'REGZEC25',
            'action_code' => 4,
        ],
        'variable_symbol_transfer' => [
            'document_type' => 'REGZEC25',
            'action_code' => 5,
        ],
        'czech_legislation_start' => [
            'document_type' => 'REGZEC25',
            'action_code' => 6,
        ],
        'czech_legislation_end' => [
            'document_type' => 'REGZEC25',
            'action_code' => 7,
        ],
        'cancellation' => [
            'document_type' => 'REGZEC25',
            'action_code' => 8,
        ],
    ];

    public function __construct(
        public string $documentType,
        public string $interaction,
        public int $actionCode,
    ) {}

    public static function isSupported(
        string $documentType,
        string $interaction,
        int $actionCode,
    ): bool {
        $definition = self::SUPPORTED[$interaction] ?? null;

        return $definition !== null
            && $definition['document_type'] === $documentType
            && $definition['action_code'] === $actionCode;
    }

    public function supported(): bool
    {
        return self::isSupported(
            $this->documentType,
            $this->interaction,
            $this->actionCode,
        );
    }

    /** @return list<int> */
    public static function actionsFor(string $documentType): array
    {
        $actions = [];
        foreach (self::SUPPORTED as $definition) {
            if ($definition['document_type'] === $documentType) {
                $actions[$definition['action_code']] = true;
            }
        }
        $result = array_map('intval', array_keys($actions));
        sort($result, SORT_NUMERIC);

        return $result;
    }
}
