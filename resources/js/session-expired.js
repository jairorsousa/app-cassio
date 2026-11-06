// Sessão expirada (HTTP 419 em /livewire/update): em vez da falha silenciosa ou do confirm padrão,
// mostra um aviso fixo e só recarrega quando a pessoa clicar (sem loop de reload).
export const SESSION_EXPIRED_MESSAGE = 'Sua sessão expirou. Recarregue a página para acessar novamente antes de salvar.';

export function reloadTarget(loc = window.location) {
    // No Dashboard, volta com o modo Personalizar aberto.
    if (loc.pathname.replace(/\/+$/, '') === '/dashboard') {
        return '/dashboard?personalizar=1';
    }

    return loc.pathname + loc.search;
}

export function showSessionExpired(doc = document) {
    if (doc.getElementById('session-expired-alert')) {
        return false;
    }
    const box = doc.createElement('div');
    box.id = 'session-expired-alert';
    box.setAttribute('role', 'alert');
    box.style.cssText = 'position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:9999;max-width:92vw;'
        + 'background:#7f1d1d;color:#fff;padding:12px 16px;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.35);'
        + 'display:flex;gap:12px;align-items:center;font-size:14px;';
    const text = doc.createElement('span');
    text.textContent = SESSION_EXPIRED_MESSAGE;
    const button = doc.createElement('button');
    button.type = 'button';
    button.textContent = 'Recarregar';
    button.style.cssText = 'background:#fff;color:#7f1d1d;font-weight:700;border-radius:6px;padding:6px 12px;min-height:36px;';
    button.addEventListener('click', () => { window.location.assign(reloadTarget()); });
    box.append(text, button);
    doc.body.appendChild(box);
    button.focus();

    return true;
}

export function registerSessionExpiredHandler(Livewire) {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) {
                preventDefault();
                showSessionExpired();
            }
        });
    });
}
