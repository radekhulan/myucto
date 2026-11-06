import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const appLayout = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')
const diagnostics = readFileSync(resolve(process.cwd(), 'src/pages/admin/Diagnostics.vue'), 'utf8')

describe('Vstup do Správy instance v hlavní aplikaci', () => {
  it('v menu není', () => {
    expect(appLayout).not.toContain("to: '/admin/instance'")
    expect(appLayout).not.toContain("to: '/instance-admin'")
  })

  it('vede ze stránky Diagnostika jen superadmina a mimo spravovanou instalaci', () => {
    expect(diagnostics).toContain('to="/admin/instance"')
    expect(diagnostics).toContain('v-if="isAdmin && !auth.isManagedInstallation"')
  })
})
