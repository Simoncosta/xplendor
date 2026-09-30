"use client";
import AnimateRotation from "@/components/animation/AnimateRotation";
import VideoParallax from "@/components/animation/VideoParallax";
import VideoModal from "@/components/common/VideoModal";
import Image from "next/image";
import { useState } from "react";

export default function ParallaxDivider() {
  const [isOpen, setIsOpen] = useState(false);
  return (
    <>
      <div className="mxd-section padding-pre-title">
        <div className="mxd-container">
          <div className="mxd-divider">
            <div className="mxd-divider__video">
              <VideoParallax
                className="video parallax-video"
                src="/video/1920x1080_video-07.webm"
                poster="/video/1920x1080_video-07.webp"
              />
            </div>
            {/* <div class="mxd-divider__image divider-image-1 parallax-img"></div> */}
            <div className="mxd-divider__trigger">
              <a
                onClick={(e) => {
                  e.preventDefault();
                  setIsOpen(true);
                }}
                href="#"
                id="showreel-trigger"
                className="btn-rotating btn-rotating-blur-outline showreel-trigger"
              >
                {/* SVG rotating text */}
                <AnimateRotation
                  as="svg"
                  version="1.1"
                  id="scrollDown"
                  xmlns="http://www.w3.org/2000/svg"
                  xmlnsXlink="http://www.w3.org/1999/xlink"
                  x="0px"
                  y="0px"
                  viewBox="0 0 160 160"
                  enableBackground={"new 0 0 160 160"}
                  xmlSpace="preserve"
                  className="btn-rotating__text animate-rotation"
                  value={360}
                >
                  <defs>
                    <path
                      id="textPath"
                      d="M149.7,80c0,38.5-31.2,69.7-69.7,69.7S10.3,118.5,10.3,80S41.5,10.3,80,10.3S149.7,41.5,149.7,80z"
                    />
                  </defs>
                  <g>
                    <use xlinkHref="#textPath" fill="none" />
                    <text>
                      {/* button text here!!! */}
                      <textPath xlinkHref="#textPath">
                        Play showreel * Play showreel * Play showreel *
                      </textPath>
                    </text>
                  </g>
                </AnimateRotation>
                {/* image */}
                <Image
                  className="btn-rotating__image"
                  alt="Object"
                  src="/img/icons/300x300_obj-btn-02.webp"
                  width={300}
                  height={300}
                />
              </a>
            </div>
          </div>
        </div>
      </div>
      <VideoModal
        videoSrc="https://vimeo.com/65036292"
        open={isOpen}
        setOpen={setIsOpen}
      />
    </>
  );
}
