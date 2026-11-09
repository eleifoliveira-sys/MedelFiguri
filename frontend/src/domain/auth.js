import { ApiError, apiRequest } from '../api/api.js';

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

export async function logout() {
    try {
        await apiRequest('/api/logout', { method: 'POST' });
    } catch (error) {
        if (!(error instanceof ApiError && error.status === 401)) {
            throw error;
        }
    }

    currentUser = null;
}
