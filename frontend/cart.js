import { ApiError, apiRequest } from './api.js';
import { bindLogoutButtons, getCurrentUser, updateAuthUI } from './auth.js';
import { refreshCartCount } from './cart-state.js';

const cartItems = document.querySelector('[data-cart-items]');
const cartMessage = document.querySelector('[data-cart-message]');
const authMessage = document.querySelector('[data-auth-message]');
const mergePanel = document.querySelector('[data-merge-panel]');
const mergeIntro = document.querySelector('[data-merge-intro]');
const mergeConflicts = document.querySelector('[data-merge-conflicts]');
const mergeRetry = document.querySelector('[data-merge-retry]');
const summaryQuantity = document.querySelector('[data-summary-quantity]');
const summarySubtotal = document.querySelector('[data-summary-subtotal]');
const checkoutLink = document.querySelector('[data-checkout-link]');
const busyItems = new Set();
const loginParameters = new URLSearchParams(window.location.search);

let user = null;
let accountCart = null;
let guestCart = null;
let mergeResult = null;
let isMerging = false;
let modelViewerModule = null;

function moneyToCents(value) {
    if (typeof value !== 'string' || !/^\d+(?:\.\d{1,2})?$/.test(value)) {
        throw new Error('O subtotal recebido pela API está em formato inválido.');
    }

    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}

function formatCents(value) {
    const whole = value / 100n;
    const fraction = String(value % 100n).padStart(2, '0');
    return `R$ ${new Intl.NumberFormat('pt-BR').format(whole)},${fraction}`;
}

function listItems(cart) {
    return Array.isArray(cart?.items) ? cart.items : [];
}

function productKey(item) {
    return String(item?.product?.id ?? '');
}

function matchingQuantity(items, item) {
    return items
        .filter((candidate) => productKey(candidate) === productKey(item))
        .reduce((total, candidate) => total + Number(candidate.quantity || 0), 0);
}

function conflictFor(item, otherItems) {
    const product = item.product;
    const available = Number(product.available_quantity);
    const requested = Number(item.quantity) + matchingQuantity(otherItems, item);
    const serverConflict = mergeResult?.conflicts?.find(
        (conflict) => String(conflict.product_id) === productKey(item),
    );

    if (serverConflict?.reason === 'product_unavailable' || product.is_available !== true || available < 1) {
        return {
            available: 0,
            requested: Number(serverConflict?.requested_quantity ?? requested),
            message: 'Este produto não está disponível. Remova-o do carrinho para continuar.',
        };
    }

    if (serverConflict || requested > available) {
        const availableTotal = Number(serverConflict?.available_quantity ?? available);
        const requestedTotal = Number(serverConflict?.requested_quantity ?? requested);
        return {
            available: availableTotal,
            requested: requestedTotal,
            message: `A combinação solicita ${requestedTotal} unidade(s), mas há ${availableTotal} disponível(is). Reduza a quantidade ou remova o item.`,
        };
    }

    return null;
}

function setMessage(text, kind = '') {
    cartMessage.textContent = text;
    cartMessage.dataset.state = kind;
}

function storageUrl(path) {
    if (typeof path !== 'string' || !path || path.includes('\\')
        || path.split('/').some((part) => !part || part === '.' || part === '..')) {
        return null;
    }

    return `/api/storage/${path.split('/').map(encodeURIComponent).join('/')}`;
}

function loadModelViewer() {
    modelViewerModule ??= import('/model-viewer.min.js').catch((error) => {
        modelViewerModule = null;
        throw error;
    });
    return modelViewerModule;
}

function makeElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) {
        element.className = className;
    }
    if (text !== undefined) {
        element.textContent = text;
    }
    return element;
}

function updateMergePanel(conflicts) {
    const queryStatus = loginParameters.get('merge');
    const attention = queryStatus === 'needs_attention' || conflicts.length > 0
        || mergeResult?.status === 'needs_attention';
    const successfulMerge = queryStatus === 'merged';
    const shouldShow = Boolean(user && (attention || successfulMerge || guestCart));

    mergePanel.hidden = !shouldShow;
    mergeConflicts.replaceChildren();
    mergeRetry.hidden = !guestCart;

    if (successfulMerge && !attention) {
        const quantity = Number(loginParameters.get('quantity')) || 0;
        mergeIntro.textContent = quantity > 0
            ? 'Os itens do carrinho de visitante foram combinados com o carrinho da sua conta.'
            : 'A sincronização do carrinho foi concluída.';
        mergePanel.dataset.state = 'success';
        return;
    }

    if (attention) {
        mergeIntro.textContent = 'Seu login foi concluído, mas alguns itens precisam ser revisados antes de combinar os carrinhos.';
        mergePanel.dataset.state = 'warning';
    } else {
        mergeIntro.textContent = 'Há um carrinho de visitante separado. Você pode combiná-lo com o carrinho da sua conta.';
        mergePanel.dataset.state = '';
    }

    const uniqueConflicts = new Map(conflicts.map((conflict) => [String(conflict.product_id), conflict]));
    for (const conflict of uniqueConflicts.values()) {
        const item = makeElement('li', '', `${conflict.name}: ${conflict.message}`);
        mergeConflicts.append(item);
    }
}

