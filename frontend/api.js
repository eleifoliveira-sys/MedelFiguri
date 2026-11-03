export class ApiError extends Error {
    constructor(message, status, errors = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }
}

function csrfCookie() {
    const cookie = document.cookie
        .split(';')
        .map((value) => value.trim())
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        throw new Error('O cookie de segurança não foi recebido.');
    }

    try {
        return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
    } catch {
        throw new Error('O cookie de segurança recebido é inválido.');
    }
}

function errorMessage(status, payload, path) {
    if (status === 401) {
        return path === '/api/login'
            ? 'As credenciais informadas são inválidas.'
            : 'Sua sessão expirou. Entre novamente para continuar.';
    }

    if (status === 403) {
        return 'Você não tem permissão para realizar esta ação.';
    }

    if (status === 419) {
        return 'A sessão de segurança expirou. Atualize a página e tente novamente.';
    }

    if (status === 422) {
        return payload?.message || 'Revise os campos indicados e tente novamente.';
    }

    if (status === 404) {
        return 'O item solicitado não foi encontrado.';
    }

    if (status === 429) {
        return 'Muitas tentativas. Aguarde um momento antes de tentar novamente.';
    }

    if (status >= 500) {
        return 'Não foi possível concluir a solicitação. Tente novamente mais tarde.';
    }

    return payload?.message || 'Não foi possível concluir a solicitação.';
}

export async function apiRequest(path, { method = 'GET', body, headers = {} } = {}) {
    const requestMethod = method.toUpperCase();
    const requestHeaders = new Headers({
        Accept: 'application/json',
        ...headers,
    });

    const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;
    if (body !== undefined && !isFormData) {
        requestHeaders.set('Content-Type', 'application/json');
    }

    if (!['GET', 'HEAD', 'OPTIONS'].includes(requestMethod)) {
        await apiRequest('/sanctum/csrf-cookie');
        requestHeaders.set('X-XSRF-TOKEN', csrfCookie());
    }

    let response;

    try {
        response = await fetch(path, {
            method: requestMethod,
            headers: requestHeaders,
            credentials: 'include',
            body: body === undefined ? undefined : (isFormData ? body : JSON.stringify(body)),
        });
    } catch {
        throw new Error('Não foi possível conectar à API. Verifique sua conexão e tente novamente.');
    }

    let payload = null;

    if (response.status !== 204 && response.headers.get('content-type')?.includes('json')) {
        try {
            payload = await response.json();
        } catch {
            throw new ApiError('A resposta do servidor está em formato inválido.', response.status);
        }
    }

    if (!response.ok) {
        throw new ApiError(
            errorMessage(response.status, payload, path),
            response.status,
            payload?.errors && typeof payload.errors === 'object' ? payload.errors : {},
        );
    }

    return payload;
}
