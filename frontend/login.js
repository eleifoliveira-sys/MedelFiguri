import { ApiError, apiRequest } from './api.js';
import { getCurrentUser } from './auth.js';
import { refreshCartCount } from './cart-state.js';

const form = document.querySelector('#login-form');
const message = document.querySelector('[data-message]');
const queryMessage = document.querySelector('[data-query-message]');
const submitButton = document.querySelector('[data-submit]');
const parameters = new URLSearchParams(window.location.search);

function requestedDestination() {
    const next = parameters.get('next');
    if (next === '/checkout.html' || next === '/meus-pedidos.html' || next === '/admin.html') {
        return next;
    }

    if (next && /^\/pedido\.html\?id=\d+$/.test(next)) {
        return next;
    }

    return '/?signedIn=1';
}

function clearErrors() {
    form.querySelectorAll('.field-error').forEach((item) => {
        item.textContent = '';
    });
    form.querySelectorAll('[aria-invalid="true"]').forEach((item) => {
        item.removeAttribute('aria-invalid');
    });
}

function showValidationErrors(errors) {
    for (const [fieldName, messages] of Object.entries(errors)) {
        const field = form.elements.namedItem(fieldName);
        const errorElement = document.getElementById(`${fieldName}-error`);
        const firstMessage = Array.isArray(messages) ? messages[0] : messages;

        if (errorElement && typeof firstMessage === 'string') {
            errorElement.textContent = firstMessage;
        }

        if (field instanceof HTMLElement) {
            field.setAttribute('aria-invalid', 'true');
        }
    }
}

form.addEventListener('input', (event) => {
    const field = event.target;
    if (!(field instanceof HTMLElement)) {
        return;
    }

    field.removeAttribute('aria-invalid');
    const errorElement = document.getElementById(`${field.getAttribute('name')}-error`);
    if (errorElement) {
        errorElement.textContent = '';
    }
    message.textContent = '';
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearErrors();
    message.textContent = '';

    if (!form.reportValidity()) {
        return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Entrando...';
    submitButton.setAttribute('aria-busy', 'true');

    try {
        const formData = new FormData(form);
        const loginResponse = await apiRequest('/api/login', {
            method: 'POST',
            body: {
                login: formData.get('login'),
                password: formData.get('password'),
            },
        });

        const user = await getCurrentUser();
        if (!user) {
            throw new Error('Não foi possível confirmar a sessão.');
        }

        const merge = loginResponse?.cart_merge;
        if (merge?.status === 'needs_attention') {
            window.location.assign('/carrinho.html?merge=needs_attention');
        } else if (merge?.status === 'merged' && Number(merge.merged_quantity) > 0) {
            window.location.assign(`/carrinho.html?merge=merged&quantity=${encodeURIComponent(merge.merged_quantity)}`);
        } else {
            window.location.assign(requestedDestination());
        }
    } catch (error) {
        if (error instanceof ApiError && error.status === 422) {
            showValidationErrors(error.errors);
        }
        message.textContent = error instanceof Error && error.message
            ? error.message
            : 'Não foi possível entrar. Tente novamente.';
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = 'Entrar';
        submitButton.removeAttribute('aria-busy');
    }
});

if (parameters.has('registered')) {
    queryMessage.textContent = 'Cadastro realizado. Entre usando suas credenciais.';
} else if (parameters.has('loggedOut')) {
    queryMessage.textContent = 'Sua sessão foi encerrada.';
}

getCurrentUser()
    .then(async (user) => {
        await refreshCartCount(user);
        if (user) {
            window.location.assign(requestedDestination());
        }
    })
    .catch(() => {
        message.textContent = 'Não foi possível verificar sua sessão ou atualizar o carrinho. Você ainda pode tentar entrar.';
    });
