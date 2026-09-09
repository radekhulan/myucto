<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;

/**
 * Dotažení protokolu a uzavření transakce BEZ UŽIVATELE.
 *
 * Do téhle třídy se přesunulo to, co dřív dělal člověk klikáním: dokud ČSSZ
 * protokol nevydala, musel se sám ptát, a po dotažení sám mačkat „uzavřít".
 * Kdo přestal klikat, nechal podání viset ve stavu „převzato" a transakci
 * u VREP otevřenou — a podací protokol její uzavření vyžaduje.
 *
 * Čtyři pravidla, na kterých to stojí:
 *
 * 1. **Žádný druhý mechanismus.** Stav běhu žije v append-only ledgeru pokusů
 *    (`payroll_submission_transport_attempts`), ne ve vlastní frontě. Rozvrh
 *    drží {@see JmhzPollSchedule}, samotný dotaz i uzavření jde přes tutéž
 *    {@see JmhzDispatchService}, jakou volá tlačítko v UI.
 * 2. **Fail-closed.** Dotaz, který selhal nebo vrátil nesrozumitelnou odpověď,
 *    NIKDY neposune pokus na hotovo. Prázdná odpověď není „nic tu není", ale
 *    „nevíme"; pokus zůstává otevřený a důvod je v ledgeru vidět.
 * 3. **Idempotence.** Dvojí spuštění nezaloží druhý pokus ani druhé uzavření:
 *    fronta bere jen řádky, kterým dozrál termín, a každý zápis posouvá
 *    `row_version`, takže souběžný běh prohraje optimistický zámek a jeho
 *    zápis se zahodí.
 * 4. **Vzdát to nahlas.** Po stropu (stáří odeslání, počet dotazů) se pokus
 *    uzavře jako `expired` a povinnost se překlopí do `manual_review` —
 *    inbox podání (MZ-19-W09) z toho udělá položku, kterou uživatel uvidí.
 *    Tiché vzdání by bylo horší než opakování donekonečna.
 */
