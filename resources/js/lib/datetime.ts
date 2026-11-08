/**
 * Format tanggal dan jam dalam zona waktu kantor (dikirim server lewat props bersama "timezone"),
 * supaya jam yang tampil sama untuk semua staf, apa pun zona waktu browsernya.
 */

export function formatTime(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone }).format(new Date(iso));
}

export function formatDayMonth(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', timeZone }).format(new Date(iso));
}

/** Jam bila hari ini, tanggal bila sebelumnya. Dipakai di daftar percakapan. */
export function formatListTime(iso: string | null, timeZone: string): string {
    if (!iso) return '';

    const dayKey = (date: Date) => new Intl.DateTimeFormat('en-CA', { timeZone }).format(date);

    return dayKey(new Date(iso)) === dayKey(new Date()) ? formatTime(iso, timeZone) : formatDayMonth(iso, timeZone);
}

/** "Senin, 9 November 2026" dari waktu ISO. */
export function formatLongDate(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone }).format(new Date(iso));
}

/** "9 Nov 2026" dari waktu ISO. */
export function formatDate(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone }).format(new Date(iso));
}

/** "9 Nov 2026, 10.00" dari waktu ISO. */
export function formatDateTime(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone }).format(
        new Date(iso),
    );
}

/** Tanggal polos "YYYY-MM-DD" (tanpa jam, tanpa konversi zona) menjadi "9 Nov 2026". */
export function formatDateOnly(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day)));
}

/** "Senin, 9 November 2026" dari tanggal polos "YYYY-MM-DD". */
export function formatDayHeading(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    return new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(year, month - 1, day)),
    );
}

/** Tanggal hari ini dalam zona waktu kantor, format "YYYY-MM-DD". */
export function todayIn(timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone }).format(new Date());
}

/** Geser tanggal polos "YYYY-MM-DD" sebanyak n hari. */
export function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, month - 1, day + days));
    return shifted.toISOString().slice(0, 10);
}
