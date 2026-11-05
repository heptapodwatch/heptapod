import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { SheetForm } from '@/components/sheet-form';
import { Input } from '@/components/ui/input';
import { Sheet } from '@/components/ui/sheet';

vi.mock('react-i18next', () => {
    const t = (key: string) => key;
    return { useTranslation: () => ({ t }) };
});
afterEach(cleanup);

/** A sheet opened from state (as every record sheet is), with a hint before its first field. */
function Page() {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>
                Edit channel
            </button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetForm
                    title="Channel"
                    description="Name and address."
                    detail="Secrets are never shown."
                    footer={<button type="submit">Save</button>}
                >
                    <label htmlFor="channel-name">Name</label>
                    <Input id="channel-name" />
                </SheetForm>
            </Sheet>
        </>
    );
}

it('opens on the first form control, not a hint, and gives focus back to the opener on close', async () => {
    render(<Page />);
    const opener = screen.getByRole('button', { name: 'Edit channel' });
    opener.focus();
    fireEvent.click(opener);
    const name = await screen.findByLabelText('Name');
    expect(document.activeElement).toBe(name);

    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await vi.waitFor(() => expect(document.activeElement).toBe(opener));
});
