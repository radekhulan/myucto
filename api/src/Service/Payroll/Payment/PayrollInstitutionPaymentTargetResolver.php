<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;

/**
 * Jedno místo, kde se k odvodu dohledá platební účet instituce.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Každý materializátor si účet hledal sám, vždy jen podle přesné shody kódu
 * instituce, a při neúspěchu hlásil vlastní větu. U sociálního pojištění to
 * dopadlo takhle: účet se hledal pod hodnotou `social_security_office_code`
 * z nastavení zaměstnavatele, tedy pod KÓDEM PRACOVIŠTĚ OSSZ („444"), zatímco
 * účetní ho — zcela logicky — založila pod kódem „CSSZ". Aplikace pak tvrdila,
 * že ČSSZ nemá ověřený účet, a poslala účetní opravovat nastavení, které bylo
 * v pořádku. Účet ověřený byl, jen se jmenoval jinak. To je chyba aplikace,
 * ne uživatele, a nesmí blokovat zaúčtovaný mzdový běh.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Kódy institucí se dělí na dva druhy a resolver s nimi zachází jinak:
 *
 *  - **Identita instituce** ({@see FallbackPolicy::NEVER}) — kód říká, KOMU se
 *    platí. Zdravotní pojišťovna „111" a „201" jsou dvě různé pojišťovny,
 *    zálohová a srážková daň mají u finančního úřadu různá předčíslí. Tady se
 *    nesmí sáhnout po jiném účtu ani tehdy, když je jediný: poslat odvod na
 *    špatný účet je horší než zastavit. Zlepšuje se jen hláška.
 *
 *  - **Organizační značka** ({@see FallbackPolicy::UNIQUE_VERIFIED_ACCOUNT}) —
 *    instituce je jen jedna (ČSSZ, pojistitel zákonného pojištění odpovědnosti)
 *    a kód je vnitřní štítek (kód pracoviště, kód pojistitele ze sazby). Když
 *    pod ním účet není, použije se JEDNOZNAČNÝ ověřený a účinný účet téže
 *    instituce, ať se jmenuje jakkoli. Ten účet účetní ověřila, tedy na něj
 *    chce platit.
 *
 * Fail-closed zůstává tam, kde volba není jednoznačná: žádný ověřený účinný
 * účet, víc než jeden takový účet, nebo kód, který by se nevešel do reference
 * platby. V těch případech hláška jmenuje OBA kódy, vypíše účty, které
 * aplikace našla, a řekne, co konkrétně změnit.
 */
final class PayrollInstitutionPaymentTargetResolver
{
    /** Účet se našel pod očekávaným kódem instituce. */
    public const MATCH_CODE = 'code';

    /**
     * Účet se našel jako jediný ověřený účinný účet instituce; jeho kód se
     * s očekávaným neshoduje a použil se právě proto, že je kód jen značka.
     */
    public const MATCH_INSTITUTION = 'institution';

    /** Zdroje ověření, které platí za doklad o účtu. */
    private const VERIFIED_SOURCE_KINDS = [
        'official_registry',
        'official_document',
        'institution_notice',
        'user_verified',
    ];

    public function __construct(
        private readonly PayrollInstitutionAccountRepository $institutions,
    ) {}

