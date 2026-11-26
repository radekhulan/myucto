import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AiCredentialsResponse, AiProvider } from '@/api/integrations'

const m = vi.hoisted(() => ({
  getAiCredentials: vi.fn(),
  listCloudModels: vi.fn(),
  setAiCredentials: vi.fn(),
  updateSupplier: vi.fn(),
  query: {} as Record<string, string>,
  replace: vi.fn(),
}))

vi.mock('@/api/integrations', () => ({
  integrationsApi: {
    getIdokladCreds: vi.fn().mockResolvedValue(null),
    getFakturoidCreds: vi.fn().mockResolvedValue(null),
    getAiCredentials: m.getAiCredentials,
    listCloudModels: m.listCloudModels,
    setAiCredentials: m.setAiCredentials,
  },
}))

vi.mock('@/api/settings', () => ({
  settingsApi: {
    getAiAssist: vi.fn().mockRejectedValue(new Error('not needed')),
    updateSupplier: m.updateSupplier,
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ path: '/admin/integrations', query: m.query }),
  useRouter: () => ({ replace: m.replace }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))

vi.mock('@/composables/useSessionAwarePolling', () => ({ useSessionAwarePolling: vi.fn() }))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, params?: Record<string, unknown>) => params ? key + JSON.stringify(params) : key }),
}))

import Integrations from '@/pages/admin/Integrations.vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import type { SupplierBrief } from '@/api/auth'
import { aiProviderBadge } from '@/pages/admin/aiProviderBadge'

function creds(active: AiProvider, configured: Partial<Record<AiProvider, boolean>>): AiCredentialsResponse {
  const info = (p: AiProvider) => ({
    configured: configured[p] ?? false,
    default_model: 'model-x',
    models: ['model-x'],
    data_region: 'us',
    eu_capable: p === 'openai' || p === 'azure_openai',
    residency_label: null,
    extractions_count: 0,
  })
  return {
    ai_provider: active,
    ai_data_region: 'us',
    ai_eu_residency_required: false,
    ai_extraction_notes: '',
    ai_effort: 'default',
    ai_efforts: ['default', 'fast', 'accurate'],
    ai_extraction_notes_max: 2000,
    providers: {
      anthropic: info('anthropic'),
      azure_openai: info('azure_openai'),
      openai: info('openai'),
      gemini: info('gemini'),
    },
  } as unknown as AiCredentialsResponse
}

async function mountPage() {
  const w = mount(Integrations)
  await flushPromises()
  return w
}

describe('aiProviderBadge', () => {
  it('ukazuje „aktivní" jen u zvoleného poskytovatele s klíčem', () => {
    expect(aiProviderBadge('anthropic', 'anthropic', true)).toBe('active')
    expect(aiProviderBadge('anthropic', 'anthropic', false)).toBe('no_key')
    expect(aiProviderBadge('openai', 'anthropic', true)).toBeNull()
    expect(aiProviderBadge('openai', 'anthropic', false)).toBeNull()
    expect(aiProviderBadge('anthropic', null, true)).toBeNull()
  })
})

