import { apiRequest } from './api.js';
import { bindLogoutButtons, getCurrentUser, updateAuthUI } from './auth.js';
import { refreshCartCount } from './cart-state.js';

const apiStatus = document.querySelector('#api-status');
const authMessage = document.querySelector('[data-auth-message]');
const catalogMessage = document.querySelector('#catalog-message');
const categoryFilters = document.querySelector('#category-filters');
const productGrid = document.querySelector('#product-grid');
const pagination = document.querySelector('#product-pagination');
const modelDialog = document.querySelector('[data-model-dialog]');
const modelTitle = document.querySelector('[data-model-title]');
const modelStatus = document.querySelector('[data-model-status]');
const modelStage = document.querySelector('.model-stage');
const modelViewer = document.querySelector('[data-model-viewer]');
const modelPreview = document.querySelector('[data-model-preview]');
const modelPlaceholder = document.querySelector('[data-model-placeholder]');
const modelProgress = document.querySelector('[data-model-progress]');
const modelRotateButton = document.querySelector('[data-model-rotate]');
const modelResetButton = document.querySelector('[data-model-reset]');
const modelFallback = document.querySelector('[data-model-fallback]');

let categories = [];
let products = [];
let selectedCategory = 'all';
let currentPage = 1;
let lastPage = 1;
let currentUser = null;
let modelViewerModule = null;
let modelDialogTrigger = null;

function storageUrl(path) {
    if (typeof path !== 'string' || path.includes('\\')) {
        return null;
    }

    const segments = path.split('/');
    if (segments.some((segment) => !segment || segment === '.' || segment === '..')) {
        return null;
    }

    return `/api/storage/${segments.map(encodeURIComponent).join('/')}`;
}

function productModel(product) {
    return (product.images ?? []).find((image) => {
        const path = typeof image.path === 'string' ? image.path.toLowerCase() : '';
        return /\.(glb|gltf)$/.test(path) && storageUrl(image.path);
    });
}

function productPreview(product) {
    return (product.images ?? []).find((image) => {
        const path = typeof image.path === 'string' ? image.path.toLowerCase() : '';
        return /\.(jpe?g|png|webp)$/.test(path) && storageUrl(image.path);
    });
}

function loadModelViewer() {
    modelViewerModule ??= import('/model-viewer.min.js');
    return modelViewerModule;
}

function setModelStatus(text, state = '') {
    modelStatus.textContent = text;
    modelStatus.dataset.state = state;
}

function resetModelDialog() {
    if (customElements.get('model-viewer')) {
        modelViewer.src = '';
    } else {
        modelViewer.removeAttribute('src');
    }
    modelViewer.removeAttribute('poster');
    modelViewer.removeAttribute('auto-rotate');
    modelViewer.hidden = true;
    modelPreview.removeAttribute('src');
    modelPreview.alt = '';
    modelPreview.hidden = true;
    modelPlaceholder.textContent = '';
    modelPlaceholder.hidden = true;
    modelProgress.value = 0;
    modelProgress.hidden = true;
    modelRotateButton.disabled = true;
    modelRotateButton.setAttribute('aria-pressed', 'false');
    modelRotateButton.textContent = 'Ativar rotação automática';
    modelResetButton.disabled = true;
    modelFallback.textContent = '';
    modelFallback.hidden = true;
}

function closeModelDialog() {
    if (modelDialog.open) {
        modelDialog.close();
    }
}

