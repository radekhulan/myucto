import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ setPostingOverrides: vi.fn(), toastError: vi.fn() }))

vi.mock('@/api/otherItems', () => ({ otherItemsApi: { setPostingOverrides: m.setPostingOverrides } }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a class="router-link"><slot /></a>' } }))

import OtherItemPostingRules from '../OtherItemPostingRules.vue'

const ChartAccountSelect = {
  props: ['modelValue', 'accounts', 'placeholder'],
  emits: ['update:modelValue'],
  template: '<input class="account" :value="modelValue" :data-codes="accounts.map(a => a.account_code).join(\',\')" @input="$emit(\'update:modelValue\', $event.target.value)" />',
}

function item(status = 'posted', bankSource = 'kind') {
  return {
    id: 7, source_id: 7, side: 'receivable', status, currency: 'CZK', title: 'Syntetická půjčka',
    posting_overrides: {},
    posting_rules: {
      origin: { event: 'origin', source: 'kind', lines: [
        { account_code: '378', side: 'debit', amount: 1200 }, { account_code: '261', side: 'credit', amount: 1200 },
      ], posting_lines: [{ account_code: '261', amount: 1200 }], description: null, description_template: null, kind_id: 3, profile_id: 9 },
      payment_bank: { event: 'payment_bank', source: bankSource, debit_account_code: '221', credit_account_code: '378',
        description: 'Splátka', description_template: 'Splátka', kind_id: 3, profile_id: 10 },
      payment_cash: { event: 'payment_cash', source: 'default', debit_account_code: '211', credit_account_code: '378',
        description: null, description_template: null, kind_id: null, profile_id: null },
    },
  }
}

const accounts = [
  { account_code: '221.001', is_active: true }, { account_code: '211', is_active: true }, { account_code: '518', is_active: true },
]

function factory(props: Record<string, unknown>) {
  return mount(OtherItemPostingRules, {
    props: { accounts, canWrite: true, ...props } as any,
    global: { stubs: { ChartAccountSelect } },
  })
}

describe('OtherItemPostingRules', () => {
  beforeEach(() => vi.clearAllMocks())

  it('shows origin only for drafts and marks the source of each event', () => {
    const posted = factory({ item: item('posted') })
    expect(posted.find('[data-test="posting-rule-origin"]').exists()).toBe(false)
    expect(posted.find('[data-test="posting-rule-payment_bank"] [data-test="posting-source"]').text()).toBe('other_items.posting.source.kind')
    expect(posted.find('[data-test="posting-rule-payment_bank"] .router-link').exists()).toBe(true)

    const draft = factory({ item: item('draft') })
    expect(draft.find('[data-test="posting-rule-origin"]').text()).toContain('378')
    expect(draft.find('[data-test="posting-rule-origin"]').text()).toContain('261')
  })

  it('saves a custom bank posting on the money side and offers only 221 accounts', async () => {
    m.setPostingOverrides.mockResolvedValue(item('posted', 'item'))
    const wrapper = factory({ item: item('posted') })
    await wrapper.find('[data-test="posting-rule-payment_bank"] [data-test="posting-own"]').trigger('click')
    const select = wrapper.find('[data-test="posting-form"] input.account')
    expect(select.attributes('data-codes')).toBe('221.001')
    await select.setValue('221.001')
    await wrapper.find('[data-test="posting-form"]').trigger('submit')
    await flushPromises()
    expect(m.setPostingOverrides).toHaveBeenCalledWith(7, {
      payment_bank: { debit_account_code: '221.001', credit_account_code: null, description_template: 'Splátka' },
    })
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('reverts a custom posting to the inherited one and hides actions without write access', async () => {
    m.setPostingOverrides.mockResolvedValue(item('posted', 'kind'))
    const wrapper = factory({ item: item('posted', 'item') })
    await wrapper.find('[data-test="posting-rule-payment_bank"] [data-test="posting-reset"]').trigger('click')
    await flushPromises()
    expect(m.setPostingOverrides).toHaveBeenCalledWith(7, { payment_bank: null })

    const readonly = factory({ item: item('posted', 'item'), canWrite: false })
    expect(readonly.find('[data-test="posting-own"]').exists()).toBe(false)
  })

  it('shows interest and fee for split installments and saves the revenue account of a receivable', async () => {
    const split = {
      ...item('posted'), has_split_installments: true,
      posting_rules: {
        ...item('posted').posting_rules,
        interest: { event: 'interest', source: 'default', account_code: '662', description: null, description_template: null, kind_id: null, profile_id: null },
        fee: { event: 'fee', source: 'default', account_code: '668', description: null, description_template: null, kind_id: null, profile_id: null },
      },
    }
    m.setPostingOverrides.mockResolvedValue(split)
    const wrapper = factory({ item: split, accounts: [...accounts, { account_code: '662.100', account_type: 'revenue', is_active: true }] })
    expect(wrapper.find('[data-test="posting-rule-interest"]').text()).toContain('662')
    expect(wrapper.find('[data-test="posting-rule-fee"]').text()).toContain('668')
    await wrapper.find('[data-test="posting-rule-interest"] [data-test="posting-own"]').trigger('click')
    const select = wrapper.find('[data-test="posting-form"] input.account')
    expect(select.attributes('data-codes')).toBe('662.100')
    await select.setValue('662.100')
    await wrapper.find('[data-test="posting-form"]').trigger('submit')
    await flushPromises()
    expect(m.setPostingOverrides).toHaveBeenCalledWith(7, {
      interest: { debit_account_code: null, credit_account_code: '662.100', description_template: null },
    })

    const plain = factory({ item: item('posted') })
    expect(plain.find('[data-test="posting-rule-interest"]').exists()).toBe(false)
  })
})
