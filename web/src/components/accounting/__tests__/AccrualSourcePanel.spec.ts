import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  sourceCandidates: vi.fn(),
  canWrite: true,
}))

vi.mock('@/api/accruals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/accruals')>()
  return { ...actual, accrualsApi: { sourceCandidates: m.sourceCandidates } }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.canWrite }) }))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (v: string) => v,
  formatMoney: (v: number) => `${v} Kč`,
  statusBadgeClass: (status: string) => `badge badge-${status}`,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import AccrualSourcePanel from '../AccrualSourcePanel.vue'

const RouterLinkStub = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }

function candidates(changes: Record<string, unknown> = {}) {
  return {
    source_type: 'purchase_invoice', source_id: 12, document_no: 'PF-1', document_date: '2099-01-05', partner_id: 3,
    partner_name: 'Syntetický dodavatel', title: 'Syntetická licence', accrual_lines: [], periods: [], items: [], ...changes,
  }
}
function mountPanel() {
  return mount(AccrualSourcePanel, { props: { sourceType: 'purchase_invoice', sourceId: 12 }, global: { stubs: { RouterLink: RouterLinkStub } } })
}

describe('AccrualSourcePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite = true
  })

  it('bez řádků 38x a bez položek se nezobrazí', async () => {
    m.sourceCandidates.mockResolvedValue(candidates())
    const wrapper = mountPanel()
    await flushPromises()
    expect(m.sourceCandidates).toHaveBeenCalledWith('purchase_invoice', 12)
    expect(wrapper.find('[data-test="accrual-source-panel"]').exists()).toBe(false)
  })

  it('ukáže existující položky s proklikem a z řádku založí předvyplněnou položku', async () => {
    m.sourceCandidates.mockResolvedValue(candidates({
      accrual_lines: [{ entry_id: 70, entry_date: '2099-01-05', account_code: '381', account_name: 'Náklady příštích období', kind: 'prepaid_expense', amount: 1200 }],
      periods: [{ item_id: null, from: '2099-01-01', to: '2099-12-31' }],
      items: [{ id: 8, item_no: 'CR-2099-0002', title: 'Syntetická licence', status: 'active' }],
    }))
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="accrual-source-panel"]').exists()).toBe(true)
    const links = wrapper.findAll('[data-test="accrual-panel-items"] a')
    expect(JSON.parse(links[0].attributes('data-to')!)).toEqual({ name: 'accounting-accrual-detail', params: { id: 8 } })
    const create = wrapper.get('[data-test="accrual-panel-create-line"]')
    expect(JSON.parse(create.attributes('data-to')!)).toEqual({
      name: 'accounting-accrual-new',
      query: { source_type: 'purchase_invoice', source_id: '12', origin_entry_id: '70', origin_amount: '1200', account: '381', amount: '1200',
        from: '2099-01-01', to: '2099-12-31', partner_id: '3', partner_name: 'Syntetický dodavatel', title: 'Syntetická licence' },
    })
  })

  it('bez práva zápisu jen zobrazí, nezakládá', async () => {
    m.canWrite = false
    m.sourceCandidates.mockResolvedValue(candidates({
      accrual_lines: [{ entry_id: 70, entry_date: '2099-01-05', account_code: '381', account_name: 'Náklady příštích období', kind: 'prepaid_expense', amount: 1200 }],
    }))
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="accrual-source-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="accrual-panel-create-line"]').exists()).toBe(false)
  })

  it('chyba načtení panel skryje', async () => {
    m.sourceCandidates.mockRejectedValue(new Error('x'))
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="accrual-source-panel"]').exists()).toBe(false)
  })

  it('řádek dokladu neurčuje sám, vybere ho editor (i u jediného řádku s obdobím)', async () => {
    m.sourceCandidates.mockResolvedValue(candidates({
      accrual_lines: [{ entry_id: 70, entry_date: '2099-01-05', account_code: '381', account_name: 'Náklady příštích období', kind: 'prepaid_expense', amount: 1200 }],
      periods: [{ item_id: 44, from: '2099-01-01', to: '2099-12-31' }],
    }))
    const wrapper = mountPanel()
    await flushPromises()
    const query = JSON.parse(wrapper.get('[data-test="accrual-panel-create-line"]').attributes('data-to')!).query
    expect(query.source_item_id).toBeUndefined()
  })

  it('řádek odložený uzávěrkou nabídne převzetí do agendy se zůstatkem k datu', async () => {
    m.sourceCandidates.mockResolvedValue(candidates({
      periods: [{ item_id: 44, from: '2099-07-01', to: '2100-06-30' }],
      closing_deferrals: [{ deferral_entry_id: 90, account_code: '381', period_ends_on: '2099-12-31', basis: 36600, period_from: '2099-07-01',
        period_to: '2100-06-30', deferred: 18200, released: 0, remaining: 18200, opening_as_of: '2100-01-01', source_item_id: 44 }],
    }))
    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="accrual-source-panel"]').exists()).toBe(true)
    const query = JSON.parse(wrapper.get('[data-test="accrual-panel-handover"]').attributes('data-to')!).query
    expect(query).toMatchObject({ source_type: 'purchase_invoice', source_id: '12', source_item_id: '44', closing_handover: '1', account: '381' })
  })
})
