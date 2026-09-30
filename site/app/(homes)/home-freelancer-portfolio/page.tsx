import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Facts from "@/components/common/Facts";
import Footer2 from "@/components/footers/Footer2";
import Projects from "@/components/homes/home-1/Projects";

import About from "@/components/homes/home-freelancer-portfolio/About";

import Education from "@/components/common/Education";
import Experiences from "@/components/homes/home-freelancer-portfolio/Experiences";
import Expertise from "@/components/homes/home-freelancer-portfolio/Expertise";

import Hero from "@/components/homes/home-freelancer-portfolio/Hero";
import MarqueeSlider from "@/components/homes/home-freelancer-portfolio/MarqueeSlider";
import ParallaxDivider from "@/components/homes/home-freelancer-portfolio/ParallaxDivider";
import ParallaxDivider2 from "@/components/homes/home-freelancer-portfolio/ParallaxDivider2";
import ParallaxDivider3 from "@/components/homes/home-freelancer-portfolio/ParallaxDivider3";

import TechStack from "@/components/homes/home-freelancer-portfolio/TechStack";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Freelancer Portfolio || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomeFreelancerPortfolioPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <Projects />
        <ParallaxDivider />
        <MarqueeSlider />
        <About />
        <Facts />
        <Expertise />
        <ParallaxDivider2 />
        <Experiences />
        <TechStack />
        <Education />
        <ParallaxDivider3 />
        <Blogs />
        <Cta />
      </main>
      <Footer2 text="alexwalker" />
    </>
  );
}
