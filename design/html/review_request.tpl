{* Сторінка за посиланням із листа. Лише класи теми й звичайна розмітка: шаблон
   рендериться в будь-якій темі, тож без svg.tpl і плагінів окремої теми. *}
<div class="block">
    <div class="block__header block__header--boxed block__header--border">
        <h1 class="block__heading">
            <span>{$lang->sviat__review_requests__page_title|escape}</span>
        </h1>
    </div>

    <div class="block__body block--boxed block--border">
        {if $rr_invalid}
            <div class="block__description">
                <p>{$lang->sviat__review_requests__link_invalid|escape}</p>
            </div>
        {else}
            {if $rr_flash.saved}
                <div class="message_success">{$lang->sviat__review_requests__saved|escape}</div>
            {/if}

            {if !$rr_items}
                <div class="block__description">
                    <p>{$lang->sviat__review_requests__nothing_to_review|escape}</p>
                </div>
            {else}
                <div class="block__description">
                    <p>{$lang->sviat__review_requests__intro|escape}</p>
                </div>

                {foreach $rr_items as $item}
                    {$is_error = ($rr_flash.error && $rr_flash.item_id == $item->id)}
                    <div class="form form--boxed" id="review_item_{$item->id}" style="margin-top:20px;">
                        <div class="form__header">
                            <div class="form__title">
                                <span>{$item->product->name|escape}</span>
                            </div>
                        </div>

                        {if $item->comment_id || $item->reviewed_at}
                            <div class="form__body">
                                <div class="message_success">{$lang->sviat__review_requests__already_reviewed|escape}</div>
                            </div>
                        {else}
                            {* Адреса абсолютна: у темах із <base href> відносна «#…» вела б форму на головну. *}
                            <form method="post" action="{$rr_form_url|escape}#review_item_{$item->id}">
                                <input type="hidden" name="customer_csrf_token" value="{$rr_csrf|escape}">
                                <input type="hidden" name="form_token" value="{$rr_form_tokens[$item->id]|escape}">
                                <input type="hidden" name="item_id" value="{$item->id}">

                                {if $is_error}
                                    <div class="message_error">
                                        {if $rr_flash.error == 'empty_name'}{$lang->sviat__review_requests__error_name|escape}
                                        {elseif $rr_flash.error == 'empty_rating'}{$lang->sviat__review_requests__error_rating|escape}
                                        {elseif $rr_flash.error == 'empty_text'}{$lang->sviat__review_requests__error_text|escape}
                                        {elseif $rr_flash.error == 'already_reviewed' || $rr_flash.error == 'duplicate'}{$lang->sviat__review_requests__already_reviewed|escape}
                                        {else}{$lang->sviat__review_requests__error_not_saved|escape}{/if}
                                    </div>
                                {/if}

                                <div class="form__body">
                                    <div class="form__group review_rating">
                                        <span class="review_rating__label">{$lang->sviat__review_requests__rating|escape}*</span>
                                        <div class="review_rating__stars">
                                            {foreach [5,4,3,2,1] as $star}
                                                <input class="review_rating__input" type="radio" name="rating" value="{$star}" id="rr_rating_{$item->id}_{$star}" required=""{if $is_error && $rr_flash.data.rating == $star} checked=""{/if} />
                                                <label class="review_rating__star" for="rr_rating_{$item->id}_{$star}" title="{$star}"><span class="sr-only">{$star}</span></label>
                                            {/foreach}
                                        </div>
                                    </div>

                                    <div class="form__group">
                                        <input class="form__input form__placeholder--focus" type="text" name="name" maxlength="100" required="" value="{if $is_error}{$rr_flash.data.name|escape}{else}{$rr_default_name|escape}{/if}" />
                                        <span class="form__placeholder">{$lang->sviat__review_requests__name|escape}*</span>
                                    </div>

                                    <div class="form__group">
                                        <textarea class="form__textarea form__placeholder--focus" rows="4" name="text" maxlength="5000" required="">{if $is_error}{$rr_flash.data.text|escape}{/if}</textarea>
                                        <span class="form__placeholder">{$lang->sviat__review_requests__text|escape}*</span>
                                    </div>
                                </div>

                                <div class="form__footer">
                                    <input class="form__button" type="submit" value="{$lang->sviat__review_requests__submit|escape}" />
                                </div>
                            </form>
                        {/if}
                    </div>
                {/foreach}

                <p class="text_grey" style="margin-top:20px;">{$lang->sviat__review_requests__moderation_note|escape}</p>
            {/if}
        {/if}
    </div>
</div>
