{{-- Repris d'artisandubenin : applique le mode sombre avant le rendu (pas de flash blanc).
     Version simplifiée : préférence enregistrée dans le navigateur seulement, clair par défaut. --}}
<script>
    (function () {
        var mode = null;
        try { mode = localStorage.getItem('compteurs-theme'); } catch (e) {}
        if (mode === 'system') {
            mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        if (mode === 'dark') document.documentElement.classList.add('dark');
    })();
</script>
