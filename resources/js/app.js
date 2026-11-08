// Même intégration qu'artisandubenin : Alpine est fourni par le bundle ESM de Livewire,
// les plugins sont enregistrés avant Livewire.start().
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

Alpine.plugin(focus);
Alpine.plugin(collapse);

window.Alpine = Alpine;

Livewire.start();
