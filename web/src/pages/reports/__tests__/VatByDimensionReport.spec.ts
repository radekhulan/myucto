import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import type { VatByDimensionAmounts, VatByDimensionReport, VatByDimensionRow } from '@/api/reports'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, string> },
  replace: vi.fn(),
  supplier: { currentSupplierId: 1 },
  canRead: vi.fn(),
  enabled: true,
  api: {
    dphSettings: vi.fn(), vatByDimensionPreview: vi.fn(), vatByDimensionDownloadUrl: vi.fn(() => '/vat-by-dimension.pdf'),
  },
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
  useRoute: () => m.route,
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('@/api/reports', async original => ({ ...await original<typeof import('@/api/reports')>(), reportsApi: m.api }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 11 }, canRead: m.canRead }) }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => m.supplier }
})
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { get value() { return m.enabled } },
    loading: { value: false },
    load: () => Promise.resolve(),
    types: { value: [{ id: 11, name: 'Středisko', is_active: true }, { id: 12, name: 'Projekt', is_active: true }] },
  }),
}))
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: () => computed(() => [2026, 2025]) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, values?: Record<string, unknown>) => `${key}${values ? JSON.stringify(values) : ''}`,
  locale: { value: 'cs' },
}) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
import VatByDimensionPage from '../VatByDimensionReport.vue'

const amounts = (vatOutput: number, vatInput: number): VatByDimensionAmounts => ({
  vat_output: vatOutput, vat_input: vatInput, vat_result: vatOutput - vatInput,
  base_output_standard: vatOutput / 0.21, base_output_reduced: 0, base_output_other: 0,
  base_input_standard: vatInput / 0.21, base_input_reduced: 0, base_input_other: 0,
})
const row = (over: Partial<VatByDimensionRow>, vatOutput: number, vatInput: number): VatByDimensionRow => ({
  value_id: 2551, code: 'S1', name: 'Dílna', label: 'S1 – Dílna', depth: 0, is_active: true, documents: 3,
  lines: {}, ...amounts(vatOutput, vatInput), ...over,
})

function report(ok = true): VatByDimensionReport {
  return {
    period: { year: 2026, month: 10, period_type: 'monthly', quarter: null, start: '2026-10-01', end: '2026-10-31', label: '10/2026' },
    supplier: { company_name: 'Test s.r.o.', ic: '00000001', dic: 'CZ00000001' },
    dimension_type: { id: 11, name: 'Středisko' },
    amount_keys: ['vat_output', 'vat_input', 'vat_result', 'base_output_standard', 'base_output_reduced', 'base_output_other', 'base_input_standard', 'base_input_reduced', 'base_input_other'],
    rows: [
      row({}, 2100, 420),
      row({ value_id: 2552, code: 'S1.1', name: 'Sklad', label: 'S1.1 – Sklad', depth: 1, documents: 1 }, 210, 0),
      row({ value_id: null, code: null, name: 'Bez dimenze', label: 'Bez dimenze', documents: 2 }, 0, 105),
    ],
    total: amounts(2310, 525),
    control: {
      evidence: ok ? amounts(2310, 525) : amounts(2310, 546),
      difference: ok ? amounts(0, 0) : { ...amounts(0, 0), vat_input: -21, vat_result: 21, base_input_standard: -100 },
      ok,
      line_differences: ok ? [] : [{ line: '40', base: -100, vat: -21 }],
      return: { vat_output: 2310, vat_input: 525, vat_result: 1785, bridge: { vat_output: 0, vat_input: 0, vat_result: 0 } },
      return_error: null,
    },
    account343: {
      rows: [
        { value_id: 2551, code: 'S1', name: 'Dílna', label: 'S1 – Dílna', debit: 420, credit: 2100, net: 1680 },
        { value_id: null, code: null, name: 'Bez dimenze', label: 'Bez dimenze', debit: 105, credit: 0, net: -105 },
      ],
      total: { debit: 525, credit: 2100, net: 1575 },
      excluded: { payments: { count: 1, net: 1500 }, opening: { count: 0, net: 0 } },
      comparison: [
        { value_id: 2551, label: 'S1 – Dílna', report_result: 1680, account_net: 1680, difference: 0 },
        { value_id: null, label: 'Bez dimenze', report_result: -105, account_net: -105, difference: 0 },
        { value_id: null, label: 'Celkem', report_result: 1575, account_net: 1575, difference: 0, is_total: true },
      ],
      note: 'ACCOUNT_343_NOTE',
    },
    account343_unavailable: null,
    warnings: [],
  }
}

