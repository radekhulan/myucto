import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { availableParallelism } from 'node:os'

const nodeTests = [
  'src/utils/__tests__/{accrualPeriod,extractionWarning,groupDashboard,integrationEditor,integrationWebhook,journalPairs,journalSourceLink,netTurnoverRows,productSetSelections,rowAutoCode,stockSearchMatch}.spec.ts',
  'src/utils/czechBirthNumber.spec.ts',
  'src/pages/payroll/__tests__/{healthPaymentStatus,jmhzBlockerRemediation,payrollInputFilters,payrollRemediation,personCardSave,registrationLabels}.spec.ts',
  'src/pages/invoices/__tests__/{invoiceDefaultNote,invoiceStockAvailability,invoiceStockPricing}.spec.ts',
  'src/pages/stock/__tests__/{fulfillmentTracking,itemPriceLevelRules}.spec.ts',
  'src/components/payroll/__tests__/statutoryBulkDefaults.spec.ts',
  'src/components/payroll/imports/__tests__/{attendanceWages,pohodaOicHelpers}.spec.ts',
  'src/composables/__tests__/{useBankTransactionSort,useProductionSendConfirm,useTaxEngine}.spec.ts',
  'src/components/layout/__tests__/stickyBottomBarsAboveFooter.spec.ts',
  'src/pages/admin/__tests__/{InvoiceCounterNextNumber,PurchaseImportReceivedDate}.spec.ts',
  'src/security/__tests__/totpEnrollment.spec.ts',
  'src/utils/__tests__/{accountingSetupDependencies,bankConnectionAccount,bankConnectionError,chartAccountOptions,clientPlatform,date,dateInput,epoAttemptState,epoHandoffCache,healthInsurers,navigationLayout,periodDefaultYear,returnPath,safeUrl,slugifyCode,varsymbol}.spec.ts',
  'src/pages/payroll/__tests__/{employmentLifecycleUi,enforcementEvidenceScope,payrollAgendaLinks,payrollComponentsUi,payrollEmployerAccounts,payrollTime,payrollTimeGrid,statutoryEvidenceForm}.spec.ts',
  'src/api/__tests__/{hostingActions,instanceAlert,instanceHealth,instancePreview,payrollYearClosed,storageQuota,storageQuotaSticky}.spec.ts',
  'src/components/layout/__tests__/{dataBoxNav,hostingNavGating,payrollNavOrder,permissionNav,quickCreateEmployee}.spec.ts',
  'src/components/settings/__tests__/bankRuleTemplatePermissions.spec.ts',
  'src/pages/admin/__tests__/{DataBoxAccessAndTheme,rolesFixedPresets}.spec.ts',
  'src/pages/hosting/__tests__/HostingEnvironmentGuard.spec.ts',
  'src/config/__tests__/manualChapters.spec.ts',
  'src/workspace/__tests__/panelSizing.spec.ts',
  'src/composables/__tests__/usePayrollLabels.spec.ts',
  'src/components/bank/__tests__/bankTranslations.spec.ts',
  'src/api/__tests__/{integrationsOllama,journalFilters,kbPlusOnboarding,moneyS3,payrollBankSubmissions,payrollEnforcement,payrollRegistration,pohoda,premier,productMasters,stereoNx}.spec.ts',
  'src/components/payroll/imports/__tests__/attendanceSourceChecks.spec.ts',
  'src/composables/__tests__/{bankStatementRefresh,useSupplierSwitch,useTableColors}.spec.ts',
  'src/config/__tests__/payrollManualChapters.spec.ts',
  'src/pages/company/__tests__/{creditCardImport,creditCardWork}.spec.ts',
  'src/utils/__tests__/{kbPlusOnboarding,purchaseApproval}.spec.ts',
  'src/workspace/__tests__/panelShortcuts.spec.ts',
]

