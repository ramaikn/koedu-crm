import { GraduationCap } from 'lucide-react';
import { SVGAttributes } from 'react';

/**
 * Ikon aplikasi (topi wisuda). Dipakai di sidebar dan halaman login.
 * Ikon berupa garis. Beberapa layout lama masih memberi kelas "fill-current", jadi isian dipaksa kosong lewat style.
 */
export default function AppLogoIcon({ className, ...props }: SVGAttributes<SVGElement>) {
    return <GraduationCap {...props} className={className} style={{ fill: 'none' }} strokeWidth={2} aria-hidden="true" />;
}
