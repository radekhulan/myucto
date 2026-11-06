<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Co z exekuční a insolvenční agendy smí volající vidět MIMO tu agendu.
 *
 * Agendy samotné hlídá `payroll.enforcement` (a u insolvencí navíc
 * `payroll.insolvency`). Jenže tatáž data protékají i do míst, která čte
 * obecné `payroll` nebo `payroll.documents`: snímek výsledku mzdového běhu,
 * blokátor smazání zaměstnance, podklady zápočtového listu. Tam se nesmí
 * vracet víc, než by volající viděl v agendě samotné. Počet ani existence
 * exekuce není veřejný údaj (viz PayrollEmploymentAgendaSummaryAction).
 *
 * Volající bez oprávnění dostane zbytek odpovědi beze změny; citlivá část se
 * vynechá nebo nahradí obecnou informací, nikdy se kvůli ní neshodí celá
 * obrazovka.
 */
final class PayrollEnforcementDisclosure
{
    public const RESTRICTED_DELETE_BLOCKER = 'payroll_employee_has_restricted_records';
    public const RESTRICTED_DELETE_MESSAGE = 'Na zaměstnance jsou navázané neměnné záznamy agendy, '
        . 'ke které nemáte oprávnění. Smazat ho proto nelze.';
    public const DEDUCTION_PERMISSION_REQUIRED = 'deduction_permission_required';
    public const DEDUCTION_PERMISSION_MESSAGE = 'Zápočtový list obsahuje pokračující srážky, '
        . 'ke kterým nemáte oprávnění. Vystavit ho musí kolega s oprávněním ke srážkám ze mzdy.';

    private const ENFORCEMENT_BLOCKER = 'payroll_employee_has_enforcement';
    private const INSOLVENCY_ALLOCATION = 'insolvency-administrator';

    private function __construct() {}

