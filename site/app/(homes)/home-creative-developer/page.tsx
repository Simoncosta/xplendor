import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-creative-developer/About";
import Hero from "@/components/homes/home-creative-developer/Hero";
import ParallaxDivider from "@/components/homes/home-creative-developer/ParallaxDivider";
import Projects from "@/components/homes/home-creative-developer/Projects";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Creative Developer || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomeCreativeDeveloperPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <Projects />
        <About />
        <ParallaxDivider />
        <Blogs />
        <Cta />
      </main>
      <Footer2 text="alexwalker" />
    </>
  );
}
