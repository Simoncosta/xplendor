"use client";

import {
  useEffect,
  useRef,
  ElementType,
  ComponentPropsWithoutRef,
} from "react";
import gsap from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

gsap.registerPlugin(ScrollTrigger);

type OwnProps = {
  value: number; // rotation value like data-value
  className?: string;
  children: React.ReactNode;
};

// Polymorphic wrapper
type PolymorphicProps<As extends ElementType> = OwnProps &
  Omit<
    ComponentPropsWithoutRef<As>,
    keyof OwnProps | "className" | "children"
  > & {
    as?: As;
  };

export default function AnimateRotation<As extends ElementType = "div">(
  props: PolymorphicProps<As>
) {
  const { as, className, children, value, ...rest } = props;
  const Tag = (as || "div") as ElementType;
  const elRef = useRef<HTMLElement | null>(null);

  useEffect(() => {
    const createAnimation = () => {
      if (!elRef.current) return;

      const anim = gsap.fromTo(
        elRef.current,
        { rotate: 0 },
        {
          rotate: value,
          ease: "sine",
          scrollTrigger: {
            trigger: elRef.current,
            scrub: true,
            toggleActions: "play none none reverse",
          },
        }
      );

      return anim;
    };

    const anim = createAnimation();

    // Remove global refresh listener to prevent conflicts
    // Individual components should handle their own refresh needs

    return () => {
      anim?.kill();
      ScrollTrigger.getAll()
        // eslint-disable-next-line react-hooks/exhaustive-deps
        .filter((t) => t.trigger === elRef.current)
        .forEach((t) => t.kill());
      // No global refresh listener to remove
    };
  }, [value]);

  return (
    <Tag ref={elRef} className={className} {...rest}>
      {children}
    </Tag>
  );
}
