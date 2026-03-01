import React, { useState } from 'react';

const FullScreenDropdown: React.FC = () => {
    const [isFullScreenMode, setIsFullScreenMode] = useState<boolean>(true);

    const toggleFullscreen = () => {
        let document : any = window.document;
        document.body.classList.add("fullscreen-enable");

        if (
            !document.fullscreenElement &&
            !document.mozFullScreenElement &&
            !document.webkitFullscreenElement
        ) {
            setIsFullScreenMode(false);
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen();
            } else if (document.documentElement.mozRequestFullScreen) {
                document.documentElement.mozRequestFullScreen();
            } else if (document.documentElement.webkitRequestFullscreen) {
                document.documentElement.webkitRequestFullscreen();
            }
        } else {
            setIsFullScreenMode(true);
            if (document.cancelFullScreen) {
                document.cancelFullScreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.webkitCancelFullScreen) {
                document.webkitCancelFullScreen();
            }
        }

        const exitHandler = () => {
            if (
                !document.webkitIsFullScreen &&
                !document.mozFullScreen &&
                !document.msFullscreenElement
            )
                document.body.classList.remove("fullscreen-enable");
        };
        document.addEventListener("fullscreenchange", exitHandler);
        document.addEventListener("webkitfullscreenchange", exitHandler);
        document.addEventListener("mozfullscreenchange", exitHandler);
    };

    return (
        <>
            <div className="ms-1 header-item d-none d-sm-flex">
                <button
                    onClick={toggleFullscreen}
                    type="button"
                    className="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle"
                >
                    <i className={isFullScreenMode ? 'bx bx-fullscreen fs-22' : "bx bx-exit-fullscreen fs-22"}></i>
                </button>
            </div>
        </>
    );
};

export default FullScreenDropdown;