function makeItemCard(item, cartType, otherItems, conflicts) {
    const key = `${cartType}:${item.id}`;
    const isBusy = busyItems.has(key) || isMerging;
    const product = item.product;
    const available = Number(product.available_quantity);
    const otherQuantity = matchingQuantity(otherItems, item);
    const maximum = Math.max(0, available - otherQuantity);
    const conflict = conflictFor(item, otherItems);
    const article = makeElement('article', 'cart-item');
    article.dataset.state = conflict ? 'conflict' : '';

    const visual = makeElement('div', 'cart-item-visual');
    const imageSrc = storageUrl(product.image?.path);
    const modelSrc = storageUrl(product.model_path);
    const image = imageSrc ? makeElement('img') : null;
    if (image) {
        image.src = imageSrc;
        image.alt = typeof product.image.alt_text === 'string' ? product.image.alt_text : product.name;
        image.loading = 'lazy';
    }

    if (modelSrc) {
        const placeholder = makeElement('span', '', 'MF');
        placeholder.setAttribute('aria-hidden', 'true');
        const viewer = makeElement('model-viewer', 'cart-item-model');
        viewer.setAttribute('aria-label', `Modelo 3D de ${product.name}`);
        viewer.setAttribute('camera-orbit', '0deg 75deg 125%');
        viewer.setAttribute('camera-target', 'auto');
        viewer.setAttribute('camera-controls', '');
        viewer.setAttribute('environment-image', 'neutral');
        viewer.setAttribute('exposure', '1.1');
        viewer.setAttribute('shadow-intensity', '1');
        viewer.setAttribute('loading', 'lazy');
        viewer.setAttribute('reveal', 'auto');
        viewer.setAttribute('interaction-prompt', 'none');

        const showFallback = () => {
            viewer.hidden = true;
            if (image) {
                placeholder.hidden = true;
                if (!image.isConnected) {
                    visual.append(image);
                }
            } else {
                placeholder.textContent = 'Modelo 3D indisponível';
                placeholder.removeAttribute('aria-hidden');
            }
        };
        viewer.addEventListener('load', () => {
            const center = viewer.getBoundingBoxCenter();
            viewer.cameraTarget = `${center.x}m ${center.y}m ${center.z}m`;
            viewer.cameraOrbit = '0deg 75deg 125%';
            viewer.classList.add('is-loaded');
            placeholder.hidden = true;
        });
        viewer.addEventListener('error', showFallback);
        visual.append(placeholder, viewer);
        loadModelViewer().then(() => {
            if (imageSrc) {
                viewer.setAttribute('poster', imageSrc);
            }
            viewer.src = modelSrc;
        }).catch(showFallback);
    } else if (image) {
        visual.append(image);
    } else {
        visual.textContent = 'MF';
        visual.setAttribute('aria-hidden', 'true');
    }

    const details = makeElement('div', 'cart-item-details');
    details.append(makeElement('h3', '', product.name));
    if (product.sku) {
        details.append(makeElement('p', 'cart-item-sku', `Código do produto: ${product.sku}`));
    }
    details.append(makeElement('p', 'cart-item-price', `${formatCents(moneyToCents(product.unit_price))} por unidade`));
    if (conflict) {
        const conflictIsShownOnGuestItem = cartType === 'account'
            && otherItems.some((candidate) => productKey(candidate) === productKey(item));
        if (!conflictIsShownOnGuestItem) {
            details.append(makeElement('p', 'item-conflict', conflict.message));
            conflicts.push({
                product_id: product.id,
                name: product.name,
                message: conflict.message,
            });
        }
    } else if (product.is_available) {
        details.append(makeElement('p', 'cart-item-stock', `Disponível: ${available} unidade(s)`));
    } else {
        details.append(makeElement('p', 'item-conflict', 'Produto indisponível.'));
    }

    const actions = makeElement('div', 'cart-item-actions');
    const quantityControls = makeElement('div', 'quantity-controls');
    quantityControls.setAttribute('aria-label', `Quantidade de ${product.name}`);

    const decrease = makeElement('button', 'quantity-button', '−');
    decrease.type = 'button';
    decrease.setAttribute('aria-label', `Diminuir quantidade de ${product.name}`);
    decrease.disabled = isBusy || item.quantity <= 1 || !product.is_available;
    decrease.addEventListener('click', () => updateItem(cartType, item, Number(item.quantity) - 1));

    const quantity = makeElement('span', 'quantity-value', String(item.quantity));
    quantity.setAttribute('aria-live', 'polite');

    const increase = makeElement('button', 'quantity-button', '+');
    increase.type = 'button';
    increase.setAttribute('aria-label', `Aumentar quantidade de ${product.name}`);
    increase.disabled = isBusy || !product.is_available || Number(item.quantity) >= maximum;
    increase.addEventListener('click', () => updateItem(cartType, item, Number(item.quantity) + 1));

    quantityControls.append(decrease, quantity, increase);
    actions.append(quantityControls);

    const remove = makeElement('button', 'remove-item', isBusy ? 'Processando...' : 'Remover');
    remove.type = 'button';
    remove.disabled = isBusy;
    remove.addEventListener('click', () => removeItem(cartType, item));
    actions.append(remove);

    article.append(
        visual,
        details,
        makeElement('strong', 'cart-item-subtotal', formatCents(moneyToCents(item.subtotal))),
        actions,
    );
    return article;
}

