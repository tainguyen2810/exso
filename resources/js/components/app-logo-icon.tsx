import type { SVGAttributes } from 'react';

/** EXSO mark: a stylised "E" made of three bars. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <rect x="6" y="6" width="28" height="7" rx="2" />
            <rect x="6" y="16.5" width="20" height="7" rx="2" />
            <rect x="6" y="27" width="28" height="7" rx="2" />
        </svg>
    );
}
