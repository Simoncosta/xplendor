import Awards from "@/components/common/Awards";
import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-creative-design-studio/About";

import Hero from "@/components/homes/home-creative-design-studio/Hero";
import HeroBg from "@/components/homes/home-creative-design-studio/HeroBg";
import MarqueeSlider from "@/components/homes/home-creative-design-studio/MarqueeSlider";
import Projects from "@/components/homes/home-creative-design-studio/Projects";
import Services from "@/components/homes/home-creative-design-studio/Services";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Creative Digital Studio || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomeCreativeDesignStudioPage() {
  return (
    <>
      <HeroBg />
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <Projects />
        <About />
        <Services />
        <Awards />
        <MarqueeSlider />
        <Blogs />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
