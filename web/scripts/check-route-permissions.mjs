// Brána proti zapomenuté permission u nové route (P1.5b, REAL_data_followup_UX.md).
// Guard v router/index.ts je deny-by-default: autentizovaná route BEZ záznamu v
// `routePermissions` (a bez role/self-service výjimky) tiše skončí redirectem
// na homepage — bez chyby, bez logu. Runtime to hlásí `console.warn` jen v dev buildu;
// tenhle skript to posune do buildu (staticky, bez spuštění appky).
//
// Staticky se čte jen struktura `router/index.ts`: přímé děti routy `/` (ty jsou
// requiresAuth), které renderují komponentu (ne redirect), musí mít klíč v
// `routePermissions`, nebo být v některém pevném seznamu rolí či self-service.
//
// Spouští se z `npm run build`; samostatně `npm run check:routes`.

import { readFileSync } from 'node:fs'
import { join, dirname, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const webRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const routerFile = join(webRoot, 'src', 'router', 'index.ts')
const workspaceRoutesFile = join(webRoot, 'src', 'router', 'workspaceRoutes.ts')
const src = readFileSync(routerFile, 'utf8')
const workspaceSrc = readFileSync(workspaceRoutesFile, 'utf8')

// Najde index znaku odpovídajícího otevírací závorce na `open`, přeskakuje řetězce
// (', ", `) a komentáře (// i /* */). Podporuje páry {} [] ().
const PAIRS = { '{': '}', '[': ']', '(': ')' }
function matchBracket(text, open) {
  const stack = [text[open]]
  let i = open + 1
  while (i < text.length && stack.length) {
    const c = text[i]
    if (c === '"' || c === "'" || c === '`') {
      i++
      while (i < text.length && text[i] !== c) i += text[i] === '\\' ? 2 : 1
      i++
      continue
    }
    if (c === '/' && text[i + 1] === '/') { i = text.indexOf('\n', i); if (i < 0) i = text.length; continue }
    if (c === '/' && text[i + 1] === '*') { i = text.indexOf('*/', i); i = i < 0 ? text.length : i + 2; continue }
    if (PAIRS[c]) stack.push(c)
    else if (c === '}' || c === ']' || c === ')') {
      if (PAIRS[stack[stack.length - 1]] !== c) return -1
      stack.pop()
    }
    i++
  }
  return stack.length ? -1 : i - 1
}

// Přímé (top-level) objektové literály `{...}` uvnitř spanu pole [ ... ].
function topLevelObjects(text) {
  const objs = []
  let i = 0
  while (i < text.length) {
    const c = text[i]
    if (c === '"' || c === "'" || c === '`') {
      i++
      while (i < text.length && text[i] !== c) i += text[i] === '\\' ? 2 : 1
      i++
      continue
    }
    if (c === '/' && text[i + 1] === '/') { i = text.indexOf('\n', i); if (i < 0) i = text.length; continue }
    if (c === '/' && text[i + 1] === '*') { i = text.indexOf('*/', i); i = i < 0 ? text.length : i + 2; continue }
    if (c === '{') {
      const end = matchBracket(text, i)
      if (end < 0) { console.error('route-check: nevyvážené závorky v routes'); process.exit(2) }
      objs.push(text.slice(i, end + 1))
      i = end + 1
      continue
    }
    i++
  }
  return objs
}

function blockAfter(marker, text = src) {
  const at = text.indexOf(marker)
  if (at < 0) { console.error(`route-check: nenašel jsem "${marker}"`); process.exit(2) }
  // Od KONCE markeru — `const routes: RouteRecordRaw[] =` má vlastní `[]` v typu
  // a hledání od začátku by otevřelo prázdný blok.
  const open = text.indexOf('[', at + marker.length)
  const close = matchBracket(text, open)
  return text.slice(open + 1, close)
}

// routePermissions — klíče (hodnota je vždy pole [perm, access?]).
const permBlockStart = src.indexOf('const routePermissions')
const permOpen = src.indexOf('{', permBlockStart)
const permBlock = src.slice(permOpen + 1, matchBracket(src, permOpen))
const routePermissions = new Set(
  [...permBlock.matchAll(/(?:^|[,{\s])(['"]?)([A-Za-z][\w-]*)\1\s*:\s*\[/g)].map((m) => m[2]),
)

const quoted = (block) => new Set([...block.matchAll(/'([\w-]+)'/g)].map((m) => m[1]))
const superadminRouteNames = quoted(blockAfter('const superadminRouteNames = new Set('))
const adminPlusRouteNames = quoted(blockAfter('const adminPlusRouteNames = new Set('))
const companyAdminRouteNames = quoted(blockAfter('const companyAdminRouteNames = new Set('))
const selfServiceRouteNames = quoted(blockAfter('const selfServiceRouteNames = new Set('))

// Children routy `/` — hlavní větev s requiresAuth: true.
const childrenBlock = blockAfter('return', workspaceSrc)
// Top-level routy: většina je `public: true`, ale `/setup-mfa` má vlastní
// requiresAuth — a přesně tu brána dřív neviděla, takže smyčka
// home → setup-mfa → home (#5) prošla až do vydání.
const rootBlock = blockAfter('const routes: RouteRecordRaw[] =')

function offenders(block, requireAuthMeta) {
  const found = []
  for (const obj of topLevelObjects(block)) {
    const name = obj.match(/\bname:\s*(['"])([\w-]+)\1/)?.[2]
    if (!name) continue
    if (requireAuthMeta && !/\brequiresAuth:\s*true/.test(obj)) continue
    if (/\bredirect\b/.test(obj)) continue          // pure redirect — guard ji nezahodí
    if (!/\bcomponent:/.test(obj)) continue          // nerenderuje stránku
    if (/\bmfaSetupOnly:\s*true/.test(obj)) continue // výjimku nese meta, viz beforeEach
    if (routePermissions.has(name)) continue
    if (superadminRouteNames.has(name)) continue
    if (adminPlusRouteNames.has(name)) continue
    if (companyAdminRouteNames.has(name)) continue
    if (selfServiceRouteNames.has(name)) continue
    found.push(name)
  }
  return found
}

const missing = [...offenders(childrenBlock, false), ...offenders(rootBlock, true)]

if (missing.length) {
  console.error(`\nroutes: ${missing.length} autentizovan${missing.length === 1 ? 'á route' : 'ých rout'} bez záznamu v routePermissions\n`)
  for (const n of missing) {
    console.error(`  "${n}" — deny-by-default guard ji tiše přesměruje na homepage/portal.`)
    console.error(`    → doplň permission do routePermissions v ${relative(webRoot, routerFile)} (nebo superadminOnly/self-service výjimku).\n`)
  }
  process.exit(1)
}

console.log(`routes OK — ${routePermissions.size} permission záznamů, ${topLevelObjects(childrenBlock).length} rout pod "/".`)
