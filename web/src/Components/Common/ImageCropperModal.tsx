import { useEffect, useRef, useState } from "react";
import Cropper from "react-easy-crop";
import "react-easy-crop/react-easy-crop.css";
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";

export type CropArea = { x: number; y: number; width: number; height: number };
type CropPosition = { x: number; y: number };

interface ImageCropperModalProps {
    open: boolean;
    imageSource: File | string;
    onConfirm: (cropParams: CropArea) => void;
    onCancel: () => void;
}

export default function ImageCropperModal({ open, imageSource, onConfirm, onCancel }: ImageCropperModalProps) {
    const [imageUrl, setImageUrl] = useState<string>("");
    const [crop, setCrop] = useState<CropPosition>({ x: 0, y: 0 });
    const [zoom, setZoom] = useState<number>(1);
    const [rotation, setRotation] = useState<number>(0);
    const [croppedAreaPixels, setCroppedAreaPixels] = useState<CropArea | null>(null);
    const revokeUrlRef = useRef<string | null>(null);

    useEffect(() => {
        if (!open) return;

        setCrop({ x: 0, y: 0 });
        setZoom(1);
        setRotation(0);
        setCroppedAreaPixels(null);

        if (imageSource instanceof File) {
            const url = URL.createObjectURL(imageSource);
            revokeUrlRef.current = url;
            setImageUrl(url);
        } else {
            revokeUrlRef.current = null;
            setImageUrl(imageSource);
        }

        return () => {
            if (revokeUrlRef.current) {
                URL.revokeObjectURL(revokeUrlRef.current);
                revokeUrlRef.current = null;
            }
        };
    }, [open, imageSource]);

    const handleConfirm = () => {
        if (!croppedAreaPixels) return;
        onConfirm(croppedAreaPixels);
    };

    return (
        <Modal isOpen={open} toggle={onCancel} centered size="lg">
            <ModalHeader toggle={onCancel}>Cortar imagem</ModalHeader>
            <ModalBody>
                <div style={{ position: "relative", width: "100%", height: 400, background: "#1a1a2e" }}>
                    {imageUrl && (
                        <Cropper
                            image={imageUrl}
                            crop={crop}
                            zoom={zoom}
                            rotation={rotation}
                            aspect={16 / 9}
                            onCropChange={setCrop}
                            onZoomChange={setZoom}
                            onCropComplete={(_area, pixels) => setCroppedAreaPixels(pixels)}
                        />
                    )}
                </div>

                <div className="mt-3 d-flex align-items-center gap-3">
                    <div className="d-flex gap-2">
                        <Button
                            type="button"
                            color="light"
                            size="sm"
                            className="border"
                            onClick={() => setRotation((r) => r - 90)}
                            title="Rodar à esquerda"
                        >
                            <i className="ri-anticlockwise-line" />
                        </Button>
                        <Button
                            type="button"
                            color="light"
                            size="sm"
                            className="border"
                            onClick={() => setRotation((r) => r + 90)}
                            title="Rodar à direita"
                        >
                            <i className="ri-clockwise-line" />
                        </Button>
                    </div>

                    <div className="d-flex align-items-center gap-2 flex-grow-1">
                        <i className="ri-zoom-out-line text-muted" />
                        <input
                            type="range"
                            className="form-range flex-grow-1"
                            min={1}
                            max={3}
                            step={0.05}
                            value={zoom}
                            onChange={(e) => setZoom(Number(e.target.value))}
                            style={{ cursor: "pointer" }}
                            aria-label="Zoom"
                        />
                        <i className="ri-zoom-in-line text-muted" />
                    </div>
                </div>

                <p className="text-muted fs-12 mt-2 mb-0">
                    Arraste para reposicionar · Proporção 16:9 obrigatória
                </p>
            </ModalBody>
            <ModalFooter>
                <Button type="button" color="light" className="border" onClick={onCancel}>
                    Cancelar
                </Button>
                <Button type="button" color="primary" onClick={handleConfirm} disabled={!croppedAreaPixels}>
                    Confirmar corte
                </Button>
            </ModalFooter>
        </Modal>
    );
}
