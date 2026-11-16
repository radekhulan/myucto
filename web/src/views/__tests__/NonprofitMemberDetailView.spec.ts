import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import View from '../NonprofitMemberDetailView.vue'
import { membershipListNotice } from '@/components/accounting/membership/membershipPage'

const auth = vi.hoisted(() => ({ user: { id: 1 } as { id: number } | null, csrfToken: 'synthetic-csrf', can: vi.fn() }))
// Reaktivní jako skutečný store: přepnutí firmy musí stránka zaznamenat.
const supplier = await vi.hoisted(async () => { const { reactive } = await import('vue'); return reactive({ currentSupplierId: 5 as number | null }) })
const nav = vi.hoisted(() => ({ route: { params: { id: '7' } } as { params: Record<string, string> }, push: vi.fn(), replace: vi.fn() }))
const accounting = vi.hoisted(() => ({ listPeriods: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('@/api/accounting', () => ({ accountingApi: accounting }))
vi.mock('vue-router', async importOriginal => ({ ...await importOriginal<typeof import('vue-router')>(), useRoute: () => nav.route, useRouter: () => ({ push: nav.push, replace: nav.replace }) }))
const Detail = defineComponent({ name: 'MembershipMemberDetail', props: ['supplier', 'canManage', 'memberId', 'label', 'periods'], emits: ['close', 'deleted', 'changed'], setup: () => () => h('div') })

const period = { id: 11, supplier_id: 5, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31', status: 'open', closed_at: null, created_at: '' }
const mounted = () => mount(View, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })], stubs: { RouterLink: RouterLinkStub, MembershipMemberDetail: Detail } } })

beforeEach(() => {
  vi.clearAllMocks()
  auth.user = { id: 1 }; auth.csrfToken = 'synthetic-csrf'; auth.can.mockReturnValue(true)
  supplier.currentSupplierId = 5
  nav.route = { params: { id: '7' } }
  accounting.listPeriods.mockResolvedValue([period])
  membershipListNotice.value = ''
  window.history.replaceState({}, '')
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

it('returns to the list with the filters the user came from, otherwise to the plain list', async () => {
  window.history.replaceState({ back: '/accounting/nonprofit-membership?year=2026&search=Synthetic' }, '')
  const w = mounted(); await flushPromises()
  expect(w.get('[data-action="back-to-list"]').text()).toBe(cs.nonprofitMembership.backToList)
  expect(w.getComponent(RouterLinkStub).props('to')).toBe('/accounting/nonprofit-membership?year=2026&search=Synthetic')
  w.findComponent(Detail).vm.$emit('close'); await flushPromises()
  expect(nav.push).toHaveBeenCalledWith('/accounting/nonprofit-membership?year=2026&search=Synthetic')
  w.unmount()

  // Předchozí stránka není seznam členů (přímý odkaz, jiná agenda): návrat na seznam bez filtrů.
  window.history.replaceState({ back: '/clients/3' }, '')
  const direct = mounted(); await flushPromises()
  expect(direct.getComponent(RouterLinkStub).props('to')).toBe('/accounting/nonprofit-membership')
  direct.unmount()
})

it('leaves a deleted member without a way back to its address and lets the list confirm the deletion', async () => {
  const w = mounted(); await flushPromises()
  w.findComponent(Detail).vm.$emit('deleted'); await flushPromises()
  expect(nav.replace).toHaveBeenCalledWith('/accounting/nonprofit-membership')
  expect(nav.push).not.toHaveBeenCalled()
  expect(membershipListNotice.value).toBe(cs.nonprofitMembership.correction.done_delete)
  w.unmount()
})

it('goes to the list when the company changes, because the member belongs to the previous one', async () => {
  const w = mounted(); await flushPromises()
  supplier.currentSupplierId = 6; await nextTick(); await flushPromises()
  expect(nav.replace).toHaveBeenCalledWith('/accounting/nonprofit-membership')
  w.unmount()
})

it('renders nothing without a current company', async () => {
  supplier.currentSupplierId = null
  const w = mounted(); await flushPromises()
  expect(w.findComponent(Detail).exists()).toBe(false)
  w.unmount()
})
