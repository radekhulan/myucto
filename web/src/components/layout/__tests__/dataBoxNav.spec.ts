import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const appLayout = readFileSync(
  resolve(process.cwd(), 'src/components/layout/AppLayout.vue'),
  'utf8',
)

describe('navigace datové schránky', () => {
  it('nabízí firemní stránku superadminovi i oprávněné neadmin roli', () => {
    expect(appLayout.match(/to: '\/admin\/databox'/g)).toHaveLength(2)
    expect(appLayout).toContain("if (payrollEnabled && auth.canWrite('settings.signing'))")
    expect(appLayout).toContain("label: t('nav.databox')")
    expect(appLayout).toContain("key: 'company_databox'")
    expect(appLayout).toContain("title: t('nav.section_company')")
  })

  it('vede globální bránu zvlášť v systémové sekci', () => {
    expect(appLayout).toContain("to: '/admin/isds-gateway'")
    expect(appLayout).toContain("label: t('nav.isds_gateway')")
    expect(appLayout).toContain("key: 'system_global'")
  })

  it('skrývá položku bez práva k zápisu stejně jako samotná routa', () => {
    expect(appLayout).toContain("if (path === '/admin/databox') return 'settings.signing'")
    expect(appLayout).toContain("if (item.to.startsWith('/admin/databox')) return auth.canWrite('settings.signing')")
  })

  it('mizí spolu s vypnutými mzdami — schránka slouží mzdovým podáním', () => {
    expect(appLayout).toContain("...(payrollEnabled ? [{ to: '/admin/databox'")
    expect(appLayout).toContain("if (payrollEnabled && auth.canWrite('settings.signing'))")
  })
})
