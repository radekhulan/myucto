import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

const mocks = vi.hoisted(() => ({
  me: vi.fn(),
  domainContext: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  authApi: {
    me: mocks.me,
    domainContext: mocks.domainContext,
  },
}))

function session(extra: Record<string, unknown> = {}) {
  return {
    user: {
      id: 2,
      email: 'ucetni@example.test',
      name: 'Účetní',
      role: { id: 130, name: 'Účetní', type: 'staff' as const, system_key: 'ucetni' },
      is_superadmin: false,
      locale: 'cs' as const,
    },
    csrf_token: 'test-csrf',
    require_totp: false,
    require_mfa: false,
    allowed_mfa_methods: [],
    session_state: 'active' as const,
    server_time: '2026-10-06T12:00:00+02:00',
    idle_expires_at: null,
    lock_after_minutes: 30,
    current_supplier_id: 1,
    suppliers: [],
    permissions: { invoices: 2 },
    permission_catalog_version: 'test',
    ...extra,
  }
}

describe('web faktura v auth store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    mocks.me.mockReset()
    mocks.domainContext.mockReset()
    mocks.domainContext.mockResolvedValue({
      mode: 'canonical',
      hostname: 'dev.example.test',
      origin: 'https://dev.example.test',
      locked: false,
      supplier_id: null,
      purpose: null,
    })
  })

  it('převezme vypnutou web fakturu ze serveru', async () => {
    const auth = useAuthStore()
    mocks.me.mockResolvedValue(session({ invoice_public_links_enabled: false }))

    expect(await auth.refresh()).toBe(true)
    expect(auth.invoicePublicLinksEnabled).toBe(false)
  })

  it('bez pole ze serveru nechá web fakturu zapnutou', async () => {
    const auth = useAuthStore()
    mocks.me.mockResolvedValueOnce(session({ invoice_public_links_enabled: false }))
    expect(await auth.refresh()).toBe(true)
    expect(auth.invoicePublicLinksEnabled).toBe(false)
    mocks.me.mockResolvedValueOnce(session())

    expect(await auth.refresh()).toBe(true)
    expect(auth.invoicePublicLinksEnabled).toBe(true)
  })
})

describe('Uložit a zaúčtovat v auth store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    mocks.me.mockReset()
    mocks.domainContext.mockReset()
    mocks.domainContext.mockResolvedValue({
      mode: 'canonical', hostname: 'dev.example.test', origin: 'https://dev.example.test',
      locked: false, supplier_id: null, purpose: null,
    })
  })

  it('bez pole ze serveru je tlačítko vypnuté', async () => {
    const auth = useAuthStore()
    mocks.me.mockResolvedValue(session())
    expect(await auth.refresh()).toBe(true)
    expect(auth.saveAndPostEnabled).toBe(false)
  })

  it('převezme zapnutou volbu instalace', async () => {
    const auth = useAuthStore()
    mocks.me.mockResolvedValue(session({ save_and_post_enabled: true }))
    expect(await auth.refresh()).toBe(true)
    expect(auth.saveAndPostEnabled).toBe(true)
  })
})
