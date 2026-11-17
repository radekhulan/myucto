import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import View from '../NonprofitMemberDetailView.vue'
import { readListHandoff, saveListHandoff } from '@/components/accounting/membership/membershipPage'

const auth = vi.hoisted(() => ({ user: { id: 1 } as { id: number } | null, csrfToken: 'synthetic-csrf', can: vi.fn() }))
// Reaktivní jako skutečný store: přepnutí firmy musí stránka zaznamenat.
const supplier = await vi.hoisted(async () => { const { reactive } = await import('vue'); return reactive({ currentSupplierId: 5 as number | null }) })
const nav = vi.hoisted(() => ({ route: { params: { id: '7' } } as { params: Record<string, string> }, push: vi.fn(), replace: vi.fn() }))
const accounting = vi.hoisted(() => ({ listPeriods: vi.fn() }))
const toast = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('@/api/accounting', () => ({ accountingApi: accounting }))
vi.mock('@/composables/useToast', () => ({ useToast: () => toast }))
vi.mock('vue-router', async importOriginal => ({ ...await importOriginal<typeof import('vue-router')>(), useRoute: () => nav.route, useRouter: () => ({ push: nav.push, replace: nav.replace }) }))
// Odkaz zpět patří do hlavičky detailu (slot), proto ho stub vykreslí.
const Detail = defineComponent({ name: 'MembershipMemberDetail', props: ['supplier', 'canManage', 'memberId', 'label', 'periods'], emits: ['deleted', 'changed'], setup: (_p, { slots }) => () => h('div', slots.back?.()) })

const period = { id: 11, supplier_id: 5, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', status: 'open', closed_at: null, created_at: '' }
const mounted = () => mount(View, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })], stubs: { RouterLink: RouterLinkStub, MembershipMemberDetail: Detail } } })

beforeEach(() => {
  vi.clearAllMocks()
  auth.user = { id: 1 }; auth.csrfToken = 'synthetic-csrf'; auth.can.mockReturnValue(true)
  supplier.currentSupplierId = 5
  nav.route = { params: { id: '7' } }
  accounting.listPeriods.mockResolvedValue([period])
  sessionStorage.clear()
})

it('shows the member from the route as a full page with accounting periods and write rights', async () => {
  const w = mounted(); await flushPromises()
  expect(w.findComponent(Detail).props()).toMatchObject({ supplier: 5, canManage: true, memberId: 7, periods: [period] })
  expect(w.findComponent(Detail).props('label')).toBeUndefined()
  w.unmount()
})

it('keeps the detail read-only without posting write permission', async () => {
  auth.can.mockReturnValue(false)
  const w = mounted(); await flushPromises()
  expect(w.findComponent(Detail).props('canManage')).toBe(false)
  w.unmount()
})

const filters = { search: 'Synthetic', year: 2026, title_key: '', payment_status: 'all' as const }
it('returns to the list with the filters the list handed over, otherwise to the plain list', async () => {
  // Panely mají paměťovou historii bez history.state.back; adresu předává seznam při otevření člena.
  saveListHandoff({ supplier: 5, pane: null, returning: false, path: '/accounting/nonprofit-membership?year=2026&search=Synthetic', filters, ids: [1], openedId: 7 })
  const w = mounted(); await flushPromises()
  expect(w.get('[data-action="back-to-list"]').text()).toBe(cs.nonprofitMembership.backToList)
  expect(w.getComponent(RouterLinkStub).props('to')).toBe('/accounting/nonprofit-membership?year=2026&search=Synthetic')
  w.unmount()

  // Předání jiné firmy nebo cizí adresa: přímý odkaz vede na seznam bez filtrů.
  saveListHandoff({ supplier: 6, pane: null, returning: false, path: '/accounting/nonprofit-membership?year=2026', filters, ids: [], openedId: 7 })
  const otherCompany = mounted(); await flushPromises()
  expect(otherCompany.getComponent(RouterLinkStub).props('to')).toBe('/accounting/nonprofit-membership')
  otherCompany.unmount()
  saveListHandoff({ supplier: 5, pane: null, returning: false, path: '/clients/3', filters, ids: [], openedId: 7 })
  const foreign = mounted(); await flushPromises()
  expect(foreign.getComponent(RouterLinkStub).props('to')).toBe('/accounting/nonprofit-membership')
  foreign.unmount()
})

it('leaves a deleted member without a way back to its address and confirms the deletion', async () => {
  const w = mounted(); await flushPromises()
  w.findComponent(Detail).vm.$emit('deleted'); await flushPromises()
  expect(nav.replace).toHaveBeenCalledWith('/accounting/nonprofit-membership')
  expect(nav.push).not.toHaveBeenCalled()
  // Oznámení je toast, ten přežije přechod na seznam ve stejném panelu.
  expect(toast.success).toHaveBeenCalledWith(cs.nonprofitMembership.correction.done_delete)
  w.unmount()
})

it('goes to the list when the company changes, because the member belongs to the previous one', async () => {
  const w = mounted(); await flushPromises()
  supplier.currentSupplierId = 6; await nextTick()
  // Člen se pod novou firmou ani na okamžik nenačítá.
  expect(w.findComponent(Detail).exists()).toBe(false)
  await flushPromises()
  expect(nav.replace).toHaveBeenCalledWith('/accounting/nonprofit-membership')
  w.unmount()
})

it('renders nothing without a current company', async () => {
  supplier.currentSupplierId = null
  const w = mounted(); await flushPromises()
  expect(w.findComponent(Detail).exists()).toBe(false)
  w.unmount()
})

it('removes a deleted member from the handed-over selection so the list does not warn about it', async () => {
  saveListHandoff({ supplier: 5, pane: null, returning: false, path: '/accounting/nonprofit-membership?year=2026', filters, ids: [1, 7], openedId: 7 })
  const w = mounted(); await flushPromises()
  w.findComponent(Detail).vm.$emit('deleted'); await flushPromises()
  expect(nav.replace).toHaveBeenCalledWith('/accounting/nonprofit-membership?year=2026')
  expect(readListHandoff(5, null)).toMatchObject({ ids: [1], openedId: null })
  w.unmount()
})

it('marks the way back on the back link so only that return restores the list state', async () => {
  saveListHandoff({ supplier: 5, pane: null, returning: false, path: '/accounting/nonprofit-membership?year=2026', filters, ids: [1], openedId: 7 })
  const w = mounted(); await flushPromises()
  expect(readListHandoff(5, null)?.returning).toBe(false)
  await w.get('[data-action="back-to-list"]').trigger('click')
  expect(readListHandoff(5, null)?.returning).toBe(true)
  w.unmount()
})
