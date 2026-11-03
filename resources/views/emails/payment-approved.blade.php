<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>تم اعتماد الدفعة</h2>
<p>السلام عليكم،</p>
<p>تم اعتماد الدفعة بقيمة {{ number_format($payment->amount, 2) }} ر.س.</p>
<p>تاريخ الاعتماد: {{ \App\Support\DisplayTime::format($payment->reviewed_at ?? now()) }}</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Payment approved</h2>
<p>Hello,</p>
<p>The payment of {{ number_format($payment->amount, 2) }} SAR has been approved.</p>
<p>Approval date: {{ \App\Support\DisplayTime::format($payment->reviewed_at ?? now()) }}</p>
</div>
</body></html>
