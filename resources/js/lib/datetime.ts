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