async function openModelDialog(product, model, preview, trigger) {
    modelDialogTrigger = trigger;
    modelTitle.textContent = product.name;
    resetModelDialog();
    setModelStatus('Preparando o visualizador 3D...');

    const previewUrl = preview ? storageUrl(preview.path) : null;
    if (previewUrl) {
        modelPreview.src = previewUrl;
        modelPreview.alt = preview.alt_text || `Prévia de ${product.name}`;
        modelPreview.hidden = false;
    } else {
        modelPlaceholder.textContent = 'Carregando modelo 3D...';
        modelPlaceholder.hidden = false;
    }

    modelDialog.showModal();
    modelStage.setAttribute('aria-busy', 'true');

    try {
        await loadModelViewer();
        await customElements.whenDefined('model-viewer');
        if (!modelDialog.open) {
            return;
        }

        modelViewer.hidden = false;
        modelViewer.cameraOrbit = '0deg 75deg 105%';
        modelViewer.cameraTarget = 'auto';
        modelViewer.fieldOfView = '45deg';
        if (previewUrl) {
            modelViewer.setAttribute('poster', previewUrl);
        }
        modelViewer.src = storageUrl(model.path);
        modelProgress.hidden = false;
        setModelStatus('Carregando modelo 3D...');
    } catch {
        modelViewerModule = null;
        modelViewer.hidden = true;
        modelProgress.hidden = true;
        modelStage.removeAttribute('aria-busy');
        modelPlaceholder.textContent = previewUrl
            ? 'A visualização 3D não está disponível. Veja a imagem do produto.'
            : 'A visualização 3D não está disponível para este arquivo.';
        modelPlaceholder.hidden = false;
        modelFallback.hidden = true;
        setModelStatus('Não foi possível iniciar o visualizador 3D.', 'error');
    }
}

modelViewer.addEventListener('progress', (event) => {
    if (!modelDialog.open) {
        return;
    }

    const progress = event.detail.totalProgress;
    modelProgress.value = progress;
    modelProgress.hidden = progress >= 1;
});

modelViewer.addEventListener('load', () => {
    if (!modelDialog.open) {
        return;
    }

    modelStage.removeAttribute('aria-busy');
    modelPreview.hidden = true;
    modelPlaceholder.hidden = true;
    modelProgress.hidden = true;
    modelRotateButton.disabled = false;
    modelResetButton.disabled = false;
    modelFallback.hidden = true;
    setModelStatus('Modelo carregado. Arraste para girar e use a roda ou o gesto de pinça para aproximar.');
});

modelViewer.addEventListener('error', () => {
    if (!modelDialog.open) {
        return;
    }

    modelStage.removeAttribute('aria-busy');
    modelViewer.hidden = true;
    modelProgress.hidden = true;
    modelRotateButton.disabled = true;
    modelResetButton.disabled = true;
    modelPreview.hidden = !modelPreview.src;
    modelPlaceholder.textContent = modelPreview.src
        ? 'Não foi possível carregar o modelo 3D. A imagem do produto está disponível abaixo.'
        : 'Não foi possível carregar este modelo 3D. Tente novamente mais tarde.';
    modelPlaceholder.hidden = false;
    modelFallback.hidden = !modelPreview.src;
    modelFallback.textContent = modelPreview.src
        ? 'Imagem convencional do produto exibida como alternativa.'
        : '';
    setModelStatus('Falha ao carregar o modelo 3D.', 'error');
});

modelRotateButton.addEventListener('click', () => {
    modelViewer.autoRotate = !modelViewer.autoRotate;
    modelRotateButton.setAttribute('aria-pressed', String(modelViewer.autoRotate));
    modelRotateButton.textContent = modelViewer.autoRotate
        ? 'Desativar rotação automática'
        : 'Ativar rotação automática';
});

modelResetButton.addEventListener('click', () => {
    modelViewer.cameraOrbit = '0deg 75deg 105%';
    modelViewer.cameraTarget = 'auto';
    modelViewer.fieldOfView = '45deg';
    modelViewer.jumpCameraToGoal();
});

modelDialog.querySelector('[data-model-close]').addEventListener('click', closeModelDialog);
modelDialog.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeModelDialog();
    }
}, true);
modelDialog.addEventListener('close', () => {
    resetModelDialog();
    modelStage.removeAttribute('aria-busy');
    setModelStatus('');
    modelDialogTrigger?.focus();
    modelDialogTrigger = null;
});

function setApiStatus(state, text) {
    apiStatus.textContent = text;
    apiStatus.dataset.state = state;
}

function categoryFilterButton(label, slug) {
    const button = document.createElement('button');
    button.className = 'filter-button';
    button.type = 'button';
    button.textContent = label;
    button.setAttribute('aria-pressed', String(selectedCategory === slug));
    button.addEventListener('click', () => {
        selectedCategory = slug;
        renderFilters();
        renderProducts();
    });
    return button;
}

