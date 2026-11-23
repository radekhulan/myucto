import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { CashRegister } from '@/api/cash'

const m = vi.hoisted(() => ({
  accountingMode: 'double_entry',
  routeParams: {} as Record<string, string>,
  routeQuery: {} as Record<string, string>,
  replace: vi.fn(),
  listRegisters: vi.fn(),
  listRulePresets: vi.fn(),
  createDocument: vi.fn(),
  getDocument: vi.fn(),
  updateDocument: vi.fn(),
  postDocument: vi.fn(),
  searchUnpaid: vi.fn(),
  listAccounts: vi.fn(),
  listPostingRules: vi.fn(),
  taxList: vi.fn(),
  clientsList: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
  push: vi.fn(),
  byEntity: vi.fn(),
  docUpload: vi.fn(),
  docAddLink: vi.fn(),
  docRemove: vi.fn(),
}))

const phoneRef = ref(false)
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))

vi.mock('@/api/cash', () => ({
  cashApi: {
    listRegisters: m.listRegisters,
    listRulePresets: m.listRulePresets,
    createDocument: m.createDocument,
    getDocument: m.getDocument,
    updateDocument: m.updateDocument,
    postDocument: m.postDocument,
    searchUnpaid: m.searchUnpaid,
  },
  UNPAID_PAGE_SIZE: 20,
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: m.listAccounts, listPostingRules: m.listPostingRules },
}))
vi.mock('@/api/taxConstants', () => ({ taxConstantsApi: { list: m.taxList } }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: m.clientsList } }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: m.toastWarning }),
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v) }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.accountingMode } }),
}))
// Dimenze jsou u firmy vypnuté (currentSupplier bez dimensions_enabled) — editor nesmí nic načítat.
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: () => true }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: m.routeQuery, params: m.routeParams }),
  useRouter: () => ({ push: m.push, replace: m.replace }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: { flags: { preview_open: true } } }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/api/documents', () => ({
  documentsApi: {
    byEntity: m.byEntity, upload: m.docUpload, addLink: m.docAddLink, remove: m.docRemove,
    previewUrl: (id: number) => `/documents/${id}/preview`, thumbUrl: () => '', downloadUrl: () => '',
  },
}))

vi.mock('@/api/numberSeries', () => ({
  numberSeriesApi: {
    options: vi.fn().mockResolvedValue({
      kind: 'cash_receipt', multiple: true, default_id: 1,
      series: [
        { id: 1, name: 'Příjmové', label: '', storage: 'native', prefix: 'PPD', template: '{PREFIX}-{YYYY}-{NNNN}', valid_to: null },
        { id: 2, name: 'Pokladna B', label: '', storage: 'native', prefix: 'PB', template: '{PREFIX}-{YYYY}-{NNNN}', valid_to: null },
      ],
    }),
  },
}))

import CashDocumentEditor from '@/pages/accounting/CashDocumentEditor.vue'

function register(overrides: Partial<CashRegister> = {}): CashRegister {
  return {
    id: 1, supplier_id: 1, name: 'Hlavní pokladna', currency_code: 'CZK',
    account_code: '211.100', account_id: 9, account_name: 'Pokladna',
    is_default: true, is_active: true, documents_count: 0,
    balance: 0, balance_date: '2093-01-01', created_at: '2093-01-01',
    ...overrides,
  } as CashRegister
}

async function mountEditor() {
  const wrapper = mount(CashDocumentEditor, {
    global: { stubs: { CapturePhotoButton: { name: 'CapturePhotoButton', template: '<button type="button" data-test="capture-stub" />' } } },
  })
  await flushPromises()
  return wrapper
}

describe('CashDocumentEditor.vue na telefonu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    for (const key of Object.keys(m.routeParams)) delete m.routeParams[key]
    for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
    m.listRegisters.mockResolvedValue([register()])
    m.listRulePresets.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listPostingRules.mockResolvedValue({})
    m.taxList.mockResolvedValue([{ year: new Date().getFullYear(), is_override: false, data: { vat_rate_standard: 21, vat_rate_reduced: 12, kh_item_threshold: 10000 } }])
    m.clientsList.mockResolvedValue({ data: [] })
    m.byEntity.mockResolvedValue([])
  })

  it('mimo telefon vykreslí formulář beze změny', async () => {
    phoneRef.value = false
    const wrapper = await mountEditor()
    expect(wrapper.find('[data-test="cash-more-details-toggle"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="cash-partner-fields"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="cash-phone-footer"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="cash-preview-posting"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="cash-original-capture"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="cash-total-amount"]').attributes('inputmode')).toBeUndefined()
  })

  it('na telefonu sbalí partnera pod Další údaje, dá částce desetinnou klávesnici a lištu s Vystavit', async () => {
    phoneRef.value = true
    const wrapper = await mountEditor()
    expect(wrapper.get('[data-test="cash-total-amount"]').attributes('inputmode')).toBe('decimal')
    expect(wrapper.find('[data-test="cash-partner-fields"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="cash-original-capture"]').exists()).toBe(true)
    await wrapper.get('[data-test="cash-more-details-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="cash-partner-fields"]').exists()).toBe(true)

    const footer = wrapper.get('[data-test="cash-phone-footer"]')
    expect(footer.classes()).toContain('sticky')
    expect(footer.find('[data-test="cash-phone-post"]').text()).toContain('cash.post_document')
    expect(wrapper.find('[data-test="cash-post-and-next"]').exists()).toBe(false)

    expect(wrapper.find('[data-test="cash-preview-posting"]').exists()).toBe(false)
    await wrapper.get('[data-test="cash-preview-posting-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="cash-preview-posting"]').exists()).toBe(true)
  })

  it('na telefonu nechá Další údaje otevřené, když už nesou hodnotu', async () => {
    phoneRef.value = true
    const wrapper = await mountEditor()
    await wrapper.get('[data-test="cash-more-details-toggle"]').trigger('click')
    const input = wrapper.get('[data-test="cash-partner-fields"] input')
    await input.setValue('Ukázková firma')
    await wrapper.get('[data-test="cash-more-details-toggle"]').trigger('click')
    expect(wrapper.find('[data-test="cash-partner-fields"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="cash-more-details-toggle"]').attributes('disabled')).toBeDefined()
  })
})
