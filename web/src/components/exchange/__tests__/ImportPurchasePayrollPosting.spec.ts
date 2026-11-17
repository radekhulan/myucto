import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import ImportPurchase from '../ImportPurchase.vue'

const m = vi.hoisted(() => ({ job: null as any }))

vi.mock('@/composables/useFileImportJob', () => ({ useFileImportJob: () => m.job }))
vi.mock('@/api/integrations', () => ({ integrationsApi: { getAiExtractionStatus: vi.fn().mockResolvedValue({ ai_provider: 'x', providers: {} }) } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: {} }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isManagedInstallation: false }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))
vi.mock('@/components/exchange/ImportReportPanel.vue', () => ({ default: { template: '<div data-testid="report" />' } }))
vi.mock('@/components/exchange/ImportJobProgress.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/purchase/ExtractionReviewModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/purchase/AiNotConfiguredNotice.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/PayrollPostingImport.vue', () => ({ default: {
  props: ['file'], template: '<div data-testid="posting-preview">{{ file.name }}</div>',
} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

describe('Import přijatých faktur: zaúčtování mezd z PAMICY', () => {
  it('vyčleněný soubor nabídne náhled zaúčtování, ostatní soubory zůstávají v reportu', async () => {
    const posting = new File(['<xml/>'], 'mzdy.xml')
    m.job = {
      files: ref([posting, new File(['<xml/>'], 'faktury.xml')]), running: ref(false), cancelling: ref(false), error: ref(''),
      job: ref(null), percent: ref(null), purchaseStatus: ref('received'), uploaded: ref(null),
      report: ref({ summary: { created: 1, skipped: 1, failed: 0 }, results: [
        { file: 'faktury.xml / 2099001', status: 'created' },
        { file: 'mzdy.xml', status: 'skipped', code: 'payroll_posting', reason: 'zaúčtování mezd' },
      ] }),
      pick: vi.fn(), start: vi.fn(), cancel: vi.fn(), reset: vi.fn(),
    }
    const wrapper = mount(ImportPurchase, { global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } } })
    await flushPromises()

    const box = wrapper.get('[data-testid="import-payroll-posting"]')
    expect(box.text()).toContain('mzdy.xml')
    expect(box.text()).not.toContain('faktury.xml')
    expect(wrapper.find('[data-testid="posting-preview"]').exists()).toBe(false)
    await wrapper.get('[data-testid="import-payroll-posting-open-mzdy.xml"]').trigger('click')
    expect(wrapper.get('[data-testid="posting-preview"]').text()).toBe('mzdy.xml')
    expect(wrapper.find('[data-testid="report"]').exists()).toBe(true)
  })
})
