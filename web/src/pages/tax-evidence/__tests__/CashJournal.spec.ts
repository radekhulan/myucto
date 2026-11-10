import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { CashJournalReport } from '@/api/taxEvidence'

const { api, toast } = vi.hoisted(() => ({
  api: { cashJournal: vi.fn(), exportReport: vi.fn(), classifyMovement: vi.fn(), deleteClassification: vi.fn() },
  toast: { error: vi.fn(), success: vi.fn() },
}))
vi.mock('@/api/taxEvidence', () => ({ taxEvidenceApi: api }))
vi.mock('@/composables/useToast', () => ({ useToast: () => toast }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (n: number) => String(n) }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({ isVisible: () => true, densityClass: { value: '' } }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, params?: object) => key + (params ? JSON.stringify(params) : '') }) }))

import CashJournal from '../CashJournal.vue'
import { windowContextKey } from '@/workspace/windowContext'

function report(overrides: Partial<CashJournalReport> = {}): CashJournalReport {
  return {
    year: 2026, from: '2026-01-01', to: '2026-12-31', classification: null,
    total_rows: 9, filtered_rows: 1, blocking_unclassified_count: 3,
    is_vat_payer: false, opening_balance: 111, closing_balance: 999, balances: [],
    totals: { prijem_danovy: 777, prijem_osvobozeny: 0, prijem_nedanovy: 0, vydaj_danovy: 0, vydaj_nedanovy: 0, prevody: 0, private: 0, nezarazeno: 123, net: 777 },
    checks: null as unknown as CashJournalReport['checks'],
    warnings: [{ source_type: 'bank', source_id: 9, date: '2026-01-02', direction: 'in', amount: 123, blocking: true, message: 'Global warning' }],
    rows: [{ source_type: 'bank', source_id: 1, invoice_id: null, purchase_invoice_id: null, date: '2026-01-01', doc_no: 'SYNTH-1', partner: '', description: '', direction: 'in', income: 10, expense: null, running_balance: 555, bucket: 'income_taxable', base: 10, vat: 0, unclassified: false, blocking: false, fx_rate_missing: false, instrument: 'bank', gopay_clearing_id: null }],
    ...overrides,
  }
}
async function setup(query = '?year=2026') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/tax-evidence/cash-journal', component: CashJournal }] })
  await router.push('/tax-evidence/cash-journal' + query)
  await router.isReady()
  const root = document.createElement('div')
  root.dataset.testPane = 'cash-journal'
  document.body.appendChild(root)
  const wrapper = mount(CashJournal, { attachTo: root, global: { provide: { [windowContextKey as symbol]: { root } }, plugins: [router], directives: { 'column-labels': {} }, stubs: { ColumnPicker: true, DensityToggle: true, CashJournalMovementModal: true, CashJournalSourceLink: true } } })
  await flushPromises()
  return { wrapper, router }
}
async function choose(wrapper: Awaited<ReturnType<typeof setup>>['wrapper'], value: string) {
  await wrapper.get('#cash-journal-classification').setValue(value)
  await flushPromises()
}
function button(wrapper: Awaited<ReturnType<typeof setup>>['wrapper'], label: string) {
  return wrapper.findAll('button').find(b => b.text().includes(label))!
}

afterEach(() => { document.querySelectorAll('[data-test-pane]').forEach(el => el.remove()) })

