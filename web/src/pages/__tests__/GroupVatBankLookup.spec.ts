import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({ ares: vi.fn(), bank: vi.fn() }))
vi.mock('vue-i18n', async original => ({ ...await original<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ replace: vi.fn() }), useRoute: () => ({ query: {} }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ fetchSetupStatus: vi.fn(), needsSetup: true, canRead: () => false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useSupplierSwitch', () => ({ useSupplierSwitch: () => ({ switchTo: vi.fn() }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/api/auth', () => ({ authApi: { setupAresLookup: mocks.ares, setupCrpdphLookup: mocks.bank } }))
vi.mock('@/api/clients', () => ({ clientsApi: { lookupAres: mocks.ares, lookupBank: mocks.bank } }))
vi.mock('@/api/suppliers', () => ({ suppliersApi: { directory: async () => [] } }))
vi.mock('@/api/diagnostics', () => ({ diagnosticsApi: { preflight: async () => null } }))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))

import Setup from '@/pages/Setup.vue'
import SuppliersPage from '@/pages/admin/SuppliersPage.vue'
import { isCzechVatGroup } from '@/utils/vatGroup'

const account = { prefix: '', number: '1000000005', bank_code: '0100', iban: '', display: '1000000005/0100' }

for (const page of [Setup, SuppliersPage]) {
  const setup = page === Setup
  describe(`${setup ? 'Setup' : 'SuppliersPage'} — účty skupinového DIČ`, () => {
    beforeEach(() => vi.clearAllMocks())

    async function lookup(dic: string, count: number, unreliable = false) {
      mocks.ares.mockResolvedValue({ found: true, data: { company_name: 'Syntetická firma', dic, is_vat_payer: true } })
      mocks.bank.mockResolvedValue({ found: true, unreliable, accounts: Array.from({ length: count }, (_, i) => ({ ...account, bank_code: i ? '0300' : '0100', display: i ? '1000000005/0300' : account.display })) })
      const wrapper = mount(page, { global: { stubs: { RouterLink: true, SupplierUrgency: true } } })
      await flushPromises()
      // Rozpracovaný formulář nastavíme bez závislosti na průchodu wizardem.
      const state = (wrapper.vm.$ as unknown as { setupState: Record<string, any> }).setupState
      if (setup) state.step = 2
      else state.newSupplier()
      const draft = setup ? state.supplier : state.supplierDraft
      const bank = setup ? state.supplier : state.supplierBank
      draft.ic = '00000019'
      Object.assign(bank, { account_number: '1000000005', bank_code: '0800', bank_name: 'Ručně zadaná banka', bic: 'TESTCZPP' })
      await (setup ? state.lookupAres() : state.supplierLookupAres())
      await flushPromises()
      return { wrapper, bank }
    }

    it.each([1, 2])('po ARES zachová bankovní údaje a nabídne výběr ze %i účtů skupiny', async count => {
      const { wrapper, bank } = await lookup('CZ699000001', count)
      expect(mocks.bank).toHaveBeenCalledWith('699000001')
      expect(bank.account_number).toBe('1000000005')
      expect(setup ? bank.bank_currency : bank.currency).toBe('CZK')
      expect(bank.iban).toBe('')
      expect(bank.bank_code).toBe('0800')
      expect(bank.bank_name).toBe('Ručně zadaná banka')
      expect(bank.bic).toBe('TESTCZPP')
      expect(wrapper.text()).toContain('supplier.bank_lookup_group')
      const choices = wrapper.findAll('button').filter(button => /^1000000005\/(0100|0300)$/.test(button.text()))
      expect(choices).toHaveLength(count)
      await choices[count - 1]!.trigger('click')
      expect(bank.bank_code).toBe(count > 1 ? '0300' : '0100')
      wrapper.unmount()
    })

    it.each([1, 2])('u běžného DIČ nadále automaticky vyplní první z %i účtů', async count => {
      const { wrapper, bank } = await lookup('CZ00000019', count)
      expect(bank.bank_code).toBe('0100')
      expect(wrapper.text()).toContain(count > 1 ? 'supplier.bank_lookup_many' : 'supplier.bank_lookup_one')
      wrapper.unmount()
    })

    it('u nespolehlivé skupiny zachová i pokyn k výběru účtu', async () => {
      const { wrapper, bank } = await lookup('CZ699000001', 1, true)
      expect(bank.bank_code).toBe('0800')
      expect(wrapper.text()).toContain('supplier.bank_lookup_group')
      expect(wrapper.text()).toContain('supplier.bank_lookup_unreliable')
      wrapper.unmount()
    })
  })
}

describe('isCzechVatGroup', () => {
  it.each(['CZ699000001', '699000001', 'cz699000001'])('rozpozná %s', dic => {
    expect(isCzechVatGroup(dic)).toBe(true)
  })
  it.each(['CZ00000019', 'CZ69900001', 'CZ6990000001', 'SK699000001', ''])('neoznačí %s jako českou skupinu', dic => {
    expect(isCzechVatGroup(dic)).toBe(false)
  })
})
