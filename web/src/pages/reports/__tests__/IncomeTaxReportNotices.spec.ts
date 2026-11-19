import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'

// Upozornění v detailu DPPO: finalizované přiznání, jehož účetnictví se po finalizaci
// změnilo, a neúplná projekce uzávěrky, když náhled některého kroku selhal.
const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  listPeriods: vi.fn(),
  canRead: true,
  taxpayerType: 'po' as 'po' | 'fo',
  get: vi.fn(),
  download: vi.fn(),
  reportPdfUrl: vi.fn(),
}))

vi.mock('@/api/taxReturn', () => ({
  taxReturnApi: {
    get: m.get,
    reportPdfUrl: m.reportPdfUrl,
    xmlUrl: () => '/api/tax-return/po/2025/xml',
    previewXmlUrl: () => '/api/tax-return/fo/2025/xml/preview',
    advanceOverrides: vi.fn().mockResolvedValue({ overrides: [], schedules: [] }),
    advances: vi.fn().mockResolvedValue({ schedules: [] }),
    insurance: vi.fn().mockResolvedValue(null),
  },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listPeriods: m.listPeriods } }))
vi.mock('@/api/tax', () => ({ taxApi: { saveProfile: vi.fn() } }))
vi.mock('@/api/taxEvidence', () => ({ taxEvidenceApi: { closing: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e),
}))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: m.download }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { taxpayer_type: m.taxpayerType } }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => m.canRead, canWrite: () => true }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: (_source: string, year: { value: number }) => computed(() => [...new Set([2026, 2025, year.value])]) }
})
// Částečný mock: `createI18n` potřebuje useFormat, `t` vrací klíč.
vi.mock('vue-i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-i18n')>()
  return {
    ...actual,
    useI18n: () => ({
      t: (key: string) => key,
      tm: () => [],
      rt: (v: unknown) => String(v),
      te: () => false,
      locale: { value: 'cs' },
    }),
  }
})

import IncomeTaxReport from '@/pages/reports/IncomeTaxReport.vue'

function makeState(type: 'po' | 'fo', status: 'draft' | 'final' = 'draft') {
  return {
    return: {
      year: 2025, type, variant: 'radne', variant_seq: 1, status, row_version: 1, inputs: {},
      last_submission_id: null, final_snapshot_id: null, finalized_at: null, finalized_by: null, updated_at: null,
    },
    form_code: type === 'po' ? 'dppdp9' : 'dpfdp7',
    variant: 'radne',
    variant_seq: 1,
    available_variants: [],
    last_known_tax_suggested: null,
    computed: {
      lines: [], tax: 0, advances_paid: 0, balance_due: 0, projection: null, summary: {}, warnings: [],
      next_advances: { regime: 'none', count: 0, amount: 0, total: 0, note: '' },
    },
    podklady: {
      period: { starts_on: '2025-01-01', ends_on: '2025-12-31' },
      suggestions: { addbacks: [], deductions: [] }, bank_accounts: [], bank_account: null, profile: {},
    },
    warnings: [],
    tax_losses: { losses: [], available_total: 0, suggested: 0, carry_years: 5 },
    prefinalize_check: null,
    snapshot: null,
    constants_year: 2025,
  }
}

let wrapper: VueWrapper | null = null

async function mountPage(state: ReturnType<typeof makeState> & Record<string, unknown>) {
  m.get.mockResolvedValue(state)
  wrapper = mount(IncomeTaxReport, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('IncomeTaxReport.vue: upozornění DPPO', () => {
  beforeEach(() => {
    m.route = reactive({ query: { type: 'po', year: '2025' } })
    m.listPeriods.mockReset().mockResolvedValue([])
    m.canRead = true
    m.taxpayerType = 'po'
    m.get.mockReset()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
  })

  it('warns when the books changed after the return was finalized', async () => {
    const w = await mountPage({ ...makeState('po', 'final'), books_current: false })
    expect(w.find('[data-test="books-changed"]').text()).toContain('taxReturn.books_changed_after_final')
  })

  it('shows a small profit difference that keeps the tax as info, not as stale books', async () => {
    const w = await mountPage({
      ...makeState('po', 'final'), books_current: false, books_current_reason: 'vh_differs_tax_same',
      books_vh: { stored: 3295583.22, current: 3295591.17, difference: 7.95 },
    })
    expect(w.find('[data-test="books-vh-differs"]').text()).toContain('taxReturn.books_vh_differs_tax_same')
    expect(w.find('[data-test="books-changed"]').exists()).toBe(false)
  })

  it('stays quiet while the books match or cannot be judged', async () => {
    for (const current of [true, null]) {
      const w = await mountPage({ ...makeState('po', 'final'), books_current: current })
      expect(w.find('[data-test="books-changed"]').exists()).toBe(false)
      w.unmount()
      wrapper = null
    }
  })

  it('lists closing steps missing from the projection', async () => {
    const state = makeState('po')
    state.podklady = { ...state.podklady, closing_projection: { items: [], warnings: [{ source: 'fx', reason: 'Chybí kurz ČNB' }] } } as typeof state.podklady
    const w = await mountPage(state)
    const box = w.find('[data-test="projection-warnings"]')
    expect(box.text()).toContain('taxReturn.projection_incomplete')
    expect(box.text()).toContain('taxReturn.proj_fx: Chybí kurz ČNB')
  })
})
