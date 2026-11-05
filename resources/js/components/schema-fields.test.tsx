import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import i18n from '@/lib/i18n';
import type { FieldSchema } from '@/types/monitoring';

afterEach(cleanup);
import { addSiteFieldViews, SchemaFields } from './schema-fields';
import { addSitePage } from '@/fixtures/design';

const timeout = addSitePage.checks.find((check) => check.key === 'uptime')!
    .config_schema.fields.timeout!;
const optionalObject = {
    ...timeout,
    type: 'object' as const,
    rules: ['sometimes'],
};

test('add-site settings omit optional objects but reject required objects they cannot edit', () => {
    const schema = {
        fields: { timeout, notification_settings: optionalObject },
    };
    expect(addSiteFieldViews(schema).map((field) => field.name)).toEqual([
        'timeout',
    ]);
    expect(() =>
        addSiteFieldViews({
            fields: {
                notification_settings: {
                    ...optionalObject,
                    rules: ['required'],
                },
            },
        }),
    ).toThrow('Unsupported configuration field type object');
});

test('schema forms expose small enums as radios, preserve larger selects and submit the selected values', async () => {
    await i18n.changeLanguage('en');
    const onChange = vi.fn();
    const schema: FieldSchema[] = [
        {
            name: 'device',
            label: 'Device',
            type: 'select',
            required: true,
            options: [
                { value: 'mobile', label: 'Mobile' },
                { value: 'desktop', label: 'Desktop' },
            ],
        },
        {
            name: 'client',
            label: 'Client',
            type: 'select',
            optionsSource: 'data',
            options: [
                { value: '1', label: 'First client' },
                { value: '2', label: 'Second client' },
            ],
        },
        {
            name: 'speed',
            label: 'Speed',
            type: 'select',
            options: ['default', 'fast', 'slow', 'slowest', 'fastest'].map(
                (value) => ({ value, label: value }),
            ),
        },
        {
            name: 'types',
            label: 'Types',
            type: 'multi-select',
            options: [
                { value: 'page', label: 'Page' },
                { value: 'image', label: 'Image' },
            ],
        },
        {
            name: 'conditions',
            label: 'Conditions',
            type: 'object-list',
            properties: [
                {
                    name: 'condition',
                    type: 'select',
                    options: [
                        { value: 'equals', label: 'Equals' },
                        { value: 'contains', label: 'Contains' },
                    ],
                },
            ],
        },
    ];
    render(
        <I18nextProvider i18n={i18n}>
            <SchemaFields
                schema={schema}
                values={{
                    device: 'mobile',
                    speed: 'default',
                    types: ['page'],
                    conditions: [{ condition: 'equals' }],
                }}
                onChange={onChange}
            />
        </I18nextProvider>,
    );
    expect(
        screen
            .getByRole('radiogroup', { name: 'Device' })
            .getAttribute('aria-required'),
    ).toBe('true');
    expect(screen.getByRole('combobox', { name: 'Speed' })).toBeTruthy();
    expect(screen.getByRole('combobox', { name: 'Client' })).toBeTruthy();
    fireEvent.click(screen.getByRole('radio', { name: 'Desktop' }));
    expect(onChange).toHaveBeenLastCalledWith('device', 'desktop');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Image' }));
    expect(onChange).toHaveBeenLastCalledWith('types', ['page', 'image']);
    fireEvent.click(screen.getByRole('radio', { name: 'Contains' }));
    expect(onChange).toHaveBeenLastCalledWith('conditions', [
        { condition: 'contains' },
    ]);
});

test.each(['desktop', ''])(
    'schema radio labels focus the checked or first radio without changing the value (%s)',
    async (selected) => {
        await i18n.changeLanguage('en');
        const onChange = vi.fn();
        render(
            <I18nextProvider i18n={i18n}>
                <SchemaFields
                    schema={[
                        {
                            name: 'device',
                            label: 'Device',
                            type: 'select',
                            options: [
                                { value: 'mobile', label: 'Mobile' },
                                { value: 'desktop', label: 'Desktop' },
                            ],
                        },
                        {
                            name: 'conditions',
                            label: 'Conditions',
                            type: 'object-list',
                            properties: [
                                {
                                    name: 'condition',
                                    type: 'select',
                                    options: [
                                        { value: 'equals', label: 'Equals' },
                                        {
                                            value: 'contains',
                                            label: 'Contains',
                                        },
                                    ],
                                },
                            ],
                        },
                    ]}
                    values={{
                        device: selected,
                        conditions: [{ condition: selected ? 'contains' : '' }],
                    }}
                    onChange={onChange}
                />
            </I18nextProvider>,
        );

        fireEvent.click(screen.getByText('Device', { selector: 'label' }));
        expect(document.activeElement).toBe(
            screen.getByRole('radio', {
                name: selected ? 'Desktop' : 'Mobile',
            }),
        );
        fireEvent.click(screen.getByText('Condition', { selector: 'label' }));
        expect(document.activeElement).toBe(
            screen.getByRole('radio', {
                name: selected ? 'Contains' : 'Equals',
            }),
        );
        expect(onChange).not.toHaveBeenCalled();
    },
);

test('a typed cron expression that does not parse is the field’s own linked error', async () => {
    await i18n.changeLanguage('en');
    render(
        <I18nextProvider i18n={i18n}>
            <SchemaFields
                schema={[
                    {
                        name: 'expression',
                        label: 'Cron expression',
                        type: 'cron',
                        required: true,
                    },
                ]}
                values={{ expression: 'not a cron' }}
                onChange={() => {}}
            />
        </I18nextProvider>,
    );
    const field = screen.getByLabelText(/Cron expression/);
    expect(field.getAttribute('aria-invalid')).toBe('true');
    const ids = field.getAttribute('aria-describedby')!.split(' ');
    expect(ids).toEqual([`${field.id}-help`, `${field.id}-error`]);
    expect(document.getElementById(`${field.id}-error`)?.textContent).toBe(
        'Enter a valid cron expression.',
    );
});
