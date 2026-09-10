import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import IntegrationCenter from '../IntegrationCenter.vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  update: vi.fn(),
  diagnostics: vi.fn(),
  create: vi.fn(),
  credentials: vi.fn(),
  rotateWebhookSecret: vi.fn(),
  reconcile: vi.fn(),
  retryOutbox: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: mocks.success, error: mocks.error }) }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('@/api/eshopIntegrations', () => ({
  eshopIntegrationsApi: {
    list: mocks.list,
    update: mocks.update,
    diagnostics: mocks.diagnostics,
    create: mocks.create,
    credentials: mocks.credentials,
    rotateWebhookSecret: mocks.rotateWebhookSecret,
    reconcile: mocks.reconcile,
    retryOutbox: mocks.retryOutbox,
  },
}))

describe('IntegrationCenter', () => {
  it('normalizes legacy empty arrays before editing an empty connection', async () => {
    const connection = {
      id: 7,
      connection_uuid: '00000000-0000-4000-8000-000000000007',
      supplier_id: 1,
      connector_key: 'synthetic.empty',
      name: 'Empty mapping',
      status: 'draft',
      mappings: [],
      field_ownership: [],
      credentials_configured: false,
      webhook_configured: false,
      rate_limit_per_minute: 60,
      retention_days: 30,
      last_synced_at: null,
      last_error_code: null,
      last_error_at: null,
      created_at: '2026-09-10 12:00:00',
      updated_at: '2026-09-10 12:00:00',
    }
    mocks.list.mockResolvedValue([connection])
    mocks.diagnostics.mockResolvedValue({
      last_synced_at: null,
      last_error_code: null,
      last_error_at: null,
      inbox: {},
      outbox: {},
      errors: [],
      jobs: [],
    })
    mocks.update.mockImplementation(async (_id, input) => ({ ...connection, ...input }))

    const wrapper = mount(IntegrationCenter, {
      global: {
        stubs: {
          EmptyState: true,
          CatalogJobProgress: true,
        },
      },
    })
    await flushPromises()
    const card = wrapper.get('[data-test="connection-7"]')
    await card.trigger('click')
    await flushPromises()

    expect(card.classes()).toContain('bg-surface-raised')
    expect(card.classes()).not.toContain('bg-primary-50')
    expect((wrapper.get('[data-test="mappings"]').element as HTMLTextAreaElement).value).toBe('{}')
    expect((wrapper.get('[data-test="ownership"]').element as HTMLTextAreaElement).value).toBe('{}')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.update).toHaveBeenCalledWith(7, expect.objectContaining({
      mappings: {},
      field_ownership: {},
    }))
    expect(mocks.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
