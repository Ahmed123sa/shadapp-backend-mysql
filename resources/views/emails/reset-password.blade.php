<!DOCTYPE html>
<html dir="rtl"><body style="font-family: sans-serif; line-height: 1.6;">
<h2>إعادة تعيين كلمة المرور</h2>
<p>السلام عليكم،</p>
<p>وصلنا طلب لإعادة تعيين كلمة المرور الخاصة بحسابك في ShadApp.</p>
<p>
    <a href="{{ $url }}" style="display: inline-block; background: #941414; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none;">
        إعادة تعيين كلمة المرور
    </a>
</p>
<p>هذا الرابط صالح لمدة {{ $minutes }} دقيقة فقط.</p>
<p>إذا لم تطلب إعادة تعيين كلمة المرور، تجاهل هذه الرسالة ولن يتغير أي شيء في حسابك.</p>
<hr>
<p style="font-size: 12px; color: #666;">
    إذا لم يعمل الزر، انسخ الرابط التالي والصقه في المتصفح:<br>
    <span dir="ltr">{{ $url }}</span>
</p>
</body></html>
