import BackgroundParallax from "@/components/animation/BackgroundParallax";

export default function Devider() {
  return (
    <div className="mxd-section padding-hero-01 padding-pre-manifest mobile-point-subtitle">
      <div className="mxd-container">
        <div className="mxd-divider">
          <BackgroundParallax
            scale={1.5}
            className="mxd-divider__image divider-image-1 parallax-img"
          >
            {/* <Image class="parallax-img"  alt=""    src="/img/illustrations/1920x1080_divider-01.webp" width="1920" height="1080" /> */}
          </BackgroundParallax>
        </div>
      </div>
    </div>
  );
}
