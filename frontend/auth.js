import { ApiError, apiRequest } from './api.js';

let currentUser = null;

export async function getCurrentUser() {
    try {
        const response = await apiRequest('/api/me');
        currentUser = response?.data ?? null;
        return currentUser;
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            currentUser = null;
            return null;
        }

        throw error;
    }
}

export async function verifySession() {
    return getCurrentUser();
}

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

    currentUser = user ?? null;
}

export async function logout() {
    try {
        await apiRequest('/api/logout', { method: 'POST' });
    } catch (error) {
        if (!(error instanceof ApiError && error.status === 401)) {
            throw error;
        }
    }

    currentUser = null;
    updateAuthUI(null);
}

export function bindLogoutButtons() {
    document.querySelectorAll('[data-logout]').forEach((button) => {
        button.addEventListener('click', async () => {
            const message = document.querySelector('[data-auth-message]');
            button.disabled = true;

            try {
                await logout();
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
