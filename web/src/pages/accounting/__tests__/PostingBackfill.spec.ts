import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ status: vi.fn() }))

vi.mock('@/api/postingBackfill', () => ({
  postingBackfillApi: { status: m.status, start: vi.fn(), job: vi.fn(), cancel: vi.fn() },
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'err' }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }) }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: unknown) => (p ? `${key}${JSON.stringify(p)}` : key) }),
}))
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))

import PostingBackfill from '../PostingBackfill.vue'

function pending(patch: Record<string, number>) {
  return { cash_documents: 0, invoices: 0, purchase_invoices: 0, bank_transactions: 0, settlements: 0, total: 0, ...patch }
}

async function mountWith(p: Record<string, number>) {
  m.status.mockResolvedValue({ pending: pending(p), jobs: [] })
  const wrapper = mount(PostingBackfill)
  await flushPromises()
  return wrapper
}

describe('PostingBackfill', () => {
  beforeEach(() => vi.clearAllMocks())

  it('při čekajících bankovních pohybech netvrdí, že je všechno zaúčtované, a odkáže do fronty', async () => {
    const wrapper = await mountWith({ bank_transactions: 3, total: 3 })
    expect(wrapper.find('[data-test="backfill-nothing"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="backfill-invoices-done"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="backfill-link-bank"]').attributes('href')).toBe('/bank?tab=posting')
  })

  it('bez čekajících pohybů ukáže, že je všechno zaúčtované', async () => {
    const wrapper = await mountWith({})
    expect(wrapper.find('[data-test="backfill-nothing"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="backfill-manual"]').exists()).toBe(false)
  })
})
