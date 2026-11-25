import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

// Štítky vystavené faktury se v detailu mění samostatnou akcí (POST /invoices/{id}/tags),
// bez vynucené úpravy dokladu. Klientská role je nevidí ani nemění.

const m = vi.hoisted(() => ({
  clientRole: false,
  setTags: vi.fn(),
  invoice: {
    id: 9, supplier_id: 1, client_id: 4, status: 'sent', invoice_type: 'invoice', booked_at: '2026-10-02 10:00:00',
    varsymbol: '2610001', currency: 'CZK', total: 1210, amount_to_pay: 1210, paid_total: 0,
    issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
    items: [], public_token: null, public_viewed_at: null, tags: [{ id: 1, name: 'Hosting' }],
  } as Record<string, unknown>,
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({ invoicesApi: emptyApi({ get: vi.fn(async () => ({ ...m.invoice })), setTags: m.setTags }) }))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
    get isClientRole() { return m.clientRole },
    isSuperadmin: false,
    isCompanyAdminRole: true,
    hasCommercialFeatures: true,
    invoicePublicLinksEnabled: true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry', stock_enabled: false } }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'

interface DetailVm {
  canEditTags: boolean
  tagsEditing: boolean
  tagsDraft: string[]
  invoice: { tags?: Array<{ id: number; name: string }> } | null
  startTagsEdit: () => void
  saveTags: () => Promise<void>
}

async function mountDetail() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/:id', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('interní štítky v detailu vydané faktury', () => {
  beforeEach(() => {
    m.clientRole = false
    m.setTags.mockReset()
  })

  it('zaúčtovanou fakturu přeštítkuje samostatnou akcí bez vynucené úpravy', async () => {
    m.setTags.mockResolvedValue({ ...m.invoice, tags: [{ id: 1, name: 'Hosting' }, { id: 4, name: 'Servis' }] })
    const wrapper = await mountDetail()
    const vm = wrapper.vm as unknown as DetailVm
    expect(vm.canEditTags).toBe(true)

    vm.startTagsEdit()
    expect(vm.tagsDraft).toEqual(['Hosting'])
    vm.tagsDraft = ['Hosting', 'Servis']
    await vm.saveTags()

    expect(m.setTags).toHaveBeenCalledWith(9, ['Hosting', 'Servis'])
    expect(vm.invoice?.tags?.map(tag => tag.name)).toEqual(['Hosting', 'Servis'])
    expect(vm.tagsEditing).toBe(false)
    wrapper.unmount()
  })

  it('klientská role štítky neupravuje', async () => {
    m.clientRole = true
    const wrapper = await mountDetail()
    expect((wrapper.vm as unknown as DetailVm).canEditTags).toBe(false)
    wrapper.unmount()
  })
})
