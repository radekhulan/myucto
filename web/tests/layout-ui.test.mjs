import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

test('mobile navigation links the signed-in user to the profile', async () => {
  const layout = await readFile(new URL('components/layout/AppLayout.vue', root), 'utf8')
  const mobileFooter = layout.match(/<!-- Mobile only: profil[\s\S]*?<\/aside>/)?.[0] || ''

  assert.match(mobileFooter, /<WorkspaceNavLink[\s\S]*?to="\/profile\/password"/)
  assert.match(mobileFooter, /@click="mobileOpen = false"/)
  assert.match(mobileFooter, /\{\{ auth\.user\?\.name \}\}/)
  assert.match(mobileFooter, /\['grid gap-2', canLockSession \? 'grid-cols-2' : 'grid-cols-1'\]/)
  assert.match(mobileFooter, /v-if="canLockSession"[\s\S]*?sessionSecurity\.lock[\s\S]*?logout/)
})

test('responsive utility breakpoints follow the nearest workspace panel', async () => {
  const styles = await readFile(new URL('styles/main.css', root), 'utf8')
  const pane = await readFile(new URL('components/workspace/WorkspacePane.vue', root), 'utf8')
  const host = await readFile(new URL('components/workspace/WorkspaceHost.vue', root), 'utf8')

  for (const [name, width] of [['sm', 40], ['md', 48], ['lg', 64], ['xl', 80], ['2xl', 96]]) {
    assert.match(styles, new RegExp(`@custom-variant ${name} \\(@container workspace \\(width >= ${width}rem\\)\\);`))
  }
  assert.match(styles, /body\s*\{[\s\S]*?container-name:\s*workspace;[\s\S]*?container-type:\s*inline-size;/)
  assert.match(pane, /'workspace-pane-container overflow-hidden bg-surface'/)
  assert.match(pane, /\.workspace-pane-container\s*\{[\s\S]*?container-name:\s*workspace;[\s\S]*?container-type:\s*inline-size;/)
  assert.match(host, /const primaryRouter = useRouter\(\)/)
  assert.match(host, /workspace\.resetLayout\(1, primaryRouter\.currentRoute\.value\.fullPath\)/)
})

test('clearing a pane parks its window and clears the visible route', async () => {
  const pane = await readFile(new URL('components/workspace/WorkspacePane.vue', root), 'utf8')
  const host = await readFile(new URL('components/workspace/WorkspaceHost.vue', root), 'utf8')

  assert.match(pane, /clear:\s*\(\) => \{\s*manager\?\.parkPane\(props\.paneId\)\s*workspace\.updatePane\(props\.paneId, \{ fullPath: null, title: null, canGoBack: false, canGoForward: false \}\)/)
  assert.match(host, /const target = fullPath \?\? '\/'/)
  assert.match(host, /await mirrorWindowLocation\(primaryRouter, target, id,/)
})

test('company settings keep the save action available above the long form', async () => {
  const settings = await readFile(new URL('pages/admin/Settings.vue', root), 'utf8')
  const header = settings.match(/<div class="mb-4 flex flex-wrap items-start justify-between gap-3">[\s\S]*?<\/div>\s*<nav/)?.[0] || ''

  assert.match(header, /@click="saveSupplier"/)
  assert.match(header, /settings\.save_supplier/)
})
