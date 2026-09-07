import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { CashRegister } from '@/api/cash'

const m = vi.hoisted(() => ({
  listRegisters: vi.fn(),
  createRegister: vi.fn(),
  updateRegister: vi.fn(),
  deleteRegister: vi.fn(),
  listAccounts: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
}))

vi.mock('@/api/cash', () => ({
  cashApi: {
    listRegisters: m.listRegisters,
    createRegister: m.createRegister,
    updateRegister: m.updateRegister,
    deleteRegister: m.deleteRegister,
  },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.listAccounts } }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: m.toastWarning }),
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v) }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry' } }),
}))
vi.mock('@/components/ui/EmptyState.vue', () => ({
  default: { name: 'EmptyState', props: ['title', 'dense', 'accent', 'icon'], template: '<div />' },
}))
vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { check: 'M0 0', x: 'M0 0', trash: 'M0 0', uturn: 'M0 0' },
  btnFilled: () => 'btn-filled',
  btnOutline: () => 'btn-outline',
  btnIconSm: () => 'btn-icon-sm',
  disabledTitle: (d: boolean, r?: string | null) => (d && r ? r : undefined),
  BTN_DISABLED_NOTE: 'note',
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import CashRegisterManager from '@/components/cash/CashRegisterManager.vue'

function reg(overrides: Partial<CashRegister> = {}): CashRegister {
  return {
    id: 1, supplier_id: 1, name: 'Hlavní', currency_code: 'CZK', account_code: '211.100',
    account_id: 5, account_name: 'Pokladna', is_default: true, is_active: true,
    documents_count: 0, balance: 0, balance_date: '2093-01-01', created_at: '2093-01-01',
    ...overrides,
  } as CashRegister
}

async function mountManager(registers: CashRegister[]) {
  m.listRegisters.mockResolvedValue(registers)
  const wrapper = mount(CashRegisterManager)
  await flushPromises()
  return wrapper
}

describe('CashRegisterManager.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listAccounts.mockResolvedValue([{ id: 5, account_code: '211.100', name: 'Pokladna', is_active: true }])
    m.createRegister.mockResolvedValue(reg())
    m.updateRegister.mockResolvedValue(reg())
    m.deleteRegister.mockResolvedValue(true)
  })

  it('má jediné Uložit — žádné tlačítko per řádek ani per sekce', async () => {
    const wrapper = await mountManager([reg(), reg({ id: 2, name: 'Vedlejší', is_default: false })])
    const labels = wrapper.findAll('button').map(b => b.text()).filter(Boolean)
    expect(labels.filter(l => l === 'common.save')).toHaveLength(1)
    // Vytvoření pokladny už nemá vlastní tlačítko.
    expect(labels).not.toContain('cash.register_create')
  })

  it('bez změny je Uložit zašedlé a řekne proč', async () => {
    const wrapper = await mountManager([reg()])
    const vm = wrapper.vm as any
    expect(vm.dirty).toBe(false)
    expect(vm.canSave).toBe(false)
    expect(vm.blockedReason).toBe('cash.register_nothing_to_save')
  })

  it('úpravy řádku i nová pokladna odejdou jedním uložením', async () => {
    const wrapper = await mountManager([reg(), reg({ id: 2, name: 'Vedlejší', is_default: false })])
    const vm = wrapper.vm as any

    vm.rows[1].name = 'Přejmenovaná'
    vm.rows[1].is_active = false
    vm.form.name = 'Nová pokladna'
    vm.form.account_code = '211.100'
    await flushPromises()

    expect(vm.dirty).toBe(true)
    await vm.saveAll()

    expect(m.updateRegister).toHaveBeenCalledTimes(1)
    expect(m.updateRegister).toHaveBeenCalledWith(2, expect.objectContaining({ name: 'Přejmenovaná', is_active: false }))
    expect(m.createRegister).toHaveBeenCalledWith(expect.objectContaining({ name: 'Nová pokladna' }))
    expect(m.toastSuccess).toHaveBeenCalled()
  })

  it('nezměněný řádek se neposílá', async () => {
    const wrapper = await mountManager([reg(), reg({ id: 2, name: 'Vedlejší', is_default: false })])
    const vm = wrapper.vm as any
    vm.rows[0].name = 'Hlavní pokladna'
    await vm.saveAll()

    expect(m.updateRegister).toHaveBeenCalledTimes(1)
    expect(m.updateRegister).toHaveBeenCalledWith(1, expect.objectContaining({ name: 'Hlavní pokladna' }))
  })

  it('smazání je označení v řádku a provede se až uložením (žádný nativní confirm)', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm')
    const wrapper = await mountManager([reg(), reg({ id: 2, name: 'Vedlejší', is_default: false })])
    const vm = wrapper.vm as any

    vm.rows[1].remove = true
    await flushPromises()
    expect(m.deleteRegister).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('cash.register_delete_pending')

    await vm.saveAll()
    expect(confirmSpy).not.toHaveBeenCalled()
    expect(m.deleteRegister).toHaveBeenCalledWith(2)
    // Smazaný řádek se už neaktualizuje.
    expect(m.updateRegister).not.toHaveBeenCalled()
  })

  it('pokladna s doklady poradí deaktivaci místo syrové chyby', async () => {
    m.deleteRegister.mockRejectedValue({
      response: { data: { error: { code: 'cash.error.register_has_documents', message: 'Pokladnu s doklady nelze smazat.' } } },
    })
    const wrapper = await mountManager([reg(), reg({ id: 2, name: 'Vedlejší', is_default: false })])
    const vm = wrapper.vm as any
    vm.rows[1].remove = true
    await vm.saveAll()

    expect(m.toastWarning).toHaveBeenCalledWith('cash.register_deactivate_hint')
    expect(vm.error).toBe('Pokladnu s doklady nelze smazat.')
  })

  it('prázdný název řádku uložit nedá a hlásí větu, ne popisek pole', async () => {
    const wrapper = await mountManager([reg()])
    const vm = wrapper.vm as any
    vm.rows[0].name = ''
    await flushPromises()

    expect(vm.canSave).toBe(false)
    expect(vm.blockedReason).toBe('cash.validation.name')
    expect(vm.blockedReason).not.toBe('cash.register_name')
  })

  it('výchozí pokladna zůstává právě jedna', async () => {
    const wrapper = await mountManager([reg(), reg({ id: 2, name: 'Vedlejší', is_default: false })])
    const vm = wrapper.vm as any
    vm.pickDefault(vm.rows[1])
    expect(vm.rows.filter((r: any) => r.is_default)).toHaveLength(1)

    vm.pickNewDefault(true)
    expect(vm.rows.filter((r: any) => r.is_default)).toHaveLength(0)
    expect(vm.form.is_default).toBe(true)
  })
})
