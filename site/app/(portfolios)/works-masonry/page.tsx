import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import MarqueeSlider from "@/components/portfolios/MarqueeSlider";
import ParallaxDivider from "@/components/portfolios/ParallaxDivider";
import PortfolioMasonry from "@/components/portfolios/PortfolioMasonry";
import Testimonials from "@/components/common/Testimonials";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Work Masonry || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function WorksMasonryPage() {
  return (
    <>
      <main
        id="mxd-page-content"
        className="mxd-page-content inner-page-content"
      >
        <PortfolioMasonry />
        <ParallaxDivider /> <MarqueeSlider />
        <Testimonials />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
