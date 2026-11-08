import { useEffect, useState } from 'react';

/** Nilai yang baru berubah setelah pengguna berhenti mengetik, supaya tidak memanggil server di tiap ketukan. */
export function useDebounced<T>(value: T, delayMs = 300): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delayMs);
        return () => clearTimeout(timer);
    }, [value, delayMs]);

    return debounced;
}
