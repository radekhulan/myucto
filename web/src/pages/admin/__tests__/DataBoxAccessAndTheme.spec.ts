import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const dataBox = readFileSync(resolve(process.cwd(), 'src/pages/admin/DataBox.vue'), 'utf8')
const gateway = readFileSync(
  resolve(process.cwd(), 'src/components/settings/IsdsGatewayRegistrations.vue'),
  'utf8',
)
const mainCss = readFileSync(resolve(process.cwd(), 'src/styles/main.css'), 'utf8')

describe('firemní přístup k datové schránce', () => {
  it('negarantuje metody gateway, které autoritativně vybírá až ISDS', () => {
    expect(dataBox).not.toContain("['password', 'mobileKey', 'identity', 'sms', 'certificate', 'securityCode']")
    expect(dataBox).toContain('databox.gateway.methodsByIsds')
    expect(dataBox).toContain('databox.access.certificateSettings')
    expect(dataBox).toContain('supplierStore.currentSupplier?.company_name')
  })

  it('nepoužívá v invertovaném dark tématu světlé neutral tokeny jako pozadí', () => {
    expect(dataBox).not.toMatch(/bg-white|dark:bg-neutral-(?:800|900)|dark:border-neutral-700/)
    expect(gateway).not.toMatch(/bg-white|dark:bg-neutral-(?:800|900)|dark:border-neutral-700/)
    expect(dataBox).not.toMatch(/dark:border-(?:primary|warning|success|danger)-/)
    expect(gateway).not.toMatch(/dark:border-(?:primary|warning|success|danger)-/)
    expect(dataBox).toContain('bg-surface')
    expect(gateway).toContain('bg-surface')
    expect(dataBox).toMatch(/v-model="environment"[\s\S]*?bg-surface[\s\S]*?text-neutral-900/)
    expect(mainCss).toMatch(/\.form-input,[\s\S]*?\.form-select[\s\S]*?background-color: var\(--color-surface\)/)
    expect(mainCss).toContain('border-color: var(--color-primary-500)')
  })

  it('zadává příjemce názvem a kód odvozuje slugifikací', () => {
    const name = dataBox.indexOf("databox.recipients.name")
    const code = dataBox.indexOf("databox.recipients.code")
    expect(name).toBeGreaterThan(-1)
    expect(code).toBeGreaterThan(name)
    expect(dataBox).toContain('recipientCodeSlug.fromName')
    expect(dataBox).toContain('recipientCodeSlug.markManual')
  })

  it('nevyžaduje u příjemce odkaz na zdroj', () => {
    expect(dataBox).not.toContain('databox.errors.sourceRequired')
    expect(dataBox).toContain('source_url: recipientSource.value.trim() || null')
  })
})
