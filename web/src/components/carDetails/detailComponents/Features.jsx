import Accordion from "@/components/common/Accordions";
import { features } from "@/data/faqs";
import React from "react";

export default function Features() {
  return (
    <>
      <div className="features-inner tf-collapse-content">
        <div className="inner">
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Touch Screen</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Alloy Wheels</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Fog Lights - Front</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Anti Lock Braking System</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Multi-function Steering Wheel</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Automatic Climate Control</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Power Windows Rear</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Engine Start Stop Button</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Power Steering</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Power Windows Front</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Passenger Airbag</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Driver Airbag</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Air Conditioner</p>
          </div>
          <div className="listing-feature-wrap flex">
            <i className="icon-autodeal-check" />
            <p>Power Adjustable Exterior Rear View Mirror</p>
          </div>
        </div>
      </div>
      <div className="row">
        <div className="col-lg-12 flat-accordion">
          <Accordion parentClass="flat-toggle style-1" faqData={features} />
        </div>
      </div>
    </>
  );
}
