document.querySelectorAll('[data-toggle-package]').forEach((select) => {
    const form = select.closest('form');
    const packageField = form ? form.querySelector('[name="customer_package_id"]') : null;
    const packageLabel = packageField ? packageField.closest('label') : null;

    function syncPackageVisibility() {
        if (!packageLabel) return;
        packageLabel.style.display = select.value === 'single' ? 'none' : '';
    }

    select.addEventListener('change', syncPackageVisibility);
    syncPackageVisibility();
});
