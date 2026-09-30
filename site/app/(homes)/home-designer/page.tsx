import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-designer/About";
import Hero from "@/components/homes/home-designer/Hero";
import MarqueeSlider from "@/components/homes/home-designer/MarqueeSlider";
import Projects from "@/components/homes/home-designer/Projects";
import Services from "@/components/homes/home-designer/Services";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Designer || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomeDesignerPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <Projects />
        <MarqueeSlider />
        <About />
        <Services />
      </main>
      <Footer2 text="alexwalker" />
    </>
  );
}
