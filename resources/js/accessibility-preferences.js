const preferenceDialog = document.querySelector('[data-accessibility-dialog]');

if (preferenceDialog instanceof HTMLDialogElement) {
    const openButton = document.querySelector('[data-open-accessibility]');
    const closeButton = preferenceDialog.querySelector('[data-close-accessibility]');
    const dismissedKey = 'selflearn:accessibility-preferences-dismissed';
    let returnFocusTarget = null;

    openButton?.addEventListener('click', () => {
        if (!preferenceDialog.open) {
            returnFocusTarget = openButton;
            preferenceDialog.showModal();
        }
    });

    closeButton?.addEventListener('click', () => preferenceDialog.close());

    preferenceDialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;

        const focusable = [...preferenceDialog.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]):not([type="radio"]), input[type="radio"]:checked, select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        )];
        const first = focusable[0];
        const last = focusable.at(-1);
        if (!first || !last) return;

        if (!preferenceDialog.contains(document.activeElement)) {
            event.preventDefault();
            first.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    preferenceDialog.addEventListener('close', () => {
        if (preferenceDialog.dataset.hasPreference !== 'true') {
            try {
                window.sessionStorage.setItem(dismissedKey, 'true');
            } catch {
                // The preference dialog remains available from the header if storage is unavailable.
            }
        }
        returnFocusTarget?.focus();
    });

    let dismissedForSession = false;
    try {
        dismissedForSession = window.sessionStorage.getItem(dismissedKey) === 'true';
    } catch {
        // Still show the first-use prompt when session storage is unavailable.
    }

    if (preferenceDialog.dataset.hasPreference !== 'true' && !dismissedForSession) {
        returnFocusTarget = openButton;
        preferenceDialog.showModal();
    }
}
