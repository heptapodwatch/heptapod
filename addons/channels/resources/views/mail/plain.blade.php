{{-- The Plain design: the same block model as skeleton.blade.php, as simple left-aligned text without images, cards or colours. --}}
<!doctype html><html lang="{{ $mail->locale }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>{{ $mail->subject }}</title></head>
<body style="margin:0;padding:0;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $mail->preheader }}</div>
<div style="max-width:600px;padding:16px;text-align:left;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:22px;">
@if ($mail->client !== null && $mail->site !== null)
<p style="margin:0 0 12px;">{{ $mail->client }} · {{ $mail->site }}</p>
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
<p style="margin:24px 0 0;font-size:13px;line-height:19px;color:#6b7580;">{{ $mail->footerWhy }}@if ($mail->routingUrl !== null) <a href="{{ $mail->routingUrl }}" style="color:#6b7580;">{{ $mail->routingLabel }}</a>@endif</p>
</div>
</body></html>
