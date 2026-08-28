import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PayrollEmployee } from '@/api/accounting'

/*
 * Legacy mzdová rekapitulace a rodné číslo (W1/P-02).
 *
 * Routa `/accounting/payroll/employees` je chráněná jen právem `accounting`,
 * takže by přes ni otevřené rodné číslo zapsal i uživatel bez jediného mzdového
 * práva — mimo šifrovanou evidenci `payroll_person_identifiers` a mimo stopu
 * o odhalení. Backend proto oba sloupce z routy odstranil a VYPLNĚNOU hodnotu
 * vrací jako 422. Kdyby formulář pole nechal, uživatel by narazil na chybu,
 * kterou nemá jak vyřešit — a u nevyplněného pole by mu aplikace tvrdila, že se
 * rodné číslo uložilo, přestože ho v novém modulu nikdy nenajde.
 */

const m = vi.hoisted(() => ({
  listPayrollEmployees: vi.fn(),
  listAccounts: vi.fn(),
  createPayrollEmployee: vi.fn(),
  updatePayrollEmployee: vi.fn(),
  capabilities: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPayrollEmployees: m.listPayrollEmployees,
    listAccounts: m.listAccounts,
    createPayrollEmployee: m.createPayrollEmployee,
    updatePayrollEmployee: m.updatePayrollEmployee,
    deletePayrollEmployee: vi.fn(),
    previewPayroll: vi.fn(),
    postPayroll: vi.fn(),
    exportReport: vi.fn(),
  },
}))
vi.mock('@/api/payroll', () => ({ payrollApi: { capabilities: m.capabilities } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: vi.fn() }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value),
  formatPeriod: (value: string) => value,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { edit: 'M0 0', trash: 'M0 0', download: 'M0 0', plus: 'M0 0', check: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnOutlineSm: () => 'btn-outline-sm',
  btnFilled: () => 'btn-filled',
}))
// Modal teleportuje do body; pro tenhle test stačí obsah vykreslit na místě.
vi.mock('@/components/ui/Modal.vue', () => ({
  default: { name: 'Modal', props: ['title'], template: '<div><slot /><slot name="footer" /></div>' },
}))

import PayrollRecap from '@/pages/accounting/PayrollRecap.vue'

function employee(overrides: Partial<PayrollEmployee> = {}): PayrollEmployee {
  return {
    id: 4,
    supplier_id: 1,
    full_name: 'Syntetická osoba',
    birth_date: '1990-05-06',
    taxpayer_type: 'employee',
    tax_credit_taxpayer: true,
    tax_declaration_signed: true,
    employment_type: 'hpp',
    child_count: 0,
    net_settlement_account_code: null,
    monthly_gross: 40_000,
    auto_post: false,
    is_active: true,
    created_at: '2026-01-01',
    updated_at: '2026-01-01',
    ...overrides,
  } as PayrollEmployee
}

async function mountPage() {
  const wrapper = mount(PayrollRecap)
  await flushPromises()
  return wrapper
}

async function openEmployeeForm(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  const edit = wrapper.findAll('button').find(button => button.attributes('title') === 'common.edit')
  await edit!.trigger('click')
  await flushPromises()
}

describe('PayrollRecap — legacy karta zaměstnance', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listPayrollEmployees.mockResolvedValue([employee()])
    m.listAccounts.mockResolvedValue([])
    m.capabilities.mockResolvedValue({ state: { status: 'inactive', start_period: null } })
    m.updatePayrollEmployee.mockResolvedValue({ employee: employee(), warnings: [] })
  })

  it('formulář se na rodné číslo ani adresu neptá', async () => {
    const wrapper = await mountPage()
    await openEmployeeForm(wrapper)

    expect(wrapper.text()).not.toContain('accounting.payroll.employees.form_birth_number')
    expect(wrapper.text()).not.toContain('accounting.payroll.employees.form_address')
    wrapper.unmount()
  })

  it('uložení karty žádné z obou polí neposílá, takže nemůže narazit na 422', async () => {
    const wrapper = await mountPage()
    await openEmployeeForm(wrapper)

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updatePayrollEmployee).toHaveBeenCalledTimes(1)
    const payload = m.updatePayrollEmployee.mock.calls[0][1]
    expect(payload).not.toHaveProperty('birth_number')
    expect(payload).not.toHaveProperty('address')
    expect(payload.full_name).toBe('Syntetická osoba')
    wrapper.unmount()
  })

  /*
   * V seznamu stálo `e.birth_number || e.birth_date` — backend rodné číslo
   * neposílá, takže se pod jménem nesmí objevit prázdno ani `undefined`.
   */
  it('v seznamu ukazuje místo rodného čísla datum narození', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('1990-05-06')
    expect(wrapper.text()).not.toContain('undefined')
    wrapper.unmount()
  })

  it('u osoby bez data narození nechá pomlčku, ne prázdno', async () => {
    m.listPayrollEmployees.mockResolvedValue([employee({ birth_date: null })])
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('—')
    wrapper.unmount()
  })
})
