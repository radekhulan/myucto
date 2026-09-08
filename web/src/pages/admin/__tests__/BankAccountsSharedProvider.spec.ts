import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { BankEmailProvider } from '@/api/settings'

// Společný (globální) provider — např. seedovaná Česká spořitelna — nepatří žádné
// firmě, takže se jeho definice needituje a UI u něj nenabízí Editovat. Zapnout ho
// nebo vypnout jen pro svoji firmu (per-supplier override) ale jít musí: dokud to
// nešlo, byl dodaný provider z produktu neovlivnitelný — nešel ani rozchodit, ani
// umlčet, a jediným východiskem byla ruční kopie přes Duplikovat.

const m = vi.hoisted(() => ({
  tab: 'email',
  bankPanelMount: vi.fn(),
  getSupplier: vi.fn(),
  listCurrencies: vi.fn(),
  getBankEmailOverview: vi.fn(),
  updateBankEmailProvider: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/settings', () => ({
  settingsApi: {
    getSupplier: m.getSupplier,
    listCurrencies: m.listCurrencies,
    getBankEmailOverview: m.getBankEmailOverview,
    updateBankEmailProvider: m.updateBankEmailProvider,
  },
}))

vi.mock('@/api/clients', () => ({ clientsApi: { lookupBank: vi.fn() } }))
vi.mock('@/api/bank', () => ({ bankApi: { accountBalances: vi.fn() } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_e: unknown, fallback: string) => fallback }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: vi.fn() }),
}))
vi.mock('@/composables/useDemoMode', () => ({
  useDemoMode: () => ({ blockDemoMutation: () => false }),
}))
vi.mock('@/composables/useTheme', () => ({ useChartColors: () => ({}) }))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
  formatDate: (v: string) => v,
  formatDateTime: (v: string) => v,
}))
vi.mock('@/components/bank/BankConnectionsPanel.vue', () => ({
  default: { name: 'BankConnectionsPanel', setup: () => { m.bankPanelMount(); return () => null } },
}))
vi.mock('@/components/charts/BalanceTrendChart.vue', () => ({
  default: { name: 'BalanceTrendChart', template: '<div />' },
}))
vi.mock('@/components/ui/EmptyState.vue', () => ({
  default: { name: 'EmptyState', props: ['dense', 'icon', 'colspan', 'title'], template: '<tr><td /></tr>' },
}))
vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { plus: 'M0 0', edit: 'M0 0', trash: 'M0 0', check: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: { tab: m.tab } }),
  useRouter: () => ({ replace: vi.fn() }),
}))

import BankAccounts from '@/pages/admin/BankAccounts.vue'

function sharedProvider(overrides: Partial<BankEmailProvider> = {}): BankEmailProvider {
  return {
    id: 2,
    provider_ref: 'db:2',
    supplier_id: null, // společný provider — nepatří žádné firmě
    code: 'ceska-sporitelna',
    name: 'Česká spořitelna - avízo o pohybu',
    parser_type: 'regex',
    enabled: true,
    sender_whitelist: 'csas.cz',
    subject_pattern: null,
    body_pattern: 'Směr\\s+platby',
    field_patterns: { variable_symbol: 'Variabilní symbol:\\s*(?<value>[0-9]+)' },
    normalizer_config: {},
    ...overrides,
  }
}

async function mountWith(providers: BankEmailProvider[]) {
  m.getSupplier.mockResolvedValue({ id: 1 })
  m.listCurrencies.mockResolvedValue([])
  m.getBankEmailOverview.mockResolvedValue({
    imap: null,
    imap_accounts: [],
    providers,
    mappings: [],
    messages: [],
    messages_total: 0,
  })
  const wrapper = mount(BankAccounts)
  await flushPromises()
  return wrapper
}

function toggleButton(wrapper: Awaited<ReturnType<typeof mountWith>>) {
  return wrapper.findAll('button').find((b) =>
    b.text() === 'bank_accounts.provider_disable' || b.text() === 'bank_accounts.provider_enable')
}

