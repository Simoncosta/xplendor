"use client";
import React, { useEffect, useRef } from "react";
import gsap from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";
import SplitType from "split-type";

gsap.registerPlugin(ScrollTrigger);

// ✅ Only allow HTML tags here, not SVG:
type HtmlTag = keyof HTMLElementTagNameMap; // 'div' | 'span' | 'h1' | 'a' | ...

type RevealTextProps<T extends HtmlTag = "span"> = {
  as?: T;
  className?: string;
  children: React.ReactNode;
  start?: string;
  end?: string;
  scrub?: boolean | number;
  stagger?: number;
  opacityFrom?: number;
} & Omit<React.ComponentPropsWithoutRef<T>, "as" | "children" | "className">;

export default function RevealText<T extends HtmlTag = "span">({
  as,
  className,
  children,
  start = "top 80%",
  end = "top 20%",
  scrub = true,
  stagger = 0.1,
  opacityFrom = 0.2,
  ...rest
}: RevealTextProps<T>) {
  const Tag = (as || "span") as unknown as React.ElementType;
  const elRef = useRef<HTMLElement | null>(null);
  const animRef = useRef<gsap.core.Tween | null>(null);
  const splitRef = useRef<SplitType | null>(null);

  useEffect(() => {
    const createAnimation = () => {
      if (!elRef.current) return;

      // Revert previous split if exists
      splitRef.current?.revert();

      const split = new SplitType(elRef.current, { types: "words,chars" });
      splitRef.current = split;

      const anim = gsap.from(split.chars, {
        scrollTrigger: {
          trigger: elRef.current,
          start,
          end,
          scrub,
          toggleActions: "play none none reverse",
        },
        opacity: opacityFrom,
        duration: 1.2,
        stagger,
        ease: "power2.out",
      });

      animRef.current = anim;
    };
    // Small delay to ensure DOM is ready
    setTimeout(() => {
      createAnimation();
    }, 100);

    // Remove global refresh listener to prevent conflicts
    // Individual components should handle their own refresh needs

    return () => {
      // Clean up animation
      animRef.current?.kill();

      // Clean up SplitType
      if (splitRef.current) {
        splitRef.current.revert();
        splitRef.current = null;
      }

      // Clean up ScrollTrigger instances for this element
      ScrollTrigger.getAll()
        .filter((t) => t.vars.trigger === elRef.current)
        .forEach((t) => t.kill());

      // No global refresh listener to remove
    };
  }, [start, end, scrub, stagger, opacityFrom]);

  return (
    <Tag
      ref={elRef}
      className={["reveal-type", className].filter(Boolean).join(" ")}
      {...rest}
    >
      {children}
    </Tag>
  );
}
