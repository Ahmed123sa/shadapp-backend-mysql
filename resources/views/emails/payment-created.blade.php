<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>تم إنشاء دفعة جديدة</h2>
<p>السلام عليكم،</p>
<p>تم إنشاء دفعة جديدة بقيمة {{ number_format($payment->amount, 2) }} ر.س.</p>
<p>حالة الدفعة: قيد المراجعة</p>
<p>تاريخ الإنشاء: {{ $payment->created_at->format('Y-m-d H:i') }}</p>
</body></html>
