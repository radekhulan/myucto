import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/api/client'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import PartySaldoPanel from '../PartySaldoPanel.vue'
import type { PartySaldoPage } from '@/api/propertyReceivables'

const page = (next: string | null, first = true): PartySaldoPage => ({
  accounting_date: '2027-04-25',
  knowledge_basis: 'current_ledger',
  totals: first ? { receivable: '1450000', credit: '600000' } : null,
  next_cursor: next,
  items: [
    { party_ids: [11, 12], parties: [{ id: 11, label: 'Synthetic A' }, { id: 12, label: '' }], unit_ids: [7], group_keys: ['joint'],
      receivable_cents: '350000', credit_cents: '0', liability_review_required: true },
  ],
})

let calls: Array<{ url: string; params: Record<string, unknown> }>
let respond: () => unknown
const original = api.defaults.adapter
const globals = () => ({ plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })] })

beforeEach(() => {
  calls = []
  respond = () => page('11,12')
  api.defaults.adapter = async config => {
    calls.push({ url: config.url!, params: config.params ?? {} })
    return { data: respond(), status: 200, statusText: 'OK', headers: {}, config }
  }
  vi.spyOn(HTMLFormElement.prototype, 'reportValidity').mockReturnValue(true)
})
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks() })

describe('party saldo panel', () => {
  it('loads the accounting date, shows house totals, units and review and keeps first-page totals while paging by cursor', async () => {
    const w = mount(PartySaldoPanel, { props: { supplier: 2, units: [{ id: 7, code: 'U07' } as never] }, global: globals() })
    await w.get('[data-field="party-saldo-date"]').setValue('2027-04-25')
    const load = w.findAll('button').find(b => b.text() === cs.propertySaldo.parties.load)!
    await load.trigger('click')
    await flushPromises()
    expect(calls[0]).toMatchObject({ url: '/property/catalog/payments/reconciliation/parties', params: { date: '2027-04-25', limit: 50 } })
    expect(w.get('[data-total="receivable"]').text()).toBe('14500.00 CZK')
    expect(w.get('[data-party-row]').text()).toContain('Synthetic A')
    expect(w.get('[data-party-row]').text()).toContain('U07')
    expect(w.find('[data-review]').exists()).toBe(true)
    respond = () => page(null, false)
    await w.get('[data-action="party-saldo-next"]').trigger('click')
    await flushPromises()
    expect(calls[1]!.params.after).toBe('11,12')
    expect(w.get('[data-total="receivable"]').text()).toBe('14500.00 CZK')
    w.unmount()
  })
})
