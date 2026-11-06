import { ref } from 'vue'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { Invoice, InvoiceOtherItem, InvoiceOtherItemCandidate } from '@/api/invoices'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key }),
}))

const candidatesMock = vi.fn()
const linkMock = vi.fn()
const unlinkMock = vi.fn()
vi.mock('@/api/invoices', () => ({
  invoicesApi: {
    otherItemCandidates: (...args: unknown[]) => candidatesMock(...args),
    linkOtherItem: (...args: unknown[]) => linkMock(...args),
    unlinkOtherItem: (...args: unknown[]) => unlinkMock(...args),
  },
}))

const toastSuccess = vi.fn()
const toastError = vi.fn()
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: toastSuccess, error: toastError }) }))

import InvoiceOtherItemLink from '@/components/invoices/InvoiceOtherItemLink.vue'

const item: InvoiceOtherItem = {
  id: 7, document_no: 'OP-2099-001', title: 'Prodej pozemku', partner_name: 'Syntetický kupující',
  variable_symbol: '2099001', issued_on: '2099-02-01', amount: 185000, currency: 'CZK', status: 'posted',
  journal_entry_id: 55, paid_amount: 185000, remaining_amount: 0,
}

function candidate(overrides: Partial<InvoiceOtherItemCandidate> = {}): InvoiceOtherItemCandidate {
  return { ...item, partner_id: 3, due_on: '2099-03-15', vs_match: true, partner_match: true, amount_match: true, ...overrides }
}

function invoice(overrides: Partial<Invoice> = {}): Invoice {
  return { id: 42, varsymbol: '2099001', status: 'issued', invoice_type: 'invoice', other_item_id: null,
    other_item_link: null, other_item_suggestion: null, ...overrides } as unknown as Invoice
}

function mountLink(inv: Invoice, canLink = true) {
  return mount(InvoiceOtherItemLink, {
    props: { invoice: inv, canLink },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a class="router-link"><slot /></a>' } } },
  })
}

describe('InvoiceOtherItemLink', () => {
  beforeEach(() => {
    candidatesMock.mockReset()
    linkMock.mockReset()
    unlinkMock.mockReset()
    toastSuccess.mockReset()
    toastError.mockReset()
  })

  it('navrhne jedinou pohledávku se shodným VS a jedním klikem fakturu naváže', async () => {
    const linked = invoice({ other_item_id: 7, other_item_link: item })
    linkMock.mockResolvedValueOnce(linked)
    const wrapper = mountLink(invoice({ other_item_suggestion: item }))

    expect(wrapper.find('[data-test="other-item-suggestion"]').exists()).toBe(true)
    await wrapper.find('[data-test="other-item-suggestion-link"]').trigger('click')
    await flushPromises()

    expect(linkMock).toHaveBeenCalledWith(42, 7)
    expect(wrapper.emitted('updated')?.[0]).toEqual([linked])
  })

  it('bez oprávnění návrh neukáže', () => {
    const wrapper = mountLink(invoice({ other_item_suggestion: item }), false)
    expect(wrapper.find('[data-test="other-item-suggestion"]').exists()).toBe(false)
  })

  it('výběr nabídne kandidáty a pohledávku s jinou částkou nedovolí vybrat', async () => {
    candidatesMock.mockResolvedValueOnce([candidate(), candidate({ id: 8, document_no: 'OP-2099-002', amount: 1000, amount_match: false, vs_match: false })])
    linkMock.mockResolvedValueOnce(invoice({ other_item_id: 7, other_item_link: item }))
    const wrapper = mountLink(invoice())

    await (wrapper.vm as unknown as { open: () => Promise<void> }).open()
    await flushPromises()

    expect(candidatesMock).toHaveBeenCalledWith(42, '')
    const buttons = wrapper.findAll('[data-test="other-item-candidate"]')
    expect(buttons).toHaveLength(2)
    expect(buttons[0].text()).toContain('OP-2099-001')
    expect(buttons[0].text()).toContain('invoice.other_item_link.vs_match')
    expect(buttons[1].attributes('disabled')).toBeDefined()

    await buttons[0].trigger('click')
    await flushPromises()
    expect(linkMock).toHaveBeenCalledWith(42, 7)
    expect(wrapper.find('[data-test="other-item-picker"]').exists()).toBe(false)
  })

  it('navázaná faktura ukáže pohledávku a umí ji odpojit', async () => {
    const unlinked = invoice()
    unlinkMock.mockResolvedValueOnce(unlinked)
    vi.spyOn(window, 'confirm').mockReturnValueOnce(true)
    const wrapper = mountLink(invoice({ other_item_id: 7, other_item_link: item }))

    expect(wrapper.find('[data-test="other-item-linked"]').text()).toContain('OP-2099-001')
    expect(wrapper.find('[data-test="other-item-linked"]').text()).toContain('invoice.other_item_link.paid')
    await wrapper.find('[data-test="other-item-unlink"]').trigger('click')
    await flushPromises()

    expect(unlinkMock).toHaveBeenCalledWith(42)
    expect(wrapper.emitted('updated')?.[0]).toEqual([unlinked])
  })
})
