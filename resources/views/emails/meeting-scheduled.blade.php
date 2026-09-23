<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>موعد اجتماع</h2>
<p>السلام عليكم،</p>
<p>تم جدولة اجتماع "{{ $meeting->title }}".</p>
<p>التاريخ: {{ \App\Support\DisplayTime::format($meeting->scheduled_at, 'Y-m-d') }}</p>
<p>الوقت: {{ \App\Support\DisplayTime::format($meeting->scheduled_at, 'H:i') }}</p>
@if($meeting->link)
<p>رابط الاجتماع: <a href="{{ $meeting->link }}">{{ $meeting->link }}</a></p>
@endif
@if($meeting->passcode)
<p>كلمة المرور: {{ $meeting->passcode }}</p>
@endif
</body></html>
