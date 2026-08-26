<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Metadata, která normalizovaný dokument nést nesmí, protože nevznikají
 * z mzdové revize: GUIDy podání a formulářů, čas vyplnění a pořadí balíku.
 * Serializér je dostává zvenčí, aby zůstal deterministický a testovatelný —
 * generování GUID ani čtení hodin uvnitř serializéru by rozbilo bajtovou
 * stabilitu i replay.
 *
 * Nově vytvářené GUIDy se drží RFC 9562 UUIDv7. Opravné podání ale přebírá
 * existující GUID přijatý ČSSZ, který podle XSD může být libovolný kanonický
 * UUID. Na vstupu se přijímají obě velikosti písmen, ven jde vždy verzálka.
 */
final readonly class JmhzSubmissionEnvelope
{
    private const GUID_PATTERN =
        '/^[0-9A-F]{8}-[0-9A-F]{4}-7[0-9A-F]{3}-[0-9A-F]{4}-[0-9A-F]{12}$/D';
    private const REFERENCE_GUID_PATTERN =
        '/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/D';

    /** @param array<int,string> $formGuids klíčem je employment_id */
    private function __construct(
        public string $submissionGuid,
        public array $formGuids,
        public string $filledAt,
        public int $packageOrdinal,
        public int $packageCount,
        public string $productName,
        public string $productVersion,
    ) {}

    /** @param array<array-key,string> $formGuids klíče přicházejí zvenčí a ověřují se */
    public static function create(
        string $submissionGuid,
        array $formGuids,
        string $filledAt,
        string $productName,
        string $productVersion,
        int $packageOrdinal = 1,
        int $packageCount = 1,
    ): self {
        return self::build(
            $submissionGuid,
            $formGuids,
            $filledAt,
            $productName,
            $productVersion,
            $packageOrdinal,
            $packageCount,
            false,
        );
    }

    /** @param array<array-key,string> $formGuids */
    public static function createForExistingSubmission(
        string $submissionGuid,
        array $formGuids,
        string $filledAt,
        string $productName,
        string $productVersion,
        int $packageOrdinal = 1,
        int $packageCount = 1,
    ): self {
        return self::build(
            $submissionGuid,
            $formGuids,
            $filledAt,
            $productName,
            $productVersion,
            $packageOrdinal,
            $packageCount,
            true,
        );
    }

    /** @param array<array-key,string> $formGuids */
    private static function build(
        string $submissionGuid,
        array $formGuids,
        string $filledAt,
        string $productName,
        string $productVersion,
        int $packageOrdinal,
        int $packageCount,
        bool $existingReferences,
    ): self {
        $normalizedSubmission = self::guid(
            $submissionGuid,
            'podání',
            $existingReferences,
        );
        $normalizedForms = [];
        foreach ($formGuids as $employmentId => $guid) {
            if (!is_int($employmentId) || $employmentId <= 0) {
                throw new JmhzXmlException(
                    'jmhz_envelope_form_guid_unbound',
                    'GUID formuláře musí být svázaný s konkrétním pracovním vztahem.',
                );
            }
            $normalizedForms[$employmentId] = self::guid(
                $guid,
                'formuláře',
                $existingReferences,
            );
        }
        // Sdílený GUID by z podání a jeho součásti udělal tentýž záznam;
        // duplicitu přijatého podání přitom nelze nikdy zopakovat.
        $all = [$normalizedSubmission, ...array_values($normalizedForms)];
        if (count(array_unique($all)) !== count($all)) {
            throw new JmhzXmlException(
                'jmhz_envelope_guid_not_unique',
                'GUID podání a všech formulářů musí být navzájem různé.',
            );
        }
        if ($packageOrdinal < 1
            || $packageCount < 1
            || $packageOrdinal > $packageCount
            || $packageCount > 999
        ) {
            throw new JmhzXmlException(
                'jmhz_envelope_package_invalid',
                'Pořadí a počet balíků dat musí být v rozsahu 1 až 999.',
            );
        }
        if ($productName === '' || $productVersion === '') {
            throw new JmhzXmlException(
                'jmhz_envelope_vendor_invalid',
                'Identifikace odesílajícího software nesmí být prázdná.',
            );
        }

        return new self(
            $normalizedSubmission,
            $normalizedForms,
            self::filledAt($filledAt),
            $packageOrdinal,
            $packageCount,
            $productName,
            $productVersion,
        );
    }

    public function formGuid(?int $employmentId): string
    {
        $guid = $employmentId === null
            ? null
            : ($this->formGuids[$employmentId] ?? null);
        if ($guid === null) {
            throw new JmhzXmlException(
                'jmhz_envelope_form_guid_missing',
                'Pro pracovní vztah v dokumentu chybí GUID formuláře.',
            );
        }

        return $guid;
    }

    private static function guid(
        string $value,
        string $subject,
        bool $existingReference,
    ): string
    {
        $normalized = strtoupper($value);
        $pattern = $existingReference
            ? self::REFERENCE_GUID_PATTERN
            : self::GUID_PATTERN;
        if (preg_match($pattern, $normalized) !== 1) {
            throw new JmhzXmlException(
                'jmhz_envelope_guid_invalid',
                $existingReference
                    ? "GUID {$subject} musí být kanonický UUID."
                    : "GUID {$subject} musí být UUIDv7 podle RFC 9562.",
            );
        }

        return $normalized;
    }

    /**
     * `datumVyplneni` je `xs:dateTime` a musí být shodné napříč dílčími balíky
     * téhož hlášení, zato unikátní mezi hlášeními. Přijímá se proto jen přesný
     * kanonický UTC tvar, ne cokoli, co `DateTimeImmutable` spolkne.
     */
    private static function filledAt(string $value): string
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC'),
        );
        if (!$parsed instanceof \DateTimeImmutable
            || $parsed->format('Y-m-d\TH:i:s\Z') !== $value
        ) {
            throw new JmhzXmlException(
                'jmhz_envelope_filled_at_invalid',
                'Datum a čas vyplnění musí být v kanonickém tvaru RRRR-MM-DDTHH:MM:SSZ.',
            );
        }

        return $value;
    }
}
