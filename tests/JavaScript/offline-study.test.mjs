import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const script = await readFile(new URL('../../public/js/offline-study.js', import.meta.url), 'utf8');

class MemoryIndexedDb {
    constructor(seed = {}) {
        this.stores = { packs: seed.packs ?? [], attempts: seed.attempts ?? [] };
    }

    open() {
        const request = {};
        const db = {
            objectStoreNames: { contains: (name) => Object.hasOwn(this.stores, name) },
            createObjectStore: (name) => { this.stores[name] ??= []; },
            close: () => {},
            transaction: (name) => {
                const tx = {
                    error: null,
                    objectStore: () => ({
                        getAll: () => ({ result: this.stores[name].map((entry) => structuredClone(entry)) }),
                        put: (entry) => {
                            const records = this.stores[name];
                            const index = records.findIndex((existing) => existing.key === entry.key);
                            if (index === -1) records.push(structuredClone(entry));
                            else records[index] = structuredClone(entry);
                            return { result: entry.key };
                        },
                        delete: (key) => {
                            this.stores[name] = this.stores[name].filter((entry) => entry.key !== key);
                            return { result: undefined };
                        },
                    }),
                };
                queueMicrotask(() => tx.oncomplete?.());
                return tx;
            },
        };
        request.result = db;
        queueMicrotask(() => {
            request.onupgradeneeded?.();
            request.onsuccess?.();
        });
        return request;
    }
}

class Element {
    constructor(dataset = {}) {
        this.dataset = dataset;
        this.listeners = {};
        this.children = [];
        this.disabled = false;
        this.hidden = false;
        this.textContent = '';
    }

    addEventListener(name, listener) { this.listeners[name] = listener; }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = [...items]; }
    querySelectorAll() { return []; }
    querySelector() { return null; }
    setAttribute() {}
    async fire(name, event = {}) { return this.listeners[name]?.({ preventDefault() {}, ...event }); }
}

const makeEnvironment = ({ stores, activeUser = 'user-a', accountId = null, reader = false, logout = false, online = true, fetch }) => {
    const indexedDB = new MemoryIndexedDb(stores);
    const localStorageData = new Map(activeUser ? [['selflearn:offline-current-user', activeUser]] : []);
    const syncStatus = new Element();
    const readerStatus = new Element();
    const list = new Element();
    const content = new Element();
    const syncButton = new Element();
    const readerNode = new Element();
    const logoutForm = new Element({ offlineUserId: accountId ?? 'user-a' });
    logoutForm.submit = () => { logoutForm.submitted = true; };
    const accountContext = accountId ? new Element({ offlineUserId: accountId }) : null;
    const alertMessages = [];
    const selectors = new Map([
        ['[data-reader-status]', readerStatus],
        ['[data-pack-list]', list],
        ['[data-pack-content]', content],
        ['[data-sync-now]', syncButton],
        ['[data-sync-status]', syncStatus],
    ]);
    readerNode.querySelector = (selector) => selectors.get(selector) ?? null;
    const document = {
        querySelector(selector) {
            if (selector === '[data-offline-controls]') return null;
            if (selector === '[data-offline-reader]') return reader ? readerNode : null;
            if (selector === '[data-offline-user-id]') return accountContext;
            if (selector === '[data-offline-status]') return null;
            return null;
        },
        querySelectorAll(selector) { return selector === 'form[action$="/logout"]' && logout ? [logoutForm] : []; },
        createElement: () => new Element(),
        createTextNode: (text) => ({ textContent: text }),
    };
    const window = {
        indexedDB,
        confirm: (message) => { alertMessages.push(message); return true; },
        setTimeout: (callback) => { queueMicrotask(callback); return 1; },
        clearTimeout: () => {},
        addEventListener: () => {},
    };
    const localStorage = {
        getItem: (key) => localStorageData.get(key) ?? null,
        setItem: (key, value) => localStorageData.set(key, value),
        removeItem: (key) => localStorageData.delete(key),
    };
    runInNewContext(script, {
        window, document, indexedDB, localStorage,
        navigator: { onLine: online },
        location: { protocol: 'https:', hostname: 'learn.example', search: '' },
        URLSearchParams,
        CSS: { escape: (value) => value },
        Blob,
        fetch,
        console,
    });

    return { indexedDB, localStorageData, logoutForm, alertMessages, syncButton, syncStatus, readerStatus, list };
};

