import VideoParallax from "../animation/VideoParallax";

export default function ParallaxDivider() {
  return (
    <div className="mxd-section padding-grid-pre-mtext">
      <div className="mxd-container">
        <div className="mxd-divider">
          <div className="mxd-divider__video">
            <VideoParallax
              className="video parallax-video"
              src="/video/1920x1080_video-05.webm"
              poster="video/1920x1080_video-05.webp"
            />
          </div>
        </div>
      </div>
    </div>
  );
}
