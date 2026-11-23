import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import { createRouter, createMemoryHistory } from 'vue-router'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  dphPreview: vi.fn(), khPreview: vi.fn(), dphSettings: vi.fn(), openBeside: vi.fn(),
  canRead: vi.fn(), toastError: vi.fn(),
}))
vi.mock('vue-router', async (original) => ({ ...await original<typeof import('vue-router')>(), useRoute: () => m.route }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError }) }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/api/reports', () => ({ reportsApi: {
  dphSettings: m.dphSettings, dphPreview: m.dphPreview, khPreview: m.khPreview,
  dphTrend: vi.fn().mockResolvedValue([]), dphDraftsPrediction: vi.fn().mockResolvedValue(null),
} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 11 }, canRead: m.canRead }) }))
vi.mock('@/api/vatClearing', () => ({ vatClearingApi: { status: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 7 }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [2026, 2025] }))
vi.mock('vue-i18n', async (original) => ({ ...await original<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
import DphReport from '@/pages/reports/DphPriznaniReport.vue'
import KhReport from '@/pages/reports/KontrolniHlaseniReport.vue'

function dphResult(period = 'quarterly') {
  return { summary: { period_type: period, lines: {
    '1': { label: 'Output', base: 100, vat: 21 },
    '40': { label: 'Input', base: 100, vat: 21 },
    '33': { label: 'Informative', base: 0, vat: 0 },
    '40k': { label: 'Reduced', base: 100, vat: 10 },
    '51b': { label: 'Excluded', base: 100, vat: 0 },
  } }, warnings: [], cross_check: [] }
}
function khResult() {
  return { summary: { a1_count: 1, a2_count: 0, a4_count: 2, a5_count_aggregated: 1,
    b1_count: 0, b2_count: 2, b3_count_aggregated: 1 }, warnings: [] }
}
const options = { global: {
  plugins: [createRouter({ history: createMemoryHistory(), routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/reports/dph-book', component: { template: '<div />' } },
    { path: '/purchase-invoices/:id', name: 'purchase-invoice-detail', component: { template: '<div />' } },
    { path: '/invoices/:id', name: 'invoice-detail', component: { template: '<div />' } },
    { path: '/accounting/journal', name: 'accounting-journal', component: { template: '<div />' } },
  ] })],
  stubs: { PendingApprovalVatNotice: true, DateInput: true, PaginationBar: true, WorkspaceTeleport: { template: '<div><slot /></div>' } },
} }
async function openAction(wrapper: VueWrapper, index = 0) {
  await flushPromises()
  const previous = wrapper.find('button[aria-expanded="true"]')
  if (previous.exists()) await previous.trigger('click')
  await wrapper.findAll('button[aria-haspopup="menu"]')[index]!.trigger('click')
  return wrapper.get('a[data-workspace-open="beside"]')
}

beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  m.openBeside.mockReset().mockResolvedValue(undefined)
  m.route = reactive({ query: { year: '2025', month: '9', period: 'quarterly' } })
  m.canRead.mockImplementation((permission: string) => permission === 'reports')
  m.dphSettings.mockResolvedValue({ vat_period: 'quarterly', taxpayer_type: 'fo' })
  m.dphPreview.mockResolvedValue(dphResult())
  m.khPreview.mockResolvedValue(khResult())
})

describe('rozdíly proti podanému KH', () => {
  it('ukáže rozdíl s částkami a bez rozdílu sekci skryje', async () => {
    const empty = mount(KhReport, options)
    await flushPromises()
    expect(empty.find('[data-testid="kh-post-filing"]').exists()).toBe(false)
    empty.unmount()

    m.khPreview.mockResolvedValue({ ...khResult(), post_filing_changes: {
      has_filing: true, snapshot_available: true, submission: { id: 1, generated_at: '2026-10-13 11:15:45', form_variant: 'B' },
      documents: [{ section: 'A.2', status: 'only_current', doc_number: 'INV-1', is_aggregate: false, filed: null, current: { base: 100, vat: 21 } }],
    } })
    const wrapper = mount(KhReport, options)
    await flushPromises()
    const box = wrapper.get('[data-testid="kh-post-filing"]').text()
    expect(box).toContain('A.2')
    expect(box).toContain('INV-1')
    expect(box).toContain('reports.kh.post_filing.status.only_current')
    expect(box).not.toContain('reports.kh.post_filing.filed')
    expect(wrapper.get('[data-testid="kh-post-filing-evidence"]').attributes('href')).toBe('/reports/submissions/1/kh-evidence')
    wrapper.unmount()
  })
})

