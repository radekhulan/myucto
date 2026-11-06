import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StereoNxAccountMapping from '../StereoNxAccountMapping.vue'
import type { StereoAccountMapping, StereoAccountMappingProposal } from '@/api/stereoNx'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}: ${Object.values(params).join(' / ')}` : key }) }))
vi.mock('@/i18n', () => ({ i18n: { global: { locale: { value: 'cs' } } } }))

const proposal: StereoAccountMappingProposal = {
  accounts: { '221': '221', '343': '343', '501001': '501.001' },
  bank_accounts: { SYN: '221.001' },
  vat: { input: '343.100', output: '343.200', settlement: '343.900', unresolved: null },
  used_accounts: [
    { source_code: '221', name: 'Synthetic bank', target_code: '221', count: 2 },
    { source_code: '343', name: 'Synthetic VAT', target_code: '343', count: 3 },
    { source_code: '501001', name: 'Synthetic expense', target_code: '501.001', count: 1 },
  ],
  banks: [{ source_key: 'SYN', label: 'Synthetic bank 1000000005 / 0100', target_code: '221.001' }],
  unresolved: [], vat_counts: { input: 1, output: 1, settlement: 1, unresolved: 0 },
  available_accounts: [{ code: '501.001', name: 'Actual existing expense name' }, { code: '999.001', name: 'Unused chart account' }],
  suggested_accounts: [{ code: '221.001', name: 'Suggested own bank name' }, { code: '343.100', name: 'Suggested input VAT name' }],
}

function mapping(data = proposal) {
  return { accounts: { ...data.accounts }, bank_accounts: { ...data.bank_accounts }, vat: data.vat ? { ...data.vat } : null }
}
function mountMapping(data = proposal) {
  return mount(StereoNxAccountMapping, { props: { proposal: data, modelValue: mapping(data) } })
}

describe('StereoNxAccountMapping', () => {
  it('shows only journal-used accounts, dotted defaults, own bank binding and automatic VAT targets', () => {
    const wrapper = mountMapping()
    expect(wrapper.findAll('[data-source-account]').map(row => row.attributes('data-source-account'))).toEqual(['221', '343', '501001'])
    expect(wrapper.find('[data-source-account="999.001"]').exists()).toBe(false)
    expect((wrapper.get('[data-testid="stereo-account-501001"]').element as HTMLInputElement).value).toBe('501.001')
    expect((wrapper.get('[data-testid="stereo-bank-account-SYN"]').element as HTMLInputElement).value).toBe('221.001')
    expect(wrapper.get('[data-testid="stereo-bank-mapping"]').text()).toContain('1000000005 / 0100')
    expect(wrapper.find('[data-testid="stereo-account-343"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="stereo-vat-auto"]').exists()).toBe(true)
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
  })

  it('requires explicit target for unresolved VAT and revalidates edits without mutating the proposal', async () => {
    const data = { ...proposal, vat_counts: { ...proposal.vat_counts, unresolved: 1 }, unresolved: [{ source_key: 'synthetic-journal:4', side: 'D', source_code: '343', reason: 'unknown' }] }
    const wrapper = mountMapping(data)
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    expect((wrapper.get('[data-testid="stereo-vat-unresolved"]').element as HTMLInputElement).value).toBe('')
    await wrapper.get('[data-testid="stereo-vat-unresolved"]').setValue('343999')
    const update = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping
    expect(update.vat?.unresolved).toBe('343.999')
    expect(data.vat?.unresolved).toBeNull()
    await wrapper.setProps({ modelValue: update })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    await wrapper.get('[data-testid="stereo-vat-input"]').setValue('221.001')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
  })

  it('can switch VAT to manual account mapping and preserves bank edits as independent bindings', async () => {
    const wrapper = mountMapping()
    await wrapper.get('[data-testid="stereo-bank-account-SYN"]').setValue('221002')
    const bankUpdate = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping
    expect(bankUpdate.bank_accounts.SYN).toBe('221.002')
    expect(bankUpdate.accounts['221']).toBe('221')
    await wrapper.setProps({ modelValue: bankUpdate })
    await wrapper.get('[data-testid="stereo-vat-enabled"]').setValue(false)
    const manual = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping
    expect(manual.vat).toBeNull()
    await wrapper.setProps({ modelValue: manual })
    expect(wrapper.find('[data-testid="stereo-account-343"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-vat-input"]').exists()).toBe(false)
  })

  it('requires a separate numeric analytic for each own bank account', async () => {
    const data = { ...proposal, bank_accounts: { SYN: '221.001', OTHER: '221.002' }, banks: [...proposal.banks, { source_key: 'OTHER', label: 'Other synthetic bank', target_code: '221.002' }] }
    const wrapper = mountMapping(data)
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    await wrapper.setProps({ modelValue: { ...mapping(data), bank_accounts: { SYN: '221', OTHER: '221.002' } } })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.setProps({ modelValue: { ...mapping(data), bank_accounts: { SYN: '221.001', OTHER: '221.001' } } })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.setProps({ modelValue: mapping(data) })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
  })

  it('shares a bank analytic only between series aliases of the same physical account and updates every alias', async () => {
    const data = { ...proposal, bank_accounts: { SYN: '221.001', ALIAS: '221.001' }, banks: [
      { ...proposal.banks[0]!, identity_key: 'synthetic-physical-account' },
      { source_key: 'ALIAS', label: 'Synthetic bank alias', target_code: '221.001', identity_key: 'synthetic-physical-account' },
    ] }
    const wrapper = mountMapping(data)
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    await wrapper.get('[data-testid="stereo-bank-account-SYN"]').setValue('221002')
    const update = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping
    expect(update.bank_accounts).toEqual({ SYN: '221.002', ALIAS: '221.002' })
    await wrapper.setProps({ modelValue: update })
    expect((wrapper.get('[data-testid="stereo-bank-account-ALIAS"]').element as HTMLInputElement).value).toBe('221.002')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    await wrapper.setProps({ modelValue: { ...update, bank_accounts: { SYN: '221.002', ALIAS: '221.003' } } })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
  })

  it('shows existing actual names and proposed new names, updating the description after a custom code edit', async () => {
    const wrapper = mountMapping()
    expect(wrapper.get('[data-testid="stereo-account-status-501001"]').text()).toBe('stereo_nx.mapping.target_existing: Actual existing expense name')
    expect(wrapper.get('[data-testid="stereo-bank-status-SYN"]').text()).toBe('stereo_nx.mapping.target_new: Suggested own bank name')
    expect(wrapper.get('[data-testid="stereo-vat-status-input"]').text()).toBe('stereo_nx.mapping.target_new: Suggested input VAT name')
    expect(wrapper.get('[data-testid="stereo-vat-status-output"]').text()).toBe('stereo_nx.mapping.target_new: stereo_nx.mapping.vat_output')
    await wrapper.get('[data-testid="stereo-account-501001"]').setValue('501002')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping })
    expect(wrapper.get('[data-testid="stereo-account-status-501001"]').text()).toBe('stereo_nx.mapping.target_new: Synthetic expense')
    await wrapper.get('[data-testid="stereo-vat-input"]').setValue('343777')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping })
    expect(wrapper.get('[data-testid="stereo-vat-status-input"]').text()).toBe('stereo_nx.mapping.target_new: Suggested input VAT name')
  })

  it.each([
    ['501001', 'stereo-account-status-501001'],
    ['221.001', 'stereo-bank-status-SYN'],
    ['343100', 'stereo-vat-status-input'],
  ])('recognizes an inactive existing target %s after normalization and blocks continuation', (code, statusId) => {
    const data = { ...proposal, available_accounts: [{ code, name: 'Inactive actual account', is_active: false }] }
    const wrapper = mountMapping(data)
    expect(wrapper.get(`[data-testid="${statusId}"]`).text()).toBe('stereo_nx.mapping.target_inactive: Inactive actual account')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
  })

  it.each([true, false])('prefers the exact canonical account name over its legacy numeric alias (canonical first: %s)', canonicalFirst => {
    const canonical = { code: '501.001', name: 'Canonical actual name', is_active: true }
    const legacy = { code: '501001', name: 'Legacy actual name', is_active: false }
    const data = { ...proposal, available_accounts: canonicalFirst ? [canonical, legacy] : [legacy, canonical] }
    const wrapper = mountMapping(data)
    expect(wrapper.get('[data-testid="stereo-account-status-501001"]').text()).toBe('stereo_nx.mapping.target_existing: Canonical actual name')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
  })

  it('does not describe a legacy raw numeric bank account as an existing canonical analytic', async () => {
    const data = { ...proposal, bank_accounts: { SYN: '221.100' }, available_accounts: [{ code: '221100', name: 'Legacy raw bank name', is_active: true }] }
    const wrapper = mountMapping(data)
    expect(wrapper.get('[data-testid="stereo-bank-status-SYN"]').text()).toBe('stereo_nx.mapping.target_new: Synthetic bank 1000000005 / 0100')
  })

  it('explains unresolved opening VAT with date, document, description, amount and full debit/credit posting', () => {
    const data = { ...proposal, vat_counts: { ...proposal.vat_counts, unresolved: 1 }, unresolved: [{ source_key: '["ID","ps","0009","0","0"]', side: 'credit', source_code: '343', reason: 'opening_balance', date: '2025-01-01', document_no: 'SYN-OPEN-1', description: 'Synthetic prior VAT balance', amount_cents: 123456, is_opening: true, debit: '701', credit: '343', source_name: 'Synthetic VAT' }] }
    const wrapper = mountMapping(data)
    const row = wrapper.get('[data-testid="stereo-vat-unresolved-row"]')
    expect(row.text()).toContain('SYN-OPEN-1')
    expect(row.text()).toContain('01. 01. 2025')
    expect(row.text().replace(/\s/g, ' ')).toContain('1 234,56 Kč')
    expect(row.text()).toContain('Synthetic prior VAT balance')
    expect(row.text()).toContain('stereo_nx.mapping.posting: 701 / 343')
    expect(row.text()).toContain('stereo_nx.mapping.opening_balance_hint')
    expect(row.get('details code').text()).toBe(data.unresolved[0]!.source_key)
    expect(row.get('summary').text()).toBe('stereo_nx.mapping.source_reference')
  })

  it('uses the declared name of the unresolved VAT account for a custom chosen code', async () => {
    const data = { ...proposal, vat_counts: { ...proposal.vat_counts, unresolved: 1 }, vat_account_names: { unresolved: 'Actual future unresolved VAT account name' } }
    const wrapper = mountMapping(data)
    await wrapper.get('[data-testid="stereo-vat-unresolved"]').setValue('343888')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping })
    expect(wrapper.get('[data-testid="stereo-vat-status-unresolved"]').text()).toBe('stereo_nx.mapping.target_new: Actual future unresolved VAT account name')
  })

  it('requires distinct normalized target accounts for input VAT, output VAT and settlement', async () => {
    const wrapper = mountMapping()
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    await wrapper.setProps({ modelValue: { ...mapping(), vat: { ...proposal.vat!, output: '343100' } } })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.setProps({ modelValue: { ...mapping(), vat: { ...proposal.vat!, settlement: '343.200' } } })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.setProps({ modelValue: mapping() })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
  })

  it('blocks unresolved reverse charge even when the sampled rows do not include it and offers manual mapping', async () => {
    const data = { ...proposal, vat_counts: { ...proposal.vat_counts, unresolved: 102, reverse_charge_unresolved: 2 } }
    const wrapper = mountMapping(data)
    await wrapper.setProps({ modelValue: { ...mapping(data), vat: { ...data.vat!, unresolved: '343.999' } } })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    expect(wrapper.find('[data-testid="stereo-vat-reverse-charge-warning"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stereo-vat-unresolved"]').exists()).toBe(false)
    await wrapper.get('[data-testid="stereo-vat-manual"]').trigger('click')
    const manual = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as StereoAccountMapping
    expect(manual.vat).toBeNull()
    await wrapper.setProps({ modelValue: manual })
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
  })
})
