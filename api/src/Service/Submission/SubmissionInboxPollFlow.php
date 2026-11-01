<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransport;
use Psr\Log\LoggerInterface;

/**
 * Ruční načtení datové schránky: příchozí zprávy a v téže relaci i doručenky.
 *
 * Doručenka odeslané zprávy není příchozí zpráva, takže ji samotné vyzvednutí
 * schránky nepřinese. Účetní si schránku načetla, u podání dál svítilo „čeká na
 * doručenku" a povinnost se neuzavřela, dokud nenašla jinou akci na jiné
 * stránce. Přihlášení už je potvrzené, druhé by bylo jen další ceremonie.
 *
 * Doručenky jsou tu vedlejší krok: jejich selhání nesmí změnit výsledek už
 * provedeného vyzvednutí. Ptáme se jen na vlastní odeslané zprávy, takže to
 * nic nedoručuje a žádnou lhůtu nespouští.
 */
final readonly class SubmissionInboxPollFlow
{
    public function __construct(
        private SubmissionInboxService $inbox,
        private DeliveryReceiptService $receipts,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{
     *   fetched:int,stored:int,skipped:int,failed:int,unclassified:int,error:?string,
     *   receipts:array{attached:int,pending:int,failed:int}|null
     * }
     */
    public function poll(ChannelContext $context, IsdsTransport $transport, ?int $actorUserId): array
    {
        $result = $this->inbox->pollWithChannel(
            $context,
            'isds',
            new IsdsChannel($transport),
            500,
            $actorUserId,
        );
        $reachable = $result['error'] === null || $result['fetched'] > 0;

        return $result + [
            'receipts' => $reachable && $actorUserId !== null
                ? $this->collectReceipts($context, $transport, $actorUserId)
                : null,
        ];
    }

    /** @return array{attached:int,pending:int,failed:int}|null */
    private function collectReceipts(ChannelContext $context, IsdsTransport $transport, int $actorUserId): ?array
    {
        try {
            $batch = $this->receipts->downloadManyFromIsds(
                $context->supplierId,
                $context->environment,
                $actorUserId,
                $context,
                $transport,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Doručenky se po načtení schránky nestáhly.', [
                'supplier_id' => $context->supplierId,
                'exception' => $e::class,
            ]);

            return null;
        }

        return [
            'attached' => $batch['attached'],
            'pending' => $batch['pending'],
            'failed' => $batch['failed'],
        ];
    }
}
