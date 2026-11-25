import { flushPromises, shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import type { SupplierBrief } from '@/api/auth'
import type { InvoicePayload } from '@/api/invoices'
import { useSupplierStore } from '@/stores/supplier'

// Editor posílá štítky jen při změně proti uloženému stavu. Po uložení, po kterém editor
// zůstane otevřený (např. selže uložení výkazu), se musí výchozí stav obnovit z odpovědi,
// jinak se pozdější odebrání štítků neodešle a na dokladu zůstanou.

const m = vi.hoisted(() => ({
  create: vi.fn(),
  update: vi.fn(),
}))

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/codebooks', () => ({
  codebooksApi: emptyApi({ currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', is_default: true }]) }),
}))
vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({ previewVarsymbol: vi.fn().mockResolvedValue({}), create: m.create, update: m.update }),
}))
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
    canWrite: () => true,
    saveAndPostEnabled: false,
    isClientRole: false,
    isSuperadmin: false,
    hasCommercialFeatures: true,
    user: { id: 1 },
  }),
}))

import InvoiceEditor from '../InvoiceEditor.vue'
import TagInput from '@/components/documents/TagInput.vue'

interface EditorVm {
  tagNames: string[]
  buildPayload: (items: never[]) => InvoicePayload
  saveDocument: (payload: InvoicePayload) => Promise<void>
}

async function mountEditor(tagsEnabled = true) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/invoices/new', component: InvoiceEditor },
      { path: '/invoices/:id', component: { render: () => null } },
    ],
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
    [{ id: 1, company_name: 'Syntetická firma', accounting_mode: 'double_entry', tags_enabled: tagsEnabled } as unknown as SupplierBrief],
    1,
    true,
  )
  await flushPromises()
  return wrapper
}

describe('InvoiceEditor.vue (vydané) - štítky po uložení', () => {
  it('po uložení porovnává štítky s uloženým stavem, takže odebrání se odešle', async () => {
    m.create.mockResolvedValue({ id: 5, items: [], tags: [{ id: 1, name: 'Hosting' }] })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as unknown as EditorVm

    vm.tagNames = ['Hosting']
    const first = vm.buildPayload([])
    expect(first.tags).toEqual(['Hosting'])
    await vm.saveDocument(first)
    await flushPromises()
    expect(m.create).toHaveBeenCalledTimes(1)

    expect(vm.buildPayload([]).tags).toBeUndefined()
    vm.tagNames = []
    expect(vm.buildPayload([]).tags).toEqual([])
    wrapper.unmount()
  })
})

describe('InvoiceEditor.vue (vydané) - štítky jako volba firmy', () => {
  it('se zapnutými štítky ukáže pole štítků', async () => {
    const wrapper = await mountEditor(true)
    expect(wrapper.findComponent(TagInput).exists()).toBe(true)
    wrapper.unmount()
  })

  it('bez zapnutých štítků pole skryje a štítky neposílá', async () => {
    const wrapper = await mountEditor(false)
    expect(wrapper.findComponent(TagInput).exists()).toBe(false)
    const vm = wrapper.vm as unknown as EditorVm
    vm.tagNames = ['Hosting']
    expect(vm.buildPayload([]).tags).toBeUndefined()
    wrapper.unmount()
  })
})
