import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => `T:${key}` }),
}))
vi.mock('../../../api/closing', () => ({ closingApi: { checkFindings: vi.fn() } }))

import CheckFindings from '@/components/accounting/CheckFindings.vue'

function mountFor(checkKey: string) {
  return mount(CheckFindings, {
    props: {
      checkKey,
      label: 'K3',
      value: { kind: 'document', count: 1, findings: [{ doc_id: 5, doc_no: 'F1', amount: 100, issues: ['marked_paid_unposted'] }] },
    },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
  })
}

describe('CheckFindings — počet v řádku', () => {
  it('nový výsledek kontroly přebije dříve načtený detail', async () => {
    const { closingApi } = await import('../../../api/closing')
    vi.mocked(closingApi.checkFindings).mockResolvedValue({ key: 'x', severity: 'warning', ok: false, value: { count: 9, findings: [] } })
    const wrapper = mount(CheckFindings, {
      props: { checkKey: 'x', label: 'X', periodId: 3, value: { count: 9, findings: [{ doc_id: 1 }] } },
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
    })
    await wrapper.find('button').trigger('click')
    await flushPromises()
    await wrapper.setProps({ value: { count: 0, findings: [] } })
    expect(wrapper.find('button').exists()).toBe(false)
  })
})

describe('CheckFindings — nález marked_paid_unposted', () => {
  it('u přijatých faktur má vlastní popisek bez GoPay', async () => {
    const wrapper = mountFor('paid_purchases_open_saldo')
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('checks.issue.marked_paid_unposted_payable')
  })

  it('u vydaných faktur zůstává popisek s GoPay', async () => {
    const wrapper = mountFor('paid_invoices_open_saldo')
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('checks.issue.marked_paid_unposted')
    expect(wrapper.text()).not.toContain('marked_paid_unposted_payable')
  })
})

describe('CheckFindings FX', () => {
  it('nedoložený zůstatek zůstane neznámý a ukáže přeložitelný důvod', async () => {
    const wrapper = mount(CheckFindings, {
      props: { checkKey: 'fx_open_items', label: 'FX', value: { kind: 'document', count: 1,
        findings: [{ doc_type: 'invoice', doc_id: 7, amount: null, currency: 'EUR', issues: ['fx_settlement_unproven'] }] } },
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
    })
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('checks.issue.fx_settlement_unproven')
    expect(wrapper.text()).not.toContain('0,00')
    expect(wrapper.text()).toContain('EUR')
  })
})

it.each([
  [{ applicable: false, complete: false }, 'not_applicable'],
  [{ applicable: true, complete: false }, 'pending'],
  [{ applicable: true, complete: true }, 'completed'],
])('shows deferred tax assessment state %j', (value, key) => {
  const wrapper = mount(CheckFindings, { props: { checkKey: 'deferred_tax_assessment', label: 'Deferred tax', value } })
  expect(wrapper.text()).toContain(`checks.value.${key}`)
})

it.each([
  ['filing_required', 'other_taxable_income', true],
  ['filing_not_required', 'only_exempt_or_withheld_income', true],
  ['unassessed', 'assessment_incomplete', false],
])('ukáže předkontrolu SVJ %s', (status, reason, complete) => {
  const value = { applicable: true, complete, findings: [], svj_precheck: { status, reason, legal_basis: 'zdp_38mb_b', taxable_source_count: 2 } }
  const wrapper = mount(CheckFindings, { props: { checkKey: 'nonprofit_tax_assessment', label: 'Tax', value } })
  const text = wrapper.get('[data-test="svj-precheck"]').text()
  expect(text).toContain(`checks.svj_precheck.status.${status}`)
  expect(text).toContain(`checks.svj_precheck.reason.${reason}`)
  expect(wrapper.text()).toContain(complete ? 'checks.value.completed' : 'checks.value.pending')
})

it('neukáže předkontrolu SVJ u jiného poplatníka 504', () => {
  const wrapper = mount(CheckFindings, { props: { checkKey: 'nonprofit_tax_assessment', label: 'Tax', value: { applicable: true, complete: true, findings: [] } } })
  expect(wrapper.find('[data-test="svj-precheck"]').exists()).toBe(false)
})
