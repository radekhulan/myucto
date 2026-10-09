<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Repository\Payroll\PayrollDependantRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\IncomeTax\ChildCreditClaimWindow;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationService;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Zápis převzaté OSOBY ({@see PayrollTakeoverPerson}) do karty zaměstnance, společný
 * pro všechny převody mezd z předchozího systému.
 *
 * Každý údaj jde toutéž cestou jako ruční zápis na kartě (identita, karta osoby, zákonná
 * evidence, vyživované osoby, výplatní účty, počáteční stavy kumulací); vlastní zápis do
 * cizích tabulek tu není. Doplňuje se jen to, co v MyÚčtu chybí: vyplněný údaj převod
 * nepřepíše, a opakovaný převod proto nic nezdvojí.
 *
 * Metody jsou samostatné kroky: volající (orchestrátor zdroje) si určuje pořadí
 * a každý krok si obalí vlastním savepointem, aby chyba jednoho údaje nezastavila další.
 * Rozdíly mezi zdroji nese {@see PayrollTakeoverPolicy}.
 */
final class PayrollTakeoverPersonWriter
{
    /** Údaje identity, které převod doplňuje do prázdných polí. */
    private const IDENTITY_FIELDS = ['title_prefix', 'title_suffix', 'birth_date', 'birth_place', 'birth_country_code', 'citizenship_country_code', 'sex'];

    public function __construct(
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollOpeningBalanceService $openings,
        private readonly PayrollDependantRepository $dependants,
        private readonly PayrollDependantValidator $dependantValidator,
        private readonly PayrollPersonAccountVerificationService $accountVerification,
    ) {}

    /**
     * Tituly, narození a občanství - jen do prázdných polí identity.
     *
     * @return array<string,int>
     */
    public function identity(int $supplierId, int $employeeId, PayrollTakeoverPerson $person, PayrollTakeoverPolicy $policy): array
    {
        $identity = $this->registrations->identityAt($supplierId, $employeeId, date('Y-m-d'));
        if ($identity === null) {
            if ($policy->strict) {
                throw new \DomainException('osoba nemá evidovanou identitu, údaje doplňte na kartě osoby.');
            }
            return [];
        }
        $merged = [];
        $changed = false;
        foreach (self::IDENTITY_FIELDS as $field) {
            $current = $identity[$field] ?? null;
            $current = $current === null || $current === '' ? null : (string) $current;
            $incoming = $person->identity[$field] ?? null;
            if ($current === null && is_string($incoming) && $incoming !== '') {
                $current = $incoming;
                $changed = true;
            }
            $merged[$field] = $current;
        }
        if (!$changed) {
            return [];
        }
        $this->identities->saveIdentityFacts($supplierId, $employeeId, (int) $identity['id'], (int) $identity['row_version'], $merged);
        return ['identity' => 1];
    }

