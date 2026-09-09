import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { realpathSync } from 'node:fs';

const git = (...args) => execFileSync('git', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();

export function validateRelease(revision, master, tag, event = 'push', ref = '') {
  const commit = git('rev-parse', '--verify', `${revision}^{commit}`);
  const masterCommit = git('rev-parse', '--verify', `${master}^{commit}`);
  git('merge-base', '--is-ancestor', commit, masterCommit);
  const version = git('show', `${commit}:VERSION`);
  const pkg = JSON.parse(git('show', `${commit}:web/package.json`));
  if (pkg.name !== 'myucto-web' || !/^6\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(version)) {
    throw new Error('Release must contain MyUcto version 6.x.y.');
  }
  if (event === 'push') {
    if (tag !== `v${version}`) throw new Error('Tag does not match VERSION.');
    git('cat-file', '-e', `${commit}:.github/release-notes/${tag}.md`);
  } else if (event === 'workflow_dispatch') {
    if (ref !== 'refs/heads/master') throw new Error('Manual builds must target the master branch.');
    if (commit !== masterCommit) throw new Error('Manual builds must use current master.');
    if (!/^(edge|test)(-[a-z0-9][a-z0-9.-]*)?$/.test(tag)) {
      throw new Error('Manual image tags must be edge, test, or use their prefix.');
    }
  } else {
    throw new Error('Unsupported release event.');
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) {
  try {
    validateRelease(...process.argv.slice(2));
    console.log('Release guard OK');
  } catch (error) {
    console.error(`Release blocked: ${error.message}`);
    process.exitCode = 1;
  }
}
