import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

/**
 * Plný zápis tokenu (#371): zaškrtávátko se ukáže jen na instanci, která ho povoluje,
 * jde použít jen se zápisem (u tokenu bez vazby jen superadmin), po zaškrtnutí ukáže
 * varování a v přehledu tokenů je vidět výrazný štítek.
 */
const m = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  tokens: [] as Array<Record<string, unknown>>,
  available: true,
}))

vi.mock('@/api/tokens', () => ({
  tokensApi: {
    list: m.list,
    overview: async () => ({ tokens: await m.list(), systemWriteAvailable: m.available }),
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
  m.available = true
  m.list.mockImplementation(async () => m.tokens)
  m.create.mockResolvedValue({ token: 'mi_pat_x', id: 1, prefix: 'mi_pat_x', warning: '' })
})

describe('ApiTokens — plný zápis přes API', () => {
  it('bez povolení instance zaškrtávátko vůbec neukáže', async () => {
    m.available = false
    const wrapper = await openForm()
    expect(wrapper.find('[data-token-system-write]').exists()).toBe(false)
    await wrapper.get('[data-token-submit]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({ allow_system_write: false }))
    wrapper.unmount()
  })

  it('jen pro čtení nejde použít, se zápisem ukáže varování a příznak odešle', async () => {
    const wrapper = await openForm()
    const box = () => wrapper.get<HTMLInputElement>('[data-token-system-write]')
    expect(box().element.disabled).toBe(true)
    expect(wrapper.text()).toContain('api_tokens.system_write_unavailable')

    await wrapper.get('input[type="radio"][value="read_write"]').setValue(true)
    expect(box().element.disabled).toBe(false)
    await box().setValue(true)
    expect(wrapper.get('[data-token-system-write-warning]').text()).toBe('api_tokens.system_write_warning')

    await wrapper.get('[data-token-submit]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      supplier_id: 7, scope: 'read_write', allow_system_write: true,
    }))
    wrapper.unmount()
  })

  it('token bez vazby na firmu neodešle, pokud uživatel není superadmin', async () => {
    const wrapper = await openForm()
    await wrapper.get('input[type="radio"][value="read_write"]').setValue(true)
    await wrapper.get('[data-token-system-write]').setValue(true)
    await wrapper.get('select').findAll('option')[0].setValue()
    expect(wrapper.get<HTMLInputElement>('[data-token-system-write]').element.disabled).toBe(true)

    await wrapper.get('[data-token-submit]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({ supplier_id: null, allow_system_write: false }))
    wrapper.unmount()
  })

  it('v přehledu tokenů ukáže štítek plného zápisu', async () => {
    m.tokens = [
      {
        id: 1, supplier_id: 7, supplier_name: 'Testovací firma', supplier_company: null, name: 'Agent',
        prefix: 'mi_pat_abcd', scope: 'read_write', allow_payroll_submission_docs: false,
        allow_issued_invoice_edit: false, allow_system_write: true, eet_external_by_default: false,
        last_used_at: null, last_used_ip: null, expires_at: null, revoked_at: null,
        created_at: '2026-11-12 10:00:00', is_revoked: false, is_expired: false, ip_rule_count: 0,
      },
      {
        id: 2, supplier_id: 7, supplier_name: 'Testovací firma', supplier_company: null, name: 'Portál',
        prefix: 'mi_pat_efgh', scope: 'read_write', allow_payroll_submission_docs: false,
        allow_issued_invoice_edit: false, allow_system_write: false, eet_external_by_default: false,
        last_used_at: null, last_used_ip: null, expires_at: null, revoked_at: null,
        created_at: '2026-11-12 10:00:00', is_revoked: false, is_expired: false, ip_rule_count: 0,
      },
    ]
    const wrapper = mountPage()
    await flushPromises()
    const badges = wrapper.findAll('[data-token-system-write-badge]')
    expect(badges).toHaveLength(1)
    expect(badges[0].text()).toBe('api_tokens.system_write_badge')
    wrapper.unmount()
  })
})
