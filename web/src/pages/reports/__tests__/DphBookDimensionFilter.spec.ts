import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import type { DphBookPreview, KhEvidenceReport } from '@/api/reports'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, string> },
  replace: vi.fn(),
  supplier: { currentSupplierId: 1 },
  canRead: vi.fn(),
  api: {
    dphSettings: vi.fn(), dphBookPreview: vi.fn(), khEvidencePreview: vi.fn(),
    dphBookPdfUrl: vi.fn(() => '/book.pdf'), khEvidenceDownloadUrl: vi.fn(() => '/kh.xlsx'),
  },
}))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({
  replace: m.replace,
  resolve: (to: unknown) => ({ href: `/#${JSON.stringify(to)}` }),
}) }))
vi.mock('@/api/reports', async original => ({ ...await original<typeof import('@/api/reports')>(), reportsApi: m.api }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 11 }, canRead: m.canRead }) }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => m.supplier }
})
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: vi.fn() }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: () => computed(() => [2026, 2025]) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, values?: Record<string, unknown>) => `${key}${values ? JSON.stringify(values) : ''}`,
  locale: { value: 'cs' },
}) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
import DphBookReport from '../DphBookReport.vue'
import DimensionReportFilters from '@/components/dimensions/DimensionReportFilters.vue'

const DIMENSION = { type_id: 11, value_id: 2551, value_ids: [2551], label: 'Středisko: S1 – Dílna', criteria: [] }

function preview(withDimension = true): DphBookPreview {
  return {
    dimension: withDimension ? DIMENSION : null,
    period: { year: 2026, month: 10, period_type: 'monthly', quarter: null, start: '2026-10-01', end: '2026-10-31', label: '10/2026' },
    supplier: {},
    sections: [],
    totals: { issued: { base: 0, vat: 0, total: 0 }, received: { base: 0, vat: 0, total: 0 }, vat_balance: 0 },
  }
}
function mountReport() {
  return mount(DphBookReport, { global: { stubs: {
    KhEvidenceSections: { props: ['report'], template: '<div data-test="kh-report">{{ report.section }}</div>' },
    EmptyState: { props: ['title'], template: '<div>{{ title }}</div>' },
    DimensionReportFilters: true,
  } } })
}

beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  m.canRead.mockReturnValue(true)
  m.api.dphSettings.mockResolvedValue({ vat_period: 'monthly', taxpayer_type: 'fo' })
  m.api.dphBookPreview.mockImplementation((...args: unknown[]) => Promise.resolve(preview(args.length > 3)))
  m.api.khEvidencePreview.mockResolvedValue({ period: preview().period, section: 'A.4', sections: {}, dimension: DIMENSION } as unknown as KhEvidenceReport)
  m.route = reactive({ query: {} })
  m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    m.route.query = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined))
    return Promise.resolve()
  })
})
afterEach(() => {
  vi.restoreAllMocks()
})

