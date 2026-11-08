// Même intégration qu'artisandubenin : Alpine est fourni par le bundle ESM de Livewire,
// les plugins sont enregistrés avant Livewire.start().
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';
import { registerOfflineIndex } from './offline-queue.js';

Alpine.plugin(focus);
Alpine.plugin(collapse);
registerOfflineIndex(Alpine);

window.Alpine = Alpine;

Livewire.start();

// ENF-04 : application installable et consultation hors ligne (public/sw.js).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Sans service worker, l'application fonctionne normalement en ligne.
        });
    });

    // Déconnexion : les pages gardées sur ce téléphone sont effacées (téléphone partagé).
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.action.endsWith('/logout')) {
            navigator.serviceWorker.controller?.postMessage('clear');
            if ('caches' in window) {
                caches.keys().then((keys) => keys.forEach((key) => caches.delete(key)));
            }
        }
    });
}
