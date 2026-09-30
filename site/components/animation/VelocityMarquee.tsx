"use client";

import { useLayoutEffect, useMemo, useRef } from "react";
import gsap from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

gsap.registerPlugin(ScrollTrigger);

type VelocityMarqueeProps = {
  className?: string;
  duration?: number;
  direction?: "left" | "right";
  minScale?: number;
  maxScale?: number;
  pauseOnHover?: boolean;
  children: React.ReactNode;
};

export default function VelocityMarquee({
  className,
  duration = 20,
  direction = "right",
  minScale = 1,
  maxScale = 6,
  pauseOnHover = false,
  children,
}: VelocityMarqueeProps) {
  const wrapRef = useRef<HTMLDivElement | null>(null);
  const trackRef = useRef<HTMLDivElement | null>(null);

  // ✅ left should be NEGATIVE, right should be POSITIVE
  const dirDelta = useMemo(
    () => (direction === "left" ? "-=50%" : "+=50%"),
    [direction]
  );

  useLayoutEffect(() => {
    if (typeof window === "undefined") return;

    const wrap = wrapRef.current;
    const track = trackRef.current;
    if (!wrap || !track) return;

    let master: gsap.core.Timeline | null = null;
    let kicker: gsap.core.Tween | null = null;
    let st: ScrollTrigger | null = null;

    const ctx = gsap.context(() => {
      const clamp = gsap.utils.clamp(minScale, maxScale);

      // 🔄 Reset any previous transform so re-renders (changing direction)
      // don't accumulate an off-screen x.
      gsap.set(track, { clearProps: "x,xPercent", x: 0, xPercent: 0 });

      // Give a tiny starting offset so the wrap is seamless both ways.
      // For rightward motion, start half-way left; for leftward, start at 0.
      if (direction === "right") {
        gsap.set(track, { xPercent: -50 });
      }

      const marquee = (el: HTMLElement, dur: number, delta: string) =>
        gsap.to(el, { x: delta, duration: dur, ease: "none", repeat: -1 });

      master = gsap.timeline({ defaults: { overwrite: "auto" } });
      master.add(marquee(track, duration, dirDelta), 0);

      kicker = gsap.to(master, { duration: 1.5, timeScale: 1, paused: true });

      st = ScrollTrigger.create({
        start: 0,
        end: "max",
        onUpdate(self) {
          const scale = clamp(Math.abs(self.getVelocity() / 200));
          master!.timeScale(scale);
          kicker!.invalidate().restart();
        },
      });

      if (pauseOnHover) {
        const onEnter = () => master?.pause();
        const onLeave = () => master?.play();
        wrap.addEventListener("mouseenter", onEnter);
        wrap.addEventListener("mouseleave", onLeave);

        // cleanup listeners
        ctx.add(() => {
          wrap.removeEventListener("mouseenter", onEnter);
          wrap.removeEventListener("mouseleave", onLeave);
        });
      }
    }, wrap);

    return () => {
      st?.kill();
      kicker?.kill();
      master?.kill();
      ctx.revert();
    };
  }, [dirDelta, direction, duration, minScale, maxScale, pauseOnHover]);

  return (
    <div ref={wrapRef} className={className} style={{ overflow: "hidden" }}>
      {/* 🚫 remove direction-specific CSS class */}
      <div
        ref={trackRef}
        className="marquee-flex"
        style={{
          display: "inline-flex",
          whiteSpace: "nowrap",
          willChange: "transform",
        }}
      >
        {children}
        {children}
      </div>
    </div>
  );
}
