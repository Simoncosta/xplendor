import CarDetails1 from "@/components/carDetails/CarDetails1";
import Footer1 from "@/components/footers/Footer1";
import Header2 from "@/components/headers/Header2";
import React from "react";
import { Link, useParams } from "react-router-dom";
import { allCars } from "@/data/cars";
import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title:
    "Car Details 01 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function BlogListingDetailsPage1() {
  let params = useParams();
  const carItem = allCars.filter((elm) => elm.id == params.id)[0] || allCars[0];
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header2 />
      </div>
      <section className="flat-title mb-40">
        <div className="container2">
          <div className="row">
            <div className="col-lg-12">
              <div className="title-inner style">
                <div className="title-group fs-12">
                  <Link className="home fw-6 text-color-3" to={`/`}>
                    Home
                  </Link>
                  <span>Used cars for sale</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <CarDetails1 carItem={carItem} />
      <Footer1 />
    </>
  );
}
