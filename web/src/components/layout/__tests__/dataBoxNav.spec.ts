import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const workspaceRoutes = readFileSync(
  resolve(process.cwd(), 'src/router/workspaceRoutes.ts'),
  'utf8',
)

const appLayout = readFileSync(
  resolve(process.cwd(), 'src/components/layout/AppLayout.vue'),
  'utf8',
)

describe('navigace datové schránky', () => {
  // Položka je JEDNA, uvnitř sekce Mzdy — dřív byla zdvojená (Firma pro superadmina,
  // vlastní blok pro neadmin roli) a rozešly se. Sekce Mzdy se staví pro obě role,
  // takže jeden záznam s právem `settings.signing` pokrývá obojí.
  it('nabízí schránku v jedné položce pod Mzdami, s právem na zápis', () => {
    expect(appLayout.match(/to: '\/admin\/databox'/g)).toHaveLength(1)
    expect(appLayout).toContain("label: t('nav.databox')")
    expect(appLayout).toContain("permission: 'settings.signing' as PermissionKey, dividerBefore: true")
  })

  // Brána stojí vedle schránky pod Mzdami: přes ISDS chodí prakticky jen mzdová
  // podání, takže rozdělení mezi Firmu a Systém uživateli nedávalo smysl.
  it('vede odesílací bránu vedle schránky, jen pro superadmina', () => {
    expect(appLayout).toContain("to: '/admin/isds-gateway'")
    expect(appLayout).toContain("label: t('nav.isds_gateway')")
    expect(appLayout).toContain("...(isAdmin ? [{ to: '/admin/isds-gateway'")
  })

  it('skrývá položku bez práva k zápisu stejně jako samotná routa', () => {
    expect(appLayout).toContain("if (path === '/admin/databox') return 'settings.signing'")
    expect(appLayout).toContain("if (item.to.startsWith('/admin/databox')) return auth.canWrite('settings.signing')")
  })

  it('mizí spolu s vypnutými mzdami — schránka slouží mzdovým podáním', () => {
    // Položka je uvnitř bloku `if (payrollEnabled)`, takže s vypnutými mzdami
    // nevznikne — dřív to zajišťoval ternární výraz ve firemní sekci.
    const payrollSection = appLayout.slice(appLayout.indexOf('if (payrollEnabled) {'))
    expect(payrollSection).toContain("to: '/admin/databox'")
    // Skrytá položka v menu nestačí — bez guardu na routě by se na stránku dalo
    // dostat přímou adresou. `requiresPayroll` řeší router/index.ts stejně jako
    // u skladu a mzdových stránek.
    expect(workspaceRoutes).toContain("name: 'admin-databox', component: () => import('@/pages/admin/DataBox.vue'), meta: { requiresPayroll: true }")
  })
})
