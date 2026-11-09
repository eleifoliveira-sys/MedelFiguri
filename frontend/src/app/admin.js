import { ApiError, apiRequest } from '../api/api.js';
import { getCurrentUser } from '../domain/auth.js';
import { bindLogoutButtons, updateAuthUI } from '../ui/auth-ui.js';

const message = document.querySelector('[data-message]');
const content = document.querySelector('[data-admin-content]');
const productForm = document.querySelector('[data-product-form]');
const categoryForm = document.querySelector('[data-category-form]');
const stockForm = document.querySelector('[data-stock-form]');
const productFilterButtons = [...document.querySelectorAll('[data-product-status]')];
const labels = { pending: 'Aguardando pagamento', paid: 'Pago', processing: 'Em preparação', shipped: 'Enviado', delivered: 'Entregue', cancelled: 'Cancelado' };
const movementLabels = { sale: 'Venda', adjustment: 'Ajuste', return: 'Devolução' };
let adminProducts = [];
let selectedProductStatus = 'active';

function node(tag, text = '', className = '') {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    return element;
}

function button(text, action, className = 'button button-small button-quiet') {
    const element = node('button', text, className);
    element.type = 'button';
    element.addEventListener('click', async () => {
        element.disabled = true;
        try {
            await action();
        } catch (error) {
            setMessage(error instanceof Error ? error.message : 'Não foi possível concluir a operação.', 'error');
        } finally {
            element.disabled = false;
        }
    });
    return element;
}

