<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

enum FinancialCloneHashKind: string
{
    case PROFILE_REQUEST = 'profile_request';
    case PROFILE_RECORD = 'profile_record';
    case ASSESSMENT_INPUT = 'assessment_input';
    case ASSESSMENT_REQUEST = 'assessment_request';
    case ASSESSMENT_SNAPSHOT = 'assessment_snapshot';
    case ASSESSMENT_RECORD = 'assessment_record';
    case ASSESSMENT_RESULT = 'assessment_result';
    case ARCHIVE_SNAPSHOT = 'archive_snapshot';
    case ARCHIVE_SOURCE = 'archive_source';
    case ARCHIVE_XML = 'archive_xml';
    case ARCHIVE_NONPROFIT = 'archive_nonprofit';
    case SAVING_SOURCE = 'saving_source';
    case SAVING_COMMAND = 'saving_command';
    case SAVING_EVENT = 'saving_event';
    case MEMBERSHIP_CLAIM = 'membership_claim';
    case MEMBERSHIP_REVISION = 'membership_revision';
    case MEMBERSHIP_LEGAL_PROFILE = 'membership_legal_profile';
    case MEMBERSHIP_OPERATION = 'membership_operation';
    case MEMBERSHIP_JOURNAL_RECEIPT = 'membership_journal_receipt';
    case MEMBERSHIP_CLASSIFICATION = 'membership_classification';
}
