import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { BalanceInventoryReport } from '@/api/accounting'

// ── Mockovaný stav (hoisted) ─────────────────────────────────────────────────
const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  getClosingInventory: vi.fn(),
  getBalanceInventory: vi.fn(),
  saveClosingInventory: vi.fn(),
  exportReport: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: m.listPeriods,
    getClosingInventory: m.getClosingInventory,
    getBalanceInventory: m.getBalanceInventory,
    saveClosingInventory: m.saveClosingInventory,
    exportReport: m.exportReport,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: m.toastWarning }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { download: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))

// ActivationBanner dělá síťové volání — nahradíme prázdným stubem.
vi.mock('@/components/settings/activation/ActivationBanner.vue', () => ({
  default: { name: 'ActivationBanner', template: '<div />' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import BalanceInventory from '@/pages/accounting/BalanceInventory.vue'

function makeReport(overrides: Partial<BalanceInventoryReport> = {}): BalanceInventoryReport {
  return {
    period: { id: 5, fiscal_year: 2093, starts_on: '2093-01-01', ends_on: '2093-12-31' } as any,
    as_of: '2093-12-31',
    entity: { name: 'Test s.r.o.', ico: '123', address: 'Praha', prepared_at: '2093-12-31 10:00' },
    draft_count: 0,
    rows: [
      { account_id: 11, account_code: '221', name: 'Bankovní účet', account_type: 'asset' as any, normal_side: 'debit' as any,
        ks_md: 1000, ks_d: 0, documentation_hint: 'Výpis', book_balance: 1000, counted_balance: null, difference: null, resolution: 'open', item_note: null, resolved: false },
      { account_id: 12, account_code: '411', name: 'Základní kapitál', account_type: 'equity' as any, normal_side: 'credit' as any,
        ks_md: 0, ks_d: 1000, documentation_hint: 'Rozpis', book_balance: -1000, counted_balance: null, difference: null, resolution: 'open', item_note: null, resolved: false },
    ],
    count: 2,
    totals: { ks_md: 1000, ks_d: 1000 },
    inventory: {
      status: 'in_progress', responsible_person: null, inventory_date: null, protocol_ref: null,
      note: null, item_count: 0, unresolved_count: 2, completed: false, can_close: false,
    },
    row_version: 3,
    ...overrides,
  }
}

describe('BalanceInventory.vue', () => {
  beforeEach(() => {
    m.listPeriods.mockReset()
    m.getClosingInventory.mockReset()
    m.getBalanceInventory.mockReset()
    m.saveClosingInventory.mockReset()
    m.toastSuccess.mockReset()
    m.toastError.mockReset()
    m.toastWarning.mockReset()
    m.listPeriods.mockResolvedValue([{ id: 5, supplier_id: 1, fiscal_year: 2093, starts_on: '2093-01-01', ends_on: '2093-12-31', status: 'open', closed_at: null, created_at: '' }])
  })

  it('(a) načte inventarizaci z uzávěrky a vykreslí editovatelné řádky + hlavičku', async () => {
    m.getClosingInventory.mockResolvedValue(makeReport())
    const wrapper = mount(BalanceInventory)
    await flushPromises()

    expect(m.getClosingInventory).toHaveBeenCalledWith(5)
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    // Editovatelné pole skutečného stavu (open období) + hlavička odpovědné osoby.
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('accounting.balance_inventory.unresolved')
  })

  it('(b) uloží inventarizaci — payload nese skutečný stav a resolved dle shody', async () => {
    m.getClosingInventory.mockResolvedValue(makeReport())
    m.saveClosingInventory.mockResolvedValue({ status: 'in_progress', unresolved_count: 1, item_count: 2, completed: false, ok: false, row_version: 4 })
    const wrapper = mount(BalanceInventory)
    await flushPromises()

    // Napočítej účet 221 na účetní hodnotu (1000 → shoda, auto-resolved).
    const countedInputs = wrapper.findAll('tbody input[inputmode="decimal"]')
    expect(countedInputs).toHaveLength(2)
    await countedInputs[0].setValue('1000')

    // „Uložit" (bez dokončení).
    const saveBtn = wrapper.findAll('button').find(b => b.text() === 'accounting.balance_inventory.save')
    expect(saveBtn).toBeTruthy()
    await saveBtn!.trigger('click')
    await flushPromises()

    expect(m.saveClosingInventory).toHaveBeenCalledTimes(1)
    const [pid, payload] = m.saveClosingInventory.mock.calls[0]
    expect(pid).toBe(5)
    expect(payload.row_version).toBe(3)
    expect(payload.complete).toBe(false)
    const acc221 = payload.items.find((i: any) => i.account_id === 11)
    expect(acc221.counted_balance).toBe(1000)
    expect(acc221.resolution).toBe('resolved') // shoda s book → vyřešeno
    const acc411 = payload.items.find((i: any) => i.account_id === 12)
    expect(acc411.counted_balance).toBeNull()
    expect(acc411.resolution).toBe('open')
  })

  it('(d) pasivní účet se ukazuje i zadává kladně, na server jde znaménková báze', async () => {
    // Bez orientace na normální stranu ukazoval účet 411 zůstatek −1000 a kladně
    // zapsaný skutečný stav dělal dvojnásobný rozdíl.
    m.getClosingInventory.mockResolvedValue(makeReport())
    m.saveClosingInventory.mockResolvedValue({ status: 'in_progress', unresolved_count: 1, item_count: 2, completed: false, ok: false, row_version: 4 })
    const wrapper = mount(BalanceInventory)
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    expect(rows[1].text()).toContain('1000')
    expect(rows[1].text()).not.toContain('-1000')

    const countedInputs = wrapper.findAll('tbody input[inputmode="decimal"]')
    await countedInputs[1].setValue('1000')
    await flushPromises()

    // Kladně zadaná shoda = nulový rozdíl, tedy vyřešený řádek.
    const saveBtn = wrapper.findAll('button').find(b => b.text() === 'accounting.balance_inventory.save')
    await saveBtn!.trigger('click')
    await flushPromises()

    const acc411 = m.saveClosingInventory.mock.calls[0][1].items.find((i: any) => i.account_id === 12)
    expect(acc411.counted_balance).toBe(-1000) // zpět na bázi MD − D
    expect(acc411.resolution).toBe('resolved')
  })

  it('(e) uložený skutečný stav se hydratuje na normální stranu účtu', async () => {
    const r = makeReport()
    r.rows[1].counted_balance = -1000
    r.rows[1].resolution = 'resolved'
    m.getClosingInventory.mockResolvedValue(r)
    m.saveClosingInventory.mockResolvedValue({ status: 'in_progress', unresolved_count: 1, item_count: 2, completed: false, ok: false, row_version: 4 })
    const wrapper = mount(BalanceInventory)
    await flushPromises()

    const countedInputs = wrapper.findAll('tbody input[inputmode="decimal"]')
    expect((countedInputs[1].element as HTMLInputElement).value).toBe('1000')

    // Uložení beze změny nesmí hodnotu překlopit — round-trip drží znaménko.
    const saveBtn = wrapper.findAll('button').find(b => b.text() === 'accounting.balance_inventory.save')
    await saveBtn!.trigger('click')
    await flushPromises()

    const acc411 = m.saveClosingInventory.mock.calls[0][1].items.find((i: any) => i.account_id === 12)
    expect(acc411.counted_balance).toBe(-1000)
  })

  it('(c) dokončení s nevyřešeným rozdílem → backend vrací completed:false → warning', async () => {
    m.getClosingInventory.mockResolvedValue(makeReport())
    m.saveClosingInventory.mockResolvedValue({ status: 'in_progress', unresolved_count: 2, item_count: 2, completed: false, ok: false, row_version: 4 })
    const wrapper = mount(BalanceInventory)
    await flushPromises()

    const completeBtn = wrapper.findAll('button').find(b => b.text() === 'accounting.balance_inventory.save_complete')
    await completeBtn!.trigger('click')
    await flushPromises()

    expect(m.saveClosingInventory).toHaveBeenCalledTimes(1)
    expect(m.saveClosingInventory.mock.calls[0][1].complete).toBe(true)
    expect(m.toastWarning).toHaveBeenCalled()
  })
})
