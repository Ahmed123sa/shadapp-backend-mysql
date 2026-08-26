<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>تذكير بمراجعة العقد</h2>
<p>السلام عليكم،</p>
<p>العقد "{{ $contract->title }}" ينتظر مراجعتك.</p>
<p>قيمة العقد: {{ number_format($contract->value, 2) }} ر.س</p>
@if($daysPending > 0)
<p>عدد أيام الانتظار: {{ $daysPending }} يوم</p>
@endif
<p>يرجى مراجعة العقد في أقرب وقت ممكن.</p>
</body></html>