    /**
     * Adresa trvalého pobytu, kontaktní adresa, e-mail a telefon, rodné příjmení a chybějící
     * identifikátory (zahraniční DIČ) - jedno uložení karty osoby (jeden optimistický zámek).
     *
     * @param ?string $start nástup vztahu; od něj (nejpozději od dneška) platí adresy
     * @return array<string,int>
     */
    public function personCard(int $supplierId, int $employeeId, PayrollTakeoverPerson $person, ?string $start, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $current = $this->profiles->get($supplierId, $employeeId);
        if ($current === null) {
            if ($policy->strict) {
                throw new \DomainException('osobní karta zaměstnance nebyla nalezena.');
            }
            return [];
        }
        $today = date('Y-m-d');
        $from = min(is_string($start) ? $start : $today, $today);
        $addresses = [];
        if ($policy->addressesPerType) {
            foreach (['residence' => $person->residence, 'mailing' => $person->mailing] as $type => $address) {
                if (!is_array($address) || ($type === 'mailing' && $address === $person->residence)) {
                    continue;
                }
                foreach ($current['addresses'] as $row) {
                    if (($row['address_type'] ?? null) === $type) {
                        continue 2;
                    }
                }
                $addresses[] = $address + ['id' => null, 'address_type' => $type, 'effective_from' => $from, 'effective_to' => null];
            }
        } elseif (is_array($person->residence) && $current['addresses'] === []) {
            $addresses[] = $person->residence + ['id' => null, 'address_type' => 'residence', 'effective_from' => $from, 'effective_to' => null];
        }
        $contacts = [];
        $hasContact = false;
        foreach ($current['contacts'] as $row) {
            $hasContact = $hasContact || (!empty($row['is_active']) && !empty($row['is_primary']));
        }
        if (!$hasContact) {
            if (is_string($person->email)) {
                $contacts[] = ['id' => null, 'contact_type' => 'email', 'value' => $person->email, 'is_primary' => true, 'is_active' => true];
            }
            if (is_string($person->phone)) {
                $contacts[] = ['id' => null, 'contact_type' => 'phone', 'value' => $person->phone, 'is_primary' => true, 'is_active' => true];
            }
        }
        $identity = [];
        if (is_string($person->birthSurname)) {
            $version = $policy->birthSurnameOnCurrentVersion
                ? (self::covering($current['identity_history'], $today) ?? ($current['identity_history'][0] ?? null))
                : ($current['identity_history'][0] ?? null);
            if ($version !== null && ($version['birth_surname_masked'] ?? null) === null) {
                $identity[] = [
                    'id' => $version['id'],
                    'full_name' => $version['full_name'],
                    'first_name' => $version['first_name'],
                    'last_name' => $version['last_name'],
                    'birth_surname' => $person->birthSurname,
                    'effective_from' => $version['effective_from'],
                    'effective_to' => $version['effective_to'],
                ];
            }
        }
        $identifierValues = $person->identifiers;
        foreach ($current['identifiers'] as $existing) {
            unset($identifierValues[(string) ($existing['identifier_type'] ?? '')]);
        }
        $identifiers = [];
        foreach ($identifierValues as $type => $value) {
            $identifiers[] = ['id' => null, 'identifier_type' => $type, 'value' => $value];
        }
        if ($addresses === [] && $contacts === [] && $identity === [] && $identifiers === []) {
            return [];
        }
        $this->profiles->save($supplierId, $employeeId, $this->profileValidator->validate([
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $current['payout_method'],
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? $today,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => $identity,
            'addresses' => $addresses,
            'contacts' => $contacts,
            'identifiers' => $identifiers,
            'accounts' => [],
        ]), $current['row_version'], $userId, null, null);
        return ['person_card' => 1] + ($identifiers !== [] ? ['person_identifiers' => count($identifiers)] : []);
    }

