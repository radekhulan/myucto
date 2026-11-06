import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import StereoNxPartnerCountries from '../StereoNxPartnerCountries.vue'
import CountrySelect from '@/components/ui/CountrySelect.vue'
import type { StereoCountryCandidate } from '@/api/stereoNx'

const m = vi.hoisted(() => ({ lookup: vi.fn(), vies: vi.fn(), create: vi.fn(), update: vi.fn() }))
vi.mock('@/api/clients', () => ({ clientsApi: { lookupAres: m.lookup, lookupVies: m.vies, create: m.create, update: m.update } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, values?: Record<string, unknown>) => values ? `${key}: ${Object.entries(values).map(([name, value]) => `${name}=${value}`).join(', ')}` : key }) }))
vi.mock('@/components/ui/CountrySelect.vue', () => ({ default: {
  props: ['modelValue', 'disabled'], emits: ['update:modelValue'],
  template: '<select :value="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.value)"><option value=""></option><option value="IE">Synthetic Ireland</option><option value="DE">Synthetic Germany</option><option value="EU">Invalid aggregate</option></select>',
} }))

const partner: StereoCountryCandidate = {
  source_key: 'synthetic-source-a', name: 'Synthetic partner', ico: '00000000', dic: 'CZ00000000',
  source_country: '', country_code: '', confirmed_country: null,
  documents: [
    { source_key: 'synthetic-issued-a', document_no: 'SYN-I-1', kind: 'issued' },
    { source_key: 'synthetic-purchase-a', document_no: 'SYN-P-1', kind: 'purchase' },
  ],
}
const found = { found: true, source: 'fresh', data: {
  company_name: 'Synthetic ARES name', ic: '00000000', dic: 'CZ00000000',
  street: 'Synthetic street 1', city: 'Synthetic city', zip: '00000', country_iso2: 'CZ', is_vat_payer: true,
} }
const foreignPartner = { ...partner, source_country: 'EMU', country_code: 'CZ', dic: ' de 000-000-000 ' }
const viesFound = { valid: true, source: 'rest', vat_number: 'DE000000000', country: 'DE', name: 'Synthetic VIES name', address: 'Synthetic VIES street 1\nSynthetic foreign city' }
function component(partners = [partner], modelValue: Record<string, string> = {}) {
  return mount(StereoNxPartnerCountries, { props: { partners, modelValue } })
}
const mounted: ReturnType<typeof component>[] = []
function track(partners = [partner], modelValue: Record<string, string> = {}) {
  const wrapper = component(partners, modelValue)
  mounted.push(wrapper)
  return wrapper
}
async function lookup(wrapper: ReturnType<typeof component>, index = 0) {
  await wrapper.get(`[data-testid="stereo-country-lookup-${index}"]`).trigger('click')
  await flushPromises()
}
beforeEach(() => {
  vi.resetAllMocks()
  m.lookup.mockResolvedValue(found)
  m.vies.mockResolvedValue(viesFound)
})
afterEach(() => { for (const wrapper of mounted.splice(0)) wrapper.unmount() })

