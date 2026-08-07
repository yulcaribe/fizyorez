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
    const setMenuOpen = (open) => {
        document.body.classList.toggle('menu-open', open);
        menuButton.setAttribute('aria-expanded', String(open));
    };

    menuButton.addEventListener('click', () => setMenuOpen(!document.body.classList.contains('menu-open')));
    document.addEventListener('click', (event) => {
        if (!document.body.classList.contains('menu-open')) return;
        if (event.target.closest('#sidebar') || event.target.closest('[data-menu]')) return;
        setMenuOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !document.body.classList.contains('menu-open')) return;
        setMenuOpen(false);
        menuButton.focus();
    });
    document.querySelectorAll('#sidebar a').forEach((link) => {
        link.addEventListener('click', () => setMenuOpen(false));
    });

    const desktopQuery = window.matchMedia('(min-width: 901px)');
    desktopQuery.addEventListener('change', (event) => {
        if (event.matches) setMenuOpen(false);
    });
}

document.querySelectorAll('[data-payment-target]').forEach((select) => {
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

const balanceDialog = document.querySelector('[data-balance-dialog]');
const balanceMessage = balanceDialog?.querySelector('[data-balance-message]');
const balanceTopupButton = balanceDialog?.querySelector('[data-balance-topup]');
const walletTopupForm = document.querySelector('#wallet-topup');
const walletTopupAmount = walletTopupForm?.querySelector('[name="amount"]');
const formatMoney = (value) => new Intl.NumberFormat('tr-TR', {
    style: 'currency',
    currency: 'TRY',
}).format(value);

document.querySelectorAll('[data-wallet-purchase]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const requiredBalance = Number(form.dataset.requiredBalance);
        const spendableBalance = Number(form.dataset.spendableBalance);
        if (!Number.isFinite(requiredBalance) || !Number.isFinite(spendableBalance) || spendableBalance >= requiredBalance) return;

        event.preventDefault();
        const shortage = Math.ceil((requiredBalance - spendableBalance) * 100) / 100;
        if (balanceMessage) {
            balanceMessage.textContent = `Net harcanabilir bakiyeniz ${formatMoney(spendableBalance)}. Bu işlem için ${formatMoney(shortage)} daha yüklemeniz gerekiyor.`;
        }
        if (balanceDialog) balanceDialog.dataset.shortage = String(shortage);

        if (balanceDialog?.showModal) {
            if (!balanceDialog.open) balanceDialog.showModal();
        } else {
            window.alert(balanceMessage?.textContent || 'Bakiyeniz bu işlem için yetersiz.');
        }
    });
});

balanceTopupButton?.addEventListener('click', () => {
    const shortage = Number(balanceDialog?.dataset.shortage);
    if (walletTopupAmount && Number.isFinite(shortage) && shortage > 0) {
        walletTopupAmount.value = shortage.toFixed(2);
    }
    balanceDialog?.close();
    walletTopupForm?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    walletTopupAmount?.focus({ preventScroll: true });
});

document.querySelectorAll('[data-book-consultant]').forEach((button) => {
    button.addEventListener('click', () => {
        const form = document.querySelector('[data-reservation-form]');
        const consultant = form?.querySelector('[name="consultant_id"]');
        const startsAt = form?.querySelector('[name="starts_at"]');
        if (consultant) consultant.value = button.dataset.bookConsultant || '';
        if (startsAt) {
            startsAt.value = `${button.dataset.bookDate}T${button.dataset.bookTime}`;
            startsAt.dispatchEvent(new Event('input', { bubbles: true }));
        }
        form?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        startsAt?.focus({ preventScroll: true });
    });
});

document.querySelectorAll('[data-edit-reservation]').forEach((button) => {
    button.addEventListener('click', () => {
        const form = document.querySelector('[data-scheduler-edit-form]');
        const empty = document.querySelector('[data-scheduler-edit-empty]');
        const label = form?.querySelector('[data-scheduler-edit-label]');
        const reservationId = form?.querySelector('[name="reservation_id"]');
        const startsAt = form?.querySelector('[name="starts_at"]');
        if (!form || !reservationId || !startsAt) return;
        form.hidden = false;
        if (empty) empty.hidden = true;
        reservationId.value = button.dataset.editReservation || '';
        form.dataset.fixedDuration = button.dataset.editDuration || '';
        startsAt.value = button.dataset.editStart || '';
        startsAt.dispatchEvent(new Event('input', { bubbles: true }));
        if (label) label.textContent = button.dataset.editLabel || 'Seçili randevu';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        startsAt.focus({ preventScroll: true });
    });
});

document.querySelectorAll('[data-reservation-timing]').forEach((form) => {
    const service = form.querySelector('[data-reservation-service]');
    const startsAt = form.querySelector('[data-reservation-start]');
    const endsAt = form.querySelector('[data-reservation-end]');
    if (!startsAt || !endsAt) return;

    const calculateEnd = () => {
        const selectedDuration = service?.selectedOptions[0]?.dataset.duration;
        const duration = Number(selectedDuration || form.dataset.fixedDuration || 0);
        if (!startsAt.value || !Number.isFinite(duration) || duration <= 0) {
            endsAt.value = '';
            return;
        }
        const end = new Date(`${startsAt.value}:00`);
        if (Number.isNaN(end.getTime())) {
            endsAt.value = '';
            return;
        }
        end.setMinutes(end.getMinutes() + duration);
        const pad = (value) => String(value).padStart(2, '0');
        endsAt.value = `${pad(end.getDate())}-${pad(end.getMonth() + 1)}-${end.getFullYear()} ${pad(end.getHours())}:${pad(end.getMinutes())}`;
    };

    startsAt.addEventListener('input', calculateEnd);
    service?.addEventListener('change', calculateEnd);
    calculateEnd();
});

document.querySelectorAll('[data-confirm]').forEach((control) => {
    control.addEventListener('click', (event) => {
        if (!window.confirm(control.dataset.confirm || 'Bu işlemi onaylıyor musunuz?')) {
            event.preventDefault();
        }
    });
});
