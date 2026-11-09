import { flushPromises, shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SupplierBrief } from '@/api/auth'
import { useSupplierStore } from '@/stores/supplier'

// Tlačítko Uložit a zaúčtovat je jen za volbou instalace (auth.saveAndPostEnabled,
// výchozí vypnuto) a jen pro uživatele s právem zaúčtovat.

const m = vi.hoisted(() => ({ saveAndPostEnabled: false, canPost: true }))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/codebooks', () => ({
  codebooksApi: emptyApi({ currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', is_default: true }]) }),
}))
vi.mock('@/api/invoices', () => ({ invoicesApi: emptyApi({ previewVarsymbol: vi.fn().mockResolvedValue({}) }) }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: emptyApi() }))
vi.mock('@/api/priceList', () => ({ priceListApi: emptyApi({ list: vi.fn().mockResolvedValue({ data: [] }) }) }))
vi.mock('@/api/cash', () => ({ cashApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({
  accountingApi: { listPostingRules: vi.fn().mockResolvedValue({}), listAccounts: vi.fn().mockResolvedValue([]) },
}))
vi.mock('@/api/client', () => ({
  api: { get: vi.fn().mockResolvedValue({ data: [] }), post: vi.fn().mockResolvedValue({ data: {} }) },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: (key: string) => (key === 'accounting.journal.post' ? m.canPost : true),
    get saveAndPostEnabled() { return m.saveAndPostEnabled },
    isClientRole: false,
    isSuperadmin: false,
    hasCommercialFeatures: true,
    user: { id: 1 },
  }),
}))

import InvoiceEditor from '../InvoiceEditor.vue'

async function mountEditor() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/new', component: InvoiceEditor }],
  })
  await router.push('/invoices/new')
  await router.isReady()
  const pinia = createPinia()
  setActivePinia(pinia)
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  useSupplierStore().setAvailable(
    [{ id: 1, company_name: 'Syntetická firma', accounting_mode: 'double_entry' } as unknown as SupplierBrief],
    1,
    true,
  )
  await flushPromises()
  return wrapper
}

describe('InvoiceEditor.vue (vydané) - Uložit a zaúčtovat', () => {
  beforeEach(() => {
    m.saveAndPostEnabled = false
    m.canPost = true
  })

  it('bez volby instalace tlačítko není', async () => {
    const wrapper = await mountEditor()
    expect(wrapper.find('[data-test="save-and-post"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('se zapnutou volbou a právem zaúčtovat tlačítko je', async () => {
    m.saveAndPostEnabled = true
    const wrapper = await mountEditor()
    expect(wrapper.find('[data-test="save-and-post"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('se zapnutou volbou bez práva zaúčtovat tlačítko není', async () => {
    m.saveAndPostEnabled = true
    m.canPost = false
    const wrapper = await mountEditor()
    expect(wrapper.find('[data-test="save-and-post"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
