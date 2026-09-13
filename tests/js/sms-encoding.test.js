'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const packageRoot = path.resolve(__dirname, '../..');
const fixture = JSON.parse(fs.readFileSync(path.join(packageRoot, 'tests/Fixtures/sms-encoding-vectors.json'), 'utf8'));
const implementations = [
    'src/web/assets/encoding/src/encoding.js',
    'src/web/assets/encoding/dist/encoding.js'
];

function load(relativePath) {
    const context = {globalThis: {}};
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(path.join(packageRoot, relativePath), 'utf8'), context);
    return context.globalThis.lrSmsEncoding;
}

for (const relativePath of implementations) {
    const calculator = load(relativePath);
    if (!calculator || typeof calculator.analyze !== 'function') {
        throw new Error(`${relativePath} did not expose lrSmsEncoding.analyze()`);
    }

    for (const vector of fixture) {
        const message = Object.prototype.hasOwnProperty.call(vector, 'message')
            ? vector.message
            : vector.repeat.value.repeat(vector.repeat.count);
        const actual = calculator.analyze(message);
        const expected = {
            encoding: vector.encoding,
            characters: vector.characters,
            units: vector.units,
            segments: vector.segments
        };
        if (JSON.stringify(actual) !== JSON.stringify(expected)) {
            throw new Error(`${relativePath}: ${vector.name}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
        }
    }
}

process.stdout.write(`SMS encoding parity passed: ${fixture.length} vectors x ${implementations.length} implementations.\n`);
