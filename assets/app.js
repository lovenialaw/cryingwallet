if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    const openCat = sessionStorage.getItem('cw-open-cat');
    if (!openCat) {
        return;
    }
    sessionStorage.removeItem('cw-open-cat');
    const card = document.querySelector('.cat-card[data-category="' + openCat.replace(/"/g, '') + '"]');
    if (card) {
        card.open = true;
        card.scrollIntoView({ block: 'nearest' });
    }
});

document.addEventListener('change', async (event) => {
    const select = event.target.closest('[data-recategorize]');
    if (!select) return;

    const id = select.getAttribute('data-id');
    const category = select.value;
    const applyPayee = select.hasAttribute('data-apply-payee');
    const res = await fetch('index.php?page=api&action=recategorize', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, category, apply_payee: applyPayee }),
    });
    const data = await res.json();
    const note = select.parentElement.querySelector('.saved');
    if (note) {
        note.textContent = data.ok ? (data.updated > 1 ? 'saved for ' + data.updated + ' rows' : 'saved') : 'failed';
        note.classList.add('show');
        setTimeout(() => note.classList.remove('show'), 1600);
    }
    if (data.ok && applyPayee) {
        const payee = (select.getAttribute('data-payee') || '').toLowerCase();
        document.querySelectorAll('[data-recategorize][data-apply-payee]').forEach((other) => {
            if ((other.getAttribute('data-payee') || '').toLowerCase() !== payee) {
                return;
            }
            other.value = category;
            const cat = other.closest('.tl-row')?.querySelector('.tl-cat');
            const opt = other.selectedOptions && other.selectedOptions[0];
            if (cat && opt) {
                cat.textContent = opt.textContent;
            }
        });
        const card = select.closest('.cat-card[data-category]');
        if (card) {
            sessionStorage.setItem('cw-open-cat', category);
            window.location.reload();
        }
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-ajax-rule], [data-ajax-category]');
    if (!form) return;
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(form).entries());
    const action = form.hasAttribute('data-ajax-category') ? 'add_category' : 'add_rule';
    if (action === 'add_category') {
        payload.name = payload.category_name;
    }
    const res = await fetch('index.php?page=api&action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.ok) {
        window.location.reload();
    } else {
        alert(data.error || 'Could not save');
    }
});
