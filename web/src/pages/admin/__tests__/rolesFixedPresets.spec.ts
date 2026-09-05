import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const rolesPage = readFileSync(resolve(process.cwd(), 'src/pages/admin/Roles.vue'), 'utf8')

describe('pevné předdefinované role', () => {
  it('zamyká pouze Superadmin, Admin a Admin Plus', () => {
    expect(rolesPage).toContain("const fixedRoleKeys = new Set(['superadmin', 'admin', 'admin_plus'])")
    expect(rolesPage).toContain('v-if="isFixedRole(role)"')
    expect(rolesPage).toContain('v-if="!isFixedRole(role)"')
  })

  it('zobrazuje popis všech tří předdefinovaných rolí', () => {
    expect(rolesPage).toContain("if (!fixedRoleKeys.has(systemKey ?? '')) return ''")
    expect(rolesPage).toContain('roles.system_descriptions.${systemKey}')
    expect(rolesPage).toContain('roleDescription(role.system_key)')
  })

  it('řadí Admin Plus před Admin', () => {
    expect(rolesPage).toContain('superadmin: 0, admin_plus: 1, admin: 2')
    expect(rolesPage).toContain('v-for="role in orderedRoles"')
  })
})
