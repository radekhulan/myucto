import { spawnSync } from 'node:child_process'
import { realpathSync } from 'node:fs'
import { join } from 'node:path'

const cwd = realpathSync(process.cwd()).replace(
  /^[a-z]:/u,
  drive => drive.toUpperCase(),
)
const vitest = join(cwd, 'node_modules', 'vitest', 'vitest.mjs')
const args = process.argv.slice(2).filter(arg => arg !== '--')
const result = spawnSync(process.execPath, [vitest, ...args], {
  cwd,
  env: {
    ...process.env,
    INIT_CWD: cwd,
    PWD: cwd,
  },
  stdio: 'inherit',
})

if (result.error) {
  throw result.error
}
process.exit(result.status ?? 1)
