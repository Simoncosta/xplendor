"use client";
import { ElementType, useLayoutEffect, useRef } from "react";
import gsap from "gsap";
// Tip: if SSR complains, use the dynamic import version shown below.
import Ukiyo from "ukiyojs";
type HtmlTag = keyof HTMLElementTagNameMap;

type UkiyoBgProps<T extends HtmlTag = "div"> = {
  as?: T; // choose the tag; default "div"
  className?: string; // your class with background-image
  scale?: number; // default 1.2
  speed?: number; // default 1.5
  willChange?: boolean; // default true
  wrapperClass?: string; // optional ukiyo wrapper class
  children?: React.ReactNode;
} & Omit<React.ComponentPropsWithoutRef<T>, "as" | "className">;

const BackgroundParallax = <T extends HtmlTag = "div">({
  as,
  className,
  scale = 1.2,
  speed = 1.5,
  willChange = true,
  wrapperClass,
  children,
}: UkiyoBgProps<T>) => {
  const elRef = useRef<HTMLElement | null>(null);
  const Tag = (as ?? "div") as ElementType;
  useLayoutEffect(() => {
    if (!elRef.current) return;

    // Create instance
    const instance = new Ukiyo(elRef.current, {
      scale,
      speed,
      willChange,
      wrapperClass,
      externalRAF: true, // we’ll drive it with GSAP’s ticker
    });

    const tick = () => instance.animate();
    gsap.ticker.add(tick);

    return () => {
      gsap.ticker.remove(tick);
      instance.destroy();
    };
  }, [scale, speed, willChange, wrapperClass]);

  return (
    <Tag ref={elRef} className={className}>
      {children}
    </Tag>
  );
};

export default BackgroundParallax;
