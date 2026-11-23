import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  takeoverPreview: vi.fn(),
  takeoverApply: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}))

vi.mock('@/api/accruals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/accruals')>()
  return { ...actual, accrualsApi: { takeoverPreview: m.takeoverPreview, takeoverApply: m.takeoverApply } }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč`, statusBadgeClass: (s: string) => `badge-${s}` }))
vi.mock('vue-router', () => ({ RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }),
}))

import AccrualTakeover from '../AccrualTakeover.vue'

function preview(changes: Record<string, unknown> = {}) {
  return {
    opening_date: '2099-01-01',
    items: [{ item_no: '99CR0002', import_key: 'pohoda:99CR0002', existing_id: null, title: 'Syntetický nájem', kind: 'deferred_revenue',
      account_code: '384', counter_account_code: '602', termination_account_code: null, partner_name: null, source_type: 'invoice',
      source_id: 7, source_document_no: 'SYN9905', basis_amount: 12000, period_from: '2099-02-01', period_to: '2100-01-31',
      method: 'yearly', status: 'draft', terminated_on: null, opening_share: null, reconstructed: false,
      releases: [{ entry_id: 1, date: '2099-02-28', amount: 958.9 }], terminations: [], note: 'Převzato z POHODY.' }],
    opening: [], terminations: [], unmatched_terminations: [],
    warnings: [{ code: 'item_without_origin', item_no: '99CR0002', account_code: '384', source_document_no: 'SYN9905', message: 'bez vzniku' }],
    summary: { items: 1, new: 1, terminated: 0, unpaired_sources: 0, opening_remainder: 0, without_origin: 1 },
    ...changes,
  }
}

describe('AccrualTakeover', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('náhled převzetí ukáže položku bez zápisu vzniku jako varování s důvodem', async () => {
    m.takeoverPreview.mockResolvedValue(preview())
    const wrapper = shallowMount(AccrualTakeover)
    await flushPromises()
    const items = wrapper.findAll('[data-test="takeover-warning"]')
    expect(items).toHaveLength(1)
    expect(items[0].text()).toContain('accruals.takeover.warning.item_without_origin')
    expect(items[0].text()).toContain('99CR0002')
  })

  it('bez varování blok upozornění nezobrazí', async () => {
    m.takeoverPreview.mockResolvedValue(preview({ warnings: [] }))
    const wrapper = shallowMount(AccrualTakeover)
    await flushPromises()
    expect(wrapper.find('[data-test="takeover-warnings"]').exists()).toBe(false)
  })
})