function renderCart() {
    cartItems.replaceChildren();

    const currentItems = user ? listItems(accountCart) : listItems(accountCart);
    const guestItems = user ? listItems(guestCart) : [];
    const conflicts = [];

    if (user && guestCart) {
        const accountSection = makeElement('section', 'cart-group');
        accountSection.append(makeElement('h2', '', 'Carrinho da conta'));
        const accountList = makeElement('div', 'cart-group-items');
        for (const item of currentItems) {
            accountList.append(makeItemCard(item, 'account', guestItems, conflicts));
        }
        accountSection.append(accountList);

        const guestSection = makeElement('section', 'cart-group');
        guestSection.append(makeElement('h2', '', 'Carrinho de visitante pendente'));
        guestSection.append(makeElement('p', 'cart-group-description', 'Estes itens continuam preservados até a combinação ser concluída.'));
        const guestList = makeElement('div', 'cart-group-items');
        for (const item of guestItems) {
            guestList.append(makeItemCard(item, 'guest', currentItems, conflicts));
        }
        guestSection.append(guestList);
        cartItems.append(accountSection, guestSection);
    } else {
        const section = makeElement('section', 'cart-group');
        section.append(makeElement('h2', '', 'Itens'));
        const list = makeElement('div', 'cart-group-items');
        for (const item of currentItems) {
            list.append(makeItemCard(item, 'account', [], conflicts));
        }
        section.append(list);
        cartItems.append(section);
    }

    const allItems = [...currentItems, ...guestItems];
    if (allItems.length === 0) {
        cartItems.replaceChildren(makeElement('p', 'empty-cart', 'Seu carrinho está vazio.'));
    }

    const quantity = allItems.reduce((sum, item) => sum + Number(item.quantity), 0);
    const subtotalCents = [accountCart, guestCart]
        .filter(Boolean)
        .reduce((sum, cart) => sum + moneyToCents(cart.subtotal), 0n);
    summaryQuantity.textContent = String(quantity);
    summarySubtotal.textContent = formatCents(subtotalCents);
    const checkoutItems = currentItems;
    const checkoutAvailable = checkoutItems.length > 0 && checkoutItems.every((item) =>
        item.product?.is_available === true
        && Number(item.quantity) <= Number(item.product.available_quantity));
    checkoutLink.setAttribute('aria-disabled', String(!checkoutAvailable));
    checkoutLink.classList.toggle('is-disabled', !checkoutAvailable);
    checkoutLink.href = user
        ? '/checkout.html'
        : '/login.html?next=%2Fcheckout.html';

    updateMergePanel(conflicts);

    if (allItems.length === 0 && !cartMessage.textContent) {
        setMessage('Seu carrinho está vazio.');
    } else if (allItems.length > 0 && cartMessage.textContent === 'Seu carrinho está vazio.') {
        setMessage('');
    }
}

checkoutLink.addEventListener('click', (event) => {
    if (checkoutLink.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        setMessage('O carrinho precisa ter itens disponíveis para iniciar o checkout.', 'error');
    }
});

