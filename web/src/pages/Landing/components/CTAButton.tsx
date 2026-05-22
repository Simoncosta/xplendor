import React from 'react';

type CTAVariant = 'primary' | 'outline';
type CTASize = 'sm' | 'lg';

interface CTAButtonProps {
    href: string;
    variant?: CTAVariant;
    size?: CTASize;
    className?: string;
    children: React.ReactNode;
}

const CTAButton: React.FC<CTAButtonProps> = ({
    href,
    variant = 'primary',
    size = 'lg',
    className = '',
    children,
}) => (
    <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
        className={[
            'lp-cta-btn',
            variant === 'outline' ? 'lp-cta-btn-outline' : '',
            size === 'sm' ? 'lp-cta-btn-sm' : '',
            className,
        ]
            .filter(Boolean)
            .join(' ')}
    >
        {children}
    </a>
);

export default CTAButton;
