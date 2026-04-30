@php
    $variant = $variant ?? 'primary';
    $primary = $variant === 'primary';
@endphp
@if ($primary)
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0;">
        <tr>
            <td align="center" bgcolor="#047844" style="border-radius: 6px;">
                <a href="{{ $href }}"
                   style="display:inline-block; padding: 13px 26px; font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:500; line-height:1; text-decoration:none; color: #ffffff;">
                    <!--[if mso]>&nbsp;&nbsp;&nbsp;<![endif]-->{{ $label }}<!--[if mso]>&nbsp;&nbsp;&nbsp;<![endif]-->
                </a>
            </td>
        </tr>
    </table>
@else
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0;">
        <tr>
            <td align="center" style="border-radius: 6px;">
                <a href="{{ $href }}"
                   style="display:inline-block; padding: 12px 25px; font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:500; line-height:1; text-decoration:none; color: #047844; border: 1px solid #047844; border-radius: 6px;">
                    {{ $label }}
                </a>
            </td>
        </tr>
    </table>
@endif
