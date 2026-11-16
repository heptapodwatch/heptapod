{{-- The Plain design: the same block model as skeleton.blade.php, as simple left-aligned text without images, cards or colours.
     It still sets its own text and background colours for both schemes, so no client default (or dark mode) leaves dark text on dark. --}}
<!doctype html><html lang="{{ $mail->locale }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>{{ $mail->subject }}</title>
<style>
body{margin:0;padding:0;background:#ffffff;color:#101418}a{color:#1f5fa8}.muted,.muted a{color:#5c6771}.rule{border-color:#c4cad0}
@media (prefers-color-scheme:dark){body{background:#13171b!important;color:#e6e9ec!important}a{color:#8fb4e0!important}.muted,.muted a{color:#a2abb4!important}.rule{border-color:#3a424b!important}}
[data-ogsc] body{background:#13171b!important;color:#e6e9ec!important}[data-ogsc] a{color:#8fb4e0!important}[data-ogsc] .muted,[data-ogsc] .muted a{color:#a2abb4!important}
</style></head>
<body style="margin:0;padding:0;background:#ffffff;color:#101418;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $mail->preheader }}</div>
<div style="max-width:600px;padding:16px;text-align:left;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:22px;">
@if ($mail->siteLine() !== null)
<p style="margin:0 0 12px;">{{ $mail->siteLine() }}</p>
@endif
<p style="margin:0 0 12px;"><strong>{{ $mail->headline }}</strong></p>
@if ($mail->lead !== null)
<p style="margin:0 0 12px;">{{ $mail->lead }}</p>
@endif
@if ($mail->chip !== null)
<p style="margin:0 0 12px;">{{ $mail->chip }} · {{ $mail->stateMeta }}</p>
@endif
@foreach ($mail->blocks as $block)
<div style="margin:0 0 12px;">@include('channels::mail.plain.'.$block->type())</div>
@endforeach
@if ($mail->primary !== null)
<p style="margin:16px 0 12px;"><a href="{{ $mail->primary['url'] }}">{{ $mail->primary['label'] }} →</a>@if ($mail->secondary !== null)<br><a href="{{ $mail->secondary['url'] }}">{{ $mail->secondary['label'] }}</a>@endif</p>
@endif
<p class="muted" style="margin:24px 0 0;font-size:13px;line-height:19px;color:#5c6771;">{{ $mail->footerWhy }}@if ($mail->routingUrl !== null) <a href="{{ $mail->routingUrl }}" style="color:#5c6771;">{{ $mail->routingLabel }}</a>@endif</p>
@if (($mailBranding['footer'] ?? null) !== null)
<p class="muted" style="margin:8px 0 0;font-size:13px;line-height:19px;color:#5c6771;">{{ $mailBranding['footer'] }}</p>
@endif
</div>
</body></html>
