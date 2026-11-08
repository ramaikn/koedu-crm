/** Angka rupiah tanpa desimal, mis. 1499000 menjadi "Rp 1.499.000". */
export function rupiah(amount: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount).replace(/\s/g, ' ');
}

/** Nomor WA 62xxx ditampilkan sebagai +62 xxx. */
export function phoneLabel(phone: string): string {
    return `+${phone}`;
}

/** Masa aktif paket: 30 hari menjadi "1 bulan", 7 menjadi "1 minggu", dan seterusnya. */
export function durationLabel(days: number): string {
    if (days % 30 === 0) return `${days / 30} bulan`;
    if (days % 7 === 0) return `${days / 7} minggu`;
    return `${days} hari`;
}
