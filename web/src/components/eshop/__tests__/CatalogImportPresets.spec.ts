import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import type { CatalogImportConfig, CatalogImportPreset } from '@/api/catalogImport'
import CatalogImportPresets from '../CatalogImportPresets.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const config: CatalogImportConfig = {
  identity: 'sku',
  source_key: null,
  mode: 'upsert',
  mapping: { sku: 'kod' },
  blank: 'preserve',
  operations: {},
  reader: { encoding: 'UTF-8', delimiter: ';', sheet: 0 },
}

const presets: CatalogImportPreset[] = [
  {
    id: 'abra-flexi-cenik-csv-v1', system: 'abra_flexi', version: 1, format: 'csv',
    documentation_url: 'https://example.test/abra', schema_evidence: 'public_demo_header',
    version_export_verified: false, supported_columns: [{ source: 'kod', target: 'sku' }], config,
  },
  {
    id: 'pohoda-zasoby-xlsx-v1', system: 'pohoda', version: 1, format: 'xlsx',
    documentation_url: 'https://example.test/pohoda', schema_evidence: 'documented_ui_columns',
    version_export_verified: false, supported_columns: [{ source: 'Kód', target: 'sku' }], config,
  },
]

describe('CatalogImportPresets', () => {
  it('shows only presets compatible with the uploaded file and emits the selected preset', async () => {
    const wrapper = mount(CatalogImportPresets, {
      props: { presets: [...presets], sourceFormat: 'csv' },
    })

    expect(wrapper.find('[data-test="catalog-import-preset-abra-flexi-cenik-csv-v1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="catalog-import-preset-pohoda-zasoby-xlsx-v1"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('kod')
    expect(wrapper.text()).toContain('eshop.import2.fields.sku')

    await wrapper.get('[data-test="apply-preset-abra-flexi-cenik-csv-v1"]').trigger('click')
    expect(wrapper.emitted('apply')).toEqual([[presets[0]]])
  })

  it('disables preset application while the parent form is busy', () => {
    const wrapper = mount(CatalogImportPresets, {
      props: { presets: [...presets], sourceFormat: 'xlsx', disabled: true },
    })

    expect(wrapper.find('[data-test="catalog-import-preset-abra-flexi-cenik-csv-v1"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="apply-preset-pohoda-zasoby-xlsx-v1"]').attributes('disabled')).toBeDefined()
  })
})