describe('Externí integrace → AI extrakce', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    m.getAiCredentials.mockReset()
    m.listCloudModels.mockReset()
    m.setAiCredentials.mockReset()
    m.updateSupplier.mockReset().mockResolvedValue({})
    m.query = { tab: 'ai' }
    m.replace.mockClear()
  })

  it('řadí záložky s webhooky pro správce firmy', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    const auth = useAuthStore()
    const w = await mountPage()
    const tabs = () => w.findAll('button[aria-pressed]').map(button => button.text())
      .filter(label => label.startsWith('integrations.') && label.endsWith('.tab'))

    expect(tabs()).toEqual(['integrations.ai.tab', 'integrations.fakturoid.tab', 'integrations.idoklad.tab'])
    auth.user = { is_superadmin: true } as NonNullable<typeof auth.user>
    await w.vm.$nextTick()
    expect(tabs()).toEqual(['integrations.ai.tab', 'integrations.webhooks.tab', 'integrations.fakturoid.tab', 'integrations.idoklad.tab'])
    w.unmount()
  })

  it('otevře výchozí Webhooky pro správce a ponechá přímý odkaz na iDoklad', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    m.query = {}
    const auth = useAuthStore()
    const w = await mountPage()
    expect(w.get('button[aria-pressed="true"]').text()).toBe('integrations.idoklad.tab')

    auth.user = { is_superadmin: true } as NonNullable<typeof auth.user>
    await w.vm.$nextTick()
    expect(w.get('button[aria-pressed="true"]').text()).toBe('integrations.webhooks.tab')
    await w.findAll('button').find(button => button.text() === 'integrations.idoklad.tab')!.trigger('click')
    expect(m.replace).toHaveBeenCalledExactlyOnceWith({ query: { tab: 'idoklad' } })
    w.unmount()

    m.query = { tab: 'idoklad' }
    const idoklad = await mountPage()
    expect(idoklad.get('button[aria-pressed="true"]').text()).toBe('integrations.idoklad.tab')
    idoklad.unmount()
  })

  it('firma bez klíče nevidí u Anthropicu odznak „aktivní"', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    const w = await mountPage()
    expect(w.find('[data-test="badge-active"]').exists()).toBe(false)
    const noKey = w.findAll('[data-test="badge-no-key"]')
    expect(noKey).toHaveLength(1)
    expect(noKey[0].element.closest('button')?.textContent).toContain('aiGateway.provider.anthropic')
  })

  it('odznak „aktivní" se objeví, až má zvolený poskytovatel klíč', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', { anthropic: true, openai: true }))
    const w = await mountPage()
    const active = w.findAll('[data-test="badge-active"]')
    expect(active).toHaveLength(1)
    expect(active[0].element.closest('button')?.textContent).toContain('aiGateway.provider.anthropic')
    expect(w.find('[data-test="badge-no-key"]').exists()).toBe(false)
  })

  it('načte modely projektu OpenAI a uloží zvolený model s existujícím klíčem', async () => {
    m.getAiCredentials.mockResolvedValue(creds('openai', { openai: true }))
    m.listCloudModels.mockResolvedValue({ models: ['gpt-6.2-sol-2026-11-12'] })
    m.setAiCredentials.mockResolvedValue({ saved: true, tested: false, test_ok: null })
    const w = await mountPage()
    const model = w.get('#ai-extraction-default-model')
    expect(model.find('option[value="gpt-6.2-sol-2026-11-12"]').exists()).toBe(false)

    await w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!.trigger('click')
    await flushPromises()
    expect(m.listCloudModels).toHaveBeenCalledExactlyOnceWith('openai', { base_url: '' }, expect.any(AbortSignal))
    await model.setValue('gpt-6.2-sol-2026-11-12')
    const save = w.get('[data-test="save-ai-credentials"]')
    expect(save.text()).toBe('common.save')
    await save.trigger('click')
    await flushPromises()

    expect(m.setAiCredentials).toHaveBeenCalledExactlyOnceWith({
      provider: 'openai', default_model: 'gpt-6.2-sol-2026-11-12', base_url: '',
    })
    expect(m.listCloudModels).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('načte modely Claude s uloženým klíčem a ID workspace', async () => {
    const status = creds('anthropic', { anthropic: true })
    status.providers.anthropic.workspace_id = 'wrkspc_fixture'
    m.getAiCredentials.mockResolvedValue(status)
    m.listCloudModels.mockResolvedValue({ models: ['claude-haiku-5-5', 'claude-fable-5-2'] })
    const w = await mountPage()

    await w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!.trigger('click')
    await flushPromises()
    expect(m.listCloudModels).toHaveBeenCalledExactlyOnceWith('anthropic', { workspace_id: 'wrkspc_fixture' }, expect.any(AbortSignal))
    expect(w.get('#ai-extraction-default-model').findAll('option:not(:disabled)').map(o => o.text())).toEqual([
      'claude-haiku-5-5', 'claude-fable-5-2',
    ])
    w.unmount()
  })

  it('načte modely Gemini s právě zadaným klíčem a nic neuloží', async () => {
    m.getAiCredentials.mockResolvedValue(creds('gemini', {}))
    m.listCloudModels.mockResolvedValue({ models: ['gemini-4-hydrogen'] })
    const w = await mountPage()
    const model = w.get('#ai-extraction-default-model')
    expect(w.findAll('button').some(b => b.text() === 'aiGateway.model_catalog_load')).toBe(false)

    await w.get('input[placeholder="AIza…"]').setValue('gemini-fixture-key')
    await w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!.trigger('click')
    await flushPromises()
    expect(m.listCloudModels).toHaveBeenCalledExactlyOnceWith('gemini', { api_key: 'gemini-fixture-key' }, expect.any(AbortSignal))
    expect(model.find('option[value="gemini-4-hydrogen"]').exists()).toBe(true)
    expect(m.setAiCredentials).not.toHaveBeenCalled()
    w.unmount()
  })

  it('zobrazí záložní modely při výpadku a zvláštní hlášku při odmítnutí klíče', async () => {
    const status = creds('gemini', { gemini: true })
    status.providers.gemini.default_model = 'gemini-3.8-flash'
    status.providers.gemini.models = ['gemini-3.8-flash']
    m.getAiCredentials.mockResolvedValue(status)
    m.listCloudModels
      .mockResolvedValueOnce({ models: ['gemini-3.8-flash'], catalog_error: 'model_catalog_unavailable' })
      .mockRejectedValueOnce({ response: { data: { error: { code: 'model_catalog_invalid_key' } } } })
    const w = await mountPage()
    const load = w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!

    await load.trigger('click')
    await flushPromises()
    expect(w.text()).toContain('aiGateway.model_catalog_unavailable')
    expect(w.get('#ai-extraction-default-model').find('option[value="gemini-3.8-flash"]').exists()).toBe(true)

    await load.trigger('click')
    await flushPromises()
    expect(w.text()).toContain('aiGateway.model_catalog_invalid_key')
    expect(w.text()).not.toContain('aiGateway.model_catalog_unavailable')
    w.unmount()
  })

  it('zobrazí částečný katalog s načtenými modely', async () => {
    m.getAiCredentials.mockResolvedValue(creds('gemini', { gemini: true }))
    m.listCloudModels.mockResolvedValue({ models: ['gemini-4-hydrogen'], catalog_error: 'model_catalog_incomplete' })
    const w = await mountPage()

    await w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!.trigger('click')
    await flushPromises()

    expect(w.get('[role="status"]').text()).toBe('aiGateway.model_catalog_incomplete')
    expect(w.get('#ai-extraction-default-model').find('option[value="gemini-4-hydrogen"]').exists()).toBe(true)
    w.unmount()
  })

  it.each([
    ['validation_failed', 'invalid_settings'],
    ['openai_base_url_invalid', 'openai_base_url_invalid'],
    ['model_catalog_endpoint_key_required', 'model_catalog_endpoint_key_required'],
  ])('přeloží chybu načtení katalogu %s podle kódu', async (code, key) => {
    m.getAiCredentials.mockResolvedValue(creds('openai', { openai: true }))
    m.listCloudModels.mockRejectedValue({ response: { data: { error: { code, message: 'Česká hláška serveru' } } } })
    const w = await mountPage()

    await w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!.trigger('click')
    await flushPromises()

    expect(w.get('[role="status"]').text()).toBe(`aiGateway.${key}`)
    w.unmount()
  })

  it.each(['model_invalid', 'model_unavailable', 'model_catalog_invalid_key', 'residency_conflict'])(
    'přeloží chybu uložení %s podle kódu', async code => {
      m.getAiCredentials.mockResolvedValue(creds('openai', { openai: true }))
      m.setAiCredentials.mockRejectedValue({ response: { data: { error: { code, message: 'Česká hláška serveru' } } } })
      const w = await mountPage()

      await w.get('[data-test="save-ai-credentials"]').trigger('click')
      await flushPromises()

      expect(w.text()).toContain(`aiGateway.${code}`)
      expect(w.text()).not.toContain('Česká hláška serveru')
      w.unmount()
    },
  )

  it('upozorní na náhradu uloženého modelu a předvybere používanou náhradu', async () => {
    const status = creds('gemini', { gemini: true })
    status.providers.gemini.default_model = 'gemini-4-carbon'
    status.providers.gemini.models = ['gemini-3.8-flash', 'gemini-4-carbon']
    status.providers.gemini.replaced_model = 'gemini-2.5-pro'
    m.getAiCredentials.mockResolvedValue(status)
    const w = await mountPage()

    expect(w.get('[role="status"]').text()).toContain('aiGateway.model_replaced{"previous":"gemini-2.5-pro","current":"gemini-4-carbon"}')
    expect(w.get<HTMLSelectElement>('#ai-extraction-default-model').element.value).toBe('gemini-4-carbon')
    w.unmount()
  })

  it('zahodí rozpracovaný katalog, když se změní zadaný klíč', async () => {
    m.getAiCredentials.mockResolvedValue(creds('gemini', {}))
    let resolve!: (value: { models: string[] }) => void
    m.listCloudModels.mockReturnValue(new Promise(r => { resolve = r }))
    const w = await mountPage()
    const key = w.get('input[placeholder="AIza…"]')
    await key.setValue('first-fixture-key')
    await w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!.trigger('click')
    await key.setValue('second-fixture-key')
    expect(m.listCloudModels.mock.calls[0]?.[2].aborted).toBe(true)
    resolve({ models: ['gemini-4-hydrogen'] })
    await flushPromises()

    expect(w.get('#ai-extraction-default-model').find('option[value="gemini-4-hydrogen"]').exists()).toBe(false)
    w.unmount()
  })

  it('při přepnutí firmy zneplatní rozpracovaný katalog a vymaže zadaný klíč', async () => {
    useSupplierStore().setAvailable([{ id: 1 }, { id: 2 }] as SupplierBrief[], 1)
    m.getAiCredentials.mockResolvedValueOnce(creds('gemini', {})).mockReturnValueOnce(new Promise(() => {}))
    m.listCloudModels.mockReturnValue(new Promise(() => {}))
    const w = await mountPage()

    await w.get('input[placeholder="AIza…"]').setValue('gemini-fixture-key')
    await w.findAll('button').find(b => b.text() === 'aiGateway.model_catalog_load')!.trigger('click')
    useSupplierStore().setSupplier(2)
    await flushPromises()

    expect(m.listCloudModels.mock.calls[0]?.[2].aborted).toBe(true)
    expect(w.get<HTMLInputElement>('input[placeholder="AIza…"]').element.value).toBe('')
    expect(w.findAll('button').some(b => b.text() === 'aiGateway.model_catalog_load')).toBe(false)
    w.unmount()
  })

  it('volba „jen do firem s nenastavenou AI" se ukáže až po zapnutí hromadného uložení', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    const w = await mountPage()
    const bulk = w.find('[data-test="bulk-apply"]')
    expect((bulk.element as HTMLInputElement).checked).toBe(false)
    expect(w.find('[data-test="bulk-only-unconfigured"]').exists()).toBe(false)

    await bulk.setValue(true)
    const only = w.find('[data-test="bulk-only-unconfigured"]')
    expect(only.exists()).toBe(true)
    expect((only.element as HTMLInputElement).checked).toBe(true)
  })

  it('hromadné uložení posílá příznaky serveru a vypíše výsledek', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    m.setAiCredentials.mockResolvedValue({
      saved: true, test_ok: true, test_error: null, model: 'model-x',
      bulk: { applied: true, updated: [{ id: 2, name: 'Firma B' }], skipped_configured: [{ id: 3, name: 'Firma C' }], skipped_forbidden: [], skipped_constraint: [] },
    })
    const w = await mountPage()
    await w.find('input[type="password"]').setValue('sk-ant-synthetic')
    await w.find('[data-test="bulk-apply"]').setValue(true)
    const save = w.findAll('button').find(b => b.text().includes('integrations.idoklad.save_and_test'))!
    await save.trigger('click')
    await flushPromises()

    expect(m.updateSupplier).toHaveBeenCalledWith({ ai_provider: 'anthropic', ai_data_region: 'us', ai_eu_residency_required: false })
    expect(m.setAiCredentials).toHaveBeenCalledWith(expect.objectContaining({
      provider: 'anthropic', api_key: 'sk-ant-synthetic', apply_to_all_companies: true, only_unconfigured: true,
    }))
    const result = w.find('[data-test="bulk-result"]')
    expect(result.text()).toContain('Firma B')
    expect(result.text()).toContain('Firma C')
  })

  it('bez zaškrtnutí se do dalších firem nic neposílá', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    m.setAiCredentials.mockResolvedValue({ saved: true, test_ok: true, test_error: null, model: 'model-x' })
    const w = await mountPage()
    await w.find('input[type="password"]').setValue('sk-ant-synthetic')
    const save = w.findAll('button').find(b => b.text().includes('integrations.idoklad.save_and_test'))!
    await save.trigger('click')
    await flushPromises()

    const payload = m.setAiCredentials.mock.calls[0][0]
    expect(payload).not.toHaveProperty('apply_to_all_companies')
    expect(payload).not.toHaveProperty('only_unconfigured')
    expect(w.find('[data-test="bulk-result"]').exists()).toBe(false)
  })

  it('při hromadném uložení bez zadaného klíče ukáže výsledek pro aktuální firmu', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', { anthropic: true }))
    m.setAiCredentials.mockResolvedValue({
      saved: true, tested: false, test_ok: null, test_error: null,
      bulk: { applied: false, reason: 'key_required' },
    })
    const w = await mountPage()
    await w.get('[data-test="bulk-apply"]').setValue(true)
    await w.get('[data-test="save-ai-credentials"]').trigger('click')
    await flushPromises()

    expect(w.get('[data-test="bulk-result"]').text()).toContain('aiGateway.bulk_key_required')
    w.unmount()
  })
})


