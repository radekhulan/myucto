import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { ChartAccount } from '@/api/accounting'
import type { BankPostingRulePayload } from '@/api/bankPosting'
import RuleForm from '../RuleForm.vue'

const mocks = vi.hoisted(() => ({ list: vi.fn() }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => `${v} Kč`, formatDate: String }))
vi.mock('@/api/settings', () => ({ settingsApi: { listCurrencies: async () => [] } }))
vi.mock('@/api/otherItems', () => ({ otherItemsApi: { list: mocks.list } }))
vi.mock('@/api/bankPosting', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/bankPosting')>(),
  bankPostingApi: { listAccounts: vi.fn(async () => []), dryRunRule: vi.fn() },
}))

const accounts = [{ id: 1, account_code: '221.100', name: 'Synthetic account', is_active: true, parent_id: null }] as ChartAccount[]
const stubs = { ChartAccountSelect: { props: ['modelValue', 'accounts'], template: '<div />' }, DimensionFields: { template: '<div />' } }
const base = {
  name: 'Synthetic rule', direction: 'outgoing', debit_account_code: '', credit_account_code: '221.100',
  counterparty_account: '1000000005', other_item_id: null,
} as unknown as BankPostingRulePayload
const item = (id: number, status: string, currency = 'CZK') => ({
  id: `manual:${id}`, source_id: id, document_no: `OZ-${id}`, title: 'Syntetické nájemné', partner_name: 'Syntetický pronajímatel',
  status, currency, remaining_amount: 1200, account_code: '325',
})

describe('RuleForm other item target', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.list.mockResolvedValue({ items: [item(5, 'posted'), item(6, 'draft'), item(7, 'confirmed', 'EUR')] })
  })

  it('offers open confirmed CZK items of the rule direction and fills the item account', async () => {
    const wrapper = mount(RuleForm, { props: { modelValue: base, accounts, mode: 'create', otherItemTarget: true }, global: { stubs } })
    await flushPromises()
    expect(mocks.list).toHaveBeenCalledWith(expect.objectContaining({ side: 'payable', status: 'open' }))
    const select = wrapper.get('[data-test="rule-other-item"]')
    expect(select.findAll('option').map(o => o.text())).toEqual([
      'bank.posting.rule_other_item_none', 'OZ-5 · Syntetický pronajímatel · 1200 Kč',
    ])
    await select.setValue(select.findAll('option')[1]!.element.value)
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toEqual(expect.objectContaining({ other_item_id: 5, debit_account_code: '325' }))
    wrapper.unmount()
  })

  it('hides the choice when items cannot be read', async () => {
    mocks.list.mockRejectedValue(new Error('403'))
    const wrapper = mount(RuleForm, { props: { modelValue: base, accounts, mode: 'create', otherItemTarget: true }, global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="rule-other-item"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
