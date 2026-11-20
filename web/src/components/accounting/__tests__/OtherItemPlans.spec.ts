import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { OtherItem } from '@/api/otherItems'

const m = vi.hoisted(() => ({
  canWrite: true,
  schedules: vi.fn(),
  installments: vi.fn(),
  planLoan: vi.fn(),
  setInstallments: vi.fn(),
  schedule: vi.fn(),
  createSchedule: vi.fn(),
}))

vi.mock('@/api/otherItemPlans', () => ({
  otherItemPlansApi: { schedules: m.schedules, installments: m.installments, schedule: m.schedule,
    planLoan: m.planLoan, setInstallments: m.setInstallments, createSchedule: m.createSchedule },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.canWrite }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč`, statusBadgeClass: (s: string) => `badge badge-${s}` }))
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
    m.canWrite = true
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

  it('návrh úvěru vyplní jistinu, úrok a poplatek a uloží je se součtem splátky', async () => {
    m.installments.mockResolvedValue([])
    m.planLoan.mockResolvedValue([
      { due_on: '2099-02-01', amount: 1530, principal_amount: 1500, interest_amount: 20, fee_amount: 10 },
      { due_on: '2099-03-01', amount: 1520, principal_amount: 1500, interest_amount: 10, fee_amount: 10 },
    ])
    m.setInstallments.mockImplementation((_id: number, rows: unknown[]) => Promise.resolve(rows))
    const wrapper = shallowMount(OtherItemPlans, {
      props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) },
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
    // Prázdný kalendář se ukáže až po akci „Rozložit na splátky“ v liště detailu.
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
    ;(wrapper.vm as unknown as { openInstallments: () => void }).openInstallments()
    await flushPromises()
    expect(wrapper.find('[data-test="loan-plan"]').exists()).toBe(false)
    await wrapper.find('[data-test="installment-components"]').trigger('change')
    await wrapper.find('[data-test="loan-plan-run"]').trigger('click')
    await flushPromises()
    expect(m.planLoan).toHaveBeenCalledWith(9, expect.objectContaining({ method: 'annuity', frequency: 'monthly', count: 12 }))
    expect(wrapper.findAll('[data-test="installment-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="installment-principal-total"]').classes()).not.toContain('text-danger-700')

    // Úprava úroku přepočítá částku splátky.
    await wrapper.findAll('[data-test="installment-interest_amount"]')[1]!.setValue(15)
    expect(wrapper.findAll('[data-test="installment-amount"]')[1]!.text()).toContain('1525 Kč')
    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')!
    await save.trigger('click')
    await flushPromises()
    expect(m.setInstallments).toHaveBeenCalledWith(9, [
      { due_on: '2099-02-01', amount: 1530, principal_amount: 1500, interest_amount: 20, fee_amount: 10 },
      { due_on: '2099-03-01', amount: 1525, principal_amount: 1500, interest_amount: 15, fee_amount: 10 },
    ])
  })

  it('úhrada samotného úroku kalendář zamkne, i když jistina zůstala celá', async () => {
    m.installments.mockResolvedValue([
      { id: 1, position: 1, due_on: '2099-01-10', amount: 1100, principal_amount: 1000, interest_amount: 100, fee_amount: 0,
        paid_amount: 100, remaining_amount: 1000, status: 'partial' },
      { id: 2, position: 2, due_on: '2099-02-10', amount: 2050, principal_amount: 2000, interest_amount: 50, fee_amount: 0,
        paid_amount: 0, remaining_amount: 2050, status: 'unpaid' },
    ])
    const wrapper = shallowMount(OtherItemPlans, {
      props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) },
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="installment-components"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('other_items.plans.paid_locked')
    // Zamčený kalendář je jen přehled: tabulka bez zamčených polí.
    expect(wrapper.findAll('[data-test="installments-table"] [data-test="installment-row"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="installment-principal_amount"]')).toHaveLength(0)
    expect(wrapper.get('[data-test="installments-table"]').text()).toContain('other_items.plans.interest_amount')
  })

  it('bez rozvrhu a splátek nic nekreslí a nabídne obě akce do lišty, u vygenerované položky ne opakování', async () => {
    m.installments.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(wrapper.find('[data-test="schedule-card"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: true, canSplit: true })

    const generated = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000,
      occurrence: { schedule_id: 4, occurrence_index: 2, source_item_id: 7, source_document_no: 'OZ-2099-0001', source_title: 'Zdroj', schedule_created_at: '2099-01-01 10:00:00', created_by_name: 'Syntetický účetní' } }) },
      global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(generated.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: false, canSplit: true })
  })

  it('zrušení rozepsaného kalendáře sekci zase schová', async () => {
    m.installments.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    ;(wrapper.vm as unknown as { openInstallments: () => void }).openInstallments()
    await flushPromises()
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(true)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: true, canSplit: false })
    await wrapper.get('[data-test="installments-cancel"]').trigger('click')
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
  })

  it('zdroj opakování ukáže přehled: další termín, kdo založil a vytvořené položky s čísly', async () => {
    m.installments.mockResolvedValue([])
    m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
    m.schedule.mockResolvedValue({ id: 4, source_item_id: 9, frequency: 'monthly', anchor_on: '2099-01-01', due_days: 14, ends_on: null,
      next_index: 3, status: 'active', auto_post: true, template: {}, created_at: '2099-01-01 10:00:00', created_by_name: 'Syntetický účetní',
      next_on: '2099-04-01', occurrences: [
        { occurrence_index: 0, item_id: 9, issued_on: '2099-01-01', status: 'confirmed', document_no: 'OZ-2099-0001' },
        { occurrence_index: 1, item_id: 10, issued_on: '2099-02-01', status: 'confirmed', document_no: 'OZ-2099-0002' },
        { occurrence_index: 2, item_id: 11, issued_on: '2099-03-01', status: 'draft', document_no: null },
      ] })
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()
    expect(wrapper.get('[data-test="schedule-next"]').text()).toBe('2099-04-01')
    expect(wrapper.get('[data-test="schedule-created-by"]').text()).toContain('Syntetický účetní')
    expect(wrapper.find('[data-test="schedule-auto-post"]').exists()).toBe(false)
    expect((wrapper.get('[data-test="schedule-auto-post-toggle"]').element as HTMLInputElement).checked).toBe(true)
    const rows = wrapper.findAll('[data-test="schedule-occurrences"] li').map(row => row.text())
    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('OZ-2099-0002')
    expect(rows[1]).toContain('#11')
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: false, canSplit: true })
  })

  it('nastavení opakování je dialog z akce v liště; po uložení se zavře a ukáže přehled', async () => {
    m.installments.mockResolvedValue([])
    m.createSchedule.mockResolvedValue({ id: 5, source_item_id: 9, frequency: 'quarterly', anchor_on: '2099-01-01', due_days: 14, ends_on: null,
      next_index: 1, status: 'active', auto_post: false, template: {}, next_on: '2099-04-01', occurrences: [] })
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) },
      global: { stubs: { RouterLink: true, Modal: { template: '<div data-test="modal"><slot /><slot name="footer" /></div>' } } } })
    await flushPromises()
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
    ;(wrapper.vm as unknown as { openSchedule: () => void }).openSchedule()
    await flushPromises()
    await wrapper.get('[data-test="schedule-form"] select').setValue('quarterly')
    await wrapper.get('[data-test="schedule-form"]').trigger('submit')
    await flushPromises()
    expect(m.createSchedule).toHaveBeenCalledWith(9, { frequency: 'quarterly', ends_on: null, auto_post: false })
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="schedule-card"]').exists()).toBe(true)
  })

  it('opakování nenabídne u storna ani bez práva zápisu; uhrazená položka nenabídne splátky', async () => {
    m.installments.mockResolvedValue([])
    const mountWith = async (changes: Partial<OtherItem>) => {
      const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000, ...changes }) }, global: { stubs: { RouterLink: true } } })
      await flushPromises()
      return wrapper.emitted('state')!.at(-1)![0]
    }
    expect(await mountWith({ status: 'cancelled' })).toMatchObject({ canSchedule: false, canSplit: false })
    expect(await mountWith({ paid_amount: 100, remaining_amount: 2900 })).toMatchObject({ canSchedule: true, canSplit: false })
    m.canWrite = false
    expect(await mountWith({})).toMatchObject({ canSchedule: false, canSplit: false })
  })

  it('zrušení uloženého kalendáře kartu schová a akce se vrátí', async () => {
    m.installments.mockResolvedValue([
      { id: 1, position: 1, due_on: '2099-02-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
      { id: 2, position: 2, due_on: '2099-03-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
    ])
    m.setInstallments.mockResolvedValue([])
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(true)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSplit: false })
    await wrapper.findAll('button').find(button => button.text().includes('other_items.plans.clear_installments'))!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSplit: true })
  })

  it('kalendář otevřený akcí, uložený a pak zrušený kartu schová', async () => {
    m.installments.mockResolvedValue([])
    const saved = [
      { id: 1, position: 1, due_on: '2099-02-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
      { id: 2, position: 2, due_on: '2099-03-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
    ]
    m.setInstallments.mockResolvedValueOnce(saved).mockResolvedValueOnce([])
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    await (wrapper.vm as unknown as { openInstallments: () => Promise<void> }).openInstallments()
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text().includes('other_items.plans.split'))!.trigger('click')
    await wrapper.findAll('button').find(button => button.text().includes('common.save'))!.trigger('click')
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text().includes('other_items.plans.clear_installments'))!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
  })

  it('přehled bez práva zápisu ukáže automatické účtování jako údaj, pozastavený bez dalšího termínu', async () => {
    m.canWrite = false
    m.installments.mockResolvedValue([])
    m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
    m.schedule.mockResolvedValue({ id: 4, source_item_id: 9, frequency: 'yearly', anchor_on: '2099-01-01', due_days: 14, ends_on: '2100-12-31',
      next_index: 1, status: 'paused', auto_post: false, template: {}, created_at: '2099-01-01 10:00:00', created_by_name: null, next_on: null, occurrences: [] })
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(wrapper.get('[data-test="schedule-status"]').text()).toBe('other_items.plans.paused')
    expect(wrapper.get('[data-test="schedule-next"]').text()).toBe('—')
    expect(wrapper.get('[data-test="schedule-auto-post"]').text()).toBe('common.no')
    expect(wrapper.find('[data-test="schedule-auto-post-toggle"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="schedule-created-by"]').text()).toContain('other_items.plans.unknown_user')
  })

  it('chyba načtení nabídne opakování a akce v liště nezmizí beze slova', async () => {
    m.schedules.mockRejectedValueOnce(new Error('500'))
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(wrapper.get('[data-test="other-item-plans"]').attributes('hidden')).toBeUndefined()
    m.installments.mockResolvedValue([])
    await wrapper.get('[data-test="plans-failed"] button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="plans-failed"]').exists()).toBe(false)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: true })
  })

  it('pozdní odpověď předchozí položky nepřepíše plány nově otevřené', async () => {
    let resolveFirst!: (rows: unknown[]) => void
    m.installments.mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
    m.installments.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await wrapper.setProps({ item: item({ id: 10, source_id: 10, paid_amount: 0, remaining_amount: 3000 }) })
    await flushPromises()
    resolveFirst([
      { id: 1, position: 1, due_on: '2099-02-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
      { id: 2, position: 2, due_on: '2099-03-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
    ])
    await flushPromises()
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
  })

  it('rozepsaný kalendář a otevřený dialog hlásí detailu rozepsanou práci', async () => {
    m.installments.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) },
      global: { stubs: { RouterLink: true, Modal: { template: '<div><slot /><slot name="footer" /></div>' } } } })
    await flushPromises()
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ editing: false })
    await (wrapper.vm as unknown as { openInstallments: () => Promise<void> }).openInstallments()
    await flushPromises()
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ editing: true })
    await wrapper.get('[data-test="installments-cancel"]').trigger('click')
    ;(wrapper.vm as unknown as { openSchedule: () => void }).openSchedule()
    await flushPromises()
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ editing: true })
  })

  it('storno zdroje plány načte znovu', async () => {
    m.installments.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    await wrapper.setProps({ item: item({ paid_amount: 0, remaining_amount: 3000, status: 'reversed' }) })
    await flushPromises()
    expect(m.schedules).toHaveBeenCalledTimes(2)
  })

  it('během opakovaného načtení jsou tlačítka rozvrhu nad starými daty zamčená', async () => {
    m.installments.mockResolvedValue([])
    m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
    m.schedule.mockResolvedValue({ id: 4, source_item_id: 9, frequency: 'monthly', anchor_on: '2099-01-01', due_days: 14, ends_on: null,
      next_index: 1, status: 'active', auto_post: false, template: {}, next_on: '2099-02-01', occurrences: [] })
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(wrapper.get('[data-test="schedule-generate"]').attributes('disabled')).toBeUndefined()
    m.schedules.mockImplementationOnce(() => new Promise(() => undefined))
    await wrapper.setProps({ item: item({ paid_amount: 100, remaining_amount: 2900 }) })
    await flushPromises()
    expect(wrapper.get('[data-test="schedule-generate"]').attributes('disabled')).toBeDefined()
  })
})
