document.querySelectorAll('[data-toggle-package]').forEach((select) => {
    const form = select.closest('form');
    const packageField = form ? form.querySelector('[data-package-field]') : null;
    const sync = () => {
        if (packageField) packageField.hidden = select.value === 'single';
    };
    select.addEventListener('change', sync);
    sync();
});

const menuButton = document.querySelector('[data-menu]');
if (menuButton) {
    menuButton.addEventListener('click', () => document.body.classList.toggle('menu-open'));
    document.addEventListener('click', (event) => {
        if (!document.body.classList.contains('menu-open')) return;
        if (event.target.closest('#sidebar') || event.target.closest('[data-menu]')) return;
        document.body.classList.remove('menu-open');
    });
}

document.querySelectorAll('[data-payment-package]').forEach((select) => {
    const form = select.closest('form');
    const customer = form?.querySelector('[name="customer_id"]');
    const amount = form?.querySelector('[name="amount"]');
    const sync = () => {
        const option = select.selectedOptions[0];
        if (!option) return;
        if (customer && option.dataset.customer) customer.value = option.dataset.customer;
        if (amount && option.dataset.amount) amount.value = option.dataset.amount;
    };
    select.addEventListener('change', sync);
    sync();
});

document.querySelectorAll('[data-card-number]').forEach((input) => {
    input.addEventListener('input', () => {
        const digits = input.value.replace(/\D/g, '').slice(0, 19);
        input.value = digits.replace(/(.{4})/g, '$1 ').trim();
    });
});

document.querySelectorAll('[data-card-expiry]').forEach((input) => {
    input.addEventListener('input', () => {
        const digits = input.value.replace(/\D/g, '').slice(0, 4);
        input.value = digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
    });
});
