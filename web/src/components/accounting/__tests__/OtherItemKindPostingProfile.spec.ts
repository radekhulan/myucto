import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({ savePostingProfiles: vi.fn() }))

vi.mock('@/api/otherItems', () => ({ otherItemKindsApi: { savePostingProfiles: m.savePostingProfiles } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import OtherItemKindPostingProfile from '../OtherItemKindPostingProfile.vue'

const ChartAccountSelect = {
  props: ['modelValue', 'accounts', 'placeholder'],
  emits: ['update:modelValue'],
  template: '<input class="account" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
}

function kind(overrides: Record<string, unknown> = {}) {
  return {
    id: null, side: 'payable', builtin: true, builtin_code: 'rent', name: null, own_series: false, is_active: true,
    posting_profiles: { origin: null, payment_bank: null, payment_cash: null },
    posting_suggestion: { debit_account_code: '518', credit_account_code: '325', available: true },
    ...overrides,
  }
}

function factory(props: Record<string, unknown> = {}) {
  return mount(OtherItemKindPostingProfile, {
    props: { kind: kind(), accounts: [], canWrite: true, ...props } as any,
    global: { stubs: { ChartAccountSelect, DimensionFields: true } },
  })
}

describe('OtherItemKindPostingProfile', () => {
  beforeEach(() => vi.clearAllMocks())

  it('applies the suggestion only on request and saves item side by kind side', async () => {
    m.savePostingProfiles.mockResolvedValue({ kind_id: 5, profiles: {} })
    const wrapper = factory()
    const origin = wrapper.find('[data-test="kind-posting-origin"]')
    expect((origin.findAll('input.account')[0].element as HTMLInputElement).value).toBe('')

    await wrapper.find('[data-test="kind-posting-suggestion"] button').trigger('click')
    expect((origin.findAll('input.account')[0].element as HTMLInputElement).value).toBe('325')
    expect((origin.findAll('input.account')[1].element as HTMLInputElement).value).toBe('518')

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    const [, payload] = m.savePostingProfiles.mock.calls[0]
    expect(payload.origin).toMatchObject({ debit_account_code: '518', credit_account_code: '325', is_active: true })
    expect(payload.payment_bank).toBeNull()
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('keeps the item side of a payment empty and posts the money account on the other side', async () => {
    m.savePostingProfiles.mockResolvedValue({ kind_id: 5, profiles: {} })
    const wrapper = factory({ kind: kind({ side: 'receivable', builtin_code: 'loan', posting_suggestion: null }) })
    const bank = wrapper.find('[data-test="kind-posting-payment_bank"]')
    expect(bank.findAll('input.account')).toHaveLength(1)
    await bank.find('input.account').setValue('221')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    const [, payload] = m.savePostingProfiles.mock.calls[0]
    expect(payload.payment_bank).toMatchObject({ debit_account_code: '221', credit_account_code: null })
  })

  it('shows an unavailable suggestion without the apply button and disables editing without rights', () => {
    const wrapper = factory({
      kind: kind({ posting_suggestion: { debit_account_code: '548', credit_account_code: '379', available: false } }),
      canWrite: false,
    })
    expect(wrapper.find('[data-test="kind-posting-suggestion"] button').exists()).toBe(false)
    expect(wrapper.find('[data-test="kind-posting-suggestion"]').text()).toContain('suggestion_unavailable')
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
  })

  it('interest and fee carry only the expense account and show the default as placeholder', async () => {
    m.savePostingProfiles.mockResolvedValue({ kind_id: 5, profiles: {} })
    const wrapper = factory({ kind: kind({ builtin_code: 'loan', posting_suggestion: null,
      posting_component_defaults: { interest: '562', fee: '568' } }) })
    const interest = wrapper.find('[data-test="kind-posting-interest"]')
    expect(interest.findAll('input.account')).toHaveLength(1)
    expect(interest.text()).toContain('other_items.posting.component_money')
    const selects = wrapper.findAllComponents(ChartAccountSelect)
    expect(selects.map(select => select.props('placeholder'))).toContain('568')
    await interest.find('input.account').setValue('562.100')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    const [, payload] = m.savePostingProfiles.mock.calls[0]
    expect(payload.interest).toMatchObject({ debit_account_code: '562.100', credit_account_code: null })
    expect(payload.fee).toBeNull()
  })
})