function formValues(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function setMessage(text, kind = '') {
    message.textContent = text;
    message.dataset.state = kind;
}

function paginationTotal(response) {
    const total = response?.meta?.total ?? response?.total;
    if (!Number.isInteger(total) || total < 0) {
        throw new Error('Uma resposta administrativa não informou a quantidade total de registros.');
    }

    return total;
}

async function loadAdminData() {
    const [productsResponse, categoriesResponse, ordersResponse, movementsResponse] = await Promise.all([
        apiRequest('/api/admin/products'),
        apiRequest('/api/admin/categories'),
        apiRequest('/api/admin/orders'),
        apiRequest('/api/admin/inventory/movements'),
    ]);
    const products = productsResponse?.data;
    const categories = categoriesResponse?.data;
    const orders = ordersResponse?.data;
    const movements = movementsResponse?.data;
    if (![products, categories, orders, movements].every(Array.isArray)) {
        throw new Error('Uma resposta administrativa está em formato inválido.');
    }
    renderStats(
        paginationTotal(productsResponse),
        paginationTotal(categoriesResponse),
        paginationTotal(ordersResponse),
    );
    renderCategories(categories);
    renderProducts(products);
    renderOrders(orders);
    renderMovements(movements);
}

function renderStats(products, categories, orders) {
    const stats = document.querySelector('[data-stats]');
    stats.replaceChildren();
    for (const [label, value] of [['Produtos', products], ['Categorias', categories], ['Pedidos', orders]]) {
        const card = node('article', '', 'admin-stat');
        card.append(node('span', label), node('strong', String(value)));
        stats.append(card);
    }
}

function renderProducts(products) {
    const container = document.querySelector('[data-products]');
    const categorySelect = document.querySelector('#p-categories');
    const stockSelect = document.querySelector('#stock-product');
    adminProducts = products;
    categorySelect.replaceChildren();
    stockSelect.replaceChildren();
    for (const category of window.adminCategories ?? []) {
        const option = node('option', category.name);
        option.value = String(category.id);
        categorySelect.append(option);
    }
    for (const product of products) {
        const stockOption = node('option', `${product.name} (${product.stock_quantity} em estoque)`);
        stockOption.value = String(product.id);
        stockSelect.append(stockOption);
    }

    renderProductList();
}

function renderProductList() {
    const container = document.querySelector('[data-products]');
    container.replaceChildren();
    for (const filterButton of productFilterButtons) {
        filterButton.setAttribute('aria-pressed', String(filterButton.dataset.productStatus === selectedProductStatus));
    }

    const visibleProducts = adminProducts.filter((product) => product.status === selectedProductStatus);
    if (visibleProducts.length === 0) {
        container.append(node('p', selectedProductStatus === 'active'
            ? 'Nenhum produto ativo.'
            : 'Nenhum produto inativo.'));
        return;
    }

    for (const product of visibleProducts) {
        const row = node('article', '', 'admin-row');
        const status = product.status === 'active' ? 'Ativo' : product.status === 'inactive' ? 'Inativo' : 'Outro';
        row.append(
            node('strong', `${product.name} · Código: ${product.sku}`),
            node('span', `R$ ${product.price} · estoque ${product.stock_quantity} · ${status}`),
        );
        row.append(button('Editar', () => editProduct(product)));
        if (product.status === 'active') {
            row.append(button('Arquivar', async () => {
                await apiRequest(`/api/admin/products/${product.id}`, { method: 'DELETE' });
                await reload();
            }));
        }
        const imageList = node('div', '', 'admin-images');
        for (const image of product.images ?? []) {
            const imageEntry = node('span', image.alt_text || image.path.split('/').pop());
            imageEntry.append(button('Remover imagem', async () => {
                await apiRequest(`/api/admin/products/${product.id}/images/${image.id}`, { method: 'DELETE' });
                await reload();
            }));
            imageList.append(imageEntry);
        }
        const upload = document.createElement('form');
        upload.className = 'admin-upload';
        const file = document.createElement('input');
        file.type = 'file';
        file.name = 'image';
        file.accept = 'image/jpeg,image/png,image/webp';
        file.required = true;
        const alt = document.createElement('input');
        alt.name = 'alt_text';
        alt.maxLength = 255;
        alt.placeholder = 'Texto alternativo';
        const primaryLabel = node('label');
        const primary = document.createElement('input');
        primary.type = 'checkbox';
        primary.name = 'is_primary';
        primaryLabel.append(primary, document.createTextNode(' Principal'));
        const submit = node('button', 'Enviar imagem', 'button button-small');
        submit.type = 'submit';
        upload.append(file, alt, primaryLabel, submit);
        upload.addEventListener('submit', async (event) => {
            event.preventDefault();
            submit.disabled = true;
            try {
                const data = new FormData(upload);
                await apiRequest(`/api/admin/products/${product.id}/images`, { method: 'POST', body: data });
                await reload();
            } catch (error) {
                setMessage(error instanceof Error ? error.message : 'Não foi possível enviar a imagem.', 'error');
            } finally {
                submit.disabled = false;
            }
        });
        row.append(imageList, upload);
        container.append(row);
    }
}

for (const filterButton of productFilterButtons) {
    filterButton.addEventListener('click', () => {
        selectedProductStatus = filterButton.dataset.productStatus;
        renderProductList();
    });
}

function editProduct(product) {
    for (const field of ['id', 'sku', 'name', 'slug', 'description', 'price', 'coloration', 'status']) {
        productForm.elements.namedItem(field).value = product[field] ?? '';
    }
    productForm.elements.namedItem('stock_quantity').value = '0';
    document.querySelector('[data-initial-stock]').hidden = true;
    const selected = new Set((product.categories ?? []).map((category) => String(category.id)));
    for (const option of productForm.elements.namedItem('category_ids').options) {
        option.selected = selected.has(option.value);
    }
    productForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderCategories(categories) {
    window.adminCategories = categories;
    const container = document.querySelector('[data-categories]');
    const parentSelect = document.querySelector('#c-parent');
    container.replaceChildren();
    parentSelect.replaceChildren(node('option', 'Nenhuma'));
    parentSelect.options[0].value = '';
    for (const category of categories) {
        const option = node('option', category.name);
        option.value = String(category.id);
        parentSelect.append(option);
        const row = node('article', '', 'admin-row');
        row.append(node('strong', category.name), node('span', `Endereço: ${category.slug} · ${category.is_active ? 'Ativa' : 'Inativa'}`));
        row.append(button('Editar', () => {
            for (const key of ['id', 'name', 'slug', 'description', 'parent_id']) {
                categoryForm.elements.namedItem(key).value = category[key] ?? '';
            }
        }));
        if (category.is_active) {
            row.append(button('Arquivar', async () => {
                await apiRequest(`/api/admin/categories/${category.id}`, { method: 'DELETE' });
                await reload();
            }));
        }
        container.append(row);
    }
}

function renderOrders(orders) {
    const container = document.querySelector('[data-admin-orders]');
    container.replaceChildren();
    for (const order of orders) {
        const row = node('article', '', 'admin-row');
        row.append(node('strong', `${order.order_number} · ${order.customer.name}`));
        row.append(node('span', `${labels[order.status] ?? 'Outro'} · R$ ${order.total}`));
        row.append(node('span', order.inventory_movements?.map((move) => `${movementLabels[move.movement_type] ?? 'Outro'}: ${move.quantity_delta}`).join(', ') || 'Sem movimentações vinculadas.'));
        const next = { paid: 'processing', processing: 'shipped', shipped: 'delivered' }[order.status];
        if (next) {
            row.append(button(`Avançar para ${labels[next]}`, async () => {
                await apiRequest(`/api/admin/orders/${order.id}/status`, { method: 'PATCH', body: { status: next } });
                await reload();
            }, 'button button-small'));
        }
        container.append(row);
    }
}

function renderMovements(movements) {
    const container = document.querySelector('[data-movements]');
    container.replaceChildren();
    for (const movement of movements) {
        container.append(node('p', `${movement.product_name ?? 'Produto'} · ${movementLabels[movement.movement_type] ?? 'Outro'} ${movement.quantity_delta > 0 ? '+' : ''}${movement.quantity_delta} · ${movement.reason}`));
    }
}

async function reload() {
    setMessage('Atualizando dados administrativos...');
    await loadAdminData();
    setMessage('');
}

productForm.addEventListener('reset', () => {
    queueMicrotask(() => { document.querySelector('[data-initial-stock]').hidden = false; });
});

productForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = productForm.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
        const data = formValues(productForm);
        const id = data.id;
        const categoryIds = Array.from(productForm.elements.namedItem('category_ids').selectedOptions).map((option) => Number(option.value));
        const body = {
            sku: data.sku, name: data.name, slug: data.slug, description: data.description,
            price: data.price, coloration: data.coloration, status: data.status, category_ids: categoryIds,
        };
        if (id) {
            await apiRequest(`/api/admin/products/${id}`, { method: 'PATCH', body });
        } else {
            body.stock_quantity = Number(data.stock_quantity);
            await apiRequest('/api/admin/products', { method: 'POST', body });
        }
        productForm.reset();
        await reload();
    } catch (error) {
        setMessage(error instanceof Error ? error.message : 'Não foi possível salvar o produto.', 'error');
    } finally {
        submit.disabled = false;
    }
});

