<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Podání převzaté anonymizovanou kopií z finančního archivu nese jen mapu transportu
 * zdrojové instalace. Potvrzení o odeslání původního XML nedokládá odeslání XML
 * pseudonymizovaného, proto takové podání nejde v kopii znovu odeslat ani zařadit do
 * fronty. Podání založená až po kopii mapovaná nejsou a mají běžný průběh.
 */
final readonly class AnonymizedCloneSubmissionProvenance
{
    private const TABLE = 'anonymized_clone_submission_provenance';

    public function __construct(private Connection $db) {}

    public function assertDispatchable(int $supplierId, int $taxSubmissionId): void
    {
        if (!$this->db->hasTable(self::TABLE)) {
            return;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE supplier_id = ? AND tax_submission_id = ? LIMIT 1',
        );
        $statement->execute([$supplierId, $taxSubmissionId]);
        if ($statement->fetchColumn() === false) {
            return;
        }

        throw new SubmissionChannelException(
            'anonymized_clone_archived_submission',
            'Archivované podání v anonymizované kopii nese jen záznam o transportu zdrojové instalace a nelze je znovu odeslat.',
            409,
        );
    }
}
