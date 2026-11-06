import { beforeEach, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import View from '@/views/NonprofitDppoView.vue'
const auth = vi.hoisted(() => ({ user: { id: 1 } as { id: number } | null, csrfToken: 'synthetic-csrf', can: vi.fn() }))
const supplier = vi.hoisted(() => ({ currentSupplierId: 1 }))
vi.mock('@/components/accounting/nonprofitDppo/NonprofitDppoWorkspace.vue', () => ({ default: { name: 'NonprofitDppoWorkspace', props: ['supplier', 'canManage', 'canEditReturn'], template: '<div />' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
const Workspace = { props: ['supplier', 'canManage', 'canEditReturn'], template: '<div />' }
beforeEach(() => { auth.user = { id: 1 }; auth.csrfToken = 'synthetic-csrf'; auth.can.mockReset(); auth.can.mockReturnValue(true) })
it('uses distinct existing legal-confirm and native-return write permissions', () => { auth.can.mockImplementation((key: string, access: string) => key === 'accounting.periods.close' && access === 'write'); const w = mount(View, { global: { stubs: { NonprofitDppoWorkspace: Workspace } } }); const props = w.findComponent(Workspace).props(); expect(props).toMatchObject({ supplier: 1, canManage: true, canEditReturn: false }); expect(auth.can).toHaveBeenCalledWith('reports', 'write'); w.unmount() })
it.each(['no session', 'no CSRF', 'readonly'])('does not enable mutations with %s', mode => { if (mode === 'no session') auth.user = null; if (mode === 'no CSRF') auth.csrfToken = ''; if (mode === 'readonly') auth.can.mockReturnValue(false); const w = mount(View, { global: { stubs: { NonprofitDppoWorkspace: Workspace } } }); expect(w.findComponent(Workspace).props()).toMatchObject({ canManage: false, canEditReturn: false }); w.unmount() })
