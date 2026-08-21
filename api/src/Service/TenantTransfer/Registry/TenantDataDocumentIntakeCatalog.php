<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Podatelna přijatých dokladů a požadavky na jejich doplnění. */
final class TenantDataDocumentIntakeCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::owned(
                'purchase_invoice_submissions',
                [
                    'submitted_by' => 'map_existing_user_or_null',
                    'processed_by' => 'map_existing_user_or_null',
                ],
                [
                    'post_import' => [
                        'state_transforms' => [
                            'status' => [
                                'processing' => 'submitted',
                            ],
                            'extraction_status' => [
                                'running' => 'not_started',
                            ],
                        ],
                        'force_columns' => [
                            'processing_started_at' => null,
                        ],
                        'reason' =>
                            'in_flight_document_extraction_requires_explicit_restart',
                    ],
                    'transfer_invariant' =>
                        'preserve_submission_history_without_reprocessing',
                ],
            ),
            self::owned(
                'document_requests',
                [
                    'created_by' => 'map_existing_user_or_null',
                    'resolved_by' => 'map_existing_user_or_null',
                ],
                [
                    'post_import' => [
                        'external_automations' => [
                            'document_request_reminders' =>
                                'disabled_until_manual_reactivation',
                        ],
                        'reason' =>
                            'transferred_requests_must_not_send_automatically',
                    ],
                    'transfer_invariant' =>
                        'preserve_request_history_without_sending_reminders',
                ],
            ),
        ];
    }

    /**
     * @param array<string,string> $actorReferences
     * @param array<string,mixed> $additionalDetails
     */
    private static function owned(
        string $table,
        array $actorReferences,
        array $additionalDetails,
    ): TenantDataDefinition {
        $actors = [];
        foreach ($actorReferences as $column => $strategy) {
            $actors[$column] = ['strategy' => $strategy];
        }

        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'documents',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'actor_references' => $actors,
                ...$additionalDetails,
            ],
        );
    }
}
