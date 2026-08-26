// Brána proti odkazům na neexistující kapitoly a kotvy manuálu.
//
// Ověřuje kontextovou nápovědu ve frontendu, přímé odkazy `/manual?ch=…`
// a lokální Markdown odkazy mezi kapitolami. Kotvy počítá stejnými pravidly
// jako `tools/generateManualHtml.php`.
//
// Spouští se z `npm run build`; samostatně `npm run check:manual`.

import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { basename, dirname, extname, join, relative, resolve } from 'node:path'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const webRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const repoRoot = join(webRoot, '..')
const manualDir = join(repoRoot, 'manual')
const srcDir = join(webRoot, 'src')

// Docker image se staví jen z `web/` (`COPY web/ ./` v Dockerfile.alpine), takže tam
// adresář manuálu vůbec není a kontrola nemá co porovnávat. Bez téhle větve build
// image spadl na ENOENT — což se stalo hned při prvním vydání po zavedení guardu.
//
// Chybějící adresář se tedy přeskakuje, PRÁZDNÝ ale ne (o pár řádků níž): v repozitáři
// znamená nula kapitol rozbitý sken, kdežto tady jde o legitimní stav. Kontrola takhle
// nepřichází o smysl — vývojář i CI ji pouštějí nad celým repozitářem.
if (!existsSync(manualDir)) {
  console.log('check-manual-links: manual/ není v tomhle kontextu (build image) — přeskočeno.')
  process.exit(0)
}

// Řetězce, které vypadají jako název kapitoly, ale nejsou jí — hodnoty číselníků
// apod. Whitelist je záměrně na přesnou hodnotu, ne na soubor: jinak by v tom
// souboru propadl i skutečně rozbitý odkaz.
const NOT_A_CHAPTER = new Set(['9_ending'])

const manualFiles = readdirSync(manualDir)
  .filter(file => file.endsWith('.md'))
  .map(file => join(manualDir, file))
const chapterFiles = manualFiles.filter(file => /^\d+[a-z]?_.+\.md$/.test(basename(file)))
const chapters = new Set(chapterFiles.map(file => basename(file, '.md')))

if (chapters.size === 0) {
  console.error('check-manual-links: v manual/ nejsou žádné kapitoly — sken je rozbitý, ne kód.')
  process.exit(1)
}

function walk(dir) {
  const out = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === '__tests__') continue
      out.push(...walk(full))
    } else if (/\.(ts|vue)$/.test(entry.name)) {
      out.push(full)
    }
  }
  return out
}

function markdownSlug(value) {
  return value
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{M}/gu, '')
    // Autoritativni je PHP mdSlug() v tools/generateManualHtml.php, protoze /manual
    // servíruje manual/index.php. Jeho iconv //TRANSLIT prevede pomlcky na '-';
    // holy strip nize by je zahodil a kotva by se rozesla s realnym HTML.
    .replace(/[‐-―−]/g, '-')
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/[\s_]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
}

function markdownAnchors(file) {
  const anchors = new Set()
  let inFence = false

  for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
    if (/^\s*```/.test(line)) {
      inFence = !inFence
      continue
    }
    if (inFence) continue

    const heading = line.match(/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/)
    if (!heading) continue
    const anchor = markdownSlug(heading[1])
    if (anchor !== '') anchors.add(anchor)
  }

  return anchors
}

function decoded(value) {
  try {
    return decodeURIComponent(value)
  } catch {
    return value
  }
}

const anchorsByFile = new Map(manualFiles.map(file => [resolve(file), markdownAnchors(file)]))
const REFERENCE = /(?:'|")(\d+[a-z]?_[A-Za-z0-9_]+)(?:'|")|ch=(\d+[a-z]?_[A-Za-z0-9_]+)/g
const MARKDOWN_LINK = /(!?)\[[^\]]*\]\(([^)]+)\)/g
const MANUAL_QUERY = /\/manual\?ch=(\d+[a-z]?_[A-Za-z0-9_]+)(?:#([A-Za-z0-9_-]+))?/g
const MANUAL_FILE = /manual[\\/](\d+[a-z]?_[A-Za-z0-9_]+\.md)(?:#([A-Za-z0-9_-]+))?/g
const broken = []
let frontendSeen = 0
let markdownSeen = 0
let repositorySeen = 0

function trackedTextFiles() {
  try {
    const listed = execFileSync('git', ['ls-files', '-z'], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    })
    return listed
      .split('\0')
      .filter(Boolean)
      .filter(file => /\.(?:md|php|ts|vue|js|mjs|json|ya?ml|ps1|cmd|txt)$/i.test(file))
      .map(file => join(repoRoot, file))
      .filter(file => existsSync(file))
  } catch {
    return [...walk(srcDir), ...manualFiles]
  }
}

function validateChapterAnchor(kind, file, line, chapter, anchor = '') {
  repositorySeen++
  if (!chapters.has(chapter)) {
    broken.push({ kind, file: relative(repoRoot, file), line, ref: chapter })
    return
  }
  if (anchor === '') return
  const target = resolve(manualDir, `${chapter}.md`)
  const anchors = anchorsByFile.get(target) ?? markdownAnchors(target)
  if (!anchors.has(decoded(anchor).toLowerCase())) {
    broken.push({
      kind: `${kind} — kotva`,
      file: relative(repoRoot, file),
      line,
      ref: `${chapter}#${anchor}`,
    })
  }
}

