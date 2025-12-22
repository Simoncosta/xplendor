"use client";

// React
import { useState } from "react";
// Icon
import IconX from "./icon/icon-x";

export default function UBadges({
    badge = 'primary',
    isOutline = true,
    isRounded = true,
    title,
}: {
    badge?: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'error' | 'info' | 'dark';
    isOutline?: boolean;
    isRounded?: boolean;
    title: string;
}) {

    const [isOpen, setIsOpen] = useState(false);

    return (
        <span className={`badge ${isOutline ? `badge-outline-${badge}` : `badge-${badge}`} ${isRounded ? 'rounded-full' : ''}`}>
            {title}
        </span>
    )
}