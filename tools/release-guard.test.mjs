import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const guard = fileURLToPath(new URL('./release-guard.mjs', import.meta.url));
const hook = fileURLToPath(new URL('../.githooks/pre-push', import.meta.url));

test('release source, version, manual tags and pre-push protection', () => {
  const cwd = mkdtempSync(join(tmpdir(), 'myucto-release-test-'));
  const git = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: 'pipe' }).trim();
  const run = (revision, tag, event = 'push', ref = 'refs/heads/master') => spawnSync(process.execPath, [guard, revision, 'origin/master', tag, event, ref], { cwd, encoding: 'utf8' });
  try {
    git('init', '-b', 'master');
    git('config', 'user.name', 'Release Test');
    git('config', 'user.email', 'release@example.invalid');
    mkdirSync(join(cwd, 'web'));
    mkdirSync(join(cwd, '.github/release-notes'), { recursive: true });
    writeFileSync(join(cwd, 'web/package.json'), '{"name":"myucto-web"}');
    writeFileSync(join(cwd, 'VERSION'), '6.8.0\n');
    writeFileSync(join(cwd, '.github/release-notes/v6.8.0.md'), 'Release');
    git('add', '.');
    git('commit', '-m', 'Synthetic release');
    const master = git('rev-parse', 'HEAD');
    git('update-ref', 'refs/remotes/origin/master', master);
    assert.equal(run('HEAD', 'v6.8.0').status, 0);
    assert.equal(run('HEAD', 'v6.8.1').status, 1);
    assert.equal(run('HEAD', 'edge', 'workflow_dispatch').status, 0);
    assert.equal(run('HEAD', 'edge', 'workflow_dispatch', 'refs/tags/v6.8.0').status, 1);
    assert.equal(run('HEAD', 'test-feature', 'workflow_dispatch').status, 0);
    assert.equal(run('HEAD', 'latest', 'workflow_dispatch').status, 1);
    assert.equal(run('HEAD', '6.8.0', 'workflow_dispatch').status, 1);
    git('checkout', '-b', 'foreign');
    writeFileSync(join(cwd, 'VERSION'), '4.56.4\n');
    writeFileSync(join(cwd, 'web/package.json'), '{"name":"myinvoice-web"}');
    git('add', '.');
    git('commit', '-m', 'Synthetic foreign release');
    const foreign = git('rev-parse', 'HEAD');
    assert.equal(run('HEAD', 'v4.56.4').status, 1);
    assert.equal(run('HEAD', 'edge', 'workflow_dispatch').status, 1);
    git('update-ref', 'refs/remotes/origin/master', foreign);
    assert.equal(run('HEAD', 'v4.56.4').status, 1);
    git('update-ref', 'refs/remotes/origin/master', master);
    const zero = '0'.repeat(40);
    const push = input => spawnSync(process.execPath, [hook, 'origin', 'git@github.com:radekhulan/myucto.git'], { cwd, input, encoding: 'utf8' });
    assert.equal(push(`refs/tags/v6.8.0 ${master} refs/tags/v6.8.0 ${zero}\n`).status, 0);
    assert.equal(push(`refs/tags/v6.8.0 ${master} refs/tags/v6.8.0 ${zero}\nrefs/tags/v4.56.4 ${foreign} refs/tags/v4.56.4 ${zero}\n`).status, 1);
    git('checkout', 'master');
    writeFileSync(join(cwd, 'VERSION'), '6.8.1\n');
    git('add', '.');
    git('commit', '-m', 'Release without notes');
    const next = git('rev-parse', 'HEAD');
    git('update-ref', 'refs/remotes/origin/master', next);
    assert.equal(run('HEAD', 'v6.8.1').status, 1);
    writeFileSync(join(cwd, '.github/release-notes/v6.8.1.md'), 'Release');
    git('add', '.');
    git('commit', '-m', 'Release with notes');
    const ready = git('rev-parse', 'HEAD');
    assert.equal(push(`refs/heads/master ${ready} refs/heads/master ${next}\nrefs/tags/v6.8.1 ${ready} refs/tags/v6.8.1 ${zero}\n`).status, 0);
  } finally {
    rmSync(cwd, { recursive: true, force: true });
  }
});
