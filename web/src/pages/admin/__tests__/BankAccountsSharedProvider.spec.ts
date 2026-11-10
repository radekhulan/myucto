import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { BankEmailImapSettings, BankEmailProvider } from '@/api/settings'

// Společný (globální) provider — např. seedovaná Česká spořitelna — nepatří žádné
// firmě, takže se jeho definice needituje a UI u něj nenabízí Editovat. Zapnout ho
// nebo vypnout jen pro svoji firmu (per-supplier override) ale jít musí: dokud to
// nešlo, byl dodaný provider z produktu neovlivnitelný — nešel ani rozchodit, ani
// umlčet, a jediným východiskem byla ruční kopie přes Duplikovat.

const m = vi.hoisted(() => ({
  tab: 'email',
  client: false,
  accountBalances: vi.fn(),
  bankPanelMount: vi.fn(),
  getSupplier: vi.fn(),
  listCurrencies: vi.fn(),
  getBankEmailOverview: vi.fn(),
  updateBankEmailProvider: vi.fn(),
  createBankEmailImapAccount: vi.fn(),
  updateBankEmailImapAccount: vi.fn(),
  browseBankEmailImapFolders: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/settings', () => ({
  settingsApi: {
    getSupplier: m.getSupplier,
    listCurrencies: m.listCurrencies,
    getBankEmailOverview: m.getBankEmailOverview,
    updateBankEmailProvider: m.updateBankEmailProvider,
    createBankEmailImapAccount: m.createBankEmailImapAccount,
    updateBankEmailImapAccount: m.updateBankEmailImapAccount,
    browseBankEmailImapFolders: m.browseBankEmailImapFolders,
  },
}))

vi.mock('@/api/clients', () => ({ clientsApi: { lookupBank: vi.fn() } }))
vi.mock('@/api/bank', () => ({ bankApi: { accountBalances: m.accountBalances } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ get isClientRole() { return m.client } }) }))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_e: unknown, fallback: string) => fallback,
  apiErrorCode: (e: any) => e?.response?.data?.error?.code ?? '',
}))

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
  default: { name: 'BankConnectionsPanel', props: ['accounts'], setup: () => { m.bankPanelMount(); return () => null } },
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

