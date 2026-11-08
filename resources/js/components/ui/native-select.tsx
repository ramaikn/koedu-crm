import * as React from 'react';

import { cn } from '@/lib/utils';

/** Pilihan bawaan browser. Lebih ringan dan nyaman di HP dibanding dropdown kustom, dengan gaya yang sama dengan Input. */
const NativeSelect = React.forwardRef<HTMLSelectElement, React.ComponentProps<'select'>>(({ className, children, ...props }, ref) => {
    return (
        <select
            className={cn(
                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                className,
            )}
            ref={ref}
            {...props}
        >
            {children}
        </select>
    );
});

NativeSelect.displayName = 'NativeSelect';

export { NativeSelect };
