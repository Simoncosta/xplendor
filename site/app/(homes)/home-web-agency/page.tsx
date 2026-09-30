import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-web-agency/About";

import Hero from "@/components/homes/home-web-agency/Hero";
import MarqueeSlider from "@/components/homes/home-web-agency/MarqueeSlider";
import MarqueeSlider2 from "@/components/homes/home-web-agency/MarqueeSlider2";
import MarqueeSlider3 from "@/components/homes/home-web-agency/MarqueeSlider3";
import ParallaxBanner from "@/components/homes/home-web-agency/ParallaxBanner";
import Projects from "@/components/homes/home-web-agency/Projects";
import Services from "@/components/homes/home-web-agency/Services";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Web Agency || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomeWebAgencyPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <MarqueeSlider />
        <ParallaxBanner />
        <About />
        <Services />
        <MarqueeSlider2 />
        <Projects />
        <MarqueeSlider3 />
        <Blogs />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
