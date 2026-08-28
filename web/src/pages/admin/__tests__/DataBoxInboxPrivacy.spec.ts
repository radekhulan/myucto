import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  inboxStorage: vi.fn(),
  hideInboxMessage: vi.fn(),
  restoreInboxMessage: vi.fn(),
  purgeInboxLocalContent: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    credentials: m.credentials,
    recipients: m.recipients,
    outbox: m.outbox,
    inbox: m.inbox,
    mobileKeyProfile: m.mobileKeyProfile,
    unmatchedReceipts: m.unmatchedReceipts,
    inboxStorage: m.inboxStorage,
    hideInboxMessage: m.hideInboxMessage,
    restoreInboxMessage: m.restoreInboxMessage,
    purgeInboxLocalContent: m.purgeInboxLocalContent,
  },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatUtcDateTime: (value: string) => value }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: unknown) => String(error) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { company_name: 'Testovací firma' } }),
}))

import DataBox from '../DataBox.vue'

function message(overrides: Record<string, unknown> = {}) {
  return {
    id: 51,
    external_message_id: 'synthetic-51',
    sender_box_id: 'abc123',
    sender_name: 'Soukromý odesílatel',
    subject: 'Soukromá zpráva',
    sender_ident: null,
    classification: 'unclassified',
    matched_outbox_id: null,
    document_id: 2605,
    signature_status: 'unverified',
    delivered_at: null,
    accepted_at: null,
    fetched_at: '2026-08-27 00:00:00',
    hidden_at: null,
    hidden_by: null,
    local_content_state: 'available',
    local_content_purged_at: null,
    local_content_purged_by: null,
    lifecycle_row_version: 4,
    ...overrides,
  }
}

describe('DataBox — soukromí příchozích zpráv', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.credentials.mockResolvedValue([])
    m.recipients.mockResolvedValue([])
    m.outbox.mockResolvedValue([])
    m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
    m.unmatchedReceipts.mockResolvedValue([])
    m.inboxStorage.mockResolvedValue({ items: [], folders: [] })
  })

  it('skrývá jen nezařazenou zprávu a používá optimistickou verzi', async () => {
    m.inbox.mockResolvedValue({ items: [message()], state: null })
    m.hideInboxMessage.mockResolvedValue(message({ hidden_at: '2026-08-27 00:01:00', lifecycle_row_version: 5 }))

    const wrapper = mount(DataBox, {
      global: { stubs: { EmptyState: true, RouterLink: true } },
    })
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')
    await wrapper.get('[data-test="inbox-hide"]').trigger('click')
    await flushPromises()

    expect(m.hideInboxMessage).toHaveBeenCalledWith(51, 4)
    expect(m.toastSuccess).toHaveBeenCalledWith('databox.inbox.privacy.hidden')
  })

  it('zobrazuje příchozí zprávy jako mobilní karty se všemi důležitými akcemi', async () => {
    m.inbox.mockResolvedValue({ items: [message()], state: null })

    const wrapper = mount(DataBox, {
      global: { stubs: { EmptyState: true, RouterLink: true } },
    })
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    const card = wrapper.get('[data-test="inbox-mobile-card"]')
    expect(wrapper.get('[data-test="inbox-mobile-list"]').classes()).toContain('md:hidden')
    expect(card.text()).toContain('Soukromá zpráva')
    expect(card.text()).toContain('Soukromý odesílatel')
    expect(card.get('[data-test="inbox-mobile-message-id"]').text()).toContain('synthetic-51')
    expect(wrapper.get('[data-test="inbox-desktop-message-id"]').text()).toContain('synthetic-51')
    expect(card.find('[data-test="inbox-mobile-open-message"]').exists()).toBe(true)
    expect(card.text()).toContain('databox.notices.recordFromMessage')
    expect(card.find('[data-test="inbox-hide"]').exists()).toBe(true)
    expect(card.find('[data-test="inbox-purge-content"]').exists()).toBe(true)
  })

  it('načte skryté hlavičky a nevratné smazání odešle až po potvrzení', async () => {
    const hidden = message({ hidden_at: '2026-08-27 00:01:00' })
    m.inbox
      .mockResolvedValueOnce({ items: [], state: null })
      .mockResolvedValue({ items: [hidden], state: null })
    m.purgeInboxLocalContent.mockResolvedValue(message({
      hidden_at: '2026-08-27 00:01:00',
      document_id: null,
      local_content_state: 'purged',
      lifecycle_row_version: 5,
    }))
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    const wrapper = mount(DataBox, {
      global: { stubs: { EmptyState: true, RouterLink: true } },
    })
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')
    await wrapper.get('[data-test="inbox-visibility-hidden"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="inbox-purge-content"]').trigger('click')
    await flushPromises()

    expect(m.inbox).toHaveBeenCalledWith('production', undefined, 'hidden')
    expect(m.purgeInboxLocalContent).toHaveBeenCalledWith(51, 4)
    expect(m.toastSuccess).toHaveBeenCalledWith('databox.inbox.privacy.purged')
  })

  it('nenabízí soukromé akce zprávě navázané na agendu', async () => {
    m.inbox.mockResolvedValue({
      items: [message({ classification: 'cssz_protocol', matched_outbox_id: 12 })],
      state: null,
    })

    const wrapper = mount(DataBox, {
      global: { stubs: { EmptyState: true, RouterLink: true } },
    })
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.find('[data-test="inbox-hide"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="inbox-purge-content"]').exists()).toBe(false)
  })

  it('ukáže nedokončené fyzické mazání a nabídne bezpečné opakování', async () => {
    const purging = message({
      document_id: null,
      local_content_state: 'purging',
      lifecycle_row_version: 5,
    })
    m.inbox.mockResolvedValue({ items: [purging], state: null })
    m.purgeInboxLocalContent.mockResolvedValue(purging)
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    const wrapper = mount(DataBox, {
      global: { stubs: { EmptyState: true, RouterLink: true } },
    })
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.text()).toContain('databox.inbox.privacy.contentPurging')
    expect(wrapper.get('[data-test="inbox-purge-content"]').text())
      .toContain('databox.inbox.privacy.purgeRetry')
    await wrapper.get('[data-test="inbox-purge-content"]').trigger('click')
    await flushPromises()

    expect(m.purgeInboxLocalContent).toHaveBeenCalledWith(51, 5)
    expect(m.toastSuccess).toHaveBeenCalledWith('databox.inbox.privacy.purgePending')
  })
})
