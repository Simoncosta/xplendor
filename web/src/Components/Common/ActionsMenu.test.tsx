import { fireEvent, render, screen } from "@testing-library/react";
import { Modal, ModalBody } from "reactstrap";
import ActionsMenu, { MENU_Z_INDEX } from "./ActionsMenu";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });

describe("ActionsMenu", () => {
    it("dentro de um modal, o menu abre no body e por cima do modal", async () => {
        const onRemove = jest.fn();
        render(<Modal isOpen fade={false}><ModalBody><ActionsMenu label="Mais ações: imóvel" items={[{ label: "Retirar do site", danger: true, onClick: onRemove }]} /></ModalBody></Modal>);
        fireEvent.click(screen.getByLabelText("Mais ações: imóvel"));
        const item = await screen.findByText("Retirar do site");
        const menu = item.closest(".dropdown-menu") as HTMLElement;
        expect(menu).not.toBeNull();
        expect(menu.closest(".modal")).toBeNull(); // fora do modal (no body)
        expect(menu.style.zIndex).toBe(String(MENU_Z_INDEX));
        expect(MENU_Z_INDEX).toBeGreaterThan(1055);
        fireEvent.click(item);
        expect(onRemove).toHaveBeenCalled();
    });
});