final readonly class JmhzTransportSweepService
{
    private const GAVE_UP_CODE = 'jmhz_protocol_not_delivered';

    public function __construct(
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private PayrollSubmissionRepository $submissionRepository,
        private JmhzFrozenPayloadReader $frozen,
        private JmhzDispatchService $dispatch,
    ) {}

    /**
     * Jeden průchod frontou.
     *
     * @return array{
     *   polled:int,completed:int,pending:int,expired:int,
     *   closed:int,close_failed:int,errors:int,skipped?:string
     * }
     */
    public function run(int $limit = 50): array
    {
        $result = [
            'polled' => 0,
            'completed' => 0,
            'pending' => 0,
            'expired' => 0,
            'closed' => 0,
            'close_failed' => 0,
            'errors' => 0,
        ];
        if (!$this->attempts->isAvailable()) {
            $result['skipped'] = 'ledger_missing';

            return $result;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($this->attempts->listDuePolls($limit) as $attempt) {
            $this->pollOne($attempt, $now, $result);
        }
        foreach (
            $this->attempts->listDueCloses($limit, JmhzPollSchedule::MAX_CLOSE_ATTEMPTS)
            as $attempt
        ) {
            $this->closeOne($attempt, $result);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array{
     *   polled:int,completed:int,pending:int,expired:int,
     *   closed:int,close_failed:int,errors:int,skipped?:string
     * } $result
     * @param-out array{
     *   polled:int,completed:int,pending:int,expired:int,
     *   closed:int,close_failed:int,errors:int,skipped?:string
     * } $result
     */
    private function pollOne(array $attempt, \DateTimeImmutable $now, array &$result): void
    {
        $exhausted = JmhzPollSchedule::exhaustedReason(
            $now,
            self::nullableString($attempt['sent_at'] ?? null),
            (int) ($attempt['poll_count'] ?? 0),
        );
        if ($exhausted !== null) {
            $this->giveUp($attempt, $exhausted, $result);

            return;
        }

        try {
            $variableSymbol = $this->variableSymbol($attempt);
        } catch (\Throwable $exception) {
            // Bez variabilního symbolu se nemáme jak zeptat. Není to důvod
            // k opakování donekonečna: pokus se uzavře a člověk to uvidí.
            $this->giveUp(
                $attempt,
                'Nepodařilo se zjistit variabilní symbol odeslaného hlášení ('
                    . $exception->getMessage() . '), takže se na jeho výsledek'
                    . ' nelze zeptat. Zkontrolujte podání na ePortálu ČSSZ.',
                $result,
            );

            return;
        }

        ++$result['polled'];
        try {
            $outcome = $this->dispatch->poll(
                (int) $attempt['supplier_id'],
                (string) $attempt['environment'],
                (int) $attempt['id'],
                $variableSymbol,
            );
        } catch (\Throwable) {
            // Důvod už zapsal dispatch do ledgeru; sem patří jen počet.
            ++$result['errors'];

            return;
        }

        if (!$outcome->isSettled()
            || $outcome->report?->status === JmhzSubmissionStatus::Processing
        ) {
            ++$result['pending'];

            return;
        }
        ++$result['completed'];
        // Uzavírá se hned po dotažení, dokud je transakce čerstvá. Když to
        // nevyjde, zůstane pokus ve frontě na uzavření a zkusí se znovu.
        $this->closeOne(
            $this->attempts->find(
                (int) $attempt['supplier_id'],
                (string) $attempt['environment'],
                (int) $attempt['id'],
            ) ?? $outcome->attempt,
            $result,
        );
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array{
     *   polled:int,completed:int,pending:int,expired:int,
     *   closed:int,close_failed:int,errors:int,skipped?:string
     * } $result
     * @param-out array{
     *   polled:int,completed:int,pending:int,expired:int,
     *   closed:int,close_failed:int,errors:int,skipped?:string
     * } $result
     */
    private function closeOne(array $attempt, array &$result): void
    {
        if (($attempt['status'] ?? null) !== 'completed'
            || ($attempt['closed_at'] ?? null) !== null
        ) {
            return;
        }
        try {
            $variableSymbol = $this->variableSymbol($attempt);
            $closed = $this->dispatch->close(
                (int) $attempt['supplier_id'],
                (string) $attempt['environment'],
                (int) $attempt['id'],
                $variableSymbol,
            );
        } catch (\Throwable) {
            ++$result['close_failed'];
            $this->escalateUnclosed($attempt);

            return;
        }
        if (!$closed['already_closed']) {
            ++$result['closed'];
        }
    }

    /**
     * Pokus, u kterého se transakce nedaří uzavřít ani po stropu pokusů, je
     * porušení pravidel provozu a musí ho vidět člověk — tlačítko „Uzavřít
     * transakci" v UI zůstává dostupné právě pro tenhle případ.
     *
     * @param array<string,mixed> $attempt
     */
    private function escalateUnclosed(array $attempt): void
    {
        if ((int) ($attempt['close_attempts'] ?? 0) + 1 < JmhzPollSchedule::MAX_CLOSE_ATTEMPTS) {
            return;
        }
        $this->flagManualReview($attempt);
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array{
     *   polled:int,completed:int,pending:int,expired:int,
     *   closed:int,close_failed:int,errors:int,skipped?:string
     * } $result
     * @param-out array{
     *   polled:int,completed:int,pending:int,expired:int,
     *   closed:int,close_failed:int,errors:int,skipped?:string
     * } $result
     */
    private function giveUp(array $attempt, string $reason, array &$result): void
    {
        try {
            $this->attempts->markExpired(
                (int) $attempt['id'],
                self::GAVE_UP_CODE,
                $reason,
                (int) $attempt['row_version'],
            );
        } catch (\Throwable) {
            // Souběžný běh nás předběhl — pak už je pokus uzavřený i bez nás.
            ++$result['errors'];

            return;
        }
        ++$result['expired'];
        $this->flagManualReview($attempt);
    }

    /**
     * Překlopení povinnosti do `manual_review`. Inbox podání (MZ-19-W09) z ní
     * při nejbližší synchronizaci udělá položku k ruční kontrole — vlastní
     * upozorňovací kanál se tu proto nezakládá.
     *
     * @param array<string,mixed> $attempt
     */
    private function flagManualReview(array $attempt): void
    {
        try {
            $obligation = $this->submissionRepository->findObligationOfSubmission(
                (int) $attempt['supplier_id'],
                (string) $attempt['environment'],
                (int) $attempt['submission_id'],
            );
            if ($obligation === null
                || in_array($obligation['status'], ['manual_review', 'fulfilled'], true)
            ) {
                return;
            }
            $this->submissionRepository->updateObligationStatus(
                (int) $attempt['supplier_id'],
                (string) $attempt['environment'],
                $obligation['id'],
                $obligation['row_version'],
                'manual_review',
            );
        } catch (\Throwable) {
            // Eskalace je nadstavba nad ledgerem; její selhání nesmí shodit běh
            // ani přebít to, co už je zapsané jako důkaz.
            return;
        }
    }

    /** @param array<string,mixed> $attempt */
    private function variableSymbol(array $attempt): string
    {
        return $this->frozen->identity(
            (int) $attempt['supplier_id'],
            (string) $attempt['environment'],
            (int) $attempt['submission_id'],
        )->variableSymbol;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
