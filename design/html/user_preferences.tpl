{* Блок у кабінеті покупця (модифікація user.tpl). Адреса форми абсолютна:
   у темах із <base href> відносна вела б на головну. *}
<div class="form form--boxed" id="sviat_review_requests_preferences" style="margin-top:24px;">
    <div class="form__header">
        <div class="form__title"><span>{$lang->sviat__review_requests__pref_title|escape}</span></div>
    </div>
    <div class="form__body">
        {if $rr_pref_saved}
            <div class="message_success">{$lang->sviat__review_requests__pref_saved|escape}</div>
        {/if}
        {if $rr_pref.locked}
            <p>{$lang->sviat__review_requests__pref_locked|escape}</p>
        {else}
            <form method="post" action="{$rr_pref_url|escape}">
                <input type="hidden" name="customer_csrf_token" value="{$rr_pref_csrf|escape}">
                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;">
                    <input type="checkbox" name="subscribed" value="1"{if $rr_pref.subscribed} checked{/if} style="margin-top:3px;">
                    <span>{$lang->sviat__review_requests__pref_label|escape} ({$rr_pref.email|escape})</span>
                </label>
                <div class="form__footer" style="margin-top:12px;">
                    <input class="form__button" type="submit" value="{$lang->sviat__review_requests__pref_save|escape}">
                </div>
            </form>
        {/if}
    </div>
</div>
