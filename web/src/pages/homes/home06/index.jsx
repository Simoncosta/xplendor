import Banner from "@/components/common/Banner";
import Brands from "@/components/common/Brands";
import CarBrands from "@/components/common/CarBrands";
import Cars from "@/components/common/Cars";
import CarSlider from "@/components/common/CarSlider";
import Footer1 from "@/components/footers/Footer1";
import Header2 from "@/components/headers/Header2";
import Blogs from "@/components/common/Blogs2";
import CarBrands2 from "@/components/common/CarBrands2";
import CarCompare from "@/components/common/CarCompare";
import Filter from "@/components/homes/home-6/Filter";
import Hero from "@/components/homes/home-6/Hero";
import LoanCalculaator from "@/components/homes/home-6/Banner2";
import LoanCalculator from "@/components/homes/home-6/LoanCalculator";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Home 06 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function HomePage6() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header2 />
      </div>
      <Hero />
      <Filter />
      <CarBrands />
      <Cars parentClass="tf-section3" />
      <LoanCalculaator />
      <CarBrands2 />
      <Banner />
      <CarSlider />
      <LoanCalculator />
      <CarCompare />
      <Blogs />
      <Brands />
      <Footer1 />
    </>
  );
}
