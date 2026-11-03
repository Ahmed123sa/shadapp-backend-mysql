<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>تذكير بمراجعة العقد</h2>
<p>السلام عليكم،</p>
<p>العقد "{{ $contract->title }}" ينتظر مراجعتك.</p>
<p>قيمة العقد: {{ number_format($contract->value, 2) }} ر.س</p>
@if($daysPending > 0)
<p>عدد أيام الانتظار: {{ $daysPending }} يوم</p>
@endif
<p>يرجى مراجعة العقد في أقرب وقت ممكن.</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Contract review reminder</h2>
<p>Hello,</p>
<p>The contract "{{ $contract->title }}" is waiting for your review.</p>
<p>Contract value: {{ number_format($contract->value, 2) }} SAR</p>
@if($daysPending > 0)
<p>Days waiting: {{ $daysPending }}</p>
@endif
<p>Please review the contract as soon as possible.</p>
</div>
</body></html>
