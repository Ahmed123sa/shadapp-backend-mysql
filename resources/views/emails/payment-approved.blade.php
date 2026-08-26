<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>تم اعتماد الدفعة</h2>
<p>السلام عليكم،</p>
<p>تم اعتماد الدفعة بقيمة {{ number_format($payment->amount, 2) }} ر.س.</p>
<p>تاريخ الاعتماد: {{ $payment->reviewed_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i') }}</p>
</body></html>
