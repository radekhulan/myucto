<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationInteractionResolver
{
    /**
     * Připnutá schémata (PREZEC26 1.2, REGZEC25 1.4.0.4) popisují režim platný
     * od spuštění registrační agendy. Pro starší rozhodné datum se neodvozuje
     * nic — resolver raději nevrátí interakci, než aby hádal starý formulář.
     *
     * ČSSZ umožnila podat P1 už osm dnů před účinností povinnosti od 1. 7. 2026,
     * tedy od 23. 6. 2026. Datum je ověřené oficiální metodikou PREZEC 1.4.
     */
    private const SUPPORTED_FROM = '2026-06-23';

    /**
     * @param array{
     *   work_started:bool,full_registration_data:bool,
     *   pre_registration_accepted:bool,did_not_start:bool,
     *   employment_ended:bool,event_interaction:?string,
     *   activity_code?:?string,relationship_detail_code?:?string,
     *   regzec_variant_data_complete?:bool
     * } $context
     */
    public function resolve(
        PayrollRegistrationIdentitySnapshot $snapshot,
        array $context,
    ): PayrollRegistrationInteraction {
        $eventInteraction = $context['event_interaction'] ?? null;
        if (!is_string($eventInteraction)
            && $snapshot->scope['effective_on'] < self::SUPPORTED_FROM
        ) {
            $this->invalid(
                'registration_interaction_before_supported_window',
                'PREZEC/REGZEC tok není podporován před začátkem ověřeného okna.',
            );
        }
        $citizenship = $snapshot->identity['citizenship_country_code'] ?? null;
        if (!is_string($citizenship)) {
            $this->invalid(
                'registration_interaction_citizenship_unverified',
                'Bez ověřeného státního občanství nelze rozlišit částečné přihlášení a plnou registraci.',
            );
        }
        if (is_string($eventInteraction)) {
            $definition = PayrollRegistrationInteraction::SUPPORTED[$eventInteraction]
                ?? null;
            if ($definition === null
                || $definition['document_type'] !== 'REGZEC25'
                || $definition['action_code'] < 2
                || $definition['action_code'] > 8
            ) {
                $this->invalid(
                    'registration_event_interaction_invalid',
                    'Neměnný zdroj neodpovídá podporované interakci REGZEC A2–A8.',
                );
            }

            return $this->forSnapshot(
                $snapshot,
                'REGZEC25',
                $eventInteraction,
                $definition['action_code'],
            );
        }
        if ($context['did_not_start']) {
            if (!$context['pre_registration_accepted']) {
                $this->invalid(
                    'registration_interaction_no_show_without_p1',
                    'PREZEC P2 vyžaduje přijatou předregistraci P1.',
                );
            }

            return $this->forSnapshot(
                $snapshot,
                'PREZEC26',
                'pre_registration_no_show',
                10,
            );
        }
        if ($context['employment_ended'] ?? false) {
            $this->invalid(
                'registration_regzec_a2_source_missing',
                'Skončený pracovní vztah vyžaduje samostatný schválený zdroj REGZEC A2; nelze za něj znovu vytvořit přihlášku A1.',
            );
        }
        if ($this->agendaFor($citizenship, $context) === 'REGZEC25') {
            if (!$context['full_registration_data']) {
                $this->invalid(
                    'registration_interaction_full_data_missing',
                    'REGZEC A1 vyžaduje úplnou a samostatně ověřenou datovou sadu.',
                );
            }
            PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                is_string($context['activity_code'] ?? null)
                    ? $context['activity_code']
                    : null,
                is_string($context['relationship_detail_code'] ?? null)
                    ? $context['relationship_detail_code']
                    : null,
                ($context['regzec_variant_data_complete'] ?? false) === true,
            );

            return $this->forSnapshot(
                $snapshot,
                'REGZEC25',
                $context['pre_registration_accepted']
                    ? 'full_registration_after_p1'
                    : 'direct_full_registration',
                1,
            );
        }
        if ($context['pre_registration_accepted']) {
            $this->invalid(
                'registration_interaction_duplicate_p1',
                'Přijatou PREZEC P1 nelze opakovat.',
            );
        }

        return $this->forSnapshot(
            $snapshot,
            'PREZEC26',
            'limited_pre_registration',
            9,
        );
    }

    /**
     * Do které agendy interakce spadne.
     *
     * Volá to resolver při rozhodování i most, který podle toho musí zmrazit
     * snapshot DŘÍV, než interakci vůbec zná: snapshot nese agendu ve svém
     * rozsahu a `assertBoundToSnapshot()` pak trvá na shodě. Bez společné
     * implementace by předvýběr agendy v mostu a rozhodnutí resolveru byly
     * dva zdroje pravdy, které se rozejdou při první změně pravidel.
     *
     * @param array{
     *   work_started:bool,full_registration_data:bool,
     *   pre_registration_accepted:bool,did_not_start:bool,
     *   employment_ended:bool,event_interaction:?string,
     *   activity_code?:?string,relationship_detail_code?:?string,
     *   regzec_variant_data_complete?:bool
     * } $context
     */
    public function agendaFor(
        ?string $citizenshipCountryCode,
        array $context,
    ): string {
        if (is_string($context['event_interaction'] ?? null)) {
            return 'REGZEC25';
        }
        if ($context['did_not_start']) {
            return 'PREZEC26';
        }
        if ($context['work_started']
            || $context['full_registration_data']
            || $citizenshipCountryCode !== 'CZ'
        ) {
            return 'REGZEC25';
        }

        return 'PREZEC26';
    }

    private function forSnapshot(
        PayrollRegistrationIdentitySnapshot $snapshot,
        string $documentType,
        string $interaction,
        int $actionCode,
    ): PayrollRegistrationInteraction {
        $candidate = new PayrollRegistrationInteraction(
            $documentType,
            $interaction,
            $actionCode,
        );
        $this->assertBoundToSnapshot($snapshot, $candidate);

        return $candidate;
    }

    /**
     * Jediná implementace vazby „interakce ↔ zmrazený snapshot". Volá ji
     * resolver při odvození i validátor před přijetím bajtů:
     * `PayrollRegistrationXmlPayload` je volně sestavitelný, takže resolver
     * jde obejít a jednomístná pojistka by nebyla brána.
     */
    public function assertBoundToSnapshot(
        PayrollRegistrationIdentitySnapshot $snapshot,
        PayrollRegistrationInteraction $interaction,
    ): void {
        $documentType = $interaction->documentType;
        if ($snapshot->scope['agenda_code'] !== $documentType) {
            $this->invalid(
                'registration_interaction_snapshot_agenda_mismatch',
                'Zmrazený snapshot patří jiné registrační agendě.',
            );
        }
        // Způsobilost počítá snapshot builder a zmrazí ji do neměnného
        // snapshotu; resolver ji smí jen přečíst. Jiný `basis` znamená, že
        // snapshot vznikl podle jiného výkladu agendy, než jaký core umí.
        [$expectedStatus, $expectedBasis] = match ($documentType) {
            'PREZEC26' => ['verified', 'domestic_citizenship_country_code'],
            default => ['not_applicable', 'agenda_not_prezec'],
        };
        $eligibility = $snapshot->registrationEligibility;
        if (($eligibility['status'] ?? null) !== $expectedStatus
            || ($eligibility['basis'] ?? null) !== $expectedBasis
        ) {
            $this->invalid(
                'registration_interaction_eligibility_basis_unsupported',
                'Způsobilost zmrazeného snapshotu neodpovídá dnešnímu výkladu registrační agendy.',
            );
        }
        // PREZEC26 má `client/@bno` povinné a nemá jediné pole, kterým by se
        // identifikátor teprve přiděloval — částečné přihlášení tedy dává smysl
        // jen u osoby, která už rodné číslo nebo EČP má. Rozsah identifikátorů
        // určuje výhradně `PayrollRegistrationIdentitySnapshotBuilder`
        // (viz `bno` = „Rodné číslo / EČP (ID 10057)" v připnutém XSD);
        // resolver ho nesmí zúžit, jinak vznikne druhý zdroj pravdy.
        if ($documentType === 'PREZEC26'
            && $snapshot->identifiers['birth_number'] === null
            && $snapshot->identifiers['ecp'] === null
        ) {
            $this->invalid(
                'registration_prezec_identifier_required',
                'PREZEC vyžaduje přidělené rodné číslo nebo EČP; bez osobního identifikátoru nelze částečné přihlášení podat.',
            );
        }
        if (!$interaction->supported()) {
            $this->invalid(
                'registration_interaction_unsupported',
                'Registrační interakce není v podporovaném katalogu.',
            );
        }
    }

    private function invalid(string $code, string $message): never
    {
        throw new PayrollRegistrationXmlException($code, $message);
    }
}
