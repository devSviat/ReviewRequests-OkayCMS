{* Відписка через кнопку, а не одним GET: сканери посилань у пошті відкривають
   посилання самі, і відписали б людину без її відома. *}
<div class="block">
    <div class="block__header block__header--boxed block__header--border">
        <h1 class="block__heading">
            <span>{$lang->sviat__review_requests__unsubscribe_title|escape}</span>
        </h1>
    </div>

    <div class="block__body block--boxed block--border">
        {if $rr_unsubscribed}
            <div class="message_success">{$lang->sviat__review_requests__unsubscribe_done|escape}</div>
        {else}
            <form method="post" action="{$rr_unsubscribe_form_url|escape}" class="form form--boxed">
                <input type="hidden" name="customer_csrf_token" value="{$rr_csrf|escape}">
                <div class="form__body">
                    <p>{$lang->sviat__review_requests__unsubscribe_text|escape}</p>
                </div>
                <div class="form__footer">
                    <input class="form__button" type="submit" value="{$lang->sviat__review_requests__unsubscribe_button|escape}" />
                </div>
            </form>
        {/if}
    </div>
</div>