for (const file of walk(srcDir)) {
  const src = readFileSync(file, 'utf8')
  const lines = src.split(/\r?\n/)
  lines.forEach((line, i) => {
    for (const match of line.matchAll(REFERENCE)) {
      const ref = match[1] ?? match[2]
      if (NOT_A_CHAPTER.has(ref)) continue
      frontendSeen++
      if (!chapters.has(ref)) {
        broken.push({ kind: 'kapitola frontendu', file: relative(repoRoot, file), line: i + 1, ref })
      }
    }
  })
}

for (const file of trackedTextFiles()) {
  const lines = readFileSync(file, 'utf8').split(/\r?\n/)
  lines.forEach((line, index) => {
    for (const match of line.matchAll(MANUAL_QUERY)) {
      validateChapterAnchor(
        'URL manuálu v repozitáři',
        file,
        index + 1,
        match[1],
        match[2] ?? '',
      )
    }
    for (const match of line.matchAll(MANUAL_FILE)) {
      const chapter = basename(match[1], '.md')
      validateChapterAnchor(
        'soubor manuálu v repozitáři',
        file,
        index + 1,
        chapter,
        match[2] ?? '',
      )
    }
  })
}

for (const file of manualFiles) {
  const lines = readFileSync(file, 'utf8').split(/\r?\n/)
  let inFence = false

  lines.forEach((line, index) => {
    if (/^\s*```/.test(line)) {
      inFence = !inFence
      return
    }
    if (inFence) return

    for (const match of line.matchAll(MARKDOWN_LINK)) {
      if (match[1] === '!') continue

      const rawHref = match[2].trim().replace(/\s+["'][^"']*["']\s*$/, '')
      if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(rawHref) || rawHref.startsWith('/')) continue

      const hashAt = rawHref.indexOf('#')
      const rawTarget = hashAt >= 0 ? rawHref.slice(0, hashAt) : rawHref
      const rawAnchor = hashAt >= 0 ? rawHref.slice(hashAt + 1) : ''
      const targetName = decoded(rawTarget)
      const anchor = decoded(rawAnchor).toLowerCase()

      if (targetName === '' && anchor === '') continue
      if (targetName !== '' && extname(targetName).toLowerCase() !== '.md') {
        if (/^\d+[a-z]?_/i.test(targetName)) {
          markdownSeen++
          broken.push({ kind: 'Markdown cíl bez .md', file: relative(repoRoot, file), line: index + 1, ref: rawHref })
        }
        continue
      }

      const target = resolve(dirname(file), targetName || basename(file))
      markdownSeen++
      if (!existsSync(target)) {
        broken.push({ kind: 'Markdown cíl', file: relative(repoRoot, file), line: index + 1, ref: rawHref })
        continue
      }

      if (anchor !== '') {
        const anchors = anchorsByFile.get(target) ?? markdownAnchors(target)
        if (!anchors.has(anchor)) {
          broken.push({ kind: 'Markdown kotva', file: relative(repoRoot, file), line: index + 1, ref: rawHref })
        }
      }
    }
  })
}

if (frontendSeen === 0) {
  console.error('check-manual-links: nenašel jsem ani jeden frontendový odkaz na kapitolu — sken je rozbitý, ne kód.')
  process.exit(1)
}

if (markdownSeen === 0) {
  console.error('check-manual-links: nenašel jsem ani jeden Markdown odkaz — sken je rozbitý, ne kód.')
  process.exit(1)
}

if (repositorySeen === 0) {
  console.error('check-manual-links: nenašel jsem žádný přímý odkaz v repozitáři — sken je rozbitý, ne kód.')
  process.exit(1)
}

if (broken.length > 0) {
  console.error(`check-manual-links: ${broken.length} neplatných odkazů manuálu.\n`)
  for (const item of broken) {
    let suggestion = ''
    if (item.kind === 'kapitola frontendu') {
      const stem = item.ref.replace(/^\d+[a-z]?_/, '')
      const replacement = [...chapters].find(chapter => chapter.replace(/^\d+[a-z]?_/, '') === stem)
      if (replacement) suggestion = `  →  ${replacement}`
    }
    console.error(`  ${item.file}:${item.line}  [${item.kind}] ${item.ref}${suggestion}`)
  }
  console.error('\nKapitoly se přečíslovávají přes tools/renumberManual.php; frontendové odkazy je nutné opravit ručně.')
  process.exit(1)
}

console.log(
  `check-manual-links: OK — ${frontendSeen} frontendových, ${markdownSeen} Markdown `
  + `a ${repositorySeen} přímých odkazů v repozitáři.`,
)
