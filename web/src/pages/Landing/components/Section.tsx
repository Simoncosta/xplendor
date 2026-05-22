import React from 'react';
import { Container } from 'reactstrap';

type SectionVariant = 'default' | 'alt' | 'dark' | 'accent';

interface SectionProps {
    id?: string;
    variant?: SectionVariant;
    className?: string;
    children: React.ReactNode;
}

const variantClass: Record<SectionVariant, string> = {
    default: '',
    alt: 'lp-section-alt',
    dark: 'lp-section-dark',
    accent: 'lp-section-accent',
};

const Section: React.FC<SectionProps> = ({
    id,
    variant = 'default',
    className = '',
    children,
}) => (
    <section id={id} className={`lp-section lp-reveal ${variantClass[variant]} ${className}`.trim()}>
        <Container>
            {children}
        </Container>
    </section>
);

export default Section;
