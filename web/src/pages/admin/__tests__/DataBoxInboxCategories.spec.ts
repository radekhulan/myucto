import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  browseInbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  inboxStorage: vi.fn(),
  assignInboxCategory: vi.fn(),
  markInboxRead: vi.fn(),
  replace: vi.fn(),
  query: {} as Record<string, string>,
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    credentials: m.credentials,
    recipients: m.recipients,
    outbox: m.outbox,
    browseInbox: m.browseInbox,
    mobileKeyProfile: m.mobileKeyProfile,
    unmatchedReceipts: m.unmatchedReceipts,
    inboxStorage: m.inboxStorage,
    assignInboxCategory: m.assignInboxCategory,
    markInboxRead: m.markInboxRead,
  },
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.query }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatUtcDateTime: (value: string) => value }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: unknown) => String(error) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, info: vi.fn() }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { company_name: 'Testovací firma' } }),
}))

import DataBox from '../DataBox.vue'

const categories = [
  { id: 7, code: 'tax_office', name: null, sort_order: 10, is_system: true },
  { id: 8, code: 'business_partners', name: null, sort_order: 60, is_system: true },
  { id: 9, code: null, name: 'Stavební řízení', sort_order: 100, is_system: false },
  { id: 10, code: 'other', name: null, sort_order: 90, is_system: true },
]

function message(overrides: Record<string, unknown> = {}) {
  return {
    id: 61,
    external_message_id: 'synthetic-61',
    sender_box_id: 'tst0001',
    sender_name: 'Finanční úřad pro Testov',
    subject: 'Platební výměr',
    sender_ident: '12 C 34/2026',
    classification: 'unclassified',
    matched_outbox_id: null,
    document_id: 3001,
    signature_status: 'unverified',
    delivered_at: '2026-03-10 10:00:00',
    accepted_at: null,
    fetched_at: '2026-03-10 10:01:00',
    hidden_at: null,
    hidden_by: null,
    local_content_state: 'available',
    local_content_purged_at: null,
    local_content_purged_by: null,
    lifecycle_row_version: 1,
    category_id: 7,
    category_source: 'auto',
    category_code: 'tax_office',
    category_name: null,
    direction: 'received',
    read_at: null,
    read_by: null,
    attachment_count: 2,
    sender_ref_number: 'TST-123/2026',
    recipient_ref_number: null,
    recipient_ident: null,
    ...overrides,
  }
}

function page(items = [message()]) {
  return {
    items,
    total: items.length,
    years: [2026],
    limit: 25,
    offset: 0,
    state: null,
    categories,
    facets: {
      categories: [
        { category_id: 7, count: 3, unread: 2 },
        { category_id: 8, count: 1, unread: 0 },
      ],
      directions: { received: 4, sent: 0 },
      classifications: { unclassified: 4 },
      unread: 2,
    },
    senders: [{ box_id: 'tst0001', name: 'Finanční úřad pro Testov', count: 3 }],
  }
}

