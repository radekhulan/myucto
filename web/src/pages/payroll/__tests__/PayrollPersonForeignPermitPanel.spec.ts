import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollForeignPermitView } from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  foreignPermits: vi.fn(),
  createForeignPermit: vi.fn(),
  searchDocuments: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    foreignPermits: mocks.foreignPermits,
    createForeignPermit: mocks.createForeignPermit,
  },
}))

vi.mock('@/api/documents', () => ({
  documentsApi: { search: mocks.searchDocuments },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: mocks.success, error: mocks.error }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollPersonForeignPermitPanel from '@/pages/payroll/PayrollPersonForeignPermitPanel.vue'

function permitView(): PayrollForeignPermitView {
  return {
    employee_id: 17,
    as_of: '2026-08-27',
    warning_days: 30,
    history: [{
      id: 81,
      permit_kind: 'work',
      permit_label: 'Zaměstnanecká karta',
      issuing_country_code: 'CZ',
      effective_from: '2026-01-01',
      valid_until: '2026-09-15',
      document_id: 71,
      supersedes_permit_id: null,
      recorded_at: '2026-08-27 10:00:00',
      status: 'expiring',
    }],
    alerts: [{
      permit_id: 81,
      permit_kind: 'work',
      permit_label: 'Zaměstnanecká karta',
      valid_until: '2026-09-15',
      status: 'expiring',
      days_remaining: 19,
    }],
  }
}

async function mounted(canWrite = true, canReadDocuments = true) {
  const wrapper = mount(PayrollPersonForeignPermitPanel, {
    props: { personId: 17, canWrite, canReadDocuments },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollPersonForeignPermitPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.foreignPermits.mockResolvedValue(permitView())
    mocks.createForeignPermit.mockResolvedValue(permitView())
    mocks.searchDocuments.mockResolvedValue([
      { id: 72, title: 'Povolení k práci', scope: 'company' },
      { id: 73, title: 'Soukromý doklad', scope: 'user' },
    ])
  })

  it('zobrazí historii, stav expirace i odkaz na autoritativní DMS doklad', async () => {
    const wrapper = await mounted()

    expect(wrapper.get('[data-test="foreign-permits-alert-count"]').text())
      .toContain('payroll.people.foreign_permits.alert_count')
    expect(wrapper.get('[data-test="foreign-permits-alerts"]').text())
      .toContain('Zaměstnanecká karta')
    expect(wrapper.get('[data-test="foreign-permits-history"]').text())
      .toContain('payroll.people.foreign_permits.status.expiring')
    expect(wrapper.text()).toContain('payroll.people.foreign_permits.open_document')
  })

  it('bez práva zápisu nabídne historii, ale ne formulář ani obnovu', async () => {
    const wrapper = await mounted(false)

    expect(wrapper.find('[data-test="foreign-permit-form"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll.people.foreign_permits.renew')
  })

  it('bez práva k Dokumentům nezobrazí odkaz na podklad', async () => {
    const wrapper = await mounted(false, false)

    expect(wrapper.text()).not.toContain('payroll.people.foreign_permits.open_document')
  })

  it('obnovení předvyplní den následující po konci předchozí platnosti', async () => {
    const wrapper = await mounted()
    const renew = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.foreign_permits.renew'))
    expect(renew).toBeDefined()
    await renew!.trigger('click')

    expect((wrapper.get('[data-test="foreign-permit-effective-from"]').element as HTMLInputElement).value)
      .toBe('2026-09-16')
  })

  it('vyhledá jen firemní DMS dokument a odešle jeho ID s normalizovanými údaji', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="foreign-permit-kind"]').setValue('work')
    await wrapper.get('[data-test="foreign-permit-label"]').setValue('  Nová karta  ')
    await wrapper.get('[data-test="foreign-permit-country"]').setValue('sk')
    await wrapper.get('[data-test="foreign-permit-document-search"]').setValue('práce')
    await flushPromises()
    expect(wrapper.get('[data-test="foreign-permit-document-candidates"]').text())
      .toContain('Povolení k práci')
    expect(wrapper.get('[data-test="foreign-permit-document-candidates"]').text())
      .not.toContain('Soukromý doklad')
    await wrapper.get('[data-test="foreign-permit-document-candidates"] button').trigger('click')
    await wrapper.get('[data-test="foreign-permit-form"]').trigger('submit')
    await flushPromises()

    expect(mocks.createForeignPermit).toHaveBeenCalledWith(17, expect.objectContaining({
      permit_kind: 'work',
      permit_label: 'Nová karta',
      issuing_country_code: 'SK',
      document_id: 72,
      supersedes_permit_id: null,
    }))
    expect(mocks.success).toHaveBeenCalled()
  })

  it('zastaví odeslání bez DMS dokladu dřív, než dojde na API', async () => {
    const wrapper = await mounted()
    await wrapper.get('[data-test="foreign-permit-form"]').trigger('submit')
    await flushPromises()

    expect(mocks.createForeignPermit).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="foreign-permit-error"]').text())
      .toContain('payroll.people.foreign_permits.document_required')
  })
})
