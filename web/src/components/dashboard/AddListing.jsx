import React, { useState } from "react";
import DropdownSelect from "../common/DropDownSelect";
import ContactMap from "./Map";

export default function AddListing() {
  const [isDragging, setIsDragging] = useState(false); // Track drag state
  const [images, setImages] = useState([
    "/assets/images/dashboard/thumb-media1.jpg",
    "/assets/images/dashboard/thumb-media2.jpg",
    "/assets/images/dashboard/thumb-media3.jpg",
    "/assets/images/dashboard/thumb-media4.jpg",
    "/assets/images/dashboard/thumb-media5.jpg",
  ]);

  const handleImageChange = (e, index) => {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        const newImages = [...images];
        newImages[index] = reader.result;
        setImages(newImages);
      };
      reader.readAsDataURL(file);
    }
  };
  const handleDelete = (index) => {
    const newImages = images.filter((_, imgIndex) => imgIndex !== index);
    setImages(newImages);
  };
  const [images2, setImages2] = useState([
    "/assets/images/dashboard/thumb-media1.jpg",
    "/assets/images/dashboard/thumb-media2.jpg",
  ]);

  const handleImageChange2 = (e, index) => {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        const newImages = [...images2];
        newImages[index] = reader.result;
        setImages2(newImages);
      };
      reader.readAsDataURL(file);
    }
  };
  const handleDelete2 = (index) => {
    const newImages = images2.filter((_, imgIndex) => imgIndex !== index);
    setImages2(newImages);
  };

  const handleDrop = (e) => {
    e.preventDefault();
    const files = Array.from(e.dataTransfer.files).slice(0, 10 - images.length);
    files.forEach((file, index) => {
      const reader = new FileReader();
      reader.onloadend = () => {
        setImages((prevImages) => {
          const newImages = [...prevImages];
          newImages[prevImages.length + index] = reader.result;
          return newImages;
        });
      };
      reader.readAsDataURL(file);
    });
  };

  const handleDragOver = (e) => {
    e.preventDefault();
    setIsDragging(true); // Add border on drag
  };

  const handleDragLeave = () => {
    setIsDragging(false); // Remove border when dragging ends
  };
  return (
    <div className="container">
      <div className="row">
        <div className="col-md-12">
          <div className="content-area">
            <main id="main" className="main-content">
              <div className="tfcl-dashboard">
                <h1 className="admin-title mb-3">Add listing</h1>
                <div className="tfcl-add-listing upload-photo">
                  <h3>Upload Photo</h3>
                  <div
                    className="upload-media"
                    style={
                      isDragging
                        ? { borderStyle: "solid", backgroundColor: "#f2f3f4" }
                        : {}
                    }
                    onDrop={handleDrop}
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                  >
                    <div className="inner">
                      <a href="#" className="relative">
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          width={18}
                          height={14}
                          viewBox="0 0 18 14"
                          fill="none"
                        >
                          <path
                            d="M0.875 10.125L5.17417 5.82583C5.34828 5.65172 5.55498 5.51361 5.78246 5.41938C6.00995 5.32515 6.25377 5.27665 6.5 5.27665C6.74623 5.27665 6.99005 5.32515 7.21754 5.41938C7.44502 5.51361 7.65172 5.65172 7.82583 5.82583L12.125 10.125M10.875 8.875L12.0492 7.70083C12.2233 7.52672 12.43 7.38861 12.6575 7.29438C12.885 7.20015 13.1288 7.15165 13.375 7.15165C13.6212 7.15165 13.865 7.20015 14.0925 7.29438C14.32 7.38861 14.5267 7.52672 14.7008 7.70083L17.125 10.125M2.125 13.25H15.875C16.2065 13.25 16.5245 13.1183 16.7589 12.8839C16.9933 12.6495 17.125 12.3315 17.125 12V2C17.125 1.66848 16.9933 1.35054 16.7589 1.11612C16.5245 0.881696 16.2065 0.75 15.875 0.75H2.125C1.79348 0.75 1.47554 0.881696 1.24112 1.11612C1.0067 1.35054 0.875 1.66848 0.875 2V12C0.875 12.3315 1.0067 12.6495 1.24112 12.8839C1.47554 13.1183 1.79348 13.25 2.125 13.25ZM10.875 3.875H10.8817V3.88167H10.875V3.875ZM11.1875 3.875C11.1875 3.95788 11.1546 4.03737 11.096 4.09597C11.0374 4.15458 10.9579 4.1875 10.875 4.1875C10.7921 4.1875 10.7126 4.15458 10.654 4.09597C10.5954 4.03737 10.5625 3.95788 10.5625 3.875C10.5625 3.79212 10.5954 3.71263 10.654 3.65403C10.7126 3.59542 10.7921 3.5625 10.875 3.5625C10.9579 3.5625 11.0374 3.59542 11.096 3.65403C11.1546 3.71263 11.1875 3.79212 11.1875 3.875Z"
                            stroke="white"
                            strokeWidth="1.5"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                          />
                        </svg>
                        Select photos
                        <input
                          type="file"
                          className="ip-file"
                          accept="image/*"
                          onChange={(e) => handleImageChange(e, images.length)}
                        />
                      </a>
                      <div className="desc">
                        or drag photos here <br />
                        <span>(Up to 10 photos)</span>
                      </div>
                    </div>
                  </div>
                  <div className="thumbnail-media">
                    {images.map((imgSrc, index) => (
                      <div key={index} className="item">
                        <img alt="img" src={imgSrc} width={615} height={405} />

                        <a onClick={() => handleDelete(index)}>
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width={16}
                            height={16}
                            viewBox="0 0 16 16"
                            fill="none"
                          >
                            <path
                              d="M9.82667 6.00035L9.596 12.0003M6.404 12.0003L6.17333 6.00035M12.8187 3.86035C13.0467 3.89501 13.2733 3.93168 13.5 3.97101M12.8187 3.86035L12.1067 13.1157C12.0776 13.4925 11.9074 13.8445 11.63 14.1012C11.3527 14.3579 10.9886 14.5005 10.6107 14.5003H5.38933C5.0114 14.5005 4.64735 14.3579 4.36999 14.1012C4.09262 13.8445 3.92239 13.4925 3.89333 13.1157L3.18133 3.86035M12.8187 3.86035C12.0492 3.74403 11.2758 3.65574 10.5 3.59568M3.18133 3.86035C2.95333 3.89435 2.72667 3.93101 2.5 3.97035M3.18133 3.86035C3.95076 3.74403 4.72416 3.65575 5.5 3.59568M10.5 3.59568V2.98501C10.5 2.19835 9.89333 1.54235 9.10667 1.51768C8.36908 1.49411 7.63092 1.49411 6.89333 1.51768C6.10667 1.54235 5.5 2.19901 5.5 2.98501V3.59568M10.5 3.59568C8.83581 3.46707 7.16419 3.46707 5.5 3.59568"
                              stroke="white"
                              strokeWidth="1.5"
                              strokeLinecap="round"
                              strokeLinejoin="round"
                            />
                          </svg>
                        </a>
                      </div>
                    ))}
                  </div>
                </div>
                <div className="tfcl-add-listing car-details">
                  <h3>Car details</h3>
                  <div className="form-group">
                    <label htmlFor="listing_title">Listing Title *</label>
                    <input
                      type="text"
                      className="form-control"
                      name="listing_title"
                      placeholder="Enter title"
                      defaultValue=""
                    />
                  </div>
                  <div className="form-group-4">
                    <div className="form-group">
                      <label htmlFor="listing_title">Model *</label>

                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Type *</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Years *</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Years"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Condition *</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Condition"
                        defaultValue=""
                      />
                    </div>
                  </div>
                  <div className="form-group-4">
                    <div className="form-group">
                      <label htmlFor="listing_title">Stock Number</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Enter number"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">VIN Number</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Enter VIN"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Mileage</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Enter mileage"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Transmission</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                  </div>
                  <div className="form-group-4">
                    <div className="form-group">
                      <label htmlFor="listing_title">Driver Type</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Engine Size</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Enter engine"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Cylinders</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Fuel Type</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                  </div>
                  <div className="form-group-4">
                    <div className="form-group">
                      <label htmlFor="listing_title">Doors</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Color</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Enter color"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Seats</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">City MPG</label>
                      <DropdownSelect
                        addtionalParentClass="form-control"
                        defaultOption={"Select"}
                        options={["Sample Data 1", "Sample Data 2"]}
                      />
                    </div>
                  </div>
                  <div className="form-group mb-0">
                    <label htmlFor="listing_title">Description</label>
                    <textarea
                      name=""
                      id=""
                      placeholder="Your description"
                      defaultValue={""}
                    />
                  </div>
                </div>
                <div className="tfcl-add-listing car-features">
                  <h3>Safety features</h3>
                  <div className="group-features-5">
                    <div className="group-features">
                      <h6>Request Price Label</h6>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>A/C: Front</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Backup Camera</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Cruise Control</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Navigation</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Power Locks</label>
                      </div>
                    </div>
                    <div className="group-features">
                      <h6>Entertainment</h6>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Audio system</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Touchscreen display</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>GPS navigation</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Phone connectivity</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>In-car Wi-Fi</label>
                      </div>
                    </div>
                    <div className="group-features">
                      <h6>Safety</h6>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Anti-lock brake system (ABS):</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Electronic stability control (ESC)</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Brake assist (BA)</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Airbags</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Blind spot monitoring system (BSM)</label>
                      </div>
                    </div>
                    <div className="group-features">
                      <h6>Interior</h6>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Premium leather seats</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Wood trim</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Mini bar</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Rear seat ventilation system</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Large infotainment screen</label>
                      </div>
                    </div>
                    <div className="group-features">
                      <h6>Exterior</h6>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Chrome-plated grill</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Smart headlight cluster</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Premium wheels</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>Body character lines</label>
                      </div>
                      <div className="parent-item group-checkbox">
                        <input className="form-check-input" type="checkbox" />
                        <label>High-quality paint</label>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="tfcl-add-listing car-price">
                  <h3>Car price</h3>
                  <div className="form-group mb-0">
                    <label htmlFor="listing_title">Full price</label>
                    <input
                      type="text"
                      className="form-control"
                      name="listing_title"
                      placeholder="Your price"
                      defaultValue=""
                    />
                  </div>
                </div>
                <div className="tfcl-add-listing car-location">
                  <h3>Location</h3>
                  <div className="form-group-2">
                    <div className="form-group">
                      <label htmlFor="listing_title">Full Address</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        defaultValue="2464 Royal Ln. Mesa, New Jersey 45463"
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Map location</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        defaultValue="2464 Royal Ln. Mesa, New Jersey 45463"
                      />
                    </div>
                  </div>
                  <div
                    id="map-single"
                    className="map-single"
                    data-map-zoom={16}
                    data-map-scroll="true"
                  >
                    <ContactMap />
                  </div>
                </div>
                <div className="tfcl-add-listing car-video">
                  <h3>Video</h3>
                  <p>
                    Listing with video gets 6 times higher exposure to buyers.
                    Put your video link here!
                  </p>
                  <div className="form-group mb-0">
                    <label htmlFor="listing_title">Video URL</label>
                    <input
                      type="text"
                      className="form-control"
                      name="listing_title"
                      placeholder="Your URL"
                      defaultValue=""
                    />
                  </div>
                </div>
                <div className="tfcl-add-listing car-attr">
                  <h3>Attachments (PDF)</h3>
                  <ul className="list-attrach">
                    {images2.map((imgSrc, index) => (
                      <li className="item" key={index}>
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          width={60}
                          height={60}
                          viewBox="0 0 60 60"
                          fill="none"
                        >
                          <path
                            d="M53.625 4.40731V55.5926C53.625 56.2947 53.3461 56.9679 52.8497 57.4644C52.3533 57.9608 51.68 58.2397 50.9779 58.2397H9.02206C8.32002 58.2397 7.64673 57.9608 7.15031 57.4644C6.65389 56.9679 6.375 56.2947 6.375 55.5926V12.3838H14.3603C15.0623 12.3838 15.7356 12.1049 16.232 11.6085C16.7285 11.1121 17.0074 10.4388 17.0074 9.73672V1.76025H50.9779C51.68 1.76025 52.3533 2.03914 52.8497 2.53556C53.3461 3.03198 53.625 3.70527 53.625 4.40731Z"
                            fill="#E9EDF4"
                          />
                          <path
                            d="M6.375 12.3838H14.3603C15.0623 12.3838 15.7356 12.1049 16.232 11.6085C16.7285 11.1121 17.0074 10.4388 17.0074 9.73672V1.76025L6.375 12.3838Z"
                            fill="#D2DBEA"
                          />
                          <path
                            d="M56.4706 34.5776V46.86C56.4706 47.562 56.1917 48.2353 55.6953 48.7317C55.1989 49.2281 54.5256 49.507 53.8235 49.507H6.17648C5.47443 49.507 4.80114 49.2281 4.30472 48.7317C3.8083 48.2353 3.52942 47.562 3.52942 46.86V34.5776C3.52942 33.8756 3.8083 33.2023 4.30472 32.7058C4.80114 32.2094 5.47443 31.9305 6.17648 31.9305H53.8235C54.5256 31.9305 55.1989 32.2094 55.6953 32.7058C56.1917 33.2023 56.4706 33.8756 56.4706 34.5776Z"
                            fill="#FF7101"
                          />
                          <path
                            d="M46.3526 19.8503C46.3526 20.4838 45.8479 20.9885 45.2144 20.9885H14.7856C14.4837 20.9885 14.1942 20.8686 13.9807 20.6551C13.7673 20.4417 13.6473 20.1521 13.6473 19.8503C13.6473 19.5484 13.7673 19.2589 13.9807 19.0454C14.1942 18.832 14.4837 18.712 14.7856 18.712H45.2144C45.8479 18.712 46.3526 19.2167 46.3526 19.8503ZM46.3526 25.6826C46.3526 26.3162 45.8479 26.8209 45.2144 26.8209H14.7856C14.4837 26.8209 14.1942 26.7009 13.9807 26.4875C13.7673 26.274 13.6473 25.9845 13.6473 25.6826C13.6473 25.3807 13.7673 25.0912 13.9807 24.8778C14.1942 24.6643 14.4837 24.5444 14.7856 24.5444H45.2144C45.8479 24.5444 46.3526 25.05 46.3526 25.6826Z"
                            fill="#D2DBEA"
                          />
                          <path
                            d="M18.7748 44.9179C18.7223 44.8663 18.6809 44.8044 18.6533 44.7361C18.6257 44.6678 18.6123 44.5945 18.6142 44.5209V36.9335C18.6142 36.7773 18.6671 36.6423 18.7748 36.5312C18.8254 36.4769 18.887 36.4338 18.9554 36.405C19.0238 36.3761 19.0976 36.3619 19.1718 36.3635H21.998C22.7833 36.3635 23.4239 36.4959 23.9189 36.7606C24.4157 37.0253 24.7704 37.3641 24.9865 37.777C25.2001 38.19 25.3086 38.6365 25.3086 39.1156C25.3086 39.5947 25.2001 40.042 24.9857 40.455C24.7704 40.8679 24.4157 41.2068 23.9189 41.4715C23.4239 41.7362 22.7824 41.8685 21.998 41.8685H19.7418V44.5209C19.7433 44.5951 19.7291 44.6688 19.7002 44.7372C19.6714 44.8056 19.6284 44.8672 19.5742 44.9179C19.463 45.0247 19.328 45.0785 19.1709 45.0785C19.0139 45.0785 18.8815 45.0256 18.7748 44.9179ZM21.8868 40.8018C22.7295 40.8018 23.3224 40.6403 23.6648 40.3182C24.008 39.9962 24.1792 39.5947 24.1792 39.1156C24.1792 38.6365 24.008 38.235 23.6648 37.9129C23.3224 37.5909 22.7295 37.4294 21.8868 37.4294H19.7418V40.8018H21.8868Z"
                            fill="white"
                          />
                          <path
                            d="M19.1709 45.2999C19.0685 45.3011 18.9669 45.2816 18.8721 45.2426C18.7774 45.2037 18.6915 45.146 18.6195 45.0732C18.5461 45.0014 18.4883 44.9153 18.4494 44.8203C18.4106 44.7253 18.3916 44.6234 18.3936 44.5208V36.9335C18.3936 36.7182 18.4686 36.5311 18.6168 36.3776C18.688 36.3024 18.774 36.2427 18.8693 36.2023C18.9647 36.1619 19.0674 36.1417 19.1709 36.1429H21.998C22.8159 36.1429 23.4971 36.2849 24.023 36.5655C24.5559 36.8496 24.945 37.2229 25.1815 37.6746C25.4109 38.1185 25.5292 38.6029 25.5292 39.1155C25.5292 39.6282 25.4118 40.1126 25.1815 40.5564C24.9459 41.0082 24.5559 41.3814 24.023 41.6664C23.498 41.9461 22.8168 42.0882 21.998 42.0882H19.9624V44.5208C19.9636 44.6246 19.9434 44.7276 19.903 44.8232C19.8627 44.9189 19.803 45.0052 19.7277 45.0767C19.6542 45.1485 19.5673 45.2051 19.4719 45.2433C19.3765 45.2814 19.2745 45.3004 19.1718 45.299L19.1709 45.2999ZM19.1709 36.584C19.1267 36.5826 19.0826 36.5908 19.0418 36.608C19.001 36.6252 18.9643 36.6511 18.9345 36.6838C18.9018 36.7164 18.8761 36.7554 18.8589 36.7984C18.8418 36.8413 18.8336 36.8873 18.8348 36.9335V44.5208C18.8348 44.6196 18.8648 44.6964 18.9309 44.7608C19.0633 44.8932 19.2812 44.894 19.4224 44.7582C19.455 44.7279 19.4807 44.6909 19.4977 44.6498C19.5148 44.6087 19.5228 44.5644 19.5212 44.5199V41.647H21.998C22.7436 41.647 23.355 41.5226 23.8156 41.2764C24.2692 41.0346 24.5965 40.724 24.7906 40.3526C24.9874 39.9732 25.088 39.5567 25.088 39.1155C25.088 38.6743 24.9874 38.2588 24.7906 37.8785C24.5965 37.5079 24.2683 37.1964 23.8156 36.9546C23.3542 36.7085 22.7427 36.584 21.998 36.584H19.1709ZM21.8868 41.0223H19.5212V37.2088H21.8859C22.7948 37.2088 23.4274 37.3861 23.8156 37.7523C24.2039 38.1167 24.3998 38.5755 24.3998 39.1155C24.3998 39.6555 24.2039 40.1143 23.8156 40.4788C23.4274 40.8449 22.7956 41.0223 21.8868 41.0223ZM19.9624 40.5811H21.8859C22.6668 40.5811 23.2148 40.4382 23.5139 40.1576C23.8139 39.8752 23.9586 39.5355 23.9586 39.1155C23.9586 38.6955 23.813 38.3549 23.5139 38.0743C23.2148 37.792 22.6668 37.6508 21.8868 37.6508H19.9624V40.5811ZM27.1059 44.8552C27.0533 44.8036 27.0117 44.7418 26.984 44.6735C26.9562 44.6052 26.9427 44.5319 26.9445 44.4582V36.9335C26.9445 36.7773 26.9974 36.6423 27.1059 36.5311C27.1565 36.4769 27.2179 36.434 27.2862 36.4051C27.3544 36.3762 27.428 36.362 27.5021 36.3635H29.9939C30.9353 36.3635 31.7356 36.5726 32.393 36.9899C33.0266 37.382 33.5394 37.9417 33.8745 38.6073C34.2045 39.269 34.3695 39.9626 34.3695 40.6896C34.3695 41.4167 34.2045 42.1111 33.8745 42.7729C33.5392 43.4385 33.0261 43.9983 32.3921 44.3902C31.7356 44.8085 30.9362 45.0167 29.9939 45.0167H27.503C27.4293 45.0184 27.356 45.005 27.2877 44.9772C27.2194 44.9494 27.1575 44.9079 27.1059 44.8552ZM29.858 43.9632C30.5348 43.9632 31.1295 43.8246 31.6421 43.5476C32.1408 43.2846 32.5538 42.8844 32.8324 42.3943C33.113 41.9029 33.2542 41.3355 33.2542 40.6905C33.2542 40.0455 33.113 39.4773 32.8324 38.9858C32.5535 38.4959 32.1406 38.0958 31.6421 37.8326C31.1303 37.5555 30.5348 37.4179 29.858 37.4179H28.0721V43.9632H29.858Z"
                            fill="white"
                          />
                          <path
                            d="M29.9939 45.2373H27.503C27.4005 45.2387 27.2987 45.2194 27.2038 45.1806C27.1089 45.1418 27.0228 45.0842 26.9506 45.0114C26.8773 44.9396 26.8195 44.8536 26.7806 44.7586C26.7418 44.6636 26.7228 44.5617 26.7248 44.4591V36.9335C26.7248 36.7182 26.7998 36.5311 26.948 36.3776C27.0193 36.3023 27.1054 36.2425 27.2009 36.2021C27.2964 36.1617 27.3993 36.1416 27.503 36.1429H29.9948C30.975 36.1429 31.8212 36.3652 32.5121 36.8038C33.1789 37.2178 33.7187 37.8076 34.0721 38.5085C34.4129 39.1852 34.5904 39.9324 34.5904 40.6901C34.5904 41.4478 34.4129 42.195 34.0721 42.8717C33.7187 43.5726 33.1789 44.1624 32.5121 44.5764C31.8212 45.0149 30.9742 45.2373 29.9939 45.2373ZM27.5021 36.5841C27.4577 36.5825 27.4135 36.5906 27.3725 36.6078C27.3315 36.6251 27.2947 36.651 27.2648 36.6838C27.2321 36.7164 27.2064 36.7554 27.1892 36.7984C27.1721 36.8413 27.1639 36.8873 27.165 36.9335V44.4591C27.165 44.557 27.195 44.6338 27.2621 44.6991C27.2932 44.7309 27.3306 44.7568 27.3719 44.7734C27.4133 44.79 27.4576 44.7977 27.5021 44.7961H29.9939C30.8895 44.7961 31.6562 44.5967 32.2748 44.2049C32.874 43.8333 33.3591 43.3036 33.6768 42.6741C33.9874 42.0588 34.1491 41.3793 34.1491 40.6901C34.1491 40.0009 33.9874 39.3214 33.6768 38.7061C33.359 38.0769 32.8738 37.5476 32.2748 37.1761C31.6562 36.7835 30.8895 36.5849 29.9939 36.5849H27.503L27.5021 36.5841ZM29.858 44.1838H27.8515V37.1964H29.858C30.5674 37.1964 31.2036 37.3455 31.7471 37.6376C32.2942 37.9341 32.7239 38.3505 33.0239 38.8755C33.3239 39.4005 33.4748 40.0111 33.4748 40.6905C33.4748 41.3699 33.3239 41.9805 33.0239 42.5038C32.7244 43.0292 32.2816 43.4586 31.7471 43.7417C31.2045 44.0346 30.5683 44.1838 29.858 44.1838ZM28.2927 43.7426H29.858C30.4942 43.7426 31.0598 43.6111 31.5371 43.3544C32.01 43.0985 32.3815 42.7385 32.6409 42.2849C32.9012 41.8288 33.0336 41.2923 33.0336 40.6905C33.0336 40.0888 32.9012 39.5523 32.6409 39.0952C32.3816 38.642 31.9992 38.2715 31.538 38.0267C31.0589 37.7691 30.4942 37.6385 29.858 37.6385H28.2927V43.7426ZM36.4156 44.9179C36.3631 44.8662 36.3218 44.8043 36.2941 44.736C36.2665 44.6677 36.2532 44.5945 36.255 44.5208V36.9335C36.255 36.7773 36.308 36.6423 36.4156 36.5311C36.4663 36.4768 36.5278 36.4338 36.5963 36.4049C36.6647 36.376 36.7384 36.3619 36.8127 36.3635H41.6348C41.783 36.3635 41.9092 36.4155 42.0124 36.5188C42.1156 36.622 42.1677 36.7482 42.1677 36.8964C42.1677 37.0446 42.1156 37.1699 42.0124 37.2688C41.9092 37.3676 41.783 37.417 41.6348 37.417H37.3827V40.1567H41.2006C41.3506 40.1567 41.4759 40.2096 41.5792 40.312C41.6824 40.4152 41.7345 40.5414 41.7345 40.6905C41.7345 40.8388 41.6824 40.9632 41.5792 41.062C41.4759 41.1608 41.3497 41.2102 41.2006 41.2102H37.3827V44.5208C37.3842 44.5951 37.37 44.6688 37.3411 44.7372C37.3122 44.8056 37.2693 44.8672 37.215 44.9179C37.1039 45.0246 36.9689 45.0785 36.8127 45.0785C36.6565 45.0785 36.5233 45.0255 36.4156 44.9179Z"
                            fill="white"
                          />
                          <path
                            d="M36.8118 45.3C36.7094 45.3011 36.6077 45.2816 36.513 45.2427C36.4183 45.2037 36.3323 45.1461 36.2603 45.0732C36.187 45.0014 36.1291 44.9154 36.0903 44.8204C36.0515 44.7254 36.0324 44.6235 36.0344 44.5209V36.9335C36.0344 36.7182 36.1094 36.5311 36.2577 36.3776C36.329 36.3023 36.4151 36.2425 36.5106 36.2022C36.6061 36.1618 36.709 36.1416 36.8127 36.1429H41.6347C41.7339 36.1413 41.8323 36.1599 41.9241 36.1975C42.0159 36.2352 42.099 36.291 42.1686 36.3617C42.2391 36.4316 42.295 36.5149 42.3327 36.6068C42.3704 36.6986 42.3893 36.7971 42.3883 36.8964C42.388 36.9948 42.3683 37.092 42.3302 37.1827C42.2921 37.2733 42.2364 37.3555 42.1663 37.4244C42.0962 37.4934 42.0131 37.5477 41.9219 37.5843C41.8306 37.6209 41.733 37.639 41.6347 37.6376H37.6033V39.9361H41.2006C41.3 39.9346 41.3986 39.9532 41.4906 39.991C41.5825 40.0288 41.6658 40.0849 41.7353 40.1559C41.8063 40.2254 41.8624 40.3087 41.9002 40.4006C41.9379 40.4925 41.9566 40.5912 41.955 40.6906C41.9564 40.7897 41.9373 40.8881 41.8989 40.9795C41.8605 41.0709 41.8036 41.1533 41.7318 41.2217C41.6614 41.2899 41.5783 41.3435 41.4871 41.3794C41.3959 41.4152 41.2986 41.4327 41.2006 41.4309H37.6033V44.5209C37.6046 44.6246 37.5845 44.7275 37.5443 44.8232C37.5041 44.9188 37.4446 45.0051 37.3694 45.0767C37.2959 45.1487 37.2088 45.2053 37.1133 45.2435C37.0177 45.2816 36.9156 45.3005 36.8127 45.2991L36.8118 45.3ZM36.8118 36.5841C36.7675 36.5827 36.7235 36.5908 36.6826 36.6081C36.6418 36.6253 36.6052 36.6511 36.5753 36.6838C36.5426 36.7165 36.5169 36.7555 36.4998 36.7984C36.4826 36.8413 36.4744 36.8873 36.4756 36.9335V44.5209C36.4756 44.6197 36.5056 44.6964 36.5727 44.7609C36.705 44.8932 36.9221 44.8941 37.0633 44.7582C37.0958 44.7279 37.1215 44.6909 37.1386 44.6498C37.1556 44.6087 37.1636 44.5644 37.1621 44.52V40.9906H41.2006C41.2423 40.9921 41.2838 40.985 41.3227 40.9698C41.3615 40.9547 41.3969 40.9317 41.4265 40.9023C41.4554 40.8754 41.4782 40.8425 41.4933 40.806C41.5083 40.7694 41.5154 40.7301 41.5139 40.6906C41.5139 40.6006 41.4856 40.53 41.423 40.4682C41.3603 40.4064 41.2906 40.3773 41.2006 40.3773H37.1621V37.1964H41.6356C41.677 37.1979 41.7181 37.1909 41.7567 37.1758C41.7952 37.1608 41.8303 37.1381 41.8597 37.1091C41.8886 37.0821 41.9114 37.0493 41.9265 37.0127C41.9416 36.9762 41.9486 36.9368 41.9471 36.8973C41.9485 36.8558 41.9412 36.8145 41.9255 36.776C41.9099 36.7375 41.8862 36.7028 41.8562 36.6741C41.8277 36.6446 41.7933 36.6214 41.7554 36.606C41.7174 36.5905 41.6766 36.583 41.6356 36.5841H36.8118Z"
                            fill="white"
                          />
                        </svg>
                        <a onClick={() => handleDelete2(index)}>
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width={16}
                            height={16}
                            viewBox="0 0 16 16"
                            fill="none"
                          >
                            <path
                              d="M9.82667 6.00035L9.596 12.0003M6.404 12.0003L6.17333 6.00035M12.8187 3.86035C13.0467 3.89501 13.2733 3.93168 13.5 3.97101M12.8187 3.86035L12.1067 13.1157C12.0776 13.4925 11.9074 13.8445 11.63 14.1012C11.3527 14.3579 10.9886 14.5005 10.6107 14.5003H5.38933C5.0114 14.5005 4.64735 14.3579 4.36999 14.1012C4.09262 13.8445 3.92239 13.4925 3.89333 13.1157L3.18133 3.86035M12.8187 3.86035C12.0492 3.74403 11.2758 3.65574 10.5 3.59568M3.18133 3.86035C2.95333 3.89435 2.72667 3.93101 2.5 3.97035M3.18133 3.86035C3.95076 3.74403 4.72416 3.65575 5.5 3.59568M10.5 3.59568V2.98501C10.5 2.19835 9.89333 1.54235 9.10667 1.51768C8.36908 1.49411 7.63092 1.49411 6.89333 1.51768C6.10667 1.54235 5.5 2.19901 5.5 2.98501V3.59568M10.5 3.59568C8.83581 3.46707 7.16419 3.46707 5.5 3.59568"
                              stroke="white"
                              strokeWidth="1.5"
                              strokeLinecap="round"
                              strokeLinejoin="round"
                            />
                          </svg>
                        </a>
                      </li>
                    ))}
                    <li className="item upload">
                      <label className="inner" style={{ cursor: "pointer" }}>
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          width={30}
                          height={30}
                          viewBox="0 0 30 30"
                          fill="none"
                        >
                          <path
                            d="M15 20.625V12.1875M15 12.1875L18.75 15.9375M15 12.1875L11.25 15.9375M8.43751 24.375C7.0993 24.3765 5.80441 23.9008 4.78539 23.0334C3.76636 22.166 3.08995 20.9637 2.87765 19.6424C2.66534 18.3212 2.93104 16.9675 3.62704 15.8245C4.32303 14.6815 5.40371 13.8241 6.67501 13.4063C6.34839 11.7327 6.68596 9.99778 7.61624 8.5688C8.54653 7.13981 9.99647 6.12902 11.659 5.75046C13.3216 5.37191 15.0662 5.65531 16.5235 6.54067C17.9807 7.42602 19.0361 8.8438 19.4663 10.4938C20.1313 10.2775 20.8435 10.2515 21.5225 10.4186C22.2016 10.5858 22.8203 10.9395 23.3089 11.4398C23.7975 11.9401 24.1365 12.5671 24.2875 13.2499C24.4386 13.9326 24.3957 14.6441 24.1638 15.3038C25.1871 15.6947 26.0413 16.4314 26.5782 17.3862C27.1151 18.341 27.3009 19.4537 27.1033 20.5311C26.9057 21.6086 26.3372 22.5829 25.4963 23.285C24.6555 23.9871 23.5954 24.3727 22.5 24.375H8.43751Z"
                            stroke="#FF7101"
                            strokeWidth="1.5"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                          />
                        </svg>
                        <div className="upload">
                          Upload file
                          <input
                            type="file"
                            className="ip-file"
                            accept="application/pdf"
                            onChange={(e) =>
                              handleImageChange2(e, images2.length)
                            }
                          />
                        </div>
                      </label>
                    </li>
                  </ul>
                </div>
                <div className="group-button-submit">
                  <button className="pre-btn">List Now</button>
                  <button className="second-btn">Save &amp; Preview</button>
                </div>
              </div>
            </main>
          </div>
        </div>
      </div>
    </div>
  );
}
