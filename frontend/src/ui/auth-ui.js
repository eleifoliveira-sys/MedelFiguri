import { logout } from '../domain/auth.js';

export function updateAuthUI(user) {
    const userLabel = document.querySelector('[data-auth-user]');
    const guestLinks = document.querySelector('[data-guest-links]');
    const accountControls = document.querySelector('[data-account-controls]');
    const adminLinks = document.querySelectorAll('[data-admin-only]');
    const isAdmin = user?.roles?.some((role) => role.slug === 'admin') ?? false;

    if (userLabel) {
        userLabel.textContent = user ? `Olá, ${user.name}` : '';
    }

    if (guestLinks) {
        guestLinks.hidden = Boolean(user);
    }

    if (accountControls) {
        accountControls.hidden = !user;
    }

    adminLinks.forEach((link) => {
        link.hidden = !isAdmin;
    });
}

export function bindLogoutButtons() {
    document.querySelectorAll('[data-logout]').forEach((button) => {
        button.addEventListener('click', async () => {
            const message = document.querySelector('[data-auth-message]');
            button.disabled = true;

            try {
                await logout();
                updateAuthUI(null);
                window.location.assign('/login.html?loggedOut=1');
            } catch {
                if (message) {
                    message.textContent = 'Não foi possível encerrar a sessão. Tente novamente.';
                }
            } finally {
                button.disabled = false;
            }
        });
    });
}
