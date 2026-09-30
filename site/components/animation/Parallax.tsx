"use client";
import { useLayoutEffect, useRef } from "react";
import gsap from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

gsap.registerPlugin(ScrollTrigger);
interface ParallaxItemProps {
  /** Parallax speed; 0 = static, 1 = same as scroll, <1 = slower than scroll, >1 = faster than scroll */
  speed?: number; // default: 0.5
  children: React.ReactNode;
  className?: string;
}
export default function ParallaxItem({
  speed = 0.5,
  children,
  className = "",
}: ParallaxItemProps) {
  const el = useRef(null);

  useLayoutEffect(() => {
    if (!el.current) return;

    const ctx = gsap.context(() => {
      gsap.to(el.current, {
        y: () => (1 - speed) * ScrollTrigger.maxScroll(window),
        ease: "none",
        scrollTrigger: {
          start: 0,
          end: "max",
          invalidateOnRefresh: true,
          scrub: 0,
        },
      });
    }, el);

    ScrollTrigger.refresh();

    return () => ctx.revert();
  }, [speed]);

  return (
    <div ref={el} className={className}>
      {children}
    </div>
  );
}
