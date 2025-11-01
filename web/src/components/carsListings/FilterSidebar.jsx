import { featureOptions } from "@/data/filterOptions";
import Pricing from "../common/Pricing";
import DropdownSelect from "../common/DropDownSelect";

export default function FilterSidebar({ allProps, clearFilter }) {
  return (
    <div className="offcanvas offcanvas-end" tabIndex={-1} id="offcanvasRight">
      <div className="offcanvas-header">
        <h4 className="offcanvas-title" id="offcanvasRightLabel">
          Filters and Sort
        </h4>
        <button
          type="button"
          className="btn-close"
          data-bs-dismiss="offcanvas"
          aria-label="Close"
        />
      </div>
      <div className="offcanvas-body">
        <a
          className="tf-btn-arrow wow fadeInUpSmall clear-filter mb-2"
          onClick={clearFilter}
        >
          <i
            className="icon-autodeal-plus "
            style={{ transform: "rotate(25deg)" }}
          />{" "}
          Clear Filter
        </a>
        <div className="form-filter-siderbar">
          <form onSubmit={(e) => e.preventDefault()}>
            <div className="wd-find-select">
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.make}
                    onChange={allProps.setMake}
                    options={["Any Make", "Audi", "Dongfeng", "BMW"]}
                  />
                </div>
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.model}
                    onChange={allProps.setModel}
                    options={["Any Model", "A4", "Almera", "Carnival"]}
                  />
                </div>
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.body}
                    onChange={allProps.setBody}
                    options={["Any Body", "Convertible", "Coupe", "Crossover"]}
                  />{" "}
                </div>
              </div>
              <div className="form-group wg-box3">
                <div className="widget widget-price">
                  <div className="caption flex-two">
                    <div>
                      <span className="fw-6">
                        Price: ${allProps.price[0]} - ${allProps.price[1]}
                      </span>
                    </div>
                  </div>
                  <Pricing
                    MIN={45000}
                    MAX={115000}
                    priceRange={allProps.price}
                    setPriceRange={allProps.setPrice}
                  />
                </div>
                {/* /.widget_price */}
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.fuel}
                    onChange={allProps.setFuel}
                    options={["Any Fuel", "Diesel", "Petrol"]}
                  />
                </div>
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.transmission}
                    onChange={allProps.setTransmission}
                    options={["Any Transmission", "Automatic", "Manual"]}
                  />
                </div>
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.location}
                    onChange={allProps.setLocation}
                    options={["Any Location", "London", "New York", "Paris"]}
                  />
                </div>
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.door}
                    onChange={allProps.setDoor}
                    options={["Any Door", "2 Door", "3 Door", "4 Door"]}
                  />
                </div>
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.cylinder}
                    onChange={allProps.setCylinder}
                    options={[
                      "Any Cylinder",
                      "2 Cylinder",
                      "3 Cylinder",
                      "4 Cylinder",
                    ]}
                  />
                </div>
              </div>
              <div className="form-group">
                <div className="group-select">
                  <DropdownSelect
                    selectedValue={allProps.color}
                    onChange={allProps.setColor}
                    options={["Any Color", "Black", "White", "Blue", "Red"]}
                  />
                </div>
              </div>
              <div className="form-group wg-box3">
                <div className="widget widget-price">
                  <div className="caption flex-two">
                    <div>
                      <span className="fw-6">
                        Year: {allProps.year[0]} - {allProps.year[1]}
                      </span>
                    </div>
                  </div>
                  <Pricing
                    MIN={2015}
                    MAX={2025}
                    priceRange={allProps.year}
                    setPriceRange={allProps.setYear}
                  />
                </div>
                {/* /.widget_price */}
              </div>
              <div className="form-group wg-box3">
                <div className="widget widget-price">
                  <div className="caption flex-two">
                    <div>
                      <span className="fw-6">
                        KM: {allProps.km[0]} km - {allProps.km[1]} km
                      </span>
                    </div>
                  </div>
                  <Pricing
                    MIN={45000}
                    MAX={115000}
                    priceRange={allProps.km}
                    setPriceRange={allProps.setKM}
                  />
                </div>
                {/* /.widget_price */}
              </div>

              <div className="features-wrap">
                <h4>Featured</h4>
                <div className="form-group">
                  <div className="tf-amenities bg-white">
                    {featureOptions.map((feature, index) => (
                      <label className="flex-three" key={index}>
                        <input
                          readOnly
                          checked={allProps.features.includes(feature)}
                          type="checkbox"
                          onClick={() => allProps.setFeatures(feature)}
                        />
                        <span className="btn-checkbox" />
                        <span className="text-color-2 font-2">{feature}</span>
                      </label>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
