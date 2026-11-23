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
    getEntry: vi.fn().mockResolvedValue({ id: 7, entry_date: '2099-04-15', lines: [] }),
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
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true, isDemo: false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ get currentSupplier() { return m.supplier } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
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
import { journalListFilterParams } from '@/api/listQueries'
import type { JournalFilters } from '@/api/accounting'
import DimensionReportFilters from '@/components/dimensions/DimensionReportFilters.vue'

const FilterBarStub = {
  name: 'FilterBar',
  props: ['chips', 'activeCount', 'collapsible'],
  template: `<div>
    <span v-for="c in chips" :key="c.key" class="chip" :data-key="c.key">{{ c.label }}: {{ c.value }}</span>
    <slot name="primary" /><slot /><slot name="actions" />
  </div>`,
}

function mountJournal(options: Record<string, unknown> = {}) {
  return mount(Journal, {
    ...options,
    global: {
      stubs: {
        FilterBar: FilterBarStub,
        DimensionReportFilters: true,
        ActivationBanner: true,
        SavedFiltersMenu: true,
        ColumnPicker: true,
        TableColorsMenu: true,
        DensityToggle: true,
        EmptyState: true,
        DateInput: true,
        JournalSourceDrawer: true,
        JournalEntryDetailPanel: true,
        AutomationBadge: true,
        WorkspaceTeleport: { template: '<div><slot /></div>' },
      },
    },
  })
}

function lastListCall(): Record<string, unknown> {
  return m.listJournal.mock.calls.at(-1)?.[0] ?? {}
}

describe('Journal – filtr dimenze', () => {
  beforeEach(() => {
    for (const fn of [m.listPeriods, m.listAccounts, m.listJournal, m.exportReport, m.replace, m.toastError]) fn.mockReset()
    m.listPeriods.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listJournal.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 50 })
    m.routeQuery = {}
  })

  it('neplatný filtr ukáže hlášku serveru, ne obecnou chybu', async () => {
    m.routeQuery = { account_from: '6', account_to: '5' }
    m.listJournal.mockRejectedValue({ response: { status: 422, data: { error: { code: 'validation_failed', message: 'Počáteční účet nesmí být větší než koncový.' } } } })
    mountJournal()
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('Počáteční účet nesmí být větší než koncový.')
  })

  it('převezme hodnotu dimenze z URL, pošle ji serveru a ukáže chip', async () => {
    m.routeQuery = { dimension_value_id: '5', dimension_descendants: '0' }
    const wrapper = mountJournal()
    await flushPromises()

    expect(lastListCall()).toMatchObject({ dimension_value_id: '5', dimension_descendants: false })
    const filter = wrapper.findComponent(DimensionReportFilters)
    expect(filter.exists()).toBe(true)
    expect(filter.props()).toMatchObject({ valueIds: [5], descendants: false })
    const chip = wrapper.find('.chip[data-key="dimension"]')
    expect(chip.text()).toContain('Projekt')
    expect(chip.text()).toContain('P5 – Projekt pět')
    expect(chip.text()).toContain('accounting.journal.filter_dimension_only_value')
  })

  it('výběr hodnoty filtruje deník a zrcadlí se do URL; zrušení chipu filtr odebere', async () => {
    const wrapper = mountJournal()
    await flushPromises()
    expect(lastListCall().dimension_value_id).toBeUndefined()

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:valueIds', [5])
    await flushPromises()
    expect(lastListCall()).toMatchObject({ dimension_value_id: '5', dimension_descendants: true })
    expect(m.replace).toHaveBeenLastCalledWith({ query: { dimension_value_id: '5' } })

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:descendants', false)
    await flushPromises()
    expect(m.replace).toHaveBeenLastCalledWith({ query: { dimension_value_id: '5', dimension_descendants: '0' } })

    wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('clear', 'dimension')
    await flushPromises()
    expect(lastListCall().dimension_value_id).toBeUndefined()
    expect(m.replace).toHaveBeenLastCalledWith({ query: {} })
  })

  it('víc hodnot různých dimenzí pošle serveru i do URL jako seznam a ukáže je v chipu', async () => {
    m.routeQuery = { dimension_value_id: '5,5,abc,8' }
    const wrapper = mountJournal()
    await flushPromises()

    expect(lastListCall()).toMatchObject({ dimension_value_id: '5,8' })
    expect(wrapper.findComponent(DimensionReportFilters).props()).toMatchObject({ valueIds: [5, 8] })
    const chip = wrapper.find('.chip[data-key="dimension"]')
    expect(chip.text()).toContain('P5 – Projekt pět')

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:valueIds', [5, 8, 9])
    await flushPromises()
    expect(lastListCall()).toMatchObject({ dimension_value_id: '5,8,9' })
    expect(m.replace).toHaveBeenLastCalledWith({ query: { dimension_value_id: '5,8,9' } })
  })

  it('export posílá stejný filtr dimenze jako seznam', async () => {
    m.routeQuery = { dimension_value_id: '5', dimension_descendants: '0' }
    m.exportReport.mockResolvedValue({ data: new Blob(['x']) })
    const createObjectURL = vi.fn(() => 'blob:x')
    Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const wrapper = mountJournal()
    await flushPromises()

    const xlsx = wrapper.findAll('button').find(b => b.text().includes('accounting.journal.export_xlsx'))
    await xlsx!.trigger('click')
    await flushPromises()
    expect(m.exportReport).toHaveBeenCalledWith('/accounting/reports/journal/export',
      expect.objectContaining({ dimension_value_id: '5', dimension_descendants: 0, format: 'xlsx' }))
  })

  it('zdroj Předpis SVJ nabízí filtr jen firmě s profilem SVJ', async () => {
    const options = async () => {
      const wrapper = mountJournal()
      await flushPromises()
      return wrapper.findAll('option').map(option => option.attributes('value'))
    }
    m.supplier = { accounting_framework: 'cz_500', entity_profile: 'unknown' }
    expect(await options()).toContain('invoice')
    expect(await options()).not.toContain('unit_charge')
    m.supplier = { accounting_framework: 'cz_504', entity_profile: 'svj' }
    expect(await options()).toContain('unit_charge')
  })
})

