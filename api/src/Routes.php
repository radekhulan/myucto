<?php

declare(strict_types=1);

namespace MyInvoice;

use MyInvoice\Action\AresVies\AresLookupAction;
use MyInvoice\Action\AresVies\CrpDphLookupAction;
use MyInvoice\Action\AresVies\ViesLookupAction;
use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Action\Client\ArchiveClientAction;
use MyInvoice\Action\Client\ClientDuplicatesAction;
use MyInvoice\Action\Client\CreateClientAction;
use MyInvoice\Action\Client\DeleteClientAction;
use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Action\Client\ClientVatStatusAction;
use MyInvoice\Action\Client\ClientBankAccountAction;
use MyInvoice\Action\Client\ListClientsAction;
use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Action\Codebook\CodebookAction;
use MyInvoice\Action\Admin\ApprovalListAction;
use MyInvoice\Action\Admin\BankRuleTemplateAdminAction;
use MyInvoice\Action\Admin\DiagnosticsAction;
use MyInvoice\Action\Admin\EmailTemplateAction;
use MyInvoice\Action\Approval\PublicApprovalDecideAction;
use MyInvoice\Action\Approval\PublicApprovalGetAction;
use MyInvoice\Action\Approval\PublicApprovalLogoAction;
use MyInvoice\Action\Approval\RequestApprovalAction;
use MyInvoice\Action\Approval\RequestApprovalTestAction;
use MyInvoice\Action\Approval\UpdateApprovalStatusAction;
use MyInvoice\Action\Admin\ExportAction;
use MyInvoice\Action\Admin\ImportAction;
use MyInvoice\Action\Export\InstanceExportAction;
use MyInvoice\Action\Admin\Import\StartIdokladImportAction;
use MyInvoice\Action\Admin\Import\StartFakturoidImportAction;
use MyInvoice\Action\Admin\Import\ImportJobStatusAction;
use MyInvoice\Action\Admin\Import\CancelImportJobAction;
use MyInvoice\Action\Admin\Import\IdokladCredentialsAction;
use MyInvoice\Action\Admin\Import\FakturoidCredentialsAction;
use MyInvoice\Action\Admin\Import\AnthropicCredentialsAction;
use MyInvoice\Action\Admin\Import\AiProviderCredentialsAction;
use MyInvoice\Action\Admin\Import\AiExtractPdfAction;
use MyInvoice\Action\Admin\Import\AiExtractPdfIssuedAction;
use MyInvoice\Action\Crm\CrmDashboardAction;
use MyInvoice\Action\Portfolio\PortfolioAction;
use MyInvoice\Action\Report\DphPriznaniAction;
use MyInvoice\Action\Report\VatCoefficientAction;
use MyInvoice\Action\Report\KontrolniHlaseniAction;
use MyInvoice\Action\Report\DphBookAction;
use MyInvoice\Action\Report\MonthlyExportAction;
use MyInvoice\Action\Report\ClosingPackageAction;
use MyInvoice\Action\Oss\OssFilingArchiveAction;
use MyInvoice\Action\Report\OssReportAction;
use MyInvoice\Action\Report\SouhrnneHlaseniAction;
use MyInvoice\Action\Admin\InvoicesZipAction;
use MyInvoice\Action\Admin\CronJobsAction;
use MyInvoice\Action\Admin\RunCronJobAction;
use MyInvoice\Action\Admin\ListActivityLogAction;
use MyInvoice\Action\Admin\ListSentEmailsAction;
use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Admin\RoleAdminAction;
use MyInvoice\Action\Admin\SupplierSearchAction;
use MyInvoice\Action\Settings\EmailBrandingAction;
use MyInvoice\Action\Settings\BrandingProfilesAction;
use MyInvoice\Action\Settings\ClientBrandingSettingsAction;
use MyInvoice\Action\Settings\EmailProfilesAction;
use MyInvoice\Action\Settings\PdfSigningDiagnosticsAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Settings\AccountingActivationAction;
use MyInvoice\Action\Payroll\AnnualTaxCertificateAction;
use MyInvoice\Action\Payroll\PayrollAnnualDocumentBatchAction;
use MyInvoice\Action\Payroll\PayrollAnnualSettlementAction;
use MyInvoice\Action\Payroll\PayrollAnnualReportAction;
use MyInvoice\Action\Payroll\PayrollYearCloseAction;
use MyInvoice\Action\Payroll\PayrollActivationAction;
use MyInvoice\Action\Payroll\PayrollAccountOptionsAction;
use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Action\Payroll\PayrollBenefitBasketOverviewAction;
use MyInvoice\Action\Payroll\PayrollCapabilitiesAction;
use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollComponentJmhzMappingsAction;
use MyInvoice\Action\Payroll\PayrollCzIscoAction;
use MyInvoice\Action\Payroll\PayrollDeadlineOverviewAction;
use MyInvoice\Action\Payroll\PayrollDeductionAgreementAction;
use MyInvoice\Action\Payroll\PayrollDimensionAction;
use MyInvoice\Action\Payroll\PayrollDiscountIntentAction;
use MyInvoice\Action\Payroll\PayrollDocumentAction;
use MyInvoice\Action\Payroll\PayrollDocumentDeliveryAction;
use MyInvoice\Action\Payroll\PublicPayrollDocumentAccessAction;
use MyInvoice\Action\Payroll\PayrollEldpAction;
use MyInvoice\Action\Payroll\PayrollEmploymentExitDocumentAction;
use MyInvoice\Action\Payroll\PayrollEnforcementAction;
use MyInvoice\Action\Payroll\PayrollEnforcementFactsAction;
use MyInvoice\Action\Payroll\PayrollXmlzamCooperationAction;
use MyInvoice\Action\Payroll\PayrollEmployerPolicyAction;
use MyInvoice\Action\Payroll\PayrollEmployerSettingsAction;
use MyInvoice\Action\Payroll\PayrollOfficeRegistrationAction;
use MyInvoice\Action\Payroll\PayrollAccidentInsuranceRateAction;
use MyInvoice\Action\Payroll\PayrollOperationalHealthAction;
use MyInvoice\Action\Payroll\PayrollOperationalReconciliationAction;
use MyInvoice\Action\Payroll\PayrollJmhzEmployerAnnualEvidenceAction;
use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Action\Payroll\PayrollEmploymentAgendaSummaryAction;
use MyInvoice\Action\Payroll\PayrollEmploymentDimensionAction;
use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Action\Payroll\PayrollHealthInsuranceOverviewAction;
use MyInvoice\Action\Payroll\PayrollHealthInsuranceIsdsAction;
use MyInvoice\Action\Payroll\PayrollHealthNotificationAction;
use MyInvoice\Action\Payroll\PayrollInputImportsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Action\Payroll\PayrollInstitutionAccountsAction;
use MyInvoice\Action\Payroll\PayrollInsuranceBreakdownAction;
use MyInvoice\Action\Payroll\PayrollJmhzCorrectionAction;
use MyInvoice\Action\Payroll\PayrollJmhzIdentityAction;
use MyInvoice\Action\Payroll\PayrollJmhzProtocolImportAction;
use MyInvoice\Action\Payroll\PayrollJmhzPvpojPreviewAction;
use MyInvoice\Action\Payroll\PayrollJmhzOrdinaryEvidenceAction;
use MyInvoice\Action\Payroll\PayrollJmhzPreparationAction;
use MyInvoice\Action\Payroll\PayrollJmhzSigningProfileAction;
use MyInvoice\Action\Payroll\PayrollJmhzSubmissionFreezeAction;
use MyInvoice\Action\Payroll\PayrollJmhzIsdsAction;
use MyInvoice\Action\Payroll\PayrollJmhzTransportAction;
use MyInvoice\Action\Payroll\PayrollJmhzXmlDryRunAction;
use MyInvoice\Action\Payroll\PayrollMonthlyChecklistAction;
use MyInvoice\Action\Payroll\PayrollNetResultAction;
use MyInvoice\Action\Payroll\PayrollPaymentAction;
use MyInvoice\Action\Payroll\PayrollPeriodExportAction;
use MyInvoice\Action\Payroll\PayrollRiskySavingsAction;
use MyInvoice\Action\Payroll\PayrollPayoutRulesAction;
use MyInvoice\Action\Payroll\PayrollPeopleAction;
use MyInvoice\Action\Payroll\PayrollForeignPermitAction;
use MyInvoice\Action\Payroll\PayrollPersonProfileAction;
use MyInvoice\Action\Payroll\PayrollPersonQuickEditAction;
use MyInvoice\Action\Payroll\PayrollOpeningBalanceAction;
use MyInvoice\Action\Payroll\PayrollPersonSensitiveRevealAction;
use MyInvoice\Action\Payroll\PayrollPersonStatutoryEvidenceAction;
use MyInvoice\Action\Payroll\PayrollPostingReconciliationAction;
use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Action\Payroll\PayrollRegistrationAction;
use MyInvoice\Action\Payroll\PayrollRegistrationTransportAction;
use MyInvoice\Action\Payroll\PayrollRegzelAction;
use MyInvoice\Action\Payroll\PayrollRecurringComponentsAction;
use MyInvoice\Action\Payroll\PayrollRetentionAction;
use MyInvoice\Action\Payroll\PayrollRulesetAction;
use MyInvoice\Action\Payroll\PayrollRunValidationOverrideAction;
use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Action\Payroll\PayrollSicknessCaseAction;
use MyInvoice\Action\Payroll\PayrollSubmissionArtifactDownloadAction;
use MyInvoice\Action\Payroll\PayrollSubmissionDetailAction;
use MyInvoice\Action\Payroll\PayrollSubmissionInboxAction;
use MyInvoice\Action\Payroll\PayrollSubmissionOverviewAction;
use MyInvoice\Action\Payroll\PayrollStatutoryObligationAction;
use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Action\Payroll\PayrollTravelAction;
use MyInvoice\Action\Settings\SignatureDocumentSelectionAction;
use MyInvoice\Action\Settings\SigningProfilesAction;
use MyInvoice\Action\Settings\SupplierInvoiceCounterAction;
use MyInvoice\Action\Bank\BankEmailNoticeAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Dashboard\PurchaseSummaryAction;
use MyInvoice\Action\Invoice\BookInvoiceAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\ExportSelectedPdfAction;
use MyInvoice\Action\Invoice\InvoiceActivityAction;
use MyInvoice\Action\Invoice\GetInvoiceAction;
use MyInvoice\Action\Invoice\InvoiceIsdocAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Action\Invoice\PreviewVarsymbolAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Action\Invoice\SetInvoiceProjectAction;
use MyInvoice\Action\Invoice\UnmarkPaidAction;
use MyInvoice\Action\Invoice\ListPaymentsAction;
use MyInvoice\Action\Invoice\CreatePaymentAction;
use MyInvoice\Action\Invoice\DeletePaymentAction;
use MyInvoice\Action\Invoice\CreatePaymentTaxDocumentAction;
use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CloneInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\AdvanceCandidatesAction;
use MyInvoice\Action\PurchaseInvoice\SettlementCandidatesAction;
use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoicePdfAction;
use MyInvoice\Action\PurchaseInvoice\DismissAdvanceSuggestionAction;
use MyInvoice\Action\PurchaseInvoice\DismissExtractionWarningAction;
use MyInvoice\Action\PurchaseInvoice\LinkAdvancePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UnlinkAdvancePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DownloadPurchaseInvoicePdfAction;
use MyInvoice\Action\PurchaseInvoice\DownloadPurchaseInvoiceSourceAction;
use MyInvoice\Action\PurchaseInvoice\OurPdfPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ExportPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ExportPurchaseInvoicesAction;
use MyInvoice\Action\PurchaseInvoice\GetPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\ImportStructuredPurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\PaymentQrAction;
use MyInvoice\Action\PurchaseInvoice\PaymentOrderAction;
use MyInvoice\Action\PurchaseInvoice\ListPurchaseInvoicesAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceImportBatchesAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceDocumentKindAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceProjectAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceActivityAction;
use MyInvoice\Action\PurchaseInvoice\ScanInboxAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExchangeRateAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UploadPurchaseInvoicePdfAction;
use MyInvoice\Action\PriceList\PriceListItemAction;
use MyInvoice\Action\Recurring\RecurringTemplateAction;
use MyInvoice\Action\Invoice\IssueFinalFromProformaAction;
use MyInvoice\Action\Invoice\AdvanceCandidatesAction as InvoiceAdvanceCandidatesAction;
use MyInvoice\Action\Invoice\FinalCandidatesAction;
use MyInvoice\Action\Invoice\LinkAdvanceAction as LinkInvoiceAdvanceAction;
use MyInvoice\Action\Invoice\UnlinkAdvanceAction as UnlinkInvoiceAdvanceAction;
use MyInvoice\Action\Invoice\PdfAction;
use MyInvoice\Action\Invoice\PublicInvoiceAttachmentAction;
use MyInvoice\Action\Invoice\PublicInvoiceGetAction;
use MyInvoice\Action\Invoice\PublicInvoicePdfAction;
use MyInvoice\Action\Invoice\PublicLinkAction;
use MyInvoice\Action\Invoice\ListPdfsAction;
use MyInvoice\Action\Invoice\DownloadArchivedPdfAction;
use MyInvoice\Action\Invoice\DownloadImportedPdfAction;
use MyInvoice\Action\Invoice\Attachment\ListAttachmentsAction;
use MyInvoice\Action\Invoice\Attachment\UploadAttachmentAction;
use MyInvoice\Action\Invoice\Attachment\DeleteAttachmentAction;
use MyInvoice\Action\Invoice\Attachment\DownloadAttachmentAction;
use MyInvoice\Action\Invoice\GetRecipientsAction;
use MyInvoice\Action\Invoice\SendEmailAction;
use MyInvoice\Action\Invoice\SendReminderAction;
use MyInvoice\Action\Invoice\BulkSendRemindersAction;
use MyInvoice\Action\Invoice\SendTestEmailAction;
use MyInvoice\Action\Invoice\SendTestReminderAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\WorkReport\GetWorkReportAction;
use MyInvoice\Action\WorkReport\SaveWorkReportAction;
use MyInvoice\Action\WorkReport\SaveWorkReportMaterialsAction;
use MyInvoice\Action\WorkReport\DeleteWorkReportAction;
use MyInvoice\Action\WorkReport\WorkReportLinkAction;
use MyInvoice\Action\WorkReport\PublicWorkReportGetAction;
use MyInvoice\Action\WorkReport\PublicWorkReportRequestCodeAction;
use MyInvoice\Action\WorkReport\PublicWorkReportVerifyAction;
use MyInvoice\Action\Project\ArchiveProjectAction;
use MyInvoice\Action\Project\CreateProjectAction;
use MyInvoice\Action\Project\DeleteProjectAction;
use MyInvoice\Action\Project\GetProjectAction;
use MyInvoice\Action\Project\ListProjectsAction;
use MyInvoice\Action\Project\ProjectProfitAction;
use MyInvoice\Action\Project\ProjectStatsAction;
use MyInvoice\Action\Project\UpdateProjectAction;
use MyInvoice\Action\Auth\ApiMeAction;
use MyInvoice\Action\Auth\ForgotPasswordAction;
use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Action\Auth\LogoutAction;
use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Action\Auth\MfaStepUpAction;
use MyInvoice\Action\Auth\PasskeyAction;
use MyInvoice\Action\Auth\ResetPasswordAction;
use MyInvoice\Action\Auth\SessionAction;
use MyInvoice\Action\Auth\SetupAction;
use MyInvoice\Action\Auth\SetupAresLookupAction;
use MyInvoice\Action\Auth\SetupCrpDphLookupAction;
use MyInvoice\Action\Auth\SetupSampleAction;
use MyInvoice\Action\Auth\SetupPreflightAction;
use MyInvoice\Action\Auth\SetupStatusAction;
use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Action\Auth\Tokens\ListTokensAction;
use MyInvoice\Action\Auth\Tokens\RevokeTokenAction;
use MyInvoice\Action\Auth\TotpAction;
use MyInvoice\Action\Document\FoldersAction;
use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Action\Document\UploadDocumentAction;
use MyInvoice\Action\Document\DocumentFileAction;
use MyInvoice\Action\Document\DocumentFilesAction;
use MyInvoice\Action\Document\LinkSearchAction;
use MyInvoice\Action\Document\DocumentJobsAction;
use MyInvoice\Action\Accounting\AccountingPeriodAction;
use MyInvoice\Action\Accounting\Attachment\DeleteJournalAttachmentAction;
use MyInvoice\Action\Accounting\Attachment\DownloadJournalAttachmentAction;
use MyInvoice\Action\Accounting\Attachment\ListJournalAttachmentsAction;
use MyInvoice\Action\Accounting\Attachment\PatchJournalAttachmentDescriptionAction;
use MyInvoice\Action\Accounting\Attachment\UploadJournalAttachmentAction;
use MyInvoice\Action\Accounting\JournalForDocumentAction;
use MyInvoice\Action\Accounting\JournalRelatedAction;
use MyInvoice\Action\Accounting\JournalSourceAction;
use MyInvoice\Action\Accounting\Note\CreateJournalNoteAction;
use MyInvoice\Action\Accounting\Note\DeleteJournalNoteAction;
use MyInvoice\Action\Accounting\Note\ListJournalNotesAction;
use MyInvoice\Action\Accounting\Note\PatchJournalNoteAction;
use MyInvoice\Action\Accounting\Assets\AssetAction;
use MyInvoice\Action\Accounting\Assets\AssetLifecycleAction;
use MyInvoice\Action\Accounting\Assets\DepreciationAction;
use MyInvoice\Action\Accounting\ChartOfAccountsAction;
use MyInvoice\Action\Accounting\CostCenterAction;
use MyInvoice\Action\Accounting\Closing\ClosingAction;
use MyInvoice\Action\Accounting\Closing\DocumentSeriesAction;
use MyInvoice\Action\Accounting\Closing\JournalTransferAction;
use MyInvoice\Action\Accounting\Closing\TaxBaseReportAction;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Accounting\JournalDocumentLinkAction;
use MyInvoice\Action\Accounting\JournalTemplateAction;
use MyInvoice\Action\Accounting\PayrollAction;
use MyInvoice\Action\Accounting\PayrollEmployeeAction;
use MyInvoice\Action\Accounting\PeriodLockAction;
use MyInvoice\Action\Accounting\PostingRuleAction;
use MyInvoice\Action\Accounting\Reports\AccountStatementAction;
use MyInvoice\Action\Accounting\Reports\BalanceInventoryAction;
use MyInvoice\Action\Accounting\Reports\EntityCategoryAction;
use MyInvoice\Action\Accounting\Reports\FinancialStatementAction;
use MyInvoice\Action\Accounting\Reports\GeneralLedgerAction;
use MyInvoice\Action\Accounting\Reports\MonthlyReportAction;
use MyInvoice\Action\Accounting\Reports\PayrollSheetAction;
use MyInvoice\Action\Accounting\Reports\ReportingSettingsAction;
use MyInvoice\Action\Accounting\Reports\SaldoAction;
use MyInvoice\Action\Accounting\Reports\SmallAssetReportAction;
use MyInvoice\Action\Accounting\Reports\TrialBalanceAction;
use MyInvoice\Action\License\LicenseBillingAction;
use MyInvoice\Action\License\LicenseStatusAction;
use MyInvoice\Action\License\ActivateLicenseAction;
use MyInvoice\Action\License\DeactivateLicenseAction;
use MyInvoice\Action\License\RefreshLicenseAction;
use MyInvoice\Action\License\CancelRenewalLicenseAction;
use MyInvoice\Action\License\ResumeRenewalLicenseAction;
use MyInvoice\Action\License\StorageQuoteAction;
use MyInvoice\Action\License\StorageUpgradeAction;
use MyInvoice\Action\License\UpgradeQuoteLicenseAction;
use MyInvoice\Action\License\UpgradeLicenseAction;
use MyInvoice\Action\License\SupportLinkAction;
use MyInvoice\Action\License\TierQuoteAction;
use MyInvoice\Action\License\TierChangeAction;
use MyInvoice\Action\License\ChangeStatusAction;
use MyInvoice\Action\License\PurchaseStartAction;
use MyInvoice\Action\License\PurchaseCompleteAction;
use MyInvoice\Action\System\HealthAction;
use MyInvoice\Action\System\OpenApiAction;
use MyInvoice\Action\System\VersionAction;
use MyInvoice\Action\Admin\UpdateAction;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
        $app->get('/api/health',  HealthAction::class);
        $app->get('/api/version', VersionAction::class);

        // Public REST API v1 — dokumentace
        $app->get('/api/openapi.yaml', [OpenApiAction::class, 'spec']);
        $app->get('/api/docs',         [OpenApiAction::class, 'docs']);       // Swagger UI (Try it out)
        $app->get('/api/reference',    [OpenApiAction::class, 'reference']);  // Redoc (pretty static)
        $app->get('/api/scalar',       [OpenApiAction::class, 'scalar']);     // Scalar (moderní reference)

        // Admin — kontrola a upgrade nové verze (M9, issue „Kontrola a upgrade")
        $app->get  ('/api/admin/update/status',  [UpdateAction::class, 'status']);
        $app->get  ('/api/admin/update/preflight', [UpdateAction::class, 'preflight']);
        $app->post ('/api/admin/update/refresh', [UpdateAction::class, 'refresh']);
        $app->post ('/api/admin/update/trigger', [UpdateAction::class, 'trigger']);
        $app->post ('/api/admin/update/cancel',  [UpdateAction::class, 'cancel']);

        // Admin — Systém → Diagnostika: audit prostředí a podklad k incidentu podpory.
        // Balíček se NIKAM neodesílá; zákazník si ho stáhne a k incidentu ho přiloží sám.
        $app->get  ('/api/admin/diagnostics',                 [DiagnosticsAction::class, 'report']);
        $app->get  ('/api/admin/diagnostics/logs',            [DiagnosticsAction::class, 'logPreview']);
        $app->get  ('/api/admin/diagnostics/bundle/preview',  [DiagnosticsAction::class, 'preview']);
        $app->get  ('/api/admin/diagnostics/bundle/download', [DiagnosticsAction::class, 'download']);
        $app->post ('/api/admin/diagnostics/bundle',          [DiagnosticsAction::class, 'create']);

        $app->get   ('/api/admin/roles',                    [RoleAdminAction::class, 'list']);
        $app->get   ('/api/admin/roles/permissions',        [RoleAdminAction::class, 'permissions']);
        $app->get   ('/api/admin/roles/{id:[0-9]+}',        [RoleAdminAction::class, 'detail']);
        $app->post  ('/api/admin/roles',                    [RoleAdminAction::class, 'create']);
        $app->put   ('/api/admin/roles/{id:[0-9]+}',        [RoleAdminAction::class, 'update']);
        $app->post  ('/api/admin/roles/{id:[0-9]+}/duplicate', [RoleAdminAction::class, 'duplicate']);
        $app->delete('/api/admin/roles/{id:[0-9]+}',        [RoleAdminAction::class, 'delete']);
        $app->get   ('/api/admin/suppliers/search',          SupplierSearchAction::class);

        // Admin — správa ukázkových (sample) dat (issue #162); admin-only přes PermissionMiddleware
        $app->get   ('/api/maintenance/sample-data', [\MyInvoice\Action\Maintenance\SampleDataAction::class, 'status']);
        $app->delete('/api/maintenance/sample-data', [\MyInvoice\Action\Maintenance\SampleDataAction::class, 'delete']);

        // Slug helper (název → kód) — sdílený Slugifier; předvyplnění kódu v UI číselníků (eshop, admin/codebooks)
        $app->get   ('/api/slug', \MyInvoice\Action\SlugAction::class);

        $app->group('/api/auth', function ($g) {
            $g->get ('/setup-status',    SetupStatusAction::class);
            $g->get ('/setup-preflight', SetupPreflightAction::class);        // audit prostředí před prvním setupem (jen dokud není admin)
            $g->post('/setup',           SetupAction::class);
            $g->post('/setup-ares-lookup', SetupAresLookupAction::class);  // public ARES proxy během setup wizardu
            $g->post('/setup-crpdph-lookup', SetupCrpDphLookupAction::class);  // public proxy do registru plátců DPH (účty z DIČ)
            $g->post('/setup-sample',    SetupSampleAction::class);         // public sample data generator (jen pokud nejsou data)
            $g->post('/login',           LoginAction::class);
            $g->get ('/domain-context',  \MyInvoice\Action\Auth\DomainContextAction::class);
            $g->post('/domain-login/start',     [\MyInvoice\Action\Auth\DomainLoginAction::class, 'start']);
            $g->post('/domain-login/authorize', [\MyInvoice\Action\Auth\DomainLoginAction::class, 'authorize']);
            $g->post('/domain-login/exchange',  [\MyInvoice\Action\Auth\DomainLoginAction::class, 'exchange']);
            $g->post('/webauthn/login/options', [LoginAction::class, 'passkeyOptions']);
            $g->post('/logout',          LogoutAction::class);
            $g->get ('/me',              MeAction::class);
            $g->get ('/api-me',          ApiMeAction::class);  // connection-test pro bearer i session
            $g->post('/change-password', ChangePasswordAction::class);
            $g->post('/forgot',          ForgotPasswordAction::class);
            $g->post('/reset',           ResetPasswordAction::class);
            // TOTP (2FA)
            $g->get ('/totp/status',     [TotpAction::class, 'status']);
            $g->post('/totp/setup',      [TotpAction::class, 'setup']);
            $g->post('/totp/enable',     [TotpAction::class, 'enable']);
            // WebAuthn/passkeys — interní session-only self-service API
            $g->get   ('/webauthn/credentials',              [PasskeyAction::class, 'credentials']);
            $g->post  ('/webauthn/register/options',          [PasskeyAction::class, 'registerOptions']);
            $g->post  ('/webauthn/register/verify',           [PasskeyAction::class, 'registerVerify']);
            $g->post  ('/webauthn/login/verify',              [PasskeyAction::class, 'loginVerify']);
            $g->post  ('/webauthn/step-up/options',           [PasskeyAction::class, 'stepUpOptions']);
            $g->post  ('/webauthn/step-up/verify',            [PasskeyAction::class, 'stepUpVerify']);
            $g->patch ('/webauthn/credentials/{id:[0-9]+}',   [PasskeyAction::class, 'rename']);
            $g->delete('/webauthn/credentials/{id:[0-9]+}',   [PasskeyAction::class, 'revoke']);
            $g->post  ('/mfa/step-up/totp',                   [MfaStepUpAction::class, 'totp']);
            $g->post  ('/mfa/step-up/recovery',               [MfaStepUpAction::class, 'recovery']);
            $g->get   ('/mfa/recovery-codes',                 [\MyInvoice\Action\Auth\MfaRecoveryCodeAction::class, 'status']);
            $g->post  ('/mfa/recovery-codes',                 [\MyInvoice\Action\Auth\MfaRecoveryCodeAction::class, 'generate']);
            // Dobrovolná nabídka MFA — „pokračovat bez ověření". Jen když se MFA nevynucuje;
            // při require_mfa = true endpoint odpoví 409 (viz MfaOfferService::dismiss).
            $g->post  ('/mfa/offer/dismiss',                  [\MyInvoice\Action\Auth\MfaOfferAction::class, 'dismiss']);
            $g->get   ('/session/status',                     [SessionAction::class, 'status']);
            $g->post  ('/session/activity',                   [SessionAction::class, 'activity']);
            $g->post  ('/session/lock',                       [SessionAction::class, 'lock']);
            $g->get   ('/session/lock-preference',            [SessionAction::class, 'lockPreference']);
            $g->put   ('/session/lock-preference',            [SessionAction::class, 'updateLockPreference']);
            $g->post  ('/session/unlock/options',             [SessionAction::class, 'unlockOptions']);
            $g->post  ('/session/unlock/verify',              [SessionAction::class, 'unlockVerify']);
            // API tokeny (Personal Access Tokens) — správa jen ze session auth
            $g->get   ('/tokens',                  ListTokensAction::class);
            $g->post  ('/tokens',                  CreateTokenAction::class);
            $g->delete('/tokens/{id:[0-9]+}',      RevokeTokenAction::class);
            // Volitelný IP allowlist tokenu (IPv4/IPv6, adresa i CIDR rozsah)
            $g->get   ('/tokens/{id:[0-9]+}/ips',                  [\MyInvoice\Action\Auth\Tokens\TokenIpRuleAction::class, 'list']);
            $g->post  ('/tokens/{id:[0-9]+}/ips',                  [\MyInvoice\Action\Auth\Tokens\TokenIpRuleAction::class, 'create']);
            $g->delete('/tokens/{id:[0-9]+}/ips/{ipId:[0-9]+}',    [\MyInvoice\Action\Auth\Tokens\TokenIpRuleAction::class, 'delete']);
            // Log volání veřejného API vlastními tokeny (MCP server i přímá integrace)
            $g->get   ('/api-log',                 \MyInvoice\Action\Auth\Tokens\ApiRequestLogAction::class);
        });

        // Licencování a aktivace (E4) — admin only (RoutePermissionMap → superadmin).
        $app->get ('/api/license/status',        LicenseStatusAction::class);
        // ⚠️ Jediná výjimka z té brány: dunning stav (co dlužím, dokdy, kde zaplatit)
        // vidí i běžný admin — jinak se o neúspěšné platbě dozví až tím, že
        // instalace přestane fungovat. Rozsah viz BillingSnapshot::dunning().
        $app->get ('/api/license/billing',       LicenseBillingAction::class);
        $app->post('/api/license/activate',      ActivateLicenseAction::class);
        // ⚠️ Mimo admin bránu schválně: tohle volá licenční server, ne člověk.
        // Autentizace je Ed25519 podpis obálky, ověřený zabudovaným veřejným
        // klíčem — nepodepsaný požadavek neudělá nic.
        $app->post('/api/managed/license',       \MyInvoice\Action\License\ManagedLicenseAction::class);
        $app->post('/api/license/deactivate',    DeactivateLicenseAction::class);
        // Okamžité stažení rozsahu z licenčního serveru — zaplacené navýšení
        // se jinak projeví až denní obnovou tokenu a zákazník kouká na staré
        // počty. Nic nekupuje, jen si řekne o čerstvý token.
        $app->post('/api/license/refresh',       RefreshLicenseAction::class);
        // Vypnutí automatického prodlužování — licence doběhne do valid_until.
        $app->post('/api/license/cancel-renewal', CancelRenewalLicenseAction::class);
        $app->post('/api/license/resume-renewal', ResumeRenewalLicenseAction::class);
        // In-place navýšení počtu uživatelů (poměrný doplatek z uložené karty).
        $app->post('/api/license/upgrade/quote', UpgradeQuoteLicenseAction::class);
        $app->post('/api/license/upgrade',       UpgradeLicenseAction::class);
        // Rozšíření úložiště hostované instance (poměrný doplatek z karty).
        $app->post('/api/license/quota/quote', StorageQuoteAction::class);
        $app->post('/api/license/quota',       StorageUpgradeAction::class);
        $app->post('/api/license/tier/quote', TierQuoteAction::class);
        $app->post('/api/license/tier',       TierChangeAction::class);
        $app->post('/api/license/change-status', ChangeStatusAction::class);
        // Nový nákup: PKCE session a serverový claim bez licenčního klíče v URL.
        $app->post('/api/license/purchase/start', PurchaseStartAction::class);
        $app->post('/api/license/purchase/complete', PurchaseCompleteAction::class);
        // Přihlášený přechod na portál podpory (myucto.cz/support) — jednorázový token.
        $app->post('/api/license/support-link',  SupportLinkAction::class);

        // ARES + VIES lookups (vyžadují auth)
        $app->post('/api/clients/lookup-ares', AresLookupAction::class);
        $app->post('/api/clients/lookup-vies', ViesLookupAction::class);
        $app->post('/api/clients/lookup-bank', CrpDphLookupAction::class);  // účty z DIČ přes registr plátců DPH

        // Globální vyhledávač pro sidebar (klienti/dodavatelé + vydané/přijaté faktury)
        $app->get('/api/search', \MyInvoice\Action\Search\GlobalSearchAction::class);
        $app->get('/api/branding-profiles', [BrandingProfilesAction::class, 'publicList']);

        // Codebooks
        $app->get('/api/codebooks/countries',  [CodebookAction::class, 'countries']);
        $app->get('/api/codebooks/currencies', [CodebookAction::class, 'currencies']);
        $app->get('/api/codebooks/vat-rates',  [CodebookAction::class, 'vatRates']);
        $app->get('/api/codebooks/units',      [CodebookAction::class, 'units']);
        $app->get('/api/codebooks/years',      [CodebookAction::class, 'years']);
        $app->get('/api/codebooks/cnb-rate',   \MyInvoice\Action\Codebook\CnbRateAction::class);

        // Expense categories (pro rozpad nákladů v CRM dashboardu)
        $app->get   ('/api/expense-categories',                  [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'list']);
        $app->post  ('/api/expense-categories',                  [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'create']);
        $app->put   ('/api/expense-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'update']);
        $app->delete('/api/expense-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\ExpenseCategoriesAction::class, 'delete']);

        // Revenue categories (pro rozpad tržeb v CRM dashboardu + Stats)
        $app->get   ('/api/revenue-categories',                  [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'list']);
        $app->post  ('/api/revenue-categories',                  [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'create']);
        $app->put   ('/api/revenue-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'update']);
        $app->delete('/api/revenue-categories/{id:[0-9]+}',      [\MyInvoice\Action\Codebook\RevenueCategoriesAction::class, 'delete']);

        // Ceníkové položky (interní session API; správa admin, čtení accountant)
        $app->get   ('/api/price-list-items', [PriceListItemAction::class, 'list']);
        $app->post  ('/api/price-list-items', [PriceListItemAction::class, 'create']);
        $app->get   ('/api/price-list-items/{id:[0-9]+}', [PriceListItemAction::class, 'get']);
        $app->put   ('/api/price-list-items/{id:[0-9]+}', [PriceListItemAction::class, 'update']);
        $app->delete('/api/price-list-items/{id:[0-9]+}', [PriceListItemAction::class, 'delete']);
        $app->get   ('/api/price-list-items/{id:[0-9]+}/resolve', [PriceListItemAction::class, 'resolve']);
        $app->get   ('/api/price-list-items/{id:[0-9]+}/prices', [PriceListItemAction::class, 'prices']);
        $app->put   ('/api/price-list-items/{id:[0-9]+}/prices/{currencyCode:[A-Za-z][A-Za-z][A-Za-z]}', [PriceListItemAction::class, 'upsertPrice']);
        $app->delete('/api/price-list-items/{id:[0-9]+}/prices/{currencyCode:[A-Za-z][A-Za-z][A-Za-z]}', [PriceListItemAction::class, 'deletePrice']);
        $app->get   ('/api/price-list-items/{id:[0-9]+}/customer-overrides', [PriceListItemAction::class, 'customerOverrides']);
        $app->put   ('/api/price-list-items/{id:[0-9]+}/customer-overrides/{clientId:[0-9]+}/{currencyCode:[A-Za-z][A-Za-z][A-Za-z]}', [PriceListItemAction::class, 'upsertCustomerOverride']);
        $app->delete('/api/price-list-items/{id:[0-9]+}/customer-overrides/{clientId:[0-9]+}/{currencyCode:[A-Za-z][A-Za-z][A-Za-z]}', [PriceListItemAction::class, 'deleteCustomerOverride']);

        // Roční daňové konstanty (globální číselník, override defaultů z TaxConstants; migrace 0079)
        $app->get   ('/api/codebooks/tax-constants',                [\MyInvoice\Action\Codebook\TaxConstantsAction::class, 'list']);
        $app->put   ('/api/codebooks/tax-constants/{year:[0-9]+}',  [\MyInvoice\Action\Codebook\TaxConstantsAction::class, 'update']);
        $app->delete('/api/codebooks/tax-constants/{year:[0-9]+}',  [\MyInvoice\Action\Codebook\TaxConstantsAction::class, 'reset']);

        // Sazby DPH členských států pro OSS (globální číselník; zápis jen superadmin — OSS-9)
        $app->get   ('/api/codebooks/oss-member-state-rates',                [\MyInvoice\Action\Codebook\OssMemberStateRatesAction::class, 'list']);
        $app->post  ('/api/codebooks/oss-member-state-rates',                [\MyInvoice\Action\Codebook\OssMemberStateRatesAction::class, 'create']);
        $app->put   ('/api/codebooks/oss-member-state-rates/{id:[0-9]+}',    [\MyInvoice\Action\Codebook\OssMemberStateRatesAction::class, 'update']);
        $app->delete('/api/codebooks/oss-member-state-rates/{id:[0-9]+}',    [\MyInvoice\Action\Codebook\OssMemberStateRatesAction::class, 'delete']);

        // VAT klasifikační kódy (pro DPHDP3 + KH)
        $app->get   ('/api/vat-classifications',                 [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'list']);
        $app->post  ('/api/vat-classifications',                 [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'create']);
        $app->put   ('/api/vat-classifications/{id:[0-9]+}',     [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'update']);
        $app->delete('/api/vat-classifications/{id:[0-9]+}',     [\MyInvoice\Action\Codebook\VatClassificationsAction::class, 'delete']);

        // Clients
        $app->get   ('/api/clients',                 ListClientsAction::class);
        $app->get   ('/api/clients/duplicates',      ClientDuplicatesAction::class);  // FR 2 — report duplicitních karet (IČO/DIČ)
        $app->post  ('/api/clients',                 CreateClientAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}',     GetClientAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}/vat-status', ClientVatStatusAction::class);  // online ARES/VIES plátcovství
        $app->put   ('/api/clients/{id:[0-9]+}',     UpdateClientAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/archive',   ArchiveClientAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/unarchive', ArchiveClientAction::class);
        $app->delete('/api/clients/{id:[0-9]+}',           DeleteClientAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}/bank-accounts', [ClientBankAccountAction::class, 'list']);
        $app->post  ('/api/clients/{id:[0-9]+}/bank-accounts', [ClientBankAccountAction::class, 'create']);
        $app->post  ('/api/clients/{id:[0-9]+}/bank-accounts/sync-registry', [ClientBankAccountAction::class, 'syncRegistry']);
        $app->delete('/api/clients/{id:[0-9]+}/bank-accounts/{accountId:[0-9]+}', [ClientBankAccountAction::class, 'delete']);
        // Sledovací odkaz na výkaz práce (klient — všechny otevřené výkazy klienta)
        $app->get   ('/api/clients/{id:[0-9]+}/work-report-link',            [WorkReportLinkAction::class, 'getClient']);
        $app->get   ('/api/clients/{id:[0-9]+}/work-report-link/recipients', [WorkReportLinkAction::class, 'recipientsClient']);
        $app->post  ('/api/clients/{id:[0-9]+}/work-report-link/send',       [WorkReportLinkAction::class, 'sendClient']);
        $app->delete('/api/clients/{id:[0-9]+}/work-report-link',            [WorkReportLinkAction::class, 'revokeClient']);

        // Projects
        $app->get   ('/api/clients/{client_id:[0-9]+}/projects', ListProjectsAction::class);
        $app->get   ('/api/projects/stats',          ProjectStatsAction::class);
        // Výsledovka po zakázkách (issue #29). Statická cesta MUSÍ být před /{id}.
        $app->get   ('/api/projects/profitability',  [ProjectProfitAction::class, 'overview']);
        $app->get   ('/api/projects',                ListProjectsAction::class);
        $app->post  ('/api/projects',                CreateProjectAction::class);
        $app->get   ('/api/projects/{id:[0-9]+}',    GetProjectAction::class);
        $app->put   ('/api/projects/{id:[0-9]+}',    UpdateProjectAction::class);
        $app->get   ('/api/projects/{id:[0-9]+}/profit',   [ProjectProfitAction::class, 'detail']);
        $app->post  ('/api/projects/{id:[0-9]+}/archive', ArchiveProjectAction::class);
        $app->delete('/api/projects/{id:[0-9]+}',         DeleteProjectAction::class);
        // Sledovací odkaz na výkaz práce (zakázka — jen otevřené výkazy dané zakázky)
        $app->get   ('/api/projects/{id:[0-9]+}/work-report-link',            [WorkReportLinkAction::class, 'getProject']);
        $app->get   ('/api/projects/{id:[0-9]+}/work-report-link/recipients', [WorkReportLinkAction::class, 'recipientsProject']);
        $app->post  ('/api/projects/{id:[0-9]+}/work-report-link/send',       [WorkReportLinkAction::class, 'sendProject']);
        $app->delete('/api/projects/{id:[0-9]+}/work-report-link',            [WorkReportLinkAction::class, 'revokeProject']);

        // Invoices (M3 — draft + editor + sumace; vystavení/odeslání/PDF přijde v M4)
        $app->get    ('/api/invoices',              ListInvoicesAction::class);
        $app->get    ('/api/invoices/export.pdf',   ExportSelectedPdfAction::class);
        // Veřejný alias admin exportu (bearer allowlist pokrývá /api/invoices/*):
        // ?format=pdf-zip|isdoc|pohoda|stereo|money_s3|csv & month=YYYY-MM nebo period=quarterly&year&quarter
        $app->get    ('/api/invoices/export',       ExportAction::class);
        $app->get    ('/api/invoices/preview-varsymbol', PreviewVarsymbolAction::class);
        $app->post   ('/api/invoices',              CreateInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}',  GetInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/activity', InvoiceActivityAction::class);
        // Epic SKLAD (§7.3 Detail FV) — výdejky/vratky vzniklé k této faktuře.
        $app->get    ('/api/invoices/{id:[0-9]+}/stock-documents', [\MyInvoice\Action\Stock\StockDocumentAction::class, 'forInvoice']);
        $app->put    ('/api/invoices/{id:[0-9]+}',  UpdateInvoiceAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}',  DeleteInvoiceAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue',     IssueInvoiceAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/mark-paid', MarkPaidAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/unmark-paid', UnmarkPaidAction::class);
        // Zakázka (issue #29) — smí i u zaúčtovaného dokladu, je to analytická dimenze.
        $app->post   ('/api/invoices/{id:[0-9]+}/project',   SetInvoiceProjectAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/rebuild-snapshots', \MyInvoice\Action\Invoice\RebuildInvoiceSnapshotsAction::class);
        // Evidence plateb / částečné úhrady (#89) + daňový doklad k přijaté platbě (zálohy)
        $app->get    ('/api/invoices/{id:[0-9]+}/payments', ListPaymentsAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/payments', CreatePaymentAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/payments/{paymentId:[0-9]+}', DeletePaymentAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/payments/{paymentId:[0-9]+}/tax-document', CreatePaymentTaxDocumentAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/cancel',    CancelInvoiceAction::class);
        // Ruční book/unbook (Epic F6, §4.6) — zámek pro roli client u tax_evidence firem.
        // Kryje route permission rules; v client permission rules není → klient 403.
        $app->post   ('/api/invoices/{id:[0-9]+}/book',      [BookInvoiceAction::class, 'book']);
        $app->delete ('/api/invoices/{id:[0-9]+}/book',      [BookInvoiceAction::class, 'unbook']);
        $app->get    ('/api/invoices/{id:[0-9]+}/isdoc',     InvoiceIsdocAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdf',       PdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdfs',      ListPdfsAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdfs/{archiveId:[0-9]+}', DownloadArchivedPdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/imported-pdf', DownloadImportedPdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/attachments', ListAttachmentsAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/attachments', UploadAttachmentAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/attachments/{attId:[0-9]+}', DownloadAttachmentAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/attachments/{attId:[0-9]+}', DeleteAttachmentAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/recipients', GetRecipientsAction::class);  // #86 vyřešení příjemců pro modal
        $app->post   ('/api/invoices/{id:[0-9]+}/send',      SendEmailAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/send-test', SendTestEmailAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder',  SendReminderAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder-test', SendTestReminderAction::class);
        // Penalizace — úrok z prodlení (NV 351/2013): náhled výpočtu + založení penalizační faktury.
        $app->get    ('/api/invoices/{id:[0-9]+}/penalty/preview', [\MyInvoice\Action\Invoice\PenaltyInvoiceAction::class, 'preview']);
        $app->post   ('/api/invoices/{id:[0-9]+}/penalty',    [\MyInvoice\Action\Invoice\PenaltyInvoiceAction::class, 'create']);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue-final', IssueFinalFromProformaAction::class);
        // Zpětné propojení daňového dokladu se zálohovou fakturou (proforma)
        $app->get    ('/api/invoices/{id:[0-9]+}/advance-candidates', InvoiceAdvanceCandidatesAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/final-candidates',   FinalCandidatesAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/link-advance',       LinkInvoiceAdvanceAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/link-advance',       UnlinkInvoiceAdvanceAction::class);
        $app->post   ('/api/invoices/bulk-reissue',          BulkReissueAction::class);
        $app->post   ('/api/invoices/bulk-reminder',         BulkSendRemindersAction::class);
        // Hromadné nastavení OSS nad výběrem dokladů (OSS-7). Náhled je povinný, proto
        // jsou to dvě routy — provedení bez předchozího potvrzení vrátí 428.
        $app->post   ('/api/invoices/bulk-oss/preview',      [\MyInvoice\Action\Invoice\BulkOssUpdateAction::class, 'preview']);
        $app->post   ('/api/invoices/bulk-oss',              [\MyInvoice\Action\Invoice\BulkOssUpdateAction::class, 'apply']);
        $app->post   ('/api/invoices/{id:[0-9]+}/clone',     CloneInvoiceAction::class);
        $app->get    ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'get']);
        $app->put    ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'put']);
        $app->delete ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'delete']);

        // Přijaté faktury (purchase invoices) — fáze 1 integrace forku.
        // Všechny chráněné AuthMiddleware + SupplierScopeMiddleware (skrz globální group).
        // scan-inbox je admin/accountant only (check v Action).
        $app->get    ('/api/purchase-invoice-submissions', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction::class, 'list']);
        $app->post   ('/api/purchase-invoice-submissions', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction::class, 'upload']);
        $app->get    ('/api/purchase-invoice-submissions/{id:[0-9]+}', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction::class, 'get']);
        $app->get    ('/api/purchase-invoice-submissions/{id:[0-9]+}/preview', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionFileAction::class, 'staffPreview']);
        $app->get    ('/api/purchase-invoice-submissions/{id:[0-9]+}/download', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionFileAction::class, 'staffDownload']);
        $app->post   ('/api/purchase-invoice-submissions/{id:[0-9]+}/extract', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction::class, 'extract']);
        $app->post   ('/api/purchase-invoice-submissions/{id:[0-9]+}/needs-information', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction::class, 'needsInformation']);
        $app->post   ('/api/purchase-invoice-submissions/{id:[0-9]+}/reject', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction::class, 'reject']);
        $app->delete ('/api/purchase-invoice-submissions/{id:[0-9]+}', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction::class, 'delete']);

        $app->post   ('/api/purchase-invoices/scan-inbox',                ScanInboxAction::class);
        $app->post   ('/api/purchase-invoices/import-structured',         ImportStructuredPurchaseInvoiceAction::class);
        $app->get    ('/api/purchase-invoices/export',                     ExportPurchaseInvoicesAction::class);
        $app->get    ('/api/purchase-invoices/import-batches',             PurchaseInvoiceImportBatchesAction::class);
        $app->get    ('/api/purchase-invoices',                           ListPurchaseInvoicesAction::class);
        $app->post   ('/api/purchase-invoices',                           CreatePurchaseInvoiceAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}',                GetPurchaseInvoiceAction::class);
        $app->put    ('/api/purchase-invoices/{id:[0-9]+}',                UpdatePurchaseInvoiceAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}',                DeletePurchaseInvoiceAction::class);
        $app->put    ('/api/purchase-invoices/{id:[0-9]+}/items',          SetPurchaseInvoiceItemsAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/exchange-rate', SetPurchaseInvoiceExchangeRateAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/transition',     TransitionPurchaseInvoiceStatusAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/document-kind',   SetPurchaseInvoiceDocumentKindAction::class);
        // Zakázka (issue #29) — smí i u zaúčtovaného dokladu, je to analytická dimenze.
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/project',         SetPurchaseInvoiceProjectAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/dismiss-extraction-warning', DismissExtractionWarningAction::class);
        // Propojení se zálohovou fakturou (advance) — proti dvojímu započtení nákladu
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/advance-candidates', AdvanceCandidatesAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/settlement-candidates', SettlementCandidatesAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/link-advance',     LinkAdvancePurchaseInvoiceAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}/link-advance',     UnlinkAdvancePurchaseInvoiceAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}/advance-suggestion', DismissAdvanceSuggestionAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/pdf',            UploadPurchaseInvoicePdfAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/pdf',            DownloadPurchaseInvoicePdfAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/source',         DownloadPurchaseInvoiceSourceAction::class);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}/pdf',            DeletePurchaseInvoicePdfAction::class);
        // Our generated PDF + Pohoda/ISDOC export pro přijatou
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/our-pdf',        OurPdfPurchaseInvoiceAction::class);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/isdoc',          [ExportPurchaseInvoiceAction::class, 'isdoc']);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/pohoda',         [ExportPurchaseInvoiceAction::class, 'pohoda']);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/activity',       PurchaseInvoiceActivityAction::class);
        // PF ↔ DMS provázání (Epic F7 §6) — link/list/unlink DMS dokumentů přes
        // document_links(entity_type='purchase_invoice'); fixní pdf_/source_ sloupce PF netknuté.
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/documents',      [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceDocumentsAction::class, 'list']);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/documents',      [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceDocumentsAction::class, 'link']);
        $app->delete ('/api/purchase-invoices/{id:[0-9]+}/documents',      [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceDocumentsAction::class, 'unlink']);
        // Epic SKLAD (§5.6) — příjem na sklad z PF. Vlastní PermissionMiddleware pravidla
        // (stejná skupina jako ostatní /api/purchase-invoices cesty); Action navíc
        // hlídá supplier.stock_enabled (GuardsStockEnabled).
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/stock-receipt',  [\MyInvoice\Action\Stock\StockReceiptAction::class, 'propose']);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/stock-receipt',  [\MyInvoice\Action\Stock\StockReceiptAction::class, 'create']);
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/stock-receipts', [\MyInvoice\Action\Stock\StockReceiptAction::class, 'list']);
        // „Zaplatit pomocí QR" — QR z uloženého účtu (GET, read), jednorázové lazy
        // doplnění účtu z ISDOC/AI (POST, write), ruční editace účtu (PUT, write).
        $app->get    ('/api/purchase-invoices/{id:[0-9]+}/payment-qr',     PaymentQrAction::class);
        $app->post   ('/api/purchase-invoices/{id:[0-9]+}/payment-qr/extract-account', [PaymentQrAction::class, 'extractAccount']);
        $app->put    ('/api/purchase-invoices/{id:[0-9]+}/payment-account', [PaymentQrAction::class, 'updateAccount']);
        // Platební příkazy (payment orders) — hromadný příkaz k úhradě z nezaplacených
        // přijatých faktur do CSV/PDF/ABO(KPC). Literální „payment-orders" je nečíselné,
        // takže nekoliduje s GET /{id:[0-9]+}. POST je write (PermissionMiddleware dle metody).
        $app->get    ('/api/purchase-invoices/payment-orders/candidates',          [PaymentOrderAction::class, 'candidates']);
        $app->get    ('/api/purchase-invoices/payment-orders/verify-account',       [PaymentOrderAction::class, 'verifyAccount']);
        $app->get    ('/api/purchase-invoices/payment-orders',                      [PaymentOrderAction::class, 'history']);
        $app->post   ('/api/purchase-invoices/payment-orders',                      [PaymentOrderAction::class, 'create']);
        $app->post   ('/api/purchase-invoices/payment-orders/mark',                 [PaymentOrderAction::class, 'markOrdered']);
        $app->get    ('/api/purchase-invoices/payment-orders/{id:[0-9]+}/download', [PaymentOrderAction::class, 'download']);
        $app->get    ('/api/purchase-invoices/payment-orders/{id:[0-9]+}',          [PaymentOrderAction::class, 'show']);

        // Pravidelné fakturace (recurring templates)
        $app->get    ('/api/recurring',                       [RecurringTemplateAction::class, 'list']);
        $app->post   ('/api/recurring',                       [RecurringTemplateAction::class, 'create']);
        $app->get    ('/api/recurring/{id:[0-9]+}',           [RecurringTemplateAction::class, 'get']);
        $app->get    ('/api/recurring/{id:[0-9]+}/invoices',  [RecurringTemplateAction::class, 'invoices']);
        $app->put    ('/api/recurring/{id:[0-9]+}',           [RecurringTemplateAction::class, 'update']);
        $app->delete ('/api/recurring/{id:[0-9]+}',           [RecurringTemplateAction::class, 'delete']);
        $app->post   ('/api/recurring/{id:[0-9]+}/pause',     [RecurringTemplateAction::class, 'pause']);
        $app->post   ('/api/recurring/{id:[0-9]+}/resume',    [RecurringTemplateAction::class, 'resume']);
        $app->post   ('/api/recurring/{id:[0-9]+}/run-now',   [RecurringTemplateAction::class, 'runNow']);

        // Work reports — výkaz víceprací (M5)
        $app->get    ('/api/invoices/{id:[0-9]+}/work-report', GetWorkReportAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}/work-report', SaveWorkReportAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}/work-report/materials', SaveWorkReportMaterialsAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/work-report', DeleteWorkReportAction::class);

        // Schvalování výkazu zákazníkem (M8)
        $app->post   ('/api/invoices/{id:[0-9]+}/request-approval',      RequestApprovalAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/request-approval-test', RequestApprovalTestAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}/approval-status',       UpdateApprovalStatusAction::class);

        // Web faktura — správa trvalého veřejného odkazu (authenticated)
        $app->post   ('/api/invoices/{id:[0-9]+}/public-link',            [PublicLinkAction::class, 'ensure']);
        $app->post   ('/api/invoices/{id:[0-9]+}/public-link/regenerate', [PublicLinkAction::class, 'regenerate']);

        // Public schvalovací endpointy (bez auth, jen token)
        $app->get    ('/api/public/approval/{token:[a-f0-9]{32,128}}',          PublicApprovalGetAction::class);
        $app->get    ('/api/public/approval/{token:[a-f0-9]{32,128}}/logo',     PublicApprovalLogoAction::class);
        $app->post   ('/api/public/approval/{token:[a-f0-9]{32,128}}/decide',   PublicApprovalDecideAction::class);

        // Web faktura — veřejný náhled + PDF + přílohy (bez auth, jen token)
        $app->get    ('/api/public/invoice/{token:[a-f0-9]{32,128}}',     PublicInvoiceGetAction::class);
        $app->get    ('/api/public/invoice/{token:[a-f0-9]{32,128}}/pdf', PublicInvoicePdfAction::class);
        $app->get    ('/api/public/invoice/{token:[a-f0-9]{32,128}}/attachment/{attId:[0-9]+}', PublicInvoiceAttachmentAction::class);

        // Public náhled na výkaz práce (bez auth; token + e-mailová autorizace kódem)
        $app->get    ('/api/public/work-report/{token:[a-f0-9]{32,128}}',              PublicWorkReportGetAction::class);
        $app->post   ('/api/public/work-report/{token:[a-f0-9]{32,128}}/request-code', PublicWorkReportRequestCodeAction::class);
        $app->post   ('/api/public/work-report/{token:[a-f0-9]{32,128}}/verify',       PublicWorkReportVerifyAction::class);
        $app->get    ('/api/public/domain-verification/{token:[a-f0-9]{64}}', \MyInvoice\Action\Public\DomainVerificationAction::class);

        // Zabezpečený odkaz na osobní mzdový dokument (bez auth; lokátor + jednorázový
        // kód na známou adresu zaměstnance). Zaměstnanec není uživatel aplikace.
        // Lokátor je přesně 64 hex znaků — kratší varianty nepřipouštíme, ať se
        // nedá formát degradovat na hádatelnou délku.
        $app->get ('/api/public/payroll-document/{token:[a-f0-9]{64}}',
                   [PublicPayrollDocumentAccessAction::class, 'state']);
        $app->post('/api/public/payroll-document/{token:[a-f0-9]{64}}/request-code',
                   [PublicPayrollDocumentAccessAction::class, 'requestCode']);
        $app->post('/api/public/payroll-document/{token:[a-f0-9]{64}}/verify',
                   [PublicPayrollDocumentAccessAction::class, 'verify']);
        $app->get ('/api/public/payroll-document/{token:[a-f0-9]{64}}/download',
                   [PublicPayrollDocumentAccessAction::class, 'download']);

        // Úplné mzdy — samostatný bounded context nezávislý na účetním režimu.
        $app->group('/api/payroll', function ($g) {
            $g->get('/capabilities', PayrollCapabilitiesAction::class);
            $g->get('/components/jmhz-targets', [PayrollComponentJmhzMappingsAction::class, 'targets']);
            $g->get('/components/jmhz-mappings', [PayrollComponentJmhzMappingsAction::class, 'list']);
            $g->get('/components', [PayrollComponentsAction::class, 'list']);
            $g->post('/components', [PayrollComponentsAction::class, 'create']);
            $g->get('/components/{id:[0-9]+}/jmhz-mapping', [PayrollComponentJmhzMappingsAction::class, 'get']);
            $g->put('/components/{id:[0-9]+}/jmhz-mapping', [PayrollComponentJmhzMappingsAction::class, 'put']);
            $g->delete('/components/{id:[0-9]+}/jmhz-mapping', [PayrollComponentJmhzMappingsAction::class, 'remove']);
            $g->put('/components/{id:[0-9]+}', [PayrollComponentsAction::class, 'update']);
            // Smazat jde jen NIKDY NEPOUŽITÁ verze složky; u použité zůstává
            // deaktivace a ukončení platnosti přes PUT výše.
            $g->delete('/components/{id:[0-9]+}', [PayrollComponentsAction::class, 'delete']);
            $g->get('/risky-savings', [PayrollRiskySavingsAction::class, 'list']);
            $g->put('/risky-savings/evidence', [PayrollRiskySavingsAction::class, 'save']);
            $g->get('/deduction-agreements', [PayrollDeductionAgreementAction::class, 'list']);
            $g->post('/deduction-agreements', [PayrollDeductionAgreementAction::class, 'create']);
            $g->get(
                '/deduction-agreements/{id:[0-9]+}',
                [PayrollDeductionAgreementAction::class, 'detail'],
            );
            $g->put(
                '/deduction-agreements/{id:[0-9]+}',
                [PayrollDeductionAgreementAction::class, 'update'],
            );
            $g->post(
                '/deduction-agreements/{id:[0-9]+}/commands/{command:[a-z_]+}',
                [PayrollDeductionAgreementAction::class, 'transition'],
            );
            $g->get('/enforcement/cases', [PayrollEnforcementAction::class, 'list']);
            $g->get('/enforcement/cooperation/candidates', [PayrollXmlzamCooperationAction::class, 'candidates']);
            $g->get('/enforcement/cooperation/requests/{id:[0-9]+}', [PayrollXmlzamCooperationAction::class, 'detail']);
            $g->post('/enforcement/cooperation/requests/import', [PayrollXmlzamCooperationAction::class, 'import']);
            $g->post('/enforcement/cooperation/requests/{id:[0-9]+}/preview', [PayrollXmlzamCooperationAction::class, 'preview']);
            $g->post('/enforcement/cooperation/requests/{id:[0-9]+}/responses', [PayrollXmlzamCooperationAction::class, 'freeze']);
            $g->post('/enforcement/cooperation/responses/{id:[0-9]+}/enqueue', [PayrollXmlzamCooperationAction::class, 'enqueue']);
            $g->post('/enforcement/cases', [PayrollEnforcementAction::class, 'create']);
            $g->get('/enforcement/cases/{id:[0-9]+}', [PayrollEnforcementAction::class, 'detail']);
            $g->get('/enforcement/cases/{id:[0-9]+}/parties', [PayrollEnforcementFactsAction::class, 'parties']);
            $g->post('/enforcement/cases/{id:[0-9]+}/parties', [PayrollEnforcementFactsAction::class, 'appendParty']);
            $g->get('/enforcement/cases/{id:[0-9]+}/recipient-instructions', [PayrollEnforcementFactsAction::class, 'recipientInstructions']);
            $g->post('/enforcement/cases/{id:[0-9]+}/recipient-instructions', [PayrollEnforcementFactsAction::class, 'appendRecipientInstruction']);
            $g->delete('/enforcement/cases/{id:[0-9]+}', [PayrollEnforcementAction::class, 'delete']);
            $g->post('/enforcement/cases/{id:[0-9]+}/claims', [PayrollEnforcementAction::class, 'addClaim']);
            $g->get('/enforcement/cases/{id:[0-9]+}/claims/{claimId:[0-9]+}/breakdowns', [PayrollEnforcementFactsAction::class, 'breakdowns']);
            $g->post('/enforcement/cases/{id:[0-9]+}/claims/{claimId:[0-9]+}/breakdowns', [PayrollEnforcementFactsAction::class, 'appendBreakdown']);
            $g->put(
                '/enforcement/cases/{id:[0-9]+}/claims/{claimId:[0-9]+}',
                [PayrollEnforcementAction::class, 'updateClaim'],
            );
            $g->delete(
                '/enforcement/cases/{id:[0-9]+}/claims/{claimId:[0-9]+}',
                [PayrollEnforcementAction::class, 'deleteClaim'],
            );
            $g->put('/enforcement/cases/{id:[0-9]+}/evidence', [PayrollEnforcementAction::class, 'updateEvidence']);
            $g->post(
                '/enforcement/cases/{id:[0-9]+}/commands/{command:[a-z_]+}',
                [PayrollEnforcementAction::class, 'transition'],
            );
            $g->put(
                '/enforcement/people/{employeeId:[0-9]+}/month/{period:[0-9]{4}-[0-9]{2}}/evidence',
                [PayrollEnforcementAction::class, 'saveMonthEvidence'],
            );
            $g->get(
                '/enforcement/people/{employeeId:[0-9]+}/month/{period:[0-9]{4}-[0-9]{2}}/evidence',
                [PayrollEnforcementAction::class, 'monthEvidence'],
            );
            $g->get(
                '/insolvency/people/{employeeId:[0-9]+}/month/{period:[0-9]{4}-[0-9]{2}}/options',
                [PayrollEnforcementAction::class, 'insolvencyOptions'],
            );
            $g->get(
                '/insolvency/people/{employeeId:[0-9]+}/month/{period:[0-9]{4}-[0-9]{2}}/evidence',
                [PayrollEnforcementAction::class, 'monthEvidence'],
            );
            $g->put(
                '/insolvency/people/{employeeId:[0-9]+}/month/{period:[0-9]{4}-[0-9]{2}}/evidence',
                [PayrollEnforcementAction::class, 'saveMonthEvidence'],
            );
            $g->post(
                '/insolvency/people/{employeeId:[0-9]+}/month/{period:[0-9]{4}-[0-9]{2}}/commands/cancel',
                [PayrollEnforcementAction::class, 'cancelInsolvency'],
            );
            $g->post(
                '/enforcement/people/{employeeId:[0-9]+}/dependants',
                [PayrollEnforcementAction::class, 'addDependant'],
            );
            $g->get(
                '/enforcement/people/{employeeId:[0-9]+}/dependants',
                [PayrollEnforcementAction::class, 'dependants'],
            );
            $g->get(
                '/benefit-baskets',
                [PayrollBenefitBasketOverviewAction::class, 'list'],
            );
            $g->get('/inputs', [PayrollInputsAction::class, 'list']);
            $g->post('/inputs/preview', [PayrollInputsAction::class, 'preview']);
            $g->post('/inputs', [PayrollInputsAction::class, 'create']);
            $g->put('/inputs/{id:[0-9]+}', [PayrollInputsAction::class, 'update']);
            // Musí předcházet `/inputs/{id}` — jinak by `approve-batch` spadlo
            // do vzoru s číselným id a skončilo jako 404.
            $g->post('/inputs/approve-batch', [PayrollInputsAction::class, 'approveBatch']);
            $g->post('/inputs/{id:[0-9]+}/approve', [PayrollInputsAction::class, 'approve']);
            $g->post('/inputs/{id:[0-9]+}/cancel', [PayrollInputsAction::class, 'cancel']);
            $g->post(
                '/inputs/{id:[0-9]+}/reverse-benefit',
                [PayrollInputsAction::class, 'reverseBenefit'],
            );
            $g->get('/quick-inputs', [PayrollQuickInputsAction::class, 'list']);
            $g->put('/quick-inputs', [PayrollQuickInputsAction::class, 'save']);
            $g->get('/recurring-components', [PayrollRecurringComponentsAction::class, 'list']);
            $g->post('/recurring-components', [PayrollRecurringComponentsAction::class, 'create']);
            $g->post('/recurring-components/materialize', [PayrollRecurringComponentsAction::class, 'materialize']);
            $g->put('/recurring-components/{id:[0-9]+}', [PayrollRecurringComponentsAction::class, 'update']);
            // Smazat jde jen předpis, ze kterého ještě nevznikl mzdový vstup.
            $g->delete('/recurring-components/{id:[0-9]+}', [PayrollRecurringComponentsAction::class, 'delete']);
            $g->get('/travel/trips', [PayrollTravelAction::class, 'list']);
            $g->post('/travel/trips', [PayrollTravelAction::class, 'create']);
            $g->post('/travel/preview', [PayrollTravelAction::class, 'preview']);
            $g->put('/travel/trips/{id:[0-9]+}', [PayrollTravelAction::class, 'update']);
            $g->get('/travel/trips/{id:[0-9]+}/calculation', [PayrollTravelAction::class, 'recalculate']);
            $g->post('/travel/trips/{id:[0-9]+}/approve', [PayrollTravelAction::class, 'approve']);
            $g->post('/travel/trips/{id:[0-9]+}/materialize', [PayrollTravelAction::class, 'materialize']);
            // Dvě různé akce: `cancel` nechá stopu po cestě, která se nekonala,
            // `delete` odklidí koncept, který vůbec neměl vzniknout.
            $g->post('/travel/trips/{id:[0-9]+}/cancel', [PayrollTravelAction::class, 'cancel']);
            $g->delete('/travel/trips/{id:[0-9]+}', [PayrollTravelAction::class, 'delete']);
            $g->post('/input-imports/preview', [PayrollInputImportsAction::class, 'preview']);
            $g->post('/input-imports/apply', [PayrollInputImportsAction::class, 'apply']);
            $g->get('/payments/liabilities', [PayrollPaymentAction::class, 'listLiabilities']);
            $g->get('/payments/payer-options', [PayrollPaymentAction::class, 'listPayerOptions']);
            $g->get('/payments/batches', [PayrollPaymentAction::class, 'listBatches']);
            $g->post('/payments/batches', [PayrollPaymentAction::class, 'createBatch']);
            // Legislativní rulesety — globální číselník (default v kódu + DB override),
            // konkrétnější cesty musí být před `/rulesets/{rulesetId}`.
            $g->get('/rulesets', [PayrollRulesetAction::class, 'list']);
            $g->get('/rulesets/{rulesetId:[A-Za-z0-9][A-Za-z0-9._-]{0,159}}/impact-preview', [PayrollRulesetAction::class, 'impactPreview']);
            $g->get('/rulesets/{rulesetId:[A-Za-z0-9][A-Za-z0-9._-]{0,159}}/diff', [PayrollRulesetAction::class, 'diff']);
            $g->post(
                '/rulesets/{rulesetId:[A-Za-z0-9][A-Za-z0-9._-]{0,159}}/commands/{command:review|approve|activate|supersede}',
                [PayrollRulesetAction::class, 'command'],
            );
            $g->get('/rulesets/{rulesetId:[A-Za-z0-9][A-Za-z0-9._-]{0,159}}', [PayrollRulesetAction::class, 'detail']);
            $g->put('/rulesets/{rulesetId:[A-Za-z0-9][A-Za-z0-9._-]{0,159}}', [PayrollRulesetAction::class, 'update']);
            $g->delete('/rulesets/{rulesetId:[A-Za-z0-9][A-Za-z0-9._-]{0,159}}', [PayrollRulesetAction::class, 'reset']);
            $g->get('/payments/reconciliation', [PayrollPaymentAction::class, 'listReconciliation']);
            $g->get('/payments/reconciliation/options', [PayrollPaymentAction::class, 'searchReconciliationOptions']);
            $g->post('/payments/reconciliation/matches', [PayrollPaymentAction::class, 'matchPayment']);
            $g->post('/payments/reconciliation/reversals', [PayrollPaymentAction::class, 'reversePayment']);
            $g->post(
                '/payments/reconciliation/incoming-refunds',
                [PayrollPaymentAction::class, 'matchIncomingRefund'],
            );
            $g->post(
                '/payments/reconciliation/incoming-refund-reversals',
                [PayrollPaymentAction::class, 'reverseIncomingRefund'],
            );
            $g->post(
                '/payments/batches/{batchId:[0-9]+}/exports',
                [PayrollPaymentAction::class, 'generateExport'],
            );
            $g->post(
                '/payments/exports/{exportId:[0-9]+}/download-grants',
                [PayrollPaymentAction::class, 'createDownloadGrant'],
            );
            $g->post('/payments/exports/download', [PayrollPaymentAction::class, 'downloadExport']);
            $g->post(
                '/revisions/{revisionId:[0-9]+}/payments/liabilities',
                [PayrollPaymentAction::class, 'materializeLiabilities'],
            );
            $g->post(
                '/revisions/{revisionId:[0-9]+}/payments/net-wage-liabilities',
                [PayrollPaymentAction::class, 'materializeNetWages'],
            );
            $g->get(
                '/revisions/{revisionId:[0-9]+}/net-results/{employeeId:[0-9]+}',
                [PayrollNetResultAction::class, 'detail'],
            );
            // MZ-10-W07 / MZ-11-W07 — jak vzniklo sociální a zdravotní pojistné.
            // Rozklad se nedotahuje se seznamem běhů: je objemný a potřebný jen
            // tehdy, když si ho účetní vyžádá u konkrétní osoby.
            $g->get(
                '/revisions/{revisionId:[0-9]+}/insurance-breakdowns/{employeeId:[0-9]+}',
                [PayrollInsuranceBreakdownAction::class, 'detail'],
            );
            // MZ-18-W07 — read-only reconciliation účetního můstku mezd.
            $g->get(
                '/posting/reconciliation',
                [PayrollPostingReconciliationAction::class, 'get'],
            );
            $g->get('/runs', [PayrollRunsAction::class, 'list']);
            $g->get('/reports/annual/{year:[0-9]{4}}', [PayrollAnnualReportAction::class, 'show']);
            // Žádosti o poukázání chybějící částky na daňovém bonusu
            // (§ 35d odst. 5 = DPZMB1, odst. 9 = DPZDB1). Vyplacené bonusy nad
            // rámec sražených záloh doplácí zaměstnavatel ze svého a bez téhle
            // žádosti mu ty peníze zůstanou u státu.
            $g->get('/reports/tax-bonus-request/preview',
                [\MyInvoice\Action\Payroll\TaxBonusRequestAction::class, 'preview']);
            $g->get('/reports/tax-bonus-request',
                [\MyInvoice\Action\Payroll\TaxBonusRequestAction::class, 'download']);
            // Roční vyúčtování daně ze závislé činnosti (DPZVD6, § 38j odst. 4)
            // a daně vybírané srážkou (DPSVD2, § 38d). Dvě samostatná podání
            // s vlastní lhůtou, ne jedno se dvěma přílohami.
            $g->get('/reports/tax-statement/preview',
                [\MyInvoice\Action\Payroll\TaxStatementAction::class, 'preview']);
            $g->get('/reports/tax-statement',
                [\MyInvoice\Action\Payroll\TaxStatementAction::class, 'download']);
            // Roční uzávěrka mzdových běhů je úmyslně samostatná od ročního
            // zúčtování daně zaměstnanců; všechna mutace jsou session-only.
            $g->get('/year-close/{year:[0-9]{4}}', [PayrollYearCloseAction::class, 'get']);
            $g->post('/year-close/{year:[0-9]{4}}/close', [PayrollYearCloseAction::class, 'close']);
            $g->post('/year-close/{year:[0-9]{4}}/reopen', [PayrollYearCloseAction::class, 'reopen']);
            $g->get('/runs/{id:[0-9]+}/history', [PayrollRunsAction::class, 'history']);
            // Detail existuje kvůli tomu, aby seznam nemusel posílat celý
            // výsledkový snapshot každého běhu — ten se dotahuje na vyžádání.
            $g->get('/runs/{id:[0-9]+}', [PayrollRunsAction::class, 'detail']);
            $g->post('/runs', [PayrollRunsAction::class, 'create']);
            $g->delete('/runs/{id:[0-9]+}', [PayrollRunsAction::class, 'delete']);
            $g->post(
                '/runs/{id:[0-9]+}/commands/{command:[a-z_]+}',
                [PayrollRunsAction::class, 'command'],
            );
            // Rezervace mzdového období. `release-legacy` je jediná cesta, jak
            // z aplikace uvolnit období zabrané původním ručním zaúčtováním —
            // bez ní se muselo zasahovat přímo v databázi.
            $g->get(
                '/periods/{period:[0-9]{4}-[0-9]{2}}/ownership',
                [PayrollRunsAction::class, 'periodOwnership'],
            );
            $g->post(
                '/periods/{period:[0-9]{4}-[0-9]{2}}/ownership/release-legacy',
                [PayrollRunsAction::class, 'releaseLegacyPeriod'],
            );
            // MZ-01-W07 — chybějící půlka override: varování vyžadující schválení
            // dosud zastavilo `approve` a nešlo ho odklidit žádnou routou.
            $g->post(
                '/runs/{id:[0-9]+}/validations/{validationId:[0-9]+}/override',
                [PayrollRunValidationOverrideAction::class, 'grant'],
            );
            $g->delete(
                '/runs/{id:[0-9]+}/validations/{validationId:[0-9]+}/override',
                [PayrollRunValidationOverrideAction::class, 'revoke'],
            );
            $g->get('/documents', [PayrollDocumentAction::class, 'list']);
            $g->get('/documents/annual', [PayrollDocumentAction::class, 'listAnnual']);
            $g->post(
                '/exports/monthly/{period:[0-9]{4}-[0-9]{2}}',
                [PayrollPeriodExportAction::class, 'createMonthly'],
            );
            $g->post(
                '/exports/annual/{year:[0-9]{4}}',
                [PayrollPeriodExportAction::class, 'createAnnual'],
            );
            $g->get(
                '/exports/jobs/{jobId:[0-9]+}',
                [PayrollPeriodExportAction::class, 'status'],
            );
            $g->post(
                '/exports/jobs/{jobId:[0-9]+}/download-grants',
                [PayrollPeriodExportAction::class, 'grantJob'],
            );
            $g->post(
                '/exports/{exportId:[0-9]+}/download-grants',
                [PayrollPeriodExportAction::class, 'grant'],
            );
            $g->post(
                '/exports/download',
                [PayrollPeriodExportAction::class, 'download'],
            );
            $g->post(
                '/people/{employeeId:[0-9]+}/documents/payroll-sheet/{year:[0-9]{4}}',
                [PayrollDocumentAction::class, 'generatePayrollSheet'],
            );
            $g->post(
                '/people/{employeeId:[0-9]+}/documents/tax-certificate/{kind:advance|withholding}/{year:[0-9]{4}}',
                [AnnualTaxCertificateAction::class, 'generate'],
            );
            // MZ-25 — roční zúčtování záloh a daňového zvýhodnění (§ 38ch ZDP).
            // Náhled a provedení jsou dvě routy schválně: provedení je právní
            // úkon plátce daně a nesmí se stát tím, že se někdo podívá.
            $g->get(
                '/annual-settlements/{year:[0-9]{4}}',
                [PayrollAnnualSettlementAction::class, 'list'],
            );
            $g->get(
                '/annual-settlements/{year:[0-9]{4}}/people/{employeeId:[0-9]+}',
                [PayrollAnnualSettlementAction::class, 'preview'],
            );
            $g->put(
                '/annual-settlements/{year:[0-9]{4}}/people/{employeeId:[0-9]+}/request',
                [PayrollAnnualSettlementAction::class, 'saveRequest'],
            );
            // Potvrzení od předchozích plátců (§ 38ch odst. 3). Celý seznam za
            // rok jedním PUT — doklady dávají smysl jen jako úplná sada od
            // VŠECH předchozích plátců.
            $g->put(
                '/annual-settlements/{year:[0-9]{4}}/people/{employeeId:[0-9]+}/certificates',
                [PayrollAnnualSettlementAction::class, 'saveCertificates'],
            );
            $g->post(
                '/annual-settlements/{year:[0-9]{4}}/people/{employeeId:[0-9]+}/settle',
                [PayrollAnnualSettlementAction::class, 'settle'],
            );
            $g->post(
                '/runs/{runId:[0-9]+}/revisions/{revisionId:[0-9]+}/documents/monthly-bundle',
                [PayrollDocumentAction::class, 'generateBundle'],
            );
            // Dávková orchestrace rendererů nad schválenou revizí: vrací
            // zprávu o dokumentační úplnosti měsíce, ne jen vytvořená PDF.
            $g->post(
                '/runs/{runId:[0-9]+}/revisions/{revisionId:[0-9]+}/documents/batch',
                [PayrollDocumentAction::class, 'generateBatch'],
            );
            $g->get(
                '/documents/batches/{batchId:[0-9]+}',
                [PayrollDocumentAction::class, 'batchDetail'],
            );
            $g->get(
                '/documents/batches/{batchId:[0-9]+}/items',
                [PayrollDocumentAction::class, 'batchItems'],
            );
            $g->post(
                '/documents/batches/{batchId:[0-9]+}/items/{itemId:[0-9]+}/retry',
                [PayrollDocumentAction::class, 'retryBatchItem'],
            );
            // Roční dokumenty (mzdový list, potvrzení o zdanitelných příjmech)
            // za celou firmu. Rozsahem je zdaňovací období, ne běh a revize —
            // proto vlastní fronta i vlastní routy.
            $g->post(
                '/documents/annual-batches/{kind:payroll-sheet|advance|withholding}/{year:[0-9]{4}}',
                [PayrollAnnualDocumentBatchAction::class, 'enqueue'],
            );
            $g->get(
                '/documents/annual-batches/{batchId:[0-9]+}',
                [PayrollAnnualDocumentBatchAction::class, 'detail'],
            );
            $g->get(
                '/documents/annual-batches/{batchId:[0-9]+}/items',
                [PayrollAnnualDocumentBatchAction::class, 'items'],
            );
            $g->post(
                '/documents/annual-batches/{batchId:[0-9]+}/items/{itemId:[0-9]+}/retry',
                [PayrollAnnualDocumentBatchAction::class, 'retryItem'],
            );
            $g->get(
                '/employments/{id:[0-9]+}/documents/exit',
                [PayrollEmploymentExitDocumentAction::class, 'list'],
            );
            $g->post(
                '/employments/{id:[0-9]+}/documents/exit/{kind:employment-certificate|average-earnings-certificate|average-earnings-statement}',
                [PayrollEmploymentExitDocumentAction::class, 'generate'],
            );
            $g->post(
                '/documents/{documentId:[0-9]+}/download-grant',
                [PayrollDocumentAction::class, 'grant'],
            );
            $g->get(
                '/documents/{documentId:[0-9]+}/download',
                [PayrollDocumentAction::class, 'download'],
            );
            $g->get(
                '/documents/{documentId:[0-9]+}/delivery-events',
                [PayrollDocumentAction::class, 'deliveryEvents'],
            );
            $g->post(
                '/documents/{documentId:[0-9]+}/delivery-events',
                [PayrollDocumentAction::class, 'recordDeliveryEvent'],
            );
            $g->get(
                '/documents/{documentId:[0-9]+}/secure-links',
                [PayrollDocumentDeliveryAction::class, 'list'],
            );
            $g->post(
                '/documents/{documentId:[0-9]+}/secure-links',
                [PayrollDocumentDeliveryAction::class, 'send'],
            );
            $g->delete(
                '/documents/{documentId:[0-9]+}/secure-links/{linkId:[0-9]+}',
                [PayrollDocumentDeliveryAction::class, 'revoke'],
            );
            $g->get('/people', [PayrollPeopleAction::class, 'list']);
            $g->post('/people', [PayrollPeopleAction::class, 'create']);
            $g->get('/people/{id:[0-9]+}', [PayrollPeopleAction::class, 'detail']);
            $g->delete('/people/{id:[0-9]+}', [PayrollPeopleAction::class, 'delete']);
            $g->post('/people/{id:[0-9]+}/employments', [PayrollEmploymentAction::class, 'create']);
            $g->delete('/employments/{id:[0-9]+}', [PayrollEmploymentAction::class, 'delete']);
            $g->get(
                '/jmhz/employment-evidence-options',
                [PayrollEmploymentAction::class, 'jmhzEvidenceOptions'],
            );
            $g->get(
                '/jmhz/municipalities',
                [PayrollEmploymentAction::class, 'jmhzMunicipalities'],
            );
            $g->get(
                '/jmhz/identities/{employmentId:[0-9]+}',
                [PayrollJmhzIdentityAction::class, 'show'],
            );
            $g->put(
                '/jmhz/identities/{employmentId:[0-9]+}',
                [PayrollJmhzIdentityAction::class, 'put'],
            );
            // Našeptávač klasifikace zaměstnání ČSÚ — hledání běží na serveru,
            // do prohlížeče jde jen shoda (viz PayrollCzIscoAction).
            $g->get('/cz-isco', [PayrollCzIscoAction::class, 'search']);
            $g->put('/employments/{id:[0-9]+}/terms', [PayrollEmploymentAction::class, 'addTerms']);
            // PUT zakládá NOVOU verzi podmínek, PATCH opravuje tu platnou.
            // Dvě různé věci, dvě routy — ať se nedají splést jedním příznakem v těle.
            $g->patch(
                '/employments/{id:[0-9]+}/terms/current',
                [PayrollEmploymentAction::class, 'correctTerms'],
            );
            $g->patch('/employments/{id:[0-9]+}/code', [PayrollEmploymentAction::class, 'rename']);
            $g->patch('/employments/{id:[0-9]+}/meal-entitlement-basis', [PayrollEmploymentAction::class, 'setMealEntitlementBasis']);
            $g->post(
                '/employments/{id:[0-9]+}/transitions/{target:preregistered|active|suspended|ended|archived|no_show}',
                [PayrollEmploymentAction::class, 'transition'],
            );
            $g->put(
                '/employments/{id:[0-9]+}/checklist/{item_key:[a-z0-9_]+}',
                [PayrollEmploymentAction::class, 'checklist'],
            );
            $g->get('/people/{id:[0-9]+}/profile', [PayrollPersonProfileAction::class, 'get']);
            $g->put('/people/{id:[0-9]+}/profile', [PayrollPersonProfileAction::class, 'put']);
            $g->get(
                '/people/{id:[0-9]+}/dependants',
                [PayrollDependantAction::class, 'list'],
            );
            $g->post(
                '/people/{id:[0-9]+}/dependants',
                [PayrollDependantAction::class, 'create'],
            );
            $g->put(
                '/people/{id:[0-9]+}/dependants/{dependantId:[0-9]+}',
                [PayrollDependantAction::class, 'update'],
            );
            $g->post(
                '/people/{id:[0-9]+}/dependants/{dependantId:[0-9]+}/claims',
                [PayrollDependantAction::class, 'createClaim'],
            );
            $g->put(
                '/people/{id:[0-9]+}/dependants/{dependantId:[0-9]+}/claims/{claimId:[0-9]+}',
                [PayrollDependantAction::class, 'saveClaim'],
            );
            $g->put(
                '/people/{id:[0-9]+}/quick-edit',
                [PayrollPersonQuickEditAction::class, 'put'],
            );
            $g->post(
                '/people/{id:[0-9]+}/sensitive-reveal',
                [PayrollPersonSensitiveRevealAction::class, 'post'],
            );
            $g->get(
                '/people/{id:[0-9]+}/statutory-evidence',
                [PayrollPersonStatutoryEvidenceAction::class, 'show'],
            );
            $g->put(
                '/people/{id:[0-9]+}/statutory-evidence',
                [PayrollPersonStatutoryEvidenceAction::class, 'save'],
            );
            $g->get(
                '/people/{id:[0-9]+}/foreign-permits',
                [PayrollForeignPermitAction::class, 'show'],
            );
            $g->post(
                '/people/{id:[0-9]+}/foreign-permits',
                [PayrollForeignPermitAction::class, 'create'],
            );
            $g->get(
                '/people/{id:[0-9]+}/statutory-openings',
                [PayrollOpeningBalanceAction::class, 'show'],
            );
            $g->put(
                '/people/{id:[0-9]+}/statutory-openings',
                [PayrollOpeningBalanceAction::class, 'save'],
            );
            $g->post(
                '/people/{employeeId:[0-9]+}/accounts/{accountId:[0-9]+}/verify',
                [PayrollPaymentAction::class, 'verifyPersonAccount'],
            );
            $g->get(
                '/people/{employeeId:[0-9]+}/payout-rules',
                [PayrollPayoutRulesAction::class, 'list'],
            );
            $g->post(
                '/people/{employeeId:[0-9]+}/payout-rules',
                [PayrollPayoutRulesAction::class, 'create'],
            );
            $g->post(
                '/people/{employeeId:[0-9]+}/payout-rules/apply-defaults',
                [PayrollPayoutRulesAction::class, 'applyDefaults'],
            );
            $g->put(
                '/people/{employeeId:[0-9]+}/payout-rules/{ruleId:[0-9]+}',
                [PayrollPayoutRulesAction::class, 'update'],
            );
            $g->delete(
                '/people/{employeeId:[0-9]+}/payout-rules/{ruleId:[0-9]+}',
                [PayrollPayoutRulesAction::class, 'deactivate'],
            );
            $g->get(
                '/submissions/regzel/profile',
                [PayrollRegzelAction::class, 'profile'],
            );
            $g->put(
                '/submissions/regzel/profile',
                [PayrollRegzelAction::class, 'saveProfile'],
            );
            $g->post(
                '/submissions/regzel/prepare',
                [PayrollRegzelAction::class, 'prepare'],
            );
            $g->get(
                '/submissions/regzel/snapshots',
                [PayrollRegzelAction::class, 'snapshots'],
            );
            $g->get(
                '/submissions/regzel/snapshots/{id:[0-9]+}/xml',
                [PayrollRegzelAction::class, 'download'],
            );
            $g->get(
                '/submissions/overview',
                PayrollSubmissionOverviewAction::class,
            );
            $g->get(
                '/submissions/monthly-checklist',
                PayrollMonthlyChecklistAction::class,
            );
            $g->get('/deadlines', PayrollDeadlineOverviewAction::class);
            $g->get('/operational-health', PayrollOperationalHealthAction::class);
            $g->get(
                '/operational-reconciliation',
                [PayrollOperationalReconciliationAction::class, 'get'],
            );
            $g->post(
                '/operational-reconciliation/sweep',
                [PayrollOperationalReconciliationAction::class, 'sweep'],
            );
            $g->get(
                '/operational-reconciliation/issues/{issueId:[0-9]+}',
                [PayrollOperationalReconciliationAction::class, 'detail'],
            );
            $g->get(
                '/submissions/statutory-obligations',
                [PayrollStatutoryObligationAction::class, 'overview'],
            );
            $g->post(
                '/submissions/statutory-obligations/evidence',
                [PayrollStatutoryObligationAction::class, 'record'],
            );
            $g->get(
                '/submissions/inbox',
                [PayrollSubmissionInboxAction::class, 'list'],
            );
            $g->post(
                '/submissions/inbox/{itemId:[0-9]+}/acknowledge',
                [PayrollSubmissionInboxAction::class, 'acknowledge'],
            );
            $g->post(
                '/submissions/inbox/{itemId:[0-9]+}/snooze',
                [PayrollSubmissionInboxAction::class, 'snooze'],
            );
            $g->get(
                '/submissions/jmhz-pvpoj/{revisionId:[0-9]+}/offices',
                [PayrollJmhzPvpojPreviewAction::class, 'offices'],
            );
            $g->get(
                '/submissions/jmhz-pvpoj/{revisionId:[0-9]+}',
                PayrollJmhzPvpojPreviewAction::class,
            );
            $g->get(
                '/submissions/jmhz-pvpoj/{revisionId:[0-9]+}/download',
                [PayrollJmhzPvpojPreviewAction::class, 'download'],
            );
            $g->get(
                '/submissions/jmhz-ordinary-evidence/{revisionId:[0-9]+}',
                [PayrollJmhzOrdinaryEvidenceAction::class, 'get'],
            );
            $g->post(
                '/submissions/jmhz-ordinary-evidence/{revisionId:[0-9]+}/{employmentId:[0-9]+}',
                [PayrollJmhzOrdinaryEvidenceAction::class, 'confirm'],
            );
            $g->get(
                '/submissions/jmhz-employer-annual-evidence/{reportYear:[0-9]{4}}',
                [PayrollJmhzEmployerAnnualEvidenceAction::class, 'get'],
            );
            $g->post(
                '/submissions/jmhz-employer-annual-evidence/{reportYear:[0-9]{4}}',
                [PayrollJmhzEmployerAnnualEvidenceAction::class, 'save'],
            );
            $g->post(
                '/submissions/jmhz-preparation/{revisionId:[0-9]+}',
                PayrollJmhzPreparationAction::class,
            );
            $g->get(
                '/submissions/jmhz-xml-dry-run/{preparationId:[0-9]+}',
                PayrollJmhzXmlDryRunAction::class,
            );
            $g->post(
                '/submissions/jmhz-freeze/{preparationId:[0-9]+}',
                PayrollJmhzSubmissionFreezeAction::class,
            );
            // Evidenční list důchodového pojištění. Vlastní zákonná povinnost
            // s vlastní lhůtou; `prepare` končí ve stavu `prepared`, odeslání
            // spouští člověk.
            $g->get(
                '/submissions/eldp',
                [PayrollEldpAction::class, 'get'],
            );
            $g->post(
                '/submissions/eldp',
                [PayrollEldpAction::class, 'prepare'],
            );
            $g->post(
                '/submissions/eldp/{statementId:[0-9]+}/manual-completion',
                [PayrollEldpAction::class, 'complete'],
            );
            // Přihlášení pracovního vztahu u ČSSZ. Cesta nenese kód formuláře:
            // PREZEC vs. REGZEC rozhoduje resolver z faktů, ne volající.
            $g->get(
                '/submissions/registration/{employmentId:[0-9]+}',
                [PayrollRegistrationAction::class, 'preview'],
            );
            $g->post(
                '/submissions/registration/{employmentId:[0-9]+}',
                [PayrollRegistrationAction::class, 'prepare'],
            );
            $g->get(
                '/submissions/registration/{employmentId:[0-9]+}/a1-profile',
                [PayrollRegistrationAction::class, 'a1Profile'],
            );
            $g->put(
                '/submissions/registration/{employmentId:[0-9]+}/a1-profile',
                [PayrollRegistrationAction::class, 'saveA1Profile'],
            );
            $g->get(
                '/submissions/registration/{employmentId:[0-9]+}/events',
                [PayrollRegistrationAction::class, 'events'],
            );
            $g->get(
                '/submissions/registration/{employmentId:[0-9]+}/a2-evidence-candidates',
                [PayrollRegistrationAction::class, 'a2EvidenceCandidates'],
            );
            $g->post(
                '/submissions/registration/{employmentId:[0-9]+}/events',
                [PayrollRegistrationAction::class, 'approveEvent'],
            );
            // Detekce změn hlásitelných do registru pojištěnců. Metoda je POST,
            // ne GET: přepočet zakládá návrhy povinností s běžící osmidenní
            // lhůtou, a to není bezpečná operace, kterou by směl zopakovat
            // prefetch prohlížeče.
            $g->post(
                '/submissions/registration/{employmentId:[0-9]+}/changes',
                [PayrollRegistrationAction::class, 'changeDetection'],
            );
            $g->post(
                '/submissions/registration/{employmentId:[0-9]+}/changes/{proposalId:[0-9]+}/file',
                [PayrollRegistrationAction::class, 'fileChange'],
            );
            $g->post(
                '/submissions/registration/{employmentId:[0-9]+}/changes/{proposalId:[0-9]+}/dismiss',
                [PayrollRegistrationAction::class, 'dismissChange'],
            );
            $g->post(
                '/submissions/registration-transport/{submissionId:[0-9]+}',
                [PayrollRegistrationTransportAction::class, 'send'],
            );
            $g->get(
                '/submissions/registration-transport/{submissionId:[0-9]+}',
                [PayrollRegistrationTransportAction::class, 'status'],
            );
            $g->post(
                '/submissions/registration-transport/{attemptId:[0-9]+}/poll',
                [PayrollRegistrationTransportAction::class, 'poll'],
            );
            $g->post(
                '/submissions/registration-transport/{attemptId:[0-9]+}/close',
                [PayrollRegistrationTransportAction::class, 'close'],
            );
            // Případy dávek nemocenského pojištění (NEMPRI, HZUPN).
            // Dvě podání s vlastními lhůtami podle § 97 zák. č. 187/2006 Sb.
            // Případ žije i bez podání — lhůta podle odst. 2 běží od 15. dne
            // trvání neschopnosti bez ohledu na to, jestli si toho někdo všiml.
            $g->get(
                '/submissions/sickness-cases',
                [PayrollSicknessCaseAction::class, 'list'],
            );
            $g->post(
                '/submissions/sickness-cases',
                [PayrollSicknessCaseAction::class, 'create'],
            );
            $g->put(
                '/submissions/sickness-cases/{caseId:[0-9]+}',
                [PayrollSicknessCaseAction::class, 'update'],
            );
            $g->get(
                '/submissions/sickness-cases/{caseId:[0-9]+}/preview',
                [PayrollSicknessCaseAction::class, 'preview'],
            );
            $g->post(
                '/submissions/sickness-cases/{caseId:[0-9]+}/prepare',
                [PayrollSicknessCaseAction::class, 'prepare'],
            );
            $g->post(
                '/submissions/sickness-cases/{caseId:[0-9]+}/receipt',
                [PayrollSicknessCaseAction::class, 'receipt'],
            );
            // Odeslání datovou schránkou visí na PŘÍPADU, ne na obrazovce
            // „Stav odeslání": ta patří kanálu VREP/APEP, kterým NEMPRI ani
            // HZUPN odeslat nejde. Endpoint jen ZAŘADÍ podání do obecné fronty;
            // dál se pokračuje `/api/submissions/outbox/{id}/…`, aby doručenka
            // a rozhodný den doručení měly jedinou evidenci.
            $g->post(
                '/submissions/sickness-cases/{caseId:[0-9]+}/dispatch',
                [PayrollSicknessCaseAction::class, 'dispatch'],
            );
            // Oznámení záměru uplatňovat slevu na pojistném (OZUSPOJ).
            // Vlastní podání s vlastní lhůtou: sleva podle § 7a bez doručeného
            // záměru nenáleží, i když se v měsíčním hlášení vykáže.
            $g->get(
                '/submissions/discount-intents',
                [PayrollDiscountIntentAction::class, 'list'],
            );
            $g->post(
                '/submissions/discount-intents',
                [PayrollDiscountIntentAction::class, 'create'],
            );
            $g->get(
                '/submissions/discount-intents/{intentId:[0-9]+}/preview',
                [PayrollDiscountIntentAction::class, 'preview'],
            );
            $g->post(
                '/submissions/discount-intents/{intentId:[0-9]+}/prepare',
                [PayrollDiscountIntentAction::class, 'prepare'],
            );
            $g->post(
                '/submissions/discount-intents/{intentId:[0-9]+}/end',
                [PayrollDiscountIntentAction::class, 'end'],
            );
            $g->post(
                '/submissions/discount-intents/{intentId:[0-9]+}/receipt',
                [PayrollDiscountIntentAction::class, 'receipt'],
            );
            $g->get(
                '/submissions/signing-profile',
                [PayrollJmhzSigningProfileAction::class, 'show'],
            );
            $g->put(
                '/submissions/signing-profile',
                [PayrollJmhzSigningProfileAction::class, 'save'],
            );
            $g->delete(
                '/submissions/signing-profile',
                [PayrollJmhzSigningProfileAction::class, 'delete'],
            );
            $g->post(
                '/submissions/{submissionId:[0-9]+}/jmhz-transport',
                [PayrollJmhzTransportAction::class, 'send'],
            );
            $g->get(
                '/submissions/jmhz-transport',
                [PayrollJmhzTransportAction::class, 'history'],
            );
            $g->get(
                '/submissions/jmhz-transport/{attemptId:[0-9]+}',
                [PayrollJmhzTransportAction::class, 'poll'],
            );
            $g->post(
                '/submissions/jmhz-transport/{attemptId:[0-9]+}/close',
                [PayrollJmhzTransportAction::class, 'close'],
            );
            // Datová schránka je druhý rovnocenný kanál JMHZ vedle VREP, ne
            // náhradní cesta — ČSSZ pro JMHZ zřídila vlastní schránku iie254d.
            // `jmhz-isds` jen ZAŘADÍ podání do fronty a vrátí hotovou zprávu;
            // odesílá se dál obecnou cestou `/api/submissions/outbox/{id}/…`,
            // aby doručenka a rozhodný den doručení měly jedinou evidenci.
            $g->get(
                '/submissions/jmhz-isds/recipients',
                [PayrollJmhzIsdsAction::class, 'recipients'],
            );
            $g->get(
                '/submissions/jmhz-isds/match-response',
                [PayrollJmhzIsdsAction::class, 'matchResponse'],
            );
            $g->post(
                '/submissions/{submissionId:[0-9]+}/jmhz-isds',
                [PayrollJmhzIsdsAction::class, 'enqueue'],
            );
            // Storno ruší za období všechno, oprava jen vyjmenované vztahy —
            // rozdíl musí být vidět i v adrese, ne až v těle požadavku.
            $g->post(
                '/submissions/{submissionId:[0-9]+}/jmhz-cancel',
                [PayrollJmhzCorrectionAction::class, 'cancel'],
            );
            $g->get(
                '/submissions/{submissionId:[0-9]+}/jmhz-cancel-components',
                [PayrollJmhzCorrectionAction::class, 'components'],
            );
            $g->post(
                '/submissions/{submissionId:[0-9]+}/jmhz-cancel-components',
                [PayrollJmhzCorrectionAction::class, 'cancelComponents'],
            );
            $g->get(
                '/submissions/{submissionId:[0-9]+}/jmhz-content-correction-preparations',
                [PayrollJmhzCorrectionAction::class, 'contentCorrectionPreparations'],
            );
            $g->get(
                '/submissions/{submissionId:[0-9]+}/jmhz-content-correction',
                [PayrollJmhzCorrectionAction::class, 'contentCorrection'],
            );
            $g->post(
                '/submissions/{submissionId:[0-9]+}/jmhz-content-correction',
                [PayrollJmhzCorrectionAction::class, 'freezeContentCorrection'],
            );
            $g->get(
                '/submissions/jmhz-protocol-import',
                [PayrollJmhzProtocolImportAction::class, 'history'],
            );
            $g->post(
                '/submissions/jmhz-protocol-import',
                [PayrollJmhzProtocolImportAction::class, 'import'],
            );
            $g->get(
                '/submissions/jmhz-protocol-import/{id:[0-9]+}/errors',
                [PayrollJmhzProtocolImportAction::class, 'errors'],
            );
            $g->get(
                '/submissions/{submissionId:[0-9]+}',
                PayrollSubmissionDetailAction::class,
            );
            $g->post(
                '/submissions/{submissionId:[0-9]+}/artifacts/{artifactId:[0-9]+}/download-grant',
                [PayrollSubmissionArtifactDownloadAction::class, 'grant'],
            );
            $g->get(
                '/submissions/{submissionId:[0-9]+}/artifacts/{artifactId:[0-9]+}/download',
                [PayrollSubmissionArtifactDownloadAction::class, 'download'],
            );
            $g->get(
                '/submissions/health-overviews/{revisionId:[0-9]+}',
                [PayrollHealthInsuranceOverviewAction::class, 'index'],
            );
            $g->get(
                '/submissions/health-overviews/{revisionId:[0-9]+}/{insurerCode:[0-9]{3}}/download',
                [PayrollHealthInsuranceOverviewAction::class, 'download'],
            );
            $g->get(
                '/submissions/health-notifications/capability',
                [PayrollHealthNotificationAction::class, 'capability'],
            );
            // Přehled za období musí stát PŘED variantou s ID vztahu —
            // jinak by ji router považoval za neúplnou adresu detailu.
            $g->get(
                '/submissions/health-notifications/duties',
                [PayrollHealthNotificationAction::class, 'periodDuties'],
            );
            $g->post(
                '/submissions/health-notifications/duties/obligations',
                [PayrollHealthNotificationAction::class, 'registerPeriodObligations'],
            );
            $g->get(
                '/submissions/health-notifications/duties/{employmentId:[0-9]+}',
                [PayrollHealthNotificationAction::class, 'duties'],
            );
            $g->post(
                '/submissions/health-notifications/duties/{employmentId:[0-9]+}/obligations',
                [PayrollHealthNotificationAction::class, 'registerObligations'],
            );
            $g->post(
                '/submissions/health-notifications/payment-overview/{revisionId:[0-9]+}/{insurerCode:[0-9]{3}}/prepare',
                [PayrollHealthNotificationAction::class, 'preparePaymentOverview'],
            );
            $g->post(
                '/submissions/health-notifications/bulk/{period:[0-9]{4}-[0-9]{2}}/{insurerCode:[0-9]{3}}/prepare',
                [PayrollHealthNotificationAction::class, 'prepareBulkNotification'],
            );
            $g->get(
                '/submissions/health-notifications/bulk/{period:[0-9]{4}-[0-9]{2}}/{insurerCode:[0-9]{3}}/download',
                [PayrollHealthNotificationAction::class, 'downloadBulkNotification'],
            );
            $g->post(
                '/submissions/{submissionId:[0-9]+}/health-isds/{insurerCode:[0-9]{3}}',
                [PayrollHealthInsuranceIsdsAction::class, 'enqueue'],
            );
            $g->get('/time/month', [PayrollTimeAction::class, 'month']);
            $g->put('/time/calendars/{employmentId:[0-9]+}', [PayrollTimeAction::class, 'calendar']);
            $g->post('/time/shifts', [PayrollTimeAction::class, 'shift']);
            $g->post('/time/entries', [PayrollTimeAction::class, 'entry']);
            $g->post('/time/entries/batch', [PayrollTimeAction::class, 'entryBatch']);
            $g->post('/time/overtime-consents', [PayrollTimeAction::class, 'overtimeConsent']);
            $g->post('/time/overtime-protections', [PayrollTimeAction::class, 'overtimeProtection']);
            $g->post(
                '/time/overtime-compensations',
                [PayrollTimeAction::class, 'overtimeCompensation'],
            );
            $g->get(
                '/time/overtime-averaging-periods',
                [PayrollTimeAction::class, 'overtimeAveragingPeriods'],
            );
            $g->post(
                '/time/overtime-averaging-periods',
                [PayrollTimeAction::class, 'overtimeAveragingPeriod'],
            );
            $g->post('/time/imports/preview', [PayrollTimeAction::class, 'previewImport']);
            $g->post('/time/imports', [PayrollTimeAction::class, 'import']);
            $g->post('/time/months/{period:[0-9]{4}-[0-9]{2}}/approve', [PayrollTimeAction::class, 'approve']);
            $g->post('/time/months/{period:[0-9]{4}-[0-9]{2}}/reopen', [PayrollTimeAction::class, 'reopen']);
            $g->get('/settings/activation', [PayrollActivationAction::class, 'get']);
            $g->put('/settings/activation', [PayrollActivationAction::class, 'put']);
            $g->get('/settings/account-options', PayrollAccountOptionsAction::class);
            $g->get('/settings/employer', [PayrollEmployerSettingsAction::class, 'get']);
            $g->put('/settings/employer', [PayrollEmployerSettingsAction::class, 'put']);
            $g->get('/settings/offices/{officeId:[0-9]+}/registrations', [PayrollOfficeRegistrationAction::class, 'list']);
            $g->post('/settings/offices/{officeId:[0-9]+}/registrations', [PayrollOfficeRegistrationAction::class, 'create']);
            $g->get('/settings/accident-insurance-rates', [PayrollAccidentInsuranceRateAction::class, 'list']);
            $g->post('/settings/accident-insurance-rates', [PayrollAccidentInsuranceRateAction::class, 'create']);
            $g->get('/settings/accident-insurance-rate-schedule', [PayrollAccidentInsuranceRateAction::class, 'schedule']);
            $g->get('/settings/policies', [PayrollEmployerPolicyAction::class, 'list']);
            $g->post('/settings/policies', [PayrollEmployerPolicyAction::class, 'create']);
            $g->get('/settings/policies/{id:[0-9]+}', [PayrollEmployerPolicyAction::class, 'detail']);
            $g->put('/settings/policies/{id:[0-9]+}', [PayrollEmployerPolicyAction::class, 'update']);
            // Smazat jde jen verze, podle které se ještě nic nespočítalo.
            $g->delete('/settings/policies/{id:[0-9]+}', [PayrollEmployerPolicyAction::class, 'delete']);
            $g->get('/setup-check', [PayrollEmployerPolicyAction::class, 'setupCheck']);
            $g->get('/settings/institution-accounts', [PayrollInstitutionAccountsAction::class, 'list']);
            $g->post('/settings/institution-accounts', [PayrollInstitutionAccountsAction::class, 'create']);
            $g->get('/settings/institution-accounts/{id:[0-9]+}', [PayrollInstitutionAccountsAction::class, 'detail']);
            $g->put('/settings/institution-accounts/{id:[0-9]+}', [PayrollInstitutionAccountsAction::class, 'update']);
            // Duplicitní nebo omylem založený účet, ze kterého se nikdy neplatilo.
            $g->delete('/settings/institution-accounts/{id:[0-9]+}', [PayrollInstitutionAccountsAction::class, 'delete']);
            $g->get('/settings/dimensions', [PayrollDimensionAction::class, 'list']);
            $g->post('/settings/dimensions', [PayrollDimensionAction::class, 'create']);
            $g->get('/settings/dimensions/{id:[0-9]+}', [PayrollDimensionAction::class, 'detail']);
            $g->put('/settings/dimensions/{id:[0-9]+}', [PayrollDimensionAction::class, 'update']);
            $g->delete('/settings/dimensions/{id:[0-9]+}', [PayrollDimensionAction::class, 'delete']);
            // Rozcestník karty zaměstnance — kolik toho na vztahu visí v navazujících
            // agendách. Agendy si akce filtruje podle oprávnění volajícího sama.
            $g->get(
                '/employments/{id:[0-9]+}/agenda-summary',
                [PayrollEmploymentAgendaSummaryAction::class, 'show'],
            );
            // Sjednané zásady zákonných příplatků § 114 až § 118 ZP. Bez nich
            // nešel schválit měsíc s prací o svátku ani ve ztíženém prostředí:
            // materializace příplatků je fail-closed a sjednat se to dosud
            // nedalo nikde.
            $g->get(
                '/employments/{id:[0-9]+}/surcharge-policies',
                [PayrollEmploymentSurchargePolicyAction::class, 'list'],
            );
            $g->post(
                '/employments/{id:[0-9]+}/surcharge-policies',
                [PayrollEmploymentSurchargePolicyAction::class, 'create'],
            );
            // Oprava překlepu v OTEVŘENÉ verzi a ukončení její platnosti.
            // Uzavřenou ani překrytou verzi tyhle routy nepustí — mzdy spočítané
            // podle ní na ni dál ukazují.
            $g->put(
                '/employments/{id:[0-9]+}/surcharge-policies/{policyId:[0-9]+}',
                [PayrollEmploymentSurchargePolicyAction::class, 'update'],
            );
            $g->post(
                '/employments/{id:[0-9]+}/surcharge-policies/{policyId:[0-9]+}/close',
                [PayrollEmploymentSurchargePolicyAction::class, 'close'],
            );
            $g->get('/employments/{id:[0-9]+}/dimensions', [PayrollEmploymentDimensionAction::class, 'list']);
            $g->post('/employments/{id:[0-9]+}/dimensions', [PayrollEmploymentDimensionAction::class, 'create']);
            $g->put(
                '/employments/{id:[0-9]+}/dimensions/{assignmentId:[0-9]+}',
                [PayrollEmploymentDimensionAction::class, 'update'],
            );
            $g->get('/time/context', [PayrollAbsenceAction::class, 'context']);
            $g->get('/time/absences', [PayrollAbsenceAction::class, 'list']);
            $g->post('/time/absences', [PayrollAbsenceAction::class, 'create']);
            $g->post('/time/absences/{id:[0-9]+}/decision', [PayrollAbsenceAction::class, 'decision']);
            $g->post('/time/absences/{id:[0-9]+}/cancel', [PayrollAbsenceAction::class, 'cancel']);
            $g->get('/time/averages', [PayrollAbsenceAction::class, 'averages']);
            $g->post('/time/averages', [PayrollAbsenceAction::class, 'createAverage']);
            $g->post('/time/averages/{id:[0-9]+}/approve', [PayrollAbsenceAction::class, 'approveAverage']);
            $g->delete('/time/averages/{id:[0-9]+}', [PayrollAbsenceAction::class, 'deleteAverage']);
            $g->get('/time/leave-ledger', [PayrollAbsenceAction::class, 'leaveLedger']);
            $g->post('/time/leave-ledger', [PayrollAbsenceAction::class, 'createLeaveEntry']);
            $g->delete('/time/leave-ledger/{id:[0-9]+}', [PayrollAbsenceAction::class, 'deleteLeaveEntry']);
            $g->post('/time/leave-entitlements', [PayrollAbsenceAction::class, 'createEntitlement']);
            $g->get(
                '/time/leave-entitlement-candidates',
                [PayrollAbsenceAction::class, 'leaveEntitlementCandidates'],
            );
            $g->post(
                '/time/leave-entitlements/bulk',
                [PayrollAbsenceAction::class, 'createAutomaticEntitlements'],
            );
            $g->delete('/time/leave-entitlements/{id:[0-9]+}', [PayrollAbsenceAction::class, 'deleteEntitlement']);

            // Retence osobních údajů, zadržení výmazu a výmaz jako NÁVRH ke schválení.
            // Konkrétní cesty jdou před `{id}` (jinak by `erasure` spadlo do id).
            // `execute` je samostatný požadavek po `approve` — výmaz se nedá odklepnout
            // jedním kliknutím a bez schválení se neprovede vůbec.
            $g->get('/retention', [PayrollRetentionAction::class, 'overview']);
            $g->get('/retention/assessment', [PayrollRetentionAction::class, 'assessment']);
            $g->get('/retention/holds', [PayrollRetentionAction::class, 'listHolds']);
            $g->post('/retention/holds', [PayrollRetentionAction::class, 'placeHold']);
            $g->delete('/retention/holds/{id:[0-9]+}', [PayrollRetentionAction::class, 'releaseHold']);
            $g->get('/retention/erasure', [PayrollRetentionAction::class, 'listProposals']);
            $g->post('/retention/erasure', [PayrollRetentionAction::class, 'createProposal']);
            $g->get('/retention/erasure/{id:[0-9]+}', [PayrollRetentionAction::class, 'showProposal']);
            $g->post('/retention/erasure/{id:[0-9]+}/approve', [PayrollRetentionAction::class, 'approveProposal']);
            $g->post('/retention/erasure/{id:[0-9]+}/reject', [PayrollRetentionAction::class, 'rejectProposal']);
            $g->post('/retention/erasure/{id:[0-9]+}/execute', [PayrollRetentionAction::class, 'executeProposal']);
            // Odvolání schválení během odkladné lhůty (W30 / C-07).
            $g->post('/retention/erasure/{id:[0-9]+}/revoke', [PayrollRetentionAction::class, 'revokeProposal']);
            $g->put('/retention/policies/{category:[a-z_]+}', [PayrollRetentionAction::class, 'putPolicy']);
            $g->delete('/retention/policies/{category:[a-z_]+}', [PayrollRetentionAction::class, 'deletePolicy']);
        });

        // Podvojné účetnictví (Epic F1) — účtová osnova, období, deník, kontace.
        // Vše tenant-scoped (ATTR_CURRENT_ID). Zápisy = účetní|admin, GET = readonly+;
        // změna stavu období = admin (PermissionMiddleware + guard v Action).
        $app->group('/api/accounting', function ($g) {
            // Účtová osnova
            $g->get   ('/accounts',             [ChartOfAccountsAction::class, 'list']);
            $g->post  ('/accounts',             [ChartOfAccountsAction::class, 'create']);
            // Karta účtu (drill-through osnova → účet → analytiky) — až ZA /accounts,
            // ale před ním nic kolizního není (segment je čistě numerický).
            $g->get   ('/accounts/{id:[0-9]+}', [ChartOfAccountsAction::class, 'detail']);
            $g->patch ('/accounts/{id:[0-9]+}', [ChartOfAccountsAction::class, 'update']);
            $g->delete('/accounts/{id:[0-9]+}', [ChartOfAccountsAction::class, 'delete']);
            // Účetní období
            $g->get   ('/periods',                    [AccountingPeriodAction::class, 'list']);
            $g->post  ('/periods',                    [AccountingPeriodAction::class, 'create']);
            $g->post  ('/periods/{id:[0-9]+}/status', [AccountingPeriodAction::class, 'status']);
            // Deník — specifické cesty PŘED generickým /journal/{id}
            $g->get   ('/journal',                            [JournalAction::class, 'list']);
            $g->post  ('/journal',                            [JournalAction::class, 'create']);
            $g->post  ('/journal/post-invoice/{id:[0-9]+}',   [JournalAction::class, 'postInvoice']);
            $g->post  ('/journal/post-purchase/{id:[0-9]+}',  [JournalAction::class, 'postPurchase']);
            // Náhled kontace před zaúčtováním — tatáž cesta jako post, jen bez zápisu.
            // `source` je `invoices` nebo `purchase-invoices`.
            $g->get   ('/journal/posting-preview/{source:invoices|purchase-invoices}/{id:[0-9]+}',
                [JournalAction::class, 'postingPreview']);
            // Hromadné zaúčtování z výběru v seznamu (A2) — tělo { ids: [...] }.
            $g->post  ('/journal/post-invoices-bulk',         [JournalAction::class, 'postInvoicesBulk']);
            $g->post  ('/journal/post-purchases-bulk',        [JournalAction::class, 'postPurchasesBulk']);
            $g->post  ('/journal/{id:[0-9]+}/reverse',        [JournalAction::class, 'reverse']);
            $g->delete('/journal/{id:[0-9]+}',                [JournalAction::class, 'delete']);
            // §35 popis + §33a přílohy — KONKRÉTNÍ cesty PŘED generickým /journal/{id}
            $g->patch ('/journal/{id:[0-9]+}/description',                          [JournalAction::class, 'updateDescription']);
            $g->get   ('/journal/{id:[0-9]+}/attachments',                          ListJournalAttachmentsAction::class);
            $g->post  ('/journal/{id:[0-9]+}/attachments',                          UploadJournalAttachmentAction::class);
            $g->get   ('/journal/{id:[0-9]+}/attachments/{attId:[0-9]+}',           DownloadJournalAttachmentAction::class);
            $g->patch ('/journal/{id:[0-9]+}/attachments/{attId:[0-9]+}/description', PatchJournalAttachmentDescriptionAction::class);
            $g->delete('/journal/{id:[0-9]+}/attachments/{attId:[0-9]+}',           DeleteJournalAttachmentAction::class);
            // Poznámky k zápisu (1:N) — KONKRÉTNÍ cesty PŘED generickým /journal/{id},
            // jinak by {id} pohltilo i segment 'notes'. Práva řeší RoutePermissionMap
            // fallbackem: GET → 'accounting' READ, zápisy → 'accounting.journal.write' WRITE.
            $g->get   ('/journal/{id:[0-9]+}/notes',                      ListJournalNotesAction::class);
            $g->post  ('/journal/{id:[0-9]+}/notes',                      CreateJournalNoteAction::class);
            $g->patch ('/journal/{id:[0-9]+}/notes/{noteId:[0-9]+}',      PatchJournalNoteAction::class);
            $g->delete('/journal/{id:[0-9]+}/notes/{noteId:[0-9]+}',      DeleteJournalNoteAction::class);
            // Náhled zdrojového dokladu pro drawer — KONKRÉTNÍ cesta PŘED generickým /journal/{id}.
            $g->get   ('/journal/{id:[0-9]+}/source',         JournalSourceAction::class);
            // Zaúčtování prvotního dokladu pro sekci „Zaúčtování" na detailu faktury —
            // KONKRÉTNÍ cesta PŘED generickým /journal/{id} (segment 'for-document' není číselný).
            $g->get   ('/journal/for-document/{source:invoices|purchase-invoices}/{id:[0-9]+}',
                                                             JournalForDocumentAction::class);
            // Protějšky zápisu (doklad ↔ úhrada) — KONKRÉTNÍ cesta PŘED generickým /journal/{id}.
            $g->get   ('/journal/{id:[0-9]+}/related',        JournalRelatedAction::class);
            // Měkká vazba zápisu na doklad (migrace 1514) — KONKRÉTNÍ cesty PŘED generickým /journal/{id}.
            // `link-candidates` je našeptávač dokladů; nekoliduje s /journal/{id}, který je číselný.
            $g->get   ('/journal/link-candidates',                        [JournalDocumentLinkAction::class, 'candidates']);
            $g->get   ('/journal/{id:[0-9]+}/links',                      [JournalDocumentLinkAction::class, 'list']);
            $g->post  ('/journal/{id:[0-9]+}/links',                      [JournalDocumentLinkAction::class, 'create']);
            $g->delete('/journal/{id:[0-9]+}/links/{linkId:[0-9]+}',      [JournalDocumentLinkAction::class, 'delete']);
            // SYSTEM VERSIONING auditní historie (audit 2026-07) — KONKRÉTNÍ cesta PŘED generickým /journal/{id}.
            $g->get   ('/journal/{id:[0-9]+}/history',        [JournalAction::class, 'history']);
            $g->get   ('/journal/{id:[0-9]+}',                [JournalAction::class, 'get']);
            // Šablony ručních zápisů (Fáze F, mzdový můstek) — KONKRÉTNÍ cesty PŘED generickým /journal-templates/{id}.
            $g->get   ('/journal-templates',                        [JournalTemplateAction::class, 'list']);
            $g->post  ('/journal-templates',                        [JournalTemplateAction::class, 'create']);
            $g->post  ('/journal-templates/{id:[0-9]+}/import-csv', [JournalTemplateAction::class, 'importCsv']);
            $g->get   ('/journal-templates/{id:[0-9]+}',            [JournalTemplateAction::class, 'get']);
            $g->put   ('/journal-templates/{id:[0-9]+}',            [JournalTemplateAction::class, 'update']);
            $g->delete('/journal-templates/{id:[0-9]+}',            [JournalTemplateAction::class, 'delete']);
            // Mzdová rekapitulace (Fáze F) — rozpad hrubé mzdy počítá PayrollCalculator,
            // účtuje se přes PostingService s source_id = RRRRMM (idempotentní na měsíc).
            $g->post  ('/payroll/preview',                          [PayrollAction::class, 'preview']);
            $g->post  ('/payroll/post',                             [PayrollAction::class, 'post']);
            // Zaměstnanci pro mzdový list (§38j) — identifikace + prohlášení poplatníka.
            $g->get   ('/payroll/employees',                        [PayrollEmployeeAction::class, 'list']);
            $g->post  ('/payroll/employees',                        [PayrollEmployeeAction::class, 'create']);
            $g->put   ('/payroll/employees/{id:[0-9]+}',            [PayrollEmployeeAction::class, 'update']);
            $g->delete('/payroll/employees/{id:[0-9]+}',            [PayrollEmployeeAction::class, 'delete']);
            // Firemní číselník středisek pro řádky deníku a šablon.
            $g->get   ('/cost-centers',                 [CostCenterAction::class, 'list']);
            $g->post  ('/cost-centers',                 [CostCenterAction::class, 'create']);
            $g->patch ('/cost-centers/{id:[0-9]+}',     [CostCenterAction::class, 'update']);
            $g->delete('/cost-centers/{id:[0-9]+}',     [CostCenterAction::class, 'delete']);
            // Kontační pravidla
            $g->get   ('/posting-rules',                            [PostingRuleAction::class, 'list']);
            $g->put   ('/posting-rules/{rule_key:[A-Za-z0-9._-]+}', [PostingRuleAction::class, 'put']);
            // Pravidla klasifikace druhu výdaje (§DM) — předvyplňují expense_kind na řádku
            // přijaté faktury. Návrh je READ-ONLY: nic neúčtuje, uživatel ho potvrdí v editoru.
            $g->get   ('/expense-rules',                            [\MyInvoice\Action\Accounting\ExpenseClassificationRuleAction::class, 'list']);
            $g->post  ('/expense-rules',                            [\MyInvoice\Action\Accounting\ExpenseClassificationRuleAction::class, 'create']);
            $g->put   ('/expense-rules/{id:[0-9]+}',                [\MyInvoice\Action\Accounting\ExpenseClassificationRuleAction::class, 'update']);
            $g->delete('/expense-rules/{id:[0-9]+}',                [\MyInvoice\Action\Accounting\ExpenseClassificationRuleAction::class, 'delete']);
            $g->get   ('/purchase-invoices/{id:[0-9]+}/expense-suggestions', [\MyInvoice\Action\Accounting\ExpenseClassificationRuleAction::class, 'suggestions']);
            // Karty evidence drobného majetku (§DM krok 3) — §28/5 ZoÚ a ČÚS 013 chtějí
            // evidenci majetku účtovaného rovnou do spotřeby. Karta NIC neúčtuje; náklad
            // na 501 vzniká už zaúčtováním dokladu podle expense_kind (1092).
            // KONKRÉTNÍ cesty PŘED generickým /small-assets/{id}.
            $g->get   ('/small-assets',                             [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'list']);
            $g->post  ('/small-assets',                             [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'create']);
            $g->post  ('/small-assets/{id:[0-9]+}/dispose',         [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'dispose']);
            $g->post  ('/small-assets/{id:[0-9]+}/sell',            [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'sell']);
            $g->post  ('/small-assets/{id:[0-9]+}/restore',         [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'restore']);
            $g->get   ('/small-assets/{id:[0-9]+}',                 [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'get']);
            $g->put   ('/small-assets/{id:[0-9]+}',                 [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'update']);
            $g->delete('/small-assets/{id:[0-9]+}',                 [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'delete']);
            $g->post  ('/purchase-invoices/{id:[0-9]+}/small-assets', [\MyInvoice\Action\Accounting\SmallAssetAction::class, 'generateFromPurchaseInvoice']);
            // Sestavy (Epic F2) — hlavní kniha, předvaha, opis účtu, výkazy, kategorie ÚJ.
            // GET = readonly+; PUT reporting-settings = účetní|admin (PermissionMiddleware + guard v Action).
            $g->get('/reports/general-ledger',                    [GeneralLedgerAction::class, 'get']);
            $g->get('/reports/general-ledger/export',             [GeneralLedgerAction::class, 'export']);
            $g->get('/reports/trial-balance',                     [TrialBalanceAction::class, 'get']);
            $g->get('/reports/trial-balance/export',              [TrialBalanceAction::class, 'export']);
            $g->get('/reports/account-statement/{accountId:[0-9]+}',        [AccountStatementAction::class, 'get']);
            $g->get('/reports/account-statement/{accountId:[0-9]+}/export', [AccountStatementAction::class, 'export']);
            $g->get('/reports/balance-sheet',                     [FinancialStatementAction::class, 'balanceSheet']);
            $g->get('/reports/balance-sheet/export',              [FinancialStatementAction::class, 'exportBalanceSheet']);
            $g->get('/reports/income-statement',                  [FinancialStatementAction::class, 'incomeStatement']);
            $g->get('/reports/income-statement/export',           [FinancialStatementAction::class, 'exportIncomeStatement']);
            // VZZ v účelovém členění (vyhl. 500/2002 Sb., př. 2 část II, § 39b) — jiný výkaz
            // s jinými řádky, proto vlastní adresa, ne `?variant=` nad druhovým.
            $g->get('/reports/income-statement-by-function',        [FinancialStatementAction::class, 'incomeStatementByFunction']);
            $g->get('/reports/income-statement-by-function/export', [FinancialStatementAction::class, 'exportIncomeStatementByFunction']);
            $g->get('/reports/statement-function-map',              [FinancialStatementAction::class, 'functionMap']);
            $g->put('/reports/statement-function-map',              [FinancialStatementAction::class, 'setFunctionMapping']);
            $g->get('/reports/saldo',                             [SaldoAction::class, 'get']);
            $g->get('/reports/saldo/export',                      [SaldoAction::class, 'export']);
            // Kontrola úplnosti dokladů proti bance (REAL_data_followup_UX.md E) — read-only
            // report: bankovní pohyby bez dokladu po prahu X dní (§24/1) + doklady po splatnosti.
            $g->get('/reports/document-completeness',             [\MyInvoice\Action\Accounting\Reports\DocumentCompletenessAction::class, 'get']);
            // Inventarizace rozvahových účtů (§29–30 ZoÚ, T2) — soupis účtů tříd 0–4 s KZ
            // k rozvahovému dni + doložení + prostor pro podpis (REAL_data_followup_UX.md T2).
            $g->get('/reports/balance-inventory',                 [BalanceInventoryAction::class, 'get']);
            $g->get('/reports/balance-inventory/export',          [BalanceInventoryAction::class, 'export']);
            // § 18 odst. 2 ZoÚ — přehled o peněžních tocích a o změnách vlastního kapitálu.
            // U velké a střední ÚJ (a u každé s povinným auditem) jsou povinnou součástí
            // závěrky stejně jako rozvaha a výsledovka.
            $g->get('/reports/section18-statements',
                [\MyInvoice\Action\Accounting\Reports\Section18StatementsAction::class, 'get']);
            $g->get('/reports/section18-statements/export',
                [\MyInvoice\Action\Accounting\Reports\Section18StatementsAction::class, 'export']);
            // Sestavy drobného majetku (§DM) — soupis k datu je podklad účetní
            // k inventarizaci (§28/5 ZoÚ), rozpis 501 sedí na hlavní knihu (501.100/501.200).
            $g->get('/reports/small-assets/inventory',                  [SmallAssetReportAction::class, 'inventory']);
            $g->get('/reports/small-assets/inventory/export',           [SmallAssetReportAction::class, 'exportInventory']);
            $g->get('/reports/small-assets/movements',                  [SmallAssetReportAction::class, 'movements']);
            $g->get('/reports/small-assets/movements/export',           [SmallAssetReportAction::class, 'exportMovements']);
            $g->get('/reports/small-assets/expense-breakdown',          [SmallAssetReportAction::class, 'expenseBreakdown']);
            $g->get('/reports/small-assets/expense-breakdown/export',   [SmallAssetReportAction::class, 'exportExpenseBreakdown']);
            // Měsíční přehled klientovi (Fáze F, audit 2026-07, P3) — PDF balíček nad
            // existujícími sestavami + odeslání e-mailem/archivace do DMS.
            $g->get ('/reports/monthly-report/preview',           [MonthlyReportAction::class, 'preview']);
            $g->get ('/reports/monthly-report/download',          [MonthlyReportAction::class, 'download']);
            $g->post('/reports/monthly-report/send',              [MonthlyReportAction::class, 'send']);
            $g->get ('/reports/monthly-report/history',           [MonthlyReportAction::class, 'history']);
            // Export deníku (audit 2026-07) — filtry shodné s GET /journal, viz JournalAction::export.
            $g->get('/reports/journal/export',                    [JournalAction::class, 'export']);
            // Mzdový list (§38j ZDP) — roční evidence za zaměstnance z payroll_monthly_records (1105).
            $g->get('/reports/payroll-sheet',                     [PayrollSheetAction::class, 'export']);
            $g->get('/reports/entity-category',                   [EntityCategoryAction::class, 'get']);
            $g->get('/reporting-settings',                        [ReportingSettingsAction::class, 'get']);
            $g->put('/reporting-settings',                        [ReportingSettingsAction::class, 'update']);
            // Příloha k účetní závěrce (§ 18/1/c ZoÚ, § 39/39a/39b vyhl. 500/2002) —
            // rozsah sekcí se stupňuje podle kategorie účetní jednotky a povinného auditu.
            $g->get('/periods/{id:[0-9]+}/statement-notes',
                [\MyInvoice\Action\Accounting\Reports\StatementNotesAction::class, 'get']);
            $g->put('/periods/{id:[0-9]+}/statement-notes/{section:[a-z0-9_]+}',
                [\MyInvoice\Action\Accounting\Reports\StatementNotesAction::class, 'save']);
            $g->post('/periods/{id:[0-9]+}/statement-notes/carry-over',
                [\MyInvoice\Action\Accounting\Reports\StatementNotesAction::class, 'carryOver']);
            // Interní doklad zúčtování DPH (migrace 1332). Primární spouštěč je PODÁNÍ
            // přiznání (VatClearingTrigger); tohle je ruční cesta z agendy DPH s náhledem
            // před zápisem. GET = náhled (accounting READ), POST = zaúčtování (journal.post).
            $g->get ('/vat-clearing',                             [\MyInvoice\Action\Accounting\VatClearingAction::class, 'preview']);
            $g->post('/vat-clearing',                             [\MyInvoice\Action\Accounting\VatClearingAction::class, 'run']);
            // Měkký zámek účtování k datu (B8). GET = readonly+; PUT = admin-only
            // (není v route permission rules → PermissionMiddleware fallback admin; Action self-check requireAdmin).
            $g->get('/period-lock',                               [PeriodLockAction::class, 'get']);
            $g->put('/period-lock',                               [PeriodLockAction::class, 'update']);
            // Kurzový režim firmy (§24/7 ZoÚ — pevný kurz). GET readonly+, zápisy účetní|admin.
            $g->get   ('/fx-rate-settings',                       [\MyInvoice\Action\Accounting\FxRateSettingsAction::class, 'get']);
            $g->put   ('/fx-rate-settings',                       [\MyInvoice\Action\Accounting\FxRateSettingsAction::class, 'updateMode']);
            $g->get   ('/fx-rate-settings/cnb-prefill',           [\MyInvoice\Action\Accounting\FxRateSettingsAction::class, 'cnbPrefill']);
            $g->put   ('/fx-rate-settings/rates',                 [\MyInvoice\Action\Accounting\FxRateSettingsAction::class, 'upsertRate']);
            $g->delete('/fx-rate-settings/rates/{id:[0-9]+}',     [\MyInvoice\Action\Accounting\FxRateSettingsAction::class, 'deleteRate']);
            // Repo sazba ČNB (číselník pro úrok z prodlení, NV 351/2013). GET readonly+, zápisy účetní|admin.
            $g->get   ('/repo-rates',                             [\MyInvoice\Action\Accounting\CnbRepoRateAction::class, 'get']);
            $g->put   ('/repo-rates',                             [\MyInvoice\Action\Accounting\CnbRepoRateAction::class, 'upsert']);
            $g->delete('/repo-rates/{date:\d{4}-\d{2}-\d{2}}',    [\MyInvoice\Action\Accounting\CnbRepoRateAction::class, 'delete']);
            // Vzájemné zápočty (Fáze F). Specifické cesty PŘED generickým /offsets/{id}.
            $g->get   ('/offsets',                                [\MyInvoice\Action\Accounting\OffsetAction::class, 'list']);
            $g->post  ('/offsets',                                [\MyInvoice\Action\Accounting\OffsetAction::class, 'create']);
            $g->get   ('/offsets/partners',                       [\MyInvoice\Action\Accounting\OffsetAction::class, 'partners']);
            $g->get   ('/offsets/open',                           [\MyInvoice\Action\Accounting\OffsetAction::class, 'open']);
            $g->get   ('/offsets/{id:[0-9]+}',                    [\MyInvoice\Action\Accounting\OffsetAction::class, 'get']);
            $g->post  ('/offsets/{id:[0-9]+}/confirm',            [\MyInvoice\Action\Accounting\OffsetAction::class, 'confirm']);
            $g->post  ('/offsets/{id:[0-9]+}/cancel',             [\MyInvoice\Action\Accounting\OffsetAction::class, 'cancel']);
            $g->get   ('/offsets/{id:[0-9]+}/pdf',                [\MyInvoice\Action\Accounting\OffsetAction::class, 'pdf']);
            // Úhrada faktury zápočtem proti zvolenému účtu (355/365) — mini-epic „úhrada jinak".
            $g->get   ('/settlements',                            [\MyInvoice\Action\Accounting\InvoiceSettlementAction::class, 'list']);
            $g->post  ('/settlements',                            [\MyInvoice\Action\Accounting\InvoiceSettlementAction::class, 'create']);
            $g->post  ('/settlements/{id:[0-9]+}/cancel',         [\MyInvoice\Action\Accounting\InvoiceSettlementAction::class, 'cancel']);
            // Doúčtování zápočtu bez zápisu (daňová evidence, hromadné přeúčtování deníku).
            $g->post  ('/settlements/{id:[0-9]+}/post',           [\MyInvoice\Action\Accounting\InvoiceSettlementAction::class, 'post']);
            // Majetek a odpisy (Epic F3). Specifické cesty PŘED generickým /assets/{id}.
            $g->get   ('/assets',                                   [AssetAction::class, 'list']);
            $g->post  ('/assets',                                   [AssetAction::class, 'create']);
            $g->get   ('/assets/purchase-candidates',               [AssetAction::class, 'purchaseCandidates']);
            $g->post  ('/assets/depreciations/book',                [DepreciationAction::class, 'bookYear']);
            $g->get   ('/assets/{id:[0-9]+}',                       [AssetAction::class, 'get']);
            $g->put   ('/assets/{id:[0-9]+}',                       [AssetAction::class, 'update']);
            $g->delete('/assets/{id:[0-9]+}',                       [AssetAction::class, 'delete']);
            $g->get   ('/assets/{id:[0-9]+}/depreciation-plan',     [DepreciationAction::class, 'plan']);
            $g->get   ('/assets/{id:[0-9]+}/depreciation-card',     [AssetAction::class, 'depreciationCard']);
            $g->post  ('/assets/{id:[0-9]+}/put-into-use',          [AssetLifecycleAction::class, 'putIntoUse']);
            $g->post  ('/assets/{id:[0-9]+}/improvements',          [AssetLifecycleAction::class, 'addImprovement']);
            $g->delete('/assets/{id:[0-9]+}/improvements/{impId:[0-9]+}', [AssetLifecycleAction::class, 'deleteImprovement']);
            $g->post  ('/assets/{id:[0-9]+}/dispose',               [AssetLifecycleAction::class, 'dispose']);
            $g->post  ('/assets/{id:[0-9]+}/dispose/revert',        [AssetLifecycleAction::class, 'revertDisposal']);
            $g->post  ('/assets/{id:[0-9]+}/depreciation/pause',    [DepreciationAction::class, 'pause']);
            $g->delete('/assets/{id:[0-9]+}/depreciation/pause/{year:[0-9]+}', [DepreciationAction::class, 'unpause']);
            // Uzávěrka období (Epic F4). Segmenty se s /periods/{id}/status nekryjí;
            // celá rodina closing/close/open-next/revert běží na právu accounting.periods.close
            // (RoutePermissionMap + Action requireClose), /status na accounting.periods.manage.
            $g->get   ('/periods/{id:[0-9]+}/closing',                          [ClosingAction::class, 'state']);
            $g->get   ('/periods/{id:[0-9]+}/monthly-check',                    [ClosingAction::class, 'monthlyCheck']);
            // CSV export nálezů jedné kontroly — BEZ stropu (náhled je capnutý na 50).
            // Sdílí ho uzávěrkový průvodce i měsíční kontrola, protože obě stavějí
            // tytéž kontroly (buildChecks).
            $g->get   ('/periods/{id:[0-9]+}/checks/{key:[a-z0-9_]+}/export',   [ClosingAction::class, 'exportCheckFindings']);
            $g->get   ('/periods/{id:[0-9]+}/checks/{key:[a-z0-9_]+}',          [ClosingAction::class, 'checkFindings']);
            $g->get   ('/periods/{id:[0-9]+}/finding-remedy',                   [ClosingAction::class, 'findingRemedy']);
            $g->post  ('/periods/{id:[0-9]+}/closing/start',                    [ClosingAction::class, 'start']);
            $g->post  ('/periods/{id:[0-9]+}/closing/abort',                    [ClosingAction::class, 'abort']);
            $g->get   ('/periods/{id:[0-9]+}/closing/fx-preview',               [ClosingAction::class, 'fxPreview']);
            $g->get   ('/periods/{id:[0-9]+}/closing/provisions-preview',       [ClosingAction::class, 'provisionsPreview']);
            $g->get   ('/periods/{id:[0-9]+}/closing/estimates-suggest',        [ClosingAction::class, 'estimatesSuggest']);
            $g->get   ('/periods/{id:[0-9]+}/closing/income-tax-preview',       [ClosingAction::class, 'incomeTaxPreview']);
            // ČÚS 003 — odložená daň (přechodné rozdíly, 592/481).
            $g->get   ('/periods/{id:[0-9]+}/closing/deferred-tax-preview',     [ClosingAction::class, 'deferredTaxPreview']);
            $g->get   ('/periods/{id:[0-9]+}/closing/small-asset-accrual-preview', [ClosingAction::class, 'smallAssetAccrualPreview']);
            $g->get   ('/periods/{id:[0-9]+}/closing/prepaid-expense-accrual-preview', [ClosingAction::class, 'prepaidExpenseAccrualPreview']);
            $g->post  ('/periods/{id:[0-9]+}/closing/book-depreciation',        [ClosingAction::class, 'bookDepreciation']);
            // EP-6: povinná inventarizace rozvahových účtů (§29–30 ZoÚ) — náhled + uložení
            // skutečného stavu / rozdílů / odpovědné osoby / protokolu (blokuje uzavření knih).
            $g->get   ('/periods/{id:[0-9]+}/closing/inventory',                [ClosingAction::class, 'inventory']);
            $g->post  ('/periods/{id:[0-9]+}/closing/inventory',                [ClosingAction::class, 'saveInventory']);
            $g->post  ('/periods/{id:[0-9]+}/closing/steps/{step:[a-z_]+}/run', [ClosingAction::class, 'runStep']);
            $g->post  ('/periods/{id:[0-9]+}/closing/steps/{step:[a-z_]+}/revert', [ClosingAction::class, 'revertStep']);
            $g->post  ('/periods/{id:[0-9]+}/closing/entries',                  [ClosingAction::class, 'createEntry']);
            $g->post  ('/periods/{id:[0-9]+}/closing/entries/{entryId:[0-9]+}/reverse', [ClosingAction::class, 'reverseEntry']);
            $g->post  ('/periods/{id:[0-9]+}/close',                            [ClosingAction::class, 'close']);
            $g->post  ('/periods/{id:[0-9]+}/open-next',                        [ClosingAction::class, 'openNext']);
            $g->get   ('/periods/{id:[0-9]+}/profit-distribution/preview',      [ClosingAction::class, 'profitDistributionPreview']);
            $g->post  ('/periods/{id:[0-9]+}/profit-distribution/revert',       [ClosingAction::class, 'profitDistributionRevert']);
            $g->post  ('/periods/{id:[0-9]+}/profit-distribution',              [ClosingAction::class, 'profitDistribution']);
            $g->post  ('/journal/transfer',                                     [JournalTransferAction::class, 'transfer']);
            $g->get   ('/reports/tax-base-adjustments',                         [TaxBaseReportAction::class, 'get']);
            $g->get   ('/document-series',                                      [DocumentSeriesAction::class, 'list']);
            $g->put   ('/document-series/{code:[a-z_]+}/{year:[0-9]+}',         [DocumentSeriesAction::class, 'update']);
            // Retenční lhůty § 31/§ 32 ZoÚ a § 35a ZDPH. Přehled je informativní —
            // uplynulá lhůta je konec povinnosti uchovávat, ne pokyn ke skartaci.
            $g->get   ('/retention',                        [\MyInvoice\Action\Accounting\RetentionAction::class, 'overview']);
            $g->get   ('/retention/holds',                  [\MyInvoice\Action\Accounting\RetentionAction::class, 'listHolds']);
            $g->post  ('/retention/holds',                  [\MyInvoice\Action\Accounting\RetentionAction::class, 'placeHold']);
            $g->delete('/retention/holds/{id:[0-9]+}',      [\MyInvoice\Action\Accounting\RetentionAction::class, 'releaseHold']);
            // Pokladna (mini-epic #14). Specifické cesty PŘED generickým /cash-documents/{id}.
            $g->get   ('/cash-registers',                      [\MyInvoice\Action\Accounting\Cash\CashRegisterAction::class, 'list']);
            $g->post  ('/cash-registers',                      [\MyInvoice\Action\Accounting\Cash\CashRegisterAction::class, 'create']);
            $g->get   ('/cash-registers/{id:[0-9]+}',          [\MyInvoice\Action\Accounting\Cash\CashRegisterAction::class, 'get']);
            $g->put   ('/cash-registers/{id:[0-9]+}',          [\MyInvoice\Action\Accounting\Cash\CashRegisterAction::class, 'update']);
            $g->delete('/cash-registers/{id:[0-9]+}',          [\MyInvoice\Action\Accounting\Cash\CashRegisterAction::class, 'delete']);
            $g->get   ('/cash-registers/{id:[0-9]+}/book',     [\MyInvoice\Action\Accounting\Cash\CashBookAction::class, 'get']);
            $g->get   ('/cash-registers/{id:[0-9]+}/book/pdf', [\MyInvoice\Action\Accounting\Cash\CashBookAction::class, 'pdf']);
            $g->get   ('/cash-documents',                      [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'list']);
            $g->post  ('/cash-documents',                      [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'create']);
            $g->get   ('/cash-documents/unpaid',               [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'unpaid']);
            $g->get   ('/cash-documents/rule-presets',         [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'rulePresets']);
            $g->get   ('/cash-documents/{id:[0-9]+}',          [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'get']);
            $g->put   ('/cash-documents/{id:[0-9]+}',          [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'update']);
            $g->delete('/cash-documents/{id:[0-9]+}',          [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'delete']);
            $g->post  ('/cash-documents/{id:[0-9]+}/post',     [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'post']);
            $g->post  ('/cash-documents/{id:[0-9]+}/reverse',  [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'reverse']);
            $g->get   ('/cash-documents/{id:[0-9]+}/pdf',      [\MyInvoice\Action\Accounting\Cash\CashDocumentAction::class, 'pdf']);
            // Automatizace (mini-epic) — pravidla účtování + fronta návrhů.
            $g->get   ('/bank-accounts',                              [\MyInvoice\Action\Accounting\Bank\SupplierBankAccountAction::class, 'list']);
            $g->patch ('/bank-accounts/{id:[0-9]+}',                  [\MyInvoice\Action\Accounting\Bank\SupplierBankAccountAction::class, 'update']);
            $g->get   ('/bank-posting-rules',                        [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'list']);
            $g->post  ('/bank-posting-rules',                        [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'create']);
            $g->post  ('/bank-posting-rules/dry-run',                [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'dryRun']);
            $g->put   ('/bank-posting-rules/{id:[0-9]+}',            [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'update']);
            $g->delete('/bank-posting-rules/{id:[0-9]+}',            [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'delete']);
            $g->post  ('/bank-posting-rules/{id:[0-9]+}/promote',    [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'promote']);
            $g->post  ('/bank-posting-rules/{id:[0-9]+}/demote',     [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'demote']);
            $g->post  ('/bank-posting-rules/{id:[0-9]+}/backfill',   [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'backfillRule']);
            $g->get   ('/bank-posting-rules/{id:[0-9]+}/history',    [\MyInvoice\Action\Accounting\Bank\BankPostingRuleAction::class, 'history']);
            $g->get   ('/bank-rule-templates',                       [\MyInvoice\Action\Accounting\Bank\BankRuleTemplateAction::class, 'list']);
            $g->post  ('/bank-rule-templates/{key:[a-z0-9._-]+}/instantiate', [\MyInvoice\Action\Accounting\Bank\BankRuleTemplateAction::class, 'instantiate']);
            $g->get   ('/auto-posting-policy',                       [\MyInvoice\Action\Accounting\Bank\AutoPostingPolicyAction::class, 'get']);
            $g->put   ('/auto-posting-policy',                       [\MyInvoice\Action\Accounting\Bank\AutoPostingPolicyAction::class, 'put']);
            $g->get   ('/bank-posting-suggestions',                  [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'list']);
            $g->get   ('/bank-posting-suggestions/count',            [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'count']);
            $g->get   ('/bank-posting-unposted',                     [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'unposted']);
            $g->get   ('/bank-posting-unposted/count',               [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'unpostedCount']);
            $g->post  ('/bank-posting-suggestions/bulk-preview',     [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'bulkPreview']);
            $g->post  ('/bank-posting-suggestions/bulk-approve',     [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'bulkApprove']);
            $g->post  ('/bank-posting-suggestions/bulk-reject',      [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'bulkReject']);
            $g->post  ('/bank-posting-suggestions/batches/{batchId:[a-fA-F0-9]{32}}/undo', [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'undoBatch']);
            $g->post  ('/bank-posting-suggestions/{id:[0-9]+}/approve', [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'approve']);
            $g->post  ('/bank-posting-suggestions/{id:[0-9]+}/reject',  [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'reject']);
            $g->post  ('/bank-posting-suggestions/{id:[0-9]+}/snooze',  [\MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction::class, 'snooze']);
            // Featura H — jednotná fronta ručního doúčtování (read-only agregace).
            $g->get   ('/manual-posting-queue',                      [\MyInvoice\Action\Accounting\ManualPostingQueueAction::class, 'list']);
        });

        // Dashboard
        $app->get ('/api/dashboard/summary',          SummaryAction::class);
        $app->get ('/api/dashboard/purchase-summary', PurchaseSummaryAction::class);

        // Admin (M6)
        $app->get    ('/api/admin/activity-log',    ListActivityLogAction::class);
        $app->get    ('/api/admin/sent-emails',     ListSentEmailsAction::class);
        $app->get    ('/api/admin/smtp-log-analysis', \MyInvoice\Action\Admin\SmtpLogAnalysisAction::class);
        $app->get    ('/api/admin/smtp-log-analysis/status', [\MyInvoice\Action\Admin\InvoiceSmtpLogAction::class, 'status']);
        $app->get    ('/api/admin/invoices/{id:[0-9]+}/smtp-log', [\MyInvoice\Action\Admin\InvoiceSmtpLogAction::class, 'forInvoice']);
        $app->get    ('/api/admin/cron-jobs',       CronJobsAction::class);
        // PUT, ne POST — nekoliduje s /{script}/run níže a je idempotentní.
        $app->put    ('/api/admin/cron-jobs/schedule-mode', \MyInvoice\Action\Admin\SetCronScheduleModeAction::class);
        $app->post   ('/api/admin/cron-jobs/{script:cron-[a-z0-9-]+}/run', RunCronJobAction::class);
        $app->get    ('/api/admin/invoices-zip',    InvoicesZipAction::class);  // legacy — drží se kvůli historickým bookmark URL
        $app->get    ('/api/admin/export',          ExportAction::class);       // generic export (?format=pdf-zip|isdoc|pohoda|stereo|money_s3|csv&month=YYYY-MM nebo period=quarterly)
        $app->post   ('/api/admin/import',          ImportAction::class);       // import vystavených faktur z Pohoda XML / ISDOC (single nebo ZIP)

        // Kompletní export dat firmy (H-14) — DB + PDF doklady + přílohy do jednoho
        // archivu s manifestem a kontrolními součty. Běží na pozadí
        // (api/bin/export-instance.php), archiv leží mimo docroot a stahuje se jen
        // odsud. Pod /api/admin/ ⇒ superadmin (fail-closed fallback RoutePermissionMap).
        $app->get    ('/api/admin/instance-export',                        [InstanceExportAction::class, 'list']);
        $app->post   ('/api/admin/instance-export/start',                  [InstanceExportAction::class, 'start']);
        $app->get    ('/api/admin/instance-export/{id:[0-9]+}',            [InstanceExportAction::class, 'status']);
        $app->get    ('/api/admin/instance-export/{id:[0-9]+}/download',   [InstanceExportAction::class, 'download']);
        $app->post   ('/api/admin/instance-export/{id:[0-9]+}/cancel',     [InstanceExportAction::class, 'cancel']);
        $app->delete ('/api/admin/instance-export/{id:[0-9]+}',            [InstanceExportAction::class, 'delete']);

        // iDoklad API import (fáze 2a) — credentials + background job lifecycle
        $app->get    ('/api/admin/imports/idoklad/credentials', [IdokladCredentialsAction::class, 'status']);
        $app->put    ('/api/admin/imports/idoklad/credentials', [IdokladCredentialsAction::class, 'update']);
        $app->delete ('/api/admin/imports/idoklad/credentials', [IdokladCredentialsAction::class, 'delete']);
        $app->post   ('/api/admin/imports/idoklad/start',       StartIdokladImportAction::class);

        // Fakturoid (fáze 2b) — credentials + start
        $app->get    ('/api/admin/imports/fakturoid/credentials', [FakturoidCredentialsAction::class, 'status']);
        $app->put    ('/api/admin/imports/fakturoid/credentials', [FakturoidCredentialsAction::class, 'update']);
        $app->delete ('/api/admin/imports/fakturoid/credentials', [FakturoidCredentialsAction::class, 'delete']);
        $app->post   ('/api/admin/imports/fakturoid/start',       StartFakturoidImportAction::class);

        // F7 — AI extrakční brána (LlmGateway): per-provider credentials (admin-only).
        // anthropic + azure_openai + openai + gemini přes jeden endpoint.
        $app->get    ('/api/admin/imports/ai/credentials',        [AiProviderCredentialsAction::class, 'status']);
        $app->put    ('/api/admin/imports/ai/credentials',        [AiProviderCredentialsAction::class, 'update']);
        $app->delete ('/api/admin/imports/ai/credentials',        [AiProviderCredentialsAction::class, 'delete']);
        // TestConnection bez změny uložených creds (admin-only; default 403 fallback).
        $app->post   ('/api/admin/imports/ai/credentials/test',   [AiProviderCredentialsAction::class, 'test']);
        // Ladění extrakce (poznámky do promptu + rychle/přesně). Žádné secrety.
        $app->put    ('/api/admin/imports/ai/tuning',             [AiProviderCredentialsAction::class, 'updateTuning']);

        // Anthropic Claude AI extraction (fáze 2c) — BYOK + synchronní PDF extract.
        // /anthropic/credentials zůstává jako back-compat alias (F7 §3.8).
        $app->get    ('/api/admin/imports/anthropic/credentials', [AnthropicCredentialsAction::class, 'status']);
        $app->put    ('/api/admin/imports/anthropic/credentials', [AnthropicCredentialsAction::class, 'update']);
        $app->delete ('/api/admin/imports/anthropic/credentials', [AnthropicCredentialsAction::class, 'delete']);
        $app->post   ('/api/admin/imports/ai-extract-pdf',        AiExtractPdfAction::class);
        // Prodejní zrcadlo — AI import vydané faktury (ISDOC priorita, AI fallback → draft vydané faktury).
        $app->post   ('/api/admin/imports/ai-extract-pdf-issued', AiExtractPdfIssuedAction::class);

        // CRM dashboard (fáze 5)
        $app->get    ('/api/crm/overview',     [CrmDashboardAction::class, 'overview']);
        $app->get    ('/api/crm/monthly',      [CrmDashboardAction::class, 'monthly']);
        $app->get    ('/api/crm/top-clients',  [CrmDashboardAction::class, 'topClients']);
        $app->get    ('/api/crm/top-vendors',  [CrmDashboardAction::class, 'topVendors']);
        $app->get    ('/api/crm/aging-receivables', [CrmDashboardAction::class, 'agingReceivables']);
        $app->get    ('/api/crm/aging-payables',    [CrmDashboardAction::class, 'agingPayables']);
        $app->get    ('/api/crm/yearly',            [CrmDashboardAction::class, 'yearly']);
        $app->get    ('/api/crm/dso',               [CrmDashboardAction::class, 'dso']);
        $app->get    ('/api/crm/payment-punctuality', [CrmDashboardAction::class, 'punctuality']);
        $app->get    ('/api/crm/concentration',     [CrmDashboardAction::class, 'concentration']);
        $app->get    ('/api/crm/vendor-concentration', [CrmDashboardAction::class, 'vendorConcentration']);
        $app->get    ('/api/crm/dpo',               [CrmDashboardAction::class, 'dpo']);
        $app->get    ('/api/crm/expense-breakdown', [CrmDashboardAction::class, 'expenseBreakdown']);
        $app->get    ('/api/crm/revenue-breakdown', [CrmDashboardAction::class, 'revenueBreakdown']);
        $app->get    ('/api/crm/churn-risk',        [CrmDashboardAction::class, 'churnRisk']);
        $app->get    ('/api/crm/action-items',      [CrmDashboardAction::class, 'actionItems']);
        // Doplatek DPPO má vlastní endpoint — je to nejdražší výpočet feedu a
        // dashboard na něj nemá čekat. Musí být PŘED /{script}-like patterny níže.
        $app->get    ('/api/crm/action-items/tax-balance', [CrmDashboardAction::class, 'taxBalanceItem']);
        $app->post   ('/api/crm/action-items/dismiss', [CrmDashboardAction::class, 'dismissActionItem']);
        $app->post   ('/api/crm/action-items/restore', [CrmDashboardAction::class, 'restoreActionItem']);
        $app->post   ('/api/crm/action-items/restore-all', [CrmDashboardAction::class, 'restoreAllActionItems']);
        $app->get    ('/api/crm/cash-flow-forecast', [CrmDashboardAction::class, 'cashFlowForecast']);
        $app->get    ('/api/crm/late-risk',         [CrmDashboardAction::class, 'lateRisk']);
        $app->get    ('/api/crm/reminder-effectiveness', [CrmDashboardAction::class, 'reminderEffectiveness']);
        $app->get    ('/api/crm/payment-time-histogram', [CrmDashboardAction::class, 'paymentTimeHistogram']);
        $app->get    ('/api/crm/tax-calendar', [CrmDashboardAction::class, 'taxCalendar']);
        $app->post   ('/api/crm/recompute',    [CrmDashboardAction::class, 'recompute']);

        // Přehled firem pro účetní kancelář (cross-supplier dashboard, Fáze F,
        // audit 2026-07 P2/M) — NENÍ scoped na X-Supplier-Id, agreguje přes
        // user_suppliers membership. RBAC: READONLY_RULES (accountant/admin/
        // readonly), role 'client' zamítnuta (PermissionMiddleware terminální větev).
        $app->get    ('/api/portfolio/overview', [PortfolioAction::class, 'overview']);

        // Kokpit Automat — cross-supplier read model; mutace návrhů zůstávají na
        // tenant-scoped bankovních endpointech.
        $app->get    ('/api/automation/feed',      [\MyInvoice\Action\Automation\AutomationFeedAction::class, 'feed']);
        $app->get    ('/api/automation/counts',    [\MyInvoice\Action\Automation\AutomationFeedAction::class, 'counts']);
        $app->get    ('/api/automation/stats',     [\MyInvoice\Action\Automation\AutomationFeedAction::class, 'stats']);
        $app->get    ('/api/automation/overview',  [\MyInvoice\Action\Automation\AutomationFeedAction::class, 'overview']);
        $app->get    ('/api/automation/checklist', [\MyInvoice\Action\Automation\AutomationFeedAction::class, 'checklist']);
        $app->get    ('/api/automation/history',   [\MyInvoice\Action\Automation\AutomationFeedAction::class, 'history']);
        $app->get    ('/api/automation/wizard/analysis', [\MyInvoice\Action\Automation\AutomationWizardAction::class, 'analysis']);
        $app->post   ('/api/automation/wizard/apply',    [\MyInvoice\Action\Automation\AutomationWizardAction::class, 'apply']);
        $app->post   ('/api/ai/suggestions/{id:[0-9]+}/accept', [\MyInvoice\Action\Ai\AiSuggestionAction::class, 'accept']);
        $app->post   ('/api/ai/suggestions/{id:[0-9]+}/reject', [\MyInvoice\Action\Ai\AiSuggestionAction::class, 'reject']);

        // Klientský portál (Epic F6) — agregovaný přehled hospodaření aktivní firmy,
        // dostupný všem přihlášeným rolím (klient = primární konzument).
        $app->get    ('/api/portal/summary',   \MyInvoice\Action\Portal\PortalSummaryAction::class);

        // Vyžádání chybějících dokladů od klienta (Fáze F, audit 2026-07):
        //   účetní pohled (accountant|admin CRUD, readonly GET) — PermissionMiddleware níže.
        $app->get    ('/api/document-requests',                [\MyInvoice\Action\Document\DocumentRequestAction::class, 'list']);
        $app->post   ('/api/document-requests',                [\MyInvoice\Action\Document\DocumentRequestAction::class, 'create']);
        $app->get    ('/api/document-requests/{id:[0-9]+}',    [\MyInvoice\Action\Document\DocumentRequestAction::class, 'get']);
        $app->post   ('/api/document-requests/{id:[0-9]+}/resolve', [\MyInvoice\Action\Document\DocumentRequestAction::class, 'resolve']);
        $app->post   ('/api/document-requests/{id:[0-9]+}/reopen',  [\MyInvoice\Action\Document\DocumentRequestAction::class, 'reopen']);
        $app->delete ('/api/document-requests/{id:[0-9]+}',    [\MyInvoice\Action\Document\DocumentRequestAction::class, 'delete']);

        //   klientský portál — vlastní požadavky + předání originálu do staging fronty.
        $app->get    ('/api/portal/document-requests',                    [\MyInvoice\Action\Portal\PortalDocumentRequestAction::class, 'list']);
        $app->post   ('/api/portal/document-requests/{id:[0-9]+}/upload', [\MyInvoice\Action\Portal\PortalDocumentRequestAction::class, 'upload']);
        $app->get    ('/api/portal/purchase-invoice-submissions', [\MyInvoice\Action\Portal\PortalPurchaseInvoiceSubmissionAction::class, 'list']);
        $app->post   ('/api/portal/purchase-invoice-submissions', [\MyInvoice\Action\Portal\PortalPurchaseInvoiceSubmissionAction::class, 'upload']);
        $app->post   ('/api/portal/purchase-invoice-submissions/{id:[0-9]+}/resubmit', [\MyInvoice\Action\Portal\PortalPurchaseInvoiceSubmissionAction::class, 'resubmit']);
        $app->get    ('/api/portal/purchase-invoice-submissions/{id:[0-9]+}/preview', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionFileAction::class, 'portalPreview']);
        $app->get    ('/api/portal/purchase-invoice-submissions/{id:[0-9]+}/download', [\MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionFileAction::class, 'portalDownload']);

        // EPO výkazy (fáze 6) — DPH přiznání DPHDP3
        $app->get    ('/api/reports/dphdp3/settings', [DphPriznaniAction::class, 'settings']);
        $app->get    ('/api/reports/dphdp3/preview',  [DphPriznaniAction::class, 'preview']);
        $app->get    ('/api/reports/dphdp3/trend',    [DphPriznaniAction::class, 'trend']);
        $app->get    ('/api/reports/dphdp3/drafts-prediction', [DphPriznaniAction::class, 'draftsPrediction']);
        // Fronta „doklady změněné po podání" (C7') — kandidáti na dodatečné přiznání.
        $app->get    ('/api/reports/dphdp3/post-filing-changes', [DphPriznaniAction::class, 'postFilingChanges']);
        $app->get    ('/api/reports/dphdp3',          [DphPriznaniAction::class, 'download']);
        // § 74b — korekce odpočtu dlužníka u neuhrazených závazků (audit §2.5): GET dry-run
        // náhled (účetní|admin), POST zaevidování období do ledgeru (reports.finalize).
        $app->get    ('/api/reports/s74b/preview',    [\MyInvoice\Action\Report\Section74bAction::class, 'preview']);
        $app->post   ('/api/reports/s74b/record',     [\MyInvoice\Action\Report\Section74bAction::class, 'record']);
        // § 46 — věřitelská oprava u nedobytné pohledávky. Oprava se ZADÁVÁ (právní důvod
        // systém neověří), obnova po úhradě (§ 46e) se odvozuje z evidovaných úhrad.
        $app->get    ('/api/reports/s46/candidates',   [\MyInvoice\Action\Report\Section46Action::class, 'candidates']);
        $app->post   ('/api/reports/s46/correction',   [\MyInvoice\Action\Report\Section46Action::class, 'correction']);
        $app->get    ('/api/reports/s46/restorations', [\MyInvoice\Action\Report\Section46Action::class, 'restorationsPreview']);
        $app->post   ('/api/reports/s46/restorations', [\MyInvoice\Action\Report\Section46Action::class, 'restorationsRecord']);
        // § 43 oprava VÝŠE daně per doklad — zpětně do období původního plnění.
        $app->get    ('/api/reports/s43',              [\MyInvoice\Action\Report\Section43Action::class, 'list']);
        $app->post   ('/api/reports/s43',              [\MyInvoice\Action\Report\Section43Action::class, 'create']);
        $app->delete ('/api/reports/s43/{id:[0-9]+}',  [\MyInvoice\Action\Report\Section43Action::class, 'delete']);
        // § 79 / § 79a odpočet při registraci a jeho snížení při zrušení registrace (ř. 45).
        // Eviduje účetní — podmínku „je součástí obchodního majetku" systém z dokladů nevidí.
        $app->get    ('/api/reports/s79',              [\MyInvoice\Action\Report\Section79Action::class, 'list']);
        $app->post   ('/api/reports/s79',              [\MyInvoice\Action\Report\Section79Action::class, 'create']);
        $app->delete ('/api/reports/s79/{id:[0-9]+}',  [\MyInvoice\Action\Report\Section79Action::class, 'delete']);
        // § 36a ZDPH / § 23 odst. 7 ZDP — spojené osoby, ceny obvyklé, úprava základu daně.
        $app->get    ('/api/reports/related-parties',                          [\MyInvoice\Action\Report\RelatedPartyAction::class, 'overview']);
        $app->get    ('/api/reports/related-parties/adjustments',              [\MyInvoice\Action\Report\RelatedPartyAction::class, 'adjustments']);
        $app->post   ('/api/reports/related-parties/adjustments',              [\MyInvoice\Action\Report\RelatedPartyAction::class, 'createAdjustment']);
        $app->delete ('/api/reports/related-parties/adjustments/{id:[0-9]+}',  [\MyInvoice\Action\Report\RelatedPartyAction::class, 'deleteAdjustment']);
        // § 76 koeficient krácení nároku na odpočet (C2'): GET čtení, PUT zálohový
        // koeficient (účetní|admin), POST /settle roční vypořádání (admin-only).
        $app->get    ('/api/reports/vat-coefficient',        [VatCoefficientAction::class, 'get']);
        $app->put    ('/api/reports/vat-coefficient',        [VatCoefficientAction::class, 'setProvisional']);
        $app->post   ('/api/reports/vat-coefficient/settle', [VatCoefficientAction::class, 'settle']);
        // Kontrolní hlášení DPHKH1 (vždy měsíční)
        $app->get    ('/api/reports/dphkh1/preview',  [KontrolniHlaseniAction::class, 'preview']);
        $app->get    ('/api/reports/dphkh1',          [KontrolniHlaseniAction::class, 'download']);
        // Kniha DPH (interní VAT žurnál — NE EPO podání, vždy měsíční)
        $app->get    ('/api/reports/dph-book/preview', [DphBookAction::class, 'preview']);
        $app->get    ('/api/reports/dph-book',         [DphBookAction::class, 'download']);
        // OSS (One Stop Shop) — kvartální dashboard; zařazení řádků se odvozuje automaticky.
        $app->get    ('/api/reports/oss/preview',      [OssReportAction::class, 'preview']);
        // Práh 10 000 EUR (§ 8 odst. 3 ZDPH) — bez guardu oss_disabled, protože ho
        // potřebuje znát právě ten, kdo ještě registrovaný není.
        $app->get    ('/api/reports/oss/threshold',    [OssReportAction::class, 'threshold']);
        // Archiv OSS podání, rekonciliace a evidence § 110f. Vlastní Action, ale POD
        // /api/reports/oss — díky tomu je chytí modulový fallback `reports` READ
        // v RoutePermissionMap a export navíc pravidlo `reports.export` (klíč `export`
        // v cestě). Detail snapshotu a stažení XML sdílí /api/reports/submissions/{id}.
        $app->get    ('/api/reports/oss/submissions',      [OssFilingArchiveAction::class, 'archive']);
        $app->get    ('/api/reports/oss/reconciliation',   [OssFilingArchiveAction::class, 'reconciliation']);
        $app->get    ('/api/reports/oss/evidence',         [OssFilingArchiveAction::class, 'evidence']);
        $app->get    ('/api/reports/oss/evidence/export',  [OssFilingArchiveAction::class, 'evidenceExport']);
        $app->get    ('/api/reports/oss',              [OssReportAction::class, 'download']);
        // Audit kurzů vs. ČNB (§C / K4) — cizoměnové doklady s odchylkou účetního kurzu od ČNB
        $app->get    ('/api/reports/cnb-rate-audit',   \MyInvoice\Action\Report\CnbRateAuditAction::class);
        // Úplnost číselné řady vydaných dokladů (FR3, vendor audit 2026-08) — mezera
        // v řadě je auditní signál pro FÚ. Read-only, dostupné bez ohledu na účetní režim.
        $app->get    ('/api/reports/invoice-series-completeness', \MyInvoice\Action\Report\InvoiceSeriesCompletenessAction::class);
        // Měsíční export — background job: jeden ZIP s vybranými exporty za měsíc
        // (VF/PF PDF+ISDOC, výpisy PDF+GPC, Kniha DPH). Běží na pozadí (import_jobs).
        $app->get    ('/api/reports/monthly-export/preview',                  [MonthlyExportAction::class, 'preview']);
        $app->post   ('/api/reports/monthly-export/start',                    [MonthlyExportAction::class, 'start']);
        $app->get    ('/api/reports/monthly-export/jobs',                     [MonthlyExportAction::class, 'list']);
        $app->get    ('/api/reports/monthly-export/jobs/{id:[0-9]+}',          [MonthlyExportAction::class, 'jobStatus']);
        $app->get    ('/api/reports/monthly-export/jobs/{id:[0-9]+}/download', [MonthlyExportAction::class, 'download']);
        $app->post   ('/api/reports/monthly-export/jobs/{id:[0-9]+}/cancel',   [MonthlyExportAction::class, 'cancel']);
        $app->delete ('/api/reports/monthly-export/jobs/{id:[0-9]+}',          [MonthlyExportAction::class, 'delete']);
        // Uzávěrkový balíček — background job: jeden ZIP se VŠEMI sestavami uzávěrky
        // účetního období (rozvaha, výsledovka, hlavní kniha, deník, obratová předvaha,
        // Kniha DPH, přiznání k dani). Jen podvojné účetnictví (guard v Action).
        $app->get    ('/api/reports/closing-package/preview',                  [ClosingPackageAction::class, 'preview']);
        $app->post   ('/api/reports/closing-package/start',                    [ClosingPackageAction::class, 'start']);
        $app->get    ('/api/reports/closing-package/jobs',                     [ClosingPackageAction::class, 'list']);
        $app->get    ('/api/reports/closing-package/jobs/{id:[0-9]+}',          [ClosingPackageAction::class, 'jobStatus']);
        $app->get    ('/api/reports/closing-package/jobs/{id:[0-9]+}/download', [ClosingPackageAction::class, 'download']);
        $app->post   ('/api/reports/closing-package/jobs/{id:[0-9]+}/cancel',   [ClosingPackageAction::class, 'cancel']);
        $app->delete ('/api/reports/closing-package/jobs/{id:[0-9]+}',          [ClosingPackageAction::class, 'delete']);
        // Souhrnné hlášení DPHSHV (EU dodání, měsíční — podávají i identifikované osoby)
        $app->get    ('/api/reports/dphshv/preview',  [SouhrnneHlaseniAction::class, 'preview']);
        $app->get    ('/api/reports/dphshv',          [SouhrnneHlaseniAction::class, 'download']);
        // Tax submission archive (historie všech generovaných EPO XML)
        $app->get    ('/api/reports/submissions',                 [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'list']);
        $app->get    ('/api/reports/submissions/settings',        [\MyInvoice\Action\Report\TaxSubmissionEpoAction::class, 'settings']);
        $app->put    ('/api/reports/submissions/settings',        [\MyInvoice\Action\Report\TaxSubmissionEpoAction::class, 'updateSettings']);
        $app->get    ('/api/reports/submissions/epo-credentials', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'credentials']);
        $app->post   ('/api/reports/submissions/epo-credentials', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'uploadCredential']);
        $app->put    ('/api/reports/submissions/epo-credentials/{credentialId:[0-9]+}/supplier', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'setCredentialSupplier']);
        $app->delete ('/api/reports/submissions/epo-credentials/{credentialId:[0-9]+}', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'deleteCredential']);
        $app->get    ('/api/reports/submissions/{id:[0-9]+}',     [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'detail']);
        $app->get    ('/api/reports/submissions/{id:[0-9]+}/xml', [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'downloadXml']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/epo-handoff', [\MyInvoice\Action\Report\TaxSubmissionEpoAction::class, 'handoff']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/epo-test', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'test']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/epo-submit', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'submit']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/epo-status', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'refreshStatus']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/epo-attempts/{attemptId:[0-9]+}/confirmation', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'recoverConfirmation']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/epo-attempts/{attemptId:[0-9]+}/resolve-not-submitted', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'resolveAsNotSubmitted']);
        // POST, ne GET: odhalení hesla pro dotaz na stav je vědomá akce se step-up ověřením
        // a auditní stopou, ne čtení, které by se dalo předvyplnit do odkazu nebo nacachovat.
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/epo-attempts/{attemptId:[0-9]+}/state-password', [\MyInvoice\Action\Report\EpoDirectSubmissionAction::class, 'revealStatePassword']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/artifacts', [\MyInvoice\Action\Report\TaxSubmissionEpoAction::class, 'uploadArtifacts']);
        $app->get    ('/api/reports/submissions/{id:[0-9]+}/artifacts/{artifactId:[0-9]+}/download', [\MyInvoice\Action\Report\TaxSubmissionEpoAction::class, 'downloadArtifact']);
        $app->post   ('/api/reports/submissions/{id:[0-9]+}/submit', [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'submit']);
        $app->delete ('/api/reports/submissions/{id:[0-9]+}',     [\MyInvoice\Action\Report\TaxSubmissionAction::class, 'delete']);

        $app->get    ('/api/admin/imports/{id:[0-9]+}',         ImportJobStatusAction::class);
        $app->post   ('/api/admin/imports/{id:[0-9]+}/cancel',  CancelImportJobAction::class);
        $app->delete ('/api/admin/imports/{id:[0-9]+}',         \MyInvoice\Action\Admin\Import\DeleteImportJobAction::class);
        $app->get    ('/api/admin/users',           [UserAdminAction::class, 'list']);
        $app->post   ('/api/admin/users',           [UserAdminAction::class, 'create']);
        $app->put    ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'update']);
        $app->delete ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'delete']);
        // Epic F0 — membership uživatel ↔ supplier (fine-grained tenant přístup)
        $app->get    ('/api/admin/users/{id:[0-9]+}/suppliers', [\MyInvoice\Action\Admin\UserSupplierAdminAction::class, 'list']);
        $app->put    ('/api/admin/users/{id:[0-9]+}/suppliers', [\MyInvoice\Action\Admin\UserSupplierAdminAction::class, 'replace']);

        // Approval inbox (admin only) — globální seznam schvalování
        $app->get    ('/api/admin/approvals',       ApprovalListAction::class);

        // Email šablony (admin only)
        $app->get    ('/api/admin/email-templates',                                  [EmailTemplateAction::class, 'list']);
        $app->get    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'get']);
        $app->put    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'put']);
        $app->delete ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'delete']);

        // Globální katalog šablon bankovních pravidel (session + superadmin only).
        $app->get    ('/api/admin/bank-rule-templates',              [BankRuleTemplateAdminAction::class, 'list']);
        $app->post   ('/api/admin/bank-rule-templates',              [BankRuleTemplateAdminAction::class, 'create']);
        $app->put    ('/api/admin/bank-rule-templates/{id:[0-9]+}',  [BankRuleTemplateAdminAction::class, 'update']);
        $app->delete ('/api/admin/bank-rule-templates/{id:[0-9]+}',  [BankRuleTemplateAdminAction::class, 'delete']);

        // Multi-supplier (M7)
        $app->get    ('/api/suppliers',                     [SettingsAction::class, 'listSuppliers']);
        $app->post   ('/api/suppliers',                     [SettingsAction::class, 'createSupplier']);
        $app->get    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'getSupplierById']);
        $app->put    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'updateSupplierById']);
        $app->delete ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'deleteSupplierById']);

        // Settings (M6) — aktuální supplier (z X-Supplier-Id)
        $app->get ('/api/settings/supplier',                [SettingsAction::class, 'getSupplier']);
        $app->get    ('/api/settings/domains',                         [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'list']);
        $app->post   ('/api/settings/domains',                         [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'create']);
        $app->put    ('/api/settings/domains/{id:[0-9]+}',              [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'update']);
        $app->post   ('/api/settings/domains/{id:[0-9]+}/challenge',    [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'rotateChallenge']);
        $app->post   ('/api/settings/domains/{id:[0-9]+}/verify',       [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'verify']);
        $app->post   ('/api/settings/domains/{id:[0-9]+}/activate',     [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'activate']);
        $app->post   ('/api/settings/domains/{id:[0-9]+}/disable',      [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'disable']);
        $app->delete ('/api/settings/domains/{id:[0-9]+}',              [\MyInvoice\Action\Settings\SupplierDomainAction::class, 'delete']);
        $app->put ('/api/settings/supplier',                [SettingsAction::class, 'updateSupplier']);
        // Historie plátcovství DPH (EPIC VH-01) — seznam vrací GET /api/settings/supplier.
        $app->post   ('/api/settings/vat-status-history',              [\MyInvoice\Action\Settings\VatStatusHistoryAction::class, 'save']);
        $app->delete ('/api/settings/vat-status-history/{id:[0-9]+}',  [\MyInvoice\Action\Settings\VatStatusHistoryAction::class, 'delete']);
        // § 6/§ 94 hlídač obratu pro banner Plátcovství DPH (EPIC VH-07).
        $app->get    ('/api/settings/vat-status-history/registration-check', [\MyInvoice\Action\Settings\VatStatusHistoryAction::class, 'registrationCheck']);
        // Historie zastoupení daňovým poradcem (§29/2 DŘ) — seznam vrací GET /api/settings/supplier.
        $app->post   ('/api/settings/tax-representation-history',             [\MyInvoice\Action\Settings\TaxRepresentationAction::class, 'save']);
        $app->delete ('/api/settings/tax-representation-history/{id:[0-9]+}', [\MyInvoice\Action\Settings\TaxRepresentationAction::class, 'delete']);
        $app->get ('/api/settings/ai-assist',               [\MyInvoice\Action\Settings\AiAssistSettingsAction::class, 'get']);
        $app->put ('/api/settings/ai-assist',               [\MyInvoice\Action\Settings\AiAssistSettingsAction::class, 'put']);
        $app->get ('/api/settings/mode-switch-preview',     [SettingsAction::class, 'modeSwitchPreview']);
        // Ciselnik CINNOSTI (CZ-NACE) - read-only referencni data pro c_okec.
        $app->get ('/api/settings/nace-codes',              \MyInvoice\Action\Settings\NaceCodesAction::class);
        $app->get ('/api/settings/accounting-activation/status', [AccountingActivationAction::class, 'status']);
        $app->post('/api/settings/accounting-activation/start', [AccountingActivationAction::class, 'start']);
        $app->get ('/api/settings/accounting-activation/opening', [AccountingActivationAction::class, 'opening']);
        $app->put ('/api/settings/accounting-activation/opening', [AccountingActivationAction::class, 'saveOpening']);
        $app->post('/api/settings/accounting-activation/opening/prefill', [AccountingActivationAction::class, 'prefillOpening']);
        $app->post('/api/settings/accounting-activation/dry-run', [AccountingActivationAction::class, 'dryRun']);
        $app->post('/api/settings/accounting-activation/execute', [AccountingActivationAction::class, 'execute']);
        $app->get ('/api/settings/accounting-activation/jobs', [AccountingActivationAction::class, 'jobs']);
        $app->get ('/api/settings/accounting-activation/jobs/{id:[0-9]+}', [AccountingActivationAction::class, 'job']);
        $app->post('/api/settings/accounting-activation/jobs/{id:[0-9]+}/cancel', [AccountingActivationAction::class, 'cancel']);
        $app->put ('/api/settings/supplier/invoice-counter', SupplierInvoiceCounterAction::class);
        $app->get    ('/api/settings/email-profiles',       [EmailProfilesAction::class, 'list']);
        $app->post   ('/api/settings/email-profiles',       [EmailProfilesAction::class, 'create']);
        $app->post   ('/api/settings/email-profiles/test',  [EmailProfilesAction::class, 'testDraft']);
        $app->post   ('/api/settings/email-profiles/imap-test', [EmailProfilesAction::class, 'testImapSettings']);
        $app->post   ('/api/settings/email-profiles/folders', [EmailProfilesAction::class, 'browseImapFolders']);
        $app->post   ('/api/settings/email-profiles/{id:[0-9]+}/test', [EmailProfilesAction::class, 'test']);
        $app->post   ('/api/settings/email-profiles/{id:[0-9]+}/imap-test', [EmailProfilesAction::class, 'testImapSettings']);
        $app->post   ('/api/settings/email-profiles/{id:[0-9]+}/folders', [EmailProfilesAction::class, 'browseImapFolders']);
        $app->put    ('/api/settings/email-profiles/{id:[0-9]+}', [EmailProfilesAction::class, 'update']);
        $app->delete ('/api/settings/email-profiles/{id:[0-9]+}', [EmailProfilesAction::class, 'delete']);
        // Klientský allowlist používá stejné supplier-scoped akce, ale vlastní URL.
        // RoutePermissionMap na těchto aliasových cestách vyžaduje settings.company WRITE.
        $app->get    ('/api/settings/client/email-profiles',       [EmailProfilesAction::class, 'list']);
        $app->post   ('/api/settings/client/email-profiles',       [EmailProfilesAction::class, 'create']);
        $app->post   ('/api/settings/client/email-profiles/test',  [EmailProfilesAction::class, 'testDraft']);
        $app->post   ('/api/settings/client/email-profiles/imap-test', [EmailProfilesAction::class, 'testImapSettings']);
        $app->post   ('/api/settings/client/email-profiles/folders', [EmailProfilesAction::class, 'browseImapFolders']);
        $app->post   ('/api/settings/client/email-profiles/{id:[0-9]+}/test', [EmailProfilesAction::class, 'test']);
        $app->post   ('/api/settings/client/email-profiles/{id:[0-9]+}/imap-test', [EmailProfilesAction::class, 'testImapSettings']);
        $app->post   ('/api/settings/client/email-profiles/{id:[0-9]+}/folders', [EmailProfilesAction::class, 'browseImapFolders']);
        $app->put    ('/api/settings/client/email-profiles/{id:[0-9]+}', [EmailProfilesAction::class, 'update']);
        $app->delete ('/api/settings/client/email-profiles/{id:[0-9]+}', [EmailProfilesAction::class, 'delete']);
        $app->get    ('/api/settings/client/branding', [ClientBrandingSettingsAction::class, 'get']);
        $app->put    ('/api/settings/client/branding', [ClientBrandingSettingsAction::class, 'update']);
        $app->get    ('/api/settings/client/branding/preview', [EmailBrandingAction::class, 'preview']);
        $app->post   ('/api/settings/client/branding/logo', [EmailBrandingAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/client/branding/logo', [EmailBrandingAction::class, 'deleteLogo']);
        $app->get    ('/api/settings/client/branding/profiles', [BrandingProfilesAction::class, 'list']);
        $app->post   ('/api/settings/client/branding/profiles', [BrandingProfilesAction::class, 'create']);
        $app->put    ('/api/settings/client/branding/profiles/{id:[0-9]+}', [BrandingProfilesAction::class, 'update']);
        $app->delete ('/api/settings/client/branding/profiles/{id:[0-9]+}', [BrandingProfilesAction::class, 'delete']);
        $app->post   ('/api/settings/client/branding/profiles/{id:[0-9]+}/default', [BrandingProfilesAction::class, 'setDefault']);
        $app->post   ('/api/settings/client/branding/profiles/{id:[0-9]+}/logo', [BrandingProfilesAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/client/branding/profiles/{id:[0-9]+}/logo', [BrandingProfilesAction::class, 'deleteLogo']);
        $app->get    ('/api/settings/branding-profiles',                 [BrandingProfilesAction::class, 'list']);
        $app->post   ('/api/settings/branding-profiles',                 [BrandingProfilesAction::class, 'create']);
        $app->put    ('/api/settings/branding-profiles/{id:[0-9]+}',     [BrandingProfilesAction::class, 'update']);
        $app->delete ('/api/settings/branding-profiles/{id:[0-9]+}',     [BrandingProfilesAction::class, 'delete']);
        $app->post   ('/api/settings/branding-profiles/{id:[0-9]+}/default', [BrandingProfilesAction::class, 'setDefault']);
        $app->post   ('/api/settings/branding-profiles/{id:[0-9]+}/logo', [BrandingProfilesAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/branding-profiles/{id:[0-9]+}/logo', [BrandingProfilesAction::class, 'deleteLogo']);
        $app->get    ('/api/settings/pdf-signing/diagnostics', PdfSigningDiagnosticsAction::class);
        $app->get    ('/api/settings/pdf-signing',          [SigningProfilesAction::class, 'pdfSettings']);
        $app->post   ('/api/settings/pdf-signing/test',     [SigningProfilesAction::class, 'testPdfSigning']);
        $app->put    ('/api/settings/pdf-signing/output-settings', [SigningProfilesAction::class, 'updatePdfOutputSettings']);
        $app->put    ('/api/settings/pdf-signing/output-settings/{output_type:[a-z_]+}', [SigningProfilesAction::class, 'updatePdfOutputSetting']);
        $app->get    ('/api/settings/pdf-signing/user-defaults', [SigningProfilesAction::class, 'userDefaults']);
        $app->put    ('/api/settings/pdf-signing/user-defaults/{output_type:[a-z_]+}', [SigningProfilesAction::class, 'updateUserDefault']);
        // Certifikáty centrálně: jedno úložiště pro podpisy e-mailů, PDF, EPO
        // i mzdová podání. EPO endpointy zůstávají a míří do téhož trezoru.
        $app->get    ('/api/settings/certificates',        [\MyInvoice\Action\Settings\CertificateVaultAction::class, 'list']);
        $app->post   ('/api/settings/certificates',        [\MyInvoice\Action\Settings\CertificateVaultAction::class, 'upload']);
        // Datová schránka jako průřezový kanál podání (DPH, KH, SH, DPPO,
        // přehledy ZP…). Systémový certifikát je vždy nastavení aktuální firmy.
        $app->get    ('/api/settings/databox',             [\MyInvoice\Action\Submission\DataBoxSettingsAction::class, 'list']);
        $app->post   ('/api/settings/databox',             [\MyInvoice\Action\Submission\DataBoxSettingsAction::class, 'save']);
        $app->get    ('/api/settings/databox/mobile-key',  [\MyInvoice\Action\Submission\DataBoxSettingsAction::class, 'mobileKeyProfile']);
        $app->post   ('/api/settings/databox/mobile-key',  [\MyInvoice\Action\Submission\DataBoxSettingsAction::class, 'saveMobileKeyProfile']);
        $app->delete ('/api/settings/databox/mobile-key/{environment:production|test}', [\MyInvoice\Action\Submission\DataBoxSettingsAction::class, 'deleteMobileKeyProfile']);
        $app->get    ('/api/settings/databox/inbox-storage', [\MyInvoice\Action\Submission\SubmissionInboxStorageSettingsAction::class, 'list']);
        $app->put    ('/api/settings/databox/inbox-storage/{environment:production|test}', [\MyInvoice\Action\Submission\SubmissionInboxStorageSettingsAction::class, 'save']);
        $app->delete ('/api/settings/databox/{environment:production|test}', [\MyInvoice\Action\Submission\DataBoxSettingsAction::class, 'delete']);
        // Registrace odesílací brány je věc PROVOZOVATELE, ne zákazníka:
        // certifikát je jeden pro celou službu a zákazník k odeslání přes bránu
        // nepotřebuje nastavit nic.
        $app->get    ('/api/settings/isds-gateway',        [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'settings']);
        $app->post   ('/api/settings/isds-gateway',        [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'saveSettings']);
        $app->post   ('/api/settings/isds-gateway/active', [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'setActive']);
        $app->delete ('/api/settings/isds-gateway/{environment:production|test}', [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'deleteSettings']);
        $app->get    ('/api/submissions/recipients',       [\MyInvoice\Action\Submission\SubmissionRecipientAction::class, 'list']);
        $app->post   ('/api/submissions/recipients',       [\MyInvoice\Action\Submission\SubmissionRecipientAction::class, 'save']);
        $app->delete ('/api/submissions/recipients/{id:[0-9]+}', [\MyInvoice\Action\Submission\SubmissionRecipientAction::class, 'delete']);
        $app->get    ('/api/submissions/outbox',           [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'list']);
        $app->post   ('/api/submissions/outbox',           [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'enqueue']);
        $app->get    ('/api/submissions/outbox/{id:[0-9]+}/attempts', [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'attempts']);
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/confirm',  [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'confirm']);
        // Odeslání datovkou v relaci potvrzené Mobilním klíčem. Stav a odeslání
        // je JEDEN endpoint schválně: potvrzení se dá vyzvednout jen jednou.
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/mobile-key/start',   [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'mobileKeyStart']);
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/mobile-key/confirm', [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'mobileKeyConfirm']);
        // Dávka: JEDNO potvrzení v mobilu pošle VÍC podání (typicky ČSSZ +
        // víc zdravotních pojišťoven za týž měsíc). Bez `{id}` schválně —
        // přihlášení k ISDS není vázané na jedno konkrétní podání.
        $app->post   ('/api/submissions/outbox/mobile-key/start-batch',   [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'mobileKeyStartBatch']);
        $app->post   ('/api/submissions/outbox/mobile-key/confirm-batch', [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'mobileKeyConfirmBatch']);
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/resolve',  [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'resolve']);
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/cancel',   [\MyInvoice\Action\Submission\SubmissionOutboxAction::class, 'cancel']);
        // Ruční cesta: uživatel odešle zprávu ze své datové schránky a přinese
        // zpátky doručenku. Bez těchhle dvou kroků by podání odeslané ručně
        // zůstalo navždy v „připraveno".
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/mark-sent', [\MyInvoice\Action\Submission\SubmissionReceiptAction::class, 'markSent']);
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/receipt',   [\MyInvoice\Action\Submission\SubmissionReceiptAction::class, 'upload']);
        // Odesílací brána ISDS (SetConcept): aplikace vloží KONCEPT do perimetru
        // datové schránky a odeslání schválí uživatel přímo v ISDS. Přihlašovací
        // údaje ke schránce tudy neprocházejí — zadávají se v ISDS (§ 9 odst. 2
        // zák. 300/2008 Sb. je tak splněn konstrukcí, ne výkladem).
        // `callback` je návratové URL registrace; oprávnění drží přihlášená
        // relace, `appToken` z přesměrování jen dohledává rozpracované podání.
        $app->post   ('/api/submissions/outbox/{id:[0-9]+}/gateway',   [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'start']);
        $app->post   ('/api/submissions/gateway/callback',             [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'complete']);
        $app->get    ('/api/submissions/gateway/capability',           [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'capability']);
        // Tatáž brána pro mzdovou roli. Globální registraci certifikátu tím
        // nezískává — aliasy jen zahájí a dokončí její vlastní podání.
        $app->post   ('/api/payroll/submissions/isds-gateway/outbox/{id:[0-9]+}', [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'payrollStart']);
        $app->post   ('/api/payroll/submissions/isds-gateway/callback',            [\MyInvoice\Action\Submission\IsdsGatewayAction::class, 'payrollComplete']);
        $app->post   ('/api/submissions/receipts',                     [\MyInvoice\Action\Submission\SubmissionReceiptAction::class, 'upload']);
        $app->get    ('/api/submissions/receipts/unmatched',           [\MyInvoice\Action\Submission\SubmissionReceiptAction::class, 'unmatched']);
        $app->get    ('/api/submissions/receipts/{id:[0-9]+}/candidates', [\MyInvoice\Action\Submission\SubmissionReceiptAction::class, 'candidates']);
        $app->post   ('/api/submissions/receipts/{id:[0-9]+}/match',   [\MyInvoice\Action\Submission\SubmissionReceiptAction::class, 'match']);
        $app->get    ('/api/submissions/inbox',            [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'list']);
        $app->post   ('/api/submissions/inbox/poll',       [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'poll']);
        $app->post   ('/api/submissions/inbox/poll/password', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'pollWithPassword']);
        $app->post   ('/api/submissions/inbox/mobile-key/start', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'mobileKeyStart']);
        $app->post   ('/api/submissions/inbox/mobile-key/status', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'mobileKeyStatus']);
        $app->post   ('/api/submissions/inbox/sms/start', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'smsStart']);
        $app->post   ('/api/submissions/inbox/sms/complete', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'smsComplete']);
        $app->post   ('/api/submissions/inbox/{id:[0-9]+}/classify', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'reclassify']);
        $app->post   ('/api/submissions/inbox/{id:[0-9]+}/hide', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'hide']);
        $app->post   ('/api/submissions/inbox/{id:[0-9]+}/restore', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'restore']);
        $app->delete ('/api/submissions/inbox/{id:[0-9]+}/local-content', [\MyInvoice\Action\Submission\SubmissionInboxAction::class, 'purgeLocalContent']);
        // Doručení a jeho následky. `delivery/refresh` nesahá na síť — jen znovu
        // posoudí už stažené zprávy, protože běžící lhůta fikce (§ 17 odst. 4
        // zák. 300/2008 Sb.) se mění pouhým během času.
        $app->post   ('/api/submissions/inbox/delivery/refresh', [\MyInvoice\Action\Submission\SubmissionDefectNoticeAction::class, 'refreshDelivery']);
        $app->get    ('/api/submissions/defect-notices',   [\MyInvoice\Action\Submission\SubmissionDefectNoticeAction::class, 'list']);
        $app->post   ('/api/submissions/defect-notices',   [\MyInvoice\Action\Submission\SubmissionDefectNoticeAction::class, 'create']);
        $app->patch  ('/api/submissions/defect-notices/{id:[0-9]+}', [\MyInvoice\Action\Submission\SubmissionDefectNoticeAction::class, 'amend']);
        $app->post   ('/api/submissions/defect-notices/{id:[0-9]+}/response', [\MyInvoice\Action\Submission\SubmissionDefectNoticeAction::class, 'respond']);
        $app->get    ('/api/settings/signing',              [SigningProfilesAction::class, 'settings']);
        $app->put    ('/api/settings/signing',              [SigningProfilesAction::class, 'updateSettings']);
        $app->get    ('/api/settings/signing/profiles',              [SigningProfilesAction::class, 'listProfiles']);
        $app->post   ('/api/settings/signing/profiles',              [SigningProfilesAction::class, 'createProfile']);
        $app->get    ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'credentialCertificate']);
        $app->post   ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'uploadCredentialCertificate']);
        $app->put    ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'updateCredentialCertificate']);
        $app->delete ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'deleteCredentialCertificate']);
        $app->get    ('/api/settings/signing/personal-certificates', [SigningProfilesAction::class, 'personalVaultCredentials']);
        $app->put    ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/personal-vault', [SigningProfilesAction::class, 'linkPersonalVaultCredential']);
        $app->get    ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'getProfile']);
        $app->put    ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'updateProfile']);
        $app->delete ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'deleteProfile']);
        $app->get    ('/api/settings/currencies',                     [SettingsAction::class, 'listCurrencies']);
        $app->post   ('/api/settings/currencies',                     [SettingsAction::class, 'createCurrency']);
        $app->put    ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'updateCurrency']);
        $app->delete ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'deleteCurrency']);
        $app->get    ('/api/settings/bank-email-notices',             [BankEmailNoticeAction::class, 'overview']);
        $app->put    ('/api/settings/bank-email-notices/imap',        [BankEmailNoticeAction::class, 'updateImap']);
        $app->post   ('/api/settings/bank-email-notices/imap/test',   [BankEmailNoticeAction::class, 'testImap']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts', [BankEmailNoticeAction::class, 'createImapAccount']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts/folders', [BankEmailNoticeAction::class, 'browseImapFolders']);
        $app->put    ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}', [BankEmailNoticeAction::class, 'updateImapAccount']);
        $app->delete ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}', [BankEmailNoticeAction::class, 'deleteImapAccount']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}/test', [BankEmailNoticeAction::class, 'testImapAccount']);
        $app->post   ('/api/settings/bank-email-notices/imap-accounts/{id:[0-9]+}/folders', [BankEmailNoticeAction::class, 'browseImapFolders']);
        $app->post   ('/api/settings/bank-email-notices/providers',   [BankEmailNoticeAction::class, 'createProvider']);
        $app->put    ('/api/settings/bank-email-notices/providers/{id:[0-9]+}', [BankEmailNoticeAction::class, 'updateProvider']);
        $app->delete ('/api/settings/bank-email-notices/providers/{id:[0-9]+}', [BankEmailNoticeAction::class, 'deleteProvider']);
        $app->put    ('/api/settings/bank-email-notices/mappings',    [BankEmailNoticeAction::class, 'updateMappings']);
        $app->post   ('/api/settings/bank-email-notices/parser/test', [BankEmailNoticeAction::class, 'testParser']);
        $app->post   ('/api/settings/bank-email-notices/scan',        [BankEmailNoticeAction::class, 'scan']);
        $app->get    ('/api/settings/bank-email-notices/messages',    [BankEmailNoticeAction::class, 'messages']);
        $app->delete ('/api/settings/bank-email-notices/messages/{id:[0-9]+}', [BankEmailNoticeAction::class, 'deleteMessage']);

        $app->get    ('/api/settings/vat-rates',                      [SettingsAction::class, 'listVatRates']);
        $app->post   ('/api/settings/vat-rates',                      [SettingsAction::class, 'createVatRate']);
        $app->put    ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'updateVatRate']);
        $app->delete ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'deleteVatRate']);

        $app->get    ('/api/settings/countries',                      [SettingsAction::class, 'listCountries']);
        $app->post   ('/api/settings/countries',                      [SettingsAction::class, 'createCountry']);
        $app->put    ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'updateCountry']);
        $app->delete ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'deleteCountry']);

        // Email branding (M16) — per-supplier logo + accent color v hlavičce odchozích emailů
        $app->post   ('/api/settings/email-branding/logo',            [EmailBrandingAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/email-branding/logo',            [EmailBrandingAction::class, 'deleteLogo']);
        $app->get    ('/api/settings/email-branding/preview',         [EmailBrandingAction::class, 'preview']);
        // Veřejné API aliasy pro logo (bearer allowlist) — stejná logika, jiná cesta.
        // Preview zůstává interní (čte soubory z disku → jen session admin).
        $app->post   ('/api/settings/supplier/logo',                  [EmailBrandingAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/supplier/logo',                  [EmailBrandingAction::class, 'deleteLogo']);

        $app->get    ('/api/settings/units',                          [SettingsAction::class, 'listUnits']);
        $app->post   ('/api/settings/units',                          [SettingsAction::class, 'createUnit']);
        $app->put    ('/api/settings/units/{id:[0-9]+}',              [SettingsAction::class, 'updateUnit']);
        $app->delete ('/api/settings/units/{id:[0-9]+}',              [SettingsAction::class, 'deleteUnit']);

        // Tax optimizer — daňový optimalizátor (srovnání režimů + predikce limitů)
        $app->get ('/api/tax/analysis',  [\MyInvoice\Action\Tax\TaxAction::class, 'analysis']);
        $app->put ('/api/tax/profile',   [\MyInvoice\Action\Tax\TaxAction::class, 'updateProfile']);

        // Písemnosti k příjmům daňových nerezidentů — oznámení o příjmech
        // plynoucích do zahraničí (DPSHL1, § 38da) a hlášení o srážce zajištění
        // daně (DPSZD1, § 38e). Nejsou pod `/api/payroll` schválně: z mezd
        // nevznikají (viz ForeignIncomeNoticeAction).
        $app->get  ('/api/tax/foreign-income/catalog',
            [\MyInvoice\Action\Tax\ForeignIncomeNoticeAction::class, 'catalog']);
        $app->post ('/api/tax/foreign-income/{form:[a-z0-9]+}/xml',
            [\MyInvoice\Action\Tax\ForeignIncomeNoticeAction::class, 'download']);

        // Bank statements (M5b)
        $app->post ('/api/bank-statements/upload',           [BankStatementAction::class, 'upload']);
        $app->post ('/api/bank-statements/upload-pdf',       [BankStatementAction::class, 'importPdf']);
        $app->post ('/api/bank-statements/scan',             [BankStatementAction::class, 'scan']);
        $app->get  ('/api/bank-statements',                  [BankStatementAction::class, 'list']);
        $app->get  ('/api/bank-statements/account-balances', [BankStatementAction::class, 'accountBalances']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}',      [BankStatementAction::class, 'detail']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}/download', [BankStatementAction::class, 'download']);
        $app->post ('/api/bank-statements/{id:[0-9]+}/pdf',  [BankStatementAction::class, 'uploadPdf']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}/pdf',  [BankStatementAction::class, 'downloadPdf']);
        $app->delete('/api/bank-statements/{id:[0-9]+}/pdf', [BankStatementAction::class, 'deletePdf']);
        $app->delete('/api/bank-statements/{id:[0-9]+}',     [BankStatementAction::class, 'delete']);
        $app->post ('/api/bank-statements/{id:[0-9]+}/rematch', [BankStatementAction::class, 'rematch']);
        $app->get  ('/api/bank-statements/{id:[0-9]+}/match-suggestions', [BankStatementAction::class, 'matchSuggestions']);
        $app->post ('/api/bank-match-suggestions/{id:[0-9]+}/accept', [BankStatementAction::class, 'acceptMatchSuggestion']);
        $app->post ('/api/bank-match-suggestions/{id:[0-9]+}/reject', [BankStatementAction::class, 'rejectMatchSuggestion']);
        $app->get  ('/api/bank-transactions/{id:[0-9]+}/match-candidates', [BankStatementAction::class, 'matchCandidates']);
        $app->get  ('/api/bank-transactions/{id:[0-9]+}/split-suggestions', [BankStatementAction::class, 'splitSuggestions']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/match',   [BankStatementAction::class, 'manualMatch']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/unmatch', [BankStatementAction::class, 'unmatch']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/ignore',  [BankStatementAction::class, 'ignore']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/create-purchase-invoice', [BankStatementAction::class, 'createPurchaseInvoice']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/document-request', [BankStatementAction::class, 'createDocumentRequest']);
        // Automatizace (mini-epic) — ruční zaúčtování / storno transakce.
        $app->post ('/api/bank-transactions/{id:[0-9]+}/post',   [\MyInvoice\Action\Accounting\Bank\BankTransactionPostingAction::class, 'post']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/unpost', [\MyInvoice\Action\Accounting\Bank\BankTransactionPostingAction::class, 'unpost']);
        $app->post ('/api/bank-transactions/{id:[0-9]+}/ai-suggest', \MyInvoice\Action\Ai\BankAiSuggestionAction::class);
        $app->get  ('/api/bank-ai-suggestion-availability', [\MyInvoice\Action\Ai\BankAiSuggestionAction::class, 'availability']);
        // Protějšek pro doklady — účetní se u konkrétní faktury může zeptat a doplnit
        // souvislost, kterou z faktury není vidět (a která rozhoduje o nákladovém účtu).
        $app->post ('/api/purchase-invoices/{id:[0-9]+}/ai-suggest', \MyInvoice\Action\Ai\PurchaseAiSuggestionAction::class);
        $app->get  ('/api/purchase-ai-suggestion-availability', [\MyInvoice\Action\Ai\PurchaseAiSuggestionAction::class, 'availability']);

        // Dokumenty (sekce Dokumenty — plán source/11)
        // Specifické cesty PŘED {id:[0-9]+}, aby je fast-route nepohltil.
        $app->get   ('/api/document-folders',                     [FoldersAction::class, 'list']);
        $app->post  ('/api/document-folders',                     [FoldersAction::class, 'create']);
        $app->patch ('/api/document-folders/{id:[0-9]+}',         [FoldersAction::class, 'rename']);
        $app->post  ('/api/document-folders/{id:[0-9]+}/move',    [FoldersAction::class, 'move']);
        $app->post  ('/api/document-folders/{id:[0-9]+}/restore', [FoldersAction::class, 'restore']);
        $app->delete('/api/document-folders/{id:[0-9]+}',         [FoldersAction::class, 'delete']);

        $app->get   ('/api/documents/search',         [DocumentsAction::class, 'search']);
        $app->get   ('/api/documents/link-search',    LinkSearchAction::class);
        // Background joby (rozbalení ZIP importu / ZIP export)
        $app->post  ('/api/documents/zip-import',     [DocumentJobsAction::class, 'zipImport']);
        $app->post  ('/api/documents/export',         [DocumentJobsAction::class, 'export']);
        // Chunkovaný upload (obchází PHP post_max_size) — velký ZIP / složka / velký soubor
        $app->post  ('/api/documents/upload/start',       [DocumentJobsAction::class, 'uploadStart']);
        $app->post  ('/api/documents/upload/chunk-bytes', [DocumentJobsAction::class, 'uploadChunkBytes']);
        $app->post  ('/api/documents/upload/chunk-files', [DocumentJobsAction::class, 'uploadChunkFiles']);
        $app->post  ('/api/documents/upload/finish',      [DocumentJobsAction::class, 'uploadFinish']);
        $app->get   ('/api/documents/jobs',           [DocumentJobsAction::class, 'list']);
        $app->get   ('/api/documents/jobs/{id:[0-9]+}',          [DocumentJobsAction::class, 'status']);
        $app->get   ('/api/documents/jobs/{id:[0-9]+}/download', [DocumentJobsAction::class, 'download']);
        $app->post  ('/api/documents/jobs/{id:[0-9]+}/cancel',   [DocumentJobsAction::class, 'cancel']);
        $app->delete('/api/documents/jobs/{id:[0-9]+}',          [DocumentJobsAction::class, 'delete']);
        $app->get   ('/api/documents/tags',           [DocumentsAction::class, 'listTags']);
        $app->get   ('/api/documents/trash',          [DocumentsAction::class, 'trash']);
        $app->post  ('/api/documents/trash/empty',    [DocumentsAction::class, 'emptyTrash']);
        $app->post  ('/api/documents/bulk',           [DocumentsAction::class, 'bulk']);
        $app->get   ('/api/documents/bulk-download',  [DocumentFileAction::class, 'bulkDownload']);
        $app->get   ('/api/documents/by-entity/{type:[a-z_]+}/{id:[0-9]+}', [DocumentsAction::class, 'byEntity']);
        $app->get   ('/api/documents',                [DocumentsAction::class, 'list']);
        $app->post  ('/api/documents',                UploadDocumentAction::class);
        $app->get   ('/api/documents/{id:[0-9]+}/text',       [DocumentsAction::class, 'text']);
        $app->get   ('/api/documents/{id:[0-9]+}',            [DocumentsAction::class, 'get']);
        $app->patch ('/api/documents/{id:[0-9]+}',            [DocumentsAction::class, 'update']);
        $app->post  ('/api/documents/{id:[0-9]+}/move',       [DocumentsAction::class, 'move']);
        $app->post  ('/api/documents/{id:[0-9]+}/restore',    [DocumentsAction::class, 'restore']);
        $app->post  ('/api/documents/{id:[0-9]+}/links',      [DocumentsAction::class, 'addLink']);
        $app->delete('/api/documents/{id:[0-9]+}/links',      [DocumentsAction::class, 'removeLink']);
        $app->delete('/api/documents/{id:[0-9]+}',            [DocumentsAction::class, 'delete']);
        $app->get   ('/api/documents/{id:[0-9]+}/download',   [DocumentFileAction::class, 'download']);
        $app->get   ('/api/documents/{id:[0-9]+}/preview',    [DocumentFileAction::class, 'preview']);
        $app->get   ('/api/documents/{id:[0-9]+}/thumb',      [DocumentFileAction::class, 'thumb']);
        // N-souborů-na-doklad (Epic F7 §6) — primary + attachments. Per-file download
        // PŘED generickou /files patch/delete kvůli čitelnosti (FastRoute matchuje plnou cestu).
        $app->get   ('/api/documents/{id:[0-9]+}/files',                    [DocumentFilesAction::class, 'list']);
        $app->post  ('/api/documents/{id:[0-9]+}/files',                    [DocumentFilesAction::class, 'add']);
        $app->get   ('/api/documents/{id:[0-9]+}/files/{fileId:[0-9]+}/download', [DocumentFilesAction::class, 'download']);
        $app->patch ('/api/documents/{id:[0-9]+}/files/{fileId:[0-9]+}',    [DocumentFilesAction::class, 'patch']);
        $app->delete('/api/documents/{id:[0-9]+}/files/{fileId:[0-9]+}',    [DocumentFilesAction::class, 'delete']);

        // Kniha jízd (logbook) — auta, jízdy, tankování, kategorie cest
        $app->get   ('/api/logbook/cars',                 [\MyInvoice\Action\Logbook\CarsAction::class, 'list']);
        $app->post  ('/api/logbook/cars',                 [\MyInvoice\Action\Logbook\CarsAction::class, 'create']);
        $app->get   ('/api/logbook/cars/{id:[0-9]+}',     [\MyInvoice\Action\Logbook\CarsAction::class, 'get']);
        $app->put   ('/api/logbook/cars/{id:[0-9]+}',     [\MyInvoice\Action\Logbook\CarsAction::class, 'update']);
        $app->delete('/api/logbook/cars/{id:[0-9]+}',     [\MyInvoice\Action\Logbook\CarsAction::class, 'delete']);

        $app->get   ('/api/logbook/trip-categories',              [\MyInvoice\Action\Logbook\TripCategoriesAction::class, 'list']);
        $app->post  ('/api/logbook/trip-categories',              [\MyInvoice\Action\Logbook\TripCategoriesAction::class, 'create']);
        $app->put   ('/api/logbook/trip-categories/{id:[0-9]+}',  [\MyInvoice\Action\Logbook\TripCategoriesAction::class, 'update']);
        $app->delete('/api/logbook/trip-categories/{id:[0-9]+}',  [\MyInvoice\Action\Logbook\TripCategoriesAction::class, 'delete']);

        $app->post  ('/api/logbook/trips/import',         \MyInvoice\Action\Logbook\ImportTripsAction::class);
        $app->get   ('/api/logbook/trips/export',         \MyInvoice\Action\Logbook\ExportTripsAction::class);
        $app->get   ('/api/logbook/trips/purposes',       [\MyInvoice\Action\Logbook\TripsAction::class, 'purposes']);
        $app->get   ('/api/logbook/trips/places',         [\MyInvoice\Action\Logbook\TripsAction::class, 'places']);
        $app->get   ('/api/logbook/trips',                [\MyInvoice\Action\Logbook\TripsAction::class, 'list']);
        $app->post  ('/api/logbook/trips',                [\MyInvoice\Action\Logbook\TripsAction::class, 'create']);
        $app->get   ('/api/logbook/trips/{id:[0-9]+}',    [\MyInvoice\Action\Logbook\TripsAction::class, 'get']);
        $app->put   ('/api/logbook/trips/{id:[0-9]+}',    [\MyInvoice\Action\Logbook\TripsAction::class, 'update']);
        $app->delete('/api/logbook/trips/{id:[0-9]+}',    [\MyInvoice\Action\Logbook\TripsAction::class, 'delete']);

        $app->get   ('/api/logbook/fuelings/export',      \MyInvoice\Action\Logbook\ExportFuelingsAction::class);
        $app->get   ('/api/logbook/fuelings',             [\MyInvoice\Action\Logbook\FuelingsAction::class, 'list']);
        $app->post  ('/api/logbook/fuelings',             [\MyInvoice\Action\Logbook\FuelingsAction::class, 'create']);
        $app->get   ('/api/logbook/fuelings/{id:[0-9]+}', [\MyInvoice\Action\Logbook\FuelingsAction::class, 'get']);
        $app->put   ('/api/logbook/fuelings/{id:[0-9]+}', [\MyInvoice\Action\Logbook\FuelingsAction::class, 'update']);
        $app->delete('/api/logbook/fuelings/{id:[0-9]+}', [\MyInvoice\Action\Logbook\FuelingsAction::class, 'delete']);

        $app->get   ('/api/logbook/summary/export',       [\MyInvoice\Action\Logbook\SummaryAction::class, 'export']);
        $app->get   ('/api/logbook/summary',              [\MyInvoice\Action\Logbook\SummaryAction::class, 'view']);

        $app->post  ('/api/logbook/fuel-invoices/backfill',           [\MyInvoice\Action\Logbook\FuelInvoicesAction::class, 'backfill']);
        $app->get   ('/api/logbook/fuel-invoices',                    [\MyInvoice\Action\Logbook\FuelInvoicesAction::class, 'list']);
        $app->get   ('/api/logbook/fuel-invoices/{id:[0-9]+}/items',  [\MyInvoice\Action\Logbook\FuelInvoicesAction::class, 'items']);
        $app->post  ('/api/logbook/fuel-invoices/{id:[0-9]+}/assign', [\MyInvoice\Action\Logbook\FuelInvoicesAction::class, 'assign']);

        // F5: per-user UI stav — ukládané filtry a preference tabulek (všechny role vč. readonly)
        $app->get   ('/api/user/filters',                     [\MyInvoice\Action\UserSettings\SavedFilterAction::class, 'list']);
        $app->post  ('/api/user/filters',                     [\MyInvoice\Action\UserSettings\SavedFilterAction::class, 'create']);
        $app->put   ('/api/user/filters/{id:[0-9]+}',         [\MyInvoice\Action\UserSettings\SavedFilterAction::class, 'update']);
        $app->delete('/api/user/filters/{id:[0-9]+}',         [\MyInvoice\Action\UserSettings\SavedFilterAction::class, 'delete']);

        $app->get   ('/api/user/preferences',                 [\MyInvoice\Action\UserSettings\UserPreferenceAction::class, 'list']);
        $app->put   ('/api/user/preferences/{key:[a-z0-9_.]+}', [\MyInvoice\Action\UserSettings\UserPreferenceAction::class, 'put']);
        $app->delete('/api/user/preferences/{key:[a-z0-9_.]+}', [\MyInvoice\Action\UserSettings\UserPreferenceAction::class, 'delete']);

        // F5: Excel export/import číselníků (osnova, kontace, majetek)
        $app->group('/api/accounting', function ($g) {
            $g->get ('/accounts/export',       [\MyInvoice\Action\Accounting\Codebooks\ChartOfAccountsExportAction::class, 'export']);
            $g->get ('/posting-rules/export',  [\MyInvoice\Action\Accounting\Codebooks\PostingRulesExportAction::class,   'export']);
            $g->get ('/assets/export',         [\MyInvoice\Action\Accounting\Codebooks\AssetsExportAction::class,         'export']);
            $g->post('/accounts/import',       [\MyInvoice\Action\Accounting\Codebooks\ChartOfAccountsImportAction::class, 'import']);
            $g->post('/posting-rules/import',  [\MyInvoice\Action\Accounting\Codebooks\PostingRulesImportAction::class,   'import']);
            $g->post('/assets/import',         [\MyInvoice\Action\Accounting\Codebooks\AssetsImportAction::class,         'import']);
        });

        // Sklad (Epic SKLAD) — evidence zásob způsobem B (skladová kniha, ne účetní
        // zápisy). Vlastní top-level skupina (NE pod /api/accounting) — modul je
        // opt-in přes supplier.stock_enabled a má vlastní PermissionMiddleware pravidla.
        // Specifické cesty PŘED generickými /{id}.
        $app->group('/api/stock', function ($g) {
            $g->get   ('/warehouses',                   [\MyInvoice\Action\Stock\WarehouseAction::class, 'list']);
            $g->post  ('/warehouses',                   [\MyInvoice\Action\Stock\WarehouseAction::class, 'create']);
            $g->get   ('/warehouses/{id:[0-9]+}',       [\MyInvoice\Action\Stock\WarehouseAction::class, 'get']);
            $g->put   ('/warehouses/{id:[0-9]+}',       [\MyInvoice\Action\Stock\WarehouseAction::class, 'update']);
            $g->delete('/warehouses/{id:[0-9]+}',       [\MyInvoice\Action\Stock\WarehouseAction::class, 'delete']);

            $g->get   ('/items/search',                 [\MyInvoice\Action\Stock\StockItemAction::class, 'search']);
            $g->get   ('/items',                        [\MyInvoice\Action\Stock\StockItemAction::class, 'list']);
            $g->post  ('/items',                        [\MyInvoice\Action\Stock\StockItemAction::class, 'create']);
            $g->get   ('/items/{id:[0-9]+}',            [\MyInvoice\Action\Stock\StockItemAction::class, 'get']);
            $g->put   ('/items/{id:[0-9]+}',            [\MyInvoice\Action\Stock\StockItemAction::class, 'update']);
            $g->delete('/items/{id:[0-9]+}',            [\MyInvoice\Action\Stock\StockItemAction::class, 'delete']);
            $g->get   ('/items/{id:[0-9]+}/movements',        [\MyInvoice\Action\Stock\StockItemAction::class, 'movements']);
            $g->get   ('/items/{id:[0-9]+}/movements/export', [\MyInvoice\Action\Stock\StockItemAction::class, 'movementsExport']);

            $g->get   ('/levels',                       [\MyInvoice\Action\Stock\StockLevelAction::class, 'levels']);
            $g->get   ('/availability',                 [\MyInvoice\Action\Stock\StockLevelAction::class, 'availability']);

            // „U dodavatele" (fáze 3) — nabídky nad stock_item_vendors.
            // /import je literální cesta, musí být PŘED generickým /{id}.
            $g->post  ('/vendor-offers/import',         [\MyInvoice\Action\Stock\VendorOfferImportAction::class, 'import']);
            $g->get   ('/vendor-offers',                [\MyInvoice\Action\Stock\VendorOfferAction::class, 'list']);
            $g->post  ('/vendor-offers',                [\MyInvoice\Action\Stock\VendorOfferAction::class, 'create']);
            $g->get   ('/vendor-offers/{id:[0-9]+}',    [\MyInvoice\Action\Stock\VendorOfferAction::class, 'get']);
            $g->patch ('/vendor-offers/{id:[0-9]+}',    [\MyInvoice\Action\Stock\VendorOfferAction::class, 'patch']);
            $g->delete('/vendor-offers/{id:[0-9]+}',    [\MyInvoice\Action\Stock\VendorOfferAction::class, 'delete']);

            // Tři dimenze množství pohromadě (fáze 2) — skladem / rezervováno /
            // na cestě / u dodavatele. Karta bez jediného pohybu musí vrátit nuly,
            // ne prázdno (rozhodnutí #12). Stávající /availability zůstává beze změny.
            $g->get   ('/quantities',                   [\MyInvoice\Action\Stock\StockQuantityAction::class, 'quantities']);
            $g->get   ('/in-transit',                   [\MyInvoice\Action\Stock\StockQuantityAction::class, 'inTransit']);
            $g->get   ('/reservations',                 [\MyInvoice\Action\Stock\StockQuantityAction::class, 'reservations']);
            $g->get   ('/replenishment',                [\MyInvoice\Action\Stock\StockQuantityAction::class, 'replenishment']);

            // Objednávky dodavatelům (fáze 1) — literální /bulk PŘED generickým /{id}.
            $g->post  ('/purchase-orders/bulk',                    [\MyInvoice\Action\Stock\PurchaseOrderBulkAction::class, 'create']);
            $g->get   ('/purchase-orders',                         [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'list']);
            $g->post  ('/purchase-orders',                         [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'create']);
            $g->get   ('/purchase-orders/{id:[0-9]+}',             [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'get']);
            $g->put   ('/purchase-orders/{id:[0-9]+}',             [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'update']);
            $g->delete('/purchase-orders/{id:[0-9]+}',             [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'delete']);
            $g->post  ('/purchase-orders/{id:[0-9]+}/send',        [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'send']);
            $g->post  ('/purchase-orders/{id:[0-9]+}/confirm',     [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'confirm']);
            $g->post  ('/purchase-orders/{id:[0-9]+}/cancel',      [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'cancel']);
            $g->post  ('/purchase-orders/{id:[0-9]+}/close',       [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'close']);
            $g->post  ('/purchase-orders/{id:[0-9]+}/reopen',      [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'reopen']);
            $g->get   ('/purchase-orders/{id:[0-9]+}/pdf',         [\MyInvoice\Action\Stock\PurchaseOrderAction::class, 'pdf']);
            // Příjem z objednávky (fáze 2) — zakládá DRAFT stock_documents
            // s origin='purchase_order'. Zaúčtování zůstává na existujícím
            // POST /api/stock/documents/{id}/post, žádný paralelní post endpoint.
            $g->get   ('/purchase-orders/{id:[0-9]+}/receipt',     [\MyInvoice\Action\Stock\PurchaseOrderReceiptAction::class, 'propose']);
            $g->post  ('/purchase-orders/{id:[0-9]+}/receipt',     [\MyInvoice\Action\Stock\PurchaseOrderReceiptAction::class, 'create']);
            $g->get   ('/purchase-orders/{id:[0-9]+}/receipts',    [\MyInvoice\Action\Stock\PurchaseOrderReceiptAction::class, 'list']);

            $g->get   ('/documents',                    [\MyInvoice\Action\Stock\StockDocumentAction::class, 'list']);
            $g->post  ('/documents',                    [\MyInvoice\Action\Stock\StockDocumentAction::class, 'create']);
            $g->get   ('/documents/{id:[0-9]+}',        [\MyInvoice\Action\Stock\StockDocumentAction::class, 'get']);
            $g->put   ('/documents/{id:[0-9]+}',        [\MyInvoice\Action\Stock\StockDocumentAction::class, 'update']);
            $g->delete('/documents/{id:[0-9]+}',        [\MyInvoice\Action\Stock\StockDocumentAction::class, 'delete']);
            $g->post  ('/documents/{id:[0-9]+}/post',   [\MyInvoice\Action\Stock\StockDocumentAction::class, 'post']);
            $g->post  ('/documents/{id:[0-9]+}/reverse',[\MyInvoice\Action\Stock\StockDocumentAction::class, 'reverse']);
            $g->get   ('/documents/{id:[0-9]+}/pdf',    [\MyInvoice\Action\Stock\StockDocumentAction::class, 'pdf']);

            // Inventury (§7.2 TakeWizard) — specifické cesty před generickým /{id}.
            $g->get   ('/takes',                        [\MyInvoice\Action\Stock\StockTakeAction::class, 'list']);
            $g->post  ('/takes',                        [\MyInvoice\Action\Stock\StockTakeAction::class, 'create']);
            $g->get   ('/takes/{id:[0-9]+}',            [\MyInvoice\Action\Stock\StockTakeAction::class, 'get']);
            $g->put   ('/takes/{id:[0-9]+}',            [\MyInvoice\Action\Stock\StockTakeAction::class, 'update']);
            $g->post  ('/takes/{id:[0-9]+}/start',      [\MyInvoice\Action\Stock\StockTakeAction::class, 'start']);
            $g->post  ('/takes/{id:[0-9]+}/close',      [\MyInvoice\Action\Stock\StockTakeAction::class, 'close']);
            $g->get   ('/takes/{id:[0-9]+}/pdf',        [\MyInvoice\Action\Stock\StockTakeAction::class, 'pdf']);

            // Sestavy (§6) — /export s literálním jménem PŘED generickým /{name}? zde
            // name je vždy poslední segment /reports/{name}/export, konflikt nehrozí.
            $g->get   ('/reports/status',                [\MyInvoice\Action\Stock\StockReportAction::class, 'status']);
            $g->get   ('/reports/valuation',              [\MyInvoice\Action\Stock\StockReportAction::class, 'valuation']);
            $g->get   ('/reports/{name}/export',          [\MyInvoice\Action\Stock\StockReportAction::class, 'export']);
        });

        // Eshop (Epic ESHOP) — karta Zboží nad stock_items (item_type='goods') +
        // číselníky (výrobci, kategorie strom, atributy, tagy, poplatky) + média.
        // Vlastní top-level skupina; opt-in přes stock_enabled (GuardsStockEnabled),
        // PermissionMiddleware pravidla pro ^/api/eshop. Specifické cesty PŘED generickými /{id}.
        $app->group('/api/eshop', function ($g) {
            // Výrobci
            $g->get   ('/manufacturers',                 [\MyInvoice\Action\Eshop\ManufacturerAction::class, 'list']);
            $g->post  ('/manufacturers',                 [\MyInvoice\Action\Eshop\ManufacturerAction::class, 'create']);
            $g->get   ('/manufacturers/{id:[0-9]+}',     [\MyInvoice\Action\Eshop\ManufacturerAction::class, 'get']);
            $g->put   ('/manufacturers/{id:[0-9]+}',     [\MyInvoice\Action\Eshop\ManufacturerAction::class, 'update']);
            $g->delete('/manufacturers/{id:[0-9]+}',     [\MyInvoice\Action\Eshop\ManufacturerAction::class, 'delete']);

            // Jazyky (číselník jazykových mutací karty a kategorií)
            $g->get   ('/locales',                       [\MyInvoice\Action\Eshop\LocaleAction::class, 'list']);
            $g->post  ('/locales',                       [\MyInvoice\Action\Eshop\LocaleAction::class, 'create']);
            $g->get   ('/locales/{id:[0-9]+}',           [\MyInvoice\Action\Eshop\LocaleAction::class, 'get']);
            $g->put   ('/locales/{id:[0-9]+}',           [\MyInvoice\Action\Eshop\LocaleAction::class, 'update']);
            $g->delete('/locales/{id:[0-9]+}',           [\MyInvoice\Action\Eshop\LocaleAction::class, 'delete']);

            // Prodejní měny (číselník cen a akčních cen; NE měnové účty z /settings/currencies)
            $g->get   ('/currencies',                    [\MyInvoice\Action\Eshop\CurrencyAction::class, 'list']);
            $g->post  ('/currencies',                    [\MyInvoice\Action\Eshop\CurrencyAction::class, 'create']);
            $g->get   ('/currencies/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\CurrencyAction::class, 'get']);
            $g->put   ('/currencies/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\CurrencyAction::class, 'update']);
            $g->delete('/currencies/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\CurrencyAction::class, 'delete']);

            // Štítky
            $g->get   ('/tags',                          [\MyInvoice\Action\Eshop\StockTagAction::class, 'list']);
            $g->post  ('/tags',                          [\MyInvoice\Action\Eshop\StockTagAction::class, 'create']);
            $g->get   ('/tags/{id:[0-9]+}',              [\MyInvoice\Action\Eshop\StockTagAction::class, 'get']);
            $g->put   ('/tags/{id:[0-9]+}',              [\MyInvoice\Action\Eshop\StockTagAction::class, 'update']);
            $g->delete('/tags/{id:[0-9]+}',              [\MyInvoice\Action\Eshop\StockTagAction::class, 'delete']);

            // Poplatky
            $g->get   ('/fee-types',                     [\MyInvoice\Action\Eshop\FeeTypeAction::class, 'list']);
            $g->post  ('/fee-types',                     [\MyInvoice\Action\Eshop\FeeTypeAction::class, 'create']);
            $g->get   ('/fee-types/{id:[0-9]+}',         [\MyInvoice\Action\Eshop\FeeTypeAction::class, 'get']);
            $g->put   ('/fee-types/{id:[0-9]+}',         [\MyInvoice\Action\Eshop\FeeTypeAction::class, 'update']);
            $g->delete('/fee-types/{id:[0-9]+}',         [\MyInvoice\Action\Eshop\FeeTypeAction::class, 'delete']);

            // Parametry/atributy (+ enum options); specifické PŘED generickými.
            $g->get   ('/attributes',                    [\MyInvoice\Action\Eshop\AttributeAction::class, 'list']);
            $g->post  ('/attributes',                    [\MyInvoice\Action\Eshop\AttributeAction::class, 'create']);
            $g->get   ('/attributes/{id:[0-9]+}/options',[\MyInvoice\Action\Eshop\AttributeAction::class, 'listOptions']);
            $g->post  ('/attributes/{id:[0-9]+}/options',[\MyInvoice\Action\Eshop\AttributeAction::class, 'createOption']);
            $g->get   ('/attributes/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\AttributeAction::class, 'get']);
            $g->put   ('/attributes/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\AttributeAction::class, 'update']);
            $g->delete('/attributes/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\AttributeAction::class, 'delete']);
            $g->put   ('/attribute-options/{oid:[0-9]+}',[\MyInvoice\Action\Eshop\AttributeAction::class, 'updateOption']);
            $g->delete('/attribute-options/{oid:[0-9]+}',[\MyInvoice\Action\Eshop\AttributeAction::class, 'deleteOption']);

            // Kategorie — strom (materialized path) + i18n; specifické PŘED generickými.
            $g->get   ('/categories',                    [\MyInvoice\Action\Eshop\CategoryAction::class, 'list']);
            $g->post  ('/categories',                    [\MyInvoice\Action\Eshop\CategoryAction::class, 'create']);
            $g->get   ('/categories/{id:[0-9]+}/i18n',   [\MyInvoice\Action\Eshop\CategoryAction::class, 'getI18n']);
            $g->put   ('/categories/{id:[0-9]+}/i18n',   [\MyInvoice\Action\Eshop\CategoryAction::class, 'putI18n']);
            $g->post  ('/categories/{id:[0-9]+}/move',   [\MyInvoice\Action\Eshop\CategoryAction::class, 'move']);
            $g->get   ('/categories/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\CategoryAction::class, 'get']);
            $g->put   ('/categories/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\CategoryAction::class, 'update']);
            $g->delete('/categories/{id:[0-9]+}',        [\MyInvoice\Action\Eshop\CategoryAction::class, 'delete']);

            // Import zboží (XLS/CSV) — literální cesta PŘED generickými /products/{id}.
            $g->post  ('/products/import',                     [\MyInvoice\Action\Eshop\ProductImportAction::class, 'import']);

            // Karta Zboží (agregát) + média; specifické PŘED generickými.
            $g->get   ('/products/{id:[0-9]+}/i18n',           [\MyInvoice\Action\Eshop\ProductCardAction::class, 'getI18n']);
            $g->get   ('/products/{id:[0-9]+}/media',          [\MyInvoice\Action\Eshop\ProductMediaAction::class, 'list']);
            $g->post  ('/products/{id:[0-9]+}/media',          [\MyInvoice\Action\Eshop\ProductMediaAction::class, 'upload']);
            $g->put   ('/products/{id:[0-9]+}/media/reorder',  [\MyInvoice\Action\Eshop\ProductMediaAction::class, 'reorder']);
            // Cenotvorba + dodavatelé (F2)
            $g->get   ('/products/{id:[0-9]+}/prices',            [\MyInvoice\Action\Eshop\ProductPriceAction::class, 'get']);
            $g->put   ('/products/{id:[0-9]+}/prices',            [\MyInvoice\Action\Eshop\ProductPriceAction::class, 'put']);
            $g->post  ('/products/{id:[0-9]+}/prices/recompute',  [\MyInvoice\Action\Eshop\ProductPriceAction::class, 'recompute']);
            // Akční (promoční) ceny — dočasný override nad cenotvorbou (migrace 1328)
            $g->get   ('/products/{id:[0-9]+}/promo-prices',       [\MyInvoice\Action\Eshop\ProductPromoPriceAction::class, 'get']);
            $g->put   ('/products/{id:[0-9]+}/promo-prices',       [\MyInvoice\Action\Eshop\ProductPromoPriceAction::class, 'put']);
            $g->get   ('/products/{id:[0-9]+}/effective-price',    [\MyInvoice\Action\Eshop\ProductPromoPriceAction::class, 'effective']);
            $g->get   ('/products/{id:[0-9]+}/vendors',           [\MyInvoice\Action\Eshop\ProductVendorAction::class, 'get']);
            $g->put   ('/products/{id:[0-9]+}/vendors',           [\MyInvoice\Action\Eshop\ProductVendorAction::class, 'put']);
            $g->get   ('/products/{id:[0-9]+}',                [\MyInvoice\Action\Eshop\ProductCardAction::class, 'get']);
            $g->put   ('/products/{id:[0-9]+}',                [\MyInvoice\Action\Eshop\ProductCardAction::class, 'update']);

            // Média podle id přílohy (serve bajtů PŘED generickým /{mid}).
            $g->get   ('/media/{mid:[0-9]+}/file',       [\MyInvoice\Action\Eshop\ProductMediaAction::class, 'file']);
            $g->put   ('/media/{mid:[0-9]+}',            [\MyInvoice\Action\Eshop\ProductMediaAction::class, 'update']);
            $g->delete('/media/{mid:[0-9]+}',            [\MyInvoice\Action\Eshop\ProductMediaAction::class, 'delete']);
        });

        // Daňová evidence OSVČ (Epic DE) — vlastní top-level skupina (NE pod
        // /api/accounting, R1). READ-ONLY reporting: peněžní deník (kasová báze §7b)
        // a pohledávky/závazky (aging reuse CRM). GET = readonly+; klient DENY
        // (PermissionMiddleware). Specifické /export cesty PŘED generickými.
        $app->group('/api/tax-evidence', function ($g) {
            $g->get('/cash-journal',                 [\MyInvoice\Action\TaxEvidence\CashJournalAction::class, 'get']);
            $g->get('/cash-journal/export',          [\MyInvoice\Action\TaxEvidence\CashJournalAction::class, 'export']);
            $g->get('/closing/{year:[0-9]+}',        [\MyInvoice\Action\TaxEvidence\AnnualClosingAction::class, 'get']);
            $g->put('/closing/{year:[0-9]+}',        [\MyInvoice\Action\TaxEvidence\AnnualClosingAction::class, 'save']);
            $g->post('/closing/{year:[0-9]+}/finalize', [\MyInvoice\Action\TaxEvidence\AnnualClosingAction::class, 'finalize']);
            $g->post('/closing/{year:[0-9]+}/reopen', [\MyInvoice\Action\TaxEvidence\AnnualClosingAction::class, 'reopen']);
            $g->get('/receivables-payables',         [\MyInvoice\Action\TaxEvidence\ReceivablesPayablesAction::class, 'get']);
            $g->get('/receivables-payables/export',  [\MyInvoice\Action\TaxEvidence\ReceivablesPayablesAction::class, 'export']);
            // G2 (audit 2026-07) — ruční klasifikační override pohybu (migrace 1027).
            // Zápis = účetní|admin (route permission rules); GET/write obojí kryje CLIENT_DENY výše.
            $g->post  ('/classification',                                       [\MyInvoice\Action\TaxEvidence\MovementClassificationAction::class, 'create']);
            $g->delete('/classification/{source_type:bank|cash}/{source_id:[0-9]+}', [\MyInvoice\Action\TaxEvidence\MovementClassificationAction::class, 'delete']);
            // G7 (audit 2026-07) — podklady pro přechodový můstek §7b→§24 ZDP (příloha č. 3).
            $g->get('/transition-report',            [\MyInvoice\Action\TaxEvidence\TransitionReportAction::class, 'get']);
        });

        // Epic DP (issue #18) — přiznání k dani z příjmů (DPPO/DPFO). Nové routy,
        // staré /api/reports/income-tax* zůstávají (upstream kompatibilita).
        $app->group('/api/tax-return', function ($g) {
            $g->get('/{type}/{year:[0-9]+}',           [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'get']);
            $g->put('/{type}/{year:[0-9]+}/inputs',    [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'putInputs']);
            $g->post('/{type}/{year:[0-9]+}/finalize', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'finalize']);
            $g->post('/{type}/{year:[0-9]+}/reopen',   [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'reopen']);
            // E10 (audit 2026-07) — předfinalizační kontrolní checklist („závěrková kontrola").
            $g->get('/{type}/{year:[0-9]+}/prefinalize-check', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'prefinalizeCheck']);
            $g->get('/{type}/{year:[0-9]+}/xml/preview', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'previewXml']);
            $g->get('/{type}/{year:[0-9]+}/xml',       [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'xml']);
            // Featura A — rekonciliace proti PODANÉMU přiznání (upload EPO XML DPPDP9 od účetní).
            $g->post('/{type}/{year:[0-9]+}/reconcile', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'reconcile']);
            $g->get('/{type}/{year:[0-9]+}/insurance/pdf', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'insurancePdf']);
            // E11 (audit 2026-07): PDF Přehled OSVČ pro zdravotní pojišťovnu.
            $g->get('/{type}/{year:[0-9]+}/insurance/pdf/health', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'healthPdf']);
            // DP v2 fáze 3 (issue #19): XML přehledu OSVČ pro ČSSZ (sociální pojištění).
            $g->get('/{type}/{year:[0-9]+}/insurance/xml/cssz', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'csszXml']);
            $g->get('/{type}/{year:[0-9]+}/insurance', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'insurance']);
            // E9 (audit 2026-07): předpisy záloh na daň a pojistné + párování s bankou.
            $g->get('/advances/upcoming',              [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'upcomingAdvances']);
            $g->get('/{type}/{year:[0-9]+}/advances',  [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'advanceSchedules']);
            $g->post('/{type}/{year:[0-9]+}/advances/generate', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'generateAdvances']);
            $g->post('/{type}/{year:[0-9]+}/advances/match',    [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'matchAdvances']);
            // #42 — vygenerovat předpisy PRO tento rok (z draftu min. roku / z rozhodnutí FÚ).
            $g->post('/{type}/{year:[0-9]+}/advances/generate-period', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'generateAdvancesForPeriod']);
            // #43 — rozhodnutí FÚ o výši záloh §174 (override) + ruční potvrzení úhrad.
            // Per-rok override (#43, /advances/override) odstraněn — nahrazen id-based
            // CRUD s rozsahem OD-DO (#46, /advances/overrides níže).
            // #46 — rozhodnutí FÚ s rozsahem OD-DO: id-based CRUD napříč roky (globální tabulka).
            $g->get('/{type}/{year:[0-9]+}/advances/overrides',                    [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'advanceOverrides']);
            $g->post('/{type}/{year:[0-9]+}/advances/overrides',                   [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'createAdvanceOverride']);
            $g->put('/{type}/{year:[0-9]+}/advances/overrides/{overrideId:[0-9]+}',    [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'updateAdvanceOverride']);
            $g->delete('/{type}/{year:[0-9]+}/advances/overrides/{overrideId:[0-9]+}', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'deleteAdvanceOverrideEntry']);
            $g->post('/{type}/{year:[0-9]+}/advances/confirm-all', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'confirmAllAdvances']);
            $g->post('/{type}/{year:[0-9]+}/advances/{scheduleId:[0-9]+}/amount',    [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'updateAdvanceAmount']);
            $g->post('/{type}/{year:[0-9]+}/advances/{scheduleId:[0-9]+}/confirm',   [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'confirmAdvance']);
            $g->post('/{type}/{year:[0-9]+}/advances/{scheduleId:[0-9]+}/unconfirm', [\MyInvoice\Action\Tax\Return\TaxReturnAction::class, 'unconfirmAdvance']);
        });

        // 404 fallback pro /api/*
        $app->any('/api/{path:.*}', function ($req, $res) {
            return \MyInvoice\Http\Json::error($res, 'not_found', 'Route not found', 404);
        });
    }
}
