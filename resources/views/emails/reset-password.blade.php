<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
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
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Reset your password</h2>
<p>Hello,</p>
<p>We received a request to reset the password of your ShadApp account.</p>
<p>
    <a href="{{ $url }}" style="display: inline-block; background: #941414; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none;">
        Reset password
    </a>
</p>
<p>This link is valid for {{ $minutes }} minutes only.</p>
<p>If you did not request a password reset, ignore this message and nothing will change in your account.</p>
<hr>
<p style="font-size: 12px; color: #666;">
    If the button does not work, copy this link into your browser:<br>
    <span dir="ltr">{{ $url }}</span>
</p>
</div>
</body></html>