categoryForm.addEventListener('reset', () => {
    queueMicrotask(() => { categoryForm.elements.namedItem('parent_id').value = ''; });
});

categoryForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = categoryForm.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
        const data = formValues(categoryForm);
        const id = data.id;
        const body = { name: data.name, slug: data.slug, description: data.description || null, parent_id: data.parent_id ? Number(data.parent_id) : null };
        await apiRequest(id ? `/api/admin/categories/${id}` : '/api/admin/categories', {
            method: id ? 'PATCH' : 'POST',
            body,
        });
        categoryForm.reset();
        await reload();
    } catch (error) {
        setMessage(error instanceof Error ? error.message : 'Não foi possível salvar a categoria.', 'error');
    } finally {
        submit.disabled = false;
    }
});

stockForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = stockForm.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
        const data = formValues(stockForm);
        await apiRequest(`/api/admin/products/${data.product_id}/stock-adjustments`, {
            method: 'POST',
            body: { quantity_delta: Number(data.quantity_delta), reason: data.reason },
        });
        stockForm.reset();
        await reload();
    } catch (error) {
        setMessage(error instanceof Error ? error.message : 'Não foi possível ajustar o estoque.', 'error');
    } finally {
        submit.disabled = false;
    }
});

async function initialize() {
    bindLogoutButtons();
    try {
        const user = await getCurrentUser();
        updateAuthUI(user);
        if (!user) {
            window.location.replace('/login.html?next=%2Fadmin.html');
            return;
        }
        await apiRequest('/api/admin/me');
        content.hidden = false;
        await loadAdminData();
        setMessage('');
    } catch (error) {
        content.hidden = true;
        if (error instanceof ApiError && error.status === 401) {
            window.location.replace('/login.html?next=%2Fadmin.html');
            return;
        }
        setMessage(error instanceof Error ? error.message : 'Não foi possível carregar a administração.', 'error');
    }
}

initialize();
