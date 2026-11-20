export type ErrorApproach = 'a' | 'b' | 'c';
export type MockLocale = 'en' | 'de';

export type LocalizedText = {
    en: string;
    de: string;
};

export type ErrorFamilyId =
    | 'f1'
    | 'f2'
    | 'f3'
    | 'f4'
    | 'f5'
    | 'f6'
    | 'f7'
    | 'f8'
    | 'f9'
    | 'f10'
    | 'f11'
    | 'f12';

export type ErrorFamilySpec = {
    id: ErrorFamilyId;
    code: string;
    name: LocalizedText;
    statuses: string;
    statusSample: string;
    counts: { expected: number; unexpected: number; apiOnly: number };
    examples: string[];
    recommended: ErrorApproach;
    recommendedSplit: LocalizedText;
    unsafeInShell?: boolean;
    /** Copy shared across error pages (Approach A & B). */
    page: {
        title: LocalizedText;
        description: LocalizedText;
        recoveryLabel: LocalizedText;
        recoveryHref: string;
        secondaryLabel?: LocalizedText;
    };
    /** Copy for in-place handling (Approach C). */
    inPlace: {
        sceneTitle: LocalizedText;
        sceneSubtitle: LocalizedText;
        controlLabel?: LocalizedText;
        preventReason?: LocalizedText;
        toastTone: 'warning' | 'error' | 'info';
        toastMessage: LocalizedText;
        toastActionLabel?: LocalizedText;
        bannerTone: 'info' | 'warning' | 'danger';
        bannerTitle: LocalizedText;
        bannerDescription: LocalizedText;
        bannerActionLabel?: LocalizedText;
        fieldError?: LocalizedText;
        extraDetail?: LocalizedText;
    };
    verdicts: Record<ErrorApproach, LocalizedText>;
};