describe('Kniha DPH – filtr dimenze', () => {
  it('proklik ze sestavy DPH podle dimenze převezme filtr i období a pošle ho do náhledu i PDF', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly', dimension_value_id: '2551', dimension_descendants: '0', remember: '0' }
    const wrapper = mountReport()
    await flushPromises()

    const filter = { dimension_value_id: '2551', dimension_descendants: false }
    expect(m.api.dphBookPreview).toHaveBeenCalledWith(2026, 10, 'monthly', filter)
    expect(wrapper.findComponent(DimensionReportFilters).props()).toMatchObject({ valueIds: [2551], descendants: false })
    expect(wrapper.get('[data-test="dph-book-dimension-label"]').text()).toContain('Středisko: S1 – Dílna')
    const note = wrapper.get('[data-test="dph-book-dimension-note"]').text()
    expect(note).toContain('reports.dph_book.dimension_corrections_note')
    expect(note).toContain('Středisko: S1 – Dílna')

    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    await wrapper.findAll('button').find(button => button.text().includes('reports.dph_book.download_pdf'))!.trigger('click')
    expect(m.api.dphBookPdfUrl).toHaveBeenCalledWith(2026, 10, 'monthly', filter)
    expect(open).toHaveBeenCalledWith('/book.pdf', '_blank')
    wrapper.unmount()
  })

  it('výběr dimenze v knize omezí na jeden typ a vypíše doklady s rozpisem DPH bez vazby na položky', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly', dimension_value_id: '2551' }
    m.api.dphBookPreview.mockResolvedValue({ ...preview(), dimension_unassigned: [
      { source: 'purchase', document_kind: 'invoice', invoice_id: 7, doc_number: 'PF-7', counterparty_name: 'Dodavatel', base: 1000, vat: 210 },
    ] })
    const wrapper = mountReport()
    await flushPromises()

    expect(wrapper.findComponent(DimensionReportFilters).props('singleType')).toBe(true)
    const unassigned = wrapper.get('[data-test="dph-book-dimension-unassigned"]').text()
    expect(unassigned).toContain('PF-7')
    expect(unassigned).toContain('reports.dph_book.dimension_unassigned')
    wrapper.unmount()
  })

  it('soupis oddílu KH dostane filtr dimenze v náhledu i v exportu', async () => {
    m.route.query = { year: '2026', month: '9', period: 'quarterly', kh_section: 'A.4', dimension_value_id: '2551' }
    const wrapper = mountReport()
    await flushPromises()
    const filter = { dimension_value_id: '2551', dimension_descendants: true }
    expect(m.api.khEvidencePreview).toHaveBeenLastCalledWith(2026, 9, 'quarterly', 'A.4', filter)
    expect(wrapper.get('[data-test="dph-book-dimension-label"]').text()).toContain('Středisko: S1 – Dílna')
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    await wrapper.findAll('button').find(button => button.text().includes('reports.kh_evidence.download_xlsx'))!.trigger('click')
    expect(m.api.khEvidenceDownloadUrl).toHaveBeenCalledWith(2026, 9, 'quarterly', 'A.4', 'xlsx', filter)
    expect(open).toHaveBeenCalledWith('/kh.xlsx', '_blank')
    wrapper.unmount()
  })

  it('výběr hodnoty zapíše URL a přenačte knihu, zrušení vrátí knihu bez filtru', async () => {
    m.route.query = { year: '2026', month: '10', period: 'monthly' }
    const wrapper = mountReport()
    await flushPromises()
    expect(m.api.dphBookPreview).toHaveBeenLastCalledWith(2026, 10, 'monthly')
    expect(wrapper.find('[data-test="dph-book-dimension-note"]').exists()).toBe(false)

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:valueIds', [2551])
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2026', month: '10', period: 'monthly', dimension_value_id: '2551' })
    expect(m.api.dphBookPreview).toHaveBeenLastCalledWith(2026, 10, 'monthly', { dimension_value_id: '2551', dimension_descendants: true })

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:descendants', false)
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2026', month: '10', period: 'monthly', dimension_value_id: '2551', dimension_descendants: '0' })
    expect(m.api.dphBookPreview).toHaveBeenLastCalledWith(2026, 10, 'monthly', { dimension_value_id: '2551', dimension_descendants: false })

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:valueIds', [])
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2026', month: '10', period: 'monthly' })
    expect(m.api.dphBookPreview).toHaveBeenLastCalledWith(2026, 10, 'monthly')
    wrapper.unmount()
  })
})

describe('reportsApi – filtr dimenze ve výkazech DPH', () => {
  it('náhled i exporty knihy a soupisu KH nesou dimension_value_id a dimension_descendants=0', async () => {
    const actual = await vi.importActual<typeof import('@/api/reports')>('@/api/reports')
    const filter = { dimension_value_id: '5,7', dimension_descendants: false }
    const pdf = new URL(actual.reportsApi.dphBookPdfUrl(2026, 10, 'monthly', filter), 'https://x.test')
    expect(pdf.searchParams.get('dimension_value_id')).toBe('5,7')
    expect(pdf.searchParams.get('dimension_descendants')).toBe('0')
    const kh = new URL(actual.reportsApi.khEvidenceDownloadUrl(2026, 10, 'monthly', 'A.4', 'xlsx', { dimension_value_id: '5' }), 'https://x.test')
    expect(kh.searchParams.get('dimension_value_id')).toBe('5')
    expect(kh.searchParams.has('dimension_descendants')).toBe(false)
    const plain = new URL(actual.reportsApi.dphBookPdfUrl(2026, 10, 'monthly'), 'https://x.test')
    expect(plain.searchParams.has('dimension_value_id')).toBe(false)
  })
})
