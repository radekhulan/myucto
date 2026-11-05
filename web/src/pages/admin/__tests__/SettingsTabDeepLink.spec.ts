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
  updateSupplier: vi.fn(),
  brandingWrite: true,
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
    updateSupplier: m.updateSupplier,
    getModeSwitchPreview: vi.fn().mockResolvedValue({ total: 0 }),
    getVatRegistrationCheck: vi.fn().mockResolvedValue(null),
    searchNaceCodes: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/api/closing', () => ({ closingSettingsApi: { get: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/admin', () => ({ adminApi: { sampleDataStatus: vi.fn().mockRejectedValue(new Error('403')) } }))
vi.mock('@/api/clients', () => ({ clientsApi: { lookupAres: vi.fn() } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_e: unknown, fallback: string) => fallback }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
// `createI18n` musí v mocku být: Nastavení sahá přes `useFormat` na sdílenou
// instanci z `@/i18n`, která se zakládá už při importu modulu.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' }, refresh: vi.fn(), setCurrent: vi.fn(), patchSupplier: vi.fn() }),
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
    canWrite: (permission: string) => permission !== 'settings.branding' || m.brandingWrite,
  }),
}))
vi.mock('@/components/settings/AutomationPolicyBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/settings/SupplierDomainsSettings.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/SearchableSelect.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/EmptyState.vue', () => ({ default: { template: '<div />' } }))

vi.mock('@/components/settings/CompanyEmailTemplates.vue', () => ({ default: { template: '<div data-test="company-email-templates" />' } }))

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
  m.brandingWrite = true
  m.getSupplier.mockResolvedValue({ ...SUPPLIER })
  m.updateSupplier.mockImplementation(async payload => ({ ...SUPPLIER, ...payload }))
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
  it('email template deep link opens the company editor without the company save button', async () => {
    const wrapper = await mountSettings('email-templates')
    expect(activeTabLabel(wrapper)).toBe('settings.tab_email-templates')
    expect(wrapper.find('[data-test="company-email-templates"]').exists()).toBe(true)
    expect(wrapper.findAll('button').some(button => button.text().includes('settings.save_supplier'))).toBe(false)
    wrapper.unmount()
  })

})


describe('Nastavení — samostatný ISDOC', () => {
  function checkbox(wrapper: ReturnType<typeof mount>, key: string) {
    const label = wrapper.findAll('label').find(l => l.text() === `settings.${key}`)!
    return label.find('input[type="checkbox"]')
  }

  it('nové přepínače jsou vypnuté a jejich hodnoty se uloží nezávisle na vložení do PDF', async () => {
    const wrapper = await mountSettings('documents')
    try {
      expect((checkbox(wrapper, 'public_isdoc_enabled').element as HTMLInputElement).checked).toBe(false)
      expect((checkbox(wrapper, 'email_attach_isdoc').element as HTMLInputElement).checked).toBe(false)
      await checkbox(wrapper, 'public_isdoc_enabled').setValue(true)
      await checkbox(wrapper, 'email_attach_isdoc').setValue(true)
      await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
      await flushPromises()
      expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({
        public_isdoc_enabled: true, email_attach_isdoc: true,
      }))
    } finally {
      wrapper.unmount()
    }
  })

  it('načte zapnuté přepínače a uloží jejich vypnutí', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, embed_isdoc: true, public_isdoc_enabled: true, email_attach_isdoc: true })
    const wrapper = await mountSettings('documents')
    try {
      expect((checkbox(wrapper, 'public_isdoc_enabled').element as HTMLInputElement).checked).toBe(true)
      expect((checkbox(wrapper, 'email_attach_isdoc').element as HTMLInputElement).checked).toBe(true)
      await checkbox(wrapper, 'public_isdoc_enabled').setValue(false)
      await checkbox(wrapper, 'email_attach_isdoc').setValue(false)
      await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
      await flushPromises()
      expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({
        embed_isdoc: true, public_isdoc_enabled: false, email_attach_isdoc: false,
      }))
    } finally {
      wrapper.unmount()
    }
  })
})


describe('Nastavení — podoba výkazu práce', () => {
  const DEFAULTS = { compact: false, date: true, hours: true, rate: true, amount: true }

  // BEZ OPRAVY PADÁ: každé uložení poslalo výslednou podobu, takže z firmy bez nastavení
  // (NULL) se stala výslovná hodnota.
  it('neposílá podobu výkazu, když ji uživatel nezměnil', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, work_report_display_options: DEFAULTS })
    const wrapper = await mountSettings('documents')
    try {
      await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
      await flushPromises()
      expect(m.updateSupplier).toHaveBeenCalledOnce()
      expect(m.updateSupplier.mock.calls[0]![0]).not.toHaveProperty('work_report_display_options')
    } finally {
      wrapper.unmount()
    }
  })

  it('pošle podobu výkazu po změně volby', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, work_report_display_options: DEFAULTS })
    const wrapper = await mountSettings('documents')
    try {
      await wrapper.get('[data-test="work-report-compact"]').setValue(true)
      await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
      await flushPromises()
      expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({
        work_report_display_options: { ...DEFAULTS, compact: true },
      }))
    } finally {
      wrapper.unmount()
    }
  })
})


