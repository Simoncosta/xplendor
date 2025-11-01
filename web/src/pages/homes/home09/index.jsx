import Blogs2 from "@/components/common/Blogs2";
import Brands from "@/components/common/Brands";
import CarBrands from "@/components/common/CarBrands";
import Categories2 from "@/components/common/Categories2";
import Cta from "@/components/common/Cta";
import Features from "@/components/common/Features";
import Footer1 from "@/components/footers/Footer1";
import Header1 from "@/components/headers/Header1";
import Cars from "@/components/homes/home-9/Cars";
import Cars2 from "@/components/homes/home-9/Cars2";
import Cars3 from "@/components/homes/home-9/Cars3";
import Hero from "@/components/homes/home-9/Hero";
import Testimonials from "@/components/homes/home-9/Testimonials";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Home 09 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function HomePage9() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header1 />
      </div>
      <Hero />
      <CarBrands />
      <Cars />
      <Cta />
      <Cars2 />
      <Features />
      <Categories2 parentClass="tf-section3 pb-0" />
      <div className="mt-5 pt-5"></div>
      <Cars3 />
      <Testimonials />
      <Blogs2 />
      <Brands />
      <Footer1 />
    </>
  );
}
