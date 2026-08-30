import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Regrese: server vrátí chybu konkrétního pole, ale formulář ukázal jen obecné
 * „Validace selhala". Inline hláška existovala u pěti polí, takže chyba IČO —
 * a stejně tak povinné ulice, města i PSČ — mlčky zapadla a uživatel neměl jak
 * zjistit, co má opravit.
 */
const m = vi.hoisted(() => ({
  create: vi.fn(),
  update: vi.fn(),
  get: vi.fn(),
}))

vi.mock('@/api/clients', () => ({
  clientsApi: {
    create: m.create,
    update: m.update,
    get: m.get,
    findDuplicates: vi.fn(async () => []),
    lookupVies: vi.fn(async () => ({ source: 'error' })),
    lookupBank: vi.fn(async () => ({ source: 'error' })),
    lookupAres: vi.fn(async () => ({})),
  },
  TAX_NUMBER_LABELS: {},
}))

vi.mock('@/api/invoices', () => ({ PAYMENT_METHODS: [] }))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    countries: vi.fn(async () => [{ iso2: 'CZ', name_cs: 'Česko', name_en: 'Czechia' }]),
    currencies: vi.fn(async () => [{ id: 1, code: 'CZK' }]),
  },
}))

vi.mock('@/api/expenseCategories', () => ({ expenseCategoriesApi: { list: vi.fn(async () => []) } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn(async () => []) } }))
vi.mock('@/api/settings', () => ({ settingsApi: { listBrandingProfiles: vi.fn(async () => []) } }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }),
}))
vi.mock('@/composables/useDemoMode', () => ({
  useDemoMode: () => ({ blockDemoMutation: () => false }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry', country_iso2: 'CZ' } }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import ClientForm from '../ClientForm.vue'

async function submitWith(fields: Record<string, string[]>) {
  // Odmítnutí se vyrábí až uvnitř volání, jinak by promise vznikla dřív, než ji
  // kdo zpracuje, a vitest ji ohlásí jako neodchycené odmítnutí.
  m.create.mockImplementation(() => Promise.reject({
    response: { data: { error: { code: 'validation_failed', message: 'Validace selhala', fields } } },
  }))
  const wrapper = mount(ClientForm, { props: { embedded: true } })
  await flushPromises()
  await wrapper.find('form').trigger('submit')
  await flushPromises()
  return wrapper
}

describe('ClientForm — serverové chyby polí', () => {
  // jsdom scrollIntoView neimplementuje; bez náhrady by odskok na chybné pole
  // shodil zpracování odpovědi neodchycenou výjimkou.
  const scrollIntoView = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    Element.prototype.scrollIntoView = scrollIntoView
  })

  it('ukáže chybu IČO u pole, ne jen obecnou hlášku', async () => {
    const wrapper = await submitWith({ ic: ['IČO musí mít 8 číslic'] })

    expect(wrapper.text()).toContain('IČO musí mít 8 číslic')
  })

  it.each(['street', 'city', 'zip', 'phone'])('ukáže chybu pole %s', async (field) => {
    const wrapper = await submitWith({ [field]: [`Chyba pole ${field}`] })

    expect(wrapper.text()).toContain(`Chyba pole ${field}`)
  })

  it('odskočí na první chybné pole, aby ho uživatel od tlačítka Uložit viděl', async () => {
    await submitWith({ ic: ['IČO musí mít 8 číslic'] })

    expect(scrollIntoView).toHaveBeenCalled()
  })

  it('vypíše i chybu pole, které vlastní místo ve formuláři nemá', async () => {
    // Backend validuje i language a currency_default_id; ta pole inline hlášku
    // nemají, takže bez souhrnu u tlačítka by hláška zmizela úplně.
    const wrapper = await submitWith({ language: ['Jazyk musí být cs nebo en'] })

    expect(wrapper.text()).toContain('Jazyk musí být cs nebo en')
  })
})
