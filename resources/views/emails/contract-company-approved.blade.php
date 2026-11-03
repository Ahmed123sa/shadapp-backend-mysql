<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>تم اعتماد العقد نهائياً</h2>
<p>السلام عليكم،</p>
<p>تم اعتماد العقد "{{ $contract->title }}" بشكل نهائي من الطرفين.</p>
<p>قيمة العقد: {{ number_format($contract->value, 2) }} ر.س</p>
<p>تاريخ الاعتماد النهائي: {{ \App\Support\DisplayTime::format($contract->company_signed_at ?? now()) }}</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>The contract is fully approved</h2>
<p>Hello,</p>
<p>The contract "{{ $contract->title }}" has been finally approved by both parties.</p>
<p>Contract value: {{ number_format($contract->value, 2) }} SAR</p>
<p>Final approval date: {{ \App\Support\DisplayTime::format($contract->company_signed_at ?? now()) }}</p>
</div>
</body></html>
