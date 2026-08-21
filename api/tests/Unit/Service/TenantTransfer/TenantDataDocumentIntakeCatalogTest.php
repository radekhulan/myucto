<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDocumentIntakeCatalog;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class TenantDataDocumentIntakeCatalogTest extends TestCase
{
    public function testIntakeRowsAreTenantOwned(): void
    {
        $definitions = self::definitions();

        self::assertSame(
            ['document_requests', 'purchase_invoice_submissions'],
            array_keys($definitions),
        );
        foreach ($definitions as $definition) {
            self::assertSame(TenantDataPolicy::TenantOwned, $definition->policy);
            self::assertSame(
                [TenantDataRegistry::TRANSFER_PROFILE],
                $definition->profiles,
            );
            self::assertSame(['id'], $definition->details['primary_key'] ?? null);
            self::assertSame(
                'documents',
                $definition->details['feature_group'] ?? null,
            );
            self::assertSame(
                [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                $definition->details['ownership'] ?? null,
            );
            self::assertSame([], $definition->details['secrets'] ?? null);
        }
    }

    public function testHistoricalActorsMapOnlyToExistingTargetUsers(): void
    {
        $definitions = self::definitions();

        self::assertSame(
            [
                'submitted_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'processed_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $definitions['purchase_invoice_submissions']
                ->details['actor_references'] ?? null,
        );
        self::assertSame(
            [
                'created_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
                'resolved_by' => [
                    'strategy' => 'map_existing_user_or_null',
                ],
            ],
            $definitions['document_requests']
                ->details['actor_references'] ?? null,
        );
    }

    public function testInFlightExtractionNeverResumesAsRunning(): void
    {
        $submission = self::definitions()['purchase_invoice_submissions'];

        self::assertSame(
            [
                'state_transforms' => [
                    'status' => ['processing' => 'submitted'],
                    'extraction_status' => ['running' => 'not_started'],
                ],
                'force_columns' => [
                    'processing_started_at' => null,
                ],
                'reason' =>
                    'in_flight_document_extraction_requires_explicit_restart',
            ],
            $submission->details['post_import'] ?? null,
        );
        self::assertSame(
            'preserve_submission_history_without_reprocessing',
            $submission->details['transfer_invariant'] ?? null,
        );
    }

    public function testRequestRemindersStayDisabledAfterImport(): void
    {
        $request = self::definitions()['document_requests'];

        self::assertSame(
            [
                'external_automations' => [
                    'document_request_reminders' =>
                        'disabled_until_manual_reactivation',
                ],
                'reason' =>
                    'transferred_requests_must_not_send_automatically',
            ],
            $request->details['post_import'] ?? null,
        );
        self::assertSame(
            'preserve_request_history_without_sending_reminders',
            $request->details['transfer_invariant'] ?? null,
        );
    }

    public function testFactoryPublishesIntakeOnlyForTransfer(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $transfer = self::keys(
            $registry,
            TenantDataRegistry::TRANSFER_PROFILE,
        );
        $archive = self::keys(
            $registry,
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        );

        foreach ([
            'document_requests',
            'purchase_invoice_submissions',
        ] as $table) {
            self::assertContains('table:' . $table, $transfer);
            self::assertNotContains('table:' . $table, $archive);
        }
    }

    /** @return array<string,TenantDataDefinition> */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (TenantDataDocumentIntakeCatalog::definitions() as $definition) {
            self::assertStringStartsWith('table:', $definition->key);
            $definitions[substr($definition->key, strlen('table:'))] =
                $definition;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }

    /** @return list<string> */
    private static function keys(
        TenantDataRegistry $registry,
        string $profile,
    ): array {
        return array_map(
            static fn (TenantDataDefinition $definition): string =>
                $definition->key,
            $registry->definitionsFor($profile),
        );
    }
}
