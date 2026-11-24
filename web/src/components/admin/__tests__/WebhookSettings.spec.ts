import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { WebhookConfiguration, WebhookEndpoint } from '@/api/webhooks'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import WebhookSettings from '../WebhookSettings.vue'

const { list, create, update, test } = vi.hoisted(() => ({
  list: vi.fn(), create: vi.fn(), update: vi.fn(), test: vi.fn(),
}))

vi.mock('@/api/webhooks', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/webhooks')>(),
  webhooksApi: { list, create, update, test },
}))

vi.mock('@/api/recurring', () => ({
  recurringApi: {
    list: async () => ({ data: [{ id: 12, name: 'Testovací šablona' }], meta: { total: 1 } }),
  },
}))

vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => true }),
}))

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

const endpoint: WebhookEndpoint = {
  id: 1, name: 'Testovací odběr', url: 'https://example.test/events', enabled: true,
  template_id: 12, event_types: ['invoice.paid'], include_advance: false,
}

async function render(configuration: WebhookConfiguration, templateId: number | null = 12) {
  list.mockResolvedValue(configuration)
  const wrapper = mount(WebhookSettings, {
    props: { templateId, helpHref: '/manual?ch=103_API#103927-odchozi-webhooky' },
    global: { stubs: { RouterLink: true } },
  })
  await flushPromises()
  return wrapper
}

describe('Webhooky v externích integracích', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  it('pro instalaci bez předplatného ukáže požadavek licence', async () => {
    const wrapper = await render({ available: false, policy: 'public', endpoints: [] })
    expect(wrapper.text()).toContain('integrations.webhooks.license_required')
    expect(wrapper.get('a[target="_blank"]').attributes('href')).toBe('/manual?ch=103_API#103927-odchozi-webhooky')
    expect(JSON.parse(wrapper.get('details pre').text())).toMatchObject({ type: 'invoice.paid', invoice_id: 1234 })
    expect(wrapper.text()).not.toContain('integrations.webhooks.add')
    wrapper.unmount()
  })

  it('ve vypnutém režimu ponechá pozastavení a historii', async () => {
    const wrapper = await render({ available: true, policy: 'disabled', endpoints: [endpoint] })
    expect(wrapper.text()).toContain('integrations.webhooks.policy_disabled')
    expect(wrapper.text()).toContain('integrations.webhooks.history')
    expect(wrapper.text()).not.toContain('integrations.webhooks.test')
    await wrapper.findAll('button').find(button => button.text() === 'integrations.webhooks.pause')?.trigger('click')
    expect(update).toHaveBeenCalledWith(1, { enabled: false })
    wrapper.unmount()
  })

  it('označuje povinná pole a po neplatném pokusu zobrazí chyby u náležitých vstupů', async () => {
    const wrapper = await render({ available: true, policy: 'public', endpoints: [] })
    await wrapper.findAll('button').find(button => button.text() === 'integrations.webhooks.add')?.trigger('click')
    const name = wrapper.get('input[type="text"]')
    const url = wrapper.get('input[type="url"]')
    const events = wrapper.get('fieldset')
    for (const input of [name, url]) {
      expect(input.attributes()).toHaveProperty('required')
      expect(wrapper.get(`label[for="${input.attributes('id')}"]`).text()).toContain('*')
    }
    expect(events.get('legend').text()).toContain('*')
    expect(wrapper.text()).toContain('integrations.webhooks.required_fields')

    await name.setValue('   ')
    await wrapper.get('input[value="invoice.paid"]').setValue(false)
    await wrapper.get('form').trigger('submit')
    for (const [field, key] of [[name, 'name_required'], [url, 'url_required'], [events, 'event_required']] as const) {
      expect(field.attributes('aria-invalid')).toBe('true')
      expect(wrapper.get(`[id="${field.attributes('aria-describedby')}"]`).text()).toBe('integrations.webhooks.' + key)
    }
    await name.setValue('Testovací webhook')
    await wrapper.get('input[value="invoice.paid"]').setValue(true)
    await url.setValue('http://example.test/webhooks')
    await wrapper.get('form').trigger('submit')
    expect(wrapper.get(`[id="${url.attributes('aria-describedby')}"]`).text()).toBe('integrations.webhooks.url_invalid')
    expect(create).not.toHaveBeenCalled()

    await url.setValue('https://example.test/webhooks')
    expect(url.attributes('aria-invalid')).toBeUndefined()
    wrapper.unmount()
  })

  it('v editoru existujícího webhooku vysvětlí, jak nastavit jinou adresu', async () => {
    const wrapper = await render({ available: true, policy: 'public', endpoints: [endpoint] })
    await wrapper.findAll('button').find(button => button.text() === 'common.edit')?.trigger('click')
    const url = wrapper.get('input[type="url"]')
    expect(url.attributes()).toHaveProperty('readonly')
    expect(url.attributes()).not.toHaveProperty('required')
    expect(wrapper.get(`[id="${url.attributes('aria-describedby')}"]`).text()).toBe('integrations.webhooks.url_readonly_hint')
    wrapper.unmount()
  })

  it('přenese vybranou šablonu a typy událostí do centrálního odběru', async () => {
    const wrapper = await render({ available: true, policy: 'public', endpoints: [] })
    create.mockResolvedValue({ id: 1, secret: 'whsec_synthetic-test' })
    await wrapper.findAll('button').find(button => button.text() === 'integrations.webhooks.add')?.trigger('click')
    await wrapper.get('input[type="text"]').setValue('Testovací odběr')
    await wrapper.get('input[type="url"]').setValue('https://example.test/events')
    await wrapper.get('input[value="invoice.published"]').setValue(true)
    expect(wrapper.find('input[value="purchase_invoice.approved"]').exists()).toBe(false)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(create).toHaveBeenCalledWith({
      name: 'Testovací odběr', url: 'https://example.test/events', enabled: true,
      template_id: 12, event_types: ['invoice.paid', 'invoice.published'], include_advance: false,
    })
    expect(wrapper.text()).toContain('whsec_synthetic-test')
    wrapper.unmount()
  })

  it('omezení na pravidelnou šablonu vyžaduje jen události vydaných faktur', async () => {
    const wrapper = await render({ available: true, policy: 'public', endpoints: [] }, null)
    await wrapper.findAll('button').find(button => button.text() === 'integrations.webhooks.add')?.trigger('click')
    await wrapper.get('input[value="purchase_invoice.approved"]').setValue(true)
    wrapper.getComponent(SearchableSelect).vm.$emit('update:modelValue', 12)
    await flushPromises()

    expect(wrapper.text()).toContain('integrations.webhooks.template_scope_error')
    expect(wrapper.get('button[type="submit"]').attributes()).toHaveProperty('disabled')
    expect(wrapper.get('input[value="stock_document.posted"]').attributes()).toHaveProperty('disabled')
    await wrapper.get('form').trigger('submit')
    expect(create).not.toHaveBeenCalled()

    await wrapper.get('input[value="purchase_invoice.approved"]').setValue(false)
    expect(wrapper.text()).not.toContain('integrations.webhooks.template_scope_error')
    expect(wrapper.get('button[type="submit"]').attributes()).not.toHaveProperty('disabled')
    wrapper.unmount()
  })
})
