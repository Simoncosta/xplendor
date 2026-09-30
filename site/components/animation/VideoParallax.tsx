"use client";
import { useLayoutEffect, useRef } from "react";
import gsap from "gsap";
// Tip: if SSR complains, use the dynamic import version shown below.
import Ukiyo from "ukiyojs";

type UkiyoBgProps = {
  className?: string; // your class with background-image
  scale?: number; // default 1.2
  speed?: number; // default 1.5
  willChange?: boolean; // default true
  src?: string; // optional ukiyo wrapper class
  poster?: string; // optional ukiyo wrapper class
  wrapperClass?: string; // optional ukiyo wrapper class
};

const VideoParallax = ({
  className,
  scale = 1.2,
  speed = 1.5,
  willChange = true,
  wrapperClass,
  src = "/video/1920x1080_video-05.webm",
  poster = "/video/1920x1080_video-05.webp",
}: UkiyoBgProps) => {
  const elRef = useRef<HTMLVideoElement | null>(null);

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
    <video
      preload="auto"
      autoPlay
      loop
      muted
      src={src}
      poster={poster}
      ref={elRef}
      className={className}
    ></video>
  );
};

export default VideoParallax;
