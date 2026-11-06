import { beforeEach, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import View from '../NonprofitMembershipView.vue'

const auth = vi.hoisted(() => ({ user: { id: 1 } as { id: number } | null, csrfToken: 'synthetic-csrf', can: vi.fn() }))
const supplier = vi.hoisted(() => ({ currentSupplierId: 5 as number | null }))
vi.mock('@/components/accounting/membership/MembershipWorkspace.vue', () => ({ default: { name: 'MembershipWorkspace', props: ['supplier', 'canManage'], template: '<div />' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
const Workspace = { props: ['supplier', 'canManage'], template: '<div />' }

beforeEach(() => {
  auth.user = { id: 1 }
  auth.csrfToken = 'synthetic-csrf'
  auth.can.mockReset()
  auth.can.mockReturnValue(true)
  supplier.currentSupplierId = 5
})

it('enables mutations only for a real session with CSRF and posting write permission', () => {
  auth.can.mockImplementation((key: string, access: string) => key === 'accounting.journal.post' && access === 'write')
  const w = mount(View, { global: { stubs: { MembershipWorkspace: Workspace } } })
  expect(w.findComponent(Workspace).props()).toMatchObject({ supplier: 5, canManage: true })
  expect(auth.can).toHaveBeenCalledWith('accounting.journal.post', 'write')
  w.unmount()
})

it.each(['no session', 'no CSRF', 'readonly', 'other write permission only'])('does not enable mutations with %s', mode => {
  if (mode === 'no session') auth.user = null
  if (mode === 'no CSRF') auth.csrfToken = ''
  if (mode === 'readonly') auth.can.mockReturnValue(false)
  if (mode === 'other write permission only') auth.can.mockImplementation((key: string) => key === 'accounting.periods.close')
  const w = mount(View, { global: { stubs: { MembershipWorkspace: Workspace } } })
  expect(w.findComponent(Workspace).props('canManage')).toBe(false)
  w.unmount()
})

it('mounts nothing without a current supplier and passes the current supplier otherwise', () => {
  supplier.currentSupplierId = null
  const empty = mount(View, { global: { stubs: { MembershipWorkspace: Workspace } } })
  expect(empty.findComponent(Workspace).exists()).toBe(false)
  empty.unmount()
  supplier.currentSupplierId = 5
  const w = mount(View, { global: { stubs: { MembershipWorkspace: Workspace } } })
  expect(w.findComponent(Workspace).props('supplier')).toBe(5)
  w.unmount()
})
