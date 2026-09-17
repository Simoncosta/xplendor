import React from 'react';
import { motion, useReducedMotion, type Variants } from 'framer-motion';

/**
 * Primitivas de animação da LANDING (isoladas — só usadas nos componentes da
 * landing; framer-motion não injeta CSS global, logo não toca no painel Velzon).
 * Todas respeitam prefers-reduced-motion (sem movimento → aparecem já visíveis).
 */

const EASE = [0.22, 1, 0.36, 1] as const;

export const fadeUp: Variants = {
    hidden: { opacity: 0, y: 28 },
    show: { opacity: 1, y: 0, transition: { duration: 0.6, ease: EASE } },
};

export const staggerContainer: Variants = {
    hidden: {},
    show: { transition: { staggerChildren: 0.1, delayChildren: 0.05 } },
};

type RevealProps = {
    children: React.ReactNode;
    className?: string;
    /** atraso extra em segundos */
    delay?: number;
    /** tag a renderizar (default div) */
    as?: 'div' | 'section' | 'li' | 'span';
};

/**
 * Revela o conteúdo com fade + slide-up quando entra no viewport (uma vez).
 * Com reduced-motion, renderiza estático (sem animação).
 */
export const Reveal: React.FC<RevealProps> = ({ children, className, delay = 0, as = 'div' }) => {
    const reduce = useReducedMotion();
    const MotionTag = motion[as] as typeof motion.div;

    if (reduce) {
        const Tag = as;
        return <Tag className={className}>{children}</Tag>;
    }

    return (
        <MotionTag
            className={className}
            variants={fadeUp}
            initial="hidden"
            whileInView="show"
            viewport={{ once: true, amount: 0.2 }}
            transition={{ delay }}
        >
            {children}
        </MotionTag>
    );
};

/**
 * Container que faz stagger dos filhos ao entrar no viewport. Os filhos diretos
 * devem usar `variants={fadeUp}` (ou <RevealItem>). Estático com reduced-motion.
 */
export const RevealGroup: React.FC<{ children: React.ReactNode; className?: string }> = ({
    children,
    className,
}) => {
    const reduce = useReducedMotion();
    if (reduce) return <div className={className}>{children}</div>;

    return (
        <motion.div
            className={className}
            variants={staggerContainer}
            initial="hidden"
            whileInView="show"
            viewport={{ once: true, amount: 0.15 }}
        >
            {children}
        </motion.div>
    );
};

/** Item para usar dentro de <RevealGroup>. Estático com reduced-motion. */
export const RevealItem: React.FC<{ children: React.ReactNode; className?: string }> = ({
    children,
    className,
}) => {
    const reduce = useReducedMotion();
    if (reduce) return <div className={className}>{children}</div>;
    return (
        <motion.div className={className} variants={fadeUp}>
            {children}
        </motion.div>
    );
};
