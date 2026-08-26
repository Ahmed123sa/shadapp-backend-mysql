<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>تم اعتماد العقد من العميل</h2>
<p>السلام عليكم،</p>
<p>قام العميل باعتماد العقد "{{ $contract->title }}".</p>
<p>تاريخ الاعتماد: {{ $contract->client_signed_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i') }}</p>
<p>يرجى مراجعة العقد وإكمال الاعتماد النهائي.</p>
</body></html>
