import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/segmented-control';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Range = Heptapod.Enums.ResponseTimeRange;
const labels: Record<Range, string> = {
    '24h': '24 hours',
    '7d': '7 days',
    '30d': '30 days',
    '90d': '90 days',
    '1y': '1 year',
    all: 'history.all',
};

/** Place in a card container (@container/card). Narrow cards get the same choices in a Select. */
export function ChartRangeControl({
    ranges,
    value,
    onValueChange,
}: {
    ranges: readonly Range[];
    value: Range;
    onValueChange: (value: string) => void;
}) {
    const { t } = useTranslation();
    const options = ranges.map((value) => ({ value, label: t(labels[value]) }));
    return (
        <>
            <div className="hidden @[640px]/card:block">
                <SegmentedControl
                    label={t('Time range')}
                    value={value}
                    onValueChange={onValueChange}
                    options={options}
                />
            </div>
            <Select value={value} onValueChange={onValueChange}>
                <SelectTrigger
                    className="w-32 @[640px]/card:hidden"
                    aria-label={t('Time range')}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map(({ value, label }) => (
                        <SelectItem key={value} value={value}>
                            {label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </>
    );
}
