@php
$light = ['bg'=>'#e8ebed','card'=>'#ffffff','line'=>'#d3d8dc','soft'=>'#e1e5e8','sunken'=>'#f5f6f7','fg'=>'#101418','fg2'=>'#3f4952','fg3'=>'#5c6771','down'=>'#b42f28','warn'=>'#94490a','ok'=>'#2c6a4e','info'=>'#3e6a96','error'=>'#5c6771'];
$dark = ['bg'=>'#0d1013','card'=>'#13171b','line'=>'#262c33','soft'=>'#1c2127','sunken'=>'#0f1215','fg'=>'#e6e9ec','fg2'=>'#a2abb4','fg3'=>'#848e99','down'=>'#e6574d','warn'=>'#dd8a43','ok'=>'#79ad95','info'=>'#7f9fc0','error'=>'#8b96a1'];
$tone = $mail->tone->color();
@endphp
@if ($plain ?? false)
@if ($mail->client !== null && $mail->site !== null)
{!! $mail->client !!} · {!! $mail->site !!}
@endif
{!! $mail->headline !!}
@if ($mail->lead !== null)
{!! $mail->lead !!}
@endif
@if ($mail->chip !== null)
{!! $mail->chip !!} · {!! $mail->stateMeta !!}
@endif

@foreach ($mail->blocks as $block)
@include('channels::mail.blocks.'.$block->type(), ['plain' => true])

@endforeach
@if ($mail->primary !== null)
{!! $mail->primary['label'] !!}: {!! $mail->primary['url'] !!}
@endif
@if ($mail->secondary !== null)
{!! $mail->secondary['label'] !!}: {!! $mail->secondary['url'] !!}
@endif

{!! $mail->footerWhy !!}
@if ($mail->routingUrl !== null)
{!! $mail->routingLabel !!}: {!! $mail->routingUrl !!}
@endif
{!! $mail->instance !!}
@else
<!doctype html><html lang="{{ $mail->locale }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>{{ $mail->subject }}</title>
<style>
body{margin:0;padding:0}a{color:inherit}
@media (max-width:480px){.px{padding-left:20px!important;padding-right:20px!important}.h1{font-size:19px!important;line-height:25px!important}}
@php
$darkRules = '.bg-bg{background:'.$dark['bg'].'!important}.bg-card{background:'.$dark['card'].'!important;border-color:'.$dark['line'].'!important}.bg-sunken{background:'.$dark['sunken'].'!important;border-color:'.$dark['soft'].'!important}.bd-soft{border-color:'.$dark['soft'].'!important}.btn-primary{background:'.$dark['fg'].'!important}.btn-primary a{color:'.$dark['bg'].'!important}';
foreach ($dark as $key => $color) {
    $darkRules .= '.c-'.$key.'{color:'.$color.'!important}.chip-'.$key.'{color:'.$color.'!important;border-color:'.$color.'!important}';
}
$darkRules .= '.bar-down{background:'.$dark['down'].'!important}';
if ($mail->avatarTile !== null) {
    $darkRules .= '.avatar-letter{background:'.$mail->avatarTile->background.'!important;color:'.$mail->avatarTile->foreground.'!important}';
}
@endphp
@media (prefers-color-scheme:dark){ {!! $darkRules !!} }
{!! preg_replace('/(^|})(\.[^{]+)/', '$1[data-ogsc] $2', $darkRules) !!}
</style></head>
<body class="bg-bg" style="margin:0;padding:0;background:#e8ebed;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $mail->preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-bg" style="background:#e8ebed;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;"><tr><td>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-card" style="background:#ffffff;border:1px solid #d3d8dc;border-radius:10px;">
@if (in_array($mail->tone, [\Heptapod\Contracts\Mail\MailTone::Down, \Heptapod\Contracts\Mail\MailTone::Failed], true))
<tr><td class="bar-down" style="height:3px;line-height:3px;font-size:0;background:#b42f28;border-radius:10px 10px 0 0;">&nbsp;</td></tr>
@endif
<tr><td class="px" style="padding:20px 28px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td valign="middle" style="font-size:13px;">
@if ($mail->client !== null && $mail->site !== null)
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
@if ($mail->avatarTile !== null)
<td class="avatar-letter" aria-hidden="true" align="center" valign="middle" style="width:20px;height:20px;line-height:20px;border-radius:10px;background:{{ $mail->avatarTile->background }};color:{{ $mail->avatarTile->foreground }};font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:9px;font-weight:600;">{{ $mail->avatarTile->text }}</td><td style="width:5px;"></td>
@elseif ($mail->avatarCid !== null)
<td><img src="cid:{{ $mail->avatarCid }}@heptapod" width="20" height="20" alt="" style="vertical-align:middle;border-radius:10px;border:0;"></td><td style="width:5px;"></td>
@endif
<td><span class="c-fg2" style="color:#3f4952;vertical-align:middle;">{{ $mail->client }} · {{ $mail->site }}</span></td></tr></table>
@endif
</td><td align="right" valign="middle"><img src="cid:lockup@heptapod" width="109" height="20" alt="Heptapod" style="border:0;display:inline-block;"></td></tr></table></td></tr>
<tr><td class="px" style="padding:22px 28px 28px;">
<h1 class="h1 c-fg" style="margin:0 0 {{ $mail->lead !== null ? 8 : 12 }}px;font-size:21px;line-height:28px;font-weight:600;letter-spacing:-0.2px;color:#101418;">{{ $mail->headline }}</h1>
@if ($mail->lead !== null)
<p class="c-fg2" style="margin:0 0 14px;font-size:14px;line-height:21px;color:#3f4952;">{{ $mail->lead }}</p>
@endif
@if ($mail->chip !== null)
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;"><tr><td class="chip-{{ $tone }}" style="border:1px solid {{ $light[$tone] }};border-radius:11px;padding:1px 9px;font-size:12px;font-weight:600;color:{{ $light[$tone] }};white-space:nowrap;">{{ $mail->chip }}</td><td class="c-fg3" style="padding-left:10px;font-size:12.5px;color:#5c6771;">{{ $mail->stateMeta }}</td></tr></table>
@endif
@if ($mail->blocks !== [])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
@foreach ($mail->blocks as $block)
<tr><td style="padding:0 0 16px;">@include('channels::mail.blocks.'.$block->type(), ['plain' => false])</td></tr>
@endforeach
</table>
@endif
@if ($mail->primary !== null)
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0;"><tr><td class="btn-primary" style="background:#101418;border-radius:16px;"><a href="{{ $mail->primary['url'] }}" style="display:inline-block;padding:8px 18px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">{{ $mail->primary['label'] }}</a></td>
@if ($mail->secondary !== null)
<td style="padding-left:16px;font-size:14px;"><a href="{{ $mail->secondary['url'] }}" class="c-fg2" style="color:#3f4952;text-decoration:underline;">{{ $mail->secondary['label'] }}</a></td>
@endif
</tr></table>
@endif
</td></tr></table></td></tr>
<tr><td class="px" style="padding:16px 28px 0;font-size:12px;line-height:18px;"><p class="c-fg3" style="margin:0 0 6px;color:#5c6771;">{{ $mail->footerWhy }}@if ($mail->routingUrl !== null) <a href="{{ $mail->routingUrl }}" class="c-fg2" style="color:#3f4952;text-decoration:underline;">{{ $mail->routingLabel }}</a>@endif</p><p class="c-fg3" style="margin:0;color:#5c6771;">{{ $mail->instance }}</p></td></tr>
</table></td></tr></table></body></html>
@endif
