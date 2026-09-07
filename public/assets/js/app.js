document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) {
        return;
    }

    const input = document.querySelector(button.dataset.copy);
    if (!input) {
        return;
    }

    const original = button.textContent;

    try {
        await navigator.clipboard.writeText(input.value);
        button.textContent = 'Copied ✓';
    } catch {
        input.select();
        document.execCommand('copy');
        button.textContent = 'Copied ✓';
    }

    setTimeout(() => {
        button.textContent = original;
    }, 1600);
});

// Repository initialization is handled by the isolated worker. A short page
// refresh keeps the UI useful without adding a frontend framework or polling API.
if (document.querySelector('[data-auto-refresh]')) {
    setTimeout(() => window.location.reload(), 3000);
}

// Manual folder inputs remain available alongside application-aware sources.
// Detected app volumes are rendered by Twig and validated again by the backend.
const sourceList = document.querySelector('[data-source-list]');
const addSourceButton = document.querySelector('[data-add-source]');

if (sourceList && addSourceButton) {
    const refreshRemoveButtons = () => {
        const buttons = sourceList.querySelectorAll('[data-remove-source]');
        buttons.forEach((button) => {
            button.disabled = buttons.length <= 1;
        });
    };

    addSourceButton.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'source-row';
        row.innerHTML = `
            <div class="path-input source-input">
                <span>/</span>
                <input type="text" name="sources[]" value="" placeholder="/DATA/AppData/example" required>
            </div>
            <button type="button" class="icon-button" data-remove-source aria-label="Remove source">×</button>
        `;
        sourceList.appendChild(row);
        row.querySelector('input').focus();
        refreshRemoveButtons();
    });

    sourceList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-source]');
        if (!button || button.disabled) {
            return;
        }
        button.closest('.source-row')?.remove();
        refreshRemoveButtons();
    });

    refreshRemoveButtons();
}

// Application selection stays framework-free: selecting an app reveals its
// detected volumes. Mount checkboxes are submitted only when the app itself is
// selected; the backend independently validates every mount id and host path.
document.querySelectorAll('[data-app-picker]').forEach((picker) => {
    const checkbox = picker.querySelector('.app-select-checkbox');
    if (!checkbox) {
        return;
    }

    const refresh = () => picker.classList.toggle('selected', checkbox.checked);
    checkbox.addEventListener('change', refresh);
    refresh();
});