const turn = () => new Promise((resolve) => setTimeout(resolve, 0));

test('an expired sign-in keeps pending answers and same-account reauthentication syncs them', async () => {
    const queued = {
        key: 'user-a:attempt-1', userId: 'user-a', assessmentId: 'quiz-1', attemptUuid: 'attempt-1',
        submitUrl: '/assessments/quiz-1/attempts', resultUrl: '/assessments/quiz-1',
        answers: { question: 'A' }, responses: {}, status: 'pending', createdAt: '2026-10-12T10:00:00.000Z',
    };
    let signedIn = false;
    const fetch = async (url) => {
        if (url === '/offline-sync-context' && !signedIn) {
            return { ok: false, status: 401, redirected: false, headers: { get: () => 'application/json' } };
        }
        if (url === '/offline-sync-context') {
            return {
                ok: true, status: 200, redirected: false,
                headers: { get: () => 'application/json' },
                json: async () => ({ user_id: 'user-a', csrf_token: 'test-token' }),
            };
        }
        return {
            ok: true, status: 200, redirected: false,
            headers: { get: () => 'application/json' },
            json: async () => ({ status: 'synced', attempt_id: 'server-attempt-1', result_url: '/assessments/quiz-1' }),
        };
    };
    const app = makeEnvironment({ stores: { attempts: [queued] }, reader: true, fetch });
    await turn();

    await app.syncButton.fire('click');
    await turn();
    assert.equal(app.indexedDB.stores.attempts[0].status, 'pending');
    assert.match(app.syncStatus.textContent, /Sign in to the same account/);

    signedIn = true;
    await app.syncButton.fire('click');
    await turn();
    assert.equal(app.indexedDB.stores.attempts[0].status, 'synced');
    assert.equal(app.indexedDB.stores.attempts[0].userId, 'user-a');
});

test('sign-out clears only that account’s packs and answers, preserving another account’s offline data', async () => {
    const app = makeEnvironment({
        logout: true,
        stores: {
            packs: [
                { key: 'user-a:space-a', userId: 'user-a', spaceId: 'space-a' },
                { key: 'user-b:space-b', userId: 'user-b', spaceId: 'space-b' },
            ],
            attempts: [
                { key: 'user-a:attempt-a', userId: 'user-a', status: 'pending' },
                { key: 'user-b:attempt-b', userId: 'user-b', status: 'pending' },
            ],
        },
    });

    await app.logoutForm.fire('submit');

    assert.deepEqual(app.indexedDB.stores.packs.map((pack) => pack.key), ['user-b:space-b']);
    assert.deepEqual(app.indexedDB.stores.attempts.map((attempt) => attempt.key), ['user-b:attempt-b']);
    assert.match(app.alertMessages[0], /1 saved answer set that will be deleted/);
    assert.equal(app.logoutForm.submitted, true);
    assert.equal(app.localStorageData.has('selflearn:offline-current-user'), false);
});

test('an authenticated page changes the offline reader to the newly signed-in account', async () => {
    const app = makeEnvironment({
        activeUser: 'user-a', accountId: 'user-b', reader: true, online: false,
        stores: {
            packs: [
                { key: 'user-a:space-a', userId: 'user-a', spaceId: 'space-a', sizeLabel: '1 KB', savedAt: '2026-10-12', data: { space: { title: 'Account A' }, concepts: [], explanations: [], assessments: [] } },
                { key: 'user-b:space-b', userId: 'user-b', spaceId: 'space-b', sizeLabel: '1 KB', savedAt: '2026-10-12', data: { space: { title: 'Account B' }, concepts: [], explanations: [], assessments: [] } },
            ],
        },
    });
    await turn();

    assert.equal(app.localStorageData.get('selflearn:offline-current-user'), 'user-b');
    assert.equal(app.list.children.length, 1);
    assert.equal(app.list.children[0].children[0].textContent, 'Account B · 1 KB');
    assert.match(app.readerStatus.textContent, /Opened Account B/);
    assert.equal(app.syncStatus.textContent, 'No answers are waiting to sync.');
});