export const ERROR_FAMILIES: ErrorFamilySpec[] = [
    {
        id: 'f1',
        code: 'F1',
        name: {
            en: 'Rate limit & cooldown',
            de: 'Abkühlzeit & Ratenlimit',
        },
        statuses: '429',
        statusSample: '429',
        counts: { expected: 11, unexpected: 0, apiOnly: 3 },
        examples: [
            'RequestCheckRunController (30s fast / 300s heavy cooldown)',
            'RequestSiteChecks (Run all checks)',
            'POST /settings/backups (1 per 5 min)',
            'POST /notification-channels/{id}/test (6/min)',
            'POST /sites/probe · HealthSettingsProbe (6/min)',
        ],
        recommended: 'c',
        recommendedSplit: {
            en: 'Approach C (disable + live countdown where known; warning toast on race). Approach B only for guest GET /invitations throttle.',
            de: 'Ansatz C (Schaltfläche deaktivieren + Countdown, wo bekannt; Warn-Toast bei Überschneidung). Ansatz B nur bei Gäste-Aufruf von Einladungen.',
        },
        page: {
            title: {
                en: 'Too many requests',
                de: 'Zu viele Anfragen',
            },
            description: {
                en: 'This check ran recently and is in cooldown. You can run it again at 14:07 (in 4 min).',
                de: 'Diese Prüfung lief erst vor Kurzem und befindet sich in der Abkühlzeit. Sie können sie um 14:07 (in 4 Min.) erneut starten.',
            },
            recoveryLabel: {
                en: 'Back to check',
                de: 'Zurück zur Prüfung',
            },
            recoveryHref: '/sites',
            secondaryLabel: {
                en: 'Go to Sites',
                de: 'Websites öffnen',
            },
        },
        inPlace: {
            sceneTitle: {
                en: 'Sitemap · shop.example.com',
                de: 'Sitemap · shop.example.com',
            },
            sceneSubtitle: {
                en: 'Heavy check · 5-minute manual cooldown',
                de: 'Aufwendige Prüfung · 5 Minuten Abkühlzeit nach manuellem Start',
            },
            controlLabel: {
                en: 'Run check',
                de: 'Prüfung starten',
            },
            preventReason: {
                en: 'Can run again at 14:07 · in 4 min',
                de: 'Erneut ausführbar um 14:07 · in 4 Min.',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'Sitemap ran recently. You can run it again at 14:07 (in 4 min).',
                de: 'Die Sitemap-Prüfung lief vor Kurzem. Erneuter Start möglich um 14:07 (in 4 Min.).',
            },
            bannerTone: 'info',
            bannerTitle: {
                en: 'Run all checks: 4 queued · 2 skipped (in cooldown)',
                de: 'Alle Prüfungen starten: 4 eingereiht · 2 übersprungen (Abkühlzeit)',
            },
            bannerDescription: {
                en: 'Sitemap and Broken links ran recently and can run again at 14:07.',
                de: 'Sitemap und Defekte Links liefen vor Kurzem und können um 14:07 erneut starten.',
            },
        },
        verdicts: {
            a: {
                en: 'Too disruptive for a button click: replaces the check page just to tell you to wait 4 minutes.',
                de: 'Zu störend für einen Klick: ersetzt die Prüfungsseite, nur um auf 4 Minuten Wartezeit hinzuweisen.',
            },
            b: {
                en: 'Only needed for unauthenticated GET throttles (e.g. /invitations/{token}); wrong for signed-in actions.',
                de: 'Nur für unangemeldete GET-Limits (z. B. /invitations/{token}) sinnvoll; ungeeignet für Aktionen in der App.',
            },
            c: {
                en: 'Recommended: disable Run check with a live countdown when cooldown is known, and flash a warning toast on a race.',
                de: 'Empfohlen: Prüfung starten mit Live-Countdown deaktivieren und bei Überschneidung einen Warn-Toast anzeigen.',
            },
        },
    },
    {
        id: 'f2',
        code: 'F2',
        name: {
            en: 'Conflict & stale state',
            de: 'Konflikt & veralteter Zustand',
        },
        statuses: '409',
        statusSample: '409',
        counts: { expected: 8, unexpected: 0, apiOnly: 2 },
        examples: [
            'EndMaintenanceWindow · MaintenanceController update/destroy',
            'RequestBackup (backup already queued/running)',
            'UpdateCheckConfig (concurrent settings edit)',
            'StartOhDearImport · RetryOhDearImport',
        ],
        recommended: 'c',
        recommendedSplit: {
            en: 'Approach C (flash toast + back() refreshes the list/sheet; inline Alert inside open edit sheets).',
            de: 'Ansatz C (Flash-Toast + Zurück-Weiterleitung aktualisiert Liste/Sheet; Inline-Hinweis in geöffneten Formularen).',
        },
        page: {
            title: {
                en: 'This state has changed',
                de: 'Dieser Zustand hat sich geändert',
            },
            description: {
                en: 'This maintenance window has already ended. Open Maintenance to see the current schedule.',
                de: 'Dieses Wartungsfenster wurde bereits beendet. Öffnen Sie Wartung, um den aktuellen Stand zu sehen.',
            },
            recoveryLabel: {
                en: 'Open Maintenance',
                de: 'Wartung öffnen',
            },
            recoveryHref: '/maintenance',
        },
        inPlace: {
            sceneTitle: {
                en: 'Maintenance window · Deploy window',
                de: 'Wartungsfenster · Deployment-Fenster',
            },
            sceneSubtitle: {
                en: 'Clicked "End window" in a tab opened 20 minutes ago',
                de: 'Klick auf „Fenster beenden“ in einem vor 20 Minuten geöffneten Tab',
            },
            controlLabel: {
                en: 'Back up now',
                de: 'Sicherung starten',
            },
            preventReason: {
                en: 'A backup is already running. Wait until it finishes.',
                de: 'Eine Sicherung läuft bereits. Warten Sie, bis sie abgeschlossen ist.',
            },
            toastTone: 'info',
            toastMessage: {
                en: 'This maintenance window has already ended. The list has been refreshed.',
                de: 'Dieses Wartungsfenster ist bereits beendet. Die Liste wurde aktualisiert.',
            },
            bannerTone: 'warning',
            bannerTitle: {
                en: 'Check settings were changed elsewhere',
                de: 'Prüfungseinstellungen wurden anderweitig geändert',
            },
            bannerDescription: {
                en: 'Another teammate saved newer settings while this sheet was open. Reload the settings before saving again.',
                de: 'Während dieses Fenster geöffnet war, wurden neuere Einstellungen gespeichert. Laden Sie die Einstellungen vor dem Speichern neu.',
            },
            bannerActionLabel: {
                en: 'Reload settings',
                de: 'Einstellungen neu laden',
            },
        },
        verdicts: {
            a: {
                en: 'Unnecessary navigation away from Maintenance or Settings when refreshing the current view solves it immediately.',
                de: 'Unnötiger Seitenwechsel weg von Wartung oder Einstellungen, wenn das Aktualisieren der Ansicht das Problem sofort löst.',
            },
            b: {
                en: 'Breaks flow for signed-in edits; keep dedicated AuthLayout state only for AcceptInvitation 409.',
                de: 'Unterbricht den Arbeitsfluss bei angemeldeten Aktionen; nur bei Einladungen (409) bleibt die eigene Auth-Ansicht sinnvoll.',
            },
            c: {
                en: 'Recommended: redirect back with a flash notice (refreshing stale rows) or show an inline Alert inside open sheets.',
                de: 'Empfohlen: Zurück-Weiterleitung mit Flash-Hinweis (aktualisiert veraltete Zeilen) oder Inline-Alert im geöffneten Sheet.',
            },
        },
    },
    {
        id: 'f3',
        code: 'F3',
        name: {
            en: 'Too large / limit exceeded',
            de: 'Zu groß / Limit überschritten',
        },
        statuses: '413',
        statusSample: '413',
        counts: { expected: 2, unexpected: 0, apiOnly: 0 },
        examples: [
            'IncidentExportController (> 10,000 rows)',
            'PostTooLargeException (oversized avatar or mail logo upload)',
        ],
        recommended: 'c',
        recommendedSplit: {
            en: 'Approach C (disable Export with reason when total > 10,000; inline field error on uploads) + Approach A if someone opens /incidents/export directly.',
            de: 'Ansatz C (Export mit Hinweis deaktivieren ab 10.000 Einträgen; Feldhinweis bei Uploads) + Ansatz A bei direktem Aufruf von /incidents/export.',
        },
        page: {
            title: {
                en: 'Export too large',
                de: 'Export zu groß',
            },
            description: {
                en: '12,480 incidents match this filter, which exceeds the 10,000-row export limit. Narrow the date range or filters and export again.',
                de: '12.480 Störungen entsprechen diesem Filter und überschreiten das Limit von 10.000 Zeilen. Grenzen Sie Zeitraum oder Filter ein und starten Sie den Export erneut.',
            },
            recoveryLabel: {
                en: 'Back to Incidents',
                de: 'Zurück zu Störungen',
            },
            recoveryHref: '/incidents',
        },
        inPlace: {
            sceneTitle: {
                en: 'Incidents · CSV export',
                de: 'Störungen · CSV-Export',
            },
            sceneSubtitle: {
                en: '12,480 matching incidents (limit: 10,000 rows)',
                de: '12.480 passende Störungen (Limit: 10.000 Zeilen)',
            },
            controlLabel: {
                en: 'Export CSV',
                de: 'CSV exportieren',
            },
            preventReason: {
                en: '12,480 incidents match. Narrow the filters to 10,000 or fewer to export.',
                de: '12.480 Störungen gefunden. Grenzen Sie die Filter auf höchstens 10.000 ein, um zu exportieren.',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'Export exceeds 10,000 rows. Narrow the filters and try again.',
                de: 'Der Export überschreitet 10.000 Zeilen. Grenzen Sie die Filter ein und versuchen Sie es erneut.',
            },
            bannerTone: 'warning',
            bannerTitle: {
                en: 'File is too large to upload',
                de: 'Datei ist zu groß zum Hochladen',
            },
            bannerDescription: {
                en: 'Choose a PNG, JPEG, or WebP image of 2 MB or smaller and try again.',
                de: 'Wählen Sie ein PNG-, JPEG- oder WebP-Bild mit höchstens 2 MB und versuchen Sie es erneut.',
            },
            fieldError: {
                en: 'File exceeds 2 MB. Choose a smaller PNG, JPEG, or WebP image.',
                de: 'Die Datei überschreitet 2 MB. Wählen Sie ein kleineres PNG-, JPEG- oder WebP-Bild.',
            },
        },
        verdicts: {
            a: {
                en: 'Good fallback when a signed-in user opens a bookmarked /incidents/export URL directly.',
                de: 'Guter Rückfall, wenn jemand eine gespeicherte /incidents/export-URL direkt öffnet.',
            },
            b: {
                en: 'Fallback for reverse-proxy / non-Inertia 413 responses before Laravel boots.',
                de: 'Rückfall für Proxy-413-Antworten, bevor Laravel überhaupt greift.',
            },
            c: {
                en: 'Recommended: prevent before the click using incidents.total, plus inline upload errors and warning toast on race.',
                de: 'Empfohlen: Export schon vor dem Klick anhand der Trefferzahl erklären und bei Uploads inline hinweisen.',
            },
        },
    },
    {
        id: 'f4',
        code: 'F4',
        name: {
            en: 'Gone / expired link or snapshot',
            de: 'Abgelaufener Link oder Schnappschuss',
        },
        statuses: '410, stale 404',
        statusSample: '410',
        counts: { expected: 2, unexpected: 0, apiOnly: 1 },
        examples: [
            'InvitationController (410 expired 7-day invite link)',
            'FindingsExportController (pruned crawl/sitemap findings snapshot)',
        ],
        recommended: 'a',
        recommendedSplit: {
            en: 'Approach A for signed-in expired findings CSV download; keep dedicated AuthLayout page (similar to B) for guest expired invitations.',
            de: 'Ansatz A für abgelaufene Befund-Exporte im angemeldeten Bereich; eigene Auth-Seite (wie B) für abgelaufene Gäste-Einladungen.',
        },
        page: {
            title: {
                en: 'Findings snapshot expired',
                de: 'Befund-Schnappschuss abgelaufen',
            },
            description: {
                en: 'This findings snapshot was removed by retention before the download started. Open the check to view or run a fresh snapshot.',
                de: 'Dieser Befund-Schnappschuss wurde vor Beginn des Downloads gemäß Aufbewahrungsfrist entfernt. Öffnen Sie die Prüfung für aktuelle Ergebnisse.',
            },
            recoveryLabel: {
                en: 'Back to check',
                de: 'Zurück zur Prüfung',
            },
            recoveryHref: '/sites',
            secondaryLabel: {
                en: 'Go to Sites',
                de: 'Websites öffnen',
            },
        },
        inPlace: {
            sceneTitle: {
                en: 'Broken links · Download CSV',
                de: 'Defekte Links · CSV herunterladen',
            },
            sceneSubtitle: {
                en: 'Tab stayed open past findings retention',
                de: 'Tab blieb über die Aufbewahrungsfrist hinaus geöffnet',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'This findings snapshot has expired. Reload the check for the latest results.',
                de: 'Dieser Befund-Schnappschuss ist abgelaufen. Laden Sie die Prüfung für aktuelle Ergebnisse neu.',
            },
            bannerTone: 'warning',
            bannerTitle: {
                en: 'Findings snapshot no longer available',
                de: 'Befund-Schnappschuss nicht mehr verfügbar',
            },
            bannerDescription: {
                en: 'Retention removed the snapshot this tab is showing. Reload the page or start a new check run.',
                de: 'Die Aufbewahrungsfrist hat den angezeigten Schnappschuss entfernt. Laden Sie die Seite neu oder starten Sie eine neue Prüfung.',
            },
            bannerActionLabel: {
                en: 'Reload check',
                de: 'Prüfung neu laden',
            },
        },
        verdicts: {
            a: {
                en: 'Recommended for signed-in direct downloads (findings.csv): keeps the sidebar and links straight back to the check.',
                de: 'Empfohlen für direkte Downloads (findings.csv): behält die Seitenleiste und führt direkt zurück zur Prüfung.',
            },
            b: {
                en: 'Right choice for guest expired links (invitations already use a dedicated AuthLayout view with HTTP 410).',
                de: 'Richtig für abgelaufene Gäste-Links (Einladungen nutzen bereits eine eigene Auth-Ansicht mit HTTP 410).',
            },
            c: {
                en: 'Only possible if CSV download is routed through a JS fetch instead of <a download>.',
                de: 'Nur möglich, wenn der CSV-Download über JS statt über einen direkten <a download>-Link erfolgt.',
            },
        },
    },
    {
        id: 'f5',
        code: 'F5',
        name: {
            en: 'Not allowed in this state',
            de: 'In diesem Zustand nicht zulässig',
        },
        statuses: '422 (domain), 428',
        statusSample: '422',
        counts: { expected: 3, unexpected: 2, apiOnly: 1 },
        examples: [
            'MemberAccessGuard (demoting/removing last active Owner)',
            'NotificationChannelsController (removing account email channel)',
            'SetUpdateChecksEnabled (disabled by server environment)',
        ],
        recommended: 'c',
        recommendedSplit: {
            en: 'Approach C (disable control + explain why before the click; warning toast / inline alert on race). Tampered 428/422 stays loud.',
            de: 'Ansatz C (Steuerelement vorab mit Begründung deaktivieren; Warn-Toast oder Inline-Alert bei Überschneidung). Manipulierte 428/422 bleiben laut.',
        },
        page: {
            title: {
                en: 'Action not allowed',
                de: 'Aktion nicht zulässig',
            },
            description: {
                en: 'At least one active account must keep the Owner role. Assign another Owner first.',
                de: 'Mindestens ein aktives Konto muss die Rolle Inhaber behalten. Weisen Sie zuerst eine weitere Person mit der Rolle Inhaber zu.',
            },
            recoveryLabel: {
                en: 'Back to Members',
                de: 'Zurück zu Teammitgliedern',
            },
            recoveryHref: '/settings/members',
        },
        inPlace: {
            sceneTitle: {
                en: 'Settings · Members · Alex Weber (Owner)',
                de: 'Einstellungen · Mitglieder · Alex Weber (Inhaber)',
            },
            sceneSubtitle: {
                en: 'Only active account with the Owner role',
                de: 'Einziges aktives Konto mit der Rolle Inhaber',
            },
            controlLabel: {
                en: 'Remove from team',
                de: 'Aus dem Team entfernen',
            },
            preventReason: {
                en: 'Assign another Owner before changing this role or removing this account.',
                de: 'Weisen Sie zuerst eine weitere Rolle Inhaber zu, bevor Sie diese Rolle ändern oder das Konto entfernen.',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'At least one active Owner is required. Assign another Owner first.',
                de: 'Mindestens ein aktives Konto mit der Rolle Inhaber ist erforderlich. Weisen Sie zuerst eine weitere Rolle Inhaber zu.',
            },
            bannerTone: 'info',
            bannerTitle: {
                en: 'Managed by server configuration',
                de: 'Durch Serverkonfiguration vorgegeben',
            },
            bannerDescription: {
                en: 'Update checks are disabled in this server’s environment and cannot be turned on from the web interface.',
                de: 'Update-Prüfungen sind in der Serverumgebung deaktiviert und können hier nicht eingeschaltet werden.',
            },
        },
        verdicts: {
            a: {
                en: 'Overkill: destroys the open Settings sheet just to explain a domain constraint.',
                de: 'Übertrieben: schließt die geöffnete Einstellungsseite nur für eine fachliche Regel.',
            },
            b: {
                en: 'Never right for signed-in settings constraints.',
                de: 'Für fachliche Regeln in den Einstellungen ungeeignet.',
            },
            c: {
                en: 'Recommended: explain the rule right on the disabled control, and show the exact server reason inline or as a toast on races.',
                de: 'Empfohlen: Regel direkt am deaktivierten Steuerelement erklären und bei Überschneidung exakt vom Server anzeigen.',
            },
        },
    },
    {
        id: 'f6',
        code: 'F6',
        name: {
            en: 'Permission / access denied',
            de: 'Keine Berechtigung',
        },
        statuses: '403',
        statusSample: '403',
        counts: { expected: 4, unexpected: 2, apiOnly: 2 },
        examples: [
            'Member opening /settings/instance, /settings/email, /sites/create',
            'Role downgraded mid-session before clicking an action',
            'Admin opening another Admin’s Oh Dear import run',
        ],
        recommended: 'a',
        recommendedSplit: {
            en: 'Approach A for signed-in GET navigation (keeps sidebar!); Approach C toast + refresh for stale-tab actions; Approach B for guests.',
            de: 'Ansatz A bei angemeldetem Seitenaufruf (Seitenleiste bleibt erhalten!); Ansatz C bei veralteten Tabs; Ansatz B für Gäste.',
        },
        page: {
            title: {
                en: 'Access denied',
                de: 'Zugriff verweigert',
            },
            description: {
                en: 'This page requires the Owner or Admin role. Ask a teammate with the Owner role if you need access.',
                de: 'Für diese Seite ist die Rolle Inhaber oder Admin erforderlich. Wenden Sie sich bei Bedarf an Personen mit der Rolle Inhaber.',
            },
            recoveryLabel: {
                en: 'Go to Sites',
                de: 'Websites öffnen',
            },
            recoveryHref: '/sites',
            secondaryLabel: {
                en: 'Open Profile',
                de: 'Profil öffnen',
            },
        },
        inPlace: {
            sceneTitle: {
                en: 'Site settings · Stale tab after role change',
                de: 'Website-Einstellungen · Veralteter Tab nach Rollenwechsel',
            },
            sceneSubtitle: {
                en: 'Role changed from Admin to Member while this page was open',
                de: 'Rolle wurde von Admin auf Mitglied geändert, während die Seite geöffnet war',
            },
            toastTone: 'error',
            toastMessage: {
                en: 'You no longer have permission to change this site. The page has been refreshed.',
                de: 'Sie haben keine Berechtigung mehr, diese Website zu ändern. Die Seite wurde aktualisiert.',
            },
            bannerTone: 'danger',
            bannerTitle: {
                en: 'Permission changed',
                de: 'Berechtigung geändert',
            },
            bannerDescription: {
                en: 'Your account no longer has permission for this action. Reload the page to see your current access.',
                de: 'Ihr Konto hat für diese Aktion keine Berechtigung mehr. Laden Sie die Seite neu, um Ihre aktuellen Rechte zu sehen.',
            },
            bannerActionLabel: {
                en: 'Go to Sites',
                de: 'Websites öffnen',
            },
        },
        verdicts: {
            a: {
                en: 'Recommended for direct/shared URLs: keeps the sidebar intact instead of kicking a signed-in teammate out of the app.',
                de: 'Empfohlen für direkte oder geteilte Links: behält die Seitenleiste bei, statt angemeldete Teammitglieder aus der App zu werfen.',
            },
            b: {
                en: 'Current behaviour for direct GETs (loses navigation shell); still needed for unauthenticated or broken-role requests.',
                de: 'Aktuelles Verhalten bei direktem Aufruf (verliert die Navigation); bleibt nötig für Gäste oder Konten ohne Rolle.',
            },
            c: {
                en: 'Recommended for stale-tab action clicks: flash error toast + refresh hides controls they can no longer use.',
                de: 'Empfohlen für Aktionen aus veralteten Tabs: Fehler-Toast + Aktualisierung blendet unzulässige Schaltflächen aus.',
            },
        },
    },
    {
        id: 'f7',
        code: 'F7',
        name: {
            en: 'Not found / deleted record',
            de: 'Nicht gefunden / gelöschter Eintrag',
        },
        statuses: '404',
        statusSample: '404',
        counts: { expected: 6, unexpected: 6, apiOnly: 2 },
        examples: [
            'Fallback route (mistyped or stale URL)',
            'Deleted site, incident, client, or maintenance window',
            'Teammate already removed in another tab (ChangeMemberAccess)',
            'Pruned activity/timeline group expansion',
        ],
        recommended: 'a',
        recommendedSplit: {
            en: 'Approach A for signed-in GET navigation; Approach C toast + refresh for stale-tab actions; Approach B for guests.',
            de: 'Ansatz A bei angemeldetem Seitenaufruf; Ansatz C (Toast + Aktualisierung) bei Aktionen auf gelöschten Einträgen; Ansatz B für Gäste.',
        },
        page: {
            title: {
                en: 'Page not found',
                de: 'Seite nicht gefunden',
            },
            description: {
                en: 'This record does not exist, was removed, or is outside your client access.',
                de: 'Dieser Eintrag existiert nicht, wurde entfernt oder liegt außerhalb Ihres Kundenzugriffs.',
            },
            recoveryLabel: {
                en: 'Go to Sites',
                de: 'Websites öffnen',
            },
            recoveryHref: '/sites',
            secondaryLabel: {
                en: 'Open Incidents',
                de: 'Störungen öffnen',
            },
        },
        inPlace: {
            sceneTitle: {
                en: 'Settings · Members · Stale row action',
                de: 'Einstellungen · Mitglieder · Aktion auf entfernter Zeile',
            },
            sceneSubtitle: {
                en: 'Another Owner already removed this teammate',
                de: 'Dieses Teammitglied wurde bereits anderweitig entfernt',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'This item is no longer available. The list has been refreshed.',
                de: 'Dieser Eintrag ist nicht mehr verfügbar. Die Liste wurde aktualisiert.',
            },
            bannerTone: 'info',
            bannerTitle: {
                en: 'This group was pruned',
                de: 'Diese Gruppe wurde bereinigt',
            },
            bannerDescription: {
                en: 'The collapsed activity entries are no longer retained. Refresh the feed to view current events.',
                de: 'Die zusammengefassten Aktivitätseinträge sind nicht mehr gespeichert. Aktualisieren Sie den Verlauf.',
            },
            bannerActionLabel: {
                en: 'Reload activity',
                de: 'Aktivität neu laden',
            },
        },
        verdicts: {
            a: {
                en: 'Recommended for signed-in navigation: user can immediately jump via the sidebar or command palette (⌘K).',
                de: 'Empfohlen bei angemeldeten Seitenaufrufen: direkter Wechsel über Seitenleiste oder Befehlspalette (⌘K) bleibt möglich.',
            },
            b: {
                en: 'Required for unauthenticated / guest 404s where no app shell exists.',
                de: 'Erforderlich für nicht angemeldete 404-Aufrufe ohne App-Rahmen.',
            },
            c: {
                en: 'Recommended when an in-place action hits a just-deleted record: toast + refresh removes the stale row.',
                de: 'Empfohlen, wenn eine Aktion einen gerade gelöschten Eintrag trifft: Toast + Aktualisierung entfernt die Zeile.',
            },
        },
    },
    {
        id: 'f8',
        code: 'F8',
        name: {
            en: 'Session expired & disabled account',
            de: 'Sitzung abgelaufen & Konto deaktiviert',
        },
        statuses: '419, 401',
        statusSample: '419',
        counts: { expected: 3, unexpected: 1, apiOnly: 2 },
        examples: [
            'CSRF TokenMismatchException (419) after idle tab',
            'Session expired on useHttpRequest JSON call (401)',
            'RejectDisabledAccount middleware (abort 401 — currently unbranded!)',
        ],
        recommended: 'c',
        recommendedSplit: {
            en: 'Approach C for Inertia 419 (back() refreshes CSRF + toast) and useHttpRequest 401 (toast + Sign in); Approach B extended to 401 for RejectDisabledAccount.',
            de: 'Ansatz C für Inertia-419 (CSRF automatisch erneuern + Toast) und JSON-401 (Toast + Anmelden); Ansatz B erweitert auf 401 für deaktivierte Konten.',
        },
        page: {
            title: {
                en: 'Session expired',
                de: 'Sitzung abgelaufen',
            },
            description: {
                en: 'Your session expired while this tab was idle. Sign in again or open a fresh page before retrying.',
                de: 'Ihre Sitzung ist abgelaufen, während dieser Tab inaktiv war. Melden Sie sich erneut an oder öffnen Sie eine neue Seite.',
            },
            recoveryLabel: {
                en: 'Sign in',
                de: 'Anmelden',
            },
            recoveryHref: '/login',
        },
        inPlace: {
            sceneTitle: {
                en: 'Idle tab · Save settings or sheet submit',
                de: 'Inaktiver Tab · Einstellungen oder Formular speichern',
            },
            sceneSubtitle: {
                en: 'CSRF token expired (419) or session ended (401)',
                de: 'CSRF-Token abgelaufen (419) oder Sitzung beendet (401)',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'Your session was refreshed. Please submit that again.',
                de: 'Ihre Sitzung wurde aktualisiert. Bitte senden Sie die Eingabe erneut ab.',
            },
            toastActionLabel: {
                en: 'Sign in',
                de: 'Anmelden',
            },
            bannerTone: 'warning',
            bannerTitle: {
                en: 'Account disabled (HTTP 401)',
                de: 'Konto deaktiviert (HTTP 401)',
            },
            bannerDescription: {
                en: 'Your account was deactivated by an Owner and signed out. Standalone Blade 401 explains this cleanly instead of a raw 401 page.',
                de: 'Ihr Konto wurde durch die Rolle Inhaber deaktiviert und abgemeldet. Die eigenständige 401-Seite erklärt dies klar statt einer Roh-Fehlerseite.',
            },
            bannerActionLabel: {
                en: 'Sign in',
                de: 'Anmelden',
            },
        },
        verdicts: {
            a: {
                en: 'Not applicable when signed out or disabled (no authenticated shell).',
                de: 'Nicht anwendbar bei abgemeldetem oder deaktiviertem Konto (kein authentifizierter App-Rahmen).',
            },
            b: {
                en: 'Recommended for RejectDisabledAccount (401) and direct non-Inertia 419 POSTs: zero-session Blade page with Sign in.',
                de: 'Empfohlen für deaktivierte Konten (401) und direkte Nicht-Inertia-419-Anfragen: eigenständige Blade-Seite mit Anmelden.',
            },
            c: {
                en: 'Recommended for Inertia 419 (redirect back refreshes CSRF + warning toast) and useHttpRequest 401 (toast with Sign in).',
                de: 'Empfohlen für Inertia-419 (CSRF erneuern + Warn-Toast) und JSON-401 (Toast mit Anmelden-Link).',
            },
        },
    },
    {
        id: 'f9',
        code: 'F9',
        name: {
            en: 'Server fault / broken invariant',
            de: 'Serverfehler / unerwarteter Defekt',
        },
        statuses: '500',
        statusSample: '500',
        counts: { expected: 0, unexpected: 3, apiOnly: 0 },
        examples: [
            '~140 LogicException / RuntimeException invariant guards',
            'Database connection / query failure',
            'PlanOhDearImportController local fault (500)',
        ],
        recommended: 'b',
        unsafeInShell: true,
        recommendedSplit: {
            en: 'Approach B (zero-JS, zero-DB standalone Blade 500 + server log) for GET; Approach C error toast via router.on("httpException") for in-place mutations (no iframe modal!).',
            de: 'Ansatz B (JS- und DB-freie Blade-500-Seite + Server-Log) für Seitenaufrufe; Ansatz C Fehler-Toast via httpException für Aktionen (kein Iframe-Modal!).',
        },
        page: {
            title: {
                en: 'Something went wrong',
                de: 'Ein Fehler ist aufgetreten',
            },
            description: {
                en: 'Heptapod could not complete this request. The error has been logged — please try again later.',
                de: 'Heptapod konnte diese Anfrage nicht abschließen. Der Fehler wurde protokolliert — bitte versuchen Sie es später erneut.',
            },
            recoveryLabel: {
                en: 'Go to Sites',
                de: 'Websites öffnen',
            },
            recoveryHref: '/sites',
        },
        inPlace: {
            sceneTitle: {
                en: 'Inertia mutation hits HTTP 500',
                de: 'Inertia-Aktion trifft auf HTTP 500',
            },
            sceneSubtitle: {
                en: 'router.on("httpException") prevents Inertia’s raw iframe modal',
                de: 'router.on("httpException") verhindert das rohe Inertia-Iframe-Modal',
            },
            toastTone: 'error',
            toastMessage: {
                en: 'Something went wrong on the server. Your change was not saved — please try again later.',
                de: 'Auf dem Server ist ein Fehler aufgetreten. Ihre Änderung wurde nicht gespeichert — bitte versuchen Sie es später erneut.',
            },
            bannerTone: 'danger',
            bannerTitle: {
                en: 'Could not complete request',
                de: 'Anfrage konnte nicht abgeschlossen werden',
            },
            bannerDescription: {
                en: 'A server error stopped this operation. The details were written to the server log.',
                de: 'Ein Serverfehler hat diesen Vorgang gestoppt. Die Details wurden im Serverprotokoll festgehalten.',
            },
        },
        verdicts: {
            a: {
                en: 'Unsafe: rendering AppLayout shared props queries the database and can fail again during a DB outage.',
                de: 'Unsicher: Die geteilten AppLayout-Daten fragen die Datenbank ab und scheitern bei einem DB-Ausfall erneut.',
            },
            b: {
                en: 'Required for GET requests: zero-JS, zero-DB Blade page (respects app.debug in local dev) and always logs the fault.',
                de: 'Pflicht für Seitenaufrufe: JS- und DB-freie Blade-Seite (beachtet app.debug lokal) und protokolliert den Fehler immer.',
            },
            c: {
                en: 'Recommended companion for Inertia POST/PATCH/DELETE and useHttpRequest: show an error toast instead of Inertia’s iframe modal.',
                de: 'Empfohlene Ergänzung für Inertia-Aktionen und useHttpRequest: Fehler-Toast statt Inertia-Iframe-Modal.',
            },
        },
    },
    {
        id: 'f10',
        code: 'F10',
        name: {
            en: 'Maintenance / service unavailable',
            de: 'Wartung / Dienst nicht verfügbar',
        },
        statuses: '503, proxy 502/504',
        statusSample: '503',
        counts: { expected: 0, unexpected: 3, apiOnly: 0 },
        examples: [
            'php artisan down (ServiceUnavailableHttpException 503)',
            'Container restart / reverse-proxy 502 or 504',
            'Reverb broadcasting auth 503 (routes/channels.php)',
        ],
        recommended: 'b',
        unsafeInShell: true,
        recommendedSplit: {
            en: 'Approach B (zero-DB standalone Blade 503 with "Try again") for GET; Approach C toast via router.on("httpException") for in-place actions during brief restarts.',
            de: 'Ansatz B (DB-freie Blade-503-Seite mit „Erneut versuchen“) für Seitenaufrufe; Ansatz C Toast via httpException bei kurzen Neustarts.',
        },
        page: {
            title: {
                en: 'Temporarily unavailable',
                de: 'Vorübergehend nicht verfügbar',
            },
            description: {
                en: 'Heptapod is temporarily unavailable, likely for an update or restart. Wait a moment and try again.',
                de: 'Heptapod ist vorübergehend nicht verfügbar, vermutlich wegen eines Updates oder Neustarts. Warten Sie einen Moment und versuchen Sie es erneut.',
            },
            recoveryLabel: {
                en: 'Try again',
                de: 'Erneut versuchen',
            },
            recoveryHref: '/dev/components/errors',
        },
        inPlace: {
            sceneTitle: {
                en: 'Brief container restart (502 / 503 / 504)',
                de: 'Kurzer Container-Neustart (502 / 503 / 504)',
            },
            sceneSubtitle: {
                en: 'Click during a 5-second deploy restart',
                de: 'Klick während eines 5-sekündigen Deploy-Neustarts',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'Heptapod is temporarily unavailable. Wait a moment and try again.',
                de: 'Heptapod ist vorübergehend nicht verfügbar. Warten Sie einen Moment und versuchen Sie es erneut.',
            },
            bannerTone: 'warning',
            bannerTitle: {
                en: 'Heptapod is restarting',
                de: 'Heptapod startet neu',
            },
            bannerDescription: {
                en: 'Your last action could not be completed while the server was unavailable. Try again in a few seconds.',
                de: 'Ihre letzte Aktion konnte während der Nichtverfügbarkeit nicht ausgeführt werden. Versuchen Sie es in wenigen Sekunden erneut.',
            },
            bannerActionLabel: {
                en: 'Try again',
                de: 'Erneut versuchen',
            },
        },
        verdicts: {
            a: {
                en: 'Unsafe: the app is down or restarting, so an Inertia shell response cannot be rendered.',
                de: 'Unsicher: Die Anwendung ist offline oder startet neu — ein Inertia-App-Rahmen kann nicht gerendert werden.',
            },
            b: {
                en: 'Required for full page loads: zero-DB Blade 503 with a plain reload link ("Try again").',
                de: 'Pflicht für vollständige Seitenaufrufe: DB-freie Blade-503-Seite mit einfachem „Erneut versuchen“-Link.',
            },
            c: {
                en: 'Recommended for in-place Inertia clicks during proxy 502/503/504: suppress raw proxy HTML iframe and show a warning toast.',
                de: 'Empfohlen bei Proxy-502/503/504 während In-Page-Klicks: rohes Proxy-HTML abfangen und Warn-Toast zeigen.',
            },
        },
    },
    {
        id: 'f11',
        code: 'F11',
        name: {
            en: 'Network lost / offline & client',
            de: 'Verbindung unterbrochen & Client-Grenzen',
        },
        statuses: 'networkError, fetch, clipboard',
        statusSample: 'OFFLINE',
        counts: { expected: 11, unexpected: 0, apiOnly: 0 },
        examples: [
            'router.on("networkError") on POST/PATCH/DELETE',
            '16 useHttpRequest callers (sheets, settings, backups, tokens)',
            '8 raw fetch() callers (probe, search, messages, logos)',
            'Clipboard denied (import-ohdear-run & findings-export)',
        ],
        recommended: 'c',
        recommendedSplit: {
            en: 'Approach C exclusively: warning toast on offline mutations, inline retryable alert in panels/sheets, manual-copy fallback dialog for clipboard.',
            de: 'Ausschließlich Ansatz C: Warn-Toast bei Offline-Aktionen, Inline-Alert mit Wiederholen in Karten/Sheets, Kopier-Dialog als Rückfall.',
        },
        page: {
            title: {
                en: 'Connection lost',
                de: 'Verbindung unterbrochen',
            },
            description: {
                en: 'Your browser could not reach Heptapod. Check your internet connection and try again.',
                de: 'Ihr Browser konnte Heptapod nicht erreichen. Prüfen Sie Ihre Internetverbindung und versuchen Sie es erneut.',
            },
            recoveryLabel: {
                en: 'Try again',
                de: 'Erneut versuchen',
            },
            recoveryHref: '/dev/components/errors',
        },
        inPlace: {
            sceneTitle: {
                en: 'Offline click · Failed panel fetch · Clipboard blocked',
                de: 'Offline-Klick · Fehlgeschlagener Abruf · Zwischenablage blockiert',
            },
            sceneSubtitle: {
                en: 'Keeps unsaved drafts intact while the connection recovers',
                de: 'Behält ungespeicherte Entwürfe bei, bis die Verbindung wieder steht',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'Could not reach Heptapod. Check your connection and try again.',
                de: 'Heptapod konnte nicht erreicht werden. Prüfen Sie Ihre Verbindung und versuchen Sie es erneut.',
            },
            bannerTone: 'warning',
            bannerTitle: {
                en: 'Could not refresh system health',
                de: 'Systemzustand konnte nicht aktualisiert werden',
            },
            bannerDescription: {
                en: 'Showing the last loaded data from 14:02. Check your connection and try again.',
                de: 'Es werden die zuletzt geladenen Daten von 14:02 angezeigt. Prüfen Sie Ihre Verbindung und versuchen Sie es erneut.',
            },
            bannerActionLabel: {
                en: 'Try again',
                de: 'Erneut versuchen',
            },
            extraDetail: {
                en: 'Clipboard blocked: opens the shared read-only copy dialog with preselected text.',
                de: 'Zwischenablage blockiert: öffnet den gemeinsamen schreibgeschützten Kopier-Dialog mit vorselektiertem Text.',
            },
        },
        verdicts: {
            a: {
                en: 'Not possible offline (no server response to render a new Inertia route) and would wipe unsaved drafts.',
                de: 'Offline nicht möglich (keine Serverantwort für eine neue Route) und würde ungespeicherte Entwürfe löschen.',
            },
            b: {
                en: 'Not applicable: network drops happen in the browser before any Blade response arrives.',
                de: 'Nicht anwendbar: Verbindungsabbrüche passieren im Browser, bevor eine Blade-Antwort ankommt.',
            },
            c: {
                en: 'Recommended: global networkError toast for failed mutations, shared httpErrorMessage() for useHttpRequest, and useClipboard fallback dialog.',
                de: 'Empfohlen: globaler networkError-Toast bei fehlgeschlagenen Aktionen, gemeinsamer httpErrorMessage()-Helfer und Kopier-Rückfalldialog.',
            },
        },
    },
    {
        id: 'f12',
        code: 'F12',
        name: {
            en: 'Validation outside forms & action refusals',
            de: 'Validierung außerhalb von Formularen',
        },
        statuses: '422 (action)',
        statusSample: '422',
        counts: { expected: 2, unexpected: 4, apiOnly: 0 },
        examples: [
            'RequestCheckRun (disabled check, paused site, already busy)',
            'Incident episode Run check (currently swallows error silently!)',
            'RequestSiteIconRefresh (throttled → inline InputError)',
        ],
        recommended: 'c',
        recommendedSplit: {
            en: 'Approach C: disable the button with a reason line where state is known; show InputError or warning toast on race (fixes Incident page silent failure).',
            de: 'Ansatz C: Schaltfläche bei bekanntem Zustand mit Begründung deaktivieren; bei Überschneidung InputError oder Warn-Toast (behebt stilles Scheitern auf Störungsseiten).',
        },
        page: {
            title: {
                en: 'Check cannot run right now',
                de: 'Prüfung kann derzeit nicht gestartet werden',
            },
            description: {
                en: 'This site is paused or the check is already queued. Resume the site or wait for the current run to finish.',
                de: 'Diese Website ist pausiert oder die Prüfung ist bereits eingereiht. Setzen Sie die Website fort oder warten Sie auf den laufenden Durchlauf.',
            },
            recoveryLabel: {
                en: 'Back to incident',
                de: 'Zurück zur Störung',
            },
            recoveryHref: '/incidents',
        },
        inPlace: {
            sceneTitle: {
                en: 'Incident #42 · Uptime · shop.example.com',
                de: 'Störung #42 · Erreichbarkeit · shop.example.com',
            },
            sceneSubtitle: {
                en: 'Site was paused in another tab right before clicking "Run check"',
                de: 'Website wurde kurz vor dem Klick auf „Prüfung starten“ in einem anderen Tab pausiert',
            },
            controlLabel: {
                en: 'Run check',
                de: 'Prüfung starten',
            },
            preventReason: {
                en: 'This site is paused. Resume monitoring to run checks.',
                de: 'Diese Website ist pausiert. Setzen Sie die Überwachung fort, um Prüfungen zu starten.',
            },
            toastTone: 'warning',
            toastMessage: {
                en: 'This check cannot run right now because the site is paused.',
                de: 'Diese Prüfung kann derzeit nicht gestartet werden, da die Website pausiert ist.',
            },
            bannerTone: 'warning',
            bannerTitle: {
                en: 'Check could not be started',
                de: 'Prüfung konnte nicht gestartet werden',
            },
            bannerDescription: {
                en: 'This check is already queued or turned off. Refresh to see its latest state.',
                de: 'Diese Prüfung ist bereits eingereiht oder ausgeschaltet. Aktualisieren Sie die Ansicht für den aktuellen Stand.',
            },
        },
        verdicts: {
            a: {
                en: 'Overkill: navigating away from an open incident because of a button race hurts incident triage.',
                de: 'Übertrieben: Das Verlassen einer offenen Störung wegen eines Button-Konflikts stört die Analyse.',
            },
            b: {
                en: 'Never right for action refusals inside an active monitoring screen.',
                de: 'Für abgelehnte Aktionen innerhalb einer aktiven Überwachungsansicht ungeeignet.',
            },
            c: {
                en: 'Recommended: prevent with disabled button + reason line when known, and show a clear toast/inline error on both Check and Incident pages.',
                de: 'Empfohlen: vorab mit deaktivierter Schaltfläche + Begründung verhindern und auf Prüfungs- sowie Störungsseiten klar melden.',
            },
        },
    },
];

export const INVENTORY_TOTALS = ERROR_FAMILIES.reduce(
    (acc, family) => ({
        expected: acc.expected + family.counts.expected,
        unexpected: acc.unexpected + family.counts.unexpected,
        apiOnly: acc.apiOnly + family.counts.apiOnly,
        total:
            acc.total +
            family.counts.expected +
            family.counts.unexpected +
            family.counts.apiOnly,
    }),
    { expected: 0, unexpected: 0, apiOnly: 0, total: 0 },
);
