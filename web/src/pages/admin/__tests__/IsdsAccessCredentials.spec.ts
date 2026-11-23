import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import IsdsAccessCredentials from '@/pages/admin/IsdsAccessCredentials.vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  save: vi.fn(),
  remove: vi.fn(),
  auth: {
    isSuperadmin: true,
    isDemo: false,
    submissionTestEnvironmentAllowed: true,
    user: { totp_enabled: false, mfa_methods: [], passkey_count: 0 },
    setupStatus: { sso_required_provider: null },
  },
}))

vi.mock('@/api/isdsAccess', () => ({
  isdsAccessApi: { adminList: mocks.list, adminSave: mocks.save, adminRemove: mocks.remove },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => mocks.auth }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))

const access = {
  id: 71,
  label: 'Sdílená účtárna',
  box_id: 'te5tb0x',
  environment: 'production',
  method: 'password',
  scope: 'shared',
  certificate_id: null,
  grants: [{ supplier_id: 11, send: true, receive: false, receipts: false }],
}

function mountPage() {
  return mount(IsdsAccessCredentials, {
    global: {
      stubs: {
        Modal: { template: '<section role="dialog"><slot /></section>' },
        SsoStepUpOption: true,
        RouterLink: { template: '<a><slot /></a>' },
      },
    },
  })
}

function button(wrapper: ReturnType<typeof mountPage>, label: string) {
  const match = wrapper.findAll('button').find(item => item.text() === label)
  if (!match) throw new Error(`Missing button: ${label}`)
  return match
}

describe('Shared data box credentials administration', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    mocks.auth.isSuperadmin = true
    mocks.auth.isDemo = false
    mocks.list.mockResolvedValue({
      items: [access],
      companies: [{ id: 11, name: 'Alpha' }, { id: 22, name: 'Beta' }],
      certificates: [{ id: 12, label: 'Signing certificate' }],
    })
    mocks.save.mockResolvedValue(access)
    mocks.remove.mockResolvedValue(undefined)
  })

  it('allows several companies to send but directs incoming messages to one company', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await button(wrapper, 'isdsAccess.admin.add').trigger('click')
    const dialog = wrapper.get('[role="dialog"]')
    const company = (name: string, operation: string) =>
      dialog.get<HTMLInputElement>(`input[aria-label="${name} · isdsAccess.admin.operation.${operation}"]`)

    await dialog.get('input[maxlength="120"]').setValue('Podatelna')
    await dialog.get('input[autocomplete="off"]').setValue('  sample-isds-login  ')
    await dialog.get('input[autocomplete="new-password"]').setValue('sample-isds-secret')
    await company('Alpha', 'receive').setValue(true)
    await company('Alpha', 'send').setValue(true)
    await company('Beta', 'send').setValue(true)
    await company('Beta', 'receipts').setValue(true)
    expect(company('Beta', 'receive').element.disabled).toBe(true)
    expect(dialog.get<HTMLButtonElement>('[data-action="save"]').element.disabled).toBe(true)
    await dialog.get('input[autocomplete="current-password"]').setValue('sample-myucto-proof')
    await dialog.get('[data-action="save"]').trigger('click')
    await flushPromises()

    expect(mocks.save).toHaveBeenCalledWith(null, {
      label: 'Podatelna', method: 'password', environment: 'production',
      username: 'sample-isds-login', password: 'sample-isds-secret',
      grants: [
        { supplier_id: 11, send: true, receive: true, receipts: false },
        { supplier_id: 22, send: true, receive: false, receipts: true },
      ],
      step_up: { password: 'sample-myucto-proof' },
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('edits a label without sending stored credentials and leaves replacement fields blank', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await button(wrapper, 'common.edit').trigger('click')
    const dialog = wrapper.get('[role="dialog"]')

    expect(dialog.text()).toContain(access.box_id)
    expect(dialog.get<HTMLSelectElement>('select').element.disabled).toBe(true)
    expect(dialog.find('input[autocomplete="off"]').exists()).toBe(false)
    expect(dialog.find('input[autocomplete="new-password"]').exists()).toBe(false)
    await dialog.get('label input[type="checkbox"]:not([aria-label])').setValue(true)
    expect(dialog.get<HTMLInputElement>('input[autocomplete="off"]').element.value).toBe('')
    expect(dialog.get<HTMLInputElement>('input[autocomplete="new-password"]').element.value).toBe('')
    await dialog.get('label input[type="checkbox"]:not([aria-label])').setValue(false)
    await dialog.get('input[maxlength="120"]').setValue('Nový název')
    await dialog.get('input[autocomplete="current-password"]').setValue('sample-myucto-proof')
    await dialog.get('[data-action="save"]').trigger('click')
    await flushPromises()

    const saved = mocks.save.mock.calls[0]
    expect(saved).toEqual([access.id, {
      label: 'Nový název', method: 'password', environment: 'production', grants: access.grants,
      step_up: { password: 'sample-myucto-proof' },
    }])
    wrapper.unmount()
  })

  it('can save a system certificate without separate data box credentials', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await button(wrapper, 'isdsAccess.admin.add').trigger('click')
    const dialog = wrapper.get('[role="dialog"]')
    await dialog.get('input[maxlength="120"]').setValue('Systémový přístup')
    await dialog.get('select:has(option[value="test"])').setValue('test')
    await dialog.get('select:has(option[value="user_certificate"])').setValue('certificate')
    expect(dialog.find('input[autocomplete="new-password"]').exists()).toBe(false)
    await dialog.get('select:has(option[value="12"])').setValue('12')
    await dialog.get('input[autocomplete="current-password"]').setValue('sample-myucto-proof')
    await dialog.get('[data-action="save"]').trigger('click')
    await flushPromises()

    expect(mocks.save).toHaveBeenCalledWith(null, {
      label: 'Systémový přístup', method: 'certificate', environment: 'test', certificate_id: 12,
      grants: [], step_up: { password: 'sample-myucto-proof' },
    })
    wrapper.unmount()
  })

  it('requires fresh proof to remove the shared access', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await button(wrapper, 'isdsAccess.admin.remove').trigger('click')
    const dialog = wrapper.get('[role="dialog"]')
    expect(dialog.get<HTMLButtonElement>('[data-action="remove"]').element.disabled).toBe(true)
    await dialog.get('input[autocomplete="current-password"]').setValue('sample-myucto-proof')
    await dialog.get('[data-action="remove"]').trigger('click')
    await flushPromises()

    expect(mocks.remove).toHaveBeenCalledWith(access.id, { password: 'sample-myucto-proof' })
    wrapper.unmount()
  })

  it.each(['regular users', 'demo users'])('does not load the credential inventory for %s', async actor => {
    mocks.auth.isSuperadmin = actor !== 'regular users'
    mocks.auth.isDemo = actor === 'demo users'
    const wrapper = mountPage()
    await flushPromises()

    expect(mocks.list).not.toHaveBeenCalled()
    expect(wrapper.find('h1').exists()).toBe(false)
    wrapper.unmount()
  })
})
