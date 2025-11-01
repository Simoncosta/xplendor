import Cars2 from "@/components/carsListings/Cars2";
import Footer1 from "@/components/footers/Footer1";
import Header2 from "@/components/headers/Header2";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title:
    "Car Listing Grid || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function ListingGridPage() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header2 />
      </div>
      <section className="tf-banner style-2">
        <div className="container">
          <div className="row">
            <div className="col-lg-12">
              <div className="content relative z-2">
                <div className="heading">
                  <h1 className="text-color-1">
                    Buying and selling cars has <br />
                    never been easier!
                  </h1>
                  <p className="text-color-1 fs-18 fw-4 lh-22 font">
                    Leading online car buying and selling platform. helps users
                    buy <br />
                    cars that are right for them
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <Cars2 />
      <Footer1 />
    </>
  );
}
