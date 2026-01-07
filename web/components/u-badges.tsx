"use client";

// React
import { useState } from "react";

export default function UBadges({
    badge = 'primary',
    isOutline = true,
    isRounded = true,
    title,
    className,
}: {
    badge?: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'error' | 'info' | 'dark';
    isOutline?: boolean;
    isRounded?: boolean;
    title: string;
    className?: string;
}) {

    const [isOpen, setIsOpen] = useState(false);

    return (
        <span className={`badge ${isOutline ? `badge-outline-${badge}` : `badge-${badge}`} ${isRounded ? 'rounded-full' : ''} ${className}`}>
            {title}
        </span>
    )
}