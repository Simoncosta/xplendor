import BackgroundParallax from "@/components/animation/BackgroundParallax";

export default function ParallaxDivider() {
  return (
    <div className="mxd-section padding-pre-title">
      <div className="mxd-container">
        <div className="mxd-divider">
          <BackgroundParallax
            scale={1.5}
            className="mxd-divider__image divider-image-3 parallax-img"
          />
        </div>
      </div>
    </div>
  );
}
