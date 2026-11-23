<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Service\Tax\Return\TaxReturnException;

/**
 * Připravenost DPPO 504 po krocích: podklady a posouzení, právní profil, jejich shoda
 * a údaje pro přiznání.
 *
 * Výpočet (NonprofitDppoDataProvider::gather) zůstává jediným zdrojem pravdy a končí
 * na první chybě. Připravenost z něj převezme, které kroky splnil a na kterém skončil.
 * Krok za chybou se dopočítá jen tehdy, když na předchozích nezávisí a nevyžaduje další
 * průchod deníku (potvrzený právní profil); ostatní zůstanou nevyhodnocené.
 */
final readonly class NonprofitDppoReadiness
{
    /** Pořadí kroků odpovídá pořadí kontrol ve výpočtu. */
    public const STEPS = ['sources', 'legal_profile', 'profile_match', 'return_data'];

    /** Chyby, které nepatří žádnému kroku: období nebo rámec, nad kterými se DPPO 504 nepočítá vůbec. */
    private const GENERAL = ['nonprofit_dppo_period_required', 'nonprofit_legal_profile_framework_required', 'nonprofit_legal_profile_period_not_found'];

    /**
     * Chyby, jejichž krok nejde odvodit z pořadí: legacy cesta ověřuje profil před posouzením
     * a neplatný uložený profil (NonprofitDppoLegalRules, důkaz profilu) se ozve už při
     * sestavení podkladů, protože ho čte i otisk a legacy snímek.
     */
    private const CODE_STEPS = ['nonprofit_dppo_legal_profile_required' => 'legal_profile', 'nonprofit_dppo_legal_profile_invalid' => 'legal_profile',
        'nonprofit_legal_profile_invalid' => 'legal_profile', 'nonprofit_legal_profile_proof_invalid' => 'legal_profile',
        'nonprofit_dppo_profile_conflict' => 'profile_match'];

    public function __construct(private Connection $db, private NonprofitDppoDataProvider $provider) {}

    /** Kroky bez vyhodnocení (profil čtený bez kontroly připravenosti). */
    public static function pending(): array
    {
        return array_map(static fn (string $step): array => ['step' => $step, 'status' => 'not_evaluated', 'code' => null, 'message' => null], self::STEPS);
    }

    /**
     * @return array{ready: bool, steps: list<array{step:string, status:string, code:?string, message:?string}>, findings: list<array{code:string, message:string}>}
     */
    public function check(int $supplierId, array $period): array
    {
        $passed = [];
        try {
            // Bez vstupů přiznání: krok return_data ověřuje podklady výpočtu (návaznost na výkazy, ř. 62).
            // Úpravy, dary a snížení zadané v přiznání ověří výpočet přiznání v jeho vybrané variantě,
            // kde se také opravují (TaxReturnService::compute).
            $this->provider->gather($supplierId, (int) $period['fiscal_year'], [], static function (string $step) use (&$passed): void {
                $passed[$step] = true;
            });
            return ['ready' => true, 'steps' => array_map(static fn (string $step): array => ['step' => $step, 'status' => 'ok', 'code' => null, 'message' => null], self::STEPS), 'findings' => []];
        } catch (TaxReturnException | NonprofitTaxAssessmentException $error) {
            $failure = ['code' => $error->errorCode, 'message' => $error->getMessage()];
        }
        return self::afterFailure($passed, $failure, fn (string $step): array|false|null => match ($step) {
            'sources' => $this->assessment($supplierId, (int) $period['id']),
            'legal_profile' => $this->legalProfile($supplierId, (int) $period['id']),
            default => false,
        });
    }

    /**
     * Kroky po chybě výpočtu. Splněné kroky jsou v pořádku, chybný krok nese chybu výpočtu
     * a za ním se dopočítá jen to, co umí $independent bez dalšího průchodu deníku
     * (null = v pořádku / nevyhodnoceno, pole = zjištění, false = kontrolu nejde udělat).
     *
     * @param array<string,true> $passed
     * @param array{code:string, message:string} $failure
     * @param callable(string): (array{code:string, message:string}|false|null) $independent
     */
    public static function afterFailure(array $passed, array $failure, callable $independent): array
    {
        $steps = self::pending();
        if (in_array($failure['code'], self::GENERAL, true)) return ['ready' => false, 'steps' => $steps, 'findings' => [$failure]];
        // Krok podle kódu chyby: legacy cesta kontroluje právní profil dřív než posouzení. Už splněný krok
        // chybu nedostane (stejný kód může přijít i z pozdější kontroly údajů pro přiznání).
        $mapped = self::CODE_STEPS[$failure['code']] ?? null;
        $failed = $mapped !== null && !isset($passed[$mapped]) ? $mapped : current(array_diff(self::STEPS, array_keys($passed)));
        $findings = [$failure];
        foreach ($steps as &$step) {
            if ($step['step'] === $failed) {
                $step = ['step' => $failed, 'status' => 'failed'] + $failure;
                continue;
            }
            if (isset($passed[$step['step']])) {
                $step['status'] = 'ok';
                continue;
            }
            $finding = $independent($step['step']);
            if ($finding === false) continue;
            $step = ['step' => $step['step'], 'status' => $finding === null ? ($step['step'] === 'sources' ? 'not_evaluated' : 'ok') : 'failed',
                'code' => $finding['code'] ?? null, 'message' => $finding['message'] ?? null];
            if ($finding !== null && !in_array($finding['code'], array_column($findings, 'code'), true)) $findings[] = $finding;
        }
        unset($step);
        return ['ready' => false, 'steps' => $steps, 'findings' => $findings];
    }

    /**
     * Chybějící potvrzené posouzení bez průchodu podklady. Existující posouzení tu nejde
     * prohlásit za aktuální (to ověří až otisk ve výpočtu), proto null = nevyhodnoceno.
     */
    private function assessment(int $supplierId, int $periodId): ?array
    {
        if (NonprofitDppoAssessmentSource::confirmed((new NonprofitTaxAssessmentRepository($this->db))->latest($supplierId, $periodId))) return null;
        $error = NonprofitDppoAssessmentSource::assessmentRequired();
        return ['code' => $error->errorCode, 'message' => $error->getMessage()];
    }

    /** Potvrzený právní profil období stejně, jak ho čte výpočet; null = v pořádku. */
    private function legalProfile(int $supplierId, int $periodId): ?array
    {
        try {
            $context = (new NonprofitLegalProfileService($this->db))->assessmentContext($supplierId, $periodId);
        } catch (TaxReturnException | NonprofitTaxAssessmentException $error) {
            return ['code' => $error->errorCode, 'message' => $error->getMessage()];
        }
        if (isset($context['legal_profile'])) return null;
        $error = NonprofitDppoDataProvider::legalProfileRequired();
        return ['code' => $error->errorCode, 'message' => $error->getMessage()];
    }

}