describe('Accounting decree settings', () => {
  it('shows the derived decree read-only and omits the preserved manual value from save', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, accounting_mode: 'double_entry', tax_accounting_decree: '504', tax_accounting_decree_effective: '500', tax_accounting_decree_is_derived: true })
    const wrapper = await mountSettings('accounting')
    expect(wrapper.get('[data-test="derived-accounting-decree"]').text()).toContain('500')
    expect(wrapper.findAll('select').some(s => s.find('option[value="501"]').exists())).toBe(false)
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledOnce()
    expect(m.updateSupplier.mock.calls[0]![0]).not.toHaveProperty('tax_accounting_decree')
    wrapper.unmount()
  })

  it('allows saving the manual decree in tax evidence', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, tax_accounting_decree: '504', tax_accounting_decree_effective: '504', tax_accounting_decree_is_derived: false })
    const wrapper = await mountSettings('accounting')
    expect(wrapper.find('[data-test="derived-accounting-decree"]').exists()).toBe(false)
    const select = wrapper.findAll('select').find(s => s.find('option[value="501"]').exists())!
    await select.setValue('501')
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({ tax_accounting_decree: '501' }))
    wrapper.unmount()
  })

  it('merges framework decree fields without replacing unrelated unsaved form values', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, accounting_mode: 'double_entry', tax_accounting_decree: '504', tax_accounting_decree_effective: '500' })
    const wrapper = await mountSettings('accounting')
    const status = wrapper.findAll('select').find(s => s.find('option[value="liquidation"]').exists())!
    await status.setValue('liquidation')
    wrapper.findComponent({ name: 'AccountingFrameworkSettings' }).vm.$emit('updated', { id: 1, accounting_framework: 'cz_504', accounting_framework_history: [], tax_accounting_decree_effective: '504', tax_accounting_decree_is_derived: true })
    await flushPromises()
    expect(wrapper.get('[data-test="derived-accounting-decree"]').text()).toContain('504')
    expect((status.element as HTMLSelectElement).value).toBe('liquidation')
    wrapper.unmount()
  })
})


describe('Unsaved accounting mode and decree', () => {
  it('previews the framework decree on switching to double entry and omits the manual value', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, tax_accounting_decree: '501', tax_accounting_decree_effective: '501', tax_accounting_decree_is_derived: false, accounting_framework: 'cz_500' })
    const wrapper = await mountSettings('accounting')
    const mode = wrapper.findAll('select').find(s => s.find('option[value="double_entry"]').exists())!
    await mode.setValue('double_entry')
    expect(wrapper.get('[data-test="derived-accounting-decree"]').text()).toContain('500')
    expect(wrapper.get('[data-test="derived-accounting-decree"]').text()).not.toContain('501')
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledOnce()
    expect(m.updateSupplier.mock.calls[0]![0]).not.toHaveProperty('tax_accounting_decree')
    wrapper.unmount()
  })

  it('restores the manual choice when reversing an unsaved mode switch', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, tax_accounting_decree: '501', tax_accounting_decree_effective: '501', tax_accounting_decree_is_derived: false, accounting_framework: 'cz_504' })
    const wrapper = await mountSettings('accounting')
    const mode = wrapper.findAll('select').find(s => s.find('option[value="double_entry"]').exists())!
    await mode.setValue('double_entry')
    expect(wrapper.get('[data-test="derived-accounting-decree"]').text()).toContain('504')
    await mode.setValue('tax_evidence')
    const manual = wrapper.findAll('select').find(s => s.find('option[value="501"]').exists())!
    expect((manual.element as HTMLSelectElement).value).toBe('501')
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({ tax_accounting_decree: '501' }))
    wrapper.unmount()
  })

  it('uses the framework fallback when an older response omits the effective fields', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, accounting_mode: 'double_entry', tax_accounting_decree: '501' })
    const wrapper = await mountSettings('accounting')
    expect(wrapper.get('[data-test="derived-accounting-decree"]').text()).toBe('500 - settings.income_tax_profile.accounting_decree_500')
    wrapper.unmount()
  })
})


