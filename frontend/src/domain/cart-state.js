import { ApiError, apiRequest } from '../api/api.js';

function cartQuantity(response) {
    const quantity = Number(response?.data?.total_quantity);
    if (!Number.isSafeInteger(quantity) || quantity < 0) {
        throw new Error('A resposta do carrinho está em formato inválido.');
    }

    return quantity;
}

function setCartCount(value) {
    document.querySelectorAll('[data-cart-count]').forEach((counter) => {
        counter.textContent = value;
        counter.setAttribute(
            'aria-label',
            value === 'indisponível'
                ? 'Quantidade do carrinho indisponível'
                : `${value} unidade(s) no carrinho`,
        );
    });
}

export async function refreshCartCount(user = null) {
    try {
        const accountOrGuestCart = await apiRequest('/api/cart');
        let total = cartQuantity(accountOrGuestCart);

        if (user) {
            try {
                total += cartQuantity(await apiRequest('/api/cart/guest'));
            } catch (error) {
                if (!(error instanceof ApiError && error.status === 404)) {
                    throw error;
                }
            }
        }

        setCartCount(`(${total})`);
        return total;
    } catch (error) {
        setCartCount('indisponível');
        throw error;
    }
}
