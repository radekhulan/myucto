import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import PohodaMigration from '../PohodaMigration.vue'
import PayrollPostingImport from '@/components/migration/PayrollPostingImport.vue'

const m = vi.hoisted(() => ({ wizard: null as any, preview: vi.fn(), apply: vi.fn() }))

vi.mock('@/composables/useMigrationWizard', () => ({ useMigrationWizard: () => m.wizard }))
vi.mock('@/api/pohoda', () => ({ pohodaApi: {}, isPohodaUploadReady: () => true }))
vi.mock('@/api/pamicaPosting', () => ({
  NOT_PAYROLL_POSTING: 'not_payroll_posting',
  payrollPostingImportsApi: { preview: m.preview, apply: m.apply },
}))
vi.mock('@/composables/useToast',() => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { ic: '87654321', company_name: 'Syntetická firma' } }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MoneyS3Protocol.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/exchange/ImportJobProgress.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/ActionBar.vue', () => ({ default: {
  props: ['actions'],
  template: '<div><button v-for="a in actions.filter(x => x.show === undefined || x.show)" :key="a.key" :data-testid="`action-${a.key}`" :disabled="a.disabled" @click="a.run && a.run()">{{ a.label }}</button></div>',
} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, params?: Record<string, unknown>) => params && Object.keys(params).length ? `${key} ${JSON.stringify(params)}` : key,
  te: () => false, tm: () => [], rt: (value: string) => value,
}) }))

function preview(overrides: Record<string, unknown> = {}) {
  return {
    format: 'pamica_payroll_posting',
    source: { application: 'PAMICA', ico: '87654321', pack_id: '00042', sym_par: 'MZ209903', period: '2099-03', accounting_date: '2099-03-31', document_date: '2099-04-15' },
    internal_documents: [{ item_id: '001', title: 'Mzdy 2099/03', document_date: '2099-04-15', accounting_date: '2099-03-31', amount: 30000,
      lines: [{ text: 'Hrubá mzda', note: null, debit: '521000', credit: '331000', without_posting: false, amount: 30000 }] }],
    commitments: [{ item_id: '005', title: 'Mzda zaměstnance', partner_name: 'Testovací Jana', partner_kind: 'person', variable_symbol: '209903',
      constant_symbol: '0138', document_date: '2099-04-15', accounting_date: '2099-03-31', due_on: '2099-04-20', payment_type: 'draft',
      account: '1000000005/0100', account_error: null, posting: 'without_posting', credit: '331000', lines: [], amount: 12000 }],
    accounts: [{ source: '336001', synthetic: '336', target: null, proposed: '336.001', status: 'creatable' }],
    totals: { internal: 30000, commitments_posted: 0, commitments_without_posting: 12000, commitments: 12000 },
    checks: [
      { code: 'ico_match', level: 'ok', message: 'IČO sedí', context: {} },
      { code: 'accounts_creatable', level: 'error', message: 'Chybí analytiky', context: {} },
    ],
    can_apply: false, can_apply_with_new_accounts: true, can_write: true, can_create_accounts: true,
    ...overrides,
  }
}

function wizard(file: File | null) {
  return {
    currentStep: ref(1), file: ref(file), job: ref(null), jobMode: ref(null), run: ref(null), jobRuns: ref([]), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), processingProgress: ref(null), processingSlow: ref(false), loadError: ref(null), deletingRun: ref(null),
    jobRunning: ref(false), jobSucceeded: ref(false), percent: ref(0),
    differencesAcceptable: ref(false), differences: ref([]), acceptDifferences: ref(false), canImport: ref(false), clearDifferences: () => {},
    upload: ref(null),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(), retryUpload: vi.fn(), abandonUpload: vi.fn(),
    start: vi.fn(), cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

const stubs = { RouterLink: { props: ['to'], template: '<a><slot /></a>' } }

beforeEach(() => {
  vi.clearAllMocks()
})

describe('Průvodce PAMICA: zaúčtování mezd z XML', () => {
  it('XML se zaúčtováním mezd ukáže náhled místo převodu mezd', async () => {
    m.preview.mockResolvedValue(preview())
    m.wizard = wizard(new File(['<xml/>'], 'mzdy.xml', { type: 'application/xml' }))
    const wrapper = mount(PohodaMigration, { props: { system: 'pamica' }, global: { stubs } })
    await wrapper.get('[data-testid="action-upload"]').trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledTimes(1)
    expect(m.wizard.doUpload).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="payroll-posting-import"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="payroll-posting-commitment-005"]').text()).toContain('payroll_posting_import.without_posting')
    expect(wrapper.find('[data-testid="action-upload"]').exists()).toBe(false)
  })

  it('XML, které zaúčtováním mezd není, pokračuje převodem mezd', async () => {
    m.preview.mockRejectedValue({ response: { status: 422, data: { error: { code: 'not_payroll_posting', message: 'jiný formát' } } } })
    m.wizard = wizard(new File(['<xml/>'], '91_mzdy.xml', { type: 'application/xml' }))
    const wrapper = mount(PohodaMigration, { props: { system: 'pamica' }, global: { stubs } })
    await wrapper.get('[data-testid="action-upload"]').trigger('click')
    await flushPromises()

    expect(m.wizard.doUpload).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="payroll-posting-import"]').exists()).toBe(false)
  })

  it('ZIP exportu jde rovnou do převodu bez dotazu na zaúčtování', async () => {
    m.wizard = wizard(new File(['PK'], 'pamica.zip', { type: 'application/zip' }))
    const wrapper = mount(PohodaMigration, { props: { system: 'pamica' }, global: { stubs } })
    await wrapper.get('[data-testid="action-upload"]').trigger('click')
    await flushPromises()
    expect(m.preview).not.toHaveBeenCalled()
    expect(m.wizard.doUpload).toHaveBeenCalledTimes(1)
  })
})

describe('Náhled zaúčtování mezd', () => {
  it('načtení pustí až po souhlasu se založením chybějících analytik', async () => {
    m.preview.mockResolvedValue(preview())
    m.apply.mockResolvedValue({ import_id: 1, sym_par: 'MZ209903', period: '2099-03', internal_documents: [{ id: 5, document_no: 'ID-2099-0001' }], other_items: [], created_accounts: ['336.001'] })
    const file = new File(['<xml/>'], 'mzdy.xml')
    const wrapper = mount(PayrollPostingImport, { props: { file }, global: { stubs } })
    await flushPromises()

    expect(wrapper.get('[data-testid="action-apply"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="payroll-posting-create-accounts"] input').setValue(true)
    expect(wrapper.get('[data-testid="action-apply"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid="action-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledWith(file, true)
    expect(wrapper.find('[data-testid="payroll-posting-done"]').exists()).toBe(true)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('duplicitní měsíc zůstane zablokovaný s hláškou kontroly', async () => {
    m.preview.mockResolvedValue(preview({
      accounts: [],
      checks: [{ code: 'already_imported', level: 'error', message: 'Už načteno', context: {} }],
      can_apply_with_new_accounts: false,
    }))
    const wrapper = mount(PayrollPostingImport, { props: { file: new File(['<xml/>'], 'mzdy.xml') }, global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-testid="payroll-posting-check-already_imported"]').text()).toBe('Už načteno')
    expect(wrapper.get('[data-testid="action-apply"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="payroll-posting-create-accounts"]').exists()).toBe(false)
  })
})
