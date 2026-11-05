<?php

declare(strict_types=1);

namespace Heptapod\Addons\Lighthouse;

enum LighthouseFailureCode: string
{
    case AuditBudgetExceeded = 'audit_budget_exceeded';
    case PageHung = 'page_hung';
    case PageCrashed = 'page_crashed';
    case NoContent = 'no_content';
    case NotHtml = 'not_html';
    case PageLoadFailed = 'page_load_failed';
    case ReportUnscored = 'report_unscored';
    case ReportTooLarge = 'report_too_large';
    case AuditIncomplete = 'audit_incomplete';
}
