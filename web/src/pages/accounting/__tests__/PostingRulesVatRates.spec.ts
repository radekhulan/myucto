import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPostingRules: vi.fn(),
  listAccounts: vi.fn(),
  putPostingRule: vi.fn(),
  vatRates: vi.fn(),
  canWrite: vi.fn(),
}))
vi.mock('@/api/accounting', () => ({ accountingApi: m }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { vatRates: m.vatRates } }))
vi.mock('@/api/codebookTransfer', () => ({ codebookTransferApi: {} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn() }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/accounting/CodebookImportDialog.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/accounting/PostingRuleChartAlignmentDialog.vue', () => ({ default: { render: () => null } }))

import PostingRules from '../PostingRules.vue'

function account(id: number, account_code: string, parent_id: number | null) {
  return { id, account_code, parent_id, name: account_code, is_synthetic: parent_id === null, is_active: true }
}

beforeEach(() => {
  vi.clearAllMocks()
  m.canWrite.mockReturnValue(true)
  m.listPostingRules.mockResolvedValue({
    'invoice.vat.output.15': { rule_key: 'invoice.vat.output.15', credit_account_code: '343.320' },
  })
  m.listAccounts.mockResolvedValue([
    account(1, '343', null), account(2, '343.320', 1), account(3, '343.900', 1), account(4, '501', null),
  ])
  m.vatRates.mockResolvedValue([{ rate_percent: 22.5 }, { rate_percent: 12 }, { rate_percent: 0 }])
  m.putPostingRule.mockResolvedValue({})
})

it('načte sazby z číselníku a vybere účet ze správné syntetiky', async () => {
  const wrapper = mount(PostingRules)
  await flushPromises()
  const rows = wrapper.findAll('section li')
  expect(rows).toHaveLength(5)
  expect(rows[1]!.text()).toContain('15 %')
  await rows[0]!.get('button').trigger('click')
  const select = wrapper.get('select')
  expect(select.findAll('option').map(option => option.element.value)).toEqual(['', '343', '343.320'])
  await select.setValue('343.320')
  await wrapper.findAll('button').at(-1)!.trigger('click')
  expect(m.putPostingRule).toHaveBeenCalledWith('invoice.vat.output.22.5', {
    debit_account_code: null, credit_account_code: '343.320',
  })
  wrapper.unmount()
})

it('umožní vrátit dřívější sazbu na společný účet', async () => {
  const wrapper = mount(PostingRules)
  await flushPromises()
  await wrapper.findAll('section li')[1]!.get('button').trigger('click')
  await wrapper.get('select').setValue('')
  await wrapper.findAll('button').at(-1)!.trigger('click')
  expect(m.putPostingRule).toHaveBeenCalledWith('invoice.vat.output.15', {
    debit_account_code: null, credit_account_code: null,
  })
  wrapper.unmount()
})
