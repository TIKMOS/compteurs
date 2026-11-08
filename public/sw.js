/*
 * Service worker de Compteurs (ENF-04, lot 3) : consultation hors ligne.
 *
 * - Pages (navigation GET) : réseau d'abord ; la réponse est gardée ; sans réseau, dernière
 *   version gardée, sinon la page « Hors connexion ».
 * - CSS, JS, icônes : la version gardée d'abord, mise à jour en arrière-plan.
 * - Jamais de cache pour les requêtes Livewire, les POST, les PDF, les photos ni l'export.
 * - Message « clear » (déconnexion) : tout le cache est effacé (téléphone partagé).
 */
const VERSION = 'compteurs-v1';
const PAGES = `${VERSION}-pages`;
const ASSETS = `${VERSION}-assets`;
const OFFLINE_URL = '/hors-ligne';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(PAGES).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'clear') {
        event.waitUntil(caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))));
    }
});

function isCacheablePage(url) {
    return /^\/(maisons|locataires|journal|locataire\/)/.test(url.pathname)
        && !/(\.pdf$|\.csv$|\/photo$|\/fiches\/|\/fiche\/)/.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/livewire')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && isCacheablePage(url)) {
                        const copy = response.clone();
                        caches.open(PAGES).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached || caches.match(OFFLINE_URL))),
        );
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.open(ASSETS).then((cache) => cache.match(request).then((cached) => {
                const network = fetch(request).then((response) => {
                    if (response.ok) {
                        cache.put(request, response.clone());
                    }
                    return response;
                }).catch(() => cached);
                return cached || network;
            })),
        );
    }
});
