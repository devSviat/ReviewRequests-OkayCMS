{* Лист рендериться з планувальника: Smarty-плагіни ядра там не зареєстровані,
   тож усі адреси й картинки приходять готовими змінними rr_*. *}
{$subject = $lang->sviat__review_requests__email_subject scope=global}
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="x-apple-disable-message-reformatting">
    <meta content="telephone=no" name="format-detection">
    {include file="`$rr_root_dir`backend/design/html/email/email_head.tpl"}
</head>
<body>
<div class="es-wrapper-color">
    <table class="es-wrapper" width="100%" cellspacing="0" cellpadding="0" id="backgroundTable">
        <tbody>
        <tr>
            <td class="es-p25t es-p25b" valign="center">
                <table class="es-header" cellspacing="0" cellpadding="0" align="center">
                    <tbody>
                    <tr>
                        <td align="center" class="es-p20t es-p20b">
                            <a target="_blank" href="{$rr_root_url|escape}">
                                <img src="{$rr_logo_url|escape}" alt="{$settings->site_name|escape}" width="200" style="display:block;margin:0 auto" />
                            </a>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <table class="es-content" cellspacing="0" cellpadding="0" align="center">
                    <tbody>
                    <tr>
                        <td align="center">
                            <table class="es-content-body" width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" align="center">
                                <tbody>
                                <tr>
                                    <td class="es-p20t es-p30b es-p25r es-p25l" align="left">
                                        <h2 style="margin:0 0 12px;">{if $rr_first_name}{$lang->sviat__review_requests__email_hello|escape}, {$rr_first_name|escape}!{else}{$lang->sviat__review_requests__email_hello|escape}!{/if}</h2>
                                        <p>{$lang->sviat__review_requests__email_text|escape}</p>

                                        <table width="100%" cellspacing="0" cellpadding="0" style="margin:16px 0;">
                                            <tbody>
                                            {foreach $rr_purchases as $purchase}
                                                <tr>
                                                    <td width="130" style="padding:8px 0;" valign="top">
                                                        {if $purchase->product->image_url}
                                                            <a target="_blank" href="{$purchase->product->absolute_url|escape}"><img src="{$purchase->product->image_url|escape}" alt="" width="120" style="display:block;border:0;" /></a>
                                                        {/if}
                                                    </td>
                                                    <td style="padding:8px 0;" valign="middle">
                                                        <a target="_blank" href="{$purchase->product->absolute_url|escape}" style="color:#222;text-decoration:none;font-weight:600;">{$purchase->product->name|escape}</a>
                                                    </td>
                                                </tr>
                                            {/foreach}
                                            </tbody>
                                        </table>

                                        <p style="text-align:center;margin:24px 0;">
                                            <a target="_blank" href="{$rr_review_url|escape}" style="display:inline-block;padding:12px 28px;background:#e63e26;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">{$lang->sviat__review_requests__email_button|escape}</a>
                                        </p>

                                        <p style="color:#777;font-size:13px;">{$lang->sviat__review_requests__email_why|escape}</p>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <table class="es-footer" cellspacing="0" cellpadding="0" align="center">
                    <tbody>
                    <tr>
                        <td align="center" class="es-p20t es-p20b" style="color:#999;font-size:12px;">
                            {$lang->sviat__review_requests__email_unsubscribe_note|escape}
                            <a target="_blank" href="{$rr_unsubscribe_url|escape}" style="color:#999;">{$lang->sviat__review_requests__email_unsubscribe_link|escape}</a>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
</div>
</body>
</html>
