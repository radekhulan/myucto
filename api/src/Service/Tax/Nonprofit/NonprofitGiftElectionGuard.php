<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Repository\NonprofitTaxAssessmentRepository;

/**
 * Volba osvobození daru (`claim_exemption`) je po potvrzení posouzení nevratná: daň
 * z daru se jednou rozhodnutou volbou řídí i v dalších letech. Platí pro každé potvrzení
 * posouzení bez ohledu na cestu (původní posouzení s řádky v JSON i stránkované J6),
 * proto je kontrola tady a volají ji obě. Dřív žila jako privátní metoda původní služby
 * a stránkované potvrzení ji obcházelo (#399).
 *
 * Dar se páruje podle původního řádku deníku (`origin.line_id`), takže rozdělený dar je
 * jeden dar: všechny jeho části musí mít stejný právní titul i volbu.
 */
final readonly class NonprofitGiftElectionGuard
{
    public function __construct(
        private NonprofitTaxAssessmentRepository $assessments,
        private J6FundingProjection $funding,
    ) {}

    /**
     * @param iterable<array{origin:int, title:string, claim_exemption:mixed}> $current řádky
     *        potvrzované revize
     * @param ?int $currentId id potvrzované revize, pokud už je uložená v historii
     *
     * @throws NonprofitTaxAssessmentException nonprofit_tax_gift_election_locked
     */
    public function assert(int $supplierId, iterable $current, ?int $currentId = null): void
    {
        $elections = [];
        foreach ($this->assessments->confirmedHistory($supplierId, true) as $revision) {
            if ($revision['status'] !== 'confirmed' || ($currentId !== null && (int) $revision['id'] === $currentId)) continue;
            foreach ($this->rowsOf($revision) as $row) {
                if ($row['title'] !== 'gift') continue;
                if (isset($elections[$row['origin']]) && $elections[$row['origin']] !== $row['claim_exemption']) self::locked();
                $elections[$row['origin']] = $row['claim_exemption'];
            }
        }
        $gifts = [];
        $others = [];
        foreach ($current as $row) {
            $origin = $row['origin'];
            if (isset($elections[$origin]) && ($row['title'] !== 'gift' || $row['claim_exemption'] !== $elections[$origin])) self::locked();
            if ($row['title'] !== 'gift') {
                $others[$origin] = true;
                continue;
            }
            if (isset($gifts[$origin]) && $gifts[$origin] !== $row['claim_exemption']) self::inconsistent();
            $gifts[$origin] = $row['claim_exemption'];
        }
        if (array_intersect_key($gifts, $others) !== []) self::inconsistent();
    }

    /**
     * Řádky uložené revize (původní i stránkované) v jednotném tvaru.
     *
     * @param array<string,mixed> $revision
     * @return \Generator<array{origin:int, title:string, claim_exemption:mixed}>
     */
    public function rowsOf(array $revision): \Generator
    {
        // Stránkovaná revize nemá řádky v JSON; přečte je stejná projekce jako financování.
        foreach ($this->funding->resultPages($revision) as $rows) {
            foreach ($rows as $row) {
                // Řádek bez původu (starší revize, ne dar) se s žádným darem nepáruje.
                $origin = (int) ($row['origin']['line_id'] ?? 0);
                if ($origin === 0) continue;
                yield [
                    'origin' => $origin,
                    'title' => (string) ($row['decision']['title'] ?? ''),
                    'claim_exemption' => $row['decision']['facts']['claim_exemption'] ?? null,
                ];
            }
        }
    }

    private static function locked(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_gift_election_locked', 'Potvrzenou volbu osvobození daru nelze zpětně změnit.', 409);
    }

    private static function inconsistent(): never
    {
        NonprofitTaxRules::invalid('Části téhož daru musí mít shodnou volbu osvobození a právní titul.');
    }
}
