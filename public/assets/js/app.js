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

// v0.9: original-path application restore requires a visible confirmation field.
document.querySelectorAll('[data-restore-mode-form]').forEach((form) => {
    const radios = form.querySelectorAll('input[name="mode"]');
    const confirmField = form.querySelector('.restore-confirm-field');
    const refresh = () => {
        const selected = form.querySelector('input[name="mode"]:checked')?.value || 'staging';
        form.querySelectorAll('.restore-mode-card').forEach((card) => {
            const radio = card.querySelector('input[name="mode"]');
            card.classList.toggle('selected', !!radio && radio.checked);
        });
        confirmField?.classList.toggle('visible', selected === 'original');
    };
    radios.forEach((radio) => radio.addEventListener('change', refresh));
    refresh();
});

// Management operations that can remove configuration or recovery points
// require an explicit browser confirmation before the POST is submitted.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.dataset.confirm || 'Are you sure?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
});

// v0.11: keep scheduling/retention forms compact and reveal only relevant fields.
document.querySelectorAll('[data-automation-form]').forEach((form) => {
    const schedule = form.querySelector('[name="schedule_type"]');
    const timeWrap = form.querySelector('[data-schedule-time]');
    const weekdayWrap = form.querySelector('[data-schedule-weekday]');
    const retentionToggle = form.querySelector('[name="retention_enabled"]');
    const retentionFields = form.querySelector('[data-retention-fields]');

    const refresh = () => {
        const type = schedule?.value || 'manual';
        if (timeWrap) timeWrap.hidden = type === 'manual';
        if (weekdayWrap) weekdayWrap.hidden = type !== 'weekly';
        if (retentionFields) retentionFields.hidden = !retentionToggle?.checked;
    };

    schedule?.addEventListener('change', refresh);
    retentionToggle?.addEventListener('change', refresh);
    refresh();
});
