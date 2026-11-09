import { ApiError, apiRequest } from '../api/api.js';
import { getCurrentUser } from '../domain/auth.js';
import { refreshCartCount } from '../domain/cart-state.js';
import { bindLogoutButtons, updateAuthUI } from '../ui/auth-ui.js';

const form = document.querySelector('#checkout-form');
const message = document.querySelector('[data-message]');
const authMessage = document.querySelector('[data-auth-message]');
const itemsContainer = document.querySelector('[data-items]');
const submitButton = document.querySelector('[data-submit]');
let user = null;
let cart = null;

function money(value) {
    if (typeof value !== 'string' || !/^\d+(?:\.\d{1,2})?$/.test(value)) {
        throw new Error('A API retornou um valor monetário inválido.');
    }
    const [whole, fraction = ''] = value.split('.');
    return `R$ ${new Intl.NumberFormat('pt-BR').format(Number(whole))},${fraction.padEnd(2, '0')}`;
}

function setMessage(text, state = '') {
    message.textContent = text;
    message.dataset.state = state;
}

function renderCart() {
    itemsContainer.replaceChildren();
    for (const item of cart.items) {
        const row = document.createElement('p');
        row.className = 'order-line';
        const label = document.createElement('span');
        label.textContent = `${item.product.name} × ${item.quantity}`;
        const amount = document.createElement('strong');
        amount.textContent = money(item.subtotal);
        row.append(label, amount);
        itemsContainer.append(row);
    }
    document.querySelector('[data-subtotal]').textContent = money(cart.subtotal);
    document.querySelector('[data-shipping]').textContent = 'R$ 0,00';
    document.querySelector('[data-total]').textContent = money(cart.subtotal);
}

function showErrors(errors) {
    form.querySelectorAll('[aria-invalid="true"]').forEach((input) => input.removeAttribute('aria-invalid'));
    for (const [key, values] of Object.entries(errors)) {
        const fieldName = key.startsWith('address.') ? key.slice(8) : key;
        const input = form.elements.namedItem(fieldName);
        if (input instanceof HTMLElement) input.setAttribute('aria-invalid', 'true');
        if (Array.isArray(values) && values[0]) setMessage(values[0], 'error');
    }
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity() || !cart?.items.length || submitButton.disabled) return;
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
    submitButton.textContent = 'Confirmando pedido...';
    setMessage('Validando disponibilidade e criando seu pedido...');

    try {
        const formData = new FormData(form);
        const address = Object.fromEntries(formData.entries());
        const result = await apiRequest('/api/checkout', { method: 'POST', body: { address } });
        const order = result?.data;
        if (!order?.id || typeof order.order_number !== 'string') {
            throw new Error('A resposta do pedido está em formato inválido.');
        }
        await refreshCartCount(user);
        const parameters = new URLSearchParams({
            acao: 'pedido-criado',
            pedido: order.order_number,
        });
        window.location.assign(`/meus-pedidos.html?${parameters.toString()}`);
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            window.location.assign('/login.html?next=%2Fcheckout.html');
            return;
        }
        if (error instanceof ApiError && error.status === 422) showErrors(error.errors);
        if (!Object.keys(error?.errors ?? {}).length) {
            setMessage(error instanceof Error ? error.message : 'Não foi possível concluir o pedido.', 'error');
        }
    } finally {
        submitButton.disabled = false;
        submitButton.removeAttribute('aria-busy');
        submitButton.textContent = 'Finalizar compra';
    }
});

async function initialize() {
    bindLogoutButtons();
    try {
        user = await getCurrentUser();
        updateAuthUI(user);
        if (!user) {
            window.location.replace('/login.html?next=%2Fcheckout.html');
            return;
        }
        const response = await apiRequest('/api/cart');
        cart = response?.data;
        if (!Array.isArray(cart?.items) || !cart.items.length) {
            form.hidden = true;
            setMessage('Seu carrinho está vazio. Adicione produtos antes de finalizar a compra.', 'error');
            return;
        }
        const unavailable = cart.items.some((item) => item.product?.is_available !== true
            || Number(item.quantity) > Number(item.product.available_quantity));
        if (unavailable) {
            form.hidden = true;
            setMessage('Há produtos indisponíveis ou com estoque insuficiente. Revise seu carrinho antes de continuar.', 'error');
            return;
        }
        renderCart();
        setMessage('');
        await refreshCartCount(user);
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            window.location.replace('/login.html?next=%2Fcheckout.html');
            return;
        }
        form.hidden = true;
        setMessage(error instanceof Error ? error.message : 'Não foi possível carregar o checkout.', 'error');
    }
}

initialize();