    public static function canSeeEnforcement(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'payroll.enforcement', AccessLevel::READ);
    }

    /** Insolvenční agenda vyžaduje obě práva, stejně jako navigace a její endpointy. */
    public static function canSeeInsolvency(Request $request): bool
    {
        return self::canSeeEnforcement($request)
            && RequestAuthorization::allows($request, 'payroll.insolvency', AccessLevel::READ);
    }

    /**
     * Snímek výsledku běhu nese u každé osoby blok `enforcement` s celým vstupem
     * výpočtu srážek (pohledávky, vyživovací povinnost, vyživované osoby,
     * insolvenční režim) i jeho rozpadem. Bez práva na agendu zůstane jen
     * celková sražená částka, kterou osoba vidí i na výplatní pásce.
     *
     * S `payroll.enforcement`, ale bez `payroll.insolvency`, se celý blok
     * zúží jen u osob, kterých se insolvence týká; jinak by se z rozpadu
     * (alokace na insolvenčního správce, výtky) dala vyčíst.
     *
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>|null
     */
    public static function redactRunSnapshot(
        ?array $snapshot,
        bool $canSeeEnforcement,
        bool $canSeeInsolvency,
    ): ?array {
        if ($snapshot === null || !is_array($snapshot['people'] ?? null)) {
            return $snapshot;
        }
        if ($canSeeEnforcement && $canSeeInsolvency) {
            return $snapshot;
        }
        $people = $snapshot['people'];
        foreach ($people as $index => $person) {
            if (!is_array($person) || !is_array($person['enforcement'] ?? null)) {
                continue;
            }
            $enforcement = $person['enforcement'];
            if ($canSeeEnforcement && !self::involvesInsolvency($enforcement)) {
                continue;
            }
            $person['enforcement'] = self::enforcementSummary($enforcement);
            $people[$index] = $person;
        }
        $snapshot['people'] = $people;

        return $snapshot;
    }

    /**
     * Seznam běhů nese jen souhrnné `totals`. Součet exekučních srážek je
     * u zaměstnavatele s jedním dlužníkem přímo jeho srážka, proto ho bez
     * `payroll.enforcement` nevracíme. Výplata po srážkách zůstává, je to
     * částka, kterou firma posílá zaměstnancům.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function redactRunTotals(array $snapshot): array
    {
        if (is_array($snapshot['totals'] ?? null)
            && array_key_exists('enforcement_withheld_minor', $snapshot['totals'])
        ) {
            $snapshot['totals']['enforcement_withheld_minor'] = null;
        }

        return $snapshot;
    }

    /**
     * Historie revizí běhu: souhrny revizí i rozdíl proti předchozí revizi.
     * Rozdíl exekučních srážek mezi revizemi ukazuje změnu u konkrétní osoby,
     * takže bez `payroll.enforcement` z rozdílu zmizí a souhrn je `null`.
     *
     * @param array<string,mixed> $history
     * @return array<string,mixed>
     */
    public static function redactRunHistory(array $history): array
    {
        $revisions = $history['revisions'] ?? null;
        if (!is_array($revisions)) {
            return $history;
        }
        foreach ($revisions as $index => $revision) {
            if (!is_array($revision)) {
                continue;
            }
            $revision = self::redactRunTotals($revision);
            $diff = $revision['diff_from_previous'] ?? null;
            if (is_array($diff) && is_array($diff['totals'] ?? null)) {
                unset($diff['totals']['enforcement_withheld_minor']);
                $revision['diff_from_previous'] = $diff;
            }
            $revisions[$index] = $revision;
        }
        $history['revisions'] = $revisions;

        return $history;
    }

    /**
     * Blokátor smazání zaměstnance jmenuje exekuci nebo insolvenci. Bez práva
     * na agendu se z něj stane obecná věta: osobu smazat nejde, ale proč, to
     * volající z odpovědi nevyčte.
     *
     * @param array<string,mixed>|null $blocker
     * @return array<string,mixed>|null
     */
    public static function redactDeleteBlocker(?array $blocker, bool $canSeeEnforcement): ?array
    {
        if ($blocker === null || $canSeeEnforcement) {
            return $blocker;
        }
        if (($blocker['code'] ?? null) !== self::ENFORCEMENT_BLOCKER) {
            return $blocker;
        }

        return [
            'code' => self::RESTRICTED_DELETE_BLOCKER,
            'message' => self::RESTRICTED_DELETE_MESSAGE,
            'employment_id' => null,
            'employment_code' => null,
        ];
    }

    public static function isRestrictedDeleteBlockerCode(string $code, bool $canSeeEnforcement): bool
    {
        return !$canSeeEnforcement && $code === self::ENFORCEMENT_BLOCKER;
    }

    /**
     * Podklady zápočtového listu: ID exekučních pohledávek a zdroje pokračujících
     * srážek z ledgeru. Bez práva se vynechají a list se ohlásí jako nedostupný
     * s vlastním kódem: vydat ho bez úplného oddílu srážek (§ 313 ZP) nejde,
     * a vydat ho s ním by znamenalo ukázat právě ta data.
     *
     * @param array<string,mixed> $readiness
     * @return array<string,mixed>
     */
    public static function redactExitReadiness(
        array $readiness,
        bool $canSeeEnforcement,
        bool $canSeeInsolvency,
    ): array {
        $certificate = $readiness['employment_certificate'] ?? null;
        if (!is_array($certificate)) {
            return $readiness;
        }
        $sources = is_array($certificate['deduction_sources'] ?? null)
            ? $certificate['deduction_sources']
            : [];
        $allowed = [];
        $restricted = false;
        foreach ($sources as $source) {
            if (is_array($source)
                && !self::deductionSourceAllowed($source, $canSeeEnforcement, $canSeeInsolvency)
            ) {
                $restricted = true;
                continue;
            }
            $allowed[] = $source;
        }
        if (!$canSeeEnforcement) {
            $restricted = $restricted || ($certificate['deduction_claim_ids'] ?? []) !== [];
            $certificate['deduction_claim_ids'] = [];
        }
        $certificate['deduction_sources'] = $allowed;
        if ($restricted && ($certificate['available'] ?? false) === true) {
            $certificate['available'] = false;
            $certificate['readiness_code'] = self::DEDUCTION_PERMISSION_REQUIRED;
        }
        $readiness['employment_certificate'] = $certificate;

        return $readiness;
    }

    /**
     * Má vztah pokračující srážku, kterou volající nesmí vidět?
     *
     * @param array<string,mixed> $readiness neredigovaná připravenost
     */
    public static function hasRestrictedDeductions(
        array $readiness,
        bool $canSeeEnforcement,
        bool $canSeeInsolvency,
    ): bool {
        $certificate = $readiness['employment_certificate'] ?? null;
        if (!is_array($certificate)) {
            return false;
        }
        if (!$canSeeEnforcement && ($certificate['deduction_claim_ids'] ?? []) !== []) {
            return true;
        }
        $sources = is_array($certificate['deduction_sources'] ?? null)
            ? $certificate['deduction_sources']
            : [];
        foreach ($sources as $source) {
            if (is_array($source)
                && !self::deductionSourceAllowed($source, $canSeeEnforcement, $canSeeInsolvency)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $source */
    private static function deductionSourceAllowed(
        array $source,
        bool $canSeeEnforcement,
        bool $canSeeInsolvency,
    ): bool {
        return match ($source['source_kind'] ?? 'enforcement_claim') {
            // Dohoda o srážkách ze mzdy je běžná mzdová agenda, ne exekuce.
            'deduction_agreement' => true,
            'insolvency' => $canSeeInsolvency,
            default => $canSeeEnforcement,
        };
    }

    /** @param array<mixed> $enforcement */
    private static function involvesInsolvency(array $enforcement): bool
    {
        $input = is_array($enforcement['input'] ?? null) ? $enforcement['input'] : [];
        $insolvency = is_array($input['insolvency'] ?? null) ? $input['insolvency'] : [];
        if (($insolvency['mode'] ?? 'none') !== 'none') {
            return true;
        }
        $result = is_array($enforcement['result'] ?? null) ? $enforcement['result'] : [];
        if (($result['insolvency_applied'] ?? false) === true) {
            return true;
        }
        $allocations = is_array($result['allocations'] ?? null) ? $result['allocations'] : [];
        foreach ($allocations as $allocation) {
            if (is_array($allocation)
                && ($allocation['claim_id'] ?? null) === self::INSOLVENCY_ALLOCATION
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $enforcement
     * @return array{redacted:true,result:array{total_withheld_minor_units:int|null}}
     */
    private static function enforcementSummary(array $enforcement): array
    {
        $result = is_array($enforcement['result'] ?? null) ? $enforcement['result'] : [];
        $withheld = $result['total_withheld_minor_units'] ?? null;

        return [
            'redacted' => true,
            'result' => [
                'total_withheld_minor_units' => is_int($withheld) ? $withheld : null,
            ],
        ];
    }
}
