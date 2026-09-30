import About from "@/components/homes/home-personal-portfolio/About";
import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Hero from "@/components/homes/home-personal-portfolio/Hero";
import MarqueeSlider from "@/components/homes/home-personal-portfolio/MarqueeSlider";
import ParallaxBanner from "@/components/homes/home-personal-portfolio/ParallaxBanner";
import Projects from "@/components/homes/home-personal-portfolio/Projects";
import Services from "@/components/homes/home-personal-portfolio/Services";

import Footer2 from "@/components/footers/Footer2";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Personal Portfolio || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomePersonalPortfolioPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <Projects />
        <MarqueeSlider />
        <Services />
        <About />
        <ParallaxBanner />
        <Blogs />
        <Cta />
      </main>
      <Footer2 text="alexwalker" />
    </>
  );
}
