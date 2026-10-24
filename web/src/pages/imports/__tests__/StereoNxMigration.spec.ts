import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import StereoNxMigration from '../StereoNxMigration.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'
import StereoNxMessages from '@/components/migration/StereoNxMessages.vue'
import StereoNxPartnerCountries from '@/components/migration/StereoNxPartnerCountries.vue'
import CountrySelect from '@/components/ui/CountrySelect.vue'
import type { StereoAccountMapping, StereoAccountMappingProposal, StereoCountryCandidate, StereoPartnerCountries } from '@/api/stereoNx'

const m = vi.hoisted(() => ({
  downloadProtocol: vi.fn(), uploads: vi.fn(), upload: vi.fn(), preview: vi.fn(), run: vi.fn(), remove: vi.fn(), fillCompanyProfile: vi.fn(), accountMapping: vi.fn(), partnerCountries: vi.fn(), lookupAres: vi.fn(), lookupVies: vi.fn(),
  locale: { value: 'cs' }, toastError: vi.fn(), supplierStore: undefined as any,
  // Stránka job spustí (`start`) a sleduje (`follow`); testy dál pracují s jedním `run`.
  jobs: new Map<number, [string, number, string, boolean, boolean, StereoAccountMapping | undefined, StereoPartnerCountries | undefined]>(),
}))

vi.mock('@/api/clients', () => ({ clientsApi: { lookupAres: m.lookupAres, lookupVies: m.lookupVies } }))
vi.mock('@/api/stereoNx', () => ({ stereoNxApi: {
  downloadProtocol: m.downloadProtocol, uploads: m.uploads, upload: m.upload, preview: m.preview, run: m.run, remove: m.remove, fillCompanyProfile: m.fillCompanyProfile, accountMapping: m.accountMapping, partnerCountries: m.partnerCountries,
  start: async (token: string, company: number, mode: string, blank: boolean, match: boolean = false, mapping?: StereoAccountMapping, countries?: StereoPartnerCountries) => {
    const id = m.jobs.size + 1
    m.jobs.set(id, [token, company, mode, blank, match, mapping, countries])
    return id
  },
  follow: async (_token: string, id: number, onJob?: (job: object) => void) => {
    const [token, company, mode, blank, , , countries] = m.jobs.get(id)!
    return countries && Object.keys(countries).length
      ? m.run(token, company, mode, blank, onJob ?? (() => {}), countries)
      : m.run(token, company, mode, blank, onJob ?? (() => {}))
  },
} }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplierStore = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => m.supplierStore }
})
vi.mock('@/i18n', () => ({ i18n: { global: { locale: m.locale } } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, params?: Record<string, unknown>) => key === 'stereo_nx.date_bounds' ? `${params?.from} až ${params?.to}` : key, te: () => true, tm: () => ['Synthetic backup instruction'],
  rt: (value: string) => value, locale: m.locale,
}) }))

const upload = { token: 'synthetic-token', filename: 'synthetic.zip', size: 1024,
  received: 1024, complete: true, created_at: 1700000000 }
const company = { index: 0, label: 'Syntetická firma',
  identity: { ico: '12345678', dic: 'CZ12345678', name: 'Syntetická firma', vat_payer: true, accounting_mode: 'tax_evidence' },
  matches_target: true }
const preview = { companies: [company], target: { ico: '12345678', accounting_mode: 'tax_evidence', vat_payer: true } }
const dryReport = { ok: true, counts: { issued: 1, purchases: 1, requires_draft: 1 },
  review_reasons: { vat_participation_unassigned: 1 }, errors: [], warnings: [],
  written: { issued: 1, purchases: 1 } }
const importReport = { ...dryReport, database_writes: true, written: { issued: 1, purchases: 1 } }
const countryCandidate: StereoCountryCandidate = {
  source_key: 'synthetic-vendor', name: 'Syntetický dodavatel', ico: '12345678', dic: '',
  source_country: '', country_code: 'CZ', confirmed_country: null,
  documents: [{ source_key: 'synthetic-purchase', document_no: 'SYN-PF-1', kind: 'purchase' }],
}
const mappingProposal: StereoAccountMappingProposal = {
  accounts: { '343': '343', '501001': '501.001' }, bank_accounts: { SYN: '221.001' },
  vat: { input: '343.100', output: '343.200', settlement: '343.900', unresolved: null },
  used_accounts: [{ source_code: '343', name: 'Synthetic VAT', target_code: '343', count: 1 }, { source_code: '501001', name: 'Synthetic expense', target_code: '501.001', count: 1 }],
  banks: [{ source_key: 'SYN', label: 'Synthetic bank', target_code: '221.001' }], unresolved: [], vat_counts: { input: 1, output: 0, settlement: 0, unresolved: 0 },
}
const doubleEntryPreview = { ...preview, companies: [{ ...company, identity: { ...company.identity, accounting_mode: 'double_entry' } }], target: { ...preview.target, accounting_mode: 'double_entry' } }

function primaryButton(wrapper: Awaited<ReturnType<typeof mountPage>>, key: string) {
  const button = wrapper.get('[data-testid="stereo-actions"]').findAll('button').find(item => item.text().includes(`stereo_nx.${key}`))
  expect(button, `missing button ${key}`).toBeDefined()
  return button!
}

