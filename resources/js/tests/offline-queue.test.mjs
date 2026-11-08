// Tests de la file d'attente hors ligne (ENF-04) : `npm run test:js` (node --test).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { QUEUE_KEY, enqueue, flush, pendingFor, readQueue, remove } from '../offline-queue.js';

function memoryStorage() {
    const data = new Map();
    return { getItem: (k) => (data.has(k) ? data.get(k) : null), setItem: (k, v) => data.set(k, String(v)) };
}

const PAGE = '/maisons/01ABC/periodes/2026-11';

test('un index saisi hors ligne est gardé, le dernier remplace le précédent', () => {
    const s = memoryStorage();
    enqueue(s, PAGE, 12, '1 534', 1);
    enqueue(s, PAGE, 12, '1 535', 2);
    enqueue(s, PAGE, 13, '430', 3);

    assert.deepEqual(pendingFor(s, PAGE), [
        { page: PAGE, meterId: 12, value: '1 535', at: 2 },
        { page: PAGE, meterId: 13, value: '430', at: 3 },
    ]);
});

test('les index d\'une autre page ne sont pas mélangés', () => {
    const s = memoryStorage();
    enqueue(s, PAGE, 12, '1 534');
    enqueue(s, '/maisons/01ABC/periodes/2026-10', 12, '1 392');

    assert.equal(pendingFor(s, PAGE).length, 1);
    remove(s, PAGE, 12);
    assert.equal(readQueue(s).length, 1);
});

test('au retour du réseau, les index partent et quittent la file', async () => {
    const s = memoryStorage();
    enqueue(s, PAGE, 12, '1 534');
    enqueue(s, PAGE, 13, '430');
    const sent = [];

    const count = await flush(s, PAGE, async (meterId, value) => { sent.push([meterId, value]); });

    assert.equal(count, 2);
    assert.deepEqual(sent, [[12, '1 534'], [13, '430']]);
    assert.deepEqual(pendingFor(s, PAGE), []);
});

test('si l\'envoi échoue, l\'index reste en attente', async () => {
    const s = memoryStorage();
    enqueue(s, PAGE, 12, '1 534');

    const count = await flush(s, PAGE, async () => { throw new Error('réseau'); });

    assert.equal(count, 0);
    assert.equal(pendingFor(s, PAGE).length, 1);
});

test('un stockage abîmé ne bloque pas la saisie', () => {
    const s = memoryStorage();
    s.setItem(QUEUE_KEY, '{pas du json');
    assert.deepEqual(readQueue(s), []);
    enqueue(s, PAGE, 1, '10');
    assert.equal(readQueue(s).length, 1);
});
