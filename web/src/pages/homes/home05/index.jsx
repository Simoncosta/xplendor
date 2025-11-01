import Cars3 from "@/components/common/Cars3";

import Header1 from "@/components/headers/Header1";
import CarBrands from "@/components/common/CarBrands";
import Cars from "@/components/homes/home-5/Cars";
import Category from "@/components/homes/home-5/Category";
import Features from "@/components/homes/home-5/Features";
import Filter from "@/components/homes/home-5/Filter";
import Hero from "@/components/homes/home-5/Hero";
import React from "react";
import Banner from "@/components/common/Banner";
import Agents from "@/components/common/Agents";
import Brands from "@/components/common/Brands";
import Blogs from "@/components/common/Blogs";
import Footer1 from "@/components/footers/Footer1";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Home 05 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function HomePage5() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header1 />
      </div>
      <Hero />
      <Filter />
      <div className="mt-5 pt-5"></div>

      <Cars3 />
      <Category />
      <Features />
      <Cars />
      <CarBrands />
      <Banner />
      <Agents />
      <Brands />
      <Blogs parentClass="section-blog tf-section3" />
      <Footer1 />
    </>
  );
}