function renderFilters() {
    categoryFilters.replaceChildren(categoryFilterButton('Todas', 'all'));

    for (const category of categories) {
        categoryFilters.append(categoryFilterButton(category.name, category.slug));
    }
}

function productMatchesCategory(product) {
    if (selectedCategory === 'all') {
        return true;
    }

    return (product.categories ?? []).some((category) => category.slug === selectedCategory);
}

function productCard(product) {
    const article = document.createElement('article');
    article.className = 'product-card';

    const model = productModel(product);
    const preview = productPreview(product);
    const visual = document.createElement('div');
    visual.className = 'product-visual';
    if (model) {
        const placeholder = document.createElement('span');
        placeholder.className = 'product-visual-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.textContent = 'MF';

        const viewer = document.createElement('model-viewer');
        viewer.className = 'product-model-preview';
        viewer.setAttribute('aria-label', `Ampliar modelo 3D de ${product.name}`);
        viewer.setAttribute('role', 'button');
        viewer.tabIndex = 0;
        viewer.setAttribute('camera-orbit', '0deg 75deg 105%');
        viewer.setAttribute('environment-image', 'neutral');
        viewer.setAttribute('exposure', '1.1');
        viewer.setAttribute('shadow-intensity', '1');
        viewer.setAttribute('shadow-softness', '0.8');
        viewer.setAttribute('loading', 'lazy');
        viewer.setAttribute('reveal', 'auto');
        viewer.setAttribute('auto-rotate', '');
        viewer.setAttribute('rotation-per-second', '20deg');
        viewer.setAttribute('interaction-prompt', 'none');

        const showPreviewError = () => {
            viewer.hidden = true;
            visual.setAttribute('role', 'img');
            visual.setAttribute('aria-label', `A prévia 3D de ${product.name} não está disponível.`);
            visual.title = 'A prévia 3D não está disponível.';
        };
        viewer.addEventListener('load', () => {
            if (viewer.isConnected) {
                viewer.classList.add('is-loaded');
                placeholder.hidden = true;
            }
        });
        viewer.addEventListener('error', showPreviewError);
        viewer.addEventListener('click', () => openModelDialog(product, model, preview, viewer));
        viewer.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openModelDialog(product, model, preview, viewer);
            }
        });

        visual.append(placeholder, viewer);
        loadModelViewer().then(() => {
            if (preview) {
                viewer.setAttribute('poster', storageUrl(preview.path));
            }
            viewer.src = storageUrl(model.path);
        }).catch(showPreviewError);
    } else if (preview) {
        const image = document.createElement('img');
        image.className = 'product-image';
        image.src = storageUrl(preview.path);
        image.alt = preview.alt_text || product.name;
        image.loading = 'lazy';
        image.addEventListener('error', () => {
            visual.removeAttribute('aria-hidden');
            visual.textContent = 'MF';
        }, { once: true });
        visual.append(image);
    } else {
        visual.setAttribute('aria-hidden', 'true');
        visual.textContent = 'MF';
    }
    article.append(visual);

    const content = document.createElement('div');
    content.className = 'product-content';

    const categoryLabel = document.createElement('p');
    categoryLabel.className = 'product-category';
    categoryLabel.textContent = (product.categories ?? []).map((category) => category.name).join(' · ');
    content.append(categoryLabel);

    const title = document.createElement('h3');
    title.textContent = product.name;
    content.append(title);

    const description = document.createElement('p');
    description.className = 'product-description';
    description.textContent = product.description ?? '';
    content.append(description);

    const details = document.createElement('div');
    details.className = 'product-details';
    const price = Number(product.price);
    const priceLabel = document.createElement('strong');
    priceLabel.className = 'product-price';
    priceLabel.textContent = Number.isFinite(price)
        ? price.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
        : 'Preço indisponível';
    details.append(priceLabel);

    const coloration = document.createElement('span');
    coloration.className = 'product-tag';
    coloration.textContent = product.coloration === 'sem-cor' ? 'Sem cor' : 'Colorida';
    details.append(coloration);
    content.append(details);

    const stock = document.createElement('p');
    stock.className = 'product-stock';
    const isAvailable = product.status === 'active' && Number(product.stock_quantity) > 0;
    stock.textContent = isAvailable ? 'Disponível' : 'Indisponível no momento';
    content.append(stock);

    const addButton = document.createElement('button');
    addButton.className = 'button button-small add-to-cart';
    addButton.type = 'button';
    addButton.textContent = 'Adicionar ao carrinho';
    addButton.disabled = !isAvailable;
    addButton.addEventListener('click', async () => {
        const message = document.querySelector('#cart-action-message');
        addButton.disabled = true;
        addButton.textContent = 'Adicionando...';
        message.textContent = '';

        try {
            await apiRequest('/api/cart/items', {
                method: 'POST',
                body: { product_id: product.id, quantity: 1 },
            });
            await refreshCartCount(currentUser);
            message.textContent = `${product.name} foi adicionado ao carrinho.`;
        } catch (error) {
            message.textContent = error instanceof Error
                ? error.message
                : 'Não foi possível adicionar o produto ao carrinho.';
        } finally {
            addButton.disabled = !isAvailable;
            addButton.textContent = 'Adicionar ao carrinho';
        }
    });
    content.append(addButton);

    article.append(content);
    return article;
}