async function mountInbox() {
  const wrapper = mount(DataBox, {
    global: { stubs: { EmptyState: true, teleport: true, RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('DataBox — kategorie, filtry a hledání příchozích zpráv', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.query = { tab: 'inbox' }
    m.credentials.mockResolvedValue([])
    m.recipients.mockResolvedValue([])
    m.outbox.mockResolvedValue({ items: [], total: 0 })
    m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
    m.unmatchedReceipts.mockResolvedValue([])
    m.inboxStorage.mockResolvedValue({ items: [], folders: [] })
    m.browseInbox.mockResolvedValue(page())
  })

  it('převezme filtry z URL a pošle je serveru', async () => {
    m.query = { tab: 'inbox', category: '7', read: 'unread', q: 'výměr', sort: 'sender' }
    await mountInbox()

    expect(m.browseInbox).toHaveBeenCalledWith('production', expect.objectContaining({
      category: 7,
      read: 'unread',
      q: 'výměr',
      sort: 'sender',
      order: 'asc',
      offset: 0,
    }))
  })

  it('ukáže kategorie s počty jen pro neprázdné kategorie a výběr zapíše do URL', async () => {
    const wrapper = await mountInbox()

    const items = wrapper.findAll('[data-test="inbox-category-item"]')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toContain('databox.inboxBrowse.system.tax_office')
    expect(items[0].text()).toContain('3')
    expect(wrapper.get('[data-test="inbox-category-all"]').text()).toContain('4')

    await items[1].trigger('click')
    await flushPromises()

    expect(m.browseInbox).toHaveBeenLastCalledWith('production', expect.objectContaining({ category: 8, offset: 0 }))
    expect(m.replace).toHaveBeenLastCalledWith({ query: { tab: 'inbox', category: '8' } })
  })

  it('zpráva nese odznak kategorie, přílohy a číslo jednací', async () => {
    const wrapper = await mountInbox()

    const card = wrapper.get('[data-test="inbox-mobile-card"]')
    expect(card.get('[data-test="inbox-category-badge"]').text()).toBe('databox.inboxBrowse.system.tax_office')
    expect(card.text()).toContain('databox.inboxBrowse.attachments')
    expect(card.text()).toContain('databox.inboxBrowse.refNumber')
    expect(card.text()).toContain('databox.inboxBrowse.fileMark')
  })

  it('hledání se odešle až potvrzením a vrátí seznam na první stránku', async () => {
    m.query = { tab: 'inbox', page: '3' }
    const wrapper = await mountInbox()
    expect(m.browseInbox).toHaveBeenLastCalledWith('production', expect.objectContaining({ offset: 50 }))

    await wrapper.get('[data-test="inbox-search"]').setValue('TST-123')
    await wrapper.get('form[role="search"]').trigger('submit')
    await flushPromises()

    expect(m.browseInbox).toHaveBeenLastCalledWith('production', expect.objectContaining({ q: 'TST-123', offset: 0 }))
    expect(m.replace).toHaveBeenLastCalledWith({ query: { tab: 'inbox', q: 'TST-123' } })
  })

  it('přeřadí zprávu a na přání zařadí i další zprávy odesílatele', async () => {
    m.assignInboxCategory.mockResolvedValue(message({ category_id: 9, category_source: 'manual' }))
    const wrapper = await mountInbox()

    await wrapper.get('[data-test="inbox-mobile-card"] [data-test="inbox-reassign"]').trigger('click')
    await wrapper.get('[data-test="inbox-assign-category"]').setValue('9')
    await wrapper.get('[data-test="inbox-assign-sender"]').setValue(true)
    await wrapper.get('[data-test="inbox-assign-save"]').trigger('click')
    await flushPromises()

    expect(m.assignInboxCategory).toHaveBeenCalledWith(61, 9, true)
    expect(m.toastSuccess).toHaveBeenCalledWith('databox.inboxBrowse.assign.savedWithRule')
  })

  it('nepřečtenou zprávu zvýrazní a umí ji označit jako přečtenou', async () => {
    m.markInboxRead.mockResolvedValue(message({ read_at: '2026-03-11 08:00:00', read_by: 1 }))
    const wrapper = await mountInbox()

    const card = wrapper.get('[data-test="inbox-mobile-card"]')
    expect(card.get('h3').classes()).toContain('font-semibold')
    await card.get('[data-test="inbox-toggle-read"]').trigger('click')
    await flushPromises()

    expect(m.markInboxRead).toHaveBeenCalledWith(61, true)
  })

  it('bez kategorií na serveru (starší API) zůstane prostý seznam', async () => {
    m.browseInbox.mockResolvedValue({ items: [message({ category_id: undefined })], total: 1, state: null })
    const wrapper = await mountInbox()

    expect(wrapper.find('[data-test="inbox-category-nav"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="inbox-reassign"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="inbox-mobile-card"]').exists()).toBe(true)
  })
})
