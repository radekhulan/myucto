import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

/**
 * Schopnost tokenu upravit vystavenou fakturu (#355): zaškrtávátko jde použít jen
 * u tokenu vázaného na firmu se zápisem, po zaškrtnutí ukáže varování a v přehledu
 * tokenů je vidět štítek. Bez vazby nebo jen pro čtení se schopnost neodešle.
 */
const m = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  tokens: [] as Array<Record<string, unknown>>,
}))

vi.mock('@/api/tokens', () => ({
  tokensApi: {
    list: m.list,
    overview: async () => ({ tokens: await m.list(), systemWriteAvailable: false }),
    create: m.create,
    revoke: vi.fn(),
    purge: vi.fn(),
    setEetExternal: vi.fn(),
    listIps: vi.fn(),
    addIp: vi.fn(),
    removeIp: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 1, totp_enabled: false, is_superadmin: false, mfa_methods: [], passkey_count: 0 },
    setupStatus: null,
    canWrite: () => true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    currentSupplierId: 7,
    availableSuppliers: [{ id: 7, company_name: 'Testovací firma s.r.o.' }],
  }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }),
}))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  isWebAuthnAvailable: () => false,
  webAuthnErrorKey: () => null,
}))
vi.mock('@/security/oidc', () => ({
  takeSsoStepUpProof: () => null,
  ssoEnforcementPolicy: () => ({ provider: null, strong: false }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import ApiTokens from '../ApiTokens.vue'

const mountPage = () => mount(ApiTokens, {
  global: { stubs: { SsoStepUpOption: true, EmptyState: true, DateInput: true } },
})

async function openForm() {
  const wrapper = mountPage()
  await flushPromises()
  await wrapper.get('[data-token-new]').trigger('click')
  await wrapper.get('input[type="text"]').setValue('Portál')
  await wrapper.get('input[type="password"]').setValue('heslo-k-tokenu')
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  m.tokens = []
  m.list.mockImplementation(async () => m.tokens)
  m.create.mockResolvedValue({ token: 'mi_pat_x', id: 1, prefix: 'mi_pat_x', warning: '' })
})

describe('ApiTokens — úprava vystavených faktur přes API', () => {
  it('u tokenu jen pro čtení zaškrtávátko nejde použít', async () => {
    const wrapper = await openForm()
    const box = wrapper.get<HTMLInputElement>('[data-token-issued-edit]')
    expect(box.element.disabled).toBe(true)
    expect(wrapper.text()).toContain('api_tokens.issued_edit_unavailable')
    wrapper.unmount()
  })

  it('u vázaného tokenu se zápisem ukáže varování a schopnost odešle', async () => {
    const wrapper = await openForm()
    await wrapper.get('input[type="radio"][value="read_write"]').setValue(true)
    const box = wrapper.get<HTMLInputElement>('[data-token-issued-edit]')
    expect(box.element.disabled).toBe(false)
    expect(wrapper.find('[data-token-issued-edit-warning]').exists()).toBe(false)
    await box.setValue(true)
    expect(wrapper.get('[data-token-issued-edit-warning]').text()).toBe('api_tokens.issued_edit_warning')

    await wrapper.get('[data-token-submit]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      supplier_id: 7, scope: 'read_write', allow_issued_invoice_edit: true,
    }))
    wrapper.unmount()
  })

  it('bez vazby na firmu schopnost neodešle, i když zůstala zaškrtnutá', async () => {
    const wrapper = await openForm()
    await wrapper.get('input[type="radio"][value="read_write"]').setValue(true)
    await wrapper.get('[data-token-issued-edit]').setValue(true)
    await wrapper.get('select').findAll('option')[0].setValue()
    expect(wrapper.get<HTMLInputElement>('[data-token-issued-edit]').element.disabled).toBe(true)

    await wrapper.get('[data-token-submit]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      supplier_id: null, allow_issued_invoice_edit: false,
    }))
    wrapper.unmount()
  })

  it('v přehledu tokenů ukáže štítek schopnosti', async () => {
    m.tokens = [
      {
        id: 1, supplier_id: 7, supplier_name: 'Testovací firma', supplier_company: null, name: 'Portál',
        prefix: 'mi_pat_abcd', scope: 'read_write', allow_payroll_submission_docs: false,
        allow_issued_invoice_edit: true, eet_external_by_default: false, last_used_at: null,
        last_used_ip: null, expires_at: null, revoked_at: null, created_at: '2026-11-12 10:00:00',
        is_revoked: false, is_expired: false, ip_rule_count: 0,
      },
      {
        id: 2, supplier_id: 7, supplier_name: 'Testovací firma', supplier_company: null, name: 'Čtení',
        prefix: 'mi_pat_efgh', scope: 'read', allow_payroll_submission_docs: false,
        allow_issued_invoice_edit: false, eet_external_by_default: false, last_used_at: null,
        last_used_ip: null, expires_at: null, revoked_at: null, created_at: '2026-11-12 10:00:00',
        is_revoked: false, is_expired: false, ip_rule_count: 0,
      },
    ]
    const wrapper = mountPage()
    await flushPromises()
    const badges = wrapper.findAll('[data-token-issued-edit-badge]')
    expect(badges).toHaveLength(1)
    expect(badges[0].text()).toBe('api_tokens.issued_edit_badge')
    wrapper.unmount()
  })
})
