import BackgroundParallax from "@/components/animation/BackgroundParallax";

export default function ParallaxDivider() {
  return (
    <div className="mxd-section padding-grid-pre-mtext">
      <div className="mxd-container">
        <div className="mxd-divider">
          <BackgroundParallax
            scale={1.5}
            className="mxd-divider__image divider-image-4 parallax-img"
          />
        </div>
      </div>
    </div>
  );
}