describe('Journal – soupiska dokladů', () => {
  beforeEach(() => {
    for (const fn of [m.listPeriods, m.listAccounts, m.listJournal, m.exportReport, m.replace, m.toastError]) fn.mockReset()
    m.listPeriods.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listJournal.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 50 })
    m.routeQuery = {}
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
  })

  async function openDialog() {
    const wrapper = mountJournal({ attachTo: document.body })
    await flushPromises()
    await wrapper.get('[data-test="journal-document-list"]').trigger('click')
    await flushPromises()
    return wrapper
  }
  const input = (wrapper: Awaited<ReturnType<typeof openDialog>>, name: string) =>
    wrapper.get(`[data-test="document-list-${name}"]`).element as HTMLInputElement

  it('pošle přesně filtr seznamu Deníku a k němu rozsah soupisky', async () => {
    m.routeQuery = {
      dimension_value_id: '5', dimension_descendants: '0', date_from: '2099-01-01', source_type: 'purchase_invoice',
      q: '518', reversal: 'none', automation: 'manual', posted: 'posted', amount_from: '10', integrity: 'amount_mismatch',
    }
    m.exportReport.mockResolvedValue({ data: new Blob(['x']) })
    const wrapper = await openDialog()
    const { page, sort_key, sort_dir, include_vat_breakdown, include_posting_accounts, include_dimensions, include_notes, include_documents, ...listFilter } = lastListCall()

    expect(input(wrapper, 'from').value).toBe('5')
    expect(input(wrapper, 'to').value).toBe('5')
    await wrapper.get('[data-test="document-list-download"]').trigger('click')
    await flushPromises()
    expect(m.exportReport).toHaveBeenLastCalledWith('/accounting/reports/journal/document-list',
      { ...journalListFilterParams(listFilter as JournalFilters), line_account_from: '5', line_account_to: '5', format: 'xlsx' })
    expect(m.exportReport.mock.calls[0][1]).toMatchObject({ q: '518', dimension_value_id: '5', dimension_descendants: 0, posted: '1', integrity: 'amount_mismatch' })
    expect(wrapper.find('[data-test="document-list-download"]').exists()).toBe(false)
    void [page, sort_key, sort_dir, include_vat_breakdown, include_posting_accounts, include_dimensions, include_notes, include_documents]
    wrapper.unmount()
  })

  it('uloží soubor pod názvem ze serveru', async () => {
    m.exportReport.mockResolvedValue({ data: new Blob(['x']), headers: { 'content-disposition': 'attachment; filename="soupiska-dokladu-2099-01-01_2099-12-31.xlsx"' } })
    const names: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { names.push(this.download) })
    const wrapper = await openDialog()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(names).toEqual(['soupiska-dokladu-2099-01-01_2099-12-31.xlsx'])
    m.exportReport.mockResolvedValue({ data: new Blob(['x']), headers: { 'content-disposition': "attachment; filename*=UTF-8''soupiska-%C4%8Dtvrtlet%C3%AD.xlsx" } })
    await wrapper.get('[data-test="journal-document-list"]').trigger('click')
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(names).toEqual(['soupiska-dokladu-2099-01-01_2099-12-31.xlsx', 'soupiska-čtvrtletí.xlsx'])
    wrapper.unmount()
  })

  it('rozsah účtů z filtru Deníku předvyplní dialog, jednostranný ne', async () => {
    m.routeQuery = { account_from: '501', account_to: '518' }
    let wrapper = await openDialog()
    expect([input(wrapper, 'from').value, input(wrapper, 'to').value]).toEqual(['501', '518'])
    expect(document.activeElement).toBe(input(wrapper, 'from'))
    wrapper.unmount()

    m.routeQuery = { account_from: '501' }
    wrapper = await openDialog()
    expect([input(wrapper, 'from').value, input(wrapper, 'to').value]).toEqual(['5', '5'])
    wrapper.unmount()
  })

  it('odskok na jeden zápis pošle i entry_id, Enter v poli stáhne', async () => {
    m.routeQuery = { entry_id: '7' }
    m.exportReport.mockResolvedValue({ data: new Blob(['x']) })
    const wrapper = await openDialog()
    await wrapper.get('[data-test="document-list-from"]').trigger('keydown.enter')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.exportReport).toHaveBeenCalledWith('/accounting/reports/journal/document-list', expect.objectContaining({ entry_id: 7 }))
    wrapper.unmount()
  })

  it('prázdný rozsah ohlásí chybu a nic nestahuje', async () => {
    const wrapper = await openDialog()
    await wrapper.get('[data-test="document-list-to"]').setValue('')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.exportReport).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="document-list-error"]').text()).toBe('accounting.journal.document_list_range_required')
    wrapper.unmount()
  })

  it('během stahování blokuje tlačítka a ukazuje stav', async () => {
    let resolve!: (v: unknown) => void
    m.exportReport.mockReturnValue(new Promise(r => { resolve = r }))
    const wrapper = await openDialog()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const download = wrapper.get('[data-test="document-list-download"]')
    expect(download.text()).toBe('accounting.journal.document_list_preparing')
    expect(download.attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="journal-document-list"]').attributes('disabled')).toBeDefined()
    resolve({ data: new Blob(['x']) })
    await flushPromises()
    expect(wrapper.find('[data-test="document-list-download"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('chybu serveru ze staženého souboru ukáže v dialogu', async () => {
    const body = new Blob([JSON.stringify({ error: { code: 'too_many_rows', message: 'Filtru odpovídá příliš mnoho dokladů.' } })], { type: 'application/json' })
    m.exportReport.mockRejectedValue({ response: { status: 422, data: body } })
    const wrapper = await openDialog()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-test="document-list-error"]').text()).toBe('Filtru odpovídá příliš mnoho dokladů.')
    wrapper.unmount()
  })
})
