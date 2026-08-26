<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>تم اعتماد العقد نهائياً</h2>
<p>السلام عليكم،</p>
<p>تم اعتماد العقد "{{ $contract->title }}" بشكل نهائي من الطرفين.</p>
<p>قيمة العقد: {{ number_format($contract->value, 2) }} ر.س</p>
<p>تاريخ الاعتماد النهائي: {{ $contract->company_signed_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i') }}</p>
</body></html>
