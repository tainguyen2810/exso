import { createContext, useContext, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';

type Slots = {
    title: HTMLElement | null;
    actions: HTMLElement | null;
    setTitle: (element: HTMLElement | null) => void;
    setActions: (element: HTMLElement | null) => void;
};

const TopbarContext = createContext<Slots | null>(null);

/**
 * Pages put their title and main buttons in the topbar, like Sapo: the
 * topbar renders two empty slots and pages fill them through portals.
 */
export function TopbarProvider({ children }: { children: ReactNode }) {
    const [title, setTitle] = useState<HTMLElement | null>(null);
    const [actions, setActions] = useState<HTMLElement | null>(null);

    return (
        <TopbarContext.Provider
            value={{ title, actions, setTitle, setActions }}
        >
            {children}
        </TopbarContext.Provider>
    );
}

export function useTopbarSlots() {
    return useContext(TopbarContext);
}

/** Replaces the topbar title (by default the last breadcrumb). */
export function PageTitle({ children }: { children: ReactNode }) {
    const slots = useContext(TopbarContext);

    return slots?.title ? createPortal(children, slots.title) : null;
}

/** Buttons on the right of the topbar, e.g. Hủy / Lưu on form pages. */
export function TopbarActions({ children }: { children: ReactNode }) {
    const slots = useContext(TopbarContext);

    return slots?.actions ? createPortal(children, slots.actions) : null;
}
