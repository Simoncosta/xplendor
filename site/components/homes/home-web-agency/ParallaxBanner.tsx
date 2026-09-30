import BackgroundParallax from "@/components/animation/BackgroundParallax";

export default function ParallaxBanner() {
  return (
    <div className="mxd-section padding-pre-manifest">
      <div className="mxd-container">
        <div className="mxd-divider">
          <BackgroundParallax
            scale={1.5}
            className="mxd-divider__image divider-image-10 parallax-img"
          />
        </div>
      </div>
    </div>
  );
}
