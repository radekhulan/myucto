import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Pravidla bankovních pohybů v daňové evidenci (issue #140): založení pravidla
// posílá podmínku i akci, tlačítko uplatnění volá backfill.

const m = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn(), apply: vi.fn() }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/taxEvidenceBankRules', () => ({ taxEvidenceBankRulesApi: m }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'err' }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))

import BankRules from '@/pages/tax-evidence/BankRules.vue'

const rule = {
  id: 3, name: 'Převod ze spořicího účtu', priority: 100, is_active: true, direction: 'any',
  counterparty_account: '19-2000145399', variable_symbol: null, text_contains: null,
  amount_min: null, amount_max: null, action_ignore: true, tax_bucket: 'transfer', hit_count: 2, last_hit_at: null,
}

const stubs = {
  Modal: { template: '<div><slot /></div>' },
  EmptyState: { template: '<div data-test="empty" />' },
}

describe('BankRules.vue', () => {
  beforeEach(() => {
    Object.values(m).forEach(f => f.mockReset())
  })

  it('založí pravidlo s protiúčtem a akcemi', async () => {
    m.list.mockResolvedValue([])
    m.create.mockResolvedValue(rule)
    const w = mount(BankRules, { global: { stubs } })
    await flushPromises()

    await w.find('[data-test="bank-rules-new"]').trigger('click')
    const inputs = w.findAll('input')
    await inputs[0].setValue('Převod ze spořicího účtu')
    await inputs[1].setValue('19-2000145399')
    await w.find('[data-test="bank-rules-save"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Převod ze spořicího účtu', counterparty_account: '19-2000145399', action_ignore: true,
    }))
  })

  it('uplatní pravidla na stávající pohyby', async () => {
    m.list.mockResolvedValue([rule])
    m.apply.mockResolvedValue({ applied: 4, ignored: 4, classified: 4 })
    const w = mount(BankRules, { global: { stubs } })
    await flushPromises()

    expect(w.text()).toContain('Převod ze spořicího účtu')
    await w.find('[data-test="bank-rules-apply"]').trigger('click')
    await flushPromises()

    expect(m.apply).toHaveBeenCalledOnce()
    expect(m.list).toHaveBeenCalledTimes(2)
  })
})
