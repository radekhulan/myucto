import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { computed, ref } from 'vue'
import type { CashDocument, CashDocumentStatus } from '@/api/cash'

const m = vi.hoisted(() => ({
  listRegisters: vi.fn(),
  listDocuments: vi.fn(),
  deleteDocument: vi.fn(),
  postDocument: vi.fn(),
  reverseDocument: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
  canWrite: vi.fn((_permission: string) => true),
}))

vi.mock('@/api/cash', () => ({
  cashApi: {
    listRegisters: m.listRegisters,
    listDocuments: m.listDocuments,
    deleteDocument: m.deleteDocument,
    postDocument: m.postDocument,
    reverseDocument: m.reverseDocument,
    documentPdfUrl: (id: number) => `/pdf/${id}`,
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: m.toastWarning }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
  formatDate: (v: string) => v,
}))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: (_k: string, columns: any[]) => ({
    columns,
    isVisible: () => true,
    densityClass: computed(() => ''),
  }),
}))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: () => ({
    filters: ref([]),
    activeId: ref(null),
    ready: ref(true),
    applyDefaultIfAny: async () => false,
    apply: () => undefined,
    clearActive: () => undefined,
  }),
  savedFilterTone: () => 'neutral',
}))
vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { edit: 'M0 0', check: 'M0 0', download: 'M0 0', uturn: 'M0 0', trash: 'M0 0', doc: 'M0 0', plus: 'M0 0', x: 'M0 0' },
  BTN_BASE: 'btn',
  FILLED: { primary: '', success: '', warning: '', danger: '', neutral: '', accent: '' },
  OUTLINE: { primary: '', success: '', warning: '', danger: '', neutral: '', accent: '' },
  MENU_ICON: { primary: '', success: '', warning: '', danger: '', neutral: '', accent: '' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
  btnIconSm: () => 'btn-icon-sm',
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: {} }),
}))
// Sdílené UI komponenty čtou preferences API / plný tvar composable ctrl — pro tenhle
// test jsou irelevantní, stačí prázdné stuby.
vi.mock('@/components/ui/SavedFiltersMenu.vue', () => ({ default: { name: 'SavedFiltersMenu', props: ['ctrl'], template: '<div />' } }))
vi.mock('@/components/ui/ColumnPicker.vue', () => ({ default: { name: 'ColumnPicker', props: ['ctrl'], template: '<div />' } }))
vi.mock('@/components/ui/DensityToggle.vue', () => ({ default: { name: 'DensityToggle', props: ['ctrl'], template: '<div />' } }))
vi.mock('@/components/ui/EmptyState.vue', () => ({ default: { name: 'EmptyState', props: ['title', 'cta', 'boxed', 'accent', 'icon', 'dense'], template: '<div />' } }))
vi.mock('@/components/ui/PaginationBar.vue', () => ({ default: { name: 'PaginationBar', props: ['page', 'perPage', 'total'], template: '<div />' } }))
vi.mock('@/components/cash/CashRegisterManager.vue', () => ({
  default: { name: 'CashRegisterManager', template: '<div />' },
}))
vi.mock('@/components/accounting/CashDocumentDetail.vue', () => ({
  default: { name: 'CashDocumentDetail', props: ['doc', 'purposeLabel'], template: '<div />' },
}))

import CashRegister from '@/pages/accounting/CashRegister.vue'

function doc(status: CashDocumentStatus, id = 1): CashDocument {
  return {
    id, supplier_id: 1, register_id: 1, doc_type: 'in', purpose: 'sale',
    doc_number: status === 'draft' ? null : 'PPD-2093-0001',
    issue_date: '2093-03-01', tax_date: null,
    partner_name: null, partner_ic: null, partner_dic: null, description: 'Prodej',
    total_amount: 100, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
    invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
    status, journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
  } as CashDocument
}

async function mountList(items: CashDocument[]) {
  m.listDocuments.mockResolvedValue({ items, total: items.length, page: 1, per_page: 50 })
  const wrapper = mount(CashRegister)
  await flushPromises()
  return wrapper
}

