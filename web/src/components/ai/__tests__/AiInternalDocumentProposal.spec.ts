import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AiInternalDocument } from '@/api/aiAssistant'

const m = vi.hoisted(() => ({
  create: vi.fn(),
  post: vi.fn(),
  addLink: vi.fn(),
  canWrite: vi.fn(),
}))

vi.mock('@/api/internalDocuments', async () => {
  const actual = await vi.importActual<typeof import('@/api/internalDocuments')>('@/api/internalDocuments')
  return { ...actual, internalDocumentsApi: { create: m.create, post: m.post } }
})
vi.mock('@/api/documents', () => ({ documentsApi: { addLink: m.addLink } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => value,
  formatMoney: (value: number) => String(value),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import AiInternalDocumentProposal from '../AiInternalDocumentProposal.vue'

const stubs = { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } }

function proposal(changes: Partial<AiInternalDocument> = {}): AiInternalDocument {
  return {
    kind: 'reclassification', title: 'Přeúčtování 518 na 501', document_date: '2099-09-30', entry_date: '2099-09-30',
    partner_id: null, partner_name: null, partner_ico: null, note: null,
    lines: [
      { account_code: '501', account_name: 'Spotřeba materiálu', side: 'debit', amount: 5000, dimensions: { 3: 7 }, dimension_labels: ['Zakázka: Stavba'] },
      { account_code: '518', account_name: 'Ostatní služby', side: 'credit', amount: 5000, dimensions: [], dimension_labels: [] },
    ],
    total_debit: 5000, total_credit: 5000, balanced: true, unknown_accounts: [], problems: [], postable: true,
    ...changes,
  }
}

describe('AiInternalDocumentProposal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.create.mockResolvedValue({ id: 77, status: 'draft' })
    m.post.mockResolvedValue({ id: 77, status: 'posted', document_no: 'ID-2099-0001' })
    m.addLink.mockResolvedValue({})
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('po potvrzení založí doklad ruční cestou, zaúčtuje ho a naváže dokumenty', async () => {
    const wrapper = mount(AiInternalDocumentProposal, { props: { doc: proposal(), linkDocumentIds: [11] }, global: { stubs } })
    await wrapper.get('[data-test="ai-internal-document-post"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith({
      kind: 'reclassification', title: 'Přeúčtování 518 na 501', document_date: '2099-09-30', entry_date: '2099-09-30',
      partner_id: null, partner_name: null, note: null,
      lines: [
        { account_code: '501', side: 'debit', amount: 5000, dimensions: { 3: 7 } },
        { account_code: '518', side: 'credit', amount: 5000, dimensions: {} },
      ],
      document_links: [],
    })
    expect(m.post).toHaveBeenCalledWith(77)
    expect(m.addLink).toHaveBeenCalledWith(11, 'internal_document', 77)
    expect(wrapper.emitted('created')).toEqual([[77]])
    expect(wrapper.get('[data-test="ai-internal-document-open"]').attributes('data-to')).toContain('accounting-internal-document-detail')
  })

  it('bez práva zaúčtovat nabídne jen koncept', async () => {
    m.canWrite.mockImplementation((perm: string) => perm !== 'accounting.journal.post')
    const wrapper = mount(AiInternalDocumentProposal, { props: { doc: proposal() }, global: { stubs } })
    expect(wrapper.find('[data-test="ai-internal-document-post"]').exists()).toBe(false)
    await wrapper.get('[data-test="ai-internal-document-draft"]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledTimes(1)
    expect(m.post).not.toHaveBeenCalled()
  })

  it('nálezy serverové kontroly i chybějící právo založení zablokují', async () => {
    const blocked = mount(AiInternalDocumentProposal, {
      props: { doc: proposal({ postable: false, problems: ['Strany MD a D se neshodují.'] }) }, global: { stubs },
    })
    expect(blocked.get('[data-test="ai-internal-document-problems"]').text()).toContain('Strany MD a D se neshodují.')
    expect(blocked.get('[data-test="ai-internal-document-draft"]').attributes('disabled')).toBeDefined()

    m.canWrite.mockReturnValue(false)
    const noRight = mount(AiInternalDocumentProposal, { props: { doc: proposal() }, global: { stubs } })
    expect(noRight.get('[data-test="ai-internal-document-draft"]').attributes('disabled')).toBeDefined()
    expect(noRight.text()).toContain('accounting.ai_assistant.internal_document.no_permission')
    await noRight.get('[data-test="ai-internal-document-draft"]').trigger('click')
    expect(m.create).not.toHaveBeenCalled()
  })

  it('použitý návrh z historie ukáže odkaz na doklad', () => {
    const wrapper = mount(AiInternalDocumentProposal, { props: { doc: proposal(), appliedId: 55 }, global: { stubs } })
    expect(wrapper.find('[data-test="ai-internal-document-draft"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="ai-internal-document-open"]').attributes('data-to')).toContain('55')
  })
})