const jsdomTests = [
  'src/workspace/__tests__/windowFrameShortcuts.spec.ts',
  'src/directives/__tests__/columnLabels.spec.ts',
  'src/composables/__tests__/{useAdaptiveTableRows,useKeyboardShortcuts}.spec.ts',
  'src/pages/activation/__tests__/ZakoupeniResume.spec.ts',
  'src/components/bank/__tests__/PayrollBankPaymentSubmission.spec.ts',
  'src/pages/payroll/__tests__/{EmploymentCard,EnforcementCases,EnforcementTerminationNoticePanel,PayrollExternalJmhzSubmissionsPanel}.spec.ts',
]

const nativeDomTests = [
  'src/utils/downloadFile.spec.ts',
  'src/utils/__tests__/mergeDocuments.spec.ts',
  'src/workspace/__tests__/{windowManager,frameSurface}.spec.ts',
  'src/pages/accounting/__tests__/{CashDocumentEditor,JournalFocus,JournalDimensionFilter,GeneralLedger}.spec.ts',
  'src/components/workspace/__tests__/{WorkspaceWindowSwitcher,WorkspaceFavorites}.spec.ts',
  'src/pages/tax-evidence/__tests__/CashJournal.spec.ts',
  'src/components/accounting/__tests__/SecuritiesDebt504Workspace.spec.ts',
  'src/components/accounting/membership/__tests__/MembershipWorkspace.spec.ts',
  'src/api/__tests__/{payrollRegzel,payrollDocuments}.spec.ts',
  'src/pages/documents/__tests__/ScanAttach.spec.ts',
  'src/pages/clients/__tests__/{ClientForm.emailFormat,ClientForm.validation}.spec.ts',
  'src/pages/payroll/__tests__/{HealthInsurerAccounts,PayrollPayments,PayrollPersonStatutoryEvidencePanel,TimeAttendance}.spec.ts',
  'src/components/payroll/__tests__/{PayrollFileDropzoneInteraction,PayrollRiskySavingsPanel}.spec.ts',
  'src/components/property/settlements/__tests__/{SettlementDossier,SettlementCompositeCost,PropertySettlements}.spec.ts',
  'src/components/property/payments/receivables/__tests__/{PartySaldoPanel,SaldoWorkflow}.spec.ts',
]

// Časové pásmo se pinuje ještě před startem workerů: `formatUtcDateTime`
// převádí UTC sloupce do pásma stroje, takže bez tohohle by tytéž testy prošly
// na vývojářském Windows (Praha) a spadly v CI (UTC).
process.env.TZ = 'Europe/Prague'

// Samostatná konfigurace pro testy — nedědí server proxy / tailwind z vite.config.ts,
// jen vue plugin (pro .vue SFC) + alias `@` → src (shodně s vite.config a tsconfig).
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@shared': fileURLToPath(new URL('./shared', import.meta.url)),
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    globals: true,
    env: { TZ: 'Europe/Prague' },
    isolate: true,
    pool: 'threads',
    fileParallelism: true,
    maxWorkers: Math.min(8, availableParallelism()),
    maxConcurrency: 1,
    projects: [
      { extends: true, test: { name: 'node', environment: 'node', pool: 'vmThreads', vmMemoryLimit: '1GB', include: nodeTests } },
      {
        extends: true,
        test: { name: 'native-dom', environment: 'happy-dom', pool: 'threads', setupFiles: ['./tests/setup-dom.ts'], include: nativeDomTests },
      },
      {
        extends: true,
        test: { name: 'browser-api', environment: 'jsdom', pool: 'threads', setupFiles: ['./tests/setup-dom.ts'], include: jsdomTests },
      },
      {
        extends: true,
        test: {
          name: 'dom',
          environment: 'happy-dom',
          pool: 'vmThreads',
          vmMemoryLimit: '1GB',
          setupFiles: ['./tests/setup-dom.ts'],
          include: ['src/**/*.{test,spec}.ts'],
          exclude: [...nodeTests, ...jsdomTests, ...nativeDomTests],
        },
      },
    ],
  },
})
