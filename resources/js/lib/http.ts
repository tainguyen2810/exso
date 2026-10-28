/** Laravel's XSRF-TOKEN cookie, sent back as the X-XSRF-TOKEN header. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string> = {},
    ) {
        super(message);
    }
}

/**
 * JSON request for the few endpoints that answer JSON (quick add, POS).
 * A 422 is thrown as HttpError with the first message of each field.
 */
export async function sendJson<T>(
    method: 'POST' | 'PUT' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const errors: Record<string, string> = {};

        for (const [key, messages] of Object.entries(
            (data.errors ?? {}) as Record<string, string[]>,
        )) {
            errors[key] = messages[0];
        }

        throw new HttpError(
            data.message ??
                (response.status === 419
                    ? 'Phiên làm việc đã hết hạn, hãy tải lại trang.'
                    : 'Có lỗi xảy ra, hãy thử lại.'),
            response.status,
            errors,
        );
    }

    return data as T;
}

export const postJson = <T>(url: string, body?: unknown) =>
    sendJson<T>('POST', url, body);
