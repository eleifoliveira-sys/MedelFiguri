import { ApiError, apiRequest } from './api.js';
import { getCurrentUser } from './auth.js';
import { refreshCartCount } from './cart-state.js';

const form = document.querySelector('#register-form');
const message = document.querySelector('[data-message]');
const successMessage = document.querySelector('[data-success]');
const submitButton = document.querySelector('[data-submit]');

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

form.addEventListener('change', (event) => {
    const field = event.target;
    if (field instanceof HTMLElement) {
        field.removeAttribute('aria-invalid');
        const errorElement = document.getElementById(`${field.getAttribute('name')}-error`);
        if (errorElement) {
            errorElement.textContent = '';
        }
    }
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearErrors();
    message.textContent = '';
    successMessage.hidden = true;

    if (!form.reportValidity()) {
        return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Criando conta...';
    submitButton.setAttribute('aria-busy', 'true');

    try {
        const formData = new FormData(form);
        await apiRequest('/api/register', {
            method: 'POST',
            body: {
                name: formData.get('name'),
                username: formData.get('username'),
                email: formData.get('email'),
                password: formData.get('password'),
                password_confirmation: formData.get('password_confirmation'),
                terms_accepted: formData.get('terms_accepted') === 'on',
            },
        });

        form.reset();
        successMessage.hidden = false;
    } catch (error) {
        if (error instanceof ApiError && error.status === 422) {
            showValidationErrors(error.errors);
        }
        message.textContent = error instanceof Error && error.message
            ? error.message
            : 'Não foi possível criar sua conta. Tente novamente.';
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = 'Criar conta';
        submitButton.removeAttribute('aria-busy');
    }
});

getCurrentUser()
    .then(async (user) => {
        await refreshCartCount(user);
        if (user) {
            window.location.assign('/');
        }
    })
    .catch(() => {
        message.textContent = 'Não foi possível verificar sua sessão ou atualizar o carrinho. Você ainda pode criar uma conta.';
    });