describe('Effective accounting mode history and decree', () => {
  it('keeps the manual decree editable before a future switch to double entry', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, accounting_mode: 'double_entry', accounting_framework: 'cz_500', tax_accounting_decree: '501', tax_accounting_decree_effective: '501', tax_accounting_decree_is_derived: false })
    const wrapper = await mountSettings('accounting')
    expect(wrapper.find('[data-test="derived-accounting-decree"]').exists()).toBe(false)
    const manual = wrapper.findAll('select').find(s => s.find('option[value="501"]').exists())!
    expect((manual.element as HTMLSelectElement).value).toBe('501')
    expect(wrapper.text()).toContain('settings.income_tax_profile.accounting_decree_hint')
    await manual.setValue('502')
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({ tax_accounting_decree: '502' }))
    wrapper.unmount()
  })

  it('keeps the decree derived before a future switch to tax evidence', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, accounting_mode: 'tax_evidence', accounting_framework: 'cz_500', tax_accounting_decree: '501', tax_accounting_decree_effective: '500', tax_accounting_decree_is_derived: true })
    const wrapper = await mountSettings('accounting')
    expect(wrapper.get('[data-test="derived-accounting-decree"]').text()).toContain('500')
    expect(wrapper.findAll('select').some(s => s.find('option[value="501"]').exists())).toBe(false)
    expect(wrapper.text()).toContain('settings.income_tax_profile.accounting_decree_derived_hint')
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledOnce()
    expect(m.updateSupplier.mock.calls[0]![0]).not.toHaveProperty('tax_accounting_decree')
    wrapper.unmount()
  })
})


describe('Nastavení — osobní údaje podnikatele', () => {
  it('OSVČ zobrazí titul, jméno a příjmení a uloží volitelný titul', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, taxpayer_type: 'fo', person_title: 'Ing.', opr_jmeno: 'Jan', opr_prijmeni: 'Novák' })
    const wrapper = await mountSettings('accounting')
    try {
      expect(wrapper.text()).toContain('settings.person_section')
      expect(wrapper.text()).toContain('settings.person_hint')
      const details = wrapper.get('[data-test="tax-person-details"]')
      expect(details.findAll('input')).toHaveLength(3)
      expect(details.text()).not.toContain('settings.opr_postaveni')
      const title = details.get('#person-title')
      expect((title.element as HTMLInputElement).value).toBe('Ing.')
      expect(title.attributes('maxlength')).toBe('10')
      await title.setValue('Mgr.')
      await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
      await flushPromises()
      expect(m.updateSupplier).toHaveBeenLastCalledWith(expect.objectContaining({
        person_title: 'Mgr.', opr_jmeno: 'Jan', opr_prijmeni: 'Novák',
      }))
      await wrapper.get('#person-title').setValue('')
      await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
      await flushPromises()
      expect(m.updateSupplier).toHaveBeenLastCalledWith(expect.objectContaining({ person_title: '' }))
    } finally {
      wrapper.unmount()
    }
  })

  it.each([null, ''])('OSVČ s neurčeným typem poplatníka (%j) také nabídne titul', async taxpayerType => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, taxpayer_type: taxpayerType })
    const wrapper = await mountSettings('accounting')
    try {
      expect(wrapper.text()).toContain('settings.person_section')
      expect(wrapper.get('[data-test="tax-person-details"]').find('#person-title').exists()).toBe(true)
    } finally {
      wrapper.unmount()
    }
  })

  it('OSVČ s dříve uloženým postavením ho vidí a může smazat, protože se posílá do XML', async () => {
    m.getSupplier.mockResolvedValue({ ...SUPPLIER, taxpayer_type: 'fo', opr_jmeno: 'Jan', opr_prijmeni: 'Novák', opr_postaveni: 'jednatel' })
    const wrapper = await mountSettings('accounting')
    try {
      const details = wrapper.get('[data-test="tax-person-details"]')
      expect(details.text()).toContain('settings.opr_postaveni')
      expect(details.findAll('input')).toHaveLength(4)
    } finally {
      wrapper.unmount()
    }
  })

  it('právnická osoba zachová podpisující osobu a její postavení bez pole titulu', async () => {
    const wrapper = await mountSettings('accounting')
    try {
      expect(wrapper.text()).toContain('settings.opr_section')
      expect(wrapper.text()).toContain('settings.opr_hint')
      const details = wrapper.get('[data-test="tax-person-details"]')
      expect(details.findAll('input')).toHaveLength(3)
      expect(details.text()).toContain('settings.opr_postaveni')
      expect(details.find('#person-title').exists()).toBe(false)
    } finally {
      wrapper.unmount()
    }
  })
})


describe('Nastavení — sdílená podoba PDF', () => {
  it('uloží kompaktní položky společně s formulářem', async () => {
    const wrapper = await mountSettings('documents')
    await wrapper.get('[data-test="invoice-pdf-compact-items"]').setValue(true)
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({ invoice_pdf_compact_items: true }))
    wrapper.unmount()
  })

  it('po odebrání práva neodešle rozpracované volby PDF', async () => {
    const wrapper = await mountSettings('documents')
    await wrapper.get('[data-test="invoice-pdf-compact-items"]').setValue(true)
    m.brandingWrite = false
    await wrapper.findAll('button').find(b => b.text() === 'settings.save_supplier')!.trigger('click')
    await flushPromises()
    const payload = m.updateSupplier.mock.calls[0]![0]
    for (const key of ['invoice_pdf_compact_items', 'invoice_pdf_monochrome', 'invoice_pdf_show_issued_by',
      'invoice_pdf_show_line_discount', 'invoice_pdf_show_base_qty', 'invoice_pdf_bank_in_footer', 'work_report_display_options']) {
      expect(payload).not.toHaveProperty(key)
    }
    wrapper.unmount()
  })
})
