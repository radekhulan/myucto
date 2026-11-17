import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const m = vi.hoisted(() => ({
  purchasePreview: vi.fn(),
  purchaseRenumber: vi.fn(),
  agendaPreview: vi.fn(),
  agendaRenumber: vi.fn(),
  listRegisters: vi.fn(),
  replace: vi.fn(),
  writable: new Set<string>(),
  query: {} as Record<string, string>,
  mode: 'double_entry',
}))

vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: { renumberPreview: m.purchasePreview, renumber: m.purchaseRenumber },
}))
vi.mock('@/api/renumber', () => ({
  renumberApi: { preview: m.agendaPreview, renumber: m.agendaRenumber },
}))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: m.listRegisters } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'chyba' }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (key: string) => m.writable.has(key),
    isClientRole: false,
    hasCommercialFeatures: true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.mode } }),
}))
const route = reactive({ query: m.query })
vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ replace: m.replace }),
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import Renumber from '../Renumber.vue'

const emptyPlan = { period: '209908', changes: [], kept: [], blocked: [] }

function cashPlan() {
  return {
    period: '209908',
    changes: [
      { id: 2, number: 'VPD-2099-0002', new_number: 'VPD-2099-0001', changed: true, date: '2099-08-05', partner: 'Syntetický partner', description: 'Kancelář', amount: 100, currency: 'CZK', status: 'posted', doc_type: 'out' },
      { id: 1, number: 'VPD-2099-0001', new_number: 'VPD-2099-0002', changed: true, date: '2099-08-20', partner: 'Syntetický partner', description: 'Pošta', amount: 50, currency: 'CZK', status: 'posted', doc_type: 'out' },
    ],
    kept: [{ id: 3, number: 'PPD-2099-0001', date: '2099-08-01', partner: '', description: 'Tržba', amount: 10, currency: 'CZK', status: 'posted', doc_type: 'in', reason: 'cash_in' }],
    blocked: [],
  }
}

describe('Renumber', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.writable.clear()
    for (const key of Object.keys(route.query)) delete route.query[key]
    m.mode = 'double_entry'
    m.purchasePreview.mockResolvedValue(emptyPlan)
    m.agendaPreview.mockResolvedValue(emptyPlan)
    m.listRegisters.mockResolvedValue([{ id: 7, name: 'Hlavní', is_default: true }, { id: 8, name: 'Vedlejší', is_default: false }])
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('ukáže jen záložky agend s právem zápisu a výchozí jsou přijaté doklady', async () => {
    m.writable = new Set(['purchase_invoices', 'other_items'])
    const wrapper = mount(Renumber)
    await flushPromises()

    const tabs = wrapper.findAll('[role="tab"]').map(t => t.attributes('data-test'))
    expect(tabs).toEqual(['renumber-tab-purchase', 'renumber-tab-other_receivable', 'renumber-tab-other_payable'])
    expect(m.purchasePreview).toHaveBeenCalled()
    expect(m.agendaPreview).not.toHaveBeenCalled()

    await wrapper.find('[data-test="renumber-tab-other_payable"]').trigger('click')
    expect(m.replace).toHaveBeenCalledWith({ query: { agenda: 'other_payable' } })
  })

  it('bez práva k přijatým otevře první dostupnou agendu, interní doklady jen v podvojném účetnictví', async () => {
    m.writable = new Set(['accounting.journal.write'])
    m.mode = 'tax_evidence'
    const wrapper = mount(Renumber)
    await flushPromises()
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(0)
    expect(m.agendaPreview).not.toHaveBeenCalled()
    expect(m.purchasePreview).not.toHaveBeenCalled()

    m.mode = 'double_entry'
    const second = mount(Renumber)
    await flushPromises()
    expect(m.agendaPreview).toHaveBeenCalledWith('internal', expect.any(String), { register_id: undefined })
    second.unmount()
  })

  it('pokladna načte pokladny, náhled podle výchozí pokladny a přečísluje ji', async () => {
    m.writable = new Set(['purchase_invoices', 'cash.document.write'])
    route.query.agenda = 'cash'
    m.agendaPreview.mockResolvedValue(cashPlan())
    m.agendaRenumber.mockResolvedValue({ ...cashPlan(), renumbered: 2 })
    const wrapper = mount(Renumber)
    await flushPromises()

    expect(m.listRegisters).toHaveBeenCalledTimes(1)
    expect(m.agendaPreview).toHaveBeenCalledWith('cash', expect.any(String), { register_id: 7 })
    expect(wrapper.text()).toContain('VPD-2099-0002')
    expect(wrapper.text()).toContain('cash.type.out_short')
    expect(m.purchasePreview).not.toHaveBeenCalled()

    await wrapper.find('[data-test="renumber-register"]').setValue('8')
    await flushPromises()
    expect(m.agendaPreview).toHaveBeenLastCalledWith('cash', expect.any(String), { register_id: 8 })

    await wrapper.find('[data-test="renumber-run"]').trigger('click')
    await flushPromises()
    expect(m.agendaRenumber).toHaveBeenCalledWith('cash', expect.any(String), { register_id: 8 })
  })
})