describe('CashRegister.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listRegisters.mockResolvedValue([{
      id: 1, supplier_id: 1, name: 'Pokladna', currency_code: 'CZK', account_code: '211.100',
      account_id: 1, account_name: 'Pokladna', is_default: true, is_active: true,
      documents_count: 1, balance: 100, balance_date: '2093-03-01', created_at: '2093-01-01',
    }])
    m.canWrite.mockImplementation((_p: string) => true)
    m.deleteDocument.mockResolvedValue({ deleted: true, warnings: [] })
    m.postDocument.mockResolvedValue({ doc_number: 'PPD-2093-0002', journal_entry_id: 5, warnings: [] })
  })

  it('vystavený doklad se maže tvrdě (force), rozpracovaný ne', async () => {
    const wrapper = await mountList([doc('posted', 1)])
    const vm = wrapper.vm as any

    vm.openDelete(doc('posted', 1))
    await vm.submitDelete()
    expect(m.deleteDocument).toHaveBeenLastCalledWith(1, true)

    vm.openDelete(doc('draft', 2))
    await vm.submitDelete()
    expect(m.deleteDocument).toHaveBeenLastCalledWith(2, false)
  })

  it('filtr stavu nabízí i rozpracované doklady', async () => {
    const wrapper = await mountList([])
    const options = wrapper.findAll('option').map(o => o.attributes('value'))
    expect(options).toContain('draft')
  })

  it('rozpracovaný doklad jde vystavit ze seznamu', async () => {
    const wrapper = await mountList([doc('draft', 3)])
    const vm = wrapper.vm as any
    await vm.postDraft(doc('draft', 3))

    expect(m.postDocument).toHaveBeenCalledWith(3)
    expect(m.toastSuccess).toHaveBeenCalled()
    // Po vystavení se musí přenačíst i zůstatek pokladny.
    expect(m.listRegisters).toHaveBeenCalledTimes(2)
  })

  it('varování z vystavení draftu se zobrazí uživateli', async () => {
    m.postDocument.mockResolvedValue({ doc_number: 'PPD-1', journal_entry_id: 5, warnings: ['cash.warning.negative_balance'] })
    const wrapper = await mountList([doc('draft', 3)])
    await (wrapper.vm as any).postDraft(doc('draft', 3))
    expect(m.toastWarning).toHaveBeenCalledWith('cash.warning.negative_balance')
  })

  it('storno bez důvodu hlásí větu, ne popisek pole', async () => {
    const wrapper = await mountList([doc('posted', 1)])
    const vm = wrapper.vm as any
    vm.openReverse(doc('posted', 1))
    vm.reverseReason = 'ab'
    await vm.submitReverse()

    expect(vm.reverseError).toBe('cash.validation.reason')
    expect(m.reverseDocument).not.toHaveBeenCalled()
  })

  it('akce hlavičky jdou přes ActionBar a mají tiery i ikony', async () => {
    const wrapper = await mountList([])
    const actions = (wrapper.vm as any).headerActions as any[]

    expect(wrapper.findComponent({ name: 'ActionBar' }).exists()).toBe(true)
    expect(actions.map(a => a.key)).toEqual(['new-in', 'new-out', 'book', 'registers'])
    expect(actions.every(a => a.icon && a.variant)).toBe(true)
    // Správa pokladen je utility → patří do „…", ne mezi tlačítka hlavičky.
    expect(actions.find(a => a.key === 'registers').tier).toBe('overflow')
  })

  it('znaménko částky nese číslo, ne ručně slepený prefix', async () => {
    const wrapper = await mountList([doc('posted', 1)])
    const vm = wrapper.vm as any
    expect(vm.signedAmount({ doc_type: 'in', total_amount: 100 })).toBe(100)
    expect(vm.signedAmount({ doc_type: 'out', total_amount: 100 })).toBe(-100)
    expect(wrapper.text()).not.toContain('−100')
  })

  it('varování o díře v číselné řadě se po smazání zobrazí', async () => {
    // Odpověď mazání se dřív zahazovala (`.then(() => true)`), takže jediný
    // warning, který server u mazání posílá, se k uživateli nikdy nedostal.
    m.deleteDocument.mockResolvedValue({
      deleted: true, warnings: ['cash.warning.series_gap'], doc_number: 'PPD-2093-0001',
    })
    const wrapper = await mountList([doc('posted', 1)])
    const vm = wrapper.vm as any
    vm.openDelete(doc('posted', 1))
    await vm.submitDelete()
    expect(m.toastWarning).toHaveBeenCalledWith('cash.warning.series_gap')
  })

  it('trvalé smazání se bez práva cash.close nenabízí', async () => {
    // Server chce na `?force=1` právo `cash.close`; role „Pokladní" (jen
    // cash.document.write) dřív tlačítko viděla a 403 dostala až po potvrzení.
    m.canWrite.mockImplementation((p: string) => p !== 'cash.close')
    const wrapper = await mountList([doc('posted', 1), doc('draft', 2)])
    const vm = wrapper.vm as any
    expect(vm.canDelete(doc('posted', 1))).toBe(false)
    expect(vm.canDelete(doc('draft', 2))).toBe(true)
  })

  it('správa pokladen je gatovaná na modul cash, ne na doklady', async () => {
    m.canWrite.mockImplementation((p: string) => p !== 'cash')
    const wrapper = await mountList([])
    const actions = (wrapper.vm as any).headerActions as any[]
    expect(actions.find(a => a.key === 'registers').show).toBe(false)
  })

  it('selhání načtení pokladen se nespolkne (prázdný seznam svádí k duplicitní pokladně)', async () => {
    m.listRegisters.mockRejectedValue({ response: { data: { error: { code: 'cash.error.validation', message: 'Nedostupné.' } } } })
    await mountList([])
    expect(m.toastError).toHaveBeenCalledWith('Nedostupné.')
  })
})
