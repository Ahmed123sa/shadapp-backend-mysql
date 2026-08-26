<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>شهادة الموافقة</h2>
<p>السلام عليكم،</p>
<p>تم إصدار شهادة موافقة للطلب "{{ $approval->title }}".</p>
<p>رقم المرجع: {{ $approval->reference_no }}</p>
<p>القرار: {{ $approval->status === 'approved' ? 'تمت الموافقة' : ($approval->status === 'rejected' ? 'مرفوض' : $approval->status) }}</p>
@if($approval->responded_at)
<p>تاريخ الاعتماد: {{ $approval->responded_at->format('Y-m-d H:i') }}</p>
@endif
@if($approval->certificate && $approval->certificate->certificate_url)
<p><a href="{{ $approval->certificate->certificate_url }}">تحميل الشهادة</a></p>
@endif
</body></html>