beforeEach(() => {
  vi.clearAllMocks()
  api.cashJournal.mockResolvedValue(report())
  api.exportReport.mockResolvedValue({ data: new Blob(['synthetic']) })
  api.classifyMovement.mockResolvedValue({})
  vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:test'); static revokeObjectURL = vi.fn() })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

describe('cash journal classification filter', () => {
  it('places the source detail action in the final actions column, separately from the document', async () => {
    const { wrapper } = await setup()
    expect(wrapper.findAll('thead th').at(-1)?.text()).toBe('')
    expect(wrapper.findAll('thead th').at(-1)?.attributes('aria-label')).toBe('tax_evidence.cash_journal.col_actions')
    const cells = wrapper.get('tbody tr').findAll('td')
    expect(cells.at(-1)?.find('cash-journal-source-link-stub').exists()).toBe(true)
    expect(cells[1]?.text()).toContain('SYNTH-1')
    expect(cells[1]?.find('cash-journal-source-link-stub').exists()).toBe(false)
  })

  it('loads URL classification and dates once, preserving period when its chip is removed', async () => {
    const { wrapper, router } = await setup('?year=2025&from=2025-02-01&to=2025-03-01&classification=expense_taxable&other=keep')
    expect(api.cashJournal).toHaveBeenCalledTimes(1)
    expect(api.cashJournal).toHaveBeenLastCalledWith({ from: '2025-02-01', to: '2025-03-01', classification: 'expense_taxable' })
    button(wrapper, 'tax_evidence.cash_journal.col_class:').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toMatchObject({ year: '2025', from: '2025-02-01', to: '2025-03-01', other: 'keep' })
    expect(router.currentRoute.value.query.classification).toBeUndefined()
    expect(api.cashJournal).toHaveBeenCalledTimes(2)
  })

  it('ignores unknown classifications and does not reload when unrelated URL state changes', async () => {
    const { wrapper, router } = await setup('?year=2026&classification=invalid')
    expect(api.cashJournal).toHaveBeenCalledWith({ year: 2026 })
    expect(wrapper.get<HTMLSelectElement>('#cash-journal-classification').element.value).toBe('')
    await router.push({ query: { ...router.currentRoute.value.query, other: 'keep' } })
    await flushPromises()
    expect(api.cashJournal).toHaveBeenCalledTimes(1)
  })

  it('resets all active filters to the current year without dropping unrelated URL state', async () => {
    const { wrapper, router } = await setup('?year=2025&from=2025-01-01&to=2025-02-01&classification=private&other=keep')
    await button(wrapper, 'common.filter_clear_all').trigger('click'); await flushPromises()
    expect(api.cashJournal).toHaveBeenLastCalledWith({ year: new Date().getFullYear() })
    expect(router.currentRoute.value.query).toEqual({ year: String(new Date().getFullYear()), other: 'keep' })
  })

  it('changes classification immediately and follows Back/Forward with one request per navigation', async () => {
    const { wrapper, router } = await setup()
    await choose(wrapper, 'private')
    await choose(wrapper, 'transfer')
    expect(api.cashJournal).toHaveBeenCalledTimes(3)
    router.back(); await flushPromises()
    expect(wrapper.get<HTMLSelectElement>('#cash-journal-classification').element.value).toBe('private')
    expect(api.cashJournal).toHaveBeenCalledTimes(4)
    router.forward(); await flushPromises()
    expect(api.cashJournal).toHaveBeenCalledTimes(5)
    expect(api.cashJournal).toHaveBeenLastCalledWith({ year: 2026, classification: 'transfer' })
  })

  it('shows the row count only while a classification filter is active', async () => {
    api.cashJournal.mockResolvedValue(report({ filtered_rows: 9 }))
    const { wrapper } = await setup('?year=2026')
    expect(wrapper.text()).not.toContain('tax_evidence.cash_journal.rows_shown')
  })

  it('retains period summary, running balances and global warnings while filtering', async () => {
    api.cashJournal.mockResolvedValue(report({ classification: 'private' }))
    const { wrapper } = await setup('?year=2026&classification=private')
    expect(wrapper.text()).toContain('tax_evidence.cash_journal.summary_period')
    expect(wrapper.text()).toContain('777')
    expect(wrapper.text()).toContain('555')
    expect(wrapper.text()).toContain('Global warning')
    expect(wrapper.text()).toContain('tax_evidence.cash_journal.unclassified_warning{"n":3}')
    expect(wrapper.text()).toContain('tax_evidence.cash_journal.rows_shown{"shown":1,"total":9}')
    await button(wrapper, 'tax_evidence.cash_journal.show_unclassified').trigger('click'); await flushPromises()
    expect(api.cashJournal).toHaveBeenLastCalledWith({ year: 2026, classification: 'nezarazeno' })
  })

  it('distinguishes an empty filtered result and clears only classification', async () => {
    api.cashJournal.mockResolvedValue(report({ classification: 'private', rows: [], filtered_rows: 0 }))
    const { wrapper, router } = await setup('?year=2025&classification=private')
    expect(wrapper.text()).toContain('tax_evidence.cash_journal.empty_classification')
    await button(wrapper, 'tax_evidence.cash_journal.clear_classification').trigger('click'); await flushPromises()
    expect(router.currentRoute.value.query.year).toBe('2025')
    expect(api.cashJournal).toHaveBeenLastCalledWith({ year: 2025 })
  })

  it('shows the original no-data message for a genuinely empty period', async () => {
    api.cashJournal.mockResolvedValue(report({ classification: 'private', rows: [], total_rows: 0, filtered_rows: 0 }))
    const { wrapper } = await setup('?classification=private')
    expect(wrapper.text()).toContain('tax_evidence.cash_journal.empty')
    expect(wrapper.text()).not.toContain('tax_evidence.cash_journal.empty_classification')
  })

  it('exports the same loaded classification in both formats', async () => {
    const { wrapper } = await setup('?year=2026&classification=expense_taxable')
    for (const format of ['pdf', 'xlsx']) {
      await button(wrapper, `tax_evidence.cash_journal.export_${format}`).trigger('click'); await flushPromises()
      expect(api.exportReport).toHaveBeenLastCalledWith('/tax-evidence/cash-journal/export', { year: 2026, classification: 'expense_taxable', format })
    }
  })

  it('rejects stale responses and blocks exports until the latest filter has loaded', async () => {
    const { wrapper } = await setup()
    let resolveOld!: (r: CashJournalReport) => void
    let resolveLatest!: (r: CashJournalReport) => void
    api.cashJournal.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
    await choose(wrapper, 'private')
    expect(button(wrapper, 'tax_evidence.cash_journal.export_pdf').attributes('disabled')).toBeDefined()
    api.cashJournal.mockImplementationOnce(() => new Promise(resolve => { resolveLatest = resolve }))
    await choose(wrapper, 'transfer')
    resolveLatest(report({ classification: 'transfer', filtered_rows: 2 })); await flushPromises()
    resolveOld(report({ classification: 'private', filtered_rows: 8 })); await flushPromises()
    expect(wrapper.text()).toContain('"shown":2')
    expect(wrapper.text()).not.toContain('"shown":8')
    await button(wrapper, 'tax_evidence.cash_journal.export_pdf').trigger('click'); await flushPromises()
    expect(api.exportReport).toHaveBeenLastCalledWith('/tax-evidence/cash-journal/export', { year: 2026, classification: 'transfer', format: 'pdf' })
  })

  it('reloads the current period and filter after reclassifying a visible movement', async () => {
    const { wrapper, router } = await setup('?year=2025&classification=income_taxable')
    const select = wrapper.findAll('select').find(s => s.attributes('id') === undefined)!
    api.cashJournal.mockResolvedValueOnce(report({ classification: 'income_taxable', rows: [], filtered_rows: 0 }))
    await select.setValue('private'); await flushPromises()
    expect(api.classifyMovement).toHaveBeenCalledWith({ source_type: 'bank', source_id: 1, tax_bucket: 'private' })
    expect(api.cashJournal).toHaveBeenLastCalledWith({ year: 2025, classification: 'income_taxable' })
    expect(router.currentRoute.value.query.classification).toBe('income_taxable')
    expect(wrapper.text()).toContain('tax_evidence.cash_journal.empty_classification')
  })

  it('keeps the table, rows and result count mounted while changing the classification filter', async () => {
    api.cashJournal.mockResolvedValueOnce(report({ classification: 'income_taxable' }))
    const { wrapper } = await setup('?year=2026&classification=income_taxable')
    const table = wrapper.get('table').element
    const row = wrapper.get('tbody tr').element
    const count = wrapper.get('[role="status"]').element
    let finish!: (result: CashJournalReport) => void
    api.cashJournal.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    await choose(wrapper, 'private')
    expect(wrapper.find('table').element).toBe(table)
    expect(wrapper.find('tbody tr').element).toBe(row)
    expect(wrapper.find('[role="status"]').element).toBe(count)
    expect(wrapper.get('table').attributes('aria-busy')).toBe('true')
    expect(button(wrapper, 'tax_evidence.cash_journal.export_pdf').attributes('disabled')).toBeDefined()
    expect(wrapper.get('tbody select').attributes('disabled')).toBeDefined()
    const result = report({ classification: 'private' })
    result.rows[0]!.bucket = 'private'
    finish(result)
    await flushPromises()
    expect(wrapper.get('table').element).toBe(table)
    expect(wrapper.get('tbody tr').element).toBe(row)
    expect(wrapper.get('table').attributes('aria-busy')).toBe('false')
    expect(wrapper.get('tbody tr').text()).toContain('tax_evidence.cash_journal.class_private')
    expect(wrapper.get('tbody select').attributes('disabled')).toBeUndefined()
  })

  it('updates a classified movement and server totals without replacing its table or row', async () => {
    const { wrapper, router } = await setup()
    const table = wrapper.get('table').element
    const row = wrapper.get('tbody tr').element
    const select = wrapper.get<HTMLSelectElement>('tbody select')
    let finish!: (result: CashJournalReport) => void
    let save!: (result: object) => void
    api.classifyMovement.mockImplementationOnce(() => new Promise(resolve => { save = resolve }))
    api.cashJournal.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    await select.setValue('private')
    await flushPromises()
    expect(wrapper.get('table').element).toBe(table)
    expect(button(wrapper, 'tax_evidence.cash_journal.export_pdf').attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'tax_evidence.cash_journal.export_xlsx').attributes('disabled')).toBeDefined()
    expect(api.cashJournal).toHaveBeenCalledTimes(1)
    save({})
    await flushPromises()
    expect(api.classifyMovement).toHaveBeenCalledWith({ source_type: 'bank', source_id: 1, tax_bucket: 'private' })
    expect(wrapper.find('table').element).toBe(table)
    expect(wrapper.find('tbody tr').element).toBe(row)
    expect(wrapper.find('tbody select').element).toBe(select.element)
    const result = report()
    result.rows[0]!.bucket = 'private'
    result.totals = { ...result.totals, prijem_danovy: 0, private: 10, net: 0 }
    finish(result)
    await flushPromises()
    expect(wrapper.get('table').element).toBe(table)
    expect(wrapper.get('tbody tr').element).toBe(row)
    expect(wrapper.get('tbody tr').text()).toContain('tax_evidence.cash_journal.class_private')
    expect(wrapper.text()).not.toContain('777')
    expect(select.element.value).toBe('')
    expect(router.currentRoute.value.query).toEqual({ year: '2026' })
    expect(api.cashJournal).toHaveBeenCalledTimes(2)
  })

  it('shows the full loading state only before the first report is available', async () => {
    let finish!: (result: CashJournalReport) => void
    api.cashJournal.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const { wrapper } = await setup()
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.text()).toContain('common.loading')
    finish(report())
    await flushPromises()
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('common.loading')
  })

  it('dims the summary and table of the previous period while a refresh is loading', async () => {
    const { wrapper } = await setup()
    expect(wrapper.get('[data-test="cash-journal-summary"]').classes()).not.toContain('opacity-60')
    expect(wrapper.get('[data-test="cash-journal-table"]').classes()).not.toContain('opacity-60')
    let finish!: (result: CashJournalReport) => void
    api.cashJournal.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    await choose(wrapper, 'private')
    expect(wrapper.get('[data-test="cash-journal-summary"]').classes()).toContain('opacity-60')
    expect(wrapper.get('[data-test="cash-journal-table"]').classes()).toContain('opacity-60')
    finish(report({ classification: 'private' }))
    await flushPromises()
    expect(wrapper.get('[data-test="cash-journal-summary"]').classes()).not.toContain('opacity-60')
    expect(wrapper.get('[data-test="cash-journal-table"]').classes()).not.toContain('opacity-60')
  })

})


