#!/usr/bin/env node

import {existsSync} from 'node:fs';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const workspaceRoot = path.resolve(packageRoot, '../..');
const insideDdev = process.env.IS_DDEV_PROJECT === 'true'
    && typeof process.env.DDEV_COMPOSER_ROOT === 'string';
const workspaceMode = !insideDdev
    && packageRoot === path.join(workspaceRoot, 'plugins/sms-manager')
    && existsSync(path.join(workspaceRoot, '.ddev/config.yaml'));
const sourceVendorRoot = insideDdev
    ? path.join(process.env.DDEV_COMPOSER_ROOT, 'vendor')
    : path.join(packageRoot, 'vendor');

const constituents = [
    {
        id: 'composer-validation',
        family: 'composer-platform-dependencies',
        standalone: ['bash', ['scripts/validate-composer']],
        workspace: ['ddev', ['exec', 'cd plugins/sms-manager && bash scripts/validate-composer']],
    },
    {
        id: 'composer-audit',
        family: 'composer-security',
        standalone: ['bash', ['scripts/composer-audit']],
        workspace: ['ddev', ['exec', 'cd plugins/sms-manager && bash scripts/composer-audit']],
    },
    {
        id: 'php-quality',
        family: 'php-static-style',
        standalone: ['composer', ['ci']],
        workspace: ['ddev', ['exec', 'cd plugins/sms-manager && composer ci']],
    },
    {
        id: 'test-conventions',
        family: 'test-naming-lifecycle',
        standalone: ['php', ['scripts/check-test-conventions.php']],
        workspace: ['ddev', ['exec', 'cd plugins/sms-manager && php scripts/check-test-conventions.php']],
    },
    {
        id: 'disposable-phpunit',
        family: 'php-behavior',
        standalone: ['php', ['tests/Fixtures/Project/run.php', '--no-progress']],
        workspace: ['ddev', ['exec', 'cd plugins/sms-manager && SMS_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT=/var/www/html/vendor php tests/Fixtures/Project/run.php --no-progress']],
        standaloneEnvironment: {
            SMS_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT: sourceVendorRoot,
        },
    },
    {
        id: 'sms-encoding-javascript',
        family: 'sms-encoding-javascript',
        standalone: ['npm', ['run', 'test:encoding']],
    },
    {
        id: 'generated-asset-parity',
        family: 'generated-source-dist',
        standalone: ['npm', ['run', 'test:build-parity']],
    },
    {
        id: 'customer-archive',
        family: 'customer-package',
        standalone: ['php', ['scripts/check-customer-archive.php']],
        workspace: ['ddev', ['exec', 'cd plugins/sms-manager && SMS_MANAGER_ARCHIVE_SOURCE_VENDOR_ROOT=/var/www/html/vendor php scripts/check-customer-archive.php']],
        standaloneEnvironment: {
            SMS_MANAGER_ARCHIVE_SOURCE_VENDOR_ROOT: sourceVendorRoot,
        },
    },
    {
        id: 'pre-commit-hook-regressions',
        family: 'pre-commit-routing',
        standalone: ['npm', ['run', 'test:hook']],
    },
    {
        id: 'orchestration-regressions',
        family: 'aggregate-ci-act',
        standalone: ['npm', ['run', 'test:orchestration']],
    },
];

const argumentsList = process.argv.slice(2);
const listOnly = argumentsList.includes('--list');
const probeIndex = argumentsList.indexOf('--probe');
const probeExecutable = probeIndex === -1 ? null : argumentsList[probeIndex + 1];

if (probeIndex !== -1 && (!probeExecutable || !path.isAbsolute(probeExecutable))) {
    console.error('--probe requires an absolute executable path.');
    process.exit(2);
}

if (listOnly) {
    const format = ([command, commandArguments]) => [command, ...commandArguments].join(' ');
    console.log(JSON.stringify(constituents.map(({id, family, standalone, workspace}) => ({
        id,
        family,
        standalone: format(standalone),
        workspace: format(workspace ?? standalone),
        active: format(workspaceMode && workspace ? workspace : standalone),
    })), null, 2));
    process.exit(0);
}

const signalStatuses = new Map([
    ['SIGHUP', 129],
    ['SIGINT', 130],
    ['SIGTERM', 143],
]);
const signalGracePeriodMs = 2000;
let receivedSignal = null;
let activeChild = null;
let forceKillTimer = null;

function signalProcessGroup(pid, signal) {
    try {
        process.kill(-pid, signal);
    } catch (error) {
        if (error.code !== 'ESRCH') {
            console.error(`Unable to send ${signal} to the active constituent process group: ${error.message}`);
        }
    }
}

function forwardSignal(signal) {
    if (receivedSignal !== null) {
        return;
    }
    receivedSignal = signal;
    if (activeChild?.pid) {
        const pid = activeChild.pid;
        signalProcessGroup(pid, signal);
        forceKillTimer = setTimeout(() => signalProcessGroup(pid, 'SIGKILL'), signalGracePeriodMs);
    }
}

for (const signal of signalStatuses.keys()) {
    process.once(signal, () => forwardSignal(signal));
}

function runConstituent(command, commandArguments, cwd, environment) {
    return new Promise((resolve) => {
        const child = spawn(command, commandArguments, {
            cwd,
            env: environment,
            stdio: 'inherit',
            detached: true,
        });
        activeChild = child;
        let startError = null;
        child.once('error', (error) => {
            startError = error;
        });
        child.once('close', (status, signal) => {
            if (forceKillTimer !== null) {
                clearTimeout(forceKillTimer);
                forceKillTimer = null;
            }
            activeChild = null;
            resolve({status, signal, error: startError, pid: child.pid});
        });
    });
}

for (const constituent of constituents) {
    if (receivedSignal !== null) {
        break;
    }
    let command;
    let commandArguments;
    let cwd = packageRoot;
    let environment = process.env;

    if (probeExecutable !== null) {
        command = probeExecutable;
        commandArguments = [constituent.id, constituent.family];
    } else if (workspaceMode && constituent.workspace) {
        [command, commandArguments] = constituent.workspace;
        cwd = workspaceRoot;
    } else {
        [command, commandArguments] = constituent.standalone;
        environment = {...process.env, ...(constituent.standaloneEnvironment ?? {})};
    }

    console.log(`\n==> ${constituent.id}`);
    const result = await runConstituent(command, commandArguments, cwd, environment);
    if (receivedSignal !== null) {
        if (result.pid) {
            // The direct child has closed. Kill any surviving descendants in its
            // exact process group without waiting on zombie reaping by container PID 1.
            signalProcessGroup(result.pid, 'SIGKILL');
        }
        process.exitCode = signalStatuses.get(receivedSignal);
        break;
    }
    if (result.error) {
        console.error(`${constituent.id} could not start: ${result.error.message}`);
        process.exitCode = 1;
        break;
    }
    if (result.status !== 0) {
        const status = result.status ?? 1;
        console.error(`${constituent.id} failed with exit ${status}.`);
        process.exitCode = status;
        break;
    }
}

if (process.exitCode === undefined) {
    console.log('\nComplete SMS Manager quality gate passed (10 constituents).');
}
