import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  inboxStorage: vi.fn(),
  saveInboxStorage: vi.fn(),
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
    saveInboxStorage: m.saveInboxStorage,
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

describe('DataBox — archiv příchozích zpráv', () => {
  it('zobrazuje celou cestu složky a uloží ji pro aktuální prostředí', async () => {
    m.credentials.mockResolvedValue([])
    m.recipients.mockResolvedValue([])
    m.outbox.mockResolvedValue([])
    m.inbox.mockResolvedValue({ items: [], state: null })
    m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
    m.unmatchedReceipts.mockResolvedValue([])
    m.inboxStorage.mockResolvedValue({
      items: [{
        supplier_id: 1,
        channel: 'isds',
        environment: 'production',
        base_folder_id: 10,
        row_version: 2,
        updated_by: 1,
        created_at: '2026-08-27 00:00:00',
        updated_at: '2026-08-27 00:00:00',
      }],
      folders: [
        { id: 10, parent_id: null, name: 'Datová schránka' },
        { id: 11, parent_id: 10, name: 'Příchozí' },
      ],
    })
    m.saveInboxStorage.mockResolvedValue({
      supplier_id: 1,
      channel: 'isds',
      environment: 'production',
      base_folder_id: 11,
      row_version: 3,
      updated_by: 1,
      created_at: '2026-08-27 00:00:00',
      updated_at: '2026-08-27 00:01:00',
    })

    const wrapper = mount(DataBox, {
      global: { stubs: { EmptyState: true, RouterLink: true } },
    })
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.text()).toContain('Datová schránka / Příchozí')
    await wrapper.get('[data-test="inbox-archive-folder"]').setValue('11')
    await wrapper.get('[data-test="inbox-archive-save"]').trigger('click')
    await flushPromises()

    expect(m.saveInboxStorage).toHaveBeenCalledWith('production', 11, 2)
    expect(m.toastSuccess).toHaveBeenCalledWith('databox.inbox.archive.saved')
  })
})
