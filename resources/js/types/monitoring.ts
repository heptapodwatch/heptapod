export type SiteState = Heptapod.Enums.SiteStatus;
export type CheckKey = Heptapod.Contracts.CheckKey;
export type CheckDto = Heptapod.Data.Ui.CheckStripData;
export type UptimeBucketDto = Heptapod.Data.Ui.UptimeBucketData;
export type ResponsePointDto = Heptapod.Data.Ui.ResponsePointData;
export type SiteDto = Heptapod.Data.Ui.SiteRowData;
export type EventDto = Heptapod.Data.Ui.EventData;

export type FieldValue =
    | null
    | string
    | number
    | boolean
    | string[]
    | Record<string, string>[]
    | Record<string, string | number | boolean | null>;
type FieldBase = {
    name: string;
    label: string;
    help?: string;
    required?: boolean;
    defaultValue?: number;
    disabled?: boolean;
    secretValues?: boolean;
};
export type FieldSchema = FieldBase &
    (
        | {
              type: 'text' | 'secret' | 'cron' | 'toggle' | 'string-list';
          }
        | {
              type: 'object-list';
              properties: {
                  name: string;
                  type: 'text' | 'secret' | 'select';
                  options: { value: string; label: string }[];
              }[];
          }
        | {
              type: 'number' | 'duration';
              unit: string;
              min?: number;
              max?: number;
          }
        | {
              type: 'select' | 'multi-select';
              optionsSource?: 'fixed' | 'data';
              options: { value: string; label: string }[];
          }
    );