describe('links from VAT reports to the book', () => {
  it('otevře doklady z blokujícího nálezu, vysvětleného posunu i informativního nálezu', async () => {
    m.canRead.mockReturnValue(true)
    const doc = { invoice_id: 42, doc_number: 'TEST-42', source: 'purchase', declared: -210, counter: 0, difference: 210 }
    m.dphPreview.mockResolvedValue({ ...dphResult(), cross_check: [
      { check: 'account_343_vs_return', blocking: true, severity: 'mismatch', label: '343', note: '', declared: -210, counter: 0, difference: 210, documents: [doc] },
      { check: 'account_343_vs_return', blocking: false, severity: 'info', label: '343', note: '', declared: -210, counter: 0, difference: 210,
        documents: [{ ...doc, invoice_id: 43, doc_number: 'TEST-43', source: 'manual', doc_type: 'journal_entry' }] },
      { check: 'draft_advance_tax_documents', blocking: false, severity: 'info', label: 'Draft', note: '', declared: 0, counter: 210, difference: -210,
        documents: [{ ...doc, invoice_id: 44, doc_number: 'TEST-44', source: 'sale' }] },
    ] })
    const wrapper = mount(DphReport, options)
    await flushPromises()
    const links = wrapper.findAll('li a[data-workspace-open="beside"]')
    expect(links.map(link => link.attributes('href'))).toEqual(['/purchase-invoices/42', '/accounting/journal?entry_id=43', '/invoices/44'])
    await links[0]!.trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledExactlyOnceWith({ name: 'purchase-invoice-detail', params: { id: 42 } })
    wrapper.unmount()
  })

  it('places an overflow menu in the last column of both DPH tables and opens its real quarterly link beside', async () => {
    const wrapper = mount(DphReport, options)
    await flushPromises()
    expect(wrapper.find('a[data-workspace-open="beside"]').exists()).toBe(false)
    expect(wrapper.findAll('button[aria-haspopup="menu"]')).toHaveLength(4)
    const tables = wrapper.findAll('table')
    expect(tables).toHaveLength(2)
    for (const table of tables) {
      expect(table.findAll('th').at(-1)!.text()).toBe('common.actions')
      for (const row of table.findAll('tbody tr')) {
        expect(row.findAll('td')).toHaveLength(5)
        expect(row.find('td:first-child a').exists()).toBe(false)
        if (row.find('button[aria-haspopup="menu"]').exists()) {
          expect(row.findAll('td').at(-1)!.find('button[aria-haspopup="menu"]').exists()).toBe(true)
        }
      }
    }
    const informative = tables[0]!.findAll('tbody tr')[1]!
    expect(informative.text()).toContain('Informative')
    expect(informative.find('button').exists()).toBe(false)
    let link = await openAction(wrapper)
    expect(link.attributes('href')).toBe('/reports/dph-book?year=2025&month=9&period=quarterly&dph_line=1&remember=0')
    expect(link.text()).toBe('reports.dph_book.open_line_beside')
    expect(link.find('svg').exists()).toBe(true)
    await link.trigger('click')
    expect(m.openBeside).toHaveBeenCalledWith({ path: '/reports/dph-book', query: { year: 2025, month: 9, period: 'quarterly', dph_line: '1', remember: '0' } })
    link = await openAction(wrapper, 2)
    expect(link.attributes('href')).toContain('dph_line=40k')
    link = await openAction(wrapper, 3)
    expect(link.attributes('href')).toContain('dph_line=51b')
    wrapper.unmount()
  })

  it('reports navigation failures without an unhandled rejection', async () => {
    m.openBeside.mockRejectedValueOnce(new Error('navigation failed'))
    const wrapper = mount(DphReport, options)
    await flushPromises()
    await (await openAction(wrapper)).trigger('click')
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('common.error')
    wrapper.unmount()
  })

  it('links amendment rows to the current document book without a filing variant', async () => {
    m.dphPreview.mockResolvedValue({ ...dphResult(), summary: { ...dphResult().summary, is_amendment: true } })
    const wrapper = mount(DphReport, options)
    await flushPromises()
    expect((await openAction(wrapper)).attributes('href')).toBe('/reports/dph-book?year=2025&month=9&period=quarterly&dph_line=1&remember=0')
    wrapper.unmount()
  })

  it('uses the response periodicity when the company default is overridden by the report', async () => {
    m.dphPreview.mockResolvedValue(dphResult('monthly'))
    const wrapper = mount(DphReport, options)
    await flushPromises()
    expect((await openAction(wrapper)).attributes('href')).toContain('period=monthly')
    wrapper.unmount()
  })

  it('renders all A/B section links including zero and aggregated rows', async () => {
    const wrapper = mount(KhReport, options)
    await flushPromises()
    expect(wrapper.findAll('table')).toHaveLength(2)
    expect(wrapper.findAll('button[aria-haspopup="menu"]')).toHaveLength(7)
    for (const table of wrapper.findAll('table')) {
      expect(table.findAll('th').at(-1)!.text()).toBe('common.actions')
      for (const row of table.findAll('tbody tr')) {
        expect(row.findAll('td')).toHaveLength(3)
        expect(row.find('td:first-child a').exists()).toBe(false)
        expect(row.findAll('td').at(-1)!.find('button[aria-haspopup="menu"]').exists()).toBe(true)
      }
    }
    const sections: (string | null)[] = []
    for (let index = 0; index < 7; index++) {
      const link = await openAction(wrapper, index)
      sections.push(new URL(link.attributes('href')!, 'https://example.test').searchParams.get('kh_section'))
    }
    expect(sections).toEqual(['A.1', 'A.2', 'A.4', 'A.5', 'B.1', 'B.2', 'B.3'])
    await (await openAction(wrapper, 5)).trigger('click')
    expect(m.openBeside).toHaveBeenCalledWith({ path: '/reports/dph-book', query: { year: 2025, month: 9, period: 'quarterly', kh_section: 'B.2', remember: '0' } })
    wrapper.unmount()
  })

  it('does not link quick-reply counts to current documents', async () => {
    m.khPreview.mockResolvedValue({ ...khResult(), summary: { ...khResult().summary, is_vyzva_odpoved: true } })
    const wrapper = mount(KhReport, options)
    await flushPromises()
    expect(wrapper.find('button[aria-haspopup="menu"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('A.1')
    wrapper.unmount()
  })

  it('keeps legal-entity KH monthly despite a quarterly route', async () => {
    m.dphSettings.mockResolvedValue({ vat_period: 'quarterly', taxpayer_type: 'po' })
    const wrapper = mount(KhReport, options)
    await flushPromises()
    expect(m.khPreview.mock.lastCall?.slice(0, 3)).toEqual([2025, 9, 'monthly'])
    expect((await openAction(wrapper)).attributes('href')).toContain('period=monthly')
    wrapper.unmount()
  })

  for (const component of [DphReport, KhReport]) {
    it(`${component.__name}: preserves modified clicks and middle click`, async () => {
      const wrapper = mount(component, options)
      await flushPromises()
      let link = await openAction(wrapper)
      for (const modifier of ['ctrlKey', 'metaKey', 'shiftKey', 'altKey']) {
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, [modifier]: true })
        link.element.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(false)
        link = await openAction(wrapper)
      }
      await link.trigger('click', { button: 1 })
      expect(m.openBeside).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it(`${component.__name}: keeps labels without links when reports cannot be read`, async () => {
      m.canRead.mockReturnValue(false)
      const wrapper = mount(component, options)
      await flushPromises()
      expect(wrapper.find('button[aria-haspopup="menu"]').exists()).toBe(false)
      expect(wrapper.text()).toContain(component === DphReport ? 'Output' : 'A.1')
      wrapper.unmount()
    })

    it(`${component.__name}: reuses the page with the newly loaded period and ignores an old response`, async () => {
      const preview = component === DphReport ? m.dphPreview : m.khPreview
      const result = component === DphReport ? dphResult('quarterly') : khResult()
      preview.mockResolvedValue(component === DphReport ? dphResult('monthly') : khResult())
      let resolveOld!: (value: unknown) => void
      preview.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      const wrapper = mount(component, options)
      await flushPromises()
      m.route.query = { year: '2026', month: '7', period: 'monthly' }
      await flushPromises()
      expect((await openAction(wrapper)).attributes('href')).toContain('year=2026&month=7&period=monthly')
      resolveOld(result)
      await flushPromises()
      expect((await openAction(wrapper)).attributes('href')).toContain('year=2026&month=7&period=monthly')
      wrapper.unmount()
    })
  }
})
