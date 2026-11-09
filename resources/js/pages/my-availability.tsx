import { AvailabilityEditor, availabilityError, type AvailabilitySlot } from '@/components/availability-editor';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface Props {
    /** null bila akun belum ditautkan ke profil tutor. */
    availabilities: AvailabilitySlot[] | null;
    days: { value: number; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Jam tersedia', href: '/my-availability' }];

export default function MyAvailability({ availabilities, days }: Props) {
    const { data, setData, put, processing, errors, isDirty } = useForm({ availabilities: availabilities ?? [] });

    function submit(e: FormEvent) {
        e.preventDefault();
        put(route('my-availability.update'), { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Jam tersedia" />
            <div className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <PageHeader title="Jam tersedia" description="Admin memakai jam ini saat menyusun jadwal bimbingan Anda. Hari tanpa jam berarti tidak tersedia." />

                {availabilities === null ? (
                    <p className="text-muted-foreground rounded-lg border p-4 text-sm">Akun Anda belum terhubung ke profil tutor. Hubungi admin untuk menghubungkannya.</p>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <AvailabilityEditor days={days} value={data.availabilities} onChange={(slots) => setData('availabilities', slots)} error={availabilityError(errors)} />
                        <div>
                            <Button type="submit" disabled={processing || !isDirty}>
                                Simpan
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
