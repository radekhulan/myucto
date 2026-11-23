import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  identities: vi.fn(),
  stepUp: vi.fn(),
  auth: { setupStatus: { sso_providers: [] as Array<{ id: string; label: string; strong?: boolean }> } },
}))
vi.mock('@/api/oidc', () => ({ oidcApi: { identities: m.identities, stepUp: m.stepUp } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: vi.fn() }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import SsoStepUpOption from '../SsoStepUpOption.vue'
import { invalidateSsoIdentities } from '@/composables/useSsoStepUp'

beforeEach(() => {
  vi.clearAllMocks()
  invalidateSsoIdentities()
  m.identities.mockResolvedValue([
    { id: 1, provider: 'entra' },
    { id: 2, provider: 'google' },
  ])
})

async function mountOption() {
  const wrapper = mount(SsoStepUpOption, { props: { operation: 'api_token.create' } })
  await flushPromises()
  return wrapper
}

it('se silným Entra ID a propojenou identitou ověření nabídne', async () => {
  m.auth.setupStatus.sso_providers = [{ id: 'entra', label: 'Microsoft', strong: true }]
  const wrapper = await mountOption()
  expect(wrapper.find('[data-testid="sso-step-up"]').exists()).toBe(true)
})

it('poskytovatele jen prvního faktoru k ověření citlivé operace nenabídne', async () => {
  m.auth.setupStatus.sso_providers = [
    { id: 'entra', label: 'Microsoft', strong: false },
    { id: 'google', label: 'Google', strong: false },
    { id: 'apple', label: 'Apple' },
    { id: 'seznam', label: 'Seznam', strong: false },
  ]
  const wrapper = await mountOption()
  expect(wrapper.find('[data-testid="sso-step-up"]').exists()).toBe(false)
  expect(m.identities).not.toHaveBeenCalled()
})

it('jen propojený Google k ověření nestačí ani při zapnutém silném Entra ID', async () => {
  m.auth.setupStatus.sso_providers = [
    { id: 'entra', label: 'Microsoft', strong: true },
    { id: 'google', label: 'Google', strong: false },
  ]
  m.identities.mockResolvedValue([{ id: 2, provider: 'google' }])
  const wrapper = await mountOption()
  expect(wrapper.find('[data-testid="sso-step-up"]').exists()).toBe(false)
})
