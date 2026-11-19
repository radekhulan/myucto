import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  listAccounts: vi.fn(),
  listJournal: vi.fn(),
  exportReport: vi.fn(),
  replace: vi.fn(),
  toastError: vi.fn(),
  routeQuery: {} as Record<string, string>,
  perms: [] as string[],
  admin: false,
  supplier: { accounting_framework: 'cz_500', entity_profile: 'unknown' } as Record<string, string>,
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
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: m.listPeriods,
    listAccounts: m.listAccounts,
    listJournal: m.listJournal,
    exportReport: m.exportReport,
    getEntry: vi.fn(),
    getEntryForm: vi.fn().mockResolvedValue({ entry_form: 'composite', history: [] }),
  },
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: (k: string) => (['accounting.bulk_changes', 'accounting.journal.bulk_repost', 'accounting.journal.post'].includes(k) ? m.perms.includes(k) : true), canRead: () => true, isDemo: false, get isCompanyAdminRole() { return m.admin }, isClientRole: false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ get currentSupplier() { return m.supplier } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: vi.fn() }) }))
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

import Journal from '@/pages/accounting/Journal.vue'

const ActionBarStub = {
  name: 'ActionBar',
  props: ['actions'],
  template: `<div data-test="action-bar"><button v-for="a in actions.filter(x => x.show)" :key="a.key" :data-test="'action-' + a.key" @click="a.run()">{{ a.label }}</button></div>`,
}
const DialogStub = { name: 'BulkActionsDialog', props: ['target', 'selection', 'selectionCount', 'filterSelection', 'filterCount'], template: '<div data-test="bulk-dialog" />' }

function mountJournal() {
  return mount(Journal, {
    global: {
      stubs: {
        FilterBar: { template: '<div><slot name="primary" /><slot /><slot name="actions" /></div>' },
        ActionBar: ActionBarStub,
        BulkActionBar: { props: ['count'], template: '<div><slot /></div>' },
        BulkActionsDialog: DialogStub,
        DimensionReportFilters: true, ActivationBanner: true, SavedFiltersMenu: true, ColumnPicker: true,
        TableColorsMenu: true, DensityToggle: true, EmptyState: true, DateInput: true,
        JournalSourceDrawer: true, JournalEntryDetailPanel: true, AutomationBadge: true, VatBreakdownCell: true,
      },
    },
  })
}

function entry(id: number) {
  return { id, entry_date: '2026-02-01', document_no: `ID${id}`, description: 'Nájem', source_type: 'manual', source_id: null, amount: 100, posted_at: '2026-02-02', reversed_by: null }
}

describe('Journal – hromadné akce', () => {
  beforeEach(() => {
    for (const fn of [m.listPeriods, m.listAccounts, m.listJournal, m.exportReport, m.replace, m.toastError]) fn.mockReset()
    m.listPeriods.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listJournal.mockResolvedValue({ items: [entry(11), entry(12)], total: 40, page: 1, per_page: 50 })
    m.routeQuery = { account_from: '5', posted: 'posted' }
    m.perms = []
    m.admin = false
  })

  it('bez práva hromadných změn není položka v menu ani výběr řádků', async () => {
    m.perms = ['accounting.journal.bulk_repost']
    const wrapper = mountJournal()
    await flushPromises()
    expect(wrapper.find('[data-test="action-bulk-actions"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="journal-row-select"]').exists()).toBe(false)
  })

  it('bez výběru pošle „všech N podle filtru" s query seznamu bez stránkování', async () => {
    m.perms = ['accounting.bulk_changes']
    const wrapper = mountJournal()
    await flushPromises()
    await wrapper.get('[data-test="action-bulk-actions"]').trigger('click')
    const dialog = wrapper.getComponent(DialogStub)
    expect(dialog.props('target')).toBe('journal_entry')
    expect(dialog.props('selection')).toEqual({ list_query: { account_from: '5', posted: '1' } })
    expect(dialog.props('selectionCount')).toBe(40)
    expect(dialog.props('filterSelection')).toBeNull()
  })

  it('s výběrem řádků pošle id a filtr nabídne jako druhou volbu (i správci firmy bez práva)', async () => {
    m.admin = true
    const wrapper = mountJournal()
    await flushPromises()
    await wrapper.findAll('[data-test="journal-row-select"]')[1]!.trigger('change')
    await wrapper.get('[data-test="action-bulk-actions"]').trigger('click')
    const dialog = wrapper.getComponent(DialogStub)
    expect(dialog.props('selection')).toEqual({ ids: [12] })
    expect(dialog.props('selectionCount')).toBe(1)
    expect(dialog.props('filterSelection')).toEqual({ list_query: { account_from: '5', posted: '1' } })
    expect(dialog.props('filterCount')).toBe(40)
  })

  it('mobilní karta má vlastní zaškrtávátko mimo tlačítko rozbalení', async () => {
    m.perms = ['accounting.bulk_changes']
    const wrapper = mountJournal()
    await flushPromises()
    const box = wrapper.findAll('[data-test="journal-card-select"]')
    expect(box).toHaveLength(2)
    expect(box[0]!.element.closest('button')).toBeNull()
    await box[0]!.trigger('change')
    await wrapper.get('[data-test="action-bulk-actions"]').trigger('click')
    expect(wrapper.getComponent(DialogStub).props('selection')).toEqual({ ids: [11] })
  })
})
