import React from 'react';
import { motion } from 'framer-motion';

interface RevealProps {
    children: React.ReactNode;
    delay?: number;
    y?: number;
    className?: string;
}

/** Entrada subtil ao scroll (fade + slide). Framer Motion — já é dependência.
 *  Respeita prefers-reduced-motion (o próprio Framer o faz com `once`). */
const Reveal: React.FC<RevealProps> = ({ children, delay = 0, y = 22, className }) => (
    <motion.div
        className={className}
        initial={{ opacity: 0, y }}
        whileInView={{ opacity: 1, y: 0 }}
        viewport={{ once: true, amount: 0.2 }}
        transition={{ duration: 0.6, delay, ease: [0.22, 1, 0.36, 1] }}
    >
        {children}
    </motion.div>
);

export default Reveal;
