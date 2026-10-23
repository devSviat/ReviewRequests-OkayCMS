{$meta_title = $btr->sviat__review_requests__title scope=global}

<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="heading_page">{$btr->sviat__review_requests__title|escape}</div>
    </div>
</div>

{if $message_success}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--success">
                <div class="alert__content">
                    <div class="alert__title">
                        {if $message_success == 'saved'}{$btr->general_settings_saved|escape}
                        {elseif $message_success == 'test_sent'}{$btr->sviat__review_requests__test_sent|escape}
                        {elseif $message_success == 'optout_removed'}{$btr->sviat__review_requests__optout_removed|escape}
                        {elseif $message_success == 'backfill_done'}{$btr->sviat__review_requests__backfill_done|escape}: {$rr_backfill_count|escape}
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
{/if}
{if $message_error}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--error">
                <div class="alert__content">
                    <div class="alert__title">
                        {if $message_error == 'test_bad_email'}{$btr->sviat__review_requests__test_bad_email|escape}
                        {elseif $message_error == 'test_no_orders'}{$btr->sviat__review_requests__test_no_orders|escape}
                        {elseif $message_error == 'optout_not_found'}{$btr->sviat__review_requests__optout_not_found|escape}
                        {elseif $message_error == 'root_url_foreign'}{$btr->sviat__review_requests__root_url_foreign|escape}
                        {elseif $message_error == 'test_too_many'}{$btr->sviat__review_requests__test_too_many|escape}
                        {else}{$btr->sviat__review_requests__test_failed|escape}{/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
{/if}

{* Режим — перше, що має бути видно: у тестовому листи не йдуть узагалі. *}
<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="alert alert--icon {if $rr.is_live}alert--success{else}alert--warning{/if}">
            <div class="alert__content">
                <div class="alert__title">{if $rr.is_live}{$btr->sviat__review_requests__mode_live_title|escape}{else}{$btr->sviat__review_requests__mode_test_title|escape}{/if}</div>
                <p>{if $rr.is_live}{$btr->sviat__review_requests__mode_live_text|escape}{else}{$btr->sviat__review_requests__mode_test_text|escape}{/if}</p>
                {if !$rr.statuses}
                    <p><strong>{$btr->sviat__review_requests__statuses_missing|escape}</strong></p>
                {/if}
                {if !$rr.effective_root_url}
                    <p><strong>{$btr->sviat__review_requests__root_url_missing|escape}</strong></p>
                {/if}
                {if $rr.window_empty}
                    <p><strong>{$btr->sviat__review_requests__window_empty|escape}</strong></p>
                {/if}
            </div>
        </div>
    </div>
</div>

{* Підсумки *}
<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="boxed">
            <div class="heading_box">{$btr->sviat__review_requests__stats_title|escape}</div>
            <table class="table_default">
                <tr class="table_default__row">
                    <td class="table_default__item text_grey">{$btr->sviat__review_requests__stats_sent_30|escape}</td>
                    <td class="table_default__item"><strong>{$rr_stats.sent_30}</strong></td>
                    <td class="table_default__item text_grey">{$btr->sviat__review_requests__stats_visited_30|escape}</td>
                    <td class="table_default__item"><strong>{$rr_stats.visited_30}</strong></td>
                    <td class="table_default__item text_grey">{$btr->sviat__review_requests__stats_reviews_30|escape}</td>
                    <td class="table_default__item"><strong>{$rr_stats.reviews_30}</strong> ({$btr->sviat__review_requests__stats_total|escape} {$rr_stats.reviews_total})</td>
                </tr>
                <tr class="table_default__row">
                    {foreach ['pending','sent','skipped','cancelled','error','baseline'] as $s}
                        <td class="table_default__item text_grey">{$rr_key = "sviat__review_requests__status_`$s`"}{$btr->getTranslation($rr_key)|escape}</td>
                        <td class="table_default__item">{$rr_stats.by_status.$s|default:0}</td>
                    {/foreach}
                </tr>
            </table>
            <p class="text_grey font_12 mt-1">{$btr->sviat__review_requests__server_time|escape}: {$rr_server_time|escape} — {if $rr_in_window}{$btr->sviat__review_requests__in_window|escape}{else}{$btr->sviat__review_requests__out_of_window|escape}{/if}</p>
        </div>
    </div>
</div>

{* Налаштування *}
<form method="post">
    <input type="hidden" name="session_id" value="{$smarty.session.id}">
    <input type="hidden" name="action" value="save">
    <div class="row">
        <div class="col-lg-12 col-md-12">
            <div class="boxed">
                <div class="heading_box">{$btr->sviat__review_requests__settings_title|escape}</div>
                <div class="row">
                    <div class="col-lg-4 col-md-6 mb-1">
                        <div class="heading_label">{$btr->sviat__review_requests__mode|escape}</div>
                        <select name="mode" class="selectpicker form-control">
                            <option value="test" {if !$rr.is_live}selected{/if}>{$btr->sviat__review_requests__mode_test|escape}</option>
                            <option value="live" {if $rr.is_live}selected{/if}>{$btr->sviat__review_requests__mode_live|escape}</option>
                        </select>
                    </div>
                    <div class="col-lg-8 col-md-6 mb-1">
                        <div class="heading_label">{$btr->sviat__review_requests__statuses|escape}</div>
                        {foreach $rr_statuses as $status}
                            <label class="mr-1"><input type="checkbox" name="statuses[]" value="{$status->id}" {if isset($rr.statuses_map[$status->id])}checked{/if}> {$status->name|escape}</label>
                        {/foreach}
                        <p class="text_grey font_12">{$btr->sviat__review_requests__statuses_hint|escape}</p>
                    </div>
                    {foreach ['delay_days','daily_cap','email_interval_days','max_order_age_days','token_ttl_days','window_from','window_to'] as $field}
                        <div class="col-lg-3 col-md-4 mb-1">
                            <div class="heading_label">{$rr_key = "sviat__review_requests__`$field`"}{$btr->getTranslation($rr_key)|escape}</div>
                            <input class="form-control" type="number" name="{$field}" value="{$rr.$field|escape}">
                        </div>
                    {/foreach}
                    <div class="col-lg-6 col-md-12 mb-1">
                        <div class="heading_label">{$btr->sviat__review_requests__root_url|escape}</div>
                        <input class="form-control" type="text" name="root_url" value="{$rr.root_url|escape}" placeholder="{$rr.effective_root_url|escape}">
                        <p class="text_grey font_12">{$btr->sviat__review_requests__root_url_hint|escape}</p>
                    </div>
                    <div class="col-lg-12 col-md-12 mb-1">
                        <label><input type="checkbox" name="one_click" value="1" {if $rr.one_click}checked{/if}> {$btr->sviat__review_requests__one_click|escape}</label>
                        <p class="text_grey font_12">{$btr->sviat__review_requests__one_click_hint|escape}</p>
                    </div>
                    <div class="col-lg-6 col-md-12 mb-1">
                        <div class="heading_label">{$btr->sviat__review_requests__excluded|escape}</div>
                        <textarea class="form-control" name="excluded" rows="3">{$rr.excluded|escape}</textarea>
                        <p class="text_grey font_12">{$btr->sviat__review_requests__excluded_hint|escape}</p>
                    </div>
                </div>
                <button type="submit" class="btn btn_small btn_blue">{$btr->general_apply|escape}</button>
            </div>
        </div>
    </div>
</form>

{* Тестовий лист і ручний добір *}
<div class="row">
    <div class="col-lg-6 col-md-12">
        <form method="post" class="boxed">
            <input type="hidden" name="session_id" value="{$smarty.session.id}">
            <input type="hidden" name="action" value="send_test">
            <div class="heading_box">{$btr->sviat__review_requests__test_title|escape}</div>
            <p class="text_grey font_12">{$btr->sviat__review_requests__test_hint|escape}</p>
            <input class="form-control mb-1" type="email" name="test_email" required placeholder="you@example.com">
            <button type="submit" class="btn btn_small btn-outline-info">{$btr->sviat__review_requests__test_button|escape}</button>
        </form>
    </div>
    <div class="col-lg-6 col-md-12">
        <form method="post" class="boxed">
            <input type="hidden" name="session_id" value="{$smarty.session.id}">
            <div class="heading_box">{$btr->sviat__review_requests__backfill_title|escape}</div>
            <p class="text_grey font_12">{$btr->sviat__review_requests__backfill_hint|escape}</p>
            <div class="row">
                <div class="col-6"><input class="form-control mb-1" type="date" name="date_from" value="{$backfill_period[0]|default:''|escape}"></div>
                <div class="col-6"><input class="form-control mb-1" type="date" name="date_to" value="{$backfill_period[1]|default:''|escape}"></div>
            </div>
            {if $backfill_preview}
                <p>
                    {$btr->sviat__review_requests__backfill_found|escape}: <strong>{$backfill_preview.total}</strong>,
                    {$btr->sviat__review_requests__backfill_will_queue|escape}: <strong>{$backfill_preview.pending}</strong>
                    {foreach $backfill_preview.skipped as $reason => $n}
                        <br><span class="text_grey">{$rr_key = "sviat__review_requests__reason_`$reason`"}{$btr->getTranslation($rr_key)|escape}: {$n}</span>
                    {/foreach}
                    {if $backfill_preview.truncated}<br><span class="text_warning">{$btr->sviat__review_requests__backfill_truncated|escape}</span>{/if}
                </p>
                <button type="submit" name="action" value="backfill_confirm" class="btn btn_small btn-outline-warning">{$btr->sviat__review_requests__backfill_confirm|escape}</button>
            {/if}
            <button type="submit" name="action" value="backfill_preview" class="btn btn_small btn-outline-info">{$btr->sviat__review_requests__backfill_preview|escape}</button>
        </form>
    </div>
</div>

{* Стоп-лист: адреси зберігаються хешем, тож лише перевірка конкретної адреси *}
<div class="row">
    <div class="col-lg-6 col-md-12">
        <form method="post" class="boxed">
            <input type="hidden" name="session_id" value="{$smarty.session.id}">
            <input type="hidden" name="action" value="optout_remove">
            <div class="heading_box">{$btr->sviat__review_requests__optout_title|escape}</div>
            <p class="text_grey font_12">{$btr->sviat__review_requests__optout_hint|escape}</p>
            <input class="form-control mb-1" type="email" name="optout_email" required placeholder="buyer@example.com">
            <button type="submit" class="btn btn_small btn-outline-info">{$btr->sviat__review_requests__optout_button|escape}</button>
        </form>
    </div>
</div>

{* Журнал *}
<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="boxed">
            <div class="heading_box">{$btr->sviat__review_requests__log_title|escape}</div>
            {if $rr_log}
                <table class="table_default">
                    <tr class="table_default__row text_grey">
                        <td class="table_default__item">{$btr->sviat__review_requests__log_order|escape}</td>
                        <td class="table_default__item">{$btr->sviat__review_requests__log_email|escape}</td>
                        <td class="table_default__item">{$btr->sviat__review_requests__log_status|escape}</td>
                        <td class="table_default__item">{$btr->sviat__review_requests__log_detected|escape}</td>
                        <td class="table_default__item">{$btr->sviat__review_requests__log_sent|escape}</td>
                        <td class="table_default__item">{$btr->sviat__review_requests__log_visited|escape}</td>
                        <td class="table_default__item">{$btr->sviat__review_requests__log_reviews|escape}</td>
                    </tr>
                    {foreach $rr_log as $row}
                        <tr class="table_default__row">
                            <td class="table_default__item"><a href="{url controller=OrderAdmin id=$row->order_id return=null}">#{$row->order_id}</a></td>
                            <td class="table_default__item">{$row->email_masked|escape}</td>
                            <td class="table_default__item">
                                {$rr_key = "sviat__review_requests__status_`$row->status`"}{$btr->getTranslation($rr_key)|escape}
                                {if $row->skip_reason}<span class="text_grey">({$rr_key = "sviat__review_requests__reason_`$row->skip_reason`"}{$btr->getTranslation($rr_key)|escape})</span>{/if}
                                {if $row->error_text}<br><span class="text_warning font_12">{$row->error_text|escape}</span>{/if}
                            </td>
                            <td class="table_default__item">{$row->detected_at|escape}</td>
                            <td class="table_default__item">{$row->sent_at|escape}</td>
                            <td class="table_default__item">{$row->first_visit_at|escape}</td>
                            <td class="table_default__item">{$row->reviews}</td>
                        </tr>
                    {/foreach}
                </table>
            {else}
                <p class="text_grey">{$btr->sviat__review_requests__log_empty|escape}</p>
            {/if}
        </div>
    </div>
</div>