describe('BankAccounts — společný parser provider', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.tab = 'email'
    m.updateBankEmailProvider.mockResolvedValue(undefined)
  })

  it('u společného provideru nabídne vypnutí pro vlastní firmu a pošle nezměněnou definici', async () => {
    const wrapper = await mountWith([sharedProvider()])

    const button = toggleButton(wrapper)
    expect(button, 'společný provider musí mít přepínač zapnutí/vypnutí').toBeDefined()
    expect(button!.text()).toBe('bank_accounts.provider_disable')

    await button!.trigger('click')
    await flushPromises()

    expect(m.updateBankEmailProvider).toHaveBeenCalledTimes(1)
    const [id, payload] = m.updateBankEmailProvider.mock.calls[0]
    expect(id).toBe(2)
    // Mění se JEN `enabled` — cokoli jiného backend odmítne (definice je společná).
    expect(payload.enabled).toBe(false)
    expect(payload.sender_whitelist).toBe('csas.cz')
    expect(payload.body_pattern).toBe('Směr\\s+platby')
    expect(payload.code).toBe('ceska-sporitelna')
    expect(payload.field_patterns).toEqual({ variable_symbol: 'Variabilní symbol:\\s*(?<value>[0-9]+)' })
  })

  it('vypnutý společný provider jde zapnout zpátky', async () => {
    const wrapper = await mountWith([sharedProvider({ enabled: false })])

    const button = toggleButton(wrapper)
    expect(button!.text()).toBe('bank_accounts.provider_enable')

    await button!.trigger('click')
    await flushPromises()

    expect(m.updateBankEmailProvider.mock.calls[0][1].enabled).toBe(true)
  })

  it('u vlastního provideru se přepínač nenabízí — ten se edituje formulářem', async () => {
    const wrapper = await mountWith([sharedProvider({ id: 7, supplier_id: 1, provider_ref: 'db:7' })])

    expect(toggleButton(wrapper)).toBeUndefined()
  })
})

it('loads email overview only after opening its tab and reuses the result', async () => {
  vi.clearAllMocks()
  m.tab = 'accounts'
  const wrapper = await mountWith([])
  expect(m.getBankEmailOverview).not.toHaveBeenCalled()
  expect(m.bankPanelMount).toHaveBeenCalledTimes(1)
  const emailTab = wrapper.findAll('button').find(b => b.text() === 'bank_accounts.tab_email_notices')!
  await emailTab.trigger('click')
  await flushPromises()
  expect(m.getBankEmailOverview).toHaveBeenCalledTimes(1)
  const accountsTab = wrapper.findAll('button').find(b => b.text() === 'bank_accounts.tab_accounts')!
  await accountsTab.trigger('click')
  await emailTab.trigger('click')
  await flushPromises()
  expect(m.getBankEmailOverview).toHaveBeenCalledTimes(1)
  wrapper.unmount()
})

it('keeps account navigation available while the email overview is pending and retries failures', async () => {
  vi.clearAllMocks()
  m.tab = 'accounts'
  const wrapper = await mountWith([])
  let rejectOverview!: (error: Error) => void
  m.getBankEmailOverview.mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectOverview = reject }))
  const emailTab = wrapper.findAll('button').find(b => b.text() === 'bank_accounts.tab_email_notices')!
  const accountsTab = wrapper.findAll('button').find(b => b.text() === 'bank_accounts.tab_accounts')!
  await emailTab.trigger('click')
  expect(wrapper.text()).toContain('bank_accounts.loading')
  await accountsTab.trigger('click')
  await emailTab.trigger('click')
  expect(m.getBankEmailOverview).toHaveBeenCalledTimes(1)
  rejectOverview(new Error('Unavailable'))
  await flushPromises()
  expect(wrapper.text()).toContain('bank_accounts.load_config_failed')
  await accountsTab.trigger('click')
  await emailTab.trigger('click')
  await flushPromises()
  expect(m.getBankEmailOverview).toHaveBeenCalledTimes(2)
  expect(wrapper.text()).not.toContain('bank_accounts.load_config_failed')
  wrapper.unmount()
})