async function loadCarts({ loading = true } = {}) {
    if (loading) {
        setMessage('Carregando carrinho...');
    }

    accountCart = (await apiRequest('/api/cart'))?.data;
    if (!accountCart || !Array.isArray(accountCart.items) || typeof accountCart.subtotal !== 'string') {
        throw new Error('A resposta do carrinho está em formato inválido.');
    }

    guestCart = null;
    if (user) {
        try {
            guestCart = (await apiRequest('/api/cart/guest'))?.data;
            if (!guestCart || !Array.isArray(guestCart.items) || typeof guestCart.subtotal !== 'string') {
                throw new Error('A resposta do carrinho visitante está em formato inválido.');
            }
        } catch (error) {
            if (!(error instanceof ApiError && error.status === 404)) {
                throw error;
            }
        }
    }

    setMessage('');
    renderCart();
}

async function mutateItem(cartType, item, method, quantity) {
    const key = `${cartType}:${item.id}`;
    if (busyItems.has(key)) {
        return;
    }

    busyItems.add(key);
    setMessage(method === 'DELETE' ? 'Removendo item...' : 'Atualizando quantidade...');
    renderCart();

    const guestPath = cartType === 'guest';
    const path = guestPath
        ? `/api/cart/guest/items/${item.id}`
        : `/api/cart/items/${item.id}`;
    const options = {
        method,
        ...(method === 'PATCH' ? { body: { quantity } } : {}),
    };

    try {
        const response = await apiRequest(path, options);
        if (guestPath) {
            guestCart = response?.data;
        } else {
            accountCart = response?.data;
        }
        mergeResult = null;
        setMessage(method === 'DELETE' ? 'O item foi removido.' : 'A quantidade foi atualizada.', 'success');
        await refreshCartCount(user);
    } catch (error) {
        const failureMessage = error instanceof Error ? error.message : 'Não foi possível atualizar o carrinho.';
        try {
            await loadCarts({ loading: false });
            setMessage(failureMessage, 'error');
        } catch {
            setMessage('A solicitação falhou e não foi possível confirmar o estado atualizado do carrinho.', 'error');
        }
    } finally {
        busyItems.delete(key);
        renderCart();
    }
}

function updateItem(cartType, item, quantity) {
    if (!Number.isInteger(quantity) || quantity < 1) {
        return;
    }

    return mutateItem(cartType, item, 'PATCH', quantity);
}

function removeItem(cartType, item) {
    return mutateItem(cartType, item, 'DELETE');
}

mergeRetry.addEventListener('click', async () => {
    if (!guestCart || mergeRetry.disabled) {
        return;
    }

    isMerging = true;
    mergeRetry.disabled = true;
    mergeRetry.textContent = 'Verificando disponibilidade...';
    mergeResult = null;
    renderCart();
    let resultMessage = '';
    let resultKind = '';

    try {
        const response = await apiRequest('/api/cart/merge-guest', { method: 'POST' });
        mergeResult = response?.data;
        if (mergeResult?.status === 'merged') {
            const quantity = Number(mergeResult.merged_quantity) || 0;
            loginParameters.set('merge', 'merged');
            loginParameters.set('quantity', String(quantity));
            history.replaceState(null, '', `/carrinho.html?${loginParameters.toString()}`);
            resultMessage = quantity > 0
                ? 'Os carrinhos foram combinados com sucesso.'
                : 'A sincronização do carrinho foi concluída.';
            resultKind = 'success';
        } else if (mergeResult?.status === 'needs_attention') {
            resultMessage = 'Ainda há itens que precisam ser revisados antes da combinação.';
            resultKind = 'error';
        } else {
            resultMessage = 'Não há um carrinho visitante disponível para combinar.';
            resultKind = 'error';
        }

        await loadCarts({ loading: false });
        await refreshCartCount(user);
        setMessage(resultMessage, resultKind);
    } catch (error) {
        setMessage(error instanceof Error ? error.message : 'Não foi possível combinar os carrinhos.', 'error');
    } finally {
        isMerging = false;
        mergeRetry.disabled = false;
        mergeRetry.textContent = 'Tentar combinar carrinhos novamente';
        renderCart();
    }
});

async function initialize() {
    bindLogoutButtons();

    try {
        user = await getCurrentUser();
        updateAuthUI(user);
        if (user) {
            authMessage.textContent = '';
        }

        await loadCarts();
        await refreshCartCount(user);
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            authMessage.textContent = 'Sua sessão expirou. Entre novamente para continuar.';
            const loginLink = makeElement('a', 'text-link', 'Entrar');
            loginLink.href = '/login.html';
            authMessage.append(' ', loginLink);
        } else {
            setMessage(error instanceof Error ? error.message : 'Não foi possível carregar o carrinho.', 'error');
        }
    }
}

initialize();
