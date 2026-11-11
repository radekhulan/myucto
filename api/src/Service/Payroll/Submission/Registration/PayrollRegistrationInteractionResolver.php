<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationInteractionResolver
{
    /**
     * Částečné přihlášení PREZEC P1 jde podat nejdřív osm dnů před účinností
     * povinnosti od 1. 7. 2026, tedy od 23. 6. 2026 (metodika PREZEC 1.4).
     *
     * Plná registrace REGZEC takovou hranici NEMÁ: podle „Pravidel pro
     * REGZEC" (ČSSZ, 30. 1. 2026) se událost, která nastala do 31. 3. 2026
     * a nebyla do té doby ohlášena, od 1. 4. 2026 hlásí jen přes REGZEC.
     * Cizí programy takové přihlášky s nástupem od ledna 2026 podávaly a ČSSZ
     * je přijala; lhůtu u nich jen neodvozujeme.
     */
    private const SUPPORTED_FROM = '2026-06-23';

    /**
     * @param array{
     *   work_started:bool,full_registration_data:bool,
     *   pre_registration_accepted:bool,did_not_start:bool,
     *   employment_ended:bool,event_interaction:?string,
     *   activity_code?:?string,relationship_detail_code?:?string,
     *   regzec_variant_data_complete?:bool,full_registration_requested?:bool
     * } $context
     */
    public function resolve(
        PayrollRegistrationIdentitySnapshot $snapshot,
        array $context,
    ): PayrollRegistrationInteraction {
        $eventInteraction = $context['event_interaction'] ?? null;
        $citizenship = $snapshot->identity['citizenship_country_code'] ?? null;
        if (!is_string($eventInteraction)
            && $snapshot->scope['effective_on'] < self::SUPPORTED_FROM
            && $this->agendaFor(
                is_string($citizenship) ? $citizenship : null,
                $context,
            ) === 'PREZEC26'
        ) {
            $this->invalid(
                'registration_interaction_before_supported_window',
                'Částečné přihlášení před nástupem (PREZEC P1) jde podat až '
                    . 'od 23. 6. 2026. Tenhle pracovní vztah začíná dřív, takže '
                    . 'se zaměstnanec přihlašuje plnou registrací REGZEC A1 — '
                    . 'vyplňte profil registrace a podejte ji.',
            );
        }
        if (!is_string($citizenship)) {
            $this->invalid(
                'registration_interaction_citizenship_unverified',
                PayrollRegistrationFieldVocabulary::label('citizenship_country_code')
                    . ' chybí. Podle něj se pozná, jestli stačí částečné '
                    . 'přihlášení (PREZEC), nebo je nutná plná registrace '
                    . '(REGZEC). '
                    . PayrollRegistrationFieldVocabulary::describe(
                        'citizenship_country_code',
                    ),
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
                    'Tenhle druh navazujícího oznámení appka neumí připravit. '
                        . 'Podat jde skončení vztahu, změna a oprava údajů, '
                        . 'převod pod jiný variabilní symbol, vznik i skončení '
                        . 'příslušnosti k českým předpisům a storno '
                        . 'přihlášení; ostatní podejte přes portál ČSSZ.',
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
                    'Oznámení, že zaměstnanec nenastoupil (PREZEC P2), se podává '
                    . 'jen k částečnému přihlášení (PREZEC P1), které už ČSSZ '
                    . 'přijala. Tady žádné přijaté P1 není — pokud jste '
                    . 'zaměstnance přihlašovali plnou registrací REGZEC, '
                    . 'použijte storno přihlášení (REGZEC A8).',
                );
            }

            return $this->forSnapshot(
                $snapshot,
                'PREZEC26',
                'pre_registration_no_show',
                10,
            );
        }
        // Skončený vztah, který u ČSSZ přihlášený NENÍ (nemá ID PPV), se
        // přihlašuje dodatečně přihláškou A1 a skončení pak hlásí odhláška A2
        // jako u každého jiného vztahu: A2 bez přihlášky (bez ID PPV) podat
        // nejde. Bez toho by brigáda, kterou noční úloha po sjednaném konci
        // ukončila dřív, než za ni účetní podala A1, zůstala nepřihlášená bez
        // cesty dál. Údaje pro podporu v nezaměstnanosti a 10224 nese až A2;
        // A1 s vyplněným 10224 by je podle EDV (ID 10224, 10376 a násl.)
        // musela nést sama.
        $registered = is_string($snapshot->employmentExternalIdentifier['value'] ?? null);
        if (($context['employment_ended'] ?? false) && $registered) {
            $this->invalid(
                'registration_regzec_a2_source_missing',
                'Pracovní vztah už skončil, takže za něj nejde znovu podat '
                . 'přihlášení. Skončení se hlásí samostatným oznámením '
                . '(REGZEC A2), které se zakládá u pracovního vztahu.',
            );
        }
        if ($this->agendaFor($citizenship, $context) === 'REGZEC25') {
            if (!$context['full_registration_data']) {
                $this->invalid(
                    'registration_interaction_full_data_missing',
                    'Přihlášení zaměstnance (REGZEC A1) nejde sestavit, protože '
                    . 'u zaměstnavatele chybí název, variabilní symbol mzdové '
                    . 'účtárny nebo kód správy sociálního zabezpečení. Doplňte '
                    . 'je v Nastavení mezd a v účtárně vztahu; údaje '
                    . 'zaměstnance ukáže tlačítko Kontrola u registrace.',
                );
            }
            $this->assertA1Snapshot($snapshot);

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
                'Částečné přihlášení před nástupem (PREZEC P1) už ČSSZ přijala, '
                . 'takže se podruhé nepodává. Navažte plnou registrací '
                . '(REGZEC A1) — v registraci u vztahu zvolte „Plná '
                . 'registrace A1", jde to i před nástupem; pokud zaměstnanec '
                . 'nenastoupil, podejte oznámení PREZEC P2.',
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
     *   regzec_variant_data_complete?:bool,full_registration_requested?:bool
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
        // Před nástupem si český zaměstnavatel vybírá: částečné přihlášení
        // (PREZEC P1) je výchozí, plnou registraci A1 volí výslovně. Zákon
        // připouští obojí (§ 19 odst. 1 písm. a) a odst. 2 zákona
        // č. 323/2025 Sb.); cizinec částečně přihlásit nejde.
        if ($context['work_started']
            || ($context['full_registration_requested'] ?? false)
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
                'Podání bylo připravené pro jiný formulář ČSSZ, než jaký se '
                    . 'teď odesílá. Zavřete podání, otevřete registraci '
                    . 'u pracovního vztahu znovu a připravte ho ještě jednou.',
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
                'Podání vzniklo podle staršího posouzení, jestli na zaměstnance '
                    . 'dosáhne částečné přihlášení. Zavřete ho a připravte '
                    . 'registraci znovu, ať se posouzení udělá z aktuálních '
                    . 'údajů.',
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
                'Rodné číslo ani evidenční číslo pojištěnce (EČP) nejsou '
                    . 'vyplněné. Částečné přihlášení před nástupem (PREZEC) '
                    . 'se bez jednoho z nich podat nedá — rodné číslo má 9 '
                    . 'nebo 10 číslic bez lomítka. Údaj doplňte na '
                    . PayrollRegistrationFieldVocabulary::WHERE_IDENTIFIERS
                    . '; pokud zaměstnanec ani jedno nemá, podejte místo toho '
                    . 'plnou registraci REGZEC.',
            );
        }
        if (!$interaction->supported()) {
            $this->invalid(
                'registration_interaction_unsupported',
                PayrollRegistrationFieldVocabulary::action(
                    $documentType,
                    $interaction->actionCode,
                ) . ' se v této kombinaci nepodává — appka umí jen podání '
                    . 'z nabídky u pracovního vztahu. Vyberte druh podání znovu '
                    . 'z nabídky.',
            );
        }
        if ($documentType === 'REGZEC25'
            && $interaction->actionCode === 1
        ) {
            $this->assertA1Snapshot($snapshot);
        }
    }

    private function assertA1Snapshot(
        PayrollRegistrationIdentitySnapshot $snapshot,
    ): void {
        PayrollRegistrationBusinessMatrix::requireA1Variant($snapshot->regzecA1);
    }

    /**
     * Výjimka tu zůstává vědomě: resolver rozhoduje, JAKÉ podání se vyrobí.
     * Bez odpovědi nemá co vrátit, takže „sbírat vady" tu nedává smysl — a na
     * ukládání rozpracovaného profilu resolver vůbec nesahá, ten běží přes
     * `PayrollRegistrationA1SnapshotBuilder::problems()`.
     */
    private function invalid(string $code, string $message): never
    {
        throw new PayrollRegistrationXmlException($code, $message);
    }
}
