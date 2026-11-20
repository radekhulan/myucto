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
  setStatus: vi.fn(),
  deleteSchedule: vi.fn(),
  setTitle: vi.fn(),
  translated: [] as string[],
}))

vi.mock('@/api/otherItemPlans', () => ({
  otherItemPlansApi: { schedules: m.schedules, installments: m.installments, schedule: m.schedule,
    planLoan: m.planLoan, setInstallments: m.setInstallments, createSchedule: m.createSchedule,
    setStatus: m.setStatus, deleteSchedule: m.deleteSchedule, setTitle: m.setTitle },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.canWrite }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' } }) }))
const toastError = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: toastError, success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč`, statusBadgeClass: (s: string) => `badge badge-${s}` }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key,
    te: (key: string) => m.translated.includes(key) }),
}))

import OtherItemPlans from '../OtherItemPlans.vue'
import OtherItemScheduleTitleFields from '../OtherItemScheduleTitleFields.vue'

/** Dialog se slotem i patičkou, ať jdou ovládat jeho pole a tlačítka. */
const ModalStub = { template: '<div data-test="modal"><slot /><slot name="footer" /></div>' }
const stubs = { RouterLink: true, Modal: ModalStub }

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
    m.translated = []
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
    m.setInstallments.mockImplementation((_id: number, rows: object[]) => Promise.resolve(rows.map((row, index) => ({ ...row, id: index + 1 }))))
    const wrapper = shallowMount(OtherItemPlans, {
      props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) },
      global: { stubs },
    })
    await flushPromises()
    // Kalendář se zakládá v dialogu z akce „Rozložit na splátky“; karta je jen přehled uložených splátek.
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
    ;(wrapper.vm as unknown as { openInstallments: () => void }).openInstallments()
    await flushPromises()
    expect(wrapper.find('[data-test="installments-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="loan-plan"]').exists()).toBe(false)
    await wrapper.find('[data-test="installment-components"]').trigger('change')
    await wrapper.find('[data-test="loan-plan-run"]').trigger('click')
    await flushPromises()
    expect(m.planLoan).toHaveBeenCalledWith(9, expect.objectContaining({ method: 'annuity', frequency: 'monthly', count: 12 }))
    expect(wrapper.findAll('[data-test="installment-draft-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="installment-principal-total"]').classes()).not.toContain('text-danger-700')

    // Úprava úroku přepočítá částku splátky.
    await wrapper.findAll('[data-test="installment-interest_amount"]')[1]!.setValue(15)
    expect(wrapper.findAll('[data-test="installment-draft-amount"]')[1]!.text()).toContain('1525 Kč')
    await wrapper.get('[data-test="installments-save"]').trigger('click')
    await flushPromises()
    expect(m.setInstallments).toHaveBeenCalledWith(9, [
      { due_on: '2099-02-01', amount: 1530, principal_amount: 1500, interest_amount: 20, fee_amount: 10 },
      { due_on: '2099-03-01', amount: 1525, principal_amount: 1500, interest_amount: 15, fee_amount: 10 },
    ])
    // Po uložení se dialog zavře a karta ukáže přehled se složkami splátky.
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="installments-card"] [data-test="installment-row"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-test="installment-amount"]')[1]!.text()).toBe('1525 Kč')
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

  it('zrušení rozepsaného kalendáře dialog zavře a nic neuloží', async () => {
    m.installments.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs } })
    await flushPromises()
    ;(wrapper.vm as unknown as { openInstallments: () => void }).openInstallments()
    await flushPromises()
    expect(wrapper.find('[data-test="installments-form"]').exists()).toBe(true)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: true, canSplit: true, editing: true })
    await wrapper.get('[data-test="installments-split"]').trigger('click')
    expect(wrapper.findAll('[data-test="installment-draft-row"]')).toHaveLength(12)
    await wrapper.get('[data-test="installments-cancel"]').trigger('click')
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
    expect(m.setInstallments).not.toHaveBeenCalled()
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ editing: false })
  })

  it('úprava uloženého kalendáře v dialogu kartu nezmění, dokud se neuloží', async () => {
    m.installments.mockResolvedValue([
      { id: 1, position: 1, due_on: '2099-02-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
      { id: 2, position: 2, due_on: '2099-03-01', amount: 1500, paid_amount: 0, remaining_amount: 1500, status: 'unpaid' },
    ])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-test="installments-edit"]').trigger('click')
    expect(wrapper.findAll('[data-test="installment-draft-row"]')).toHaveLength(2)
    await wrapper.findAll('[data-test="installment-amount-input"]')[0]!.setValue(1000)
    expect(wrapper.findAll('[data-test="installments-card"] [data-test="installment-amount"]')[0]!.text()).toBe('1500 Kč')
    await wrapper.get('[data-test="installments-cancel"]').trigger('click')
    expect(wrapper.findAll('[data-test="installment-amount"]')[0]!.text()).toBe('1500 Kč')
    // Znovu otevřený dialog začíná z uloženého stavu, ne z neuložené úpravy.
    await wrapper.get('[data-test="installments-edit"]').trigger('click')
    expect((wrapper.findAll('[data-test="installment-amount-input"]')[0]!.element as HTMLInputElement).value).toBe('1500')
  })

  it('kalendář s úhradou má Upravit zamčené s důvodem', async () => {
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item() }, global: { stubs } })
    await flushPromises()
    const edit = wrapper.get('[data-test="installments-edit"]')
    expect(edit.attributes('disabled')).toBeDefined()
    expect(edit.attributes('title')).toBe('other_items.plans.paid_locked')
    expect(edit.attributes('aria-describedby')).toBe('other-item-installments-locked')
    expect(wrapper.get('[data-test="installments-locked"]').text()).toBe('other_items.plans.paid_locked')
    await edit.trigger('click')
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
    // Stornovaná položka kalendář neupravuje vůbec; zámek „po přiřazení úhrady“ by tu lhal.
    const reversed = shallowMount(OtherItemPlans, { props: { item: item({ status: 'reversed', paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs } })
    await flushPromises()
    expect(reversed.find('[data-test="installments-card"]').exists()).toBe(true)
    expect(reversed.find('[data-test="installments-edit"]').exists()).toBe(false)
    expect(reversed.find('[data-test="installments-locked"]').exists()).toBe(false)
    m.canWrite = false
    const reader = shallowMount(OtherItemPlans, { props: { item: item() }, global: { stubs } })
    await flushPromises()
    expect(reader.find('[data-test="installments-edit"]').exists()).toBe(false)
    expect(reader.find('[data-test="installments-locked"]').exists()).toBe(false)
  })

  it('úprava opakování je dialog z karty: pozastavení a zrušení tam, generování a automatika zůstávají v kartě', async () => {
    m.installments.mockResolvedValue([])
    const active = { id: 4, source_item_id: 9, frequency: 'monthly', anchor_on: '2099-01-01', due_days: 14, ends_on: null,
      next_index: 1, status: 'active', auto_post: false, template: {}, next_on: '2099-02-01', occurrences: [] }
    m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
    m.schedule.mockResolvedValue(active)
    m.setStatus.mockResolvedValue({ ...active, status: 'paused', next_on: null })
    m.deleteSchedule.mockResolvedValue(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs } })
    await flushPromises()
    const card = wrapper.get('[data-test="schedule-card"]')
    expect(card.find('[data-test="schedule-generate"]').exists()).toBe(true)
    expect(card.find('[data-test="schedule-auto-post-toggle"]').exists()).toBe(true)
    expect(card.find('[data-test="schedule-toggle"]').exists()).toBe(false)
    expect(card.find('[data-test="schedule-delete"]').exists()).toBe(false)
    await wrapper.get('[data-test="schedule-edit"]').trigger('click')
    expect(wrapper.find('[data-test="schedule-edit-form"]').exists()).toBe(true)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ editing: true })
    await wrapper.get('[data-test="schedule-toggle"]').trigger('click')
    await flushPromises()
    expect(m.setStatus).toHaveBeenCalledWith(4, 'paused')
    expect(wrapper.get('[data-test="schedule-status"]').text()).toBe('other_items.plans.paused')
    await wrapper.get('[data-test="schedule-delete"]').trigger('click')
    await flushPromises()
    expect(m.deleteSchedule).toHaveBeenCalledWith(4)
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="schedule-card"]').exists()).toBe(false)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: true, editing: false })
  })

  it('chyba s kódem other_items.error.* se ukáže přeloženě, jinak hláška serveru', async () => {
    m.translated = ['other_items.error.invalid_source_item']
    m.schedules.mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.invalid_source_item', message: 'Česká hláška.' } } } })
    shallowMount(OtherItemPlans, { props: { item: item() }, global: { stubs } })
    await flushPromises()
    expect(toastError).toHaveBeenLastCalledWith('other_items.error.invalid_source_item')
    m.schedules.mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.unknown', message: 'Česká hláška.' } } } })
    shallowMount(OtherItemPlans, { props: { item: item({ id: 10 }) }, global: { stubs } })
    await flushPromises()
    expect(toastError).toHaveBeenLastCalledWith('Česká hláška.')
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
    expect(m.createSchedule).toHaveBeenCalledWith(9, { frequency: 'quarterly', ends_on: null, auto_post: false, title_template: null, title_sync_period: false })
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
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(true)
    expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSplit: false })
    await wrapper.get('[data-test="installments-edit"]').trigger('click')
    await wrapper.get('[data-test="installments-clear"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="installments-card"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
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
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs } })
    await flushPromises()
    ;(wrapper.vm as unknown as { openInstallments: () => void }).openInstallments()
    await flushPromises()
    await wrapper.get('[data-test="installments-split"]').trigger('click')
    await wrapper.get('[data-test="installments-save"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="installments-card"] [data-test="installment-row"]')).toHaveLength(2)
    await wrapper.get('[data-test="installments-edit"]').trigger('click')
    await wrapper.get('[data-test="installments-clear"]').trigger('click')
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

  it('tichá obnova detailu (nový objekt položky se stejnými údaji) plány načte znovu', async () => {
    m.installments.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs: { RouterLink: true } } })
    await flushPromises()
    await wrapper.setProps({ item: item({ paid_amount: 0, remaining_amount: 3000 }) })
    await flushPromises()
    expect(m.schedules).toHaveBeenCalledTimes(2)
  })

  it('karta opakování řadí údaje do dvojic: četnost s koncem, příští termín s názvem', async () => {
    m.installments.mockResolvedValue([])
    m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
    m.schedule.mockResolvedValue({ id: 4, source_item_id: 9, frequency: 'monthly', anchor_on: '2099-01-01', due_days: 14,
      ends_on: null, next_index: 1, status: 'active', auto_post: false, template: {}, next_on: '2099-02-01', next_title: 'Nájem 02',
      occurrences: [], created_at: '2099-01-01 10:00:00', created_by_name: 'Syntetický uživatel', finished: false, source_active: true })
    const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000 }) }, global: { stubs } })
    await flushPromises()
    const rows = wrapper.findAll('[data-test="schedule-card"] dl > div')
    expect(rows.map(row => row.get('dt').text())).toEqual([
      'other_items.plans.frequency', 'other_items.plans.ends_label',
      'other_items.plans.next_on', 'other_items.plans.next_title',
      'other_items.plans.created_by',
    ])
    expect(rows.map(row => row.classes().includes('sm:col-span-2'))).toEqual([false, false, false, false, true])
  })

  describe('název opakování v dialozích', () => {
    const open = { item: item({ paid_amount: 0, remaining_amount: 3000 }) }
    const base = { id: 4, source_item_id: 9, frequency: 'monthly', anchor_on: '2099-01-01', due_days: 14, ends_on: null,
      next_index: 2, status: 'active', auto_post: false, next_on: '2099-03-01', occurrences: [], template: { title: 'A' },
      next_title: 'A', finished: false, source_active: true }
    const fields = (wrapper: ReturnType<typeof shallowMount>) => wrapper.getComponent(OtherItemScheduleTitleFields)
    beforeEach(() => { m.installments.mockResolvedValue([]) })

    it('založení: náhled dostane četnost a konec, šablona jde do založení, chyba s polem zůstane u pole', async () => {
      m.createSchedule.mockRejectedValueOnce({ response: { data: { error: {
        code: 'other_items.error.unknown_title_placeholder', field: 'title_template', message: 'Neznámý znak {FOO}.' } } } })
      const wrapper = shallowMount(OtherItemPlans, { props: open, global: { stubs } })
      await flushPromises()
      ;(wrapper.vm as unknown as { openSchedule: () => void }).openSchedule()
      await flushPromises()
      expect(fields(wrapper).props('frequency')).toBe('monthly')
      expect(fields(wrapper).props('endsOn')).toBeNull()
      expect(fields(wrapper).props('itemId')).toBe(9)
      await wrapper.get('[data-test="schedule-form"] select').setValue('quarterly')
      await flushPromises()
      expect(fields(wrapper).props('frequency')).toBe('quarterly')
      fields(wrapper).vm.$emit('update:modelValue', { title_template: 'Nájem {FOO}', title_sync_period: true })
      await flushPromises()
      await wrapper.get('[data-test="schedule-form"]').trigger('submit')
      await flushPromises()
      expect(m.createSchedule).toHaveBeenCalledWith(9, expect.objectContaining({ frequency: 'quarterly', title_template: 'Nájem {FOO}', title_sync_period: true }))
      expect(wrapper.find('[data-test="modal"]').exists()).toBe(true)
      expect(fields(wrapper).props('submitError')).toMatchObject({ code: 'other_items.error.unknown_title_placeholder', field: 'title_template' })
      expect(toastError).not.toHaveBeenCalled()
      // Oprava textu starou hlášku u pole smaže.
      fields(wrapper).vm.$emit('update:modelValue', { title_template: 'Nájem {MM}', title_sync_period: true })
      await flushPromises()
      expect(fields(wrapper).props('submitError')).toBeNull()
    })

    it('založení: změna četnosti smaže starou chybu u pole, chyba bez pole jde do toastu, chyba konce k poli Konec', async () => {
      m.createSchedule
        .mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.occurrence_title_too_long', field: 'title_template', message: 'x' } } } })
        .mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.invalid_status', message: 'Neaktivní.' } } } })
        .mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.invalid_end', field: 'ends_on', message: 'x' } } } })
      const wrapper = shallowMount(OtherItemPlans, { props: open, global: { stubs } })
      await flushPromises()
      ;(wrapper.vm as unknown as { openSchedule: () => void }).openSchedule()
      await flushPromises()
      await wrapper.get('[data-test="schedule-form"]').trigger('submit')
      await flushPromises()
      expect(fields(wrapper).props('submitError')).not.toBeNull()
      await wrapper.get('[data-test="schedule-form"] select').setValue('quarterly')
      await flushPromises()
      expect(fields(wrapper).props('submitError')).toBeNull()
      await wrapper.get('[data-test="schedule-form"]').trigger('submit')
      await flushPromises()
      expect(toastError).toHaveBeenCalledTimes(1)
      expect(fields(wrapper).props('submitError')).toBeNull()
      await wrapper.get('[data-test="schedule-form"]').trigger('submit')
      await flushPromises()
      expect(wrapper.find('[data-test="schedule-ends-error"]').exists()).toBe(true)
      expect(toastError).toHaveBeenCalledTimes(1)
    })

    it('úprava: dialog načte rozvrh znovu, převezme šablonu, Uložit název ji uloží a karta ukáže název příští položky', async () => {
      m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
      m.schedule.mockResolvedValue({ ...base, template: { title: 'Nájem', title_template: 'Nájem {MM}' }, next_title: 'Nájem 03',
        occurrences: [{ occurrence_index: 0, item_id: 9, issued_on: '2099-01-01', status: 'confirmed' },
          { occurrence_index: 1, item_id: 10, issued_on: '2099-02-01', status: 'draft' },
          { occurrence_index: 2, item_id: 11, issued_on: '2099-03-01', status: 'cancelled' }] })
      const wrapper = shallowMount(OtherItemPlans, { props: open, global: { stubs } })
      await flushPromises()
      expect(wrapper.get('[data-test="schedule-next-title"]').text()).toBe('Nájem 03')
      const loads = m.schedule.mock.calls.length
      await wrapper.get('[data-test="schedule-edit"]').trigger('click')
      await flushPromises()
      expect(m.schedule.mock.calls.length).toBe(loads + 1)
      expect(wrapper.find('[data-test="schedule-edit-form"]').exists()).toBe(true)
      expect(fields(wrapper).props('modelValue')).toEqual({ title_template: 'Nájem {MM}', title_sync_period: false })
      // Už vytvořené položky se nepřejmenují; i stornovaný výskyt posunul hranici nové šablony.
      expect(wrapper.get('[data-test="schedule-title-created-until"]').text()).toContain('"date":"2099-03-01"')
      // Existující rozvrh: náhled bere jeho příští termín, četnost se nepředává.
      expect(fields(wrapper).props('frequency')).toBeUndefined()
      expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ editing: true })
      fields(wrapper).vm.$emit('update:modelValue', { title_template: 'Nájem Q{Q}', title_sync_period: false })
      await flushPromises()
      let finish: () => void = () => {}
      m.setTitle.mockImplementationOnce(() => new Promise(resolve => {
        finish = () => resolve({ ...base, template: { title: 'Nájem', title_template: 'Nájem Q{Q}' }, next_title: 'Nájem Q1' })
      }))
      await wrapper.get('[data-test="schedule-title-form"]').trigger('submit')
      await flushPromises()
      // Během ukládání dialog nejde zavřít ani rozvrh zrušit.
      expect(wrapper.get('[data-test="schedule-close"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="schedule-title-submit"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="schedule-delete"]').attributes('disabled')).toBeDefined()
      finish()
      await flushPromises()
      expect(m.setTitle).toHaveBeenCalledWith(4, { title_template: 'Nájem Q{Q}', title_sync_period: false })
      expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="schedule-next-title"]').text()).toBe('Nájem Q1')
    })

    it('úprava: chyba názvu s polem zůstane u pole a dialog otevřený, bez pole jde do toastu', async () => {
      m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
      m.schedule.mockResolvedValue(base)
      m.setTitle
        .mockRejectedValueOnce({ response: { data: { error: { code: 'other_items.error.occurrence_title_too_long', field: 'title_template', message: 'x' } } } })
        .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'other_items.error.schedule_finished', message: 'Skončilo.' } } } })
      const wrapper = shallowMount(OtherItemPlans, { props: open, global: { stubs } })
      await flushPromises()
      await wrapper.get('[data-test="schedule-edit"]').trigger('click')
      await flushPromises()
      await wrapper.get('[data-test="schedule-title-form"]').trigger('submit')
      await flushPromises()
      expect(wrapper.find('[data-test="schedule-edit-form"]').exists()).toBe(true)
      expect(fields(wrapper).props('submitError')).toMatchObject({ field: 'title_template' })
      expect(toastError).not.toHaveBeenCalled()
      fields(wrapper).vm.$emit('update:modelValue', { title_template: 'Kratší', title_sync_period: false })
      await flushPromises()
      expect(fields(wrapper).props('submitError')).toBeNull()
      await wrapper.get('[data-test="schedule-title-form"]').trigger('submit')
      await flushPromises()
      expect(toastError).toHaveBeenCalledTimes(1)
      expect(fields(wrapper).props('submitError')).toBeNull()
    })

    it('Uložit název chybí u skončeného rozvrhu a stornovaného zdroje; pozastavený před koncem ho má', async () => {
      m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
      const variants: Array<[Record<string, unknown>, Partial<OtherItem>, boolean]> = [
        [{ finished: true, next_on: null, next_title: null, ends_on: '2099-02-28' }, {}, false],
        [{ status: 'paused', next_on: null, finished: false }, {}, true],
        [{ status: 'paused', next_on: null, next_title: null, finished: true }, {}, false],
        [{}, { status: 'cancelled' }, false],
      ]
      for (const [schedule, overrides, editable] of variants) {
        m.schedule.mockResolvedValue({ ...base, ...schedule })
        const wrapper = shallowMount(OtherItemPlans, { props: { item: item({ paid_amount: 0, remaining_amount: 3000, ...overrides }) }, global: { stubs } })
        await flushPromises()
        await wrapper.get('[data-test="schedule-edit"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-test="schedule-edit-form"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="schedule-title-form"]').exists()).toBe(editable)
        expect(wrapper.find('[data-test="schedule-title-submit"]').exists()).toBe(editable)
        expect(wrapper.find('[data-test="schedule-close"]').exists()).toBe(true)
        wrapper.unmount()
      }
    })

    it('zastaralé odpovědi: znovunačtení ani uložení názvu pro předchozí položku neotevře dialog ani nepřepíše kartu', async () => {
      m.schedules.mockImplementation((id: number) => Promise.resolve([{ id: id === 9 ? 4 : 5, source_item_id: id }]))
      m.schedule.mockImplementation((id: number) => Promise.resolve({ ...base, id, source_item_id: id === 4 ? 9 : 12,
        template: { title: id === 4 ? 'A' : 'B' }, next_title: id === 4 ? 'A' : 'B' }))
      const wrapper = shallowMount(OtherItemPlans, { props: open, global: { stubs } })
      await flushPromises()
      let resolveOld: (v: unknown) => void = () => {}
      m.schedule.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      await wrapper.get('[data-test="schedule-edit"]').trigger('click')
      await wrapper.setProps({ item: item({ id: 12, paid_amount: 0, remaining_amount: 3000 }) })
      await flushPromises()
      resolveOld({ ...base, id: 4, source_item_id: 9, template: { title: 'A' }, next_title: 'A' })
      await flushPromises()
      expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="schedule-next-title"]').text()).toBe('B')

      await wrapper.get('[data-test="schedule-edit"]').trigger('click')
      await flushPromises()
      let resolveSave: (v: unknown) => void = () => {}
      m.setTitle.mockImplementationOnce(() => new Promise(resolve => { resolveSave = resolve }))
      await wrapper.get('[data-test="schedule-title-form"]').trigger('submit')
      await wrapper.setProps({ item: item({ id: 9, paid_amount: 0, remaining_amount: 3000 }) })
      await flushPromises()
      resolveSave({ ...base, id: 5, source_item_id: 12, template: { title: 'B' }, next_title: 'B uložený' })
      await flushPromises()
      expect(wrapper.get('[data-test="schedule-next-title"]').text()).toBe('A')
    })

    it('úprava smazaného rozvrhu (404): hláška a karta zmizí bez nového načtení plánů', async () => {
      m.schedules.mockResolvedValue([{ id: 4, source_item_id: 9 }])
      m.schedule.mockResolvedValue(base)
      const wrapper = shallowMount(OtherItemPlans, { props: open, global: { stubs } })
      await flushPromises()
      const loads = m.schedules.mock.calls.length
      m.schedule.mockRejectedValueOnce({ response: { status: 404, data: { error: { code: 'other_items.error.schedule_not_found', message: 'x' } } } })
      await wrapper.get('[data-test="schedule-edit"]').trigger('click')
      await flushPromises()
      expect(toastError).toHaveBeenCalledTimes(1)
      expect(wrapper.find('[data-test="modal"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="schedule-card"]').exists()).toBe(false)
      expect(m.schedules.mock.calls.length).toBe(loads)
      expect(wrapper.emitted('state')!.at(-1)![0]).toMatchObject({ canSchedule: true })
    })
  })
})
