import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Reconciliation from '../MembershipReconciliation.vue'
import { formatDateRange } from '../membershipFormat'
import type { ReconciliationTotals } from '@/api/nonprofitMembership'
import { formatMoneyMinor } from '@/composables/useFormat'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ reconciliation: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; run: () => void }>)
    .map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))),
})
const period = { id: 11, supplier_id: 5, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', status: 'open' as const, closed_at: null, created_at: '' }
const totals = (cents: number): ReconciliationTotals => ({
  claims: { row_count: 1, cents }, operations: { row_count: 1, cents },
  ledger: { operation_count: 1, receivable_cents: cents, revenue_cents: cents, invalid_count: 0 },
  status: 'balanced', difference_cents: { claims_to_operations: 0, operations_to_receivable: 0, receivable_to_revenue: 0 }, proof_complete: true,
})
const plugins = () => [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })]

beforeEach(() => {
  vi.clearAllMocks()
  api.reconciliation.mockResolvedValue(totals(120050))
})

describe('Membership reconciliation', () => {
  it('lists periods and amounts in the app formats', async () => {
    const w = mount(Reconciliation, { props: { supplier: 5, periods: [period] }, global: { plugins: plugins(), stubs: { ActionBar } } })
    expect(w.get('[data-field="reconcile-period"]').text()).toContain(formatDateRange('2026-01-01', '2026-12-31'))
    await w.get('[data-field="reconcile-period"]').setValue('11')
    await w.get('[data-action="reconcile"]').trigger('click')
    await flushPromises()
    expect(api.reconciliation).toHaveBeenCalledWith(5, 11)
    expect(w.get('[data-field="reconcile-claims"]').text()).toBe(formatMoneyMinor(120050))
    w.unmount()
  })

  it('shows a loading state while the check is running', async () => {
    api.reconciliation.mockReturnValue(new Promise(() => {}))
    const w = mount(Reconciliation, { props: { supplier: 5, periods: [period] }, global: { plugins: plugins(), stubs: { ActionBar } } })
    expect(w.find('[data-section="reconcile-loading"]').exists()).toBe(false)
    await w.get('[data-field="reconcile-period"]').setValue('11')
    await w.get('[data-action="reconcile"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-section="reconcile-loading"]').exists()).toBe(true)
    w.unmount()
  })

  it('drops a shown result once a write makes it outdated', async () => {
    const w = mount(Reconciliation, { props: { supplier: 5, periods: [period], dataVersion: 0 }, global: { plugins: plugins(), stubs: { ActionBar } } })
    await w.get('[data-field="reconcile-period"]').setValue('11')
    await w.get('[data-action="reconcile"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-section="reconcile-result"]').exists()).toBe(true)
    expect(w.get('[data-section="reconcile-result"] dl').classes()).toEqual(expect.arrayContaining(['sm:grid-cols-2', 'lg:grid-cols-4']))
    expect(w.get('[data-field="reconcile-status"]').classes()).toContain('text-success-600')
    await w.setProps({ dataVersion: 1 })
    expect(w.find('[data-section="reconcile-result"]').exists()).toBe(false)
    expect(api.reconciliation).toHaveBeenCalledTimes(1)
    w.unmount()
  })
})
