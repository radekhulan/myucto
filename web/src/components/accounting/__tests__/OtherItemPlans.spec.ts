import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { OtherItem } from '@/api/otherItems'

const m = vi.hoisted(() => ({
  schedules: vi.fn(),
  installments: vi.fn(),
}))

vi.mock('@/api/otherItemPlans', () => ({
  otherItemPlansApi: { schedules: m.schedules, installments: m.installments, schedule: vi.fn() },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč` }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))

import OtherItemPlans from '../OtherItemPlans.vue'

const item = (overrides: Partial<OtherItem> = {}): OtherItem => ({
  id: 9, source_kind: 'manual', source_id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr',
  partner_name: null, issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000,
  paid_amount: 1400, remaining_amount: 1600, status: 'confirmed', certainty: 'confirmed', editable: false,
  document_count: 0, source_url: null, ...overrides,
})

describe('OtherItemPlans', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.schedules.mockResolvedValue([])
    m.installments.mockResolvedValue([
      { id: 1, position: 1, due_on: '2099-01-10', amount: 1000, paid_amount: 1000, remaining_amount: 0, status: 'paid' },
      { id: 2, position: 2, due_on: '2099-02-10', amount: 1000, paid_amount: 400, remaining_amount: 600, status: 'partial' },
      { id: 3, position: 3, due_on: '2000-03-10', amount: 1000, paid_amount: 0, remaining_amount: 1000, status: 'overdue' },
    ])
  })

  it('u každé splátky ukáže stav a u částečné úhrady zbytek', async () => {
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item() }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    const badges = wrapper.findAll('[data-test="installment-status"]')
    expect(badges.map(badge => badge.text())).toEqual([
      'other_items.plans.installment_status.paid',
      'other_items.plans.installment_status.partial · other_items.plans.installment_remaining:{"amount":"600 Kč"}',
      'other_items.plans.installment_status.overdue',
    ])
    expect(badges[2]!.classes()).toContain('bg-danger-50')
  })

  it('po přiřazení úhrady načte stav splátek znovu', async () => {
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item() }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(m.installments).toHaveBeenCalledTimes(1)
    await wrapper.setProps({ item: item({ paid_amount: 2000, remaining_amount: 1000 }) })
    await flushPromises()
    expect(m.installments).toHaveBeenCalledTimes(2)
  })
})
