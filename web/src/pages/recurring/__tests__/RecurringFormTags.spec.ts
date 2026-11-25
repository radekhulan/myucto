import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

// Interní štítky šablony pravidelné fakturace jsou volba firmy (výchozí vypnuto):
// bez ní editor šablony pole štítků neukáže a štítky v uložení neposílá.

const m = vi.hoisted(() => ({
  tagsEnabled: false,
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/recurring', () => ({ recurringApi: emptyApi() }))
vi.mock('@/api/clients', () => ({ clientsApi: emptyApi({ list: vi.fn().mockResolvedValue({ data: [], meta: {} }) }) }))
vi.mock('@/api/projects', () => ({ projectsApi: emptyApi() }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: emptyApi() }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: emptyApi() }))
vi.mock('@/api/priceList', () => ({ priceListApi: emptyApi({ list: vi.fn().mockResolvedValue({ data: [] }) }) }))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi() }))
vi.mock('@/api/client', () => ({
  api: { get: vi.fn().mockResolvedValue({ data: [] }), post: vi.fn().mockResolvedValue({ data: {} }) },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
    isClientRole: false,
    isSuperadmin: false,
    hasCommercialFeatures: true,
    user: { id: 1 },
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    currentSupplierId: 1,
    currentSupplier: { id: 1, is_vat_payer: true, accounting_mode: 'tax_evidence', tags_enabled: m.tagsEnabled },
  }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async importOriginal => {
  const { ref } = await import('vue')
  return {
    ...await importOriginal<typeof import('vue-i18n')>(),
    useI18n: () => ({ t: (key: string) => key, tm: () => [], rt: (v: unknown) => String(v), te: () => false, locale: ref('cs') }),
  }
})

import RecurringForm from '../RecurringForm.vue'
import TagInput from '@/components/documents/TagInput.vue'

describe('RecurringForm.vue - štítky jako volba firmy', () => {
  beforeEach(() => {
    m.tagsEnabled = false
  })

  it('bez zapnutých štítků pole štítků neukáže', async () => {
    const wrapper = shallowMount(RecurringForm)
    await flushPromises()
    expect(wrapper.findComponent(TagInput).exists()).toBe(false)
    wrapper.unmount()
  })

  it('se zapnutými štítky pole štítků ukáže', async () => {
    m.tagsEnabled = true
    const wrapper = shallowMount(RecurringForm)
    await flushPromises()
    expect(wrapper.findComponent(TagInput).exists()).toBe(true)
    wrapper.unmount()
  })
})
