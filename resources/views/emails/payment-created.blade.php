<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>تم إنشاء دفعة جديدة</h2>
<p>السلام عليكم،</p>
<p>تم إنشاء دفعة جديدة بقيمة {{ number_format($payment->amount, 2) }} ر.س.</p>
<p>حالة الدفعة: قيد المراجعة</p>
<p>تاريخ الإنشاء: {{ \App\Support\DisplayTime::format($payment->created_at) }}</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>New payment created</h2>
<p>Hello,</p>
<p>A new payment of {{ number_format($payment->amount, 2) }} SAR has been created.</p>
<p>Payment status: Under review</p>
<p>Created on: {{ \App\Support\DisplayTime::format($payment->created_at) }}</p>
</div>
</body></html>
