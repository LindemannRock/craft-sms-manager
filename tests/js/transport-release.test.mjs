import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Exercise the shipped handler in a VM with no network or application access.
// Twig's two translated message expressions are replaced with inert text.
const source = readFileSync(new URL('../../src/templates/_components/_transport-release-script.twig', import.meta.url), 'utf8')
    .replace(/{%\s*(?:js|endjs)\s*%}/g, '')
    .replace(/{{[\s\S]*?}}/g, '"Synthetic translated message"');

function fixture({confirmed = true, send = async () => ({data: {success: true, message: 'Released'}}), tagName = 'A'} = {}) {
    const requests = [];
    const confirmations = [];
    const errors = [];
    let reloads = 0;
    let listener;
    const attributes = new Map();
    const trigger = {
        tagName,
        disabled: false,
        dataset: {handle: 'synthetic-config-provider', lane: 'development', token: 'synthetic-generation', laneLabel: 'Development account'},
        setAttribute: (key, value) => attributes.set(key, value),
        removeAttribute: (key) => attributes.delete(key),
    };
    vm.runInNewContext(source, {
        document: {addEventListener: (event, callback) => { assert.equal(event, 'click'); listener = callback; }},
        window: {confirm: (message) => { confirmations.push(message); return confirmed; }, location: {reload: () => reloads++}},
        Craft: {
            sendActionRequest: async (method, route, payload) => {
                assert.equal(method, 'POST');
                assert.equal(route, 'sms-manager/providers/release-transport-hold');
                requests.push(JSON.parse(JSON.stringify(payload)));
                return send();
            },
            cp: {displayNotice: () => {}, displayError: (message) => errors.push(message)},
        },
        fetch: () => assert.fail('Network fallback forbidden'),
        XMLHttpRequest: class { constructor() { assert.fail('Network fallback forbidden'); } },
    });
    return {
        trigger, attributes, requests, confirmations, errors,
        get reloads() { return reloads; },
        click: (matched = true) => listener({
            target: {closest: (selector) => { assert.equal(selector, '[data-action="release-transport-hold"]'); return matched ? trigger : null; }},
            preventDefault: () => {},
        }),
    };
}

test('cancel and unrelated actions make zero requests', async () => {
    const state = fixture({confirmed: false});
    await state.click(false);
    assert.equal(state.confirmations.length, 0);
    await state.click();
    assert.match(state.confirmations[0], /synthetic-config-provider\nDevelopment account/);
    assert.equal(state.requests.length, 0);
    assert.equal(state.reloads, 0);
});

test('confirmation posts only the selected hold and blocks duplicate clicks', async () => {
    let resolve;
    const state = fixture({send: () => new Promise((done) => { resolve = done; })});
    const pending = state.click();
    await state.click();
    assert.equal(state.requests.length, 1);
    assert.equal(state.attributes.get('aria-disabled'), 'true');
    assert.deepEqual(state.requests[0], {data: {handle: 'synthetic-config-provider', lane: 'development', token: 'synthetic-generation', confirmed: '1'}});
    resolve({data: {success: true, message: 'Released'}});
    await pending;
    assert.equal(state.reloads, 1);
    assert.equal(state.attributes.has('aria-disabled'), false);
    assert.equal(state.trigger.dataset.releasing, undefined);
});

for (const [name, send] of [
    ['rejected stale hold', async () => ({data: {success: false, message: 'Refresh'}})],
    ['request failure', async () => { throw new Error('Synthetic failure'); }],
]) {
    test(`${name} restores the detail button without retrying`, async () => {
        const state = fixture({send, tagName: 'BUTTON'});
        await state.click();
        assert.equal(state.requests.length, 1);
        assert.equal(state.errors.length, 1);
        assert.equal(state.reloads, 0);
        assert.equal(state.trigger.disabled, false);
        assert.equal(state.attributes.has('aria-disabled'), false);
        assert.equal(state.trigger.dataset.releasing, undefined);
    });
}
