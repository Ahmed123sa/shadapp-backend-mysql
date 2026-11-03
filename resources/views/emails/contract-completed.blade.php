<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>تم إكمال العقد</h2>
<p>السلام عليكم،</p>
<p>تم إكمال العقد "{{ $contract->title }}" بنجاح.</p>
<p>قيمة العقد: {{ number_format($contract->value, 2) }} ر.س</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Contract completed</h2>
<p>Hello,</p>
<p>The contract "{{ $contract->title }}" was completed successfully.</p>
<p>Contract value: {{ number_format($contract->value, 2) }} SAR</p>
</div>
</body></html>
