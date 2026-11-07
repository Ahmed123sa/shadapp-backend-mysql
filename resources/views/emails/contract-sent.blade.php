<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>تم إرسال العقد</h2>
<p>السلام عليكم،</p>
<p>تم إرسال عقد "{{ $contract->title }}" للمراجعة.</p>
<p>قيمة العقد: {{ number_format($contract->value, 2) }} ر.س</p>
@if($contract->ads_percentage !== null)
<p>نسبة إدارة الإعلانات ({{ rtrim(rtrim(number_format($contract->ads_percentage, 2, '.', ''), '0'), '.') }}%): {{ number_format($contract->ads_amount, 2) }} ر.س</p>
<p>ميزانية الإعلانات: {{ number_format($contract->ads_budget, 2) }} ر.س</p>
@endif
@if($contract->requiredDocuments->count() > 0)
<h3 style="margin-top:20px;">المستندات المطلوبة</h3>
<ul>
@foreach($contract->requiredDocuments as $doc)
<li>{{ $doc->name }} @if($doc->is_required) (مطلوب) @endif</li>
@endforeach
</ul>
@endif
<p>تاريخ الإرسال: {{ \App\Support\DisplayTime::format(now()) }}</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Contract sent</h2>
<p>Hello,</p>
<p>The contract "{{ $contract->title }}" was sent for review.</p>
<p>Contract value: {{ number_format($contract->value, 2) }} SAR</p>
@if($contract->ads_percentage !== null)
<p>Ads management fee ({{ rtrim(rtrim(number_format($contract->ads_percentage, 2, '.', ''), '0'), '.') }}%): {{ number_format($contract->ads_amount, 2) }} SAR</p>
<p>Ad budget: {{ number_format($contract->ads_budget, 2) }} SAR</p>
@endif
@if($contract->requiredDocuments->count() > 0)
<h3 style="margin-top:20px;">Required documents</h3>
<ul>
@foreach($contract->requiredDocuments as $doc)
<li>{{ $doc->name }} @if($doc->is_required) (required) @endif</li>
@endforeach
</ul>
@endif
<p>Sent on: {{ \App\Support\DisplayTime::format(now()) }}</p>
</div>
</body></html>
