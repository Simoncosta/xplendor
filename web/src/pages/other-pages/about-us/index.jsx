import Agents from "@/components/common/Agents";
import Brands from "@/components/common/Brands";
import Footer1 from "@/components/footers/Footer1";
import RecomandedCars from "@/components/common/RecomandedCars";
import Header2 from "@/components/headers/Header2";
import Testimonials from "@/components/homes/home-10/Testimonials";
import Features from "@/components/homes/home-3/Features";
import Banner from "@/components/otherPages/about/Banner";

import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "About Us || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function AboutUsPage() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header2 />
      </div>
      <Banner />
      <div className="mt-5 pt-5"></div>
      <Features />
      <Agents parentClass="tf-section3" />
      <Brands />
      <Testimonials />
      <RecomandedCars />
      <Footer1 />
    </>
  );
}
