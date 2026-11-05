// Types used only by the design fixtures (Vitest and the /dev/components gallery).
export type ClientDto = Heptapod.Data.Ui.ClientData;
export type DesignIncidentDto = Heptapod.Data.Ui.IncidentData;
export type DesignPageProps = {
    state: 'loading' | 'empty' | 'populated' | 'error' | 'down';
};
