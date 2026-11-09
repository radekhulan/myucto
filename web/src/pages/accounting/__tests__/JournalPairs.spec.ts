import { reactive, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  listAccounts: vi.fn(),
  listJournal: vi.fn(),
  exportReport: vi.fn(),
  getEntry: vi.fn(),
  reversePairs: vi.fn(),
  replace: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

// Dimenze zapnuté: hodnota 5 typu Projekt (Firma → Dimenze).
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true }, canEdit: { value: true }, loading: { value: false },
    types: { value: [] }, values: { value: [] }, documentTypes: { value: [] },
    valueById: { value: new Map([[5, { id: 5, type_id: 1, code: 'P5', name: 'Projekt pět', is_active: true }]]) },
    typeById: { value: new Map([[1, { id: 1, name: 'Projekt' }]]) },
    load: () => Promise.resolve(), reload: () => Promise.resolve(),
    options: () => [], treeOf: () => [], pathOf: () => [], labelOf: () => '',
    valueLabel: (id: number) => (id === 5 ? 'P5 – Projekt pět' : `#${id}`),
  }),
}))
vi.mock('@/composables/useAdaptiveTableRows', () => ({ useAdaptiveTableRows: vi.fn() }))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: m.listPeriods,
    listAccounts: m.listAccounts,
    listJournal: m.listJournal,
    exportReport: m.exportReport,
    getEntry: m.getEntry,
    reversePairs: m.reversePairs,
    getEntryForm: vi.fn().mockResolvedValue({ entry_form: 'single', history: [{ valid_from: '2010-01-01', entry_form: 'single', created_by: 1, created_at: '', note: null }] }),
  },
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: unknown) => (p && typeof p === 'object' ? `${key}${JSON.stringify(p)}` : key) }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true, isDemo: false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_framework: 'cz_500', entity_profile: 'unknown' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatDateTime: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined), getPagePrefs: () => ref({}) }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: (_key: string, columns: unknown[]) => ({
    columns, orderedColumns: ref(columns), ready: ref(true), isVisible: () => true, densityClass: ref(''), setFlag: vi.fn(), flag: () => false,
    sort: ref(null), toggleSort: vi.fn(),
  }),
}))
vi.mock('@/composables/useSavedFilters', () => ({
  savedFilterTone: () => 'neutral',
  useSavedFilters: () => ({
    filters: ref([]), activeId: ref(null), clearActive: vi.fn(), apply: vi.fn(),
    applyDefaultIfAny: vi.fn().mockResolvedValue(false),
  }),
}))

vi.mock('@/components/ui/ActionBar.vue', () => ({
  default: { props: ['actions'], template: '<div data-test="bar"><button v-for="a in actions.filter(x => x.show === undefined || x.show)" :key="a.key" :data-key="a.key" :disabled="a.disabled" @click="a.run && a.run()">{{ a.label }}</button></div>' },
}))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div><slot /><slot name="footer" /></div>' } }))

import Journal from '@/pages/accounting/Journal.vue'
import { windowContextKey } from '@/workspace/windowContext'
import type { JournalEntryDetail } from '@/api/accounting'

function mountJournal() {
  const root = document.createElement('div')
  document.body.appendChild(root)
  return mount(Journal, {
    attachTo: root,
    global: {
      provide: { [windowContextKey as symbol]: { root } },
      directives: { 'column-labels': {} },
      stubs: {
        FilterBar: { name: 'FilterBar', props: ['chips', 'activeCount', 'collapsible'], template: '<div><slot name="primary" /><slot /><slot name="actions" /></div>' },
        DimensionReportFilters: true, ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        TableColorsMenu: true, DensityToggle: true, EmptyState: true, DateInput: true, JournalSourceDrawer: true,
        JournalEntryDetailPanel: true, AutomationBadge: true,
      },
    },
  })
}

function entry(id: number, patch: Partial<JournalEntryDetail> = {}): JournalEntryDetail {
  return { id, supplier_id: 1, period_id: 1, entry_date: '2010-03-02', document_date: null,
    document_no: `SYNTH-${id}`, description: '', source_type: 'manual', source_id: null,
    posted_at: '2010-03-02', posted_by: 1, reversed_by: null, row_version: 1,
    created_at: '2010-03-02', updated_at: '2010-03-02', lines: [], entry_form: 'single',
    pairs: [
      { pair_no: 1, debit_line_id: 1, credit_line_id: 2, debit_account_code: '221', credit_account_code: '311', amount: 100 },
      { pair_no: 2, debit_line_id: 3, credit_line_id: 4, debit_account_code: '568', credit_account_code: '221', amount: 15 },
    ],
    ...patch }
}

