import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";
import type { ButtonHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

/**
 * Os botões do site (a base do shadcn/ui, com o aspeto próprio): retângulos de cantos quase
 * retos, nunca comprimidos. O principal é âmbar com texto azul escuro; os secundários são de
 * contorno, conforme o fundo.
 */
export const buttonVariants = cva(
    "inline-flex items-center justify-center gap-2 font-sans font-semibold tracking-[0.005em] transition-colors duration-200 rounded-xs disabled:opacity-60 disabled:cursor-not-allowed select-none",
    {
        variants: {
            variant: {
                primary: "bg-amber text-navy hover:bg-[color-mix(in_oklab,var(--color-amber)_88%,white)]",
                outline: "border border-navy text-navy hover:bg-navy hover:text-cream",
                outlineDark: "border border-cream/70 text-cream hover:bg-cream hover:text-navy",
                ghost: "text-current underline underline-offset-4 decoration-current/40 hover:decoration-current px-0",
            },
            size: {
                md: "h-12 px-6 text-[0.98rem]",
                lg: "h-14 px-7 text-base",
                sm: "h-10 px-4 text-sm",
            },
        },
        defaultVariants: { variant: "primary", size: "md" },
    },
);

type Props = ButtonHTMLAttributes<HTMLButtonElement> & VariantProps<typeof buttonVariants> & { asChild?: boolean };

export function Button({ className, variant, size, asChild, ...props }: Props) {
    const Comp = asChild ? Slot : "button";
    return <Comp className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
