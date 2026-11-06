import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import type { ChartAccount } from '@/api/accounting'

const m = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), store: undefined as any }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.list, createAccount: m.create } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.store }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, isDemo: false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => vi.fn() }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => undefined }))
vi.mock('@/api/codebookTransfer', () => ({ codebookTransferApi: { download: vi.fn() } }))
vi.mock('@/components/accounting/CodebookImportDialog.vue', () => ({ default: {
  props: ['modelValue'],
  emits: ['imported', 'update:modelValue'],
  template: '<div v-if="modelValue" data-test="import-dialog"><button data-test="import-complete" @click="$emit(\'imported\')">Done</button></div>',
} }))

import ChartOfAccounts from '../ChartOfAccounts.vue'

function account(code: string, type: ChartAccount['account_type'] = 'asset', supplierId = 1): ChartAccount {
  return { id: Number(code), supplier_id: supplierId, account_code: code, name: `Account ${code}`, account_type: type, normal_side: 'debit', is_synthetic: true, parent_id: null, is_active: true, created_at: '2026-01-01' }
}
function render() {
  return mount(ChartOfAccounts, {
    global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}
function group(wrapper: ReturnType<typeof render>, key: string) { return wrapper.find(`[data-account-class="${key}"]`) }

beforeEach(() => {
  vi.clearAllMocks()
  m.store = reactive({ currentSupplierId: 1 as number | null, suppliers: [
    { id: 1, accounting_framework: 'cz_500', tax_accounting_decree: '504' },
    { id: 2, accounting_framework: 'cz_504', tax_accounting_decree: '500' },
  ], get currentSupplier() { return this.suppliers.find(s => s.id === this.currentSupplierId) ?? null } })
  m.list.mockResolvedValue([account('411', 'equity'), account('701', 'closing'), account('901', 'offbalance')])
})

describe('Chart of accounts new analytic', () => {
  it('opens the form for the row parent and saves only the suffix behind its fixed prefix', async () => {
    m.list.mockResolvedValue([account('013'), account('019'), { ...account('019100'), id: 19100, account_code: '019.100', is_synthetic: false, parent_id: 19 }])
    m.create.mockResolvedValue({})
    const wrapper = render()
    await flushPromises()
    const buttons = wrapper.findAll('[data-test="add-analytic"]')
    expect(buttons).toHaveLength(2)
    await buttons[1].trigger('click')
    expect(wrapper.get('[data-test="code-prefix"]').text()).toBe('019.')
    const inputs = wrapper.findAll('input[type="text"]')
    await inputs[0].setValue('200')
    await inputs[1].setValue('Licence')
    await wrapper.findAll('button').find(button => button.text() === cs.common.create)!.trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith({ parent_id: 19, account_code: '019.200', name: 'Licence' })

    await buttons[1].trigger('click')
    await wrapper.findAll('input[type="text"]')[0].setValue('019.300')
    await wrapper.findAll('input[type="text"]')[1].setValue('Další')
    await wrapper.findAll('button').find(button => button.text() === cs.common.create)!.trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenLastCalledWith({ parent_id: 19, account_code: '019.300', name: 'Další' })
  })
})

describe('Chart of accounts framework display', () => {
  it('keeps the import result visible after reloading accounts and closes it on company switch', async () => {
    const wrapper = render()
    await flushPromises()
    const importButton = wrapper.findAll('button').find(button => button.text() === cs.codebookTransfer.import)!
    await importButton.trigger('click')
    expect(wrapper.find('[data-test="import-dialog"]').exists()).toBe(true)
    await wrapper.get('[data-test="import-complete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="import-dialog"]').exists()).toBe(true)
    m.store.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.find('[data-test="import-dialog"]').exists()).toBe(false)
  })

  it('preserves class grouping and labels for 500 independently of the manual decree', async () => {
    const wrapper = render()
    await flushPromises()
    expect(group(wrapper, '4').text()).toContain(cs.accounting.accounts.class['4'])
    expect(group(wrapper, '7').text()).toContain(cs.accounting.accounts.class['7'])
    expect(group(wrapper, 'offbalance').text()).toContain('Account 901')
    expect(group(wrapper, '9').exists()).toBe(false)
    expect(wrapper.text()).toContain(cs.accounting.accounts.subtitle)
    expect(m.list).toHaveBeenCalledWith({ supplierId: 1, includeInactive: false })
  })

  it('shows 504 classes, free custom classes, and backend account roles without 500 labels', async () => {
    m.store.suppliers[0].accounting_framework = 'cz_504'
    m.store.suppliers[0].tax_accounting_decree = '500'
    m.list.mockResolvedValue(['011', '111', '211', '311', '511', '611'].map(code => account(code)).concat([
      account('411', 'offbalance'), account('701', 'offbalance'), account('801', 'offbalance'), account('901', 'equity'), account('961', 'closing'),
    ]))
    const wrapper = render()
    await flushPromises()
    for (const key of ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'] as const) {
      expect(group(wrapper, key).text()).toContain(cs.accounting.accounts.class_504[key])
    }
    expect(wrapper.text()).toContain(cs.accounting.accounts.subtitle_504)
    expect(wrapper.text()).not.toContain(cs.accounting.accounts.class['4'])
    expect(wrapper.text()).not.toContain(cs.accounting.accounts.class['7'])
    expect(group(wrapper, '9').text()).toContain(cs.accounting.accounts.type.equity)
    expect(group(wrapper, '9').text()).toContain(cs.accounting.accounts.type.closing)
  })

  it('does not add empty free classes to the 504 chart', async () => {
    m.store.suppliers[0].accounting_framework = 'cz_504'
    m.list.mockResolvedValue([account('901', 'equity')])
    const wrapper = render()
    await flushPromises()
    for (const key of ['4', '7', '8']) expect(group(wrapper, key).exists()).toBe(false)
    expect(group(wrapper, '9').exists()).toBe(true)
  })

  it('clears the old company and ignores its delayed accounts response after switching', async () => {
    let resolveOld!: (value: ChartAccount[]) => void
    let resolveNew!: (value: ChartAccount[]) => void
    m.list.mockImplementation(({ supplierId }) => new Promise(resolve => {
      if (supplierId === 1) resolveOld = resolve
      else resolveNew = resolve
    }))
    const wrapper = render()
    await flushPromises()
    m.store.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.findAll('[data-account-class]')).toHaveLength(0)
    resolveNew([account('901', 'equity', 2)])
    await flushPromises()
    expect(group(wrapper, '9').text()).toContain('Account 901')
    resolveOld([account('411', 'equity')])
    await flushPromises()
    expect(group(wrapper, '9').text()).toContain(cs.accounting.accounts.class_504['9'])
    expect(group(wrapper, '4').exists()).toBe(false)
    expect(m.list).toHaveBeenLastCalledWith({ supplierId: 2, includeInactive: false })
  })

  it('does not display accounts from a failed request', async () => {
    m.list.mockRejectedValue(new Error('Unavailable'))
    const wrapper = render()
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe(cs.common.error)
    expect(wrapper.findAll('[data-account-class]')).toHaveLength(0)
  })

  it('does not request an implicit company when the initial scope is missing', async () => {
    m.store.currentSupplierId = null
    const wrapper = render()
    await flushPromises()
    expect(m.list).not.toHaveBeenCalled()
    expect(wrapper.findAll('[data-account-class]')).toHaveLength(0)
  })

  it('clears the chart on logout and ignores the previous company response', async () => {
    let resolveAccounts!: (value: ChartAccount[]) => void
    m.list.mockReturnValue(new Promise(resolve => { resolveAccounts = resolve }))
    const wrapper = render()
    await flushPromises()
    m.store.currentSupplierId = 0
    await flushPromises()
    resolveAccounts([account('411', 'equity')])
    await flushPromises()
    expect(m.list).toHaveBeenCalledTimes(1)
    expect(wrapper.findAll('[data-account-class]')).toHaveLength(0)
    expect(wrapper.text()).not.toContain(cs.accounting.accounts.subtitle)
  })


  it('reacts to the effective framework supplied by an auth refresh', async () => {
    const wrapper = render()
    await flushPromises()
    expect(group(wrapper, 'offbalance').exists()).toBe(true)
    m.list.mockResolvedValue([account('901', 'equity')])
    m.store.suppliers[0].accounting_framework = 'cz_504'
    await flushPromises()
    expect(group(wrapper, '9').text()).toContain(cs.accounting.accounts.class_504['9'])
    expect(group(wrapper, '4').exists()).toBe(false)
    expect(m.list).toHaveBeenCalledTimes(2)
  })


  it('waits for the current supplier brief before loading the initial chart', async () => {
    m.store.suppliers = []
    const wrapper = render()
    await flushPromises()
    expect(m.list).not.toHaveBeenCalled()
    m.store.suppliers = [{ id: 1, accounting_framework: 'cz_504' }]
    await flushPromises()
    expect(m.list).toHaveBeenCalledWith({ supplierId: 1, includeInactive: false })
    expect(group(wrapper, '9').exists()).toBe(true)
  })


  it('ignores an older request for the same company after changing the inactive filter', async () => {
    let resolveOld!: (value: ChartAccount[]) => void
    let resolveNew!: (value: ChartAccount[]) => void
    m.list.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))
    m.list.mockReturnValueOnce(new Promise(resolve => { resolveNew = resolve }))
    const wrapper = render()
    await flushPromises()
    await wrapper.get('input[type="checkbox"]').setValue(true)
    resolveNew([account('611', 'revenue')])
    await flushPromises()
    resolveOld([account('411', 'equity')])
    await flushPromises()
    expect(group(wrapper, '6').text()).toContain('Account 611')
    expect(group(wrapper, '4').exists()).toBe(false)
    expect(m.list).toHaveBeenLastCalledWith({ supplierId: 1, includeInactive: true })
  })

})
