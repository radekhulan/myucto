import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const page = readFileSync(resolve(process.cwd(), 'src/components/settings/BankRuleTemplatesAdmin.vue'), 'utf8')

describe('oprávnění katalogu bankovních šablon', () => {
  it('umožní čtení stránky bez editačních akcí a zápis jen přes bank.rules', () => {
    expect(page).toContain("const canWrite = computed(() => auth.canWrite('bank.rules'))")
    expect(page.match(/v-if="canWrite"/g)?.length).toBeGreaterThanOrEqual(4)
    expect(page).toContain(":cta=\"canWrite ? t('bank_template_admin.new') : undefined\"")
    expect(page).toContain('<div class="font-medium text-neutral-800">{{ item.name_cs }}</div>')
    expect(page).toContain('<div class="font-mono text-xs">{{ item.rule_key }}</div>')
    expect(page).toContain('<td v-if="canWrite" class="px-3 py-3">')
  })

  it('po změně firmy zavře formulář a ignoruje pozdní odpověď předchozího načtení', () => {
    expect(page).toContain('watch(() => supplierStore.currentSupplierId')
    expect(page).toContain('const version = ++loadVersion')
    expect(page).toContain('if (version !== loadVersion) return')
    expect(page).toContain('closeForm()')
    expect(page).toContain('page.value = 1')
  })
})
