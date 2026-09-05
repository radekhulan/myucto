import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('Hosting preview environment guard', () => {
  it('renders preview controls only from the exact server development flag', () => {
    const source = readFileSync(join(process.cwd(), 'src/pages/hosting/Hosting.vue'), 'utf8')
    const preview = source.match(/<section[^>]*data-hosting-preview-switch[^>]*>/)?.[0] ?? ''

    expect(preview).toContain('serverDevelopment')
    expect(preview).toContain('auth.isSuperadmin')
    expect(source).toContain('serverDevelopment.value = result.development === true')
    expect(source).toContain('if (!serverDevelopment.value || !auth.isSuperadmin')
    expect(source).not.toContain('import.meta.env')
  })
})