function renderProducts() {
    productGrid.replaceChildren();
    const visibleProducts = products.filter(productMatchesCategory);

    if (visibleProducts.length === 0) {
        catalogMessage.hidden = false;
        catalogMessage.textContent = 'Nenhum produto encontrado nesta categoria.';
    } else {
        catalogMessage.hidden = true;
        visibleProducts.forEach((product) => productGrid.append(productCard(product)));
    }
}

function renderPagination() {
    pagination.replaceChildren();
    pagination.hidden = lastPage <= 1;

    if (lastPage <= 1) {
        return;
    }

    const previous = document.createElement('button');
    previous.className = 'button button-quiet';
    previous.type = 'button';
    previous.textContent = 'Anterior';
    previous.disabled = currentPage <= 1;
    previous.addEventListener('click', () => loadProducts(currentPage - 1));

    const pageLabel = document.createElement('span');
    pageLabel.textContent = `Página ${currentPage} de ${lastPage}`;

    const next = document.createElement('button');
    next.className = 'button button-quiet';
    next.type = 'button';
    next.textContent = 'Próxima';
    next.disabled = currentPage >= lastPage;
    next.addEventListener('click', () => loadProducts(currentPage + 1));

    pagination.append(previous, pageLabel, next);
}

async function loadCategories() {
    try {
        const response = await apiRequest('/api/categories');
        if (!Array.isArray(response)) {
            throw new Error('A resposta de categorias está em formato inválido.');
        }

        categories = response;
        renderFilters();
        renderProducts();
    } catch {
        catalogMessage.hidden = false;
        catalogMessage.textContent = 'Não foi possível carregar as categorias. Tente novamente mais tarde.';
    }
}

async function loadProducts(page = 1) {
    catalogMessage.hidden = false;
    catalogMessage.textContent = 'Carregando catálogo...';

    try {
        const response = await apiRequest(`/api/products?page=${page}`);
        if (!Array.isArray(response?.data)) {
            throw new Error('A resposta do catálogo está em formato inválido.');
        }

        products = response.data;
        currentPage = Number(response.current_page) || page;
        lastPage = Number(response.last_page) || 1;
        renderProducts();
        renderPagination();
    } catch {
        productGrid.replaceChildren();
        pagination.replaceChildren();
        catalogMessage.hidden = false;
        catalogMessage.textContent = 'Não foi possível carregar o catálogo. Tente novamente mais tarde.';
    }
}

async function initializeApiStatus() {
    try {
        await apiRequest('/up');
        setApiStatus('online', 'conectada');
    } catch {
        setApiStatus('offline', 'indisponível no momento');
    }
}

async function initializeAuth() {
    try {
        currentUser = await getCurrentUser();
        updateAuthUI(currentUser);
        await refreshCartCount(currentUser);

        if (currentUser && new URLSearchParams(window.location.search).has('signedIn')) {
            authMessage.textContent = `Bem-vindo, ${currentUser.name}!`;
        }
    } catch {
        authMessage.textContent = 'Não foi possível verificar sua sessão ou atualizar o carrinho neste momento.';
    }
}

bindLogoutButtons();
initializeApiStatus();
initializeAuth();
loadCategories();
loadProducts();
