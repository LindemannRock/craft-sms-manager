#!/usr/bin/env node

import assert from 'node:assert/strict';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const packageRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const assetRoot = path.join(packageRoot, 'src/web/assets');
const temporaryRoot = mkdtempSync(path.join(os.tmpdir(), 'sms-manager-build-parity-'));
let cleaned = false;

function cleanup() {
    if (!cleaned) {
        rmSync(temporaryRoot, {recursive: true, force: true});
        cleaned = true;
    }
}

for (const [signal, status] of [['SIGHUP', 129], ['SIGINT', 130], ['SIGTERM', 143]]) {
    process.once(signal, () => {
        cleanup();
        process.exit(status);
    });
}

try {
    const terser = path.join(assetRoot, 'node_modules/.bin/terser');
    const assets = ['analytics', 'encoding'];
    for (const asset of assets) {
        const source = path.join(assetRoot, asset, 'src', `${asset}.js`);
        const generated = path.join(temporaryRoot, `${asset}.js`);
        const expected = path.join(assetRoot, asset, 'dist', `${asset}.js`);
        const result = spawnSync(terser, [source, '-o', generated, '-c', '-m'], {
            cwd: packageRoot,
            encoding: 'utf8',
        });
        if (result.error) {
            throw result.error;
        }
        if (result.status !== 0) {
            process.stderr.write(result.stdout ?? '');
            process.stderr.write(result.stderr ?? '');
            process.exitCode = result.status ?? 1;
            break;
        }
        assert.deepEqual(readFileSync(generated), readFileSync(expected), `${asset} dist is stale`);
    }
    if (!process.exitCode) {
        process.stdout.write('Generated asset parity passed: 2 source/dist bundles.\n');
    }
} finally {
    cleanup();
}
