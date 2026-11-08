import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { ReactNode } from 'react';

interface FieldProps {
    label: string;
    htmlFor?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    className?: string;
    children: ReactNode;
}

/** Label, isian, petunjuk, dan pesan error dalam satu blok yang rapi. */
export function Field({ label, htmlFor, error, hint, required, className, children }: FieldProps) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={htmlFor}>
                {label}
                {required && <span className="text-red-600"> *</span>}
            </Label>
            {children}
            {hint && !error && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} className="whitespace-pre-line" />
        </div>
    );
}