describe('StereoNxPartnerCountries', () => {
  it('does not query automatically or emit a country until the user explicitly confirms the ARES suggestion', async () => {
    const wrapper = track()
    expect(m.lookup).not.toHaveBeenCalled()
    expect(m.vies).not.toHaveBeenCalled()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await lookup(wrapper)
    expect(m.lookup).toHaveBeenCalledWith('00000000')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    const proposal = wrapper.get('[data-testid="stereo-country-proposal-0"]')
    expect(proposal.text()).toContain('Synthetic ARES name')
    expect(proposal.text()).toContain('Synthetic street 1')
    expect(proposal.text()).toContain('Synthetic city')
    expect(proposal.text()).toContain('stereo_nx.partner_countries.scope: count=2, country=CZ')
    expect(proposal.text()).toContain('stereo_nx.partner_countries.history_hint')
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([[{ [partner.source_key]: 'CZ' }]])
    expect(m.create).not.toHaveBeenCalled()
    expect(m.update).not.toHaveBeenCalled()
  })

  it('keeps different source partners with the same ICO independent and preserves every other map entry', async () => {
    const second = { ...partner, source_key: 'synthetic-source-b', name: 'Other source card', documents: [{ source_key: 'synthetic-issued-b', document_no: 'SYN-I-2', kind: 'issued' as const }] }
    const wrapper = track([partner, second], { unrelated: 'SK', [second.source_key]: 'DE' })
    expect(wrapper.findAll('article')).toHaveLength(2)
    await lookup(wrapper)
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ unrelated: 'SK', [second.source_key]: 'DE', [partner.source_key]: 'CZ' }])
    expect(m.lookup).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="stereo-country-proposal-1"]').exists()).toBe(false)
  })

  it('uses the current model as authority, can undo only that source key, and stays visible after a change', async () => {
    const wrapper = track([{ ...partner, confirmed_country: 'CZ' }])
    expect(wrapper.find('[data-testid="stereo-country-confirmed-0"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('stereo_nx.partner_countries.previous_confirmation')
    await wrapper.setProps({ modelValue: { unrelated: 'DE', [partner.source_key]: 'SK' }, changed: true })
    expect(wrapper.get('[data-testid="stereo-country-confirmed-0"]').text()).toContain('country=SK')
    await wrapper.get('[data-testid="stereo-country-undo-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ unrelated: 'DE' }])
    expect(wrapper.find('[data-testid="stereo-partner-countries"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-country-changed"]').exists()).toBe(true)
  })

  it.each([
    [{ found: false, source: 'fresh' }, 'not_found'],
    [{ ...found, data: { ...found.data, ic: '00000001' } }, 'mismatched_ico'],
    [{ ...found, data: { ...found.data, country_iso2: 'EU' } }, 'invalid_country'],
    [{ ...found, data: { ...found.data, country_iso2: '' } }, 'invalid_country'],
    [{ ...found, data: { ...found.data, country_iso2: 'CZE' } }, 'invalid_country'],
  ])('does not confirm an unsafe ARES result: %s', async (result, error) => {
    m.lookup.mockResolvedValueOnce(result)
    const wrapper = track()
    await lookup(wrapper)
    expect(wrapper.get('[data-testid="stereo-country-error-0"]').text()).toBe(`stereo_nx.partner_countries.errors.${error}`)
    expect(wrapper.find('[data-testid="stereo-country-proposal-0"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="stereo-country-confirm-0"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.get('[data-testid="stereo-country-lookup-0"]').attributes('disabled')).toBeUndefined()
  })

  it('shows a localized unavailable result and allows a successful retry without a default country', async () => {
    m.lookup.mockRejectedValueOnce(new Error('raw network error'))
    const wrapper = track()
    await lookup(wrapper)
    expect(wrapper.text()).toContain('stereo_nx.partner_countries.errors.unavailable')
    expect(wrapper.text()).not.toContain('raw network error')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await lookup(wrapper)
    expect(wrapper.find('[data-testid="stereo-country-proposal-0"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-country-error-0"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it.each([
    { ...partner, ico: 'foreign 00000000' }, { ...partner, ico: '0000000' },
    { ...partner, dic: 'SK0000000000' }, { ...partner, dic: ' s k 000-000-000 ' }, { ...partner, country_code: 'DE', dic: '' },
  ])('never guesses a Czech registration from foreign or malformed identification', async source => {
    const wrapper = track([source])
    expect(wrapper.get('[data-testid="stereo-country-lookup-0"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    expect(m.lookup).not.toHaveBeenCalled()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('ignores a late response after a partner/company switch even when the new source reuses the same key', async () => {
    let resolve!: (value: typeof found) => void
    m.lookup.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const wrapper = track()
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    expect(wrapper.get('[data-testid="stereo-country-lookup-0"]').attributes('disabled')).toBeDefined()
    await wrapper.setProps({ partners: [{ ...partner, name: 'New company source', ico: '00000019' }] })
    resolve(found)
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-proposal-0"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="stereo-country-pending-0"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('ignores responses after unmount and while disabled, and never changes existing confirmations while busy', async () => {
    let resolve!: (value: typeof found) => void
    m.lookup.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const wrapper = track([partner], { [partner.source_key]: 'SK' })
    await wrapper.get('[data-testid="stereo-country-expand-0"]').trigger('click')
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    expect(wrapper.get('[data-testid="stereo-country-undo-0"]').attributes('disabled')).toBeDefined()
    await wrapper.setProps({ disabled: true })
    resolve(found)
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-proposal-0"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await wrapper.setProps({ disabled: false })
    m.lookup.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    wrapper.unmount()
    resolve(found)
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('lists the complete affected document scope without rendering source identifiers or raw HTML', async () => {
    const source = { ...partner, name: '<img src=x onerror=alert(1)>', documents: Array.from({ length: 53 }, (_, index) => ({ source_key: `private-document-${index}`, document_no: `SYN-${index}`, kind: 'issued' as const })) }
    const wrapper = track([source])
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain(source.name)
    const details = wrapper.get('[data-testid="stereo-country-documents-0"]')
    expect(details.text()).toContain('count=53')
    expect(details.findAll('li')).toHaveLength(0)
    ;(details.element as HTMLDetailsElement).open = true
    await details.trigger('toggle')
    expect(details.findAll('li')).toHaveLength(50)
    await details.get('button').trigger('click')
    expect(details.findAll('li')).toHaveLength(53)
    expect(details.text()).toContain('SYN-52')
    expect(wrapper.html()).not.toContain('private-document-')
  })

  it('allows EMU plus a foreign VAT ID through VIES despite a technical CZ fallback and confirms only after review', async () => {
    const wrapper = track([foreignPartner], { unrelated: 'SK' })
    expect(m.vies).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="stereo-country-lookup-0"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="stereo-country-vies-0"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(m.vies).toHaveBeenCalledWith('DE000000000')
    expect(m.lookup).not.toHaveBeenCalled()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    const proposal = wrapper.get('[data-testid="stereo-country-proposal-0"]')
    expect(proposal.text()).toContain('Synthetic VIES name')
    expect(proposal.text()).toContain('Synthetic VIES street 1')
    expect(proposal.text()).toContain('Synthetic foreign city')
    expect(wrapper.text()).toContain('stereo_nx.partner_countries.vies_hint')
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([[{ unrelated: 'SK', [partner.source_key]: 'DE' }]])
    expect(m.create).not.toHaveBeenCalled()
    expect(m.update).not.toHaveBeenCalled()
  })

  it.each(['EL', 'GR'])('maps a valid EL VAT registration with returned country %s to GR ISO2', async country => {
    m.vies.mockResolvedValue({ ...viesFound, vat_number: 'EL000000000', country })
    const wrapper = track([{ ...foreignPartner, dic: 'el 000-000-000' }])
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(m.vies).toHaveBeenCalledWith('EL000000000')
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ [partner.source_key]: 'GR' }])
  })

  it.each([
    [{ ...viesFound, valid: false }, 'vies_invalid'],
    [{ ...viesFound, valid: false, source: 'error' }, 'vies_unavailable'],
    [{ ...viesFound, source: 'error' }, 'vies_unavailable'],
    [{ ...viesFound, vat_number: 'DE000000001' }, 'vies_mismatched_vat'],
    [{ ...viesFound, vat_number: undefined }, 'vies_mismatched_vat'],
    [{ ...viesFound, country: 'SK' }, 'vies_country_mismatch'],
    [{ ...viesFound, country: undefined }, 'vies_country_mismatch'],
  ])('rejects an unsafe VIES result without proposing or confirming a country: %s', async (result, error) => {
    m.vies.mockResolvedValue(result)
    const wrapper = track([foreignPartner])
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="stereo-country-error-0"]').text()).toBe(`stereo_nx.partner_countries.errors.${error}`)
    expect(wrapper.find('[data-testid="stereo-country-proposal-0"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('distinguishes VIES unavailability, permits retry and compares normalized returned VAT IDs', async () => {
    m.vies.mockRejectedValueOnce(new Error('raw VIES transport failure')).mockResolvedValueOnce({ ...viesFound, vat_number: ' de 000-000-000 ' })
    const wrapper = track([foreignPartner])
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('stereo_nx.partner_countries.errors.vies_unavailable')
    expect(wrapper.text()).not.toContain('raw VIES transport failure')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-proposal-0"]').exists()).toBe(true)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('fails closed for XI instead of deriving a GB country from its special VAT prefix', async () => {
    const wrapper = track([{ ...foreignPartner, dic: 'XI000000000' }])
    expect(wrapper.get('[data-testid="stereo-country-vies-0"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('stereo_nx.partner_countries.xi_unsupported')
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    expect(m.vies).not.toHaveBeenCalled()
    expect(m.lookup).not.toHaveBeenCalled()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('ignores a late VIES result after changing partners and never performs an automatic replacement lookup', async () => {
    let resolve!: (value: typeof viesFound) => void
    m.vies.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const wrapper = track([foreignPartner])
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    expect(wrapper.get('[data-testid="stereo-country-vies-0"]').attributes('disabled')).toBeDefined()
    await wrapper.setProps({ partners: [{ ...foreignPartner, name: 'Changed source company' }] })
    resolve(viesFound)
    await flushPromises()
    expect(wrapper.find('[data-testid="stereo-country-proposal-0"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(m.vies).toHaveBeenCalledTimes(1)
  })

  it.each(['ares', 'vies'])('identifies a standalone directory card without an empty document list and still allows %s confirmation', async registry => {
    const source = { ...(registry === 'ares' ? partner : foreignPartner), documents: [] }
    const wrapper = track([source])
    expect(wrapper.get('[data-testid="stereo-country-directory-only-0"]').text()).toBe('stereo_nx.partner_countries.directory_only')
    expect(wrapper.find('[data-testid="stereo-country-documents-0"]').exists()).toBe(false)
    await wrapper.get(`[data-testid="${registry === 'ares' ? 'stereo-country-lookup-0' : 'stereo-country-vies-0'}"]`).trigger('click')
    await flushPromises()
    const country = registry === 'ares' ? 'CZ' : 'DE'
    expect(wrapper.get('[data-testid="stereo-country-proposal-0"]').text()).toContain(`stereo_nx.partner_countries.directory_scope: count=0, country=${country}`)
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ [source.source_key]: country }])
  })

  it('automatically collapses a newly confirmed source card while keeping its name, country and undo available', async () => {
    const wrapper = track()
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-country-expand-0"]').exists()).toBe(false)
    await lookup(wrapper)
    await wrapper.get('[data-testid="stereo-country-confirm-0"]').trigger('click')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as Record<string, string> })
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="stereo-country-partner-0"]').text()).toContain(partner.name)
    expect(wrapper.get('[data-testid="stereo-country-confirmed-0"]').text()).toContain('country=CZ')
    expect(wrapper.find('[data-testid="stereo-country-undo-0"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="stereo-country-expand-0"]').attributes('aria-expanded')).toBe('false')
  })

  it('starts confirmed cards collapsed, supports manual reopening and collapse, and reopens after undo or external reset', async () => {
    const wrapper = track([partner], { [partner.source_key]: 'CZ' })
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(false)
    await wrapper.get('[data-testid="stereo-country-expand-0"]').trigger('click')
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="stereo-country-expand-0"]').attributes('aria-expanded')).toBe('true')
    await wrapper.get('[data-testid="stereo-country-expand-0"]').trigger('click')
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(false)
    await wrapper.get('[data-testid="stereo-country-undo-0"]').trigger('click')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as Record<string, string> })
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-country-expand-0"]').exists()).toBe(false)
    await wrapper.setProps({ modelValue: { [partner.source_key]: 'DE' } })
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(false)
    await wrapper.get('[data-testid="stereo-country-expand-0"]').trigger('click')
    await wrapper.setProps({ modelValue: {} })
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-country-undo-0"]').exists()).toBe(false)
  })

  it('allows an explicit manual IE country after VIES does not validate a synthetic Irish VAT ID without inferring or emitting it automatically', async () => {
    m.vies.mockResolvedValue({ ...viesFound, valid: false, vat_number: 'IE0000000A', country: 'IE' })
    const source = { ...foreignPartner, dic: 'IE0000000A' }
    const wrapper = track([source], { unrelated: 'DE' })
    expect(wrapper.find('[data-testid="stereo-country-manual-select-0"]').exists()).toBe(false)
    await wrapper.get('[data-testid="stereo-country-vies-0"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('stereo_nx.partner_countries.errors.vies_invalid')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await wrapper.get('[data-testid="stereo-country-manual-toggle-0"]').trigger('click')
    const select = wrapper.get('[data-testid="stereo-country-manual-select-0"]')
    expect((select.element as HTMLSelectElement).value).toBe('')
    expect(wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').attributes('disabled')).toBeDefined()
    await select.setValue('IE')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([[{ unrelated: 'DE', [source.source_key]: 'IE' }]])
    expect(m.create).not.toHaveBeenCalled()
    expect(m.update).not.toHaveBeenCalled()
  })

  it('guards manual confirmation while disabled and clears drafts on partner reset instead of inheriting a VAT-prefix country', async () => {
    const source = { ...foreignPartner, dic: 'IE0000000A' }
    const wrapper = track([source])
    await wrapper.get('[data-testid="stereo-country-manual-toggle-0"]').trigger('click')
    await wrapper.get('[data-testid="stereo-country-manual-select-0"]').setValue('IE')
    await wrapper.setProps({ disabled: true })
    expect(wrapper.get('[data-testid="stereo-country-manual-select-0"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').attributes('disabled')).toBeDefined()
    wrapper.getComponent(CountrySelect).vm.$emit('update:modelValue', 'DE')
    await wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await wrapper.setProps({ disabled: false })
    expect((wrapper.get('[data-testid="stereo-country-manual-select-0"]').element as HTMLSelectElement).value).toBe('IE')
    await wrapper.setProps({ partners: [{ ...source, name: 'Changed source company' }] })
    expect(wrapper.find('[data-testid="stereo-country-manual-select-0"]').exists()).toBe(false)
    await wrapper.get('[data-testid="stereo-country-manual-toggle-0"]').trigger('click')
    expect((wrapper.get('[data-testid="stereo-country-manual-select-0"]').element as HTMLSelectElement).value).toBe('')
    expect(wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').attributes('disabled')).toBeDefined()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('blocks aggregate EU and pending-lookups, then uses the same collapse and undo flow for a manual confirmation', async () => {
    const wrapper = track()
    await wrapper.get('[data-testid="stereo-country-manual-toggle-0"]').trigger('click')
    await wrapper.get('[data-testid="stereo-country-manual-select-0"]').setValue('EU')
    expect(wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="stereo-country-manual-select-0"]').setValue('IE')
    let resolve!: (value: typeof found) => void
    m.lookup.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    await wrapper.get('[data-testid="stereo-country-lookup-0"]').trigger('click')
    expect(wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    resolve(found)
    await flushPromises()
    await wrapper.get('[data-testid="stereo-country-manual-confirm-0"]').trigger('click')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as Record<string, string> })
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="stereo-country-confirmed-0"]').text()).toContain('country=IE')
    await wrapper.get('[data-testid="stereo-country-undo-0"]').trigger('click')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as Record<string, string> })
    expect(wrapper.find('[data-testid="stereo-country-body-0"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-country-manual-select-0"]').exists()).toBe(false)
    await wrapper.get('[data-testid="stereo-country-manual-toggle-0"]').trigger('click')
    expect((wrapper.get('[data-testid="stereo-country-manual-select-0"]').element as HTMLSelectElement).value).toBe('')
  })
})
