{{-- Autofill → Livewire sync, shared by every public form.

     wire:model listens for input/change events. Chrome fills autocompleted
     fields without reliably dispatching either, so a form can LOOK filled
     while the component's properties are still empty — the user submits and
     gets "name is required" over a visibly populated field.

     Browsers do apply the :-webkit-autofill pseudo-class, and a CSS animation
     keyed to it fires animationstart — the standard autofill-detection trick.
     Also swept on submit, for fills that predate the listener (and Safari,
     which never fires the animation).

     Was inline in contact-section and scoped to `.contact-form`, so the /jobs
     form silently missed it: an autofilled application could submit empty.
     Scoped to form[wire\:submit], which is every Livewire form. --}}
<style>
    @keyframes onAutoFillStart { from { /* marker only */ } to { /* marker only */ } }
    form[wire\:submit] input:-webkit-autofill { animation-name: onAutoFillStart; animation-duration: 1ms; }
</style>

<script data-navigate-once>
    (() => {
        if (window.__autofillSyncWired) return;
        window.__autofillSyncWired = true;

        const sync = (el) => {
            if (!el || el.dataset.autofillSynced === el.value) return;
            el.dataset.autofillSynced = el.value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        };

        document.addEventListener('animationstart', (e) => {
            if (e.animationName === 'onAutoFillStart') sync(e.target);
        }, true);

        // Belt and braces: some fills happen before this script runs.
        document.addEventListener('submit', (e) => {
            const form = e.target.closest?.('form[wire\\:submit]');
            if (form) form.querySelectorAll('input, textarea, select').forEach(sync);
        }, true);
    })();
</script>
