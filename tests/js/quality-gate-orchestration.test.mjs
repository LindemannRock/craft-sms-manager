import assert from 'node:assert/strict';
import {execFileSync, spawn, spawnSync} from 'node:child_process';
import {chmodSync, cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const pluginRoot = path.resolve(import.meta.dirname, '../..');
const gatePath = path.join(pluginRoot, 'scripts/quality-gate.mjs');
const expectedIds = [
    'composer-validation',
    'composer-audit',
    'php-quality',
    'test-conventions',
    'disposable-phpunit',
    'sms-encoding-javascript',
    'generated-asset-parity',
    'customer-archive',
    'pre-commit-hook-regressions',
    'orchestration-regressions',
];

const signals = [
    ['SIGHUP', 129],
    ['SIGINT', 130],
    ['SIGTERM', 143],
];
const childCloseTimedOut = Symbol('child close timed out');

function delay(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function executable(pathname, source) {
    writeFileSync(pathname, source, {mode: 0o700});
    chmodSync(pathname, 0o700);
}

async function waitFor(predicate, description, timeout = 3000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (predicate()) {
            return;
        }
        await delay(10);
    }
    assert.fail(`Timed out waiting for ${description}.`);
}

function completion(child) {
    return new Promise((resolve, reject) => {
        child.once('error', reject);
        child.once('close', (code, signal) => resolve({code, signal}));
    });
}

async function waitForCompletion(childClosed, timeout = 6000) {
    let timeoutId;
    try {
        return await Promise.race([
            childClosed,
            new Promise((resolve) => {
                timeoutId = setTimeout(() => resolve(childCloseTimedOut), timeout);
            }),
        ]);
    } finally {
        clearTimeout(timeoutId);
    }
}

function conventionalStatus(result) {
    if (result.code !== null) {
        return result.code;
    }
    return new Map(signals).get(result.signal) ?? 1;
}

function processIsActive(pid) {
    if (!Number.isInteger(pid) || pid < 1) {
        return false;
    }
    try {
        process.kill(pid, 0);
    } catch (error) {
        return error.code === 'EPERM';
    }

    // A zombie has terminated and cannot execute or retain resources. Some
    // container PID 1 implementations reap it later, so kill(pid, 0) alone is
    // not a portable liveness assertion.
    try {
        const stat = readFileSync(`/proc/${pid}/stat`, 'utf8');
        return !/^\d+ \(.+\) Z(?: |$)/.test(stat);
    } catch {
        return true;
    }
}

function recordedPid(pathname) {
    try {
        return Number.parseInt(readFileSync(pathname, 'utf8').trim(), 10);
    } catch {
        return null;
    }
}

function terminateFixtureProcesses(pids) {
    for (const pid of pids) {
        if (!Number.isInteger(pid) || pid < 1) {
            continue;
        }
        try {
            process.kill(-pid, 'SIGKILL');
        } catch {}
        try {
            process.kill(pid, 'SIGKILL');
        } catch {}
    }
}

const definitions = (environment = {}) => JSON.parse(execFileSync('node', [gatePath, '--list'], {
    cwd: pluginRoot,
    encoding: 'utf8',
    env: {...process.env, ...environment},
}));

function probeFixture() {
    const root = mkdtempSync(path.join(os.tmpdir(), 'sms-manager-gate-'));
    const probe = path.join(root, 'probe.sh');
    const log = path.join(root, 'constituents.log');
    const resources = path.join(root, 'resources');
    mkdirSync(resources);
    writeFileSync(path.join(resources, 'unrelated-owner-resource'), 'survive\n');
    writeFileSync(probe, `#!/bin/sh
owned="$SMS_MANAGER_GATE_RESOURCE_ROOT/owned-$1"
mkdir "$owned" || exit $?
cleanup() { rm -rf -- "$owned"; }
trap cleanup EXIT HUP INT TERM
printf '%s:%s\\n' "$1" "$2" >> "$SMS_MANAGER_GATE_PROBE_LOG"
if [ "$1" = "$SMS_MANAGER_GATE_FAIL_ID" ]; then exit 71; fi
exit 0
`, {mode: 0o700});
    chmodSync(probe, 0o700);
    return {
        root,
        resources,
        run: (failId = '') => spawnSync('node', [gatePath, '--probe', probe], {
            cwd: pluginRoot,
            encoding: 'utf8',
            env: {...process.env, SMS_MANAGER_GATE_PROBE_LOG: log, SMS_MANAGER_GATE_RESOURCE_ROOT: resources, SMS_MANAGER_GATE_FAIL_ID: failId},
        }),
        ids: () => { try { return readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map((line) => line.split(':')[0]); } catch { return []; } },
        reset: () => writeFileSync(log, ''),
        residue: () => readdirSync(resources).sort(),
        cleanup: () => rmSync(root, {recursive: true, force: true}),
    };
}

function signalFixture(kind, ignoreGracefulSignals = false) {
    const root = mkdtempSync(path.join(os.tmpdir(), `sms-manager-${kind}-signal-`));
    const bin = path.join(root, 'bin');
    const temporaryRoot = path.join(root, 'temporary');
    const resources = path.join(root, 'resources');
    const started = path.join(root, 'started');
    const childPid = path.join(root, 'child.pid');
    const descendantPid = path.join(root, 'descendant.pid');
    const delayedCompletion = path.join(root, 'delayed-completion');
    const constituentLog = path.join(root, 'constituents.log');
    const descendantScript = path.join(root, 'descendant.mjs');
    const unrelatedSentinel = path.join(resources, 'unrelated-owner-resource');
    mkdirSync(bin);
    mkdirSync(temporaryRoot);
    mkdirSync(resources);
    writeFileSync(unrelatedSentinel, 'survive\n');
    writeFileSync(descendantScript, `import {writeFileSync} from 'node:fs';
for (const [signal, status] of [['SIGHUP', 129], ['SIGINT', 130], ['SIGTERM', 143]]) {
    process.once(signal, () => {
        if (process.env.SMS_MANAGER_SIGNAL_IGNORE_GRACEFUL !== '1') process.exit(status);
    });
}
writeFileSync(process.env.SMS_MANAGER_SIGNAL_DESCENDANT_PID, String(process.pid));
setTimeout(() => writeFileSync(process.env.SMS_MANAGER_SIGNAL_DELAYED_COMPLETION, 'completed'), 10000);
`);

    const childBody = `#!/usr/bin/env bash
set -u
owned="$SMS_MANAGER_SIGNAL_RESOURCE_ROOT/owned"
mkdir "$owned" || exit $?
cleanup() { rm -rf -- "$owned"; }
if [ "\${SMS_MANAGER_SIGNAL_IGNORE_GRACEFUL:-0}" = "1" ]; then
    trap '' HUP INT TERM
else
    trap 'cleanup; exit 129' HUP
    trap 'cleanup; exit 130' INT
    trap 'cleanup; exit 143' TERM
fi
trap cleanup EXIT
if [ -n "\${SMS_MANAGER_SIGNAL_CONSTITUENT_LOG:-}" ]; then
    printf '%s\\n' "$1" >> "$SMS_MANAGER_SIGNAL_CONSTITUENT_LOG"
fi
printf '%s\\n' "$$" > "$SMS_MANAGER_SIGNAL_CHILD_PID"
"$SMS_MANAGER_SIGNAL_NODE" "$SMS_MANAGER_SIGNAL_DESCENDANT_SCRIPT" &
descendant=$!
printf started > "$SMS_MANAGER_SIGNAL_STARTED"
wait "$descendant"
`;

    let command;
    let args;
    const environment = {
        ...process.env,
        SMS_MANAGER_SIGNAL_RESOURCE_ROOT: resources,
        SMS_MANAGER_SIGNAL_STARTED: started,
        SMS_MANAGER_SIGNAL_CHILD_PID: childPid,
        SMS_MANAGER_SIGNAL_DESCENDANT_PID: descendantPid,
        SMS_MANAGER_SIGNAL_DELAYED_COMPLETION: delayedCompletion,
        SMS_MANAGER_SIGNAL_DESCENDANT_SCRIPT: descendantScript,
        SMS_MANAGER_SIGNAL_NODE: process.execPath,
        SMS_MANAGER_SIGNAL_IGNORE_GRACEFUL: ignoreGracefulSignals ? '1' : '0',
    };
    if (kind === 'composer') {
        const packageRoot = path.join(root, 'package');
        mkdirSync(path.join(packageRoot, 'scripts'), {recursive: true});
        cpSync(path.join(pluginRoot, 'composer.json'), path.join(packageRoot, 'composer.json'));
        cpSync(path.join(pluginRoot, 'scripts/composer-audit'), path.join(packageRoot, 'scripts/composer-audit'));
        executable(path.join(bin, 'composer'), childBody);
        command = '/bin/bash';
        args = [path.join(packageRoot, 'scripts/composer-audit')];
        environment.PATH = `${bin}:/usr/bin:/bin`;
        environment.TMPDIR = temporaryRoot;
    } else {
        const probe = path.join(bin, 'constituent');
        executable(probe, childBody);
        command = 'node';
        args = [gatePath, '--probe', probe];
        environment.SMS_MANAGER_SIGNAL_CONSTITUENT_LOG = constituentLog;
    }

    let parent = null;
    return {
        root,
        temporaryRoot,
        resources,
        unrelatedSentinel,
        started,
        childPid,
        descendantPid,
        delayedCompletion,
        constituentLog,
        run: () => {
            parent = spawn(command, args, {cwd: pluginRoot, env: environment, detached: true, stdio: 'ignore'});
            return parent;
        },
        cleanup: () => {
            terminateFixtureProcesses([
                parent?.pid,
                recordedPid(childPid),
                recordedPid(descendantPid),
            ]);
            rmSync(root, {recursive: true, force: true});
        },
    };
}

function workflow(steps) {
    return `jobs:\n  quality-gates:\n    container: node:24-bookworm\n    steps:\n${steps.join('\n')}\n`;
}
const checkout = '      - uses: actions/checkout@v6';
const trust = `      - name: Trust checked-out repository\n        run: git config --global --add safe.directory "$GITHUB_WORKSPACE"`;
const gate = `      - name: Complete package quality gate\n        run: composer quality-gate`;

function actFixture(content = workflow([checkout, trust, gate])) {
    const root = mkdtempSync(path.join(os.tmpdir(), 'sms-manager-act-'));
    const bin = path.join(root, 'bin');
    const resources = path.join(root, 'resources');
    const log = path.join(root, 'act.log');
    mkdirSync(path.join(root, '.github/workflows'), {recursive: true});
    mkdirSync(path.join(root, 'scripts'));
    mkdirSync(bin);
    mkdirSync(resources);
    cpSync(path.join(pluginRoot, 'scripts/act-quality-gates'), path.join(root, 'scripts/act-quality-gates'));
    writeFileSync(path.join(root, '.github/workflows/ci.yml'), content);
    writeFileSync(path.join(bin, 'act'), `#!/bin/sh
printf '%s\\n' "$*" > "$SMS_MANAGER_ACT_LOG"
touch "$SMS_MANAGER_ACT_RESOURCES/container" "$SMS_MANAGER_ACT_RESOURCES/network" "$SMS_MANAGER_ACT_RESOURCES/volume"
case " $* " in *" --rm "*) rm -f "$SMS_MANAGER_ACT_RESOURCES"/* ;; esac
exit 73
`, {mode: 0o700});
    chmodSync(path.join(bin, 'act'), 0o700);
    return {
        root,
        log,
        resources,
        run: () => spawnSync('/bin/bash', ['scripts/act-quality-gates'], {cwd: root, encoding: 'utf8', env: {...process.env, PATH: `${bin}:/usr/bin:/bin`, SMS_MANAGER_ACT_LOG: log, SMS_MANAGER_ACT_RESOURCES: resources}}),
        cleanup: () => rmSync(root, {recursive: true, force: true}),
    };
}

test('aggregate declares every package constituent exactly once', () => {
    const declared = definitions();
    assert.deepEqual(declared.map(({id}) => id), expectedIds);
    assert.equal(new Set(declared.map(({family}) => family)).size, expectedIds.length);
    assert.doesNotMatch(JSON.stringify(declared), /php-8[245]|postgres|browser|live-provider/i);
});

test('an active DDEV runtime executes constituents locally without recursive DDEV calls', () => {
    const declared = definitions({IS_DDEV_PROJECT: 'true', DDEV_COMPOSER_ROOT: '/var/www/html'});
    for (const constituent of declared) {
        assert.doesNotMatch(constituent.active, /^ddev\s/);
    }
    assert.equal(declared.find(({id}) => id === 'php-quality').active, 'composer ci');
    assert.equal(declared.find(({id}) => id === 'disposable-phpunit').active, 'php tests/Fixtures/Project/run.php --no-progress');
});

test('successful aggregate runs the canonical fail-fast order without residue', () => {
    const current = probeFixture();
    try {
        const result = current.run();
        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(current.ids(), expectedIds);
        assert.deepEqual(current.residue(), ['unrelated-owner-resource']);
    } finally { current.cleanup(); }
});

test('Composer security wrapper forwards operating-system signals and awaits its process tree', async (context) => {
    for (const [signal, expectedStatus] of signals) {
        await context.test(signal, async () => {
            const current = signalFixture('composer');
            try {
                const parent = current.run();
                const resultPromise = completion(parent);
                await waitFor(() => existsSync(current.started), 'the Composer child to start');
                await waitFor(
                    () => readdirSync(current.temporaryRoot).some((name) => name.startsWith('sms-manager-composer-audit.')),
                    'the owned Composer security root',
                );
                await waitFor(() => existsSync(current.childPid) && existsSync(current.descendantPid), 'the Composer process records');
                const child = recordedPid(current.childPid);
                const descendant = recordedPid(current.descendantPid);
                assert.equal(processIsActive(child), true);
                assert.equal(processIsActive(descendant), true);

                process.kill(parent.pid, signal);
                const result = await waitForCompletion(resultPromise);
                assert.notEqual(result, childCloseTimedOut, 'the Composer wrapper exceeded its signal deadline');
                assert.equal(conventionalStatus(result), expectedStatus);
                assert.equal(result.signal, null, 'the wrapper must return its conventional signal status after waiting');
                await waitFor(() => !processIsActive(child), 'the Composer child to terminate');
                await waitFor(() => !processIsActive(descendant), 'the Composer descendant to terminate');

                assert.equal(existsSync(current.delayedCompletion), false);
                assert.deepEqual(readdirSync(current.temporaryRoot), []);
                assert.deepEqual(readdirSync(current.resources), ['unrelated-owner-resource']);
                assert.equal(readFileSync(current.unrelatedSentinel, 'utf8'), 'survive\n');
            } finally {
                current.cleanup();
            }
        });
    }
});

test('aggregate forwards operating-system signals and awaits its active constituent tree', async (context) => {
    for (const [signal, expectedStatus] of signals) {
        await context.test(signal, async () => {
            const current = signalFixture('gate');
            try {
                const parent = current.run();
                const resultPromise = completion(parent);
                await waitFor(() => existsSync(current.started), 'the probe constituent to start');
                await waitFor(() => existsSync(current.childPid) && existsSync(current.descendantPid), 'the probe process records');
                const child = recordedPid(current.childPid);
                const descendant = recordedPid(current.descendantPid);
                assert.equal(processIsActive(child), true);
                assert.equal(processIsActive(descendant), true);

                process.kill(parent.pid, signal);
                const result = await waitForCompletion(resultPromise);
                assert.notEqual(result, childCloseTimedOut, 'the aggregate exceeded its signal deadline');
                assert.equal(conventionalStatus(result), expectedStatus);
                assert.equal(result.signal, null, 'the aggregate must return its conventional signal status after waiting');
                await waitFor(() => !processIsActive(child), 'the probe constituent to terminate');
                await waitFor(() => !processIsActive(descendant), 'the probe descendant to terminate');

                assert.equal(existsSync(current.delayedCompletion), false);
                assert.deepEqual(readFileSync(current.constituentLog, 'utf8').trim().split('\n'), [expectedIds[0]]);
                assert.deepEqual(readdirSync(current.resources), ['unrelated-owner-resource']);
                assert.equal(readFileSync(current.unrelatedSentinel, 'utf8'), 'survive\n');
            } finally {
                current.cleanup();
            }
        });
    }
});

test('signal escalation is bounded when the active process tree ignores graceful termination', async (context) => {
    for (const kind of ['composer', 'gate']) {
        await context.test(kind, async () => {
            const current = signalFixture(kind, true);
            try {
                const parent = current.run();
                const resultPromise = completion(parent);
                await waitFor(() => existsSync(current.started), `${kind} resistant child to start`);
                await waitFor(() => existsSync(current.childPid) && existsSync(current.descendantPid), `${kind} resistant process records`);
                const child = recordedPid(current.childPid);
                const descendant = recordedPid(current.descendantPid);

                process.kill(parent.pid, 'SIGTERM');
                const result = await waitForCompletion(resultPromise);
                assert.notEqual(result, childCloseTimedOut, `${kind} exceeded its forced-termination deadline`);
                assert.equal(conventionalStatus(result), 143);
                await waitFor(() => !processIsActive(child), `${kind} resistant child to terminate`);
                await waitFor(() => !processIsActive(descendant), `${kind} resistant descendant to terminate`);
                assert.equal(existsSync(current.delayedCompletion), false);
                assert.equal(readFileSync(current.unrelatedSentinel, 'utf8'), 'survive\n');
                if (kind === 'composer') {
                    assert.deepEqual(readdirSync(current.temporaryRoot), []);
                }
            } finally {
                current.cleanup();
            }
        });
    }
});

test('every constituent failure returns its status, stops later work, and cleans only owned resources', async (context) => {
    const current = probeFixture();
    try {
        for (const [index, id] of expectedIds.entries()) {
            await context.test(id, () => {
                current.reset();
                const result = current.run(id);
                assert.equal(result.status, 71, `${id}\n${result.stderr}`);
                assert.deepEqual(current.ids(), expectedIds.slice(0, index + 1));
                assert.match(result.stderr, new RegExp(`${id} failed with exit 71`));
                assert.deepEqual(current.residue(), ['unrelated-owner-resource']);
            });
        }
    } finally { current.cleanup(); }
});

test('an unstartable constituent makes the aggregate nonzero', () => {
    const result = spawnSync('node', [gatePath, '--probe', path.join(os.tmpdir(), 'missing-sms-manager-probe')], {cwd: pluginRoot, encoding: 'utf8'});
    assert.equal(result.status, 1);
    assert.match(result.stderr, /could not start/);
});

test('CI and Act use only the canonical aggregate authority', () => {
    const ci = readFileSync(path.join(pluginRoot, '.github/workflows/ci.yml'), 'utf8');
    const act = readFileSync(path.join(pluginRoot, 'scripts/act-quality-gates'), 'utf8');
    assert.equal((ci.match(/run:\s+composer quality-gate/g) ?? []).length, 1);
    assert.equal((ci.match(/safe\.directory/g) ?? []).length, 1);
    assert.doesNotMatch(ci, /safe\.directory[^\n]*\*/);
    assert.ok(ci.indexOf('actions/checkout@v6') < ci.indexOf('safe.directory "$GITHUB_WORKSPACE"'));
    assert.ok(ci.indexOf('safe.directory "$GITHUB_WORKSPACE"') < ci.indexOf('run: composer quality-gate'));
    assert.doesNotMatch(ci, /run:\s+composer (?:phpstan|check-cs|test|ci:full|audit)/);
    assert.match(ci, /php-version:\s+'8\.3'/);
    assert.match(ci, /npm ci --prefix src\/web\/assets/);
    assert.match(act, /-j quality-gates/);
    assert.match(act, /^\s*--rm\s*$/m);
});

test('Act rejects invalid trust or aggregate contracts before launch', async (context) => {
    const invalid = [
        ['missing trust', workflow([checkout, gate]), /trust exactly/],
        ['duplicate trust', workflow([checkout, trust, trust, gate]), /trust exactly/],
        ['wildcard trust', workflow([checkout, trust, '      - run: git config --global --add safe.directory "*"', gate]), /wildcard/],
        ['trust before checkout', workflow([trust, checkout, gate]), /after checkout/],
        ['duplicate gate', workflow([checkout, trust, gate, gate]), /exactly once/],
    ];
    for (const [name, content, error] of invalid) {
        await context.test(name, () => {
            const current = actFixture(content);
            try {
                const result = current.run();
                assert.notEqual(result.status, 0);
                assert.match(result.stderr, error);
                assert.equal(existsSync(current.log), false);
                assert.deepEqual(readdirSync(current.resources), []);
            } finally { current.cleanup(); }
        });
    }
});

test('controlled Act failure returns the exact status and removes run-owned resources', () => {
    const current = actFixture();
    try {
        const result = current.run();
        assert.equal(result.status, 73, result.stderr);
        assert.match(readFileSync(current.log, 'utf8'), /(?:^|\s)--rm(?:\s|$)/);
        assert.deepEqual(readdirSync(current.resources), []);
    } finally { current.cleanup(); }
});