async function mountWith(providers: BankEmailProvider[], currencies: unknown[] = [], imapAccounts: Partial<BankEmailImapSettings>[] = []) {
  m.getSupplier.mockResolvedValue({ id: 1 })
  m.listCurrencies.mockResolvedValue(currencies)
  m.getBankEmailOverview.mockResolvedValue({
    imap: null,
    imap_accounts: imapAccounts,
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
    m.client = false
    m.accountBalances.mockResolvedValue(null)
    m.updateBankEmailProvider.mockResolvedValue(undefined)
  })

  it('uloží Microsoft 365 účet s údaji aplikace a adresou schránky', async () => {
    m.createBankEmailImapAccount.mockResolvedValueOnce({})
    const wrapper = await mountWith([])
    await wrapper.findAll('button').find(button => button.text() === 'bank_accounts.new_imap')!.trigger('click')
    const label = (key: string) => wrapper.findAll('label').find(item => item.text().startsWith(key))!
    await label('settings.imap_auth_mode').get('select').setValue('microsoft365')
    expect(wrapper.findAll('label').some(item => item.text() === 'bank_accounts.host')).toBe(false)
    await label('settings.imap_oauth_mailbox').get('input').setValue('shared@example.test')
    await label('settings.imap_oauth_tenant_id').get('input').setValue('11111111-1111-4111-8111-111111111111')
    await label('settings.imap_oauth_client_id').get('input').setValue('22222222-2222-4222-8222-222222222222')
    await label('settings.imap_oauth_client_secret').get('input').setValue('synthetic-client-secret')
    await label('bank_accounts.require_email_auth').get('input').setValue(false)
    await wrapper.findAll('button').find(button => button.text() === 'bank_accounts.save_imap')!.trigger('click')
    await flushPromises()
    expect(m.createBankEmailImapAccount).toHaveBeenCalledWith(expect.objectContaining({
      auth_mode: 'microsoft365',
      username: 'shared@example.test',
      oauth_tenant_id: '11111111-1111-4111-8111-111111111111',
      oauth_client_id: '22222222-2222-4222-8222-222222222222',
      oauth_client_secret: 'synthetic-client-secret',
      password: undefined,
    }))
    wrapper.unmount()
  })

  it.each([
    ['settings.imap_oauth_mailbox', 'shared@example.test', 'other@example.test'],
    ['settings.imap_oauth_tenant_id', '11111111-1111-4111-8111-111111111111', '33333333-3333-4333-8333-333333333333'],
    ['settings.imap_oauth_client_id', '22222222-2222-4222-8222-222222222222', '44444444-4444-4444-8444-444444444444'],
  ])('po změně %s vyžádá Client Secret před uložením i načtením složek', async (field, original, changed) => {
    const wrapper = await mountWith([], [], [{
      id: 7,
      name: 'Syntetická schránka',
      auth_mode: 'microsoft365',
      username: 'shared@example.test',
      oauth_tenant_id: '11111111-1111-4111-8111-111111111111',
      oauth_client_id: '22222222-2222-4222-8222-222222222222',
      has_oauth_client_secret: true,
      require_email_auth: false,
    }])
    const button = (key: string) => wrapper.findAll('button').find(item => item.text() === key)!
    const input = (key: string) => wrapper.findAll('label').find(item => item.text().startsWith(key))!.get('input')
    await button('common.edit').trigger('click')
    const target = input(field)
    const secret = input('settings.imap_oauth_client_secret')
    expect(secret.attributes('placeholder')).toBe('settings.imap_oauth_secret_saved')

    await target.setValue(changed)
    expect(secret.attributes('required')).toBeDefined()
    expect(secret.attributes('placeholder')).toBeUndefined()
    await button('bank_accounts.save_imap').trigger('click')
    await button('bank_accounts.browse').trigger('click')
    expect(m.updateBankEmailImapAccount).not.toHaveBeenCalled()
    expect(m.browseBankEmailImapFolders).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('settings.imap_oauth_secret_required')

    await target.setValue(original)
    expect(secret.attributes('required')).toBeUndefined()
    expect(secret.attributes('placeholder')).toBe('settings.imap_oauth_secret_saved')

    await target.setValue(changed)
    await secret.setValue('synthetic-new-secret')
    m.updateBankEmailImapAccount.mockResolvedValueOnce({})
    await button('bank_accounts.save_imap').trigger('click')
    await flushPromises()
    expect(m.updateBankEmailImapAccount).toHaveBeenCalledWith(7, expect.objectContaining({
      oauth_client_secret: 'synthetic-new-secret',
    }))
    wrapper.unmount()
  })

  it('po změně serveru vyžádá znovu heslo před uložením i načtením složek', async () => {
    const wrapper = await mountWith([], [], [{
      id: 8,
      name: 'Syntetická avíza',
      auth_mode: 'password',
      host: 'imap.example.test',
      port: 993,
      encryption: 'ssl',
      username: 'aviza@example.test',
      has_password: true,
      require_email_auth: false,
    }])
    const button = (key: string) => wrapper.findAll('button').find(item => item.text() === key)!
    const host = () => wrapper.findAll('div').find(item => item.find('label').exists() && item.get('label').text() === 'bank_accounts.host')!.get('input')
    await button('common.edit').trigger('click')
    const password = wrapper.get('input[type="password"]')
    expect(password.attributes('required')).toBeUndefined()

    await host().setValue('attacker.example.test')
    expect(password.attributes('required')).toBeDefined()
    await button('bank_accounts.save_imap').trigger('click')
    await button('bank_accounts.browse').trigger('click')
    expect(m.updateBankEmailImapAccount).not.toHaveBeenCalled()
    expect(m.browseBankEmailImapFolders).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('bank_accounts.imap_password_required')

    await password.setValue('synthetic-new-password')
    m.updateBankEmailImapAccount.mockResolvedValueOnce({})
    await button('bank_accounts.save_imap').trigger('click')
    await flushPromises()
    expect(m.updateBankEmailImapAccount).toHaveBeenCalledWith(8, expect.objectContaining({
      host: 'attacker.example.test',
      password: 'synthetic-new-password',
    }))
    wrapper.unmount()
  })

  it('odmítnutí serveru kvůli uloženému heslu převede na výzvu k zadání hesla', async () => {
    const wrapper = await mountWith([], [], [{
      id: 9, name: 'Syntetická avíza', auth_mode: 'password', host: 'imap.example.test', port: 993,
      encryption: 'ssl', username: 'aviza@example.test', has_password: true, require_email_auth: false,
    }])
    await wrapper.findAll('button').find(item => item.text() === 'common.edit')!.trigger('click')
    m.browseBankEmailImapFolders.mockRejectedValueOnce({ response: { data: { error: { code: 'imap_password_required', message: 'x' } } } })
    await wrapper.findAll('button').find(item => item.text() === 'bank_accounts.browse')!.trigger('click')
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('bank_accounts.imap_password_required')
    wrapper.unmount()
  })

  it('nová schránka nabízí označení po načtení vypnuté a uloží jeho zapnutí nezávisle na akci po úspěchu', async () => {
    let saved: unknown
    m.createBankEmailImapAccount.mockImplementationOnce(async payload => { saved = { ...payload }; return {} })
    const wrapper = await mountWith([])
    await wrapper.findAll('button').find(button => button.text() === 'bank_accounts.new_imap')!.trigger('click')
    const markSeen = wrapper.get('[data-testid="imap-mark-seen-after-import"] input')
    expect((markSeen.element as HTMLInputElement).checked).toBe(false)
    await markSeen.setValue(true)
    const authLabel = wrapper.findAll('label').find(label => label.text().startsWith('bank_accounts.require_email_auth'))!
    await authLabel.get('input').setValue(false)
    await wrapper.findAll('button').find(button => button.text() === 'bank_accounts.save_imap')!.trigger('click')
    await flushPromises()
    expect(m.createBankEmailImapAccount).toHaveBeenCalledOnce()
    expect(saved).toMatchObject({ mark_seen_after_import: true, success_action: 'none' })
    expect(m.toastSuccess).toHaveBeenCalledWith('bank_accounts.imap_created')
    wrapper.unmount()
  })

  it('označení po načtení je nedostupné při vypnutém načítání avíz', async () => {
    const wrapper = await mountWith([])
    await wrapper.findAll('button').find(button => button.text() === 'bank_accounts.new_imap')!.trigger('click')
    const ingestLabel = wrapper.findAll('label').find(label => label.text().startsWith('bank_accounts.ingest_notices'))!
    await ingestLabel.get('input').setValue(false)
    expect((wrapper.get('[data-testid="imap-mark-seen-after-import"] input').element as HTMLInputElement).disabled).toBe(true)
    wrapper.unmount()
  })

  it('loads staff account settings when entering on balances and retains them after switching to accounts', async () => {
    m.tab = 'balances'
    const currencies = [{ id: 7, currency: 'CZK', bank_account: '1000000005', bank_code: '0100', is_default: true }]
    const wrapper = await mountWith([], currencies)
    expect(m.accountBalances).toHaveBeenCalledOnce()
    expect(m.getSupplier).toHaveBeenCalledOnce()
    expect(m.listCurrencies).toHaveBeenCalledOnce()
    await wrapper.findAll('button').find(button => button.text() === 'bank_accounts.tab_accounts')!.trigger('click')
    await flushPromises()
    expect(wrapper.findComponent({ name: 'BankConnectionsPanel' }).props('accounts')).toEqual(currencies)
    wrapper.unmount()
  })

  it('loads client balances without requesting bank settings', async () => {
    m.tab = 'balances'
    m.client = true
    const wrapper = await mountWith([])
    expect(m.accountBalances).toHaveBeenCalledOnce()
    expect(m.getSupplier).not.toHaveBeenCalled()
    expect(m.listCurrencies).not.toHaveBeenCalled()
    expect(m.getBankEmailOverview).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('bank_accounts.loading')
    wrapper.unmount()
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
