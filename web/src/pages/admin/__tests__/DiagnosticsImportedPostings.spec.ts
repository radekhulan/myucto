import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, reactive } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

const m = vi.hoisted(() => ({ report: vi.fn(), preview: vi.fn() }))
const supplier = reactive({ currentSupplierId: 7 })
vi.mock('@/api/diagnostics', () => ({ diagnosticsApi: { report: m.report, preview: m.preview } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isSuperadmin: true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Diagnostics from '../Diagnostics.vue'

function report(next: number | null = 42) {
  return {
    generated_at: '', summary: { status: 'ok', ok: 1, warn: 0, fail: 0, skip: 0 }, checks: [], facts: {},
    imported_posting_ambiguities: {
      available: true,
      supplier_id: supplier.currentSupplierId, total: 22, per_page: 20, candidate_limit: 20, next_after: next,
      entries: [{ entry_id: 42, entry_date: '2098-01-01', description: 'Syntetický zápis', owner_count: 2,
        candidates: [{ doc_type: 'invoice', doc_id: 10, document_number: 'TEST-10' },
          { doc_type: 'purchase_invoice', doc_id: 11, document_number: 'TEST-11' }] }],
    },
  }
}

async function setup(keepAlive = false) {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/diagnostics', name: 'diagnostics', component: Diagnostics },
    { path: '/other', component: { template: '<div />' } },
    { path: '/journal', name: 'accounting-journal', component: { template: '<div />' } },
    { path: '/invoices/:id', name: 'invoice-detail', component: { template: '<div />' } },
    { path: '/purchases/:id', name: 'purchase-invoice-detail', component: { template: '<div />' } },
  ] })
  await router.push('/diagnostics')
  await router.isReady()
  const root = keepAlive ? defineComponent({ template: '<RouterView v-slot="{ Component }"><KeepAlive><component :is="Component" /></KeepAlive></RouterView>' }) : Diagnostics
  const wrapper = mount(root, { global: { plugins: [router], stubs: { EnvironmentCheckList: true, PayrollArchiveEncryptionPanel: true } } })
  await flushPromises()
  return { wrapper, router }
}

describe('Diagnostika nejednoznačných vlastnictví převzatých zápisů', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    supplier.currentSupplierId = 7
    m.report.mockResolvedValue(report())
    m.preview.mockResolvedValue(null)
  })

  it('otevře přesný zápis a oba kandidáty, stránkuje kurzorem', async () => {
    const { wrapper } = await setup()
    const links = wrapper.findAll('article a')
    expect(JSON.parse(links[0]!.attributes('data-to')!)).toEqual({ name: 'accounting-journal', query: { entry_id: '42' } })
    expect(JSON.parse(links[1]!.attributes('data-to')!)).toEqual({ name: 'invoice-detail', params: { id: 10 } })
    expect(JSON.parse(links[2]!.attributes('data-to')!)).toEqual({ name: 'purchase-invoice-detail', params: { id: 11 } })
    expect(links[1]!.text()).toContain('TEST-10')
    expect(links[2]!.text()).toContain('TEST-11')
    m.report.mockResolvedValue(report(null))
    await wrapper.findAll('button').find(button => button.text() === 'diagnostics.imported_postings.next')!.trigger('click')
    await flushPromises()
    expect(m.report).toHaveBeenLastCalledWith(undefined, 42)
    expect(wrapper.text()).not.toContain('diagnostics.imported_postings.next')
    wrapper.unmount()
  })

  it('při změně firmy vymaže starý nález a načte scope nové firmy', async () => {
    const { wrapper } = await setup()
    m.report.mockResolvedValue({ ...report(null), imported_posting_ambiguities: { ...report(null).imported_posting_ambiguities, supplier_id: 8, total: 0, entries: [] } })
    supplier.currentSupplierId = 8
    await flushPromises()
    expect(m.report).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('article a')).toHaveLength(0)
    expect(wrapper.text()).toContain('diagnostics.imported_postings.empty')
    wrapper.unmount()
  })

  it('po návratu do KeepAlive znovu načte nálezy', async () => {
    const { wrapper, router } = await setup(true)
    expect(m.report).toHaveBeenCalledTimes(1)
    await router.push('/other')
    await flushPromises()
    await router.push('/diagnostics')
    await flushPromises()
    expect(m.report).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('nedostupnou databázi nebo schéma neukáže jako kontrolu bez nálezů', async () => {
    m.report.mockResolvedValue({ ...report(null), imported_posting_ambiguities: { ...report(null).imported_posting_ambiguities, available: false, total: null, entries: [] } })
    const { wrapper } = await setup()
    expect(wrapper.text()).toContain('diagnostics.imported_postings.unavailable')
    expect(wrapper.text()).not.toContain('diagnostics.imported_postings.empty')
    wrapper.unmount()
  })
})
