import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Issue #74: uživatel přechází z jiného software s rozjetou řadou a chce, aby první
 * faktura dostala konkrétní číslo. Backend to uměl (PUT /settings/supplier/invoice-counter),
 * ale v UI k tomu nevedla žádná cesta.
 *
 * Číselné řady mají tři osy (klient > kategorie tržby > dodavatel), takže pole musí být
 * u každé z nich — a protože tři kopie téhož prvku se rozejdou, je to JEDNA sdílená
 * komponenta. Tenhle test hlídá právě tohle: že komponenta existuje, volá ten endpoint,
 * nabízí se jen u šablony s čítačem a je zapojená na všech třech místech.
 */
const field = readFileSync(resolve(process.cwd(), 'src/components/settings/InvoiceCounterField.vue'), 'utf8')
const settingsApi = readFileSync(resolve(process.cwd(), 'src/api/settings.ts'), 'utf8')
const settingsPage = readFileSync(resolve(process.cwd(), 'src/pages/admin/Settings.vue'), 'utf8')
const codebooks = readFileSync(resolve(process.cwd(), 'src/pages/admin/Codebooks.vue'), 'utf8')
const clientForm = readFileSync(resolve(process.cwd(), 'src/pages/clients/ClientForm.vue'), 'utf8')
const cs = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/cs.json'), 'utf8'))
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'src/i18n/en.json'), 'utf8'))

describe('nastavení příštího čísla číselné řady', () => {
  it('API klient volá endpoint počítadla a umí předat scope', () => {
    expect(settingsApi).toContain("'/settings/supplier/invoice-counter'")
    expect(settingsApi).toMatch(/setInvoiceCounter:\s*\(/)
    expect(settingsApi).toContain('next_number: nextNumber')
    expect(settingsApi).toContain('client_id: clientId')
    expect(settingsApi).toContain('revenue_category_id: revenueCategoryId')
  })

  it('po načtení ukáže aktuální příští číslo ze serveru', () => {
    // Bez toho bylo pole po obnovení stránky prázdné a uložené číslo nebylo vidět.
    expect(settingsApi).toMatch(/getInvoiceCounter:\s*\(/)
    expect(field).toContain('settingsApi.getInvoiceCounter(')
    expect(field).toContain('immediate: true')
  })

  it('komponenta se nabízí jen u šablony s čítačem', () => {
    // Bez {C+} je číslo fixní — počítadlo by nemělo co nastavovat.
    expect(field).toContain('hasCounterPlaceholder(template.value)')
    expect(field).toContain('v-if="supported"')
  })

  it('náhled bere ze serveru, nedopočítává se v prohlížeči', () => {
    expect(field).toContain('result.preview')
    expect(field).not.toContain('renderVarsymbolTemplate')
  })

  it('ukládá se samostatně, ne přes společné Uložit', () => {
    // Vlastní tlačítko s vlastní hláškou; saveSupplier() na counter nesmí sahat.
    expect(field).toContain("t('settings.numbering_next_number_apply')")
    expect(settingsPage).not.toMatch(/next_number:\s*supplier\.value/)
  })

  it('tlačítko má ikonu i sémantickou barvu a skupina se zalamuje', () => {
    expect(field).toContain("btnOutline('primary')")
    expect(field).toContain('ICONS.check')
    expect(field).toContain('flex flex-wrap')
  })

  it('varuje před měsíčním resetem u šablony bez {MM}', () => {
    expect(field).toContain("props.period === 'month'")
    expect(field).toContain("!template.value.includes('{MM}')")
  })

  it('je zapojená na všech třech osách číslování', () => {
    expect(settingsPage).toContain('<InvoiceCounterField type="invoice"')
    expect(settingsPage).toContain('<InvoiceCounterField type="proforma"')
    expect(settingsPage).toContain('<InvoiceCounterField type="credit_note"')
    expect(codebooks).toContain(':revenue-category-id="revenueDraft.id"')
    expect(clientForm).toContain(':client-id="clientId"')
  })

  it('u neuložené kategorie ani klienta se pole nenabízí', () => {
    // Endpoint míří na id, které u nového záznamu ještě neexistuje.
    expect(codebooks).toContain('<InvoiceCounterField v-if="revenueDraft.id"')
    expect(clientForm).toContain('<InvoiceCounterField v-if="clientId"')
  })

  it('má kompletní české i anglické texty', () => {
    for (const dict of [cs, en]) {
      for (const key of [
        'numbering_next_number',
        'numbering_next_number_placeholder',
        'numbering_next_number_apply',
        'numbering_next_number_hint',
        'numbering_next_number_period_warning',
        'numbering_next_number_result',
        'numbering_next_number_saved',
        'numbering_next_number_invalid',
      ]) {
        expect(typeof dict.settings[key], key).toBe('string')
        expect(dict.settings[key].length, key).toBeGreaterThan(0)
      }
    }
  })

  it('nápověda říká, proč pole existuje, a nelže o agendě úplnosti', () => {
    expect(cs.settings.numbering_next_number_hint).toContain('jiného software')
    expect(cs.settings.numbering_next_number_hint).toContain('Úplnost číselné řady')
    expect(en.settings.numbering_next_number_hint).toMatch(/another system/i)
  })

  it('literální složené závorky ve varování jsou escapované pro vue-i18n', () => {
    // Neescapované {MM} by vue-i18n bral jako interpolaci a render by tiše spadl.
    for (const dict of [cs, en]) {
      expect(dict.settings.numbering_next_number_period_warning).toContain("{'{MM}'}")
      expect(dict.settings.numbering_next_number_period_warning).not.toMatch(/[^']\{MM\}/)
    }
  })
})
