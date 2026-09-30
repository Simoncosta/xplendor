"use client";

import {
  useLayoutEffect,
  useEffect,
  useRef,
  Children,
  isValidElement,
} from "react";
import gsap from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

gsap.registerPlugin(ScrollTrigger);

type StackCardsProps = {
  className?: string;
  /** Content rendered above the stack; its height extends the scroll distance */
  offset?: React.ReactNode;
  /** Pin the wrapper during the effect */
  pin?: boolean; // default: true
  /** ScrollTrigger scrub value */
  scrub?: boolean | number; // default: true
  /** Duration multiplier used like index * durationMul */
  durationMul?: number; // default: 0.5
  /** Cards to stack (each child becomes one card) */
  children: React.ReactNode;
  stackName?: string; // default: "services-stack"
};

export default function StackCards({
  className,
  offset,
  pin = true,
  scrub = true,
  durationMul = 0.5,
  children,
  stackName = "services-stack",
}: StackCardsProps) {
  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const offsetRef = useRef<HTMLDivElement | null>(null);

  // stable refs by index (don’t push in render)
  const itemRefs = useRef<HTMLElement[]>([]);

  useLayoutEffect(() => {
    if (typeof window === "undefined") return;
    const wrapper = wrapperRef.current;
    const offsetEl = offsetRef.current;

    // collect current items
    const items = itemRefs.current.filter(Boolean);
    if (!wrapper || items.length === 0) return;

    let tl: gsap.core.Timeline | null = null;
    let st: ScrollTrigger | null = null;

    const initCards = () => {
      // safety: kill previous
      st?.kill();
      tl?.kill();

      // clear previous transforms/styles before measuring
      gsap.set(items, { clearProps: "transform" });

      // measure AFTER clearing transforms
      const first = items[0];
      const cardHeight =
        first.getBoundingClientRect().height || first.offsetHeight;

      tl = gsap.timeline({ defaults: { ease: "none" } });

      items.forEach((card, index) => {
        if (index > 0) {
          // place each below the previous by full height
          gsap.set(card, { y: index * cardHeight, willChange: "transform" });
          // animate each to y:0; later cards take longer (stack effect)
          tl!.to(card, { y: 0, duration: index * durationMul }, 0);
        }
      });

      st = ScrollTrigger.create({
        trigger: wrapper,
        start: "top top",
        pin,
        pinSpacing: true,
        anticipatePin: 1,
        end: () => {
          const extra = offsetEl?.offsetHeight ?? 0;
          // scroll distance = stack travel + any offset block height
          return `+=${items.length * cardHeight + extra}`;
        },
        scrub,
        animation: tl!,
        invalidateOnRefresh: true,
        // markers: true,
      });
    };

    const ctx = gsap.context(() => {
      // Save original styles so refresh/revert won’t leave artifacts
      ScrollTrigger.saveStyles(items);
      initCards();

      // When ScrollTrigger refreshes (resize, font load, etc.), rebuild with fresh measurements
      // ScrollTrigger.addEventListener("refreshInit", initCards);
    }, wrapper);

    return () => {
      // ScrollTrigger.removeEventListener("refreshInit", initCards);
      st?.kill();
      tl?.kill();
      ctx.revert();
    };
  }, [pin, scrub, durationMul, children]);

  // Ensure we refresh once after images/fonts load (critical for reload-at-section)
  useEffect(() => {
    const onLoad = () => {
      // Next tick helps when the browser restores scroll first
      requestAnimationFrame(() => ScrollTrigger.refresh());
    };
    if (document.readyState === "complete") {
      onLoad();
    } else {
      window.addEventListener("load", onLoad, { once: true });
    }
    return () => window.removeEventListener("load", onLoad);
  }, []);

  // Render children with index-stable refs
  const wrappedChildren = Children.map(children, (child, index) => {
    const content = isValidElement(child) ? child : <>{child}</>;
    return (
      <div
        ref={(el) => {
          if (el) itemRefs.current[index] = el;
        }}
        className="stack-item"
        style={{ willChange: "transform" }}
      >
        {content}
      </div>
    );
  });

  return (
    <div ref={wrapperRef} className={className}>
      {/* Offset block rendered BEFORE the cards (now actually using the prop) */}
      <div className="stack-offset" ref={offsetRef}>
        {offset}
      </div>

      {/* Cards */}
      <div className={stackName}>{wrappedChildren}</div>
    </div>
  );
}
