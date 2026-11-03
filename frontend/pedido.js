import { ApiError, apiRequest } from './api.js';
import { bindLogoutButtons, getCurrentUser, updateAuthUI } from './auth.js';
import { refreshCartCount } from './cart-state.js';

const message = document.querySelector('[data-message]');
const actionMessage = document.querySelector('[data-action-message]');
const article = document.querySelector('[data-order]');
const confirmButton = document.querySelector('[data-confirm]');
const cancelButton = document.querySelector('[data-cancel]');
const id = new URLSearchParams(location.search).get('id');
const statusNames = { pending: 'Aguardando pagamento', paid: 'Pago', processing: 'Em preparação', shipped: 'Enviado', delivered: 'Entregue', cancelled: 'Cancelado' };
let order = null;

function goToOrders(action) {
    const parameters = new URLSearchParams({
        acao: action,
        pedido: order.order_number,
    });
    window.location.assign(`/meus-pedidos.html?${parameters.toString()}`);
}

function money(value) {
    const [whole, fraction = '00'] = String(value).split('.');
    return `R$ ${new Intl.NumberFormat('pt-BR').format(Number(whole))},${fraction.padEnd(2, '0')}`;
}

function el(tag, text, className = '') {
    const node = document.createElement(tag);
    node.textContent = text;
    if (className) node.className = className;
    return node;
}

function render() {
    article.hidden = false;
    document.querySelector('[data-order-number]').textContent = `Pedido ${order.order_number}`;
    document.querySelector('[data-status]').textContent = statusNames[order.status] ?? order.status;
    document.querySelector('[data-subtotal]').textContent = money(order.subtotal);
    document.querySelector('[data-shipping]').textContent = money(order.shipping_cost);
    document.querySelector('[data-discount]').textContent = money(order.discount_amount);
    document.querySelector('[data-total]').textContent = money(order.total);
    const items = document.querySelector('[data-items]');
    items.replaceChildren();
    for (const item of order.items ?? []) {
        items.append(el('p', `${item.product_name} · ${item.product_sku} × ${item.quantity} — ${money(item.line_total)}`, 'order-line'));
    }
    const address = document.querySelector('[data-address]');
    address.replaceChildren(el('h2', 'Endereço de entrega'), el('p', [
        order.address?.recipient_name, order.address?.street, order.address?.number,
        order.address?.complement, order.address?.neighborhood,
        `${order.address?.city ?? ''} - ${order.address?.state ?? ''}`, order.address?.cep,
    ].filter(Boolean).join(', ')));
    confirmButton.hidden = order.status !== 'pending';
    cancelButton.hidden = !['pending', 'paid'].includes(order.status);
}

async function reload() {
    const response = await apiRequest(`/api/orders/${encodeURIComponent(id)}`);
    order = response?.data;
    if (!order?.id) throw new Error('A API retornou um pedido inválido.');
    render();
}

confirmButton.addEventListener('click', async () => {
    confirmButton.disabled = true;
    confirmButton.textContent = 'Confirmando...';
    actionMessage.textContent = '';
    try {
        const result = await apiRequest(`/api/orders/${order.id}/confirm-payment`, { method: 'POST' });
        if (result?.payment?.simulated !== true) throw new Error('A API não confirmou o pagamento simulado.');
        if (result?.data?.status !== 'paid') throw new Error('O pagamento não foi confirmado para este pedido.');
        order = result.data;
        goToOrders('pagamento-confirmado');
    } catch (error) {
        actionMessage.textContent = error instanceof Error ? error.message : 'Não foi possível confirmar o pagamento.';
        if (error instanceof ApiError && error.status === 422) {
            try {
                await reload();
            } catch (reloadError) {
                const detail = reloadError instanceof Error ? reloadError.message : 'Não foi possível atualizar o pedido.';
                actionMessage.textContent += ` ${detail}`;
            }
        }
    } finally {
        confirmButton.disabled = false;
        confirmButton.textContent = 'Confirmar pagamento simulado';
    }
});

cancelButton.addEventListener('click', async () => {
    if (!window.confirm('Deseja cancelar este pedido?')) return;
    cancelButton.disabled = true;
    try {
        const result = await apiRequest(`/api/orders/${order.id}/cancel`, { method: 'POST' });
        if (result?.data?.status !== 'cancelled') throw new Error('O cancelamento não foi confirmado para este pedido.');
        order = result?.data;
        goToOrders('pedido-cancelado');
    } catch (error) {
        actionMessage.textContent = error instanceof Error ? error.message : 'Não foi possível cancelar o pedido.';
    } finally {
        cancelButton.disabled = false;
    }
});

async function initialize() {
    bindLogoutButtons();
    if (!/^\d+$/.test(id ?? '')) {
        message.textContent = 'Identificador de pedido inválido.';
        return;
    }
    try {
        const user = await getCurrentUser();
        updateAuthUI(user);
        if (!user) {
            window.location.replace(`/login.html?next=${encodeURIComponent(location.pathname + location.search)}`);
            return;
        }
        await refreshCartCount(user);
        await reload();
        message.textContent = new URLSearchParams(location.search).has('created')
            ? 'Pedido criado com sucesso.'
            : '';
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            window.location.replace(`/login.html?next=${encodeURIComponent(location.pathname + location.search)}`);
            return;
        }
        message.textContent = error instanceof Error ? error.message : 'Não foi possível carregar o pedido.';
    }
}

initialize();
