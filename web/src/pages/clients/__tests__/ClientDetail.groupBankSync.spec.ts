import { describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({ sync: vi.fn(), info: vi.fn(), success: vi.fn() }))
vi.mock('vue-i18n', async original => ({ ...await original<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', async original => ({ ...await original<typeof import('vue-router')>(), useRoute: () => ({ params: { id: '1' } }), useRouter: () => ({}) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useWindowTitle', () => ({ useWindowTitle: vi.fn() }))
vi.mock('@/composables/useWindowRefresh', () => ({ useWindowRefresh: vi.fn() }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ info: mocks.info, success: mocks.success, error: vi.fn() }) }))
vi.mock('@/api/clients', () => ({ TAX_NUMBER_LABELS: {}, clientsApi: { get: async () => null, syncBankAccountsFromRegistry: mocks.sync } }))
vi.mock('@/api/invoices', () => ({ invoicesApi: { listGrouped: async () => ({ data: [], meta: { total: 0 } }) } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { listGrouped: async () => ({ data: [] }) } }))
vi.mock('@/api/recurring', () => ({ recurringApi: { list: async () => ({ data: [] }) } }))

import ClientDetail from '../ClientDetail.vue'

describe('ClientDetail — synchronizace účtů skupiny DPH', () => {
  it('vysvětlí sdílené účty skupiny místo nepravdivé hlášky o prázdném registru', async () => {
    const accounts = [{ id: 1, account_number: '1000000005', bank_code: '0100', source: 'manual' }]
    mocks.sync.mockResolvedValue({ found: false, source: 'group_registration', synced: 0, accounts })
    const wrapper = shallowMount(ClientDetail)
    await flushPromises()
    const state = (wrapper.vm.$ as unknown as { setupState: Record<string, any> }).setupState
    state.client = { id: 1, language: 'cs', company_name: 'Syntetická firma', country_iso2: 'CZ', dic: 'CZ699000001', bank_accounts: accounts }
    await state.syncBankAccountsFromRegistry()
    expect(mocks.sync).toHaveBeenCalledWith(1)
    expect(mocks.info).toHaveBeenCalledWith('client.bank_accounts.sync_group')
    expect(mocks.info).not.toHaveBeenCalledWith('client.bank_accounts.sync_empty')
    expect(mocks.success).not.toHaveBeenCalled()
    expect(state.client.bank_accounts).toEqual(accounts)
    wrapper.unmount()
  })
})
