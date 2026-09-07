import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

/**
 * Záložky Nastavení firmy jsou adresovatelné přes `?tab=`.
 *
 * Why: na „Daně a účetnictví" (režim účetnictví, plátcovství DPH) se odkazuje
 * z průvodce prvním nastavením i z rad typu „zapněte účetnictví v Nastavení".
 * Dokud se query jen jednou přečetla při setupu, odkaz ze stránky NA STEJNOU
 * routu neudělal nic — komponenta se nepřemountuje, takže zůstala viset první
 * záložka. A přepnutí ručně adresu neměnilo, takže nešla ani poslat, ani uložit.
 */

const m = vi.hoisted(() => ({
  query: undefined as any,
  replace: vi.fn(),
  getSupplier: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({
    currentRoute: m.query,
    replace: m.replace,
  }),
}))

vi.mock('@/api/settings', () => ({
  settingsApi: {
    getSupplier: m.getSupplier,
    getVatRegistrationCheck: vi.fn().mockResolvedValue(null),
    searchNaceCodes: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/api/admin', () => ({ adminApi: { sampleDataStatus: vi.fn().mockRejectedValue(new Error('403')) } }))
vi.mock('@/api/clients', () => ({ clientsApi: { lookupAres: vi.fn() } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_e: unknown, fallback: string) => fallback }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' }, refresh: vi.fn(), setCurrent: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isSuperadmin: true,
    isDemo: false,
    isManagedInstallation: false,
    domainsFeatureEnabled: false,
    hasCommercialFeatures: true,
    license: null,
    canRead: () => true,
    canWrite: () => true,
  }),
}))
vi.mock('@/components/settings/AutomationPolicyBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/settings/SupplierDomainsSettings.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/SearchableSelect.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/EmptyState.vue', () => ({ default: { template: '<div />' } }))

import Settings from '../Settings.vue'

const SUPPLIER = {
  id: 1, company_name: 'Testovací s.r.o.', accounting_mode: 'tax_evidence',
  is_vat_payer: false, taxpayer_type: 'po', cz_nace_resolved: null,
}

function activeTabLabel(wrapper: ReturnType<typeof mount>): string {
  const active = wrapper.findAll('nav button').find(b => b.classes().includes('border-primary-600'))
  return active?.text() ?? ''
}

async function mountSettings(tab?: string) {
  m.query = ref({ query: tab === undefined ? {} : { tab }, path: '/admin/settings' })
  const wrapper = mount(Settings)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  m.getSupplier.mockResolvedValue({ ...SUPPLIER })
})

describe('Nastavení — adresovatelné záložky', () => {
  it('bez ?tab= otevře první záložku', async () => {
    expect(activeTabLabel(await mountSettings())).toBe('settings.tab_company')
  })

  it('?tab=accounting otevře Daně a účetnictví', async () => {
    expect(activeTabLabel(await mountSettings('accounting'))).toBe('settings.tab_accounting')
  })

  it('neznámá hodnota spadne zpět na první záložku', async () => {
    expect(activeTabLabel(await mountSettings('neexistuje'))).toBe('settings.tab_company')
  })

  it('přepnutí záložky zapíše ?tab= do adresy', async () => {
    const wrapper = await mountSettings()

    const accounting = wrapper.findAll('nav button').find(b => b.text() === 'settings.tab_accounting')!
    await accounting.trigger('click')

    expect(m.replace).toHaveBeenCalledWith({ query: { tab: 'accounting' } })
    expect(activeTabLabel(wrapper)).toBe('settings.tab_accounting')
  })

  it('změna ?tab= zvenčí přepne záložku i bez přemountování', async () => {
    const wrapper = await mountSettings()

    m.query.value = { ...m.query.value, query: { tab: 'accounting' } }
    await flushPromises()

    expect(activeTabLabel(wrapper)).toBe('settings.tab_accounting')
  })
})