async function mountPage() {
  const wrapper = mount(StereoNxMigration, { global: { stubs: { CountrySelect: true, RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

async function openExisting(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  const button = wrapper.findAll('button').find(item => item.text().includes('stereo_nx.open_upload'))
  expect(button).toBeDefined()
  await button!.trigger('click')
  await flushPromises()
}

async function reachDryRun(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await openExisting(wrapper)
  expect(wrapper.get('[data-testid="stereo-company-select"]').element).toBeTruthy()
  await primaryButton(wrapper, 'continue').trigger('click')
  await flushPromises()
  if (wrapper.find('[data-testid="stereo-account-mapping"]').exists()) {
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
  }
  expect(wrapper.find('[data-testid="stereo-country-step"]').exists()).toBe(true)
  await primaryButton(wrapper, 'continue').trigger('click')
  await flushPromises()
  await primaryButton(wrapper, 'dry_run').trigger('click')
  await flushPromises()
}

async function reviewCountries(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await wrapper.get('[data-testid="stereo-step-3"]').trigger('click')
  await flushPromises()
}

async function rerunFromCountries(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await primaryButton(wrapper, 'continue').trigger('click')
  await primaryButton(wrapper, 'dry_run').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.resetAllMocks()
  m.jobs.clear()
  m.locale.value = 'cs'
  sessionStorage.clear()
  m.supplierStore.currentSupplierId = 1
  m.uploads.mockResolvedValue([upload])
  m.upload.mockResolvedValue({ token: upload.token })
  m.preview.mockResolvedValue(preview)
  m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run' ? dryReport : importReport)
  m.downloadProtocol.mockResolvedValue(undefined)
  m.remove.mockResolvedValue(undefined)
  m.fillCompanyProfile.mockResolvedValue({ filled_fields: [] })
  m.accountMapping.mockResolvedValue(mappingProposal)
  m.partnerCountries.mockResolvedValue([])
  m.lookupAres.mockResolvedValue({ found: true, source: 'fresh', data: {
    company_name: 'Syntetický dodavatel z registru', ic: countryCandidate.ico, dic: '',
    street: 'Syntetická 1', city: 'Testov', zip: '10000', country_iso2: 'CZ', is_vat_payer: true,
  } })
  m.lookupVies.mockResolvedValue({ valid: true, source: 'rest', country: 'DE', vat_number: 'DE123456789', name: 'Synthetic EU supplier', address: 'Synthetic address' })
})

afterEach(() => vi.clearAllMocks())

describe('Stereo NX migration wizard', () => {
  it('allows explicitly confirming the document country when VIES does not confirm a historical foreign VAT ID', async () => {
    const historicalPartner = { ...countryCandidate, ico: '', dic: 'IE0000000A', source_country: 'EMU' }
    m.partnerCountries.mockResolvedValue([historicalPartner])
    m.lookupVies.mockResolvedValue({ valid: false, source: 'rest', country: 'IE', vat_number: historicalPartner.dic })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-error-0"]').exists()).toBe(true)
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({})
    await wrapper.get('[data-testid="stereo-country-manual-toggle-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.getComponent(CountrySelect).props('modelValue')).toBe('')
    wrapper.getComponent(CountrySelect).vm.$emit('update:modelValue', 'IE')
    await flushPromises()
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({})
    await wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({ 'synthetic-vendor': 'IE' })
    await rerunFromCountries(wrapper)
    expect(m.jobs.get(1)?.[6]).toEqual({ 'synthetic-vendor': 'IE' })
    wrapper.unmount()
  })

  it('requires a successful read-only country preview before the dry-run and allows retrying it', async () => {
    m.partnerCountries.mockRejectedValueOnce(new Error('Synthetic preview failure')).mockResolvedValueOnce([])
    const wrapper = await mountPage()
    await openExisting(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-step"]').exists()).toBe(true)
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="stereo-step-4"]').attributes('disabled')).toBeDefined()
    expect(m.run).not.toHaveBeenCalled()
    const retry = wrapper.get('[data-testid="stereo-actions"]').findAll('button').find(button => button.text().includes('stereo_nx.partner_countries.reload'))!
    await retry.trigger('click')
    await flushPromises()
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeUndefined()
    expect(m.partnerCountries).toHaveBeenCalledTimes(2)
    expect(m.run).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('ignores a late country preview from a different company context', async () => {
    let resolve!: (value: StereoCountryCandidate[]) => void
    m.partnerCountries.mockReturnValueOnce(new Promise<StereoCountryCandidate[]>(done => { resolve = done }))
    const wrapper = await mountPage()
    await openExisting(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    m.supplierStore.currentSupplierId = 2
    await flushPromises()
    resolve([countryCandidate])
    await flushPromises()
    expect(wrapper.findComponent(StereoNxPartnerCountries).exists()).toBe(false)
    expect(m.run).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('resolves an EMU partner through foreign VAT ID and sends its confirmed country to a fresh dry-run', async () => {
    m.run.mockResolvedValue({ ...dryReport, partner_country_candidates: [{ ...countryCandidate, source_country: 'EMU', dic: 'DE123456789' }] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await reviewCountries(wrapper)
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(m.lookupVies).toHaveBeenCalledWith('DE123456789')
    expect(m.lookupAres).not.toHaveBeenCalled()
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({})
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-protocol-pdf"]').exists()).toBe(false)
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({ 'synthetic-vendor': 'DE' })
    await rerunFromCountries(wrapper)
    expect(m.jobs.get(2)?.[6]).toEqual({ 'synthetic-vendor': 'DE' })
    wrapper.unmount()
  })

  it('requires a fresh dry-run after confirming an ARES proposal and persists the exact choice through resume and import', async () => {
    m.run.mockImplementation(async (_token: string, _company: number, mode: string, _blank: boolean, _onJob: unknown, countries: StereoPartnerCountries = {}) => ({
      ...dryReport, database_writes: mode === 'import',
      partner_country_candidates: [{ ...countryCandidate, confirmed_country: countries[countryCandidate.source_key] ?? null }],
    }))
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await reviewCountries(wrapper)
    expect(m.lookupAres).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    await flushPromises()
    expect(m.lookupAres).toHaveBeenCalledWith(countryCandidate.ico)
    expect(m.run).toHaveBeenCalledTimes(1)
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({})
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-changed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-protocol-pdf"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="stereo-import-confirm"]').exists()).toBe(false)
    await rerunFromCountries(wrapper)
    expect(m.jobs.get(2)?.[6]).toEqual({ 'synthetic-vendor': 'CZ' })
    await reviewCountries(wrapper)
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('changed')).toBe(false)
    wrapper.unmount()
    const resumed = await mountPage()
    await reviewCountries(resumed)
    expect(resumed.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({ 'synthetic-vendor': 'CZ' })
    await primaryButton(resumed, 'continue').trigger('click')
    await primaryButton(resumed, 'continue').trigger('click')
    await resumed.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(resumed, 'import').trigger('click')
    await flushPromises()
    expect(m.jobs.get(3)?.[6]).toEqual({ 'synthetic-vendor': 'CZ' })
    expect(resumed.findComponent(StereoNxPartnerCountries).exists()).toBe(false)
    resumed.unmount()
  })

  it('keeps unresolved partners available after undo and discards their choices when the company changes', async () => {
    m.run.mockResolvedValue({ ...dryReport, partner_country_candidates: [countryCandidate] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await reviewCountries(wrapper)
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    await wrapper.get('[data-testid="stereo-country-undo-0"]').trigger('click')
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({})
    expect(wrapper.find('[data-testid="stereo-country-changed"]').exists()).toBe(true)
    m.supplierStore.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.findComponent(StereoNxPartnerCountries).exists()).toBe(false)
    wrapper.unmount()
  })

  it('allows undoing a country choice after a dry-run fails before returning its country candidates', async () => {
    m.run.mockResolvedValueOnce({ ...dryReport, partner_country_candidates: [countryCandidate] })
      .mockResolvedValueOnce({ ...dryReport, ok: false })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await reviewCountries(wrapper)
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    await rerunFromCountries(wrapper)
    await reviewCountries(wrapper)
    await wrapper.get('[data-testid="stereo-country-undo-0"]').trigger('click')
    expect(wrapper.getComponent(StereoNxPartnerCountries).props('modelValue')).toEqual({})
    expect(wrapper.find('[data-testid="stereo-protocol-pdf"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('downloads the report belonging to each dry-run, confirmation and import-result view', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="stereo-protocol-pdf"]').exists()).toBe(false)
    await reachDryRun(wrapper)
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    await flushPromises()
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 1, 'cs')
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    await flushPromises()
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 1, 'cs')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 2, 'cs')
    wrapper.unmount()
  })

  it.each(['dry_run', 'import'])('offers the PDF for an unsuccessful finished %s report', async mode => {
    m.run.mockImplementation(async (_token: string, _company: number, current: string) => ({ ...(current === 'dry_run' ? dryReport : importReport), ok: current !== mode }))
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    if (mode === 'import') {
      await primaryButton(wrapper, 'continue').trigger('click')
      await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
      await primaryButton(wrapper, 'import').trigger('click')
      await flushPromises()
    }
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, mode === 'dry_run' ? 1 : 2, 'cs')
    wrapper.unmount()
  })

  it('preserves the original PDF reference after automatic ZIP cleanup and resumes it without the ZIP', async () => {
    m.remove.mockImplementation(async () => { m.uploads.mockResolvedValue([]) })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await wrapper.get('[data-testid="stereo-delete-after-import"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.remove).toHaveBeenCalledOnce()
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 2, 'cs')
    wrapper.unmount()
    m.preview.mockClear()
    const resumed = await mountPage()
    expect(m.preview).not.toHaveBeenCalled()
    expect(m.remove).toHaveBeenCalledOnce()
    await resumed.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 2, 'cs')
    resumed.unmount()
  })


  it('keeps the completed import PDF through resetSelection when the ZIP is removed manually', async () => {
    m.remove.mockImplementation(async () => { m.uploads.mockResolvedValue([]) })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="stereo-step-1"]').trigger('click')
    await wrapper.get(`[data-testid="stereo-upload-remove-${upload.token}"]`).trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 2, 'cs')
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    wrapper.unmount()
    const resumed = await mountPage()
    await resumed.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 2, 'cs')
    resumed.unmount()
  })

  it('requests the PDF in the active UI language', async () => {
    m.locale.value = 'en'
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenCalledWith(upload.token, 1, 'en')
    wrapper.unmount()
  })

  it('restores the completed dry-run PDF reference when resuming the existing upload', async () => {
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    wrapper.unmount()
    const resumed = await mountPage()
    await resumed.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenCalledWith(upload.token, 1, 'cs')
    resumed.unmount()
  })

  it('shows PDF loading and a translated error, then prevents stale errors or exports after a supplier change', async () => {
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    let fail!: (reason: unknown) => void
    m.downloadProtocol.mockImplementationOnce(() => new Promise((_resolve, reject) => { fail = reject }))
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(wrapper.get('[data-testid="stereo-protocol-pdf"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="stereo-protocol-pdf"]').text()).toContain('stereo_nx.pdf_downloading')
    fail(new Error('synthetic failure'))
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('stereo_nx.pdf_download_failed')
    expect(wrapper.text()).toContain('stereo_nx.pdf_download_failed')
    m.toastError.mockClear()
    m.downloadProtocol.mockImplementationOnce(() => new Promise((_resolve, reject) => { fail = reject }))
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    m.supplierStore.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-protocol-pdf"]').exists()).toBe(false)
    fail(new Error('synthetic stale failure'))
    await flushPromises()
    expect(m.toastError).not.toHaveBeenCalled()
    expect(m.downloadProtocol).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('clears the export reference when choosing another upload and binds the next report to that upload', async () => {
    const other = { ...upload, token: 'other-synthetic-token', filename: 'other-synthetic.zip' }
    m.uploads.mockResolvedValue([upload, other])
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await wrapper.get('[data-testid="stereo-step-1"]').trigger('click')
    await wrapper.findAll('button').filter(button => button.text().includes('stereo_nx.open_upload'))[1]!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-protocol-pdf"]').exists()).toBe(false)
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.find('[data-testid="stereo-protocol-pdf"]').exists()).toBe(false)
    await flushPromises()
    await primaryButton(wrapper, 'continue').trigger('click')
    await primaryButton(wrapper, 'dry_run').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenCalledWith(other.token, 2, 'cs')
    wrapper.unmount()
  })

  it.each([['cs', '23. 12. 2025 až 31. 08. 2026'], ['en', '12/23/2025 až 08/31/2026']])('formats report date bounds using %s locale in all wizard report views', async (locale, expected) => {
    m.locale.value = locale
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => ({ ...(mode === 'dry_run' ? dryReport : importReport), date_bounds: { from: '2025-12-23', to: '2026-08-31' } }))
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.text()).toContain(expected)
    expect(wrapper.text()).not.toContain('2025-12-23')
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.text()).toContain(expected)
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain(expected)
    wrapper.unmount()
  })

  it('renders source, ready and skipped agenda counts in columns and preserves every other metric once per stage', async () => {
    const counts = { assets_source: 1, assets_ready: 0, assets_skipped: 1, requires_draft: 3, requires_movement_review: 2, custom_metric: 7 }
    m.run.mockResolvedValue({ ...dryReport, counts, written: { assets_created: 0, custom_metric: 9 } })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    const summary = wrapper.get('[data-testid="stereo-counts-summary"]')
    await summary.get('[data-testid="stereo-counts-show-all"]').setValue(true)
    const asset = summary.get('[data-agenda="assets"]').findAll('td').map(cell => cell.text())
    expect(asset).toEqual(['1', '0', '1', '—'])
    expect(summary.get('[data-count-key="assets_skipped"]').classes()).toContain('text-warning-700')
    expect(summary.findAll('[data-count-key]')).toHaveLength(Object.keys(counts).length + 2)
    expect(summary.get('[data-agenda="documents_review"]').findAll('td').map(cell => cell.text())).toEqual(['—', '—', '—', '3'])
    expect(summary.get('[data-agenda="movements_review"]').findAll('td').map(cell => cell.text())).toEqual(['—', '—', '—', '2'])
    expect(summary.findAll('[data-count-key="requires_draft"]')).toHaveLength(1)
    expect(summary.findAll('[data-count-key="requires_movement_review"]')).toHaveLength(1)
    const details = summary.get('[data-testid="stereo-counts-details"]')
    expect(details.findAll('tbody tr').map(row => row.findAll('td').map(cell => cell.text()))).toEqual([
      ['stereo_nx.steps.source_summary', 'stereo_nx.counts.custom_metric', '7'],
      ['stereo_nx.steps.would_write', 'stereo_nx.counts.assets_created', '0'],
      ['stereo_nx.steps.would_write', 'stereo_nx.counts.custom_metric', '9'],
    ])
    expect(wrapper.getComponent(StereoNxMessages).props('messages')).toContainEqual(expect.objectContaining({
      code: 'vat_participation_unassigned', context: { summary: true, count: 1 },
    }))
    wrapper.unmount()
  })

  it('requires bank-series approval, sends it and restores it after returning', async () => {
    const bank = { requires_confirmation: true, ambiguous: false, assignments: [{ account_index: 0, account_label: 'Synthetic bank', series: 'SYN' }], missing_accounts: 1, unmatched_series: ['SYN'] }
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, bank_account_series: bank }] })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="stereo-bank-series"]').text()).toContain('Synthetic bank → SYN')
    const checkbox = wrapper.get('[data-testid="stereo-bank-series-confirm"]')
    expect((checkbox.element as HTMLInputElement).checked).toBe(false)
    await checkbox.setValue(true)
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
    await primaryButton(wrapper, 'continue').trigger('click')
    await primaryButton(wrapper, 'dry_run').trigger('click')
    await flushPromises()
    expect(m.jobs.get(1)?.[4]).toBe(true)
    expect(JSON.parse(sessionStorage.getItem('myucto.stereo-nx.job')!).matchBankAccountSeries).toBe(true)
    wrapper.unmount()
    const resumed = await mountPage()
    await resumed.get('[data-testid="stereo-step-2"]').trigger('click')
    expect((resumed.get('[data-testid="stereo-bank-series-confirm"]').element as HTMLInputElement).checked).toBe(true)
    await resumed.get('[data-testid="stereo-bank-series-confirm"]').setValue(false)
    expect(resumed.get('[data-testid="stereo-step-4"]').attributes('disabled')).toBeDefined()
    expect(primaryButton(resumed, 'continue').attributes('disabled')).toBeDefined()
    resumed.unmount()
  })

  it('blocks ambiguous bank series and requires correction in Stereo', async () => {
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, bank_account_series: { requires_confirmation: false, ambiguous: true, assignments: [], missing_accounts: 0, unmatched_series: ['SYN'] } }] })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(wrapper.get('[data-testid="stereo-bank-series"]').text()).toContain('stereo_nx.bank_series_ambiguous')
    expect(wrapper.get('[data-testid="stereo-bank-series"]').text()).not.toContain('stereo_nx.bank_series_missing')
    expect(wrapper.find('[data-testid="stereo-bank-series-confirm"]').exists()).toBe(false)
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('resets bank-series approval on company changes and repeated preview', async () => {
    const bank = { requires_confirmation: true, ambiguous: false, assignments: [{ account_index: 0, account_label: 'Synthetic bank', series: 'SYN' }], missing_accounts: 1, unmatched_series: ['SYN'] }
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, bank_account_series: bank }, { ...company, index: 1, bank_account_series: bank }] })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    await wrapper.get('[data-testid="stereo-bank-series-confirm"]').setValue(true)
    await wrapper.get('[data-testid="stereo-company-select"]').setValue('1')
    expect((wrapper.get('[data-testid="stereo-bank-series-confirm"]').element as HTMLInputElement).checked).toBe(false)
    await wrapper.get('[data-testid="stereo-bank-series-confirm"]').setValue(true)
    await primaryButton(wrapper, 'read_again').trigger('click')
    await flushPromises()
    expect((wrapper.get('[data-testid="stereo-bank-series-confirm"]').element as HTMLInputElement).checked).toBe(false)
    wrapper.unmount()
  })

  it('after returning to the page resumes the dry run and allows the transfer', async () => {
    const first = await mountPage()
    await reachDryRun(first)
    expect(m.run).toHaveBeenCalledTimes(1)
    first.unmount()

    const wrapper = await mountPage()
    expect(m.run).toHaveBeenCalledTimes(2)
    expect(m.run).toHaveBeenLastCalledWith(upload.token, 0, 'dry_run', false, expect.any(Function))
    expect(m.jobs.size).toBe(1)
    expect(primaryButton(wrapper, 'dry_run_again')).toBeTruthy()
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeUndefined()
  })

  it('links historical wages only after a committed transfer', async () => {
    const written = { historical_payroll_created: 2 }
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run'
      ? { ...dryReport, written } : { ...importReport, written })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.find('[data-testid="stereo-payroll-link"]').exists()).toBe(false)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="stereo-payroll-link"]').attributes('href')).toBe('/payroll/imports?tab=takeover')
    wrapper.unmount()
  })

  it('links committed review records and never links rolled-back dry-run IDs', async () => {
    const reviews = {
      review_documents: [{ kind: 'purchase', source_key: 'synthetic', document_no: 'TEST', review_codes: ['vat_participation_unassigned'], target_id: 123 }],
      review_movements: [{ kind: 'bank', source_key: 'bank', review_codes: ['pending_reconciliation'], target_id: 456, statement_id: 789 },
        { kind: 'cash', source_key: 'cash', review_codes: ['pending_reconciliation'], target_id: 321 }],
    }
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run'
      ? { ...dryReport, ...reviews } : { ...importReport, ...reviews })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.find('[data-testid="stereo-review-links"]').exists()).toBe(false)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    const hrefs = wrapper.get('[data-testid="stereo-review-links"]').findAll('a').map(a => a.attributes('href'))
    expect(hrefs).toEqual(['/purchase-invoices/123', '/bank/789?tx=456', '/accounting/cash/321/edit'])
    wrapper.unmount()
  })

  it('adds a used-account mapping step for double entry and sends identical confirmed mappings to dry run and import', async () => {
    m.preview.mockResolvedValue(doubleEntryPreview)
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.find('[data-testid="stereo-step-5"]').exists()).toBe(true)
    expect(m.accountMapping).toHaveBeenCalledWith(upload.token, 0, false)
    expect(m.jobs.get(1)?.[5]).toEqual({ accounts: mappingProposal.accounts, bank_accounts: mappingProposal.bank_accounts, vat: mappingProposal.vat })
    expect(m.run).toHaveBeenCalledWith(upload.token, 0, 'dry_run', false, expect.any(Function))
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.jobs.get(2)?.[5]).toEqual(m.jobs.get(1)?.[5])
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('invalidates a successful dry run after account edits and resumes the edited mapping for the next run', async () => {
    m.preview.mockResolvedValue(doubleEntryPreview)
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await wrapper.get('[data-testid="stereo-step-3"]').trigger('click')
    await wrapper.get('[data-testid="stereo-account-501001"]').setValue('501002')
    expect(wrapper.get('[data-testid="stereo-step-6"]').attributes('disabled')).toBeDefined()
    expect(sessionStorage.getItem('myucto.stereo-nx.job')).toBeNull()
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
    await primaryButton(wrapper, 'continue').trigger('click')
    await primaryButton(wrapper, 'dry_run').trigger('click')
    await flushPromises()
    expect(m.jobs.get(2)?.[5]?.accounts['501001']).toBe('501.002')
    wrapper.unmount()
    const resumed = await mountPage()
    expect(resumed.find('[data-testid="stereo-step-5"]').exists()).toBe(true)
    expect(resumed.find('[data-testid="stereo-dry-report"]').exists()).toBe(true)
    await resumed.get('[data-testid="stereo-step-3"]').trigger('click')
    await flushPromises()
    expect((resumed.get('[data-testid="stereo-account-501001"]').element as HTMLInputElement).value).toBe('501.002')
    resumed.unmount()
  })

  it('blocks continuing past mapping until unresolved VAT has an explicit target', async () => {
    m.preview.mockResolvedValue(doubleEntryPreview)
    m.accountMapping.mockResolvedValue({ ...mappingProposal, vat_counts: { ...mappingProposal.vat_counts, unresolved: 1 } })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="stereo-step-4"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="stereo-vat-unresolved"]').setValue('343.999')
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeUndefined()
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
    await primaryButton(wrapper, 'continue').trigger('click')
    await primaryButton(wrapper, 'dry_run').trigger('click')
    await flushPromises()
    expect(m.jobs.get(1)?.[5]?.vat?.unresolved).toBe('343.999')
    wrapper.unmount()
  })

  it.each(['automatic', 'manual'])('keeps double-entry results on step five after %s ZIP cleanup and resume', async cleanup => {
    m.preview.mockResolvedValue(doubleEntryPreview)
    m.remove.mockImplementation(async () => { m.uploads.mockResolvedValue([]) })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    if (cleanup === 'automatic') await wrapper.get('[data-testid="stereo-delete-after-import"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    if (cleanup === 'manual') {
      await wrapper.get('[data-testid="stereo-step-1"]').trigger('click')
      await wrapper.get(`[data-testid="stereo-upload-remove-${upload.token}"]`).trigger('click')
      await flushPromises()
    }
    expect(wrapper.find('[data-testid="stereo-step-5"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    await wrapper.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 2, 'cs')
    wrapper.unmount()
    const resumed = await mountPage()
    expect(resumed.find('[data-testid="stereo-step-5"]').exists()).toBe(true)
    expect(resumed.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    await resumed.get('[data-testid="stereo-protocol-pdf"]').trigger('click')
    expect(m.downloadProtocol).toHaveBeenLastCalledWith(upload.token, 2, 'cs')
    resumed.unmount()
  })

  it.each([
    ['tax_evidence', 'double_entry'],
    ['double_entry', 'tax_evidence'],
  ])('blocks mismatched source %s and target %s and links to accounting settings', async (source, target) => {
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, identity: { ...company.identity, accounting_mode: source } }], target: { ...preview.target, accounting_mode: target } })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(wrapper.get('[data-testid="stereo-mode-mismatch"]').text()).toContain('stereo_nx.mode_mismatch')
    expect(wrapper.get('[data-testid="stereo-mode-mismatch"]').text()).toContain('stereo_nx.open_accounting_settings')
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeDefined()
    expect(m.run).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('explains an unknown source mode while allowing validation to continue', async () => {
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, identity: { ...company.identity, accounting_mode: null } }] })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(wrapper.text()).toContain('stereo_nx.source_mode_unknown')
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('selects empty fields by default and allows an individual overwrite with expected values', async () => {
    const proposal = { ...company, profile_suggestions: { city: 'Vzorov', street: 'Testovací 1' }, profile_current: { city: '', street: 'Původní 2' } }
    m.preview.mockResolvedValueOnce({ ...preview, companies: [proposal] }).mockResolvedValueOnce(preview)
    m.fillCompanyProfile.mockResolvedValue({ filled_fields: ['city', 'street'] })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(wrapper.get('[data-testid="stereo-profile-suggestions"]').text()).toContain('Vzorov')
    expect(wrapper.get('[data-testid="stereo-profile-suggestions"]').text()).toContain('Testovací 1')
    const checkboxes = wrapper.get('[data-testid="stereo-profile-suggestions"]').findAll('input[type="checkbox"]')
    expect((checkboxes[0].element as HTMLInputElement).checked).toBe(false)
    expect((checkboxes[1].element as HTMLInputElement).checked).toBe(true)
    await checkboxes[0].setValue(true)
    await wrapper.findAll('button').find(button => button.text().includes('stereo_nx.fill_company_profile'))!.trigger('click')
    await flushPromises()
    expect(m.fillCompanyProfile).toHaveBeenCalledWith(upload.token, 0, ['street', 'city'], { street: 'Původní 2', city: '' })
    expect(m.preview).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="stereo-profile-suggestions"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="stereo-profile-filled"]').text()).toContain('stereo_nx.profile_filled')
    wrapper.unmount()
  })

  it('keeps specific document review details under their cause without counting a duplicate in summary', async () => {
    m.run.mockResolvedValue({ ...dryReport,
      warnings: [{ level: 'warning', code: 'issued_lines_aggregated', document_no: 'TEST', message: 'Specific draft warning' }],
      review_documents: [{ kind: 'issued', source_key: 'synthetic-issued', document_no: 'TEST', review_codes: ['issued_lines_aggregated'], target_id: null }],
    })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    const run = wrapper.getComponent(MoneyS3Protocol).props('run')
    expect(run.protocol?.steps.find(step => step.key === 'source_summary')?.messages).toEqual([])
    const details = wrapper.getComponent(StereoNxMessages).props('messages').filter(item => item.code === 'issued_lines_aggregated')
    expect(details).toEqual([expect.objectContaining({ level: 'warning', text: 'Specific draft warning',
      context: { document_no: 'TEST', kind: 'issued', source_key: 'synthetic-issued' } })])
    wrapper.unmount()
  })

  it('preserves warning cause codes and separates document identities with the same displayed number', async () => {
    m.run.mockResolvedValue({ ...dryReport, review_reasons: {},
      warnings: [
        { level: 'warning', code: 'document_rounding_account_review', document_no: 'SYN-1', source_key: 'source-A', message: 'Rounding A' },
        { level: 'warning', code: 'document_rounding_account_review', document_no: 'SYN-1', source_key: 'source-B', message: 'Rounding B' },
        { level: 'info', code: 'document_items_reconstructed', document_no: 'SYN-2', message: 'Reconstructed items' },
      ],
    })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    const messages = wrapper.getComponent(StereoNxMessages).props('messages')
    expect(messages).toEqual([
      expect.objectContaining({ level: 'warning', code: 'document_rounding_account_review', context: { document_no: 'SYN-1', source_key: 'source-A' } }),
      expect.objectContaining({ level: 'warning', code: 'document_rounding_account_review', context: { document_no: 'SYN-1', source_key: 'source-B' } }),
      expect.objectContaining({ level: 'info', code: 'document_items_reconstructed' }),
    ])
    expect(wrapper.text()).not.toContain('source-A')
    wrapper.unmount()
  })

  it('preserves aggregate warning occurrence counts without hiding their full text', async () => {
    m.run.mockResolvedValue({ ...dryReport, review_reasons: {}, warnings: [
      { level: 'warning', code: 'bank_currency_unresolved', message: 'Seven synthetic movements need review', count: 7 },
    ] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.getComponent(StereoNxMessages).props('messages')).toEqual([
      expect.objectContaining({ code: 'bank_currency_unresolved', text: 'Seven synthetic movements need review', context: { count: 7 } }),
    ])
    wrapper.unmount()
  })

  it('groups repeated legacy warnings without silently dropping their occurrences', async () => {
    m.run.mockResolvedValue({ ...dryReport, review_reasons: {}, warnings: [
      'Repeated synthetic warning', 'Repeated synthetic warning', 'Different synthetic warning',
    ] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    const messages = wrapper.getComponent(StereoNxMessages).props('messages')
    expect(messages.filter(item => item.text === 'Repeated synthetic warning')).toHaveLength(2)
    expect(wrapper.get('details[data-message-code="text:Repeated synthetic warning"] [data-testid="stereo-message-group-count"]').text()).toBe('2')
    expect(wrapper.findAll('details[data-message-level="warning"]')).toHaveLength(2)
    wrapper.unmount()
  })

  it('keeps ambiguous warning identities separate and emits one review detail per cause', async () => {
    m.run.mockResolvedValue({ ...dryReport, review_reasons: { issued_lines_missing: 2 },
      warnings: [{ level: 'warning', code: 'issued_lines_missing', document_no: 'SYN-1', message: 'Unattributed detail' }],
      review_documents: [
        { kind: 'issued', source_key: 'source-A', document_no: 'SYN-1', review_codes: ['issued_lines_missing', 'partner_country_unresolved'], target_id: null },
        { kind: 'issued', source_key: 'source-B', document_no: 'SYN-1', review_codes: ['issued_lines_missing'], target_id: null },
      ],
    })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    const messages = wrapper.getComponent(StereoNxMessages).props('messages')
    const missing = messages.filter(item => item.code === 'issued_lines_missing')
    expect(missing).toHaveLength(4)
    expect(missing.find(item => item.context.summary)).toEqual(expect.objectContaining({ context: { summary: true, count: 2 } }))
    expect(missing.find(item => item.text === 'Unattributed detail')).toBeDefined()
    expect(messages.filter(item => item.code === 'partner_country_unresolved')).toHaveLength(1)
    wrapper.unmount()
  })

  it.each(['dry_run', 'import'] as const)('keeps reconstructed document information informational in the %s protocol', async mode => {
    m.run.mockImplementation(async (_token: string, _company: number, runMode: string) => ({ ...dryReport,
      counts: { issued: 1 }, review_reasons: {}, database_writes: runMode === 'import',
      warnings: [{ level: 'info', code: 'document_items_reconstructed', message: 'Synthetic reconstructed document details' }],
    }))
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    if (mode === 'import') {
      await primaryButton(wrapper, 'continue').trigger('click')
      await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
      await primaryButton(wrapper, 'import').trigger('click')
      await flushPromises()
    }
    const run = wrapper.getComponent(MoneyS3Protocol).props('run')
    expect(run.protocol?.steps.find(step => step.key === 'source_summary')?.messages).toEqual([
      expect.objectContaining({ level: 'info', text: 'Synthetic reconstructed document details' }),
    ])
    expect(run.status).toBe('completed')
    expect(run.protocol?.status).toBe('completed')
    wrapper.unmount()
  })

  it('shows shared reconciliation checks and differences in the transfer protocol', async () => {
    m.run.mockResolvedValue({ ...dryReport, ok: false, reconciliation: [{
      year: 2030, period_id: 1, ok: false,
      checks: [{ key: 'stereo_nx_journal', ok: false }],
      journal_diffs: [{ account: '518', myucto: [0, 120, 120], money: [0, 100, 100] }],
      money_report: null, documents: [],
    }] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    const report = wrapper.get('[data-testid="stereo-dry-report"]')
    expect(report.text()).toContain('stereo_nx.checks.stereo_nx_journal')
    expect(report.text()).toContain('518')
    wrapper.unmount()
  })

  it('keeps the ZIP when accounting reports agendas requiring further transfer', async () => {
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run'
      ? { ...dryReport, partial: true } : { ...importReport, partial: true })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.get('[data-testid="stereo-delete-after-import"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('stereo_nx.partial_backup_kept')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.remove).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('opens an existing ZIP and gates import behind company selection, dry run and confirmation', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="stereo-upload-input"]').exists()).toBe(true)
    await openExisting(wrapper)
    expect(m.preview).toHaveBeenCalledWith(upload.token)
    expect(wrapper.find('[data-testid="stereo-company-select"]').exists()).toBe(true)
    await primaryButton(wrapper, 'continue').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-step"]').exists()).toBe(true)
    expect(m.run).not.toHaveBeenCalled()
    expect(m.partnerCountries).toHaveBeenCalledWith(upload.token, 0, false, false, {})
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.find('[data-testid="stereo-dry-report"]').exists()).toBe(true)
    await primaryButton(wrapper, 'dry_run').trigger('click')
    await flushPromises()
    expect(m.run).toHaveBeenCalledWith(upload.token, 0, 'dry_run', false, expect.any(Function))
    expect(wrapper.find('[data-testid="stereo-dry-report"]').exists()).toBe(true)
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    expect(primaryButton(wrapper, 'import').attributes('disabled')).toBeDefined()
    expect(m.run).toHaveBeenCalledTimes(1)
    const confirm = wrapper.get('input[type="checkbox"][data-testid="stereo-import-confirm"]')
    await confirm.setValue(true)
    expect(primaryButton(wrapper, 'import').attributes('disabled')).toBeUndefined()
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.run).toHaveBeenLastCalledWith(upload.token, 0, 'import', false, expect.any(Function))
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('stereo_nx.protocol.title')
    expect(wrapper.text()).not.toContain('#null')
    wrapper.unmount()
  })

  it('keeps an unsuccessful dry run from enabling the import', async () => {
    m.run.mockResolvedValueOnce({ ...dryReport, ok: false,
      errors: [{ level: 'error', code: 'period_closed', message: 'Synthetic closed period' }] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).toContain('Synthetic closed period')
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(item => item.text().includes('stereo_nx.import') && !item.attributes('disabled'))).toBe(false)
    expect(m.run).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('deletes the source only after a successful import and preserves its protocol', async () => {
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await wrapper.get('[data-testid="stereo-delete-after-import"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.remove).toHaveBeenCalledOnce()
    expect(m.remove).toHaveBeenCalledWith(upload.token)
    expect(wrapper.get('[data-testid="stereo-import-report"]').text()).toContain('stereo_nx.protocol.title')
    expect(wrapper.find('[data-testid="stereo-step-4"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows the background job progress while the dry run is running', async () => {
    let finish!: (report: typeof dryReport) => void
    m.run.mockImplementationOnce((_token: string, _company: number, _mode: string, _blank: boolean, onJob: (job: object) => void) => {
      onJob({ id: 5, status: 'running', total_items: 1, processed: 0, created_count: 0, skipped_count: 0, failed_count: 0, current_step: 'Synthetic dry run step' })
      return new Promise(resolve => { finish = resolve })
    })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).toContain('Synthetic dry run step')
    finish(dryReport)
    await flushPromises()
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).not.toContain('Synthetic dry run step')
    wrapper.unmount()
  })

  it('retains a failed import and its ZIP even when deletion was selected', async () => {
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run' ? dryReport
      : { ...importReport, ok: false, database_writes: false,
        errors: [{ level: 'error', code: 'source_changed', message: 'Synthetic changed source' }] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await wrapper.get('[data-testid="stereo-delete-after-import"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.remove).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="stereo-import-report"]').text()).toContain('Synthetic changed source')
    wrapper.unmount()
  })
})
