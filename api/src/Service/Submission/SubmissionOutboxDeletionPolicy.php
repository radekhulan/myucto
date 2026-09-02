<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Submission\Channel\AcceptanceState;
use MyInvoice\Service\Submission\Channel\DispatchState;

/**
 * Jediné místo, které rozhoduje, jestli SMÍ zrušená odchozí zpráva zmizet.
 *
 * ── Co se tu vlastně posuzuje ────────────────────────────────────────────────
 * Řádek `submission_outbox` NENÍ podání. Je to jeden pokus dopravit podklad
 * k úřadu. Zrušením ho účetní stáhla zpátky ještě před odesláním — a takový
 * řádek nedokládá nic, takže nemá důvod zůstávat ve výpisu navždy.
 *
 * Jakmile ale zpráva jednou opustila aplikaci, je to DOKLAD a nemaže se nikdy.
 * Stejný princip drží archiv EPO snapshotů („Doklad o skutečně podaném podání
 * smazat nelze."), jen se tady posuzuje víc stop, protože datovkou vede víc
 * cest ven.
 *
 * ── Proč pravidlo NENÍ schované v akci ani v repozitáři ─────────────────────
 * Odpověď na otázku „jde to smazat?" potřebuje výpis (aby tlačítko vůbec
 * nenabídl) i vlastní mazání (aby ho odmítlo). Kdyby to byl privátní helper
 * jedné z těch cest, druhá by si ho okopírovala a rozešly by se.
 *
 * Třída je záměrně BEZ závislostí: fakta si obstará volající (řádek + dvě
 * shrnutí z repozitářů) a tady se jen vyhodnotí, takže ji lze zavolat
 * odkudkoli a otestovat bez databáze.
 */
final class SubmissionOutboxDeletionPolicy
{
    /**
     * Zpráva jde smazat jen ze stavu „zrušeno". Ostatní stavy tlačítko
     * nedostanou — `ready` čeká na odeslání, `sent` a výš je doklad,
     * `sending`/`send_uncertain` je nevědomost, kterou maže leda dohledání.
     */
    public const DELETABLE_STATE = DispatchState::Cancelled;

    /**
     * Důvody, proč se mazat nesmí. Kód se překládá v UI
     * (`databox.outbox.deleteBlocked.*`), takže výčet musí zůstat uzavřený.
     */
    public const REASONS = ['state', 'sent', 'receipt', 'decided', 'attempt', 'gateway', 'linked'];

    /**
     * @param array<string,mixed> $row řádek fronty
     * @param array{total:int,left_application:int} $attempts {@see \MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository::deletionEvidence()}
     * @param array{
     *   inbox_messages:int,defect_notices:int,
     *   gateway_sessions:int,enforcement_dispatches:int
     * } $links {@see \MyInvoice\Repository\Submission\SubmissionOutboxRepository::linkedRecordCounts()}
     *
     * @return ?string kód důvodu, nebo null když se mazat smí
     */
    public static function blockingReason(array $row, array $attempts, array $links): ?string
    {
        if ((string) $row['dispatch_state'] !== self::DELETABLE_STATE->value) {
            return 'state';
        }

        // ── 1. Stopy po tom, že zpráva odešla ──
        // Kterákoli z nich sama o sobě stačí. `sent_at` a `external_message_id`
        // jsou v DB jednorázové přiřazení, takže je nemá kdo uklidit; pokud na
        // zrušeném řádku jsou, znamená to, že zpráva ven prokazatelně šla.
        if ($row['external_message_id'] !== null
            || $row['sent_at'] !== null
            || $row['delivered_at'] !== null
            || $attempts['left_application'] > 0
        ) {
            return 'sent';
        }

        // ── 2. Doručenka a příchozí dokumenty ──
        if ($row['receipt_document_id'] !== null
            || ($row['receipt_inbox_message_id'] ?? null) !== null
            || $links['inbox_messages'] > 0
        ) {
            return 'receipt';
        }

        // ── 3. Rozhodnutí úřadu ──
        if ((string) $row['acceptance_state'] !== AcceptanceState::Unknown->value) {
            return 'decided';
        }

        // ── 4. Auditní stopy, které přežívají samotnou zprávu ──
        // Neúspěšný pokus, který skončil chybou JEŠTĚ PŘED odesláním, sám
        // o sobě neznamená, že zpráva odešla — proto nespadl do bodu 1. Řádek
        // ledgeru je ale append-only (DB trigger i FK `ON DELETE RESTRICT`)
        // a zahodit se nedá, takže s ním nemůže zmizet ani zpráva. Uživateli
        // se to řekne jako důvod, ne jako selhání někde v půlce mazání.
        if ($attempts['total'] > 0) {
            return 'attempt';
        }
        if ($links['gateway_sessions'] > 0) {
            return 'gateway';
        }
        if ($links['defect_notices'] > 0 || $links['enforcement_dispatches'] > 0) {
            return 'linked';
        }

        return null;
    }
}