describe('povolení klientského vytěžování', () => {
  it('načte uložené povolení i u firmy bez aktivního klíče', async () => {
    setActivePinia(createPinia())
    m.getAiCredentials.mockResolvedValue({ ...creds('anthropic', {}), client_ai_extraction_enabled: true })
    const w = await mountPage()
    expect((w.get('[data-test="client-ai-extraction"]').element as HTMLInputElement).checked).toBe(true)
    expect(w.get('[data-test="save-client-ai-extraction"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('uloží přepínač samostatně bez změny vybraného poskytovatele', async () => {
    setActivePinia(createPinia())
    m.getAiCredentials.mockResolvedValue(creds('anthropic', { anthropic: true }))
    m.updateSupplier.mockReset().mockResolvedValue({ client_ai_extraction_enabled: true })
    const w = await mountPage()
    expect((w.get('[data-test="client-ai-extraction"]').element as HTMLInputElement).checked).toBe(false)
    expect(w.get('[data-test="save-client-ai-extraction"]').attributes('disabled')).toBeDefined()
    await w.get('[data-test="client-ai-extraction"]').setValue(true)
    await w.get('[data-test="save-client-ai-extraction"]').trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledExactlyOnceWith({ client_ai_extraction_enabled: true, client_ai_extraction_monthly_limit: 100 })
    w.unmount()
  })
})


describe('měsíční limit klientského vytěžování', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    m.updateSupplier.mockReset().mockResolvedValue({})
    m.getAiCredentials.mockReset().mockResolvedValue({
      ...creds('anthropic', { anthropic: true }), client_ai_extraction_enabled: true,
      client_ai_extraction_usage: { period: '2026-10', limit: 25, used: 7, remaining: 18 },
    })
  })

  it('zobrazí čerpání a uloží limit včetně nuly', async () => {
    const w = await mountPage()
    expect((w.get('[data-test="client-ai-monthly-limit"]').element as HTMLInputElement).value).toBe('25')
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('\"used\":7')
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('\"remaining\":18')
    await w.get('[data-test="client-ai-monthly-limit"]').setValue('0')
    await w.get('[data-test="save-client-ai-extraction"]').trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledExactlyOnceWith({ client_ai_extraction_enabled: true, client_ai_extraction_monthly_limit: 0 })
    w.unmount()
  })

  it('po přepnutí firmy ignoruje opožděné čerpání původní firmy', async () => {
    useSupplierStore().setAvailable([{ id: 1 }, { id: 2 }] as SupplierBrief[], 1)
    let finish!: (value: AiCredentialsResponse) => void
    m.getAiCredentials.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const w = await mountPage()
    useSupplierStore().setSupplier(2)
    await flushPromises()
    finish({ ...creds('anthropic', {}), client_ai_extraction_usage: { period: '2026-10', limit: 1, used: 1, remaining: 0 } })
    await flushPromises()
    expect((w.get('[data-test="client-ai-monthly-limit"]').element as HTMLInputElement).value).toBe('25')
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('"remaining":18')
    w.unmount()
  })

  it.each(['-1', '100001', '1.5', ''])('neuloží neplatný limit %s', async value => {
    const w = await mountPage()
    await w.get('[data-test="client-ai-monthly-limit"]').setValue(value)
    expect(w.get('[data-test="save-client-ai-extraction"]').attributes('disabled')).toBeDefined()
    expect(m.updateSupplier).not.toHaveBeenCalled()
    w.unmount()
  })
})
