import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { ChartAccount } from '@/api/accounting'
import type { BankPostingRulePayload } from '@/api/bankPosting'
import RuleForm from '../RuleForm.vue'
import { manualMovementDimensions, usableDimensions } from '../ruleDimensions'

const mocks = vi.hoisted(() => ({ listAccounts: vi.fn(), load: vi.fn() }))
const dims = vi.hoisted(() => ({
  enabled: null as unknown as { value: boolean },
  documentTypes: null as unknown as { value: unknown[] },
  valueById: null as unknown as { value: Map<number, unknown> },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatDate: String }))
vi.mock('@/api/settings', () => ({ settingsApi: { listCurrencies: async () => [] } }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ enabled: dims.enabled, documentTypes: dims.documentTypes, valueById: dims.valueById, load: mocks.load }),
}))
vi.mock('@/api/bankPosting', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/bankPosting')>(),
  bankPostingApi: { listAccounts: mocks.listAccounts, dryRunRule: vi.fn() },
}))

const accounts = [{ id: 1, account_code: '221.100', name: 'Synthetic account', is_active: true, parent_id: null }] as ChartAccount[]
const ownAccount = (id: number, isActive: boolean) => ({
  id, label: `Synthetic ${id}`, account_number: '1000000005', bank_code: '0100', iban: null, currency: 'CZK',
  kind: 'savings', analytic_suffix: String(id), document_series: null, is_active: isActive, source: 'manual', currency_id: null,
})
const stubs = {
  ChartAccountSelect: { name: 'ChartAccountSelect', props: ['modelValue', 'accounts'], template: '<div />' },
  DimensionFields: { name: 'DimensionFields', props: ['modelValue'], emits: ['update:modelValue'], template: '<div />' },
}
const base = {
  name: 'Synthetic rule', direction: 'incoming', debit_account_code: '221.100', credit_account_code: '662',
  bank_account_id: 3, dimensions: {},
} as unknown as BankPostingRulePayload

describe('RuleForm own account and dimensions', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    dims.enabled = ref(true)
    dims.documentTypes = ref([{ id: 7, name: 'Středisko', is_active: true, show_on_documents: true }])
    dims.valueById = ref(new Map([[70, { type_id: 7, is_active: true }], [71, { type_id: 7, is_active: false }]]))
    mocks.listAccounts.mockResolvedValue([ownAccount(1, true), ownAccount(2, false), ownAccount(3, false)])
  })

  it('offers active accounts plus the archived one the rule already uses and emits the choice', async () => {
    const wrapper = mount(RuleForm, { props: { modelValue: base, accounts, mode: 'edit', showScope: true }, global: { stubs } })
    await flushPromises()
    const select = wrapper.find('[data-test="rule-own-account"]')
    const values = select.findAll('option').map(o => (o.element as HTMLOptionElement).value)
    expect(values).toHaveLength(3)
    expect(select.findAll('option').map(o => o.text())).toEqual(['bank.posting.rule_own_account_any', 'Synthetic 1 (1000000005/0100)', 'Synthetic 3 (1000000005/0100)'])
    await select.setValue(values[1])
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toEqual(expect.objectContaining({ bank_account_id: 1 }))

    wrapper.findComponent({ name: 'DimensionFields' }).vm.$emit('update:modelValue', { 7: 70 })
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toEqual(expect.objectContaining({ dimensions: { 7: 70 } }))
    wrapper.unmount()
  })

  it('hides account and dimensions outside the rule editor and skips loading', async () => {
    const wrapper = mount(RuleForm, { props: { modelValue: base, accounts, mode: 'create' }, global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="rule-own-account"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rule-dimensions"]').exists()).toBe(false)
    expect(mocks.listAccounts).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('new rule keeps only dimensions the form shows and the server accepts', async () => {
    const prefill = { ...base, bank_account_id: null, dimensions: { 7: 70, 8: 80 } } as unknown as BankPostingRulePayload
    const wrapper = mount(RuleForm, { props: { modelValue: prefill, accounts, mode: 'create', showScope: true }, global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent({ name: 'DimensionFields' }).props('modelValue')).toEqual({ 7: 70 })
    wrapper.unmount()
  })

  it('editing keeps stored dimensions unchanged and survives a failed dimension load', async () => {
    const stored = { ...base, dimensions: { 7: 71 } } as unknown as BankPostingRulePayload
    const wrapper = mount(RuleForm, { props: { modelValue: stored, accounts, mode: 'edit', showScope: true }, global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent({ name: 'DimensionFields' }).props('modelValue')).toEqual({ 7: 71 })
    wrapper.unmount()

    mocks.load.mockRejectedValue(new Error('forbidden'))
    const failing = mount(RuleForm, { props: { modelValue: { ...base, bank_account_id: null, dimensions: { 7: 70 } } as unknown as BankPostingRulePayload, accounts, mode: 'create', showScope: true }, global: { stubs } })
    await flushPromises()
    expect(failing.find('[data-test="rule-own-account"]').exists()).toBe(true)
    failing.unmount()
  })

  it('hides dimensions when the company has them off', async () => {
    dims.enabled = ref(false)
    const wrapper = mount(RuleForm, { props: { modelValue: base, accounts, mode: 'create', showScope: true }, global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="rule-own-account"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="rule-dimensions"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('rule prefill from a movement', () => {
  it('takes only manually entered movement dimensions', () => {
    expect(manualMovementDimensions({ dimensions: { 7: 70, 8: 80, 9: null }, rule_dimension_types: [8] })).toEqual({ 7: 70 })
    expect(manualMovementDimensions({ dimensions: { 7: 70 } })).toEqual({ 7: 70 })
    expect(manualMovementDimensions({})).toEqual({})
  })

  it('drops closed values, hidden types and values of another type', () => {
    const valueById = new Map([[70, { type_id: 7, is_active: true }], [71, { type_id: 7, is_active: false }], [90, { type_id: 9, is_active: true }]])
    expect(usableDimensions({ 7: 70, 8: 90 }, [{ id: 7 }, { id: 8 }], valueById)).toEqual({ 7: 70 })
    expect(usableDimensions({ 7: 71 }, [{ id: 7 }], valueById)).toEqual({})
    expect(usableDimensions({ 9: 90 }, [{ id: 7 }], valueById)).toEqual({})
  })
})
