import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  replace: vi.fn(),
  query: {} as Record<string, string>,
}))
const phoneRef = ref(false)

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('@/composables/useWindowRefresh', () => ({ useWindowRefresh: () => {} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.query }),
  useRouter: () => ({ replace: m.replace }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isCompanyAdminRole: true, isDemo: false, isClientRole: false, hasCommercialFeatures: true,
    canRead: () => true, canWrite: () => true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry' } }),
}))
vi.mock('@/api/bankPosting', () => ({ bankPostingApi: { unpostedCount: vi.fn().mockResolvedValue(3) } }))
vi.mock('../StatementList.vue', () => ({ default: { name: 'StatementList', template: '<div />' } }))
vi.mock('@/pages/admin/BankAccounts.vue', () => ({ default: { name: 'BankAccounts', template: '<div />' } }))
vi.mock('../BankAccountAnalytics.vue', () => ({ default: { name: 'BankAccountAnalytics', template: '<div />' } }))
vi.mock('../PostingSuggestions.vue', () => ({ default: { name: 'PostingSuggestions', template: '<div />' } }))
vi.mock('../UnpostedTransactions.vue', () => ({ default: { name: 'UnpostedTransactions', template: '<div />' } }))

import BankPage from '../BankPage.vue'

async function mountPage() {
  const wrapper = shallowMount(BankPage)
  await flushPromises()
  return wrapper
}

describe('Bankovní účty: záložky na telefonu', () => {
  beforeEach(() => {
    m.replace.mockReset()
    m.query = {}
  })

  it('mimo telefon drží původní pruh všech záložek', async () => {
    phoneRef.value = false
    const wrapper = await mountPage()
    expect(wrapper.find('[data-test="bank-phone-tabs"]').exists()).toBe(false)
    expect(wrapper.findAll('button[data-active], button:not([data-active])').length).toBeGreaterThanOrEqual(7)
    expect(wrapper.text()).toContain('bank.posting.tab_suggestions')
  })

  it('na telefonu ukáže tři hlavní záložky a ostatní ve výběru Více', async () => {
    phoneRef.value = true
    const wrapper = await mountPage()
    const tabs = wrapper.get('[data-test="bank-phone-tabs"]')
    const primary = tabs.findAll('[role="tab"]').map(b => b.attributes('data-test'))
    expect(primary).toEqual(['bank-phone-tab-statements', 'bank-phone-tab-all_movements', 'bank-phone-tab-balances'])
    const options = wrapper.get('[data-test="bank-phone-more"]').findAll('option').map(o => o.attributes('value'))
    expect(options).toEqual(['', 'posting', 'analytics', 'accounts', 'email'])
    expect(wrapper.get('[data-test="bank-phone-more"]').text()).toContain('bank.posting.tab_suggestions (3)')

    await wrapper.get('[data-test="bank-phone-tab-balances"]').trigger('click')
    expect(m.replace).toHaveBeenLastCalledWith({ query: { tab: 'balances' } })
    const select = wrapper.get('[data-test="bank-phone-more"]')
    ;(select.element as HTMLSelectElement).value = 'analytics'
    await select.trigger('change')
    expect(m.replace).toHaveBeenLastCalledWith({ query: { tab: 'analytics' } })
  })

  it('na telefonu zkrátí podzáložky K zaúčtování', async () => {
    phoneRef.value = true
    m.query = { tab: 'posting' }
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('bank.posting.tab_unposted_short')
    expect(wrapper.text()).not.toContain('bank.posting.tab_unposted ')
    expect((wrapper.get('[data-test="bank-phone-more"]').element as HTMLSelectElement).value).toBe('posting')
  })
})
