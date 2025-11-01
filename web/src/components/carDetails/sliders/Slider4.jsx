import PhotoSwipeLightbox from "photoswipe/lightbox";
import React, { useEffect, useRef, useState } from "react";

const imageList = [
  {
    id: 1,
    imgSrc: "/assets/images/section/listing-detai-1.jpg",
    alt: "image",
    videoLabel: "Video",
    allImageLabel: "All image",
  },
  {
    id: 2,
    imgSrc: "/assets/images/section/listing-detai-2.jpg",
    alt: "image",
    videoLabel: "Video",
    allImageLabel: "All image",
  },
  {
    id: 3,
    imgSrc: "/assets/images/section/listing-detai-3.jpg",
    alt: "image",
    videoLabel: "Video",
    allImageLabel: "All image",
  },
  {
    id: 4,
    imgSrc: "/assets/images/section/listing-detai-1.jpg",
    alt: "image",
    videoLabel: "Video",
    allImageLabel: "All image",
  },
  {
    id: 5,
    imgSrc: "/assets/images/section/listing-detai-2.jpg",
    alt: "image",
    videoLabel: "Video",
    allImageLabel: "All image",
  },
];
export default function Slider4() {
  const [activeItem, setActiveItem] = useState(1);

  // Function to handle click and set the active item
  const handleClick = (id) => {
    setActiveItem(id);
  };
  const lightboxRef = useRef(null);

  useEffect(() => {
    // Initialize PhotoSwipeLightbox
    const lightbox = new PhotoSwipeLightbox({
      gallery: "#my-gallery",
      children: ".image",
      pswpModule: () => import("photoswipe"),
    });

    lightbox.init();

    // Store the lightbox instance in the ref for later use
    lightboxRef.current = lightbox;

    // Cleanup: destroy the lightbox when the component unmounts
    return () => {
      lightbox.destroy();
    };
  }, []);

  const openLightbox = (index) => {
    if (lightboxRef.current) {
      lightboxRef.current.loadAndOpen(index);
    }
  };
  return (
    <div className="tfcl-listing-info style-grid" id="my-gallery">
      {imageList.map((item, i) => (
        <div
          className={`image-list-details tfcl-light-gallery item-${item.id} ${
            activeItem === item.id ? "active" : ""
          }`}
          key={item.id}
          onClick={() => handleClick(item.id)} // Add click handler
        >
          <a
            className="image"
            data-pswp-width="940"
            data-pswp-height="825"
            data-fancybox="gallery"
          >
            <img
              className="lazyload"
              data-src={item.imgSrc}
              alt={item.alt}
              src={item.imgSrc}
              width={940}
              height={825}
            />
          </a>
          <div className="specs-features-wrap flex-three flex-wrap">
            <a className="specs-features">
              <div className="icon">
                {" "}
                <svg
                  width={18}
                  height={14}
                  viewBox="0 0 18 14"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M12.125 5.75L16.0583 1.81667C16.1457 1.72937 16.2571 1.66993 16.3782 1.64586C16.4994 1.62179 16.625 1.63417 16.7391 1.68143C16.8532 1.72869 16.9508 1.80871 17.0195 1.91139C17.0882 2.01407 17.1249 2.1348 17.125 2.25833V11.7417C17.1249 11.8652 17.0882 11.9859 17.0195 12.0886C16.9508 12.1913 16.8532 12.2713 16.7391 12.3186C16.625 12.3658 16.4994 12.3782 16.3782 12.3541C16.2571 12.3301 16.1457 12.2706 16.0583 12.1833L12.125 8.25M2.75 12.625H10.25C10.7473 12.625 11.2242 12.4275 11.5758 12.0758C11.9275 11.7242 12.125 11.2473 12.125 10.75V3.25C12.125 2.75272 11.9275 2.27581 11.5758 1.92417C11.2242 1.57254 10.7473 1.375 10.25 1.375H2.75C2.25272 1.375 1.77581 1.57254 1.42417 1.92417C1.07254 2.27581 0.875 2.75272 0.875 3.25V10.75C0.875 11.2473 1.07254 11.7242 1.42417 12.0758C1.77581 12.4275 2.25272 12.625 2.75 12.625Z"
                    stroke="CurrentColor"
                    strokeWidth="1.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </div>
              <span className="fw-5 font text-color-2 lh-16">
                {item.videoLabel}
              </span>
            </a>
            <a
              className="specs-features"
              onClick={() => openLightbox(i)}
              data-fancybox="gallery"
            >
              <div className="icon">
                <svg
                  width={18}
                  height={14}
                  viewBox="0 0 18 14"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M0.875 10.125L5.17417 5.82583C5.34828 5.65172 5.55498 5.51361 5.78246 5.41938C6.00995 5.32515 6.25377 5.27665 6.5 5.27665C6.74623 5.27665 6.99005 5.32515 7.21754 5.41938C7.44502 5.51361 7.65172 5.65172 7.82583 5.82583L12.125 10.125M10.875 8.875L12.0492 7.70083C12.2233 7.52672 12.43 7.38861 12.6575 7.29438C12.885 7.20015 13.1288 7.15165 13.375 7.15165C13.6212 7.15165 13.865 7.20015 14.0925 7.29438C14.32 7.38861 14.5267 7.52672 14.7008 7.70083L17.125 10.125M2.125 13.25H15.875C16.2065 13.25 16.5245 13.1183 16.7589 12.8839C16.9933 12.6495 17.125 12.3315 17.125 12V2C17.125 1.66848 16.9933 1.35054 16.7589 1.11612C16.5245 0.881696 16.2065 0.75 15.875 0.75H2.125C1.79348 0.75 1.47554 0.881696 1.24112 1.11612C1.0067 1.35054 0.875 1.66848 0.875 2V12C0.875 12.3315 1.0067 12.6495 1.24112 12.8839C1.47554 13.1183 1.79348 13.25 2.125 13.25ZM10.875 3.875H10.8817V3.88167H10.875V3.875ZM11.1875 3.875C11.1875 3.95788 11.1546 4.03737 11.096 4.09597C11.0374 4.15458 10.9579 4.1875 10.875 4.1875C10.7921 4.1875 10.7126 4.15458 10.654 4.09597C10.5954 4.03737 10.5625 3.95788 10.5625 3.875C10.5625 3.79212 10.5954 3.71263 10.654 3.65403C10.7126 3.59542 10.7921 3.5625 10.875 3.5625C10.9579 3.5625 11.0374 3.59542 11.096 3.65403C11.1546 3.71263 11.1875 3.79212 11.1875 3.875Z"
                    stroke="CurrentColor"
                    strokeWidth="1.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </div>
              <span className="fw-5 font text-color-2 lh-16">
                {item.allImageLabel}
              </span>
            </a>
          </div>
        </div>
      ))}
    </div>
  );
}
