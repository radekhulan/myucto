import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  create: vi.fn(),
  update: vi.fn(),
  get: vi.fn(),
  post: vi.fn(),
  routerPush: vi.fn(),
  routerReplace: vi.fn(),
  canWrite: vi.fn(),
  route: { params: {} as Record<string, string>, query: {} as Record<string, string> },
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listAccounts: vi.fn().mockResolvedValue([
      { id: 1, account_code: '311', name: 'Pohledávky z obchodních vztahů', is_active: true, account_type: 'asset' },
      { id: 2, account_code: '321', name: 'Závazky z obchodních vztahů', is_active: true, account_type: 'liability' },
      { id: 3, account_code: '546', name: 'Odpis pohledávky', is_active: true, account_type: 'expense' },
    ]),
    listCostCenters: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/api/internalDocuments', async () => {
  const actual = await vi.importActual<typeof import('@/api/internalDocuments')>('@/api/internalDocuments')
  return {
    ...actual,
    internalDocumentsApi: { create: m.create, update: m.update, get: m.get, post: m.post },
  }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useDimensions', async () => {
  const { ref } = await import('vue')
  return { useDimensions: () => ({ enabled: ref(false) }) }
})
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => value,
  formatMoney: (value: number) => String(value),
}))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({ push: m.routerPush, replace: m.routerReplace }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }),
}))

import InternalDocumentEditor from '../InternalDocumentEditor.vue'

const stubs = {
  RouterLink: { template: '<a><slot /></a>' },
  DateInput: { props: ['modelValue'], template: '<input />' },
  ClientSearchSelect: { template: '<div />' },
  DocumentLinkPicker: { template: '<div />' },
  DimensionFields: { template: '<div />' },
}

async function mountEditor() {
  const wrapper = mount(InternalDocumentEditor, { global: { stubs }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('InternalDocumentEditor rychlé zadání', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.route.params = {}
    m.route.query = {}
    m.canWrite.mockReturnValue(true)
    m.create.mockResolvedValue({ id: 77, status: 'draft' })
    m.update.mockResolvedValue({ id: 77, status: 'draft' })
    m.post.mockResolvedValue({ id: 77, status: 'posted', document_no: 'ID-2099-0001' })
    m.routerPush.mockResolvedValue(undefined)
  })

  it('šablona zápočtu předvyplní účty, druhý řádek se dorovná a doklad se uloží i zaúčtuje', async () => {
    const wrapper = await mountEditor()
    await wrapper.find('[data-test="template-offset"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="title"]').element as HTMLInputElement).value).toBe('internal_documents.templates.offset.title')
    expect((wrapper.find('[data-test="kind"]').element as HTMLSelectElement).value).toBe('offset')
    const accounts = wrapper.findAll('[data-test="line"] input[type="text"]').map(input => (input.element as HTMLInputElement).value)
    expect(accounts.filter(Boolean)).toEqual(['321', '311'])

    const amounts = wrapper.findAll('[data-test="line-amount"]')
    await amounts[0].setValue('1500')
    await flushPromises()
    expect(amounts[1].attributes('placeholder')).toBe('1500')
    expect(wrapper.find('[data-test="balance-badge"]').text()).toBe('accounting.manual.balanced')

    await wrapper.find('[data-test="save-post"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      kind: 'offset',
      lines: [
        expect.objectContaining({ account_code: '321', side: 'debit', amount: 1500 }),
        expect.objectContaining({ account_code: '311', side: 'credit', amount: 1500 }),
      ],
      document_links: [],
    }))
    expect(m.post).toHaveBeenCalledWith(77)
    expect(m.routerPush).toHaveBeenCalledWith({ name: 'accounting-internal-document-detail', params: { id: 77 } })
    wrapper.unmount()
  })

  it('nevyrovnaný doklad nejde zaúčtovat, ale uloží se jako koncept zkratkou Ctrl+S', async () => {
    const wrapper = await mountEditor()
    await wrapper.find('[data-test="title"]').setValue('Rozpracovaná oprava')
    const accountInputs = wrapper.findAll('[data-test="line"] input[type="text"]')
    await accountInputs[0].setValue('546')
    const amounts = wrapper.findAll('[data-test="line-amount"]')
    await amounts[0].setValue('100')
    await accountInputs.filter(input => input.attributes('id')?.endsWith('-line-1-account'))[0].setValue('311')
    await amounts[1].setValue('90')
    await flushPromises()

    expect(wrapper.find('[data-test="save-post"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="balance-badge"]').text()).toContain('accounting.manual.unbalanced')

    await wrapper.find('form').trigger('keydown', { key: 's', ctrlKey: true })
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Rozpracovaná oprava',
      lines: [
        expect.objectContaining({ account_code: '546', side: 'debit', amount: 100 }),
        expect.objectContaining({ account_code: '311', side: 'credit', amount: 90 }),
      ],
    }))
    expect(m.post).not.toHaveBeenCalled()

    // Dorovnat přidá řádek s rozdílem na slabší straně.
    await wrapper.find('[data-test="balance"]').trigger('click')
    await flushPromises()
    const lastAmount = wrapper.findAll('[data-test="line-amount"]').at(-1)!
    expect((lastAmount.element as HTMLInputElement).value).toBe('10')
    wrapper.unmount()
  })

  it('Enter v částce posledního řádku přidá řádek a bez práva zaúčtovat chybí tlačítko', async () => {
    m.canWrite.mockImplementation((perm: string) => perm !== 'accounting.journal.post')
    const wrapper = await mountEditor()
    expect(wrapper.findAll('[data-test="line"]')).toHaveLength(2)
    await wrapper.findAll('[data-test="line-amount"]')[1].trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.findAll('[data-test="line"]')).toHaveLength(3)
    expect(wrapper.find('[data-test="save-post"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('úprava konceptu načte řádky a uloží je přes update', async () => {
    m.route.params = { id: '77' }
    m.get.mockResolvedValue({
      id: 77, status: 'draft', kind: 'write_off', title: 'Odpis', document_date: '2099-02-01', entry_date: '2099-02-01',
      partner_id: null, partner_name: null, note: null, document_links: [{ doc_type: 'invoice', doc_id: 5, note: null, doc_title: 'FV2099001' }],
      lines: [{ account_code: '546', side: 'debit', amount: 800 }, { account_code: '311', side: 'credit', amount: 800 }],
    })
    const wrapper = await mountEditor()
    expect(wrapper.find('[data-test="templates"]').exists()).toBe(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(77, expect.objectContaining({
      kind: 'write_off',
      document_links: [{ doc_type: 'invoice', doc_id: 5 }],
      lines: [expect.objectContaining({ amount: 800 }), expect.objectContaining({ amount: 800 })],
    }))
    wrapper.unmount()
  })
})
