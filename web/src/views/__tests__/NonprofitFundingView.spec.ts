import { beforeEach, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, onMounted } from 'vue'
import { createI18n } from 'vue-i18n'
import View from '../NonprofitFundingView.vue'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const auth = vi.hoisted(() => ({ user: { id: 1 } as { id: number } | null, csrfToken: 'synthetic-csrf', can: vi.fn() }))
const supplier = vi.hoisted(() => ({ currentSupplierId: 5 as number | null }))
const route = await vi.hoisted(async () => { const { reactive } = await import('vue'); return reactive({ query: {} as Record<string, string | undefined> }) })
const router = vi.hoisted(() => ({ replace: vi.fn() }))
const mounts = vi.hoisted(() => ({ work: 0, confirmed: 0, reload: 0 }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => router }))
vi.mock('@/components/accounting/funding/FundingWorkPanel.vue', () => ({ default: defineComponent({ name: 'FundingWorkPanel', props: ['supplier', 'canManage'], setup: (_p, { expose }) => { onMounted(() => { mounts.work++ }); expose({ reload: async () => { mounts.reload++ } }); return () => h('div', { 'data-stub': 'work' }) } }) }))
vi.mock('@/components/accounting/funding/FundingWorkspace.vue', () => ({ default: defineComponent({ name: 'FundingWorkspace', props: { supplier: Number, canManage: Boolean, embedded: Boolean }, setup: () => { onMounted(() => { mounts.confirmed++ }); return () => h('div', { 'data-stub': 'confirmed' }) } }) }))

function mounted() { return mount(View, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })] } }) }

beforeEach(() => {
 auth.user = { id: 1 }; auth.csrfToken = 'synthetic-csrf'; auth.can.mockReset(); auth.can.mockReturnValue(true)
 supplier.currentSupplierId = 5
 route.query = {}
 router.replace.mockReset(); router.replace.mockImplementation((to: { query: Record<string, string | undefined> }) => { route.query = to.query; return Promise.resolve() })
 mounts.work = 0; mounts.confirmed = 0; mounts.reload = 0
})

it('opens the working records tab first and loads the J6 tab only on first visit', async () => {
 const w = mounted(); await flushPromises()
 expect(w.get('[data-tab="work"]').attributes('aria-selected')).toBe('true')
 expect(mounts).toEqual({ work: 1, confirmed: 0, reload: 0 })
 expect(w.findComponent({ name: 'FundingWorkspace' }).exists()).toBe(false)
 w.unmount()
})

it('keeps both panels mounted when switching tabs so unsaved state survives', async () => {
 route.query = { tab: 'confirmed' }
 const w = mounted(); await flushPromises()
 expect(mounts).toEqual({ work: 0, confirmed: 1, reload: 0 })
 expect(w.findComponent({ name: 'FundingWorkspace' }).props('embedded')).toBe(true)
 route.query = {}
 await flushPromises()
 expect(mounts).toEqual({ work: 1, confirmed: 1, reload: 0 })
 expect(w.findComponent({ name: 'FundingWorkspace' }).exists()).toBe(true)
 expect((w.get('[data-panel="confirmed"]').element as HTMLElement).style.display).toBe('none')
 w.unmount()
})

it('reloads working records when returning from the J6 tab', async () => {
 const w = mounted(); await flushPromises()
 route.query = { tab: 'confirmed' }; await flushPromises()
 route.query = {}; await flushPromises()
 expect(mounts).toEqual({ work: 1, confirmed: 1, reload: 1 })
 w.unmount()
})

it('enables working-record writes only for a session with CSRF and accounting write', () => {
 auth.can.mockImplementation((key: string, access: string) => key === 'accounting' && access === 'write')
 let w = mounted()
 expect(w.findComponent({ name: 'FundingWorkPanel' }).props('canManage')).toBe(true)
 expect(w.findComponent({ name: 'FundingWorkPanel' }).props('supplier')).toBe(5)
 w.unmount()
 for (const mode of ['no session', 'no CSRF', 'readonly']) {
  auth.user = mode === 'no session' ? null : { id: 1 }
  auth.csrfToken = mode === 'no CSRF' ? '' : 'synthetic-csrf'
  auth.can.mockImplementation((key: string) => mode !== 'readonly' && key === 'accounting')
  w = mounted()
  expect(w.findComponent({ name: 'FundingWorkPanel' }).props('canManage'), mode).toBe(false)
  w.unmount()
 }
})
