import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import CertificateVaultUsage from '@/components/settings/CertificateVaultUsage.vue'
import cs from '@/i18n/cs.json'

const getCertificateUsage = vi.hoisted(() => vi.fn())

vi.mock('@/api/settings', () => ({ settingsApi: { getCertificateUsage } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_error: unknown, fallback: string) => fallback }))

function mountUsage() {
  return mount(CertificateVaultUsage, {
    props: { certificateId: 21 },
    global: {
      plugins: [createI18n({ legacy: false, locale: 'cs', messages: {
        cs: { common: { loading: cs.common.loading }, settings: {
          certificate_vault_company_usage: cs.settings.certificate_vault_company_usage,
        } },
      } })],
    },
  })
}

describe('CertificateVaultUsage', () => {
  beforeEach(() => getCertificateUsage.mockReset())

  it('loads only on disclosure and renders the EPO, payroll and ISDS states', async () => {
    getCertificateUsage.mockResolvedValueOnce([{
      supplier_id: 17,
      name: 'Syntetická firma',
      epo: 'eligible',
      payroll: [{ environment: 'test', registration: 'mismatch' }],
      databox: [{
        environment: 'production', box_id: 'abc1234', source: 'shared', operations: ['send', 'receipts'],
      }],
    }])

    const wrapper = mountUsage()
    const toggle = wrapper.get('button')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(getCertificateUsage).not.toHaveBeenCalled()

    await toggle.trigger('click')
    await flushPromises()
    expect(getCertificateUsage).toHaveBeenCalledExactlyOnceWith(21)
    expect(toggle.attributes('aria-expanded')).toBe('true')
    const [company, epo, payroll, databox] = wrapper.get('tbody tr').findAll('th, td').map(cell => cell.text())
    expect(company).toBe('Syntetická firma')
    expect(epo).toBe('Povolený k výběru')
    expect(payroll).toContain('Test · Vybraný')
    expect(payroll).toContain('Sériové číslo nesouhlasí')
    expect(databox).toContain('abc1234')
    expect(databox).toContain('Sdílený přístup')
    expect(databox).toContain('Odesílání, Doručenky')
    expect(databox).not.toContain('Příjem')
  })

  it('shows a failed request and refreshes the overview after closing and reopening', async () => {
    getCertificateUsage.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce([])
    const wrapper = mountUsage()
    const toggle = wrapper.get('button')

    await toggle.trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Použití certifikátu se nepodařilo načíst.')

    await toggle.trigger('click')
    await toggle.trigger('click')
    await flushPromises()
    expect(getCertificateUsage).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Nemáte žádnou firmu')
  })
})
