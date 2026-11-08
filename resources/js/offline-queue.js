/*
 * File d'attente des index saisis sans réseau (ENF-04, lot 3).
 *
 * Fonctions pures autour d'un stockage de type localStorage (getItem / setItem), testées avec
 * Node (resources/js/tests/offline-queue.test.mjs). Une entrée par page et par décompteur : la
 * dernière saisie remplace la précédente.
 */
export const QUEUE_KEY = 'compteurs:index-en-attente';

export function readQueue(storage) {
    try {
        const parsed = JSON.parse(storage.getItem(QUEUE_KEY) ?? '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function writeQueue(storage, queue) {
    storage.setItem(QUEUE_KEY, JSON.stringify(queue));
}

export function enqueue(storage, page, meterId, value, now = Date.now()) {
    const queue = readQueue(storage).filter((e) => !(e.page === page && e.meterId === meterId));
    queue.push({ page, meterId, value, at: now });
    writeQueue(storage, queue);
    return queue;
}

export function pendingFor(storage, page) {
    return readQueue(storage).filter((e) => e.page === page);
}

export function remove(storage, page, meterId) {
    writeQueue(storage, readQueue(storage).filter((e) => !(e.page === page && e.meterId === meterId)));
}

/*
 * Envoie les index en attente de la page avec `send(meterId, value)` (une promesse) ; ceux qui
 * réussissent quittent la file, les autres y restent pour la prochaine tentative.
 */
export async function flush(storage, page, send) {
    let sent = 0;
    for (const entry of pendingFor(storage, page)) {
        try {
            await send(entry.meterId, entry.value);
            remove(storage, page, entry.meterId);
            sent++;
        } catch {
            break;
        }
    }
    return sent;
}

/*
 * Composant Alpine d'un champ d'index de la page de saisie :
 *   x-data="offlineIndex({ page: '…', meterId: 12 })" sur le conteneur, x-ref="input" sur le champ.
 * En ligne : envoie à Livewire ($wire.set). Hors ligne : garde l'index et l'enverra au retour.
 */
export function registerOfflineIndex(Alpine) {
    Alpine.data('offlineIndex', ({ page, meterId }) => ({
        pending: false,
        init() {
            this.pending = pendingFor(window.localStorage, page).some((e) => e.meterId === meterId);
            const pendingEntry = pendingFor(window.localStorage, page).find((e) => e.meterId === meterId);
            if (pendingEntry) {
                this.$refs.input.value = pendingEntry.value;
            }
            window.addEventListener('online', () => this.sync());
            if (navigator.onLine && this.pending) {
                this.sync();
            }
        },
        save() {
            const value = this.$refs.input.value;
            if (navigator.onLine) {
                this.$wire.set(`indexes.${meterId}`, value);
                return;
            }
            enqueue(window.localStorage, page, meterId, value);
            this.pending = true;
        },
        async sync() {
            const entry = pendingFor(window.localStorage, page).find((e) => e.meterId === meterId);
            if (!entry) {
                this.pending = false;
                return;
            }
            try {
                await this.$wire.set(`indexes.${meterId}`, entry.value);
                remove(window.localStorage, page, meterId);
                this.pending = false;
            } catch {
                this.pending = true;
            }
        },
    }));
}
