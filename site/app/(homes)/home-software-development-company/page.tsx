import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-software-development-company/About";
import Capabilities from "@/components/homes/home-software-development-company/Capabilities";
import Facts from "@/components/homes/home-software-development-company/Facts";
import Hero from "@/components/homes/home-software-development-company/Hero";
import MarqueeSlider from "@/components/homes/home-software-development-company/MarqueeSlider";
import MarqueeSlider2 from "@/components/homes/home-software-development-company/MarqueeSlider2";
import ParallaxBanner from "@/components/homes/home-software-development-company/ParallaxBanner";
import ParallaxDivider from "@/components/homes/home-software-development-company/ParallaxDivider";
import Projects from "@/components/homes/home-software-development-company/Projects";
import Services from "@/components/homes/home-software-development-company/Services";
import TechStacks from "@/components/homes/home-software-development-company/TechStacks";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Software Development Company || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomeSoftwareDevelopmentCompanyPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <MarqueeSlider />
        <Services />
        <About />
        <ParallaxBanner />
        <Capabilities />
        <Projects />
        <TechStacks />
        <MarqueeSlider2 />
        <Facts />
        <ParallaxDivider />
        <Blogs />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
