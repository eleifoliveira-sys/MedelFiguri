import { ApiError, apiRequest } from '../api/api.js';
import { getCurrentUser } from '../domain/auth.js';
import { refreshCartCount } from '../domain/cart-state.js';
import { bindLogoutButtons, updateAuthUI } from '../ui/auth-ui.js';

const ordersContainer = document.querySelector('[data-orders]');
const message = document.querySelector('[data-message]');
const pagination = document.querySelector('[data-pagination]');
let currentPage = 1;
let lastPage = 1;
const pageParameters = new URLSearchParams(window.location.search);
const actionMessages = {
    'pedido-criado': (number) => `Pedido ${number} criado com sucesso. Você pode confirmar o pagamento ou cancelar em “Ver detalhes”.`,
    'pagamento-confirmado': (number) => `Pagamento do pedido ${number} confirmado. Nenhuma cobrança real foi realizada.`,
    'pedido-cancelado': (number) => `Pedido ${number} cancelado com sucesso.`,
};

const labels = {
    pending: 'Aguardando pagamento',
    paid: 'Pago',
    processing: 'Em preparação',
    shipped: 'Enviado',
    delivered: 'Entregue',
    cancelled: 'Cancelado',
};

function money(value) {
    const [whole, fraction = '00'] = String(value).split('.');
    return `R$ ${new Intl.NumberFormat('pt-BR').format(Number(whole))},${fraction.padEnd(2, '0')}`;
}

function date(value) {
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? 'Data indisponível' : parsed.toLocaleDateString('pt-BR');
}

function renderOrders(orders) {
    ordersContainer.replaceChildren();
    if (!orders.length) {
        message.textContent = 'Você ainda não tem pedidos.';
        return;
    }
    message.textContent = '';
    for (const order of orders) {
        const article = document.createElement('article');
        article.className = 'order-card';
        const heading = document.createElement('h2');
        heading.textContent = `Pedido ${order.order_number}`;
        const status = document.createElement('p');
        status.className = 'order-status';
        status.textContent = labels[order.status] ?? order.status;
        const meta = document.createElement('p');
        meta.textContent = `${date(order.created_at)} · ${money(order.total)}`;
        const link = document.createElement('a');
        link.className = 'button button-small';
        link.href = `/pedido.html?id=${encodeURIComponent(order.id)}`;
        link.textContent = 'Ver detalhes';
        article.append(heading, status, meta, link);
        ordersContainer.append(article);
    }
}

function showActionResult() {
    const action = pageParameters.get('acao');
    const orderNumber = pageParameters.get('pedido');
    const messageForAction = actionMessages[action];
    if (!messageForAction || !orderNumber) {
        return false;
    }

    message.textContent = messageForAction(orderNumber);
    message.dataset.state = 'success';
    return true;
}

function renderPagination() {
    pagination.replaceChildren();
    pagination.hidden = lastPage <= 1;
    if (lastPage <= 1) return;
    const previous = document.createElement('button');
    previous.className = 'button button-quiet';
    previous.type = 'button';
    previous.textContent = 'Anterior';
    previous.disabled = currentPage <= 1;
    previous.addEventListener('click', () => loadOrders(currentPage - 1));
    const text = document.createElement('span');
    text.textContent = `Página ${currentPage} de ${lastPage}`;
    const next = document.createElement('button');
    next.className = 'button button-quiet';
    next.type = 'button';
    next.textContent = 'Próxima';
    next.disabled = currentPage >= lastPage;
    next.addEventListener('click', () => loadOrders(currentPage + 1));
    pagination.append(previous, text, next);
}

async function loadOrders(page = 1) {
    message.textContent = 'Carregando pedidos...';
    try {
        const response = await apiRequest(`/api/orders?page=${page}`);
        if (!Array.isArray(response?.data)) throw new Error('A resposta dos pedidos está em formato inválido.');
        currentPage = Number(response.current_page) || page;
        lastPage = Number(response.last_page) || 1;
        renderOrders(response.data);
        showActionResult();
        renderPagination();
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            window.location.replace('/login.html?next=%2Fmeus-pedidos.html');
            return;
        }
        message.textContent = error instanceof Error ? error.message : 'Não foi possível carregar seus pedidos.';
    }
}

async function initialize() {
    bindLogoutButtons();
    try {
        const user = await getCurrentUser();
        updateAuthUI(user);
        if (!user) {
            window.location.replace('/login.html?next=%2Fmeus-pedidos.html');
            return;
        }
        await refreshCartCount(user);
        await loadOrders();
    } catch (error) {
        message.textContent = error instanceof Error ? error.message : 'Não foi possível verificar sua sessão.';
    }
}

initialize();