beforeEach(() => {
  vi.clearAllMocks()
  m.routeQuery = reactive({})
  m.listPeriods.mockResolvedValue([{ id: 1, status: 'open', starts_on: '2010-01-01', ends_on: '2010-12-31', fiscal_year: 2010 }])
  m.listAccounts.mockResolvedValue([])
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() })
  vi.spyOn(HTMLElement.prototype, 'getClientRects').mockImplementation(function (this: HTMLElement) {
    return (this.tagName === 'TR' ? [{}] : []) as unknown as DOMRectList
  })
})

async function openEntry(detail: JournalEntryDetail, listPatch: Record<string, unknown> = {}) {
  m.routeQuery = reactive({ entry_id: String(detail.id) })
  m.getEntry.mockResolvedValue(detail)
  m.listJournal.mockResolvedValue({ items: [{ ...detail, ...listPatch }], total: 1, page: 1, per_page: 50 })
  const wrapper = mountJournal()
  await flushPromises()
  return wrapper
}

describe('Journal: dvojice jednoduchého zápisu', () => {
  it('ruční zápis nabídne přeúčtování i storno vybraných dvojic; storno pošle pair_nos', async () => {
    m.reversePairs.mockResolvedValue({ id: 99 })
    const confirmSpy = vi.spyOn(window, 'confirm')
    const wrapper = await openEntry(entry(7))
    expect(wrapper.find('[data-test="journal-entry-pairs"]').exists()).toBe(true)
    // Detail je v DOM dvakrát (desktopová tabulka a mobilní karta), proto se čte první tabulka.
    expect(wrapper.findAll('[data-test="journal-pairs-table"]')[0].findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.find('[data-key="pair-reverse"]').exists()).toBe(true)
    expect(wrapper.find('[data-key="pair-repost"]').exists()).toBe(true)
    // bez výběru je storno zašedlé
    expect((wrapper.get('[data-key="pair-reverse"]').element as HTMLButtonElement).disabled).toBe(true)

    const boxes = wrapper.findAll('[data-test="journal-pairs-table"]')[0].findAll('tbody input[type="checkbox"]')
    await boxes[1].setValue(true)
    await wrapper.get('[data-key="pair-reverse"]').trigger('click')
    await flushPromises()
    // Potvrzení je stylovaný dialog aplikace, ne nativní confirm(); storno čeká na jeho potvrzení.
    expect(confirmSpy).not.toHaveBeenCalled()
    expect(m.reversePairs).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="confirm-message"]').text()).toContain('"n":1,"nos":"2"')
    await wrapper.get('[data-test="confirm-ok"]').trigger('click')
    await flushPromises()
    expect(m.reversePairs).toHaveBeenCalledWith(7, { pair_nos: [2] })
    wrapper.unmount()
  })

  it('rozbalený detail drží šířku viditelné plochy, ne celé vodorovně posuvné tabulky', async () => {
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(1000)
    const wrapper = await openEntry(entry(7))
    const box = wrapper.get('[data-test="journal-detail-viewport"]').element as HTMLElement
    expect(box.style.position).toBe('sticky')
    expect(box.style.width).toBe('976px')
    wrapper.unmount()
  })

  it('u zápisu z dokladu (banka) jde dvojici jen přeúčtovat, čisté storno se nenabízí', async () => {
    const wrapper = await openEntry(entry(8, { source_type: 'bank', source_id: 3 }))
    expect(wrapper.find('[data-key="pair-repost"]').exists()).toBe(true)
    expect(wrapper.find('[data-key="pair-reverse"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('accounting.pairs.document_repost_only')
    wrapper.unmount()
  })

  it('mzdy, stornované zápisy a protizápisy akce dvojic nemají', async () => {
    const payroll = await openEntry(entry(9, { source_type: 'payroll' }))
    expect(payroll.find('[data-key="pair-reverse"]').exists()).toBe(false)
    expect(payroll.find('[data-key="pair-repost"]').exists()).toBe(false)
    payroll.unmount()
    const reversed = await openEntry(entry(10, { reversed_by: 11 }))
    expect(reversed.find('[data-key="pair-repost"]').exists()).toBe(false)
    reversed.unmount()
  })

  it('zápis M : N je v seznamu označený odznakem', async () => {
    const wrapper = await openEntry(entry(12, { pairs: [] }), { pair_status: 'composite_mn' })
    expect(wrapper.find('[data-test="pair-badge-mn"]').text()).toBe('accounting.pairs.badge_mn')
    expect(wrapper.find('[data-test="journal-entry-pairs"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('filtr M : N se nabízí jen firmě s jednoduchou formou', async () => {
    const wrapper = await openEntry(entry(13))
    expect(wrapper.text()).toContain('accounting.pairs.filter_mn')
    wrapper.unmount()
  })
})
