import Blogs from "@/components/common/Blogs";
import Brands from "@/components/common/Brands";
import Footer1 from "@/components/footers/Footer1";
import Header1 from "@/components/headers/Header1";
import Banner from "@/components/homes/home-2/Banner";
import CarBrands from "@/components/homes/home-2/CarBrands";
import CarCompare from "@/components/homes/home-2/CarCompare";
import Cars from "@/components/homes/home-2/Cars";
import Cars2 from "@/components/common/Cars2";
import CarSlider from "@/components/common/CarSlider";
import Categories from "@/components/common/Categories2";
import Features from "@/components/homes/home-2/Features";
import Hero from "@/components/homes/home-2/Hero";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Home 02 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function HomePage2() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header1 />
      </div>
      <Hero />
      <Categories />
      <CarSlider />
      <Cars />
      <Features />
      <Banner />
      <CarBrands />
      <CarCompare />
      <Cars2 />
      <Blogs parentClass="section-blog tf-section3" />
      <Brands />
      <Footer1 />
    </>
  );
}
