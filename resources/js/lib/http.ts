/**
 * Panggilan JSON kecil untuk pemeriksaan langsung di form (cek jadwal, saran tutor).
 * Halaman biasa tetap memakai Inertia. Laravel memeriksa token CSRF dari cookie XSRF-TOKEN.
 */

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function postJson<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`Permintaan gagal (${response.status})`);
    }

    return (await response.json()) as T;
}

export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new Error(`Permintaan gagal (${response.status})`);
    }

    return (await response.json()) as T;
}
