import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'

const m = vi.hoisted(() => ({ createRule: vi.fn(), getRule: vi.fn(), updateRule: vi.fn(), createBankRule: vi.fn(), createJournalTemplate: vi.fn(), postingPreview: vi.fn(), getEntry: vi.fn(), listAccounts: vi.fn(), listCostCenters: vi.fn() }))
vi.mock('@/api/accounting', () => ({ accountingApi: m }))
vi.mock('@/api/expenseRules', () => ({ expenseRulesApi: { createRule: m.createRule, getRule: m.getRule, updateRule: m.updateRule }, expenseRuleErrorMessage: () => 'error' }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatDate: String }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
vi.mock('@/api/settings', () => ({ settingsApi: { listCurrencies: async () => [{ code: 'EUR', is_active: true }] } }))
vi.mock('@/api/bankPosting', () => ({ bankPostingApi: { createRule: m.createBankRule }, bankPostingErrorMessage: () => 'error' }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import ExpenseRuleTemplateModal from '../ExpenseRuleTemplateModal.vue'
import ExpenseRules from '@/pages/accounting/ExpenseRules.vue'
import RuleFormModal from '@/components/bank/RuleFormModal.vue'

const global = { stubs: {
  Modal: { template: '<div><slot /></div>' },
  VendorPicker: { name: 'VendorPicker', props: ['modelValue'], emits: ['update:modelValue'], template: '<div />' },
  ChartAccountSelect: { name: 'ChartAccountSelect', props: ['modelValue', 'accounts'], emits: ['update:modelValue'], template: '<div>{{ modelValue }}</div>' },
} }

describe('templates from source documents', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listAccounts.mockResolvedValue([])
    m.listCostCenters.mockResolvedValue([])
    m.createRule.mockResolvedValue({ rule: { id: 1 } })
    m.updateRule.mockResolvedValue({ rule: { id: 42 } })
    m.getRule.mockResolvedValue({
      id: 42, name: 'Existing rule', vendor_client_id: null, vendor_name_contains: 'Synthetic vendor',
      description_contains: 'hosting', amount_min: null, amount_max: null, expense_kind: 'service',
      target_account_code: '518.100', application_mode: 'auto', priority: 17, is_active: true,
      hit_count: 2, last_hit_at: null,
    })
    m.createJournalTemplate.mockResolvedValue({ id: 1 })
    m.createBankRule.mockResolvedValue({ rule: { id: 1 } })
  })

  it('saves a complete recommendation draft with nullable criteria through the original expense editor', async () => {
    const wrapper = mount(ExpenseRules, { props: { initialDraft: { vendorId: null, rule: {
      name: 'Synthetic hosting', vendor_client_id: null, vendor_name_contains: null,
      description_contains: 'hosting', target_account_code: null, expense_kind: 'service',
      amount_min: 10, amount_max: 100, priority: 80, application_mode: 'auto',
    } } }, global })
    await flushPromises()
    expect(wrapper.findComponent({ name: 'VendorPicker' }).exists()).toBe(false)
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.createRule).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Synthetic hosting', vendor_client_id: null, vendor_name_contains: null,
      description_contains: 'hosting', target_account_code: null, amount_min: 10, amount_max: 100,
      priority: 80, application_mode: 'suggest',
    }))
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('loads a recommended rule fresh and updates it without losing mode or priority', async () => {
    const wrapper = mount(ExpenseRules, { props: { initialDraft: { vendorId: null, ruleId: 42 } }, global })
    await flushPromises()
    expect(m.getRule).toHaveBeenCalledWith(42)
    expect(wrapper.find('input[id$="-description"]').element).toHaveProperty('value', 'hosting')
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.updateRule).toHaveBeenCalledWith(42, expect.objectContaining({
      vendor_name_contains: 'Synthetic vendor', description_contains: 'hosting', application_mode: 'auto', priority: 17,
    }))
    expect(m.createRule).not.toHaveBeenCalled()
  })

  it('prefills the vendor, leaves matching text for the user and saves their text', async () => {
    const wrapper = mount(ExpenseRuleTemplateModal, { props: { vendorId: 12, vendorName: 'Synthetic vendor' }, global })
    await flushPromises()
    expect((wrapper.find('input[id$="-description"]').element as HTMLInputElement).value).toBe('')
    expect(m.createRule).not.toHaveBeenCalled()
    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')!
    expect(save.attributes('disabled')).toBeDefined()
    await wrapper.find('input[id$="-description"]').setValue('   ')
    expect(save.attributes('disabled')).toBeDefined()
    await wrapper.find('input[id$="-description"]').setValue('licence')
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.createRule).toHaveBeenCalledWith(expect.objectContaining({ vendor_client_id: 12, description_contains: 'licence', application_mode: 'suggest' }))
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it.each(['invoices', 'purchase-invoices'])('connects the %s menu to the original posting rule dialog and permission', source => {
    const page = readFileSync(`src/pages/${source}/InvoiceDetail.vue`, 'utf8')
    expect(page).toContain("import RuleFormModal from '@/components/bank/RuleFormModal.vue'")
    expect(page).toMatch(/key: 'posting-rule'[\s\S]*?tier: 'overflow'[\s\S]*?auth.canWrite\('bank.rules'\)/)
    expect(page).toContain('<RuleFormModal v-if="postingRuleOpen && invoice"')
    expect(page).not.toContain('SaveJournalTemplateModal')
  })

  it('keeps the original posting rule fields and saves a range instead of a fixed journal amount', async () => {
    const wrapper = mount(RuleFormModal, { props: {
      prefill: { name: 'Synthetic vendor', direction: 'outgoing', applies_currency: 'EUR', variable_symbol: '123' } as any,
    }, global })
    await flushPromises()
    for (const field of ['rule_priority', 'rule_currency', 'rule_auto_cap', 'rule_amount_range', 'dry_run']) {
      expect(wrapper.text()).toContain(`bank.posting.${field}`)
    }
    expect(wrapper.find('input[placeholder="accounting.templates.default_amount"]').exists()).toBe(false)
    await wrapper.find('input[placeholder="bank.posting.amount_min"]').setValue('50')
    await wrapper.find('input[placeholder="bank.posting.amount_max"]').setValue('500')
    await wrapper.find('input[max="999"]').setValue('70')
    wrapper.findAllComponents({ name: 'ChartAccountSelect' })[0]!.vm.$emit('update:modelValue', '518.101')
    wrapper.findAllComponents({ name: 'ChartAccountSelect' })[1]!.vm.$emit('update:modelValue', '221.100')
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.createBankRule).toHaveBeenCalledWith(expect.objectContaining({ name: 'Synthetic vendor', direction: 'outgoing', applies_currency: 'EUR', variable_symbol: '123', amount_min: 50, amount_max: 500, priority: 70, debit_account_code: '518.101', credit_account_code: '221.100', mode: 'suggest' }))
    expect(m.createJournalTemplate).not.toHaveBeenCalled()
    expect(m.postingPreview).not.toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('keeps the full expense criteria, amount range, priority and mode', async () => {
    const wrapper = mount(ExpenseRuleTemplateModal, { props: { vendorId: 12, vendorName: 'Synthetic vendor' }, global })
    await flushPromises()
    expect(wrapper.text()).toContain('accounting.expense_rules.form_vendor_name_contains')
    expect(wrapper.text()).toContain('accounting.expense_rules.form_priority')
    expect(wrapper.text()).toContain('accounting.expense_rules.form_active')
    await wrapper.find('input[id$="-vendor-name"]').setValue('Synthetic')
    await wrapper.find('input[id$="-description"]').setValue('licence')
    await wrapper.find('input[placeholder="accounting.expense_rules.amount_min"]').setValue('50')
    await wrapper.find('input[placeholder="accounting.expense_rules.amount_max"]').setValue('500')
    await wrapper.find('input[max="999"]').setValue('70')
    await wrapper.findAll('select')[1]!.setValue('auto')
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.createRule).toHaveBeenCalledWith(expect.objectContaining({ vendor_name_contains: 'Synthetic', amount_min: 50, amount_max: 500, priority: 70, application_mode: 'auto' }))
  })

  it.each([25])('allows changing the prefilled vendor to %s', async (vendorId) => {
    const wrapper = mount(ExpenseRuleTemplateModal, { props: { vendorId: 12, vendorName: 'Synthetic vendor' }, global })
    await flushPromises()
    const picker = wrapper.findComponent({ name: 'VendorPicker' })
    expect(picker.props('modelValue')).toBe(12)
    picker.vm.$emit('update:modelValue', vendorId)
    await wrapper.find('input[id$="-description"]').setValue('hosting')
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.createRule).toHaveBeenCalledWith(expect.objectContaining({ vendor_client_id: vendorId, description_contains: 'hosting' }))
  })

  it('explicitly unbinds both vendor criteria so the rule matches text for any vendor', async () => {
    const wrapper = mount(ExpenseRuleTemplateModal, { props: { vendorId: 12, vendorName: 'Synthetic vendor' }, global })
    await flushPromises()
    await wrapper.find('input[id$="-vendor-name"]').setValue('Synthetic')
    await wrapper.find('input[id$="-bind-vendor"]').setValue(false)
    expect(wrapper.findComponent({ name: 'VendorPicker' }).exists()).toBe(false)
    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')!
    expect(save.attributes('disabled')).toBeDefined()
    await wrapper.find('input[id$="-description"]').setValue(' hosting ')
    await save.trigger('click')
    await flushPromises()
    expect(m.createRule).toHaveBeenCalledWith(expect.objectContaining({ vendor_client_id: null, vendor_name_contains: null, description_contains: 'hosting' }))
  })

  it('requires matching text when no vendor is selected', async () => {
    const wrapper = mount(ExpenseRuleTemplateModal, { props: { vendorId: 12, vendorName: 'Synthetic vendor' }, global })
    await flushPromises()
    wrapper.findComponent({ name: 'VendorPicker' }).vm.$emit('update:modelValue', null)
    await flushPromises()
    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')!
    expect(save.attributes('disabled')).toBeDefined()
    await save.trigger('click')
    expect(m.createRule).not.toHaveBeenCalled()
  })
})
