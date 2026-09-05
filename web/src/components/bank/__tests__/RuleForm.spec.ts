import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { ChartAccount } from '@/api/accounting'
import type { BankPostingRulePayload } from '@/api/bankPosting'
import RuleForm from '../RuleForm.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatDate: String }))
vi.mock('@/api/settings', () => ({ settingsApi: { listCurrencies: async () => [] } }))

const accounts = ['221.100', '518.100', '321.100'].map((code, index) => ({
  id: index + 1, account_code: code, name: 'Synthetic account', is_active: true, parent_id: null,
})) as ChartAccount[]

describe('RuleForm account selection', () => {
  it.each(['incoming', 'outgoing'] as const)('filters the bank side and excludes saldo on the other side for %s', async direction => {
    const wrapper = mount(RuleForm, {
      props: { modelValue: { name: 'Synthetic rule', direction, debit_account_code: '', credit_account_code: '' } as BankPostingRulePayload, accounts, mode: 'create' },
      global: { stubs: { ChartAccountSelect: { name: 'ChartAccountSelect', props: ['modelValue', 'accounts'], emits: ['update:modelValue'], template: '<div />' } } },
    })
    await flushPromises()
    const pickers = wrapper.findAllComponents({ name: 'ChartAccountSelect' })
    const bankIndex = direction === 'incoming' ? 0 : 1
    expect(pickers[bankIndex]!.props('accounts').map((a: ChartAccount) => a.account_code)).toEqual(['221.100'])
    expect(pickers[1 - bankIndex]!.props('accounts').map((a: ChartAccount) => a.account_code)).not.toContain('321.100')
    pickers[0]!.vm.$emit('update:modelValue', direction === 'incoming' ? '221.100' : '518.100')
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toEqual(expect.objectContaining({ debit_account_code: direction === 'incoming' ? '221.100' : '518.100' }))
    wrapper.unmount()
  })
})