    /**
     * @param string $expectedCode kód, pod kterým účet očekává nastavení
     * @param string $institutionLabel jak se instituce jmenuje v hlášce
     * @param string $expectedCodeOrigin kde se očekávaný kód v aplikaci bere
     * @param string $referenceCodePattern tvar kódu, který snese reference platby
     * @param list<string> $reservedCodes kódy patřící JINÉMU cíli téhož typu
     * @return array{
     *   account:array{
     *     id:int,
     *     institution_id:int,
     *     institution_type:string,
     *     institution_code:string,
     *     institution_name:string,
     *     bank_account_ciphertext:string,
     *     bank_account_hash:string,
     *     currency_code:string,
     *     variable_symbol:?string,
     *     specific_symbol:?string,
     *     constant_symbol:?string,
     *     valid_from:string,
     *     valid_to:?string,
     *     source_kind:string,
     *     source_reference:string,
     *     verified_on:string,
     *     verified_by:?int,
     *     row_version:int
     *   },
     *   institution_code:string,
     *   expected_code:string,
     *   matched_by:string
     * }
     */
    public function resolve(
        int $supplierId,
        string $institutionType,
        string $expectedCode,
        string $currencyCode,
        string $effectiveOn,
        string $institutionLabel,
        string $expectedCodeOrigin,
        PayrollInstitutionFallbackPolicy $fallback,
        string $referenceCodePattern = '/^[A-Z0-9][A-Z0-9._-]{0,31}$/D',
        array $reservedCodes = [],
    ): array {
        $exact = $this->institutions->lockEffectivePaymentTargets(
            $supplierId,
            $institutionType,
            $expectedCode,
            $currencyCode,
            $effectiveOn,
        );
        if (count($exact) === 1) {
            return [
                'account' => $exact[0],
                'institution_code' => $exact[0]['institution_code'],
                'expected_code' => $expectedCode,
                'matched_by' => self::MATCH_CODE,
            ];
        }

        $all = $this->institutions->lockEffectiveInstitutionPaymentTargets(
            $supplierId,
            $institutionType,
            $currencyCode,
            $effectiveOn,
        );

        if (count($exact) > 1) {
            // Přesná shoda kódu, ale účtů je víc — tady se opravdu nedá vybrat.
            throw new \DomainException(sprintf(
                '%s má k %s pod kódem „%s" víc než jeden účinný účet (%s).'
                . ' Ukončete v Nastavení mezd → Účty institucí platnost těch,'
                . ' na které se už neplatí, ať zůstane jediný.',
                $institutionLabel,
                $effectiveOn,
                $expectedCode,
                $this->describe($exact),
            ));
        }

        if ($fallback === PayrollInstitutionFallbackPolicy::UNIQUE_VERIFIED_ACCOUNT) {
            $candidates = [];
            foreach ($all as $account) {
                if (in_array($account['institution_code'], $reservedCodes, true)
                    || !$this->looksVerified($account, $effectiveOn)
                ) {
                    continue;
                }
                $candidates[] = $account;
            }
            if (count($candidates) === 1
                && preg_match(
                    $referenceCodePattern,
                    $candidates[0]['institution_code'],
                ) === 1
            ) {
                return [
                    'account' => $candidates[0],
                    'institution_code' => $candidates[0]['institution_code'],
                    'expected_code' => $expectedCode,
                    'matched_by' => self::MATCH_INSTITUTION,
                ];
            }
            if (count($candidates) === 1) {
                // Jediný ověřený účet by se dal použít, jenže jeho kód by se
                // nevešel do reference platby (např. lomítko) a závazek by
                // spadl až u sestavení dávky, dávno po zaúčtování.
                throw new \DomainException(sprintf(
                    '%s má k %s jediný ověřený účet %s, jeho kód instituce ale'
                    . ' nelze použít v referenci platby (povolena jsou velká'
                    . ' písmena, číslice, tečka, podtržítko a pomlčka).'
                    . ' Přepište v Nastavení mezd → Účty institucí kód tohoto'
                    . ' účtu na „%s" (%s).',
                    $institutionLabel,
                    $effectiveOn,
                    $this->describe($candidates),
                    $expectedCode,
                    $expectedCodeOrigin,
                ));
            }

            throw new \DomainException(sprintf(
                '%s nemá k %s ověřený účinný účet pod kódem „%s" (%s)%s %s',
                $institutionLabel,
                $effectiveOn,
                $expectedCode,
                $expectedCodeOrigin,
                $this->found($all, $effectiveOn),
                $candidates === []
                    ? 'Zadejte a ověřte v Nastavení mezd → Účty institucí'
                        . ' účet, na který se odvod posílá; kód instituce u něj'
                        . ' může být jakýkoli, aplikace si ho dohledá.'
                    : sprintf(
                        'Ověřených účtů je víc, takže aplikace nesmí hádat,'
                        . ' na který z nich odvod poslat. Ukončete v Nastavení'
                        . ' mezd → Účty institucí platnost těch, na které se už'
                        . ' neplatí, nebo u toho správného nastavte kód'
                        . ' instituce „%s".',
                        $expectedCode,
                    ),
            ));
        }

        throw new \DomainException(sprintf(
            '%s nemá k %s ověřený účinný účet pod kódem „%s" (%s)%s'
            . ' Zadejte a ověřte v Nastavení mezd → Účty institucí účet'
            . ' s kódem instituce „%s", nebo tento kód opravte u účtu, který'
            . ' už tam je. Jiný účet aplikace použít nesmí — u tohoto typu'
            . ' instituce kód říká, komu se platí.',
            $institutionLabel,
            $effectiveOn,
            $expectedCode,
            $expectedCodeOrigin,
            $this->found($all, $effectiveOn),
            $expectedCode,
        ));
    }

    /**
     * Levná kontrola „vypadá ověřeně" pro výběr kandidáta. Plné ověření
     * (rozšifrování účtu a porovnání otisku) dělá volající nad vybraným účtem.
     *
     * @param array<string,mixed> $account
     */
    private function looksVerified(array $account, string $effectiveOn): bool
    {
        $verifiedBy = $account['verified_by'] ?? null;
        $verifiedOn = $account['verified_on'] ?? null;
        $hash = $account['bank_account_hash'] ?? null;

        return in_array(
            $account['source_kind'] ?? null,
            self::VERIFIED_SOURCE_KINDS,
            true,
        )
            && is_int($verifiedBy)
            && $verifiedBy > 0
            && is_string($verifiedOn)
            && $verifiedOn <= PayrollInstitutionVerificationWindow
                ::latestAcceptable($effectiveOn)
            && is_string($hash)
            && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1;
    }

    /**
     * Věta „co aplikace našla". Vypisuje jen kód, název a pořadové číslo účtu —
     * nikdy číslo bankovního účtu, to je šifrované a do hlášky nepatří.
     *
     * @param list<array<string,mixed>> $accounts
     */
    private function found(array $accounts, string $effectiveOn): string
    {
        if ($accounts === []) {
            return '.'
                . ' Pro tento typ instituce nemá firma k tomuto dni zadaný'
                . ' žádný účinný účet.';
        }
        $verified = [];
        foreach ($accounts as $account) {
            if ($this->looksVerified($account, $effectiveOn)) {
                $verified[] = $account;
            }
        }
        if ($verified === []) {
            return sprintf(
                '. Účty tohoto typu firma zadané má (%s), žádný z nich ale'
                . ' není ověřený.',
                $this->describe($accounts),
            );
        }

        return sprintf(
            '. Ověřené účty tohoto typu, které aplikace našla: %s.',
            $this->describe($verified),
        );
    }

    /** @param list<array<string,mixed>> $accounts */
    private function describe(array $accounts): string
    {
        $parts = [];
        foreach ($accounts as $account) {
            $name = is_string($account['institution_name'] ?? null)
                ? trim($account['institution_name'])
                : '';
            $parts[] = sprintf(
                '#%d „%s"%s',
                (int) ($account['id'] ?? 0),
                (string) ($account['institution_code'] ?? ''),
                $name === '' ? '' : " – {$name}",
            );
        }

        return implode(', ', $parts);
    }
}
