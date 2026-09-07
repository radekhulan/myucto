import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { Manufacturer } from '@/api/eshop'

// SEC-10 — dvě věci najednou:
//  1) nebezpečná uložená adresa se nikdy nesmí vyrenderovat jako `href`,
//  2) legacy neplatná adresa nesmí zablokovat editaci ostatních polí (regrese 1. kola).

const m = vi.hoisted(() => ({
  listManufacturers: vi.fn(),
  createManufacturer: vi.fn(),
  updateManufacturer: vi.fn(),
  deleteManufacturer: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
}))

vi.mock('@/api/eshop', () => ({
  eshopApi: {
    listManufacturers: m.listManufacturers,
    createManufacturer: m.createManufacturer,
    updateManufacturer: m.updateManufacturer,
    deleteManufacturer: m.deleteManufacturer,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: m.toastWarning }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { plus: 'M0 0', edit: 'M0 0', trash: 'M0 0', check: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))

vi.mock('@/components/ui/Modal.vue', () => ({
  default: { name: 'Modal', props: ['title', 'widthClass'], template: '<div class="modal"><slot /></div>' },
}))

vi.mock('@/components/ui/CodeNameFields.vue', () => ({
  default: {
    name: 'CodeNameFields',
    props: ['code', 'name', 'codeLabel', 'nameLabel', 'editing'],
    emits: ['update:code', 'update:name'],
    template: `<div>
      <input class="f-code" :value="code" @input="$emit('update:code', $event.target.value)" />
      <input class="f-name" :value="name" @input="$emit('update:name', $event.target.value)" />
    </div>`,
  },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import Manufacturers from '@/pages/eshop/Manufacturers.vue'

function make(overrides: Partial<Manufacturer> = {}): Manufacturer {
  return {
    id: 1,
    code: 'ACME',
    name: 'Acme',
    website: 'https://example.com',
    display_order: 10,
    export_eshop: true,
    archived: false,
    ...overrides,
  } as Manufacturer
}

async function mountWith(rows: Manufacturer[]) {
  m.listManufacturers.mockResolvedValue(rows)
  const wrapper = mount(Manufacturers)
  await flushPromises()
  return wrapper
}

describe('Manufacturers — SEC-10 website', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.updateManufacturer.mockResolvedValue(undefined)
    m.createManufacturer.mockResolvedValue(undefined)
  })

  it('platnou http(s) adresu vykreslí jako odkaz', async () => {
    const wrapper = await mountWith([make({ website: 'https://example.com' })])
    const link = wrapper.find('tbody a')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('https://example.com')
    expect(link.attributes('rel')).toContain('noopener')
  })

  it.each([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'java\tscript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    '//evil.com',
    'https://duveryhodna.cz@evil.com',
  ])('nebezpečnou adresu %s vykreslí jako text, nikdy jako href', async (website) => {
    const wrapper = await mountWith([make({ website })])
    const body = wrapper.find('tbody')

    expect(body.find('a').exists()).toBe(false)
    // Pozor: šablona obsahuje vysvětlující komentář se slovem „href", proto
    // hlídáme atribut `href=` (ne holé slovo) — skutečný odkaz se nikdy nevykreslí.
    expect(body.html()).not.toContain('href=')
    // Hodnota se pořád zobrazí (uživatel má vidět, co je uložené) — jen jako text.
    expect(
      body.findAll('span').some((s) => {
        const txt = s.text().toLowerCase()
        return txt.includes('script') || txt.includes('evil')
      }),
    ).toBe(true)
  })

  it('legacy neplatná adresa nezablokuje přejmenování výrobce', async () => {
    const wrapper = await mountWith([make({ website: 'javascript:alert(1)' })])

    await wrapper.find('tbody button[title="common.edit"]').trigger('click')
    await flushPromises()

    // Neplatná hodnota se do formuláře nezkopíruje a uživatel je na to upozorněn.
    const websiteInput = wrapper.find('input[type="url"]')
    expect((websiteInput.element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).toContain('eshop.manufacturers.website_dropped')

    await wrapper.find('input.f-name').setValue('Acme Nove')
    const saveBtn = wrapper.findAll('.modal button').at(-1)!
    await saveBtn.trigger('click')
    await flushPromises()

    expect(m.updateManufacturer).toHaveBeenCalledTimes(1)
    const [id, payload] = m.updateManufacturer.mock.calls[0]
    expect(id).toBe(1)
    expect(payload.name).toBe('Acme Nove')
    // Nebezpečná adresa se neposílá zpátky — backend ji tím pádem z DB smaže.
    expect(payload.website).toBeNull()
  })

  it('platnou adresu ponechá ve formuláři a upozornění nezobrazí', async () => {
    const wrapper = await mountWith([make({ website: 'https://example.com' })])

    await wrapper.find('tbody button[title="common.edit"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('input[type="url"]').element as HTMLInputElement).value).toBe('https://example.com')
    expect(wrapper.text()).not.toContain('eshop.manufacturers.website_dropped')
  })

  it('upozornění se nepřenese z editace do zakládání nového výrobce', async () => {
    const wrapper = await mountWith([make({ website: 'javascript:alert(1)' })])

    await wrapper.find('tbody button[title="common.edit"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('eshop.manufacturers.website_dropped')

    // Zavřít modal a otevřít "nový" — hláška musí zmizet.
    await wrapper.findAll('.modal button')[0].trigger('click')
    await wrapper.find('button.btn-filled').trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('eshop.manufacturers.website_dropped')
  })
})