    /**
     * Daňová rezidence, prohlášení poplatníka, zdravotní pojištění, příslušnost
     * k sociálnímu pojištění a sleva pracujícího důchodce - jen do prázdných řad zákonné
     * evidence, jedním uložením.
     *
     * Údaj, který převod vyplnit nesmí (nerezident bez státu rezidence, osoba podléhající
     * cizím právním předpisům), ohlásí přes `$manual` (`tax_residence`,
     * `social_jurisdiction`); volající rozhodne, jestli jde o výjimku, nebo upozornění.
     * Výjimku z minima zdravotního pojištění bez doloženého důvodu zapíše a ohlásí
     * (`health_minimum_reduction`): důvod musí doplnit účetní.
     *
     * @param callable(string):void $manual
     * @return array<string,int>
     */
    public function statutoryEvidence(int $supplierId, int $employeeId, PayrollTakeoverPerson $person, string $today, ?int $userId, PayrollTakeoverPolicy $policy, callable $manual): array
    {
        $view = $this->statutory->editorView($supplierId, $employeeId, $today);
        if ($view === null) {
            if ($policy->strict) {
                throw new \DomainException('zákonná evidence zaměstnance nebyla nalezena.');
            }
            return [];
        }
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];
        $counts = [];
        $residence = $person->taxResidence;
        if (($sections['tax_residences'] ?? []) === [] && $residence !== null) {
            if ($residence->status === 'czech-resident') {
                $sections['tax_residences'] = [[
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => $residence->reference,
                    'effective_from' => $residence->from,
                    'effective_to' => null,
                    'evidence_note' => $residence->note,
                ]];
                $counts['tax_residence'] = 1;
            } elseif ($residence->status === 'non-resident' && $residence->country !== null && $residence->country !== 'CZ') {
                $sections['tax_residences'] = [[
                    'residence' => 'non-resident',
                    'country_code' => $residence->country,
                    'evidence_reference' => $residence->reference,
                    'effective_from' => $residence->from,
                    'effective_to' => null,
                    'evidence_note' => $residence->note,
                ]];
                $counts['tax_residence'] = 1;
            } elseif ($residence->status === 'non-resident') {
                $manual('tax_residence');
            }
        }
        if (($sections['tax_declarations'] ?? []) === [] && $person->taxDeclarations !== []) {
            $rows = [];
            foreach ($person->taxDeclarations as $run) {
                $rows[] = [
                    'status' => $run->status,
                    'evidence_reference' => $run->reference,
                    'effective_from' => $run->from,
                    'effective_to' => $run->to,
                    'evidence_note' => $run->note,
                ];
            }
            $sections['tax_declarations'] = $rows;
            $counts['tax_declarations'] = 1;
        }
        // Zdravotní pojištění: osoba založená druhým souběžným vztahem ho od importu mezd
        // nedostane, kód pojišťovny ale zdroj má.
        $healthRuns = $person->healthCoverageHistory !== []
            ? $person->healthCoverageHistory
            : ($person->healthCoverage !== null ? [$person->healthCoverage] : []);
        if (($sections['health_coverages'] ?? []) === [] && $healthRuns !== []) {
            $rows = [];
            foreach ($healthRuns as $health) {
                $rows[] = [
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'insurer_status' => 'verified',
                    'insurer_code' => $health->status,
                    'insurer_evidence_reference' => null,
                    'health_evidence_document_id' => null,
                    'health_evidence_document_sha256' => null,
                    'effective_from' => $health->from,
                    // Jediný úsek bez historie je otevřený, jak ho převod vždy zapisoval.
                    'effective_to' => $person->healthCoverageHistory !== [] ? $health->to : null,
                    'evidence_note' => $health->note,
                ];
            }
            $sections['health_coverages'] = $rows;
            $counts['health_coverage'] = 1;
        }
        // Příslušnost k sociálnímu pojištění: český režim bez A1, ledaže zdroj vede
        // osobu jako podléhající cizím právním předpisům (to se ručně ověří).
        $social = $person->socialJurisdiction;
        if (($sections['social_jurisdictions'] ?? []) === [] && $social !== null) {
            if ($social->status === 'foreign') {
                $manual('social_jurisdiction');
            } else {
                $sections['social_jurisdictions'] = [[
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                    'effective_from' => $social->from,
                    'effective_to' => null,
                    'evidence_note' => $social->note,
                ]];
                $counts['social_jurisdiction'] = 1;
            }
        }
        // Výjimky z minima zdravotního pojištění: jen úseky, do kterých dosud žádná výjimka
        // nezasahuje (účetní ji mohla zapsat i s důvodem). Na rozdíl od ostatních řad se
        // doplňují i do neprázdné řady, protože každý převáděný rok nese své měsíce.
        $reductions = $sections['health_minimum_reductions'] ?? [];
        $added = 0;
        $unverified = false;
        foreach ($person->healthMinimumExemptions as $exemption) {
            foreach ($reductions as $existing) {
                $existingTo = $existing['effective_to'] ?? null;
                if ((string) $existing['effective_from'] <= (string) $exemption->to
                    && ($existingTo === null || (string) $existingTo >= $exemption->from)
                ) {
                    continue 2;
                }
            }
            $verified = $exemption->status !== 'unverified';
            $reductions[] = [
                'reason' => $exemption->status,
                'evidence_reference' => $verified ? $exemption->reference : null,
                'effective_from' => $exemption->from,
                'effective_to' => $exemption->to,
                'evidence_note' => $exemption->note,
            ];
            $unverified = $unverified || !$verified;
            $added++;
        }
        if ($added > 0) {
            $sections['health_minimum_reductions'] = $reductions;
            $counts['health_minimum_reduction'] = $added;
            if ($unverified) {
                $manual('health_minimum_reduction');
            }
        }
        if (($sections['social_discount_claims'] ?? []) === [] && $person->socialDiscountClaims !== []) {
            $rows = [];
            foreach ($person->socialDiscountClaims as $run) {
                $rows[] = [
                    'status' => $run->status,
                    'evidence_reference' => $run->reference,
                    'effective_from' => $run->from,
                    'effective_to' => $run->to,
                    'evidence_note' => $run->note,
                ];
            }
            $sections['social_discount_claims'] = $rows;
            $counts['pensioner_discount'] = 1;
        }
        if ($counts === []) {
            return [];
        }
        $this->statutory->save($supplierId, $employeeId, ['sections' => $sections], $today, $userId, null, null);
        return $counts;
    }

    public const OPENINGS_EMPTY = 'empty';
    public const OPENINGS_EXISTING = 'existing';
    public const OPENINGS_GAP = 'gap';
    public const OPENINGS_WRITTEN = 'written';
    public const OPENINGS_UNCHANGED = 'unchanged';

    /**
     * Počáteční stavy ročních kumulací za měsíce roku před prvním obdobím, které
     * zpracovává MyÚčto. Jen souvislá řada měsíců a jen tam, kde stavy nejsou (nebo je
     * zapsal dřívější převod téhož zdroje, dovoluje-li to {@see PayrollTakeoverPolicy::$rewriteOwnOpenings}).
     *
     * Vrací, co se stalo (`OPENINGS_*`); hlášení je na volajícím.
     *
     * @param array<int,PayrollTakeoverOpeningMonth> $months úhrny měsíců roku podle čísla měsíce
     */
    public function openingBalances(int $supplierId, int $employeeId, int $year, int $startMonth, array $months, ?int $userId, PayrollTakeoverPolicy $policy): string
    {
        $months = array_filter($months, static fn (int $month): bool => $month < $startMonth, ARRAY_FILTER_USE_KEY);
        if ($months === []) {
            return self::OPENINGS_EMPTY;
        }
        $current = $this->openings->current($supplierId, $employeeId, $year);
        $existing = array_filter($current['openings'], static fn (?int $id): bool => $id !== null);
        if ($existing !== [] && (!$policy->rewriteOwnOpenings || !str_starts_with((string) $current['source_reference'], $policy->label . ':'))) {
            return self::OPENINGS_EXISTING;
        }
        $numbers = array_keys($months);
        if (count($numbers) !== max($numbers) - min($numbers) + 1) {
            return self::OPENINGS_GAP;
        }
        $rows = [];
        foreach ($months as $month) {
            $rows[] = $month->toRow();
        }
        $saved = $this->openings->save($supplierId, $employeeId, $year, $rows,
            sprintf('%s: zpracované mzdy %d. až %d. měsíc %d', $policy->label, min($numbers), max($numbers), $year), $userId);
        if (!$policy->rewriteOwnOpenings) {
            return self::OPENINGS_WRITTEN;
        }
        return $saved['openings'] == $current['openings'] ? self::OPENINGS_UNCHANGED : self::OPENINGS_WRITTEN;
    }

    /**
     * Děti s daňovým zvýhodněním jako vyživované osoby s nárokem daného pořadí. Jen
     * osobě, která vyživované osoby ještě nemá. Vztah k dítěti zdroj nevede (zapíše se
     * vlastní dítě) a nezná ani jinou vyživující osobu v domácnosti (zůstane nevyplněná).
     *
     * @return array<string,int>
     */
    public function children(int $supplierId, int $employeeId, PayrollTakeoverPerson $person, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $children = $person->children;
        $counts = $person->childrenWithoutCredit > 0 ? ['children_without_credit' => 1] : [];
        if ($children === []) {
            return $counts;
        }
        // Doložený nárok stojí na podepsaném prohlášení; převod ho zapisuje od prvního
        // měsíce, kdy je prohlášení ve zdroji podepsané.
        if (!is_string($person->firstSignedPeriod)) {
            throw new \DomainException("dítě má v {$policy->label} daňové zvýhodnění, ale prohlášení poplatníka není v převáděném roce podepsané. Nárok doplňte ručně.");
        }
        $declaredFrom = $person->firstSignedPeriod . '-01';
        $today = date('Y-m-d');
        $view = $this->dependants->overview($supplierId, $employeeId, $today)
            ?? throw new \DomainException('zaměstnanec nebyl nalezen.');
        if ($view['dependants'] !== []) {
            return $counts + ['children_existing' => 1];
        }
        $created = 0;
        foreach ($children as $child) {
            if (!is_string($child['birth_number'])) {
                throw new \DomainException("dítě s daňovým zvýhodněním nemá v {$policy->label} rodné číslo, doplňte ho ručně.");
            }
            $birthNumber = CzechBirthNumber::normalize($child['birth_number']);
            $birthDate = (string) CzechBirthNumber::birthDate($birthNumber);
            $from = is_string($child['from']) && $child['from'] > $birthDate ? $child['from'] : $birthDate;
            // Nárok běží po celých měsících a nesmí přesahovat dobu, po kterou je dítě vedené
            // jako vyživované: vyživování se proto vede od začátku měsíce nároku (nejdřív od
            // narození) do konce měsíce, kdy nárok ve zdroji končí. Měsíc narození do nároku
            // patří (§ 35c odst. 10), jiný měsíc začatý v průběhu ne.
            $claimFrom = max(substr($from, 0, 7) . '-01', $declaredFrom);
            $existenceFrom = max($birthDate, min($from, $claimFrom));
            $claimFrom = max($claimFrom, ChildCreditClaimWindow::earliestFrom($birthDate, $existenceFrom, null));
            $until = is_string($child['to']) ? $this->dependantValidator->monthEnd($child['to']) : null;
            if ($until !== null && $until < $claimFrom) {
                continue;
            }
            $name = trim(($child['given_name'] ?? '') . ' ' . ($child['family_name'] ?? ''));
            $known = array_column($view['dependants'], 'id');
            $view = $this->dependants->createDependant($supplierId, $employeeId, $this->dependantValidator->validateDependant([
                'relation' => 'child_own',
                'full_name' => $name !== '' ? $name : 'Dítě',
                'given_name' => $child['given_name'],
                'family_name' => $child['family_name'],
                'birth_date' => $birthDate,
                'birth_number' => $birthNumber,
                'ztp_p' => false,
                'student' => false,
                'existence_from' => $existenceFrom,
                'existence_to' => $until,
                'note' => $policy->note("dítě s daňovým zvýhodněním; vztah k dítěti {$policy->label} nevede, zapsáno jako vlastní dítě."),
            ]), $today, $userId, null, null);
            $dependantId = null;
            foreach ($view['dependants'] as $dependant) {
                if (!in_array($dependant['id'], $known, true)) {
                    $dependantId = (int) $dependant['id'];
                }
            }
            if ($dependantId === null) {
                throw new \DomainException('vyživovanou osobu se nepodařilo založit.');
            }
            $view = $this->dependants->createClaim($supplierId, $employeeId, $dependantId, $this->dependantValidator->validateClaim([
                'child_order' => $child['order'],
                'credit_status' => 'claimed',
                'claim_reason' => null,
                'evidence_status' => 'verified',
                'evidence_reference' => $child['reference'],
                'shared_household_confirmed' => true,
                'other_claimant_excluded' => true,
                'ztp_p' => false,
                'effective_from' => $claimFrom,
                'effective_to' => $until,
                'other_household_caregiver_status' => null,
            ]), $today, $userId, null, null);
            $created++;
        }
        return $counts + ['children' => $created];
    }

    /**
     * Odpověď „tytéž děti jiná osoba v domácnosti nevyživuje" (JMHZ 10453 = NE) u nároků,
     * které ji ještě nemají. Zdrojem je hlášení, které předchozí program za osobu podal;
     * bez odpovědi se měsíční hlášení nezmrazí. Kladnou odpověď převod nezapisuje: hlášení
     * pak musí jmenovat tu druhou osobu a to je rozhodnutí účetní.
     *
     * @return array<string,int>
     */
    public function otherCaregiverNone(int $supplierId, int $employeeId, ?int $userId): array
    {
        $today = date('Y-m-d');
        $view = $this->dependants->overview($supplierId, $employeeId, $today)
            ?? throw new \DomainException('zaměstnanec nebyl nalezen.');
        $updated = 0;
        foreach ($view['dependants'] as $dependant) {
            foreach ($dependant['claims'] as $claim) {
                if ($claim['superseded_by_id'] !== null || $claim['other_household_caregiver_status'] !== 'unknown' || $claim['is_frozen']) {
                    continue;
                }
                $this->dependants->saveClaim(
                    $supplierId,
                    $employeeId,
                    (int) $dependant['id'],
                    (int) $claim['id'],
                    $this->dependantValidator->validateClaim([
                        'child_order' => $claim['child_order'],
                        'credit_status' => $claim['credit_status'],
                        'claim_reason' => $claim['claim_reason'],
                        'evidence_status' => $claim['evidence_status'],
                        'evidence_reference' => $claim['evidence_reference'],
                        'shared_household_confirmed' => $claim['shared_household_confirmed'],
                        'other_claimant_excluded' => $claim['other_claimant_excluded'],
                        'ztp_p' => $claim['ztp_p'],
                        'effective_from' => $claim['effective_from'],
                        'effective_to' => $claim['effective_to'],
                        'other_household_caregiver_status' => 'none',
                    ]),
                    $claim['row_version'],
                    $today,
                    $userId,
                    null,
                    null,
                );
                $updated++;
            }
        }

        return $updated > 0 ? ['children_other_caregiver_none' => $updated] : [];
    }

    /**
     * Výplatní účty osoby. Založí se jen osobě bez účtů; ověření viz
     * {@see self::verifyAccounts()}. Mzda odcházela ve zdroji na účet, takže způsob
     * výplaty je bankovní; hotovostní dávka by jinak čekala na ruční přepnutí u každé osoby.
     *
     * @param ?string $start nástup vztahu; od něj (nejpozději od dneška) účet platí
     * @return array<string,int>
     */
    public function payoutAccounts(int $supplierId, int $employeeId, PayrollTakeoverPerson $person, ?string $start, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state): array
    {
        $accounts = $person->payoutAccounts;
        if ($accounts === []) {
            return [];
        }
        $current = $this->profiles->get($supplierId, $employeeId);
        if ($current === null) {
            if ($policy->strict) {
                throw new \DomainException('osobní karta zaměstnance nebyla nalezena.');
            }
            return [];
        }
        $today = date('Y-m-d');
        // Den poslední mzdy, kterou zdroj na účet vyplatil. Nese ho popisek účtu, protože
        // pole pro odkaz na zdroj ověření tabulka výplatních účtů nemá.
        $paidOn = $person->payoutAccountsPaidOn;
        // Účty z dřívějšího běhu se nezakládají znovu - ověřit se ale musí. Dřív tu bylo
        // holé `return []`, což znamenalo, že převod spuštěný znovu nad už převedenou
        // firmou ověření NIKDY nedoplnil: krok skončil dřív, než se k němu dostal. Přesně
        // to potkalo instalace, kde účty založil starší běh, který ověřovat ještě neuměl.
        if ($current['accounts'] !== []) {
            $this->repairCashShare($supplierId, $employeeId, $current, $userId, $state);
            return $policy->verifyPayoutAccounts ? $this->verifyAccounts($supplierId, $employeeId, $paidOn, $userId, $state) : [];
        }
        $from = min(is_string($start) ? $start : $today, $today);
        $label = $policy->label;
        $rows = [];
        foreach ($accounts as $index => $account) {
            // Účet, který zdroj vede jako neaktivní, mzdu nedostával: není aktivní ani tady
            // a podíl výplaty nemá.
            $primary = $index === 0 && $account->active;
            $rows[] = [
                'id' => null,
                'label' => $primary
                    ? 'Výplatní účet z ' . $label . ($paidOn === null ? '' : ', mzdy vypláceny do ' . $paidOn)
                    : ($account->active ? 'Další účet z ' . $label . ' ' . ($index + 1) : 'Účet z ' . $label . ' bez výplat ' . ($index + 1)),
                'bank_account' => $account->account . '/' . $account->bankCode,
                'allocation_basis_points' => $primary ? 10000 : 0,
                'effective_from' => $from,
                'effective_to' => null,
                'is_active' => $primary,
            ];
        }
        $method = $current['payout_method'] === 'cash' ? 'bank' : $current['payout_method'];
        $hasPrimary = array_filter($rows, static fn (array $row): bool => $row['is_active'] && $row['allocation_basis_points'] === 10000) !== [];
        $this->profiles->save($supplierId, $employeeId, $this->profileValidator->validate([
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $method,
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            // Výplata na účet celým podílem: hotovost je nula. Dřív zůstal podíl
            // hotovosti z výchozí hotovostní karty (100 %) a karta hlásila rozdělení
            // výplaty přes 100 %.
            'cash_allocation_basis_points' => $method === 'bank' && $hasPrimary ? 0 : $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? $today,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => [],
            'addresses' => [],
            'contacts' => [],
            'identifiers' => [],
            'accounts' => $rows,
        ]), $current['row_version'], $userId, null, null);
        if (!$policy->verifyPayoutAccounts) {
            return ['payout_accounts_to_verify' => count($rows)];
        }
        return ['payout_accounts' => count($rows)]
            + $this->verifyAccounts($supplierId, $employeeId, $paidOn, $userId, $state);
    }

    /**
     * Oprava karty, kterou založil starší běh převodu: výplata na účet celým podílem
     * a k tomu podíl hotovosti 100 % z výchozí hotovostní karty. Součet 200 % karta
     * hlásí jako chybné rozdělení výplaty. Opravuje se jen přesně tahle kombinace —
     * jiné rozdělení mohla účetní nastavit vědomě.
     *
     * @param array<string,mixed> $current
     */
    private function repairCashShare(int $supplierId, int $employeeId, array $current, ?int $userId, PayrollTakeoverRunState $state): void
    {
        if ($current['payout_method'] !== 'bank' || (int) $current['cash_allocation_basis_points'] !== 10000) {
            return;
        }
        $today = date('Y-m-d');
        $bankShare = 0;
        foreach ($current['accounts'] as $account) {
            if ($account['is_active'] === true && (string) $account['effective_from'] <= $today
                && ($account['effective_to'] === null || (string) $account['effective_to'] >= $today)
            ) {
                $bankShare += (int) $account['allocation_basis_points'];
            }
        }
        if ($bankShare !== 10000) {
            return;
        }
        $this->profiles->save($supplierId, $employeeId, $this->profileValidator->validate([
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'],
            'payout_method' => 'bank',
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => $current['payout_effective_on'] ?? $today,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => [],
            'addresses' => [],
            'contacts' => [],
            'identifiers' => [],
            'accounts' => [],
        ]), $current['row_version'], $userId, null, null);
        $state->cashShareRepaired++;
    }

    /**
     * Ověření účtu: na účet předchozí mzdový systém opakovaně vyplácel mzdu, a to je
     * věcný doklad, ne domněnka. Zdroj `user_verified` je z přípustných hodnot nejbližší
     * (převod ani migrace mezi nimi nejsou) a původ nese popisek účtu, protože pole pro
     * odkaz na zdroj tabulka účtů nemá. Datum je den poslední výplaty ze zdroje.
     *
     * Je to samostatný krok schválně: pouští se i nad účty, které založil dřívější běh,
     * takže opakovaný převod dovede evidenci doplnit místo aby ji nechal, jak byla.
     *
     * @return array<string,int>
     */
    private function verifyAccounts(int $supplierId, int $employeeId, ?string $paidOn, ?int $userId, PayrollTakeoverRunState $state): array
    {
        $saved = $this->profiles->get($supplierId, $employeeId);
        $state->accountsAlreadyVerified += count(array_filter(
            $saved['accounts'] ?? [],
            static fn (array $account): bool => $account['verification_source'] !== null && $account['is_active'] === true,
        ));
        $unverified = array_values(array_filter(
            $saved['accounts'] ?? [],
            static fn (array $account): bool => $account['verification_source'] === null,
        ));
        if ($unverified === []) {
            return [];
        }
        foreach ($unverified as $account) {
            if ($account['is_active'] !== true) {
                $state->accountsInactive++;
            }
        }
        // Bez přihlášeného uživatele nebo bez dokladu o výplatě ověřit nejde: `verified_by`
        // i `verified_on` jsou povinné společně (trigger z migrace 1271).
        if ($userId === null || $paidOn === null) {
            if ($paidOn === null && array_filter($unverified, static fn (array $a): bool => $a['is_active'] === true) !== []) {
                $state->accountsWithoutPayout[] = $employeeId;
            }
            $state->accountsToVerify += count($unverified);
            return [];
        }
        $verified = 0;
        $pending = 0;
        foreach ($unverified as $account) {
            // Neaktivní účet ověřit nejde a ani nemá čím: ve zdroji je jen veden, mzda na něj
            // nechodila. Zůstane k rozhodnutí účetní.
            if ($account['is_active'] !== true) {
                $pending++;
                continue;
            }
            try {
                $this->accountVerification->verify(
                    $supplierId,
                    $employeeId,
                    $account['id'],
                    'user_verified',
                    $paidOn,
                    $userId,
                    $account['row_version'],
                );
                $verified++;
            } catch (\DomainException|\InvalidArgumentException|\RuntimeException) {
                $pending++;
            }
        }
        $state->accountsVerified += $verified;
        $state->accountsToVerify += $pending;
        $counts = [];
        if ($verified > 0) {
            $counts['payout_accounts_verified'] = $verified;
        }
        if ($pending > 0) {
            $counts['payout_accounts_to_verify'] = $pending;
        }
        return $counts;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private static function covering(array $rows, string $onDate): ?array
    {
        foreach ($rows as $row) {
            $to = $row['effective_to'] ?? null;
            if ((string) $row['effective_from'] <= $onDate && ($to === null || (string) $to >= $onDate)) {
                return $row;
            }
        }
        return null;
    }
}
