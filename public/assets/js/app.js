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
