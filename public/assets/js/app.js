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

if (document.querySelector('[data-auto-refresh]')) {
    setTimeout(() => window.location.reload(), 3000);
}

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
            <input type="text" name="sources[]" value="" placeholder="/DATA/Documents">
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

document.querySelectorAll('[data-app-picker]').forEach((picker) => {
    const checkbox = picker.querySelector('.app-select-checkbox');
    if (!checkbox) {
        return;
    }

    const refresh = () => picker.classList.toggle('selected', checkbox.checked);
    checkbox.addEventListener('change', refresh);
    refresh();
});

document.querySelectorAll('.repo-option').forEach((option) => {
    const radio = option.querySelector('input[type="radio"]');
    if (!radio) {
        return;
    }

    const refresh = () => {
        document.querySelectorAll('.repo-option').forEach((item) => {
            const itemRadio = item.querySelector('input[type="radio"]');
            item.classList.toggle('selected', !!itemRadio && itemRadio.checked);
        });
    };

    radio.addEventListener('change', refresh);
    refresh();
});
