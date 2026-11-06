<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

enum FinancialArchiveJsonColumn
{
    case RETURN_SNAPSHOT;
    case RETURN_SOURCE_MANIFEST;
    case RETURN_BUSINESS_ERRORS;
    case ASSESSMENT_SNAPSHOT;
    case ASSESSMENT_FACTS;
    case ASSESSMENT_RESULT;

    public function table(): string
    {
        return match ($this) {
            self::RETURN_SNAPSHOT, self::RETURN_SOURCE_MANIFEST, self::RETURN_BUSINESS_ERRORS => 'income_tax_return_snapshots',
            self::ASSESSMENT_SNAPSHOT, self::ASSESSMENT_FACTS, self::ASSESSMENT_RESULT => 'nonprofit_tax_assessments',
        };
    }

    public function column(): string
    {
        return match ($this) {
            self::RETURN_SNAPSHOT => 'snapshot_json',
            self::RETURN_SOURCE_MANIFEST => 'source_manifest',
            self::RETURN_BUSINESS_ERRORS => 'business_errors',
            self::ASSESSMENT_SNAPSHOT => 'snapshot_json',
            self::ASSESSMENT_FACTS => 'assessment_json',
            self::ASSESSMENT_RESULT => 'result_json',
        };
    }
}
