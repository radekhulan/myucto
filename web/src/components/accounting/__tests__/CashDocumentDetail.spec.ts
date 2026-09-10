import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { CashDocument } from '@/api/cash'

const m = vi.hoisted(() => ({ canRead: vi.fn(), canWrite: vi.fn() }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead, canWrite: m.canWrite }) }))
vi.mock('@/components/documents/AttachmentCheckBadge.vue', () => ({
  default: {
    name: 'AttachmentCheckBadge',
    props: { entityType: String, entityId: Number, canAcknowledge: Boolean },
    template: '<span />',
  },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/components/documents/LinkedDocumentsPanel.vue', () => ({
  default: {
    name: 'LinkedDocumentsPanel',
    props: { entityType: String, entityId: Number, uploadable: Boolean, title: String },
    template: '<div />',
  },
}))

import CashDocumentDetail from '@/components/accounting/CashDocumentDetail.vue'

function doc(overrides: Partial<CashDocument> = {}): CashDocument {
  return {
    id: 42, supplier_id: 1, register_id: 1, doc_type: 'out', purpose: 'other', doc_number: 'VPD-2093-0001',
    issue_date: '2093-03-01', tax_date: null, partner_name: null, partner_ic: null, partner_dic: null,
    description: 'Poštovné', total_amount: 250, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
    invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
    status: 'posted', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    ...overrides,
  } as CashDocument
}

describe('CashDocumentDetail — přílohy', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead.mockReturnValue(true)
    m.canWrite.mockReturnValue(true)
  })

  it('ukáže odznak porovnání s vytěženou přílohou tohoto dokladu', () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mount(CashDocumentDetail, { props: { doc: doc(), purposeLabel: (p: string) => p } })

    const badge = wrapper.findComponent({ name: 'AttachmentCheckBadge' })
    expect(badge.exists()).toBe(true)
    expect(badge.props('entityType')).toBe('cash_document')
    expect(badge.props('entityId')).toBe(42)
    expect(badge.props('canAcknowledge')).toBe(false)
    expect(m.canWrite).toHaveBeenCalledWith('cash.document.write')
  })

  it('ukáže sekci Přílohy navázanou na tento pokladní doklad s nahráváním', () => {
    const wrapper = mount(CashDocumentDetail, { props: { doc: doc(), purposeLabel: (p: string) => p } })

    const panel = wrapper.findComponent({ name: 'LinkedDocumentsPanel' })
    expect(panel.exists()).toBe(true)
    expect(panel.props('entityType')).toBe('cash_document')
    expect(panel.props('entityId')).toBe(42)
    expect(panel.props('uploadable')).toBe(true)
    expect(panel.props('title')).toBe('linked_documents.title')
    expect(m.canRead).toHaveBeenCalledWith('documents')
  })

  it('bez práva na Dokumenty sekci nezobrazí', () => {
    m.canRead.mockReturnValue(false)
    const wrapper = mount(CashDocumentDetail, { props: { doc: doc(), purposeLabel: (p: string) => p } })
    expect(wrapper.findComponent({ name: 'LinkedDocumentsPanel' }).exists()).toBe(false)
  })

  it('klik v sekci nezavře rozbalenou kartu (neprobublá)', async () => {
    const outer = vi.fn()
    const wrapper = mount({
      components: { CashDocumentDetail },
      setup: () => ({ outer, d: doc(), label: (p: string) => p }),
      template: '<div @click="outer"><CashDocumentDetail :doc="d" :purpose-label="label" /></div>',
    })
    await wrapper.find('[data-testid="cash-attachments"]').trigger('click')
    expect(outer).not.toHaveBeenCalled()
  })
})