describe('cash journal movement focus', () => {
  it('keeps the full historical year and focuses only the matching source in its own pane', async () => {
    const row = report().rows[0]!
    api.cashJournal.mockResolvedValue(report({ year: 2010, rows: [
      { ...row, source_type: 'cash', doc_no: 'CASH-COLLISION' },
      { ...row, date: '2010-04-01', doc_no: 'BANK-TARGET', unclassified: true },
      { ...row, date: '2010-04-01', doc_no: 'BANK-TARGET-SPLIT' },
    ] }))
    const otherPane = document.createElement('tr')
    otherPane.dataset.cashJournalSource = 'bank-1'
    document.body.appendChild(otherPane)
    const scroll = vi.fn()
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, writable: true, value: scroll })
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
    const { wrapper } = await setup('?year=2010&source_type=bank&source_id=1&classification=private&from=2026-01-01&to=2026-01-31')
    expect(api.cashJournal).toHaveBeenLastCalledWith({ year: 2010 })
    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(3)
    expect(wrapper.get<HTMLSelectElement>('#cash-journal-year').element.value).toBe('2010')
    expect(rows[0]!.classes()).not.toContain('ring-primary-500')
    expect(rows[1]!.classes()).toContain('ring-primary-500')
    expect(rows[2]!.classes()).toContain('ring-primary-500')
    expect(scroll).toHaveBeenCalledTimes(1)
    expect(scroll.mock.instances[0]).toBe(rows[1]!.element)
    expect(wrapper.text()).toContain('777')
    expect(wrapper.text()).toContain('999')
    expect(api.classifyMovement).not.toHaveBeenCalled()
    otherPane.remove()
  })

  it('clears current-year source focus through explicit filters without getting stuck loading', async () => {
    const { wrapper, router } = await setup(`?year=${new Date().getFullYear()}&source_type=bank&source_id=1`)
    wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('clear-all')
    await flushPromises()
    expect(router.currentRoute.value.query.source_id).toBeUndefined()
    expect(wrapper.get('table').attributes('aria-busy')).toBe('false')
    expect(wrapper.findAll('.ring-primary-500')).toHaveLength(0)
    await choose(wrapper, 'private')
    expect(api.cashJournal).toHaveBeenLastCalledWith({ year: new Date().getFullYear(), classification: 'private' })
    expect(router.currentRoute.value.query.source_type).toBeUndefined()
  })

  it('retries a failed report when navigating to another source in the same year', async () => {
    api.cashJournal.mockRejectedValueOnce(new Error('Temporary failure'))
    const { wrapper, router } = await setup('?year=2026&source_type=bank&source_id=1')
    expect(wrapper.find('table').exists()).toBe(false)
    await router.push({ query: { year: '2026', source_type: 'bank', source_id: '2' } })
    await flushPromises()
    expect(api.cashJournal).toHaveBeenCalledTimes(2)
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.text()).toContain('tax_evidence.cash_journal.source_not_found')
    expect(wrapper.findAll('.ring-primary-500')).toHaveLength(0)
  })

  it('only scrolls the current source after a late previous-year request completes', async () => {
    let finishOld!: (value: CashJournalReport) => void
    api.cashJournal.mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve }))
    const scroll = vi.fn()
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, writable: true, value: scroll })
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
    const { wrapper, router } = await setup('?year=2010&source_type=bank&source_id=8')
    await router.push({ query: { year: '2026', source_type: 'bank', source_id: '1' } })
    await flushPromises()
    finishOld(report({ year: 2010, rows: [{ ...report().rows[0]!, source_id: 8 }] }))
    await flushPromises()
    expect(scroll).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[aria-current="true"]').attributes('data-cash-journal-source')).toBe('bank-1')
    expect(wrapper.get<HTMLSelectElement>('#cash-journal-year').element.value).toBe('2026')
  })

  it('moves persistent focus on reused-route navigation without narrowing the report', async () => {
    const row = report().rows[0]!
    api.cashJournal.mockResolvedValue(report({ rows: [row, { ...row, source_id: 2 }] }))
    const scroll = vi.fn()
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, writable: true, value: scroll })
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
    const { wrapper, router } = await setup('?year=2026&source_type=bank&source_id=1')
    await router.push({ query: { year: '2026', source_type: 'bank', source_id: '2' } })
    await flushPromises()
    const rows = wrapper.findAll('tbody tr')
    expect(rows[0]!.classes()).not.toContain('ring-primary-500')
    expect(rows[1]!.classes()).toContain('ring-primary-500')
    expect(scroll.mock.instances.at(-1)).toBe(rows[1]!.element)
    expect(api.cashJournal).toHaveBeenCalledTimes(1)
    await router.push({ query: { year: '2026' } })
    await flushPromises()
    expect(wrapper.findAll('.ring-primary-500')).toHaveLength(0)
  })
})
