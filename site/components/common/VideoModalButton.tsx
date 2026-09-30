"use client";
import { useState } from "react";
import VideoModal from "./VideoModal";

interface VideoModalButtonProps {
  videoSrc: string;
  buttonClassName?: string;
  iconClassName?: string;
}

export default function VideoModalButton({
  videoSrc,
  buttonClassName = "btn btn-round btn-round-medium btn-accent slide-right anim-no-delay showreel-trigger",
  iconClassName = "ph-fill ph-play",
}: VideoModalButtonProps) {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <>
      <button onClick={() => setIsOpen(true)} className={buttonClassName}>
        <i className={iconClassName} />
      </button>
      <VideoModal videoSrc={videoSrc} open={isOpen} setOpen={setIsOpen} />
    </>
  );
}
