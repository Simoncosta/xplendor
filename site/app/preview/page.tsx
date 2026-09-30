import Hero from "@/components/preview/Hero";

import Demos from "@/components/preview/Demos";
import MarqueeSection from "@/components/preview/MarqueeSection";
import BlogPages from "@/components/preview/BlogPages";
import Features from "@/components/preview/Features";
import MarqueeSection2 from "@/components/preview/MarqueeSection2";
import InnerPagesSlider from "@/components/preview/InnerPagesSlider";
import PagesMarquee from "@/components/preview/PagesMarquee";
import Features2 from "@/components/preview/Features2";
import FeatureCards from "@/components/preview/FeatureCards";
import Cta from "@/components/preview/Cta";
import Footer1 from "@/components/footers/Footer1";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Preview || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function PreviewPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        <Demos />
        <MarqueeSection />
        <BlogPages />
        <Features />
        <MarqueeSection2 />
        <InnerPagesSlider />
        <PagesMarquee />
        <Features2 />
        <FeatureCards />
        <Cta />
      </main>
      <Footer1 />
    </>
  );
}
