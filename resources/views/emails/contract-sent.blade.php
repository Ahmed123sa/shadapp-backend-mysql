<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>تم إرسال العقد</h2>
<p>السلام عليكم،</p>
<p>تم إرسال عقد "{{ $contract->title }}" للمراجعة.</p>
<p>قيمة العقد: {{ number_format($contract->value, 2) }} ر.س</p>
@if($contract->requiredDocuments->count() > 0)
<h3 style="margin-top:20px;">المستندات المطلوبة</h3>
<ul>
@foreach($contract->requiredDocuments as $doc)
<li>{{ $doc->name }} @if($doc->is_required) (مطلوب) @endif</li>
@endforeach
</ul>
@endif
<p>تاريخ الإرسال: {{ now()->format('Y-m-d H:i') }}</p>
</body></html>
