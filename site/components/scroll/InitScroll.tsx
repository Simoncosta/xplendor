"use client";
import useGsapScrollScaleAnimations from "@/hooks/useGsapScrollScaleAnimations";
import gsap from "gsap";
import { ScrollTrigger } from "gsap/ScrollTrigger";

gsap.registerPlugin(ScrollTrigger);

export default function InitScroll() {
  useGsapScrollScaleAnimations();

  return null;
}
