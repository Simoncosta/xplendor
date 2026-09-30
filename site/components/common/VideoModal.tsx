// VideoModal.tsx

import ReactPlayer from "react-player";
import Modal from "react-modal";

interface Props {
  videoSrc: string;
  open: boolean;
  setOpen: React.Dispatch<React.SetStateAction<boolean>>;
}

export default function VideoModal({ videoSrc, open, setOpen }: Props) {
  return (
    <Modal
      isOpen={open}
      onRequestClose={() => setOpen(false)}
      className="videoModal__content"
      overlayClassName="videoModal__overlay"
      bodyOpenClassName="videoModal__bodyOpen" // <— body scroll lock
      ariaHideApp={false}
      shouldCloseOnEsc
      shouldCloseOnOverlayClick
    >
      <div className="videoModal__playerWrap">
        <ReactPlayer
          src={videoSrc} // <— use `url` not `src`
          playing={open}
          controls
          width="100%"
          height="100%"
          style={{ position: "absolute", inset: 0 }}
        />
      </div>
    </Modal>
  );
}