// Intl odděluje tisíce nezlomitelnou mezerou.
const plain = (text: string) => text.replace(/[\u00a0\u202f]/g, ' ')

function mountReport() {
  return mount(VatByDimensionPage, { global: { stubs: {
    EmptyState: { props: ['title'], template: '<div data-test="empty">{{ title }}</div>' },
  } } })
}

beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  m.enabled = true
  m.canRead.mockReturnValue(true)
  m.api.dphSettings.mockResolvedValue({ vat_period: 'monthly', taxpayer_type: 'fo' })
  m.api.vatByDimensionPreview.mockResolvedValue(report())
  m.route = reactive({ query: {} })
  m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    m.route.query = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined))
    return Promise.resolve()
  })
})
afterEach(() => {
  vi.restoreAllMocks()
})

describe('DPH podle dimenze', () => {
  it('vykreslí hodnoty s odsazením, řádek Bez dimenze, celkem a kontrolu bez rozdílu', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly', type_id: '11' }
    const wrapper = mountReport()
    await flushPromises()

    expect(m.api.vatByDimensionPreview).toHaveBeenCalledTimes(1)
    expect(m.api.vatByDimensionPreview).toHaveBeenCalledWith(2026, 10, 'monthly', 11)
    const rows = wrapper.findAll('[data-test="vat-dimension-row"]')
    expect(rows).toHaveLength(3)
    expect(rows[0]!.text()).toContain('S1 – Dílna')
    expect(plain(rows[0]!.text())).toContain('2 100,00')
    expect(rows[1]!.find('span').attributes('style')).toContain('padding-left: 1.25rem')
    expect(rows[2]!.text()).toContain('reports.vat_by_dimension.no_dimension')
    expect(plain(wrapper.get('[data-test="vat-dimension-total"]').text())).toContain('2 310,00')
    expect(wrapper.get('[data-test="vat-dimension-evidence"]').text()).toContain('reports.vat_by_dimension.evidence')
    const difference = wrapper.get('[data-test="vat-dimension-difference"]')
    expect(difference.classes()).toContain('bg-success-50')
    expect(wrapper.find('[data-test="vat-dimension-line-differences"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="vat-dimension-ok"]').exists()).toBe(true)
    expect(plain(wrapper.get('[data-test="vat-dimension-return"]').text())).toContain('1 785,00')
    wrapper.unmount()
  })

  it('nenulový rozdíl zvýrazní a vypíše rozdíly po řádcích přiznání', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly', type_id: '11' }
    m.api.vatByDimensionPreview.mockResolvedValue(report(false))
    const wrapper = mountReport()
    await flushPromises()
    const difference = wrapper.get('[data-test="vat-dimension-difference"]')
    expect(difference.classes()).toContain('bg-danger-50')
    expect(difference.find('.text-danger-600.font-semibold').exists()).toBe(true)
    const lines = wrapper.get('[data-test="vat-dimension-line-differences"]').text()
    expect(lines).toContain('"line":"40"')
    expect(lines).toContain('-21,00')
    expect(wrapper.find('[data-test="vat-dimension-ok"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('proklik z hodnoty vede na knihu DPH s filtrem jen té hodnoty a stejným obdobím, Bez dimenze proklik nemá', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly', type_id: '11' }
    const wrapper = mountReport()
    await flushPromises()
    const link = wrapper.get('[data-test="vat-dimension-book-2551"]')
    expect(JSON.parse(link.attributes('data-to')!)).toEqual({ path: '/reports/dph-book', query: {
      year: '2026', month: '10', period: 'monthly', dimension_value_id: '2551', dimension_descendants: '0', remember: '0',
    } })
    expect(wrapper.find('[data-test="vat-dimension-book-2552"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="vat-dimension-row"]')[2]!.find('a').exists()).toBe(false)
    wrapper.unmount()
  })

  it('řádky rozpisu DPH a oprav mají vlastní popisek bez prokliku a chybějící kurz označí sestavu jako předběžnou', async () => {
    const base = report()
    m.api.vatByDimensionPreview.mockResolvedValue({
      ...base,
      preliminary: true,
      rows: [
        ...base.rows,
        row({ kind: 'vat_allocation', value_id: null, code: null, name: 'Rozpis', label: 'Rozpis', documents: 1 }, 0, 21),
        row({ kind: 'corrections', value_id: null, code: null, name: 'Opravy', label: 'Opravy', documents: 0 }, -178, 0),
      ],
    })
    const wrapper = mountReport()
    await flushPromises()

    expect(wrapper.get('[data-test="vat-dimension-preliminary"]').text()).toContain('reports.vat_by_dimension.preliminary')
    const text = wrapper.get('[data-test="vat-dimension-table"]').text()
    expect(text).toContain('reports.vat_by_dimension.row_vat_allocation')
    expect(text).toContain('reports.vat_by_dimension.row_corrections')
    const links = wrapper.findAll('a').map(a => a.attributes('data-to'))
    // Proklik mají jen hodnoty (tabulka i mobilní karty), zvláštní řádky ne.
    const bookLinks = links.filter(l => l?.includes('dimension_value_id'))
    expect(bookLinks).toHaveLength(4)
    expect(bookLinks.every(l => l!.includes('2551') || l!.includes('2552'))).toBe(true)
    wrapper.unmount()
  })

  it('box účtu 343 ukáže obraty po hodnotách, porovnání, poznámku a vyloučené platby', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly', type_id: '11' }
    const wrapper = mountReport()
    await flushPromises()
    const box = wrapper.get('[data-test="vat-dimension-343"]')
    const rows = box.findAll('[data-test="vat-dimension-343-row"]')
    expect(rows).toHaveLength(2)
    expect(plain(rows[0]!.text())).toContain('1 680,00')
    expect(rows[1]!.text()).toContain('reports.vat_by_dimension.no_dimension')
    const comparison = box.get('[data-test="vat-dimension-343-comparison"]').findAll('tbody tr')
    expect(comparison[0]!.text()).toContain('S1 – Dílna')
    expect(comparison[1]!.text()).toContain('reports.vat_by_dimension.no_dimension')
    expect(comparison[2]!.text()).toContain('reports.vat_by_dimension.total')
    expect(box.text()).toContain('ACCOUNT_343_NOTE')
    expect(box.get('[data-test="vat-dimension-343-excluded"]').text()).toContain('"payments":1')

    m.api.vatByDimensionPreview.mockResolvedValue({ ...report(), account343: null, account343_unavailable: 'NO_343' })
    m.route.query = { ...m.route.query, month: '9' }
    await flushPromises()
    expect(wrapper.get('[data-test="vat-dimension-343"]').text()).toContain('NO_343')
    wrapper.unmount()
  })

  it('bez typu v URL vybere první typ a zapíše ho do URL, změna typu přenačte sestavu', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly' }
    const wrapper = mountReport()
    await flushPromises()
    expect(m.route.query.type_id).toBe('11')
    expect(m.api.vatByDimensionPreview).toHaveBeenLastCalledWith(2026, 10, 'monthly', 11)
    await wrapper.get('[data-test="vat-dimension-type"]').setValue('12')
    await flushPromises()
    expect(m.route.query.type_id).toBe('12')
    expect(m.api.vatByDimensionPreview).toHaveBeenLastCalledWith(2026, 10, 'monthly', 12)
    wrapper.unmount()
  })

  it('export otevře PDF i XLSX v novém okně, jen s právem exportu', async () => {
    m.route.query = { year: '2026', month: '10', period: 'quarterly', type_id: '11' }
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    const wrapper = mountReport()
    await flushPromises()
    await wrapper.get('[data-test="vat-dimension-pdf"]').trigger('click')
    expect(m.api.vatByDimensionDownloadUrl).toHaveBeenCalledWith(2026, 10, 'quarterly', 11, 'pdf')
    expect(open).toHaveBeenCalledWith('/vat-by-dimension.pdf', '_blank')
    await wrapper.get('[data-test="vat-dimension-xlsx"]').trigger('click')
    expect(m.api.vatByDimensionDownloadUrl).toHaveBeenLastCalledWith(2026, 10, 'quarterly', 11, 'xlsx')
    wrapper.unmount()

    m.canRead.mockImplementation((key: string) => key !== 'reports.export')
    const limited = mountReport()
    await flushPromises()
    expect(limited.find('[data-test="vat-dimension-pdf"]').exists()).toBe(false)
    limited.unmount()
  })

  it('bez zapnutých dimenzí ukáže prázdný stav a nic nenačítá', async () => {
    m.enabled = false
    const wrapper = mountReport()
    await flushPromises()
    expect(wrapper.get('[data-test="empty"]').text()).toBe('dimensions.disabled_title')
    expect(m.api.vatByDimensionPreview).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
