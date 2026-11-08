import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useDebounced } from './use-debounced';

/**
 * Filter halaman daftar yang tersimpan di URL. Ketikan pencarian ditunda sebentar,
 * pilihan lain langsung diterapkan. Nilai kosong tidak ikut ke URL.
 */
export function useFilters<T extends Record<string, string | number>>(routeName: string, initial: T) {
    const [filters, setFilters] = useState<T>(initial);
    const debounced = useDebounced(filters, 350);
    const first = useRef(true);

    useEffect(() => {
        // Lewati render pertama: halaman baru saja dimuat dengan filter yang sama.
        if (first.current) {
            first.current = false;
            return;
        }

        const params = Object.fromEntries(Object.entries(debounced).filter(([, value]) => value !== '' && value !== null));
        router.get(route(routeName), params, { preserveState: true, preserveScroll: true, replace: true });
    }, [debounced, routeName]);

    const set = <K extends keyof T>(key: K, value: T[K]) => setFilters((current) => ({ ...current, [key]: value }));

    return { filters, set, setAll: setFilters };
}
