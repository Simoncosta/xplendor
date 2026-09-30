import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Facts from "@/components/common/Facts";
import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-digital-agency/About";

import Capabilities from "@/components/homes/home-digital-agency/Capabilities";

import Hero from "@/components/homes/home-digital-agency/Hero";
import MarqueeSlider from "@/components/homes/home-digital-agency/MarqueeSlider";
import Partners from "@/components/homes/home-digital-agency/Partners";
import Services from "@/components/homes/home-digital-agency/Services";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Digital Agency || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function HomeDigitalAgencyPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <Services />
        <Capabilities />
        <MarqueeSlider />
        <Facts />
        <About />
        <Partners />
        <Blogs />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
