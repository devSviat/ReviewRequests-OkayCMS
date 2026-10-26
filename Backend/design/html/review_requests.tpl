{$meta_title = $btr->sviat__review_requests__title scope=global}

{* Вкладки — компонент адмінки (.tabs): обрана вкладка живе в адресі як active_tab,
   тож форми відправляються на свою вкладку, і після збереження сторінка на ній і лишається. *}
{$rr_problems = []}
{if !$rr.statuses}{$rr_problems[] = $btr->sviat__review_requests__statuses_missing}{/if}
{if !$rr.effective_root_url}{$rr_problems[] = $btr->sviat__review_requests__root_url_missing}{/if}
{if $rr.window_empty}{$rr_problems[] = $btr->sviat__review_requests__window_empty}{/if}

<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="rr-head">
            <div class="heading_page">{$btr->sviat__review_requests__title|escape}</div>
            <span class="rr-pill {if $rr.is_live}rr-pill--live{else}rr-pill--test{/if}">
                <span class="rr-pill__dot"></span>{if $rr.is_live}{$btr->sviat__review_requests__mode_badge_live|escape}{else}{$btr->sviat__review_requests__mode_badge_test|escape}{/if}
            </span>
        </div>
    </div>
</div>

{if $message_success}
    <div class="alert alert--center alert--icon alert--success">
        <div class="alert__content">
            <div class="alert__title">
                {if $message_success == 'saved'}{$btr->general_settings_saved|escape}
                {elseif $message_success == 'test_sent'}{$btr->sviat__review_requests__test_sent|escape}
                {elseif $message_success == 'optout_removed'}{$btr->sviat__review_requests__optout_removed|escape}
                {elseif $message_success == 'verified_done'}{$btr->sviat__review_requests__verified_done|escape}: {$rr_verified_done_count|escape}
                {elseif $message_success == 'backfill_done'}{$btr->sviat__review_requests__backfill_done|escape}: {$rr_backfill_count|escape}
                {/if}
            </div>
        </div>
    </div>
{/if}
{if $message_error}
    <div class="alert alert--center alert--icon alert--error">
        <div class="alert__content">
            <div class="alert__title">
                {if $message_error == 'test_bad_email'}{$btr->sviat__review_requests__test_bad_email|escape}
                {elseif $message_error == 'test_no_orders'}{$btr->sviat__review_requests__test_no_orders|escape}
                {elseif $message_error == 'optout_not_found'}{$btr->sviat__review_requests__optout_not_found|escape}
                {elseif $message_error == 'root_url_foreign'}{$btr->sviat__review_requests__root_url_foreign|escape}
                {elseif $message_error == 'verified_unavailable'}{$btr->sviat__review_requests__verified_unavailable|escape}
                {elseif $message_error == 'test_too_many'}{$btr->sviat__review_requests__test_too_many|escape}
                {else}{$btr->sviat__review_requests__test_failed|escape}{/if}
            </div>
        </div>
    </div>
{/if}

{if $rr_problems}
    <div class="alert alert--icon alert--error rr-problems">
        <div class="alert__content">
            <div class="alert__title">{$btr->sviat__review_requests__problems_title|escape}</div>
            <ul class="rr-problems__list">
                {foreach $rr_problems as $problem}<li>{$problem|escape}</li>{/foreach}
            </ul>
            <a class="btn btn_small btn_blue" href="{url active_tab='rr_settings'}">{$btr->sviat__review_requests__open_settings|escape}</a>
        </div>
    </div>
{/if}

<div class="tabs rr-tabs">
    <div class="heading_tabs">
        <div class="tab_navigation">
            <a href="#rr_overview" class="heading_box tab_navigation_link">{$btr->sviat__review_requests__tab_overview|escape}</a>
            <a href="#rr_settings" class="heading_box tab_navigation_link">{$btr->sviat__review_requests__tab_settings|escape}</a>
            <a href="#rr_tools" class="heading_box tab_navigation_link">{$btr->sviat__review_requests__tab_tools|escape}</a>
            <a href="#rr_log" class="heading_box tab_navigation_link">{$btr->sviat__review_requests__tab_log|escape}</a>
        </div>
    </div>

    <div class="tab_container">

        {* ── Огляд ── *}
        <div id="rr_overview" class="tab">
            <div class="rr-grid rr-grid--overview">
                <div class="rr-stack">
                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__results_title|escape}</h2>
                        <div class="rr-kpis">
                            <div class="rr-kpi">
                                <div class="rr-kpi__value">{$rr_stats.sent_30}</div>
                                <div class="rr-kpi__label">{$btr->sviat__review_requests__kpi_sent|escape}</div>
                            </div>
                            <div class="rr-kpi">
                                <div class="rr-kpi__value">{$rr_stats.visited_30}<span class="rr-kpi__pct">{$rr_stats.open_pct}%</span></div>
                                <div class="rr-kpi__label">{$btr->sviat__review_requests__kpi_opened|escape}</div>
                            </div>
                            <div class="rr-kpi">
                                <div class="rr-kpi__value">{$rr_stats.reviews_30}<span class="rr-kpi__pct">{$rr_stats.review_pct}%</span></div>
                                <div class="rr-kpi__label">{$btr->sviat__review_requests__kpi_reviews|escape}</div>
                            </div>
                            <div class="rr-kpi rr-kpi--muted">
                                <div class="rr-kpi__value">{$rr_stats.reviews_total}</div>
                                <div class="rr-kpi__label">{$btr->sviat__review_requests__kpi_reviews_total|escape}</div>
                            </div>
                        </div>
                        <p class="rr-note">{$btr->sviat__review_requests__kpi_note|escape}</p>
                    </section>

                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__queue_title|escape}</h2>
                        <div class="rr-queue">
                            {foreach ['pending','sent','skipped','cancelled','error'] as $s}
                                {$rr_key = "sviat__review_requests__status_`$s`"}
                                <a class="rr-queue__item" href="{url active_tab='rr_log' log_status=$s}">
                                    <span class="rr-badge rr-badge--{$s}">{$btr->getTranslation($rr_key)|escape}</span>
                                    <span class="rr-queue__count">{$rr_stats.by_status.$s|default:0}</span>
                                </a>
                            {/foreach}
                        </div>
                        {if $rr_stats.by_status.baseline|default:0 > 0}
                            <p class="rr-note">{$btr->sviat__review_requests__baseline_note|replace:'%s':$rr_stats.baseline_formatted|escape}
                                <a href="{url active_tab='rr_tools'}">{$btr->sviat__review_requests__backfill_title|escape}</a></p>
                        {/if}
                    </section>
                </div>

                <div class="rr-stack">
                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__setup_title|escape}</h2>
                        <ul class="rr-checklist">
                            <li class="{if $rr.statuses}is-ok{else}is-missing{/if}">
                                <span class="rr-checklist__label">{$btr->sviat__review_requests__statuses|escape}</span>
                                <span class="rr-checklist__value">
                                    {if $rr.statuses}
                                        {foreach $rr_statuses as $status}{if isset($rr.statuses_map[$status->id])}{$status->name|escape}{if !$status@last} {/if}{/if}{/foreach}
                                    {else}<a href="{url active_tab='rr_settings'}">{$btr->sviat__review_requests__not_set|escape}</a>{/if}
                                </span>
                            </li>
                            <li class="{if $rr.effective_root_url}is-ok{else}is-missing{/if}">
                                <span class="rr-checklist__label">{$btr->sviat__review_requests__root_url|escape}</span>
                                <span class="rr-checklist__value">
                                    {if $rr.effective_root_url}{$rr.effective_root_url|escape}{else}<a href="{url active_tab='rr_settings'}">{$btr->sviat__review_requests__not_set|escape}</a>{/if}
                                </span>
                            </li>
                            <li class="{if $rr.is_live}is-ok{else}is-wait{/if}">
                                <span class="rr-checklist__label">{$btr->sviat__review_requests__mode|escape}</span>
                                <span class="rr-checklist__value">{if $rr.is_live}{$btr->sviat__review_requests__mode_badge_live|escape}{else}{$btr->sviat__review_requests__mode_test_short|escape}{/if}</span>
                            </li>
                        </ul>
                        {if !$rr.is_live}<p class="rr-note">{$btr->sviat__review_requests__mode_test_text|escape}</p>{/if}
                    </section>

                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__schedule_title|escape}</h2>
                        <dl class="rr-facts">
                            <dt>{$btr->sviat__review_requests__server_time|escape}</dt>
                            <dd>{$rr_server_time|escape} · {if $rr_in_window}<span class="rr-ok">{$btr->sviat__review_requests__in_window|escape}</span>{else}{$btr->sviat__review_requests__out_of_window|escape}{/if}</dd>
                            <dt>{$btr->sviat__review_requests__window_label|escape}</dt>
                            <dd>{$rr.window_from|string_format:'%02d'}:00 – {$rr.window_to|string_format:'%02d'}:00</dd>
                            <dt>{$btr->sviat__review_requests__sent_today|escape}</dt>
                            <dd>{$rr_sent_today} / {$rr.daily_cap}</dd>
                            <dt>{$btr->sviat__review_requests__delay_days|escape}</dt>
                            <dd>{$rr.delay_days}</dd>
                        </dl>
                    </section>
                </div>
            </div>
        </div>

        {* ── Налаштування ── *}
        <div id="rr_settings" class="tab">
            <form method="post" action="{url active_tab='rr_settings'}">
                <input type="hidden" name="session_id" value="{$smarty.session.id}">
                <input type="hidden" name="action" value="save">

                <div class="rr-grid">
                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__section_mode|escape}</h2>
                        <div class="rr-field">
                            <div class="heading_label">{$btr->sviat__review_requests__mode|escape}</div>
                            <div class="rr-choices">
                                <label class="rr-choice">
                                    <input type="radio" name="mode" value="test" {if !$rr.is_live}checked{/if}>
                                    <span class="rr-choice__body">
                                        <span class="rr-choice__title">{$btr->sviat__review_requests__mode_test_short|escape}</span>
                                        <span class="rr-choice__text">{$btr->sviat__review_requests__mode_test_desc|escape}</span>
                                    </span>
                                </label>
                                <label class="rr-choice">
                                    <input type="radio" name="mode" value="live" {if $rr.is_live}checked{/if}>
                                    <span class="rr-choice__body">
                                        <span class="rr-choice__title">{$btr->sviat__review_requests__mode_live|escape}</span>
                                        <span class="rr-choice__text">{$btr->sviat__review_requests__mode_live_desc|escape}</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="rr-field">
                            <div class="heading_label">{$btr->sviat__review_requests__statuses|escape}</div>
                            <div class="rr-checks">
                                {foreach $rr_statuses as $status}
                                    <div class="okay_type_checkbox_wrap">
                                        <input id="rr_status_{$status->id}" class="hidden_check" type="checkbox" name="statuses[]" value="{$status->id}" {if isset($rr.statuses_map[$status->id])}checked{/if}>
                                        <label for="rr_status_{$status->id}" class="okay_type_checkbox"><span>{$status->name|escape}</span></label>
                                    </div>
                                {/foreach}
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__statuses_hint|escape}</p>
                        </div>
                    </section>

                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__section_when|escape}</h2>
                        <div class="rr-field">
                            <label class="heading_label" for="rr_delay_days">{$btr->sviat__review_requests__delay_days|escape}</label>
                            <div class="input-group rr-num">
                                <input id="rr_delay_days" class="form-control" type="number" min="0" max="90" name="delay_days" value="{$rr.delay_days|escape}">
                                <span class="input-group-addon">{$btr->sviat__review_requests__unit_days|escape}</span>
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__delay_hint|escape}</p>
                        </div>
                        <div class="rr-field">
                            <div class="heading_label">{$btr->sviat__review_requests__window_label|escape}</div>
                            <div class="rr-range">
                                <span>{$btr->sviat__review_requests__from|escape}</span>
                                <div class="input-group rr-num rr-num--hour">
                                    <input class="form-control" type="number" min="0" max="23" name="window_from" value="{$rr.window_from|escape}" aria-label="{$btr->sviat__review_requests__window_from|escape}">
                                    <span class="input-group-addon">{$btr->sviat__review_requests__unit_hours|escape}</span>
                                </div>
                                <span>{$btr->sviat__review_requests__to|escape}</span>
                                <div class="input-group rr-num rr-num--hour">
                                    <input class="form-control" type="number" min="0" max="24" name="window_to" value="{$rr.window_to|escape}" aria-label="{$btr->sviat__review_requests__window_to|escape}">
                                    <span class="input-group-addon">{$btr->sviat__review_requests__unit_hours|escape}</span>
                                </div>
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__window_hint|escape}</p>
                        </div>
                        <div class="rr-field">
                            <label class="heading_label" for="rr_daily_cap">{$btr->sviat__review_requests__daily_cap|escape}</label>
                            <div class="input-group rr-num">
                                <input id="rr_daily_cap" class="form-control" type="number" min="1" max="500" name="daily_cap" value="{$rr.daily_cap|escape}">
                                <span class="input-group-addon">{$btr->sviat__review_requests__unit_emails|escape}</span>
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__daily_cap_hint|escape}</p>
                        </div>
                    </section>

                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__section_limits|escape}</h2>
                        <div class="rr-field">
                            <label class="heading_label" for="rr_interval">{$btr->sviat__review_requests__email_interval_days|escape}</label>
                            <div class="input-group rr-num">
                                <input id="rr_interval" class="form-control" type="number" min="0" max="365" name="email_interval_days" value="{$rr.email_interval_days|escape}">
                                <span class="input-group-addon">{$btr->sviat__review_requests__unit_days|escape}</span>
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__interval_hint|escape}</p>
                        </div>
                        <div class="rr-field">
                            <label class="heading_label" for="rr_max_age">{$btr->sviat__review_requests__max_order_age_days|escape}</label>
                            <div class="input-group rr-num">
                                <input id="rr_max_age" class="form-control" type="number" min="1" max="365" name="max_order_age_days" value="{$rr.max_order_age_days|escape}">
                                <span class="input-group-addon">{$btr->sviat__review_requests__unit_days|escape}</span>
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__max_age_hint|escape}</p>
                        </div>
                        <div class="rr-field">
                            <label class="heading_label" for="rr_excluded">{$btr->sviat__review_requests__excluded|escape}</label>
                            <textarea id="rr_excluded" class="form-control rr-textarea" name="excluded" rows="3" placeholder="partner@example.com, example.com">{$rr.excluded|escape}</textarea>
                            <p class="rr-help">{$btr->sviat__review_requests__excluded_hint|escape}</p>
                        </div>
                    </section>

                    <section class="boxed rr-card">
                        <h2 class="rr-card__title">{$btr->sviat__review_requests__section_link|escape}</h2>
                        <div class="rr-field">
                            <label class="heading_label" for="rr_root_url">{$btr->sviat__review_requests__root_url|escape}</label>
                            <input id="rr_root_url" class="form-control" type="text" name="root_url" value="{$rr.root_url|escape}" placeholder="{$rr.effective_root_url|escape}">
                            <p class="rr-help">{$btr->sviat__review_requests__root_url_hint|escape}</p>
                        </div>
                        <div class="rr-field">
                            <label class="heading_label" for="rr_ttl">{$btr->sviat__review_requests__token_ttl_days|escape}</label>
                            <div class="input-group rr-num">
                                <input id="rr_ttl" class="form-control" type="number" min="1" max="180" name="token_ttl_days" value="{$rr.token_ttl_days|escape}">
                                <span class="input-group-addon">{$btr->sviat__review_requests__unit_days|escape}</span>
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__ttl_hint|escape}</p>
                        </div>
                        <div class="rr-field">
                            <div class="okay_switch clearfix rr-switch">
                                <label class="switch_label" for="rr_one_click">{$btr->sviat__review_requests__one_click|escape}</label>
                                <label class="switch switch-default">
                                    <input id="rr_one_click" class="switch-input" type="checkbox" name="one_click" value="1" {if $rr.one_click}checked{/if}>
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                            <p class="rr-help">{$btr->sviat__review_requests__one_click_hint|escape}</p>
                        </div>
                    </section>
                </div>

                <div class="rr-savebar">
                    <button type="submit" class="btn btn_small btn_blue">{$btr->general_apply|escape}</button>
                </div>
            </form>
        </div>

        {* ── Інструменти ── *}
        <div id="rr_tools" class="tab">
            <div class="rr-grid">
                <section class="boxed rr-card">
                    <h2 class="rr-card__title">{$btr->sviat__review_requests__test_title|escape}</h2>
                    <p class="rr-help">{$btr->sviat__review_requests__test_hint|escape}</p>
                    <form method="post" action="{url active_tab='rr_tools'}" class="rr-inline-form">
                        <input type="hidden" name="session_id" value="{$smarty.session.id}">
                        <input type="hidden" name="action" value="send_test">
                        <input class="form-control" type="email" name="test_email" required placeholder="you@example.com" aria-label="{$btr->sviat__review_requests__test_title|escape}">
                        <button type="submit" class="btn btn_small btn_blue">{$btr->sviat__review_requests__test_button|escape}</button>
                    </form>
                </section>

                <section class="boxed rr-card">
                    <h2 class="rr-card__title">{$btr->sviat__review_requests__optout_title|escape}</h2>
                    <p class="rr-help">{$btr->sviat__review_requests__optout_hint|escape}</p>
                    <form method="post" action="{url active_tab='rr_tools'}" class="rr-inline-form">
                        <input type="hidden" name="session_id" value="{$smarty.session.id}">
                        <input type="hidden" name="action" value="optout_remove">
                        <input class="form-control" type="email" name="optout_email" required placeholder="buyer@example.com" aria-label="{$btr->sviat__review_requests__optout_title|escape}">
                        <button type="submit" class="btn btn_small btn-outline-info">{$btr->sviat__review_requests__optout_button|escape}</button>
                    </form>
                </section>

                <section class="boxed rr-card rr-card--wide">
                    <h2 class="rr-card__title">{$btr->sviat__review_requests__backfill_title|escape}</h2>
                    <p class="rr-help">{$btr->sviat__review_requests__backfill_hint|escape}</p>
                    <form method="post" action="{url active_tab='rr_tools'}">
                        <input type="hidden" name="session_id" value="{$smarty.session.id}">
                        <div class="rr-range">
                            <span>{$btr->sviat__review_requests__from|escape}</span>
                            <input class="form-control rr-date" type="date" name="date_from" value="{$backfill_period[0]|default:''|escape}" aria-label="{$btr->sviat__review_requests__from|escape}">
                            <span>{$btr->sviat__review_requests__to|escape}</span>
                            <input class="form-control rr-date" type="date" name="date_to" value="{$backfill_period[1]|default:''|escape}" aria-label="{$btr->sviat__review_requests__to|escape}">
                            <button type="submit" name="action" value="backfill_preview" class="btn btn_small btn-outline-info">{$btr->sviat__review_requests__backfill_preview|escape}</button>
                        </div>
                        {if $backfill_preview}
                            <div class="rr-preview">
                                <div class="rr-preview__numbers">
                                    <div><span class="rr-preview__value">{$backfill_preview.total}</span>{$btr->sviat__review_requests__backfill_found|escape}</div>
                                    <div><span class="rr-preview__value rr-ok">{$backfill_preview.pending}</span>{$btr->sviat__review_requests__backfill_will_queue|escape}</div>
                                </div>
                                {if $backfill_preview.skipped}
                                    <ul class="rr-preview__skipped">
                                        {foreach $backfill_preview.skipped as $reason => $n}
                                            {$rr_key = "sviat__review_requests__reason_`$reason`"}
                                            <li>{$btr->getTranslation($rr_key)|escape}: {$n}</li>
                                        {/foreach}
                                    </ul>
                                {/if}
                                {if $backfill_preview.truncated}<p class="rr-warn">{$btr->sviat__review_requests__backfill_truncated|escape}</p>{/if}
                                {if $backfill_preview.pending > 0}
                                    <button type="submit" name="action" value="backfill_confirm" class="btn btn_small btn-warning">{$btr->sviat__review_requests__backfill_confirm|escape} ({$backfill_preview.pending})</button>
                                {/if}
                            </div>
                        {/if}
                    </form>
                </section>

                <section class="boxed rr-card rr-card--wide">
                    <h2 class="rr-card__title">{$btr->sviat__review_requests__verified_title|escape}</h2>
                    {if $rr_verified_done_at}
                        <p class="rr-help">{$btr->sviat__review_requests__verified_done_at|escape} {$rr_verified_done_at|escape}: {$rr_verified_done_count|escape}</p>
                    {else}
                        <p class="rr-help">{$btr->sviat__review_requests__verified_hint|escape}</p>
                        <form method="post" action="{url active_tab='rr_tools'}">
                            <input type="hidden" name="session_id" value="{$smarty.session.id}">
                            <button type="submit" name="action" value="verified_preview" class="btn btn_small btn-outline-info">{$btr->sviat__review_requests__verified_preview|escape}</button>
                            {if $verified_preview}
                                <div class="rr-preview">
                                    <div class="rr-preview__numbers">
                                        <div><span class="rr-preview__value rr-ok">{$verified_preview.total|escape}</span>{$btr->sviat__review_requests__verified_found|escape}</div>
                                    </div>
                                    {if $verified_preview.rows}
                                        <div class="rr-table-wrap">
                                            <table class="rr-table">
                                                <thead><tr>
                                                    <th>{$btr->sviat__review_requests__verified_col_date|escape}</th>
                                                    <th>{$btr->sviat__review_requests__verified_col_author|escape}</th>
                                                    <th>{$btr->sviat__review_requests__verified_col_product|escape}</th>
                                                    <th>{$btr->sviat__review_requests__verified_col_order|escape}</th>
                                                </tr></thead>
                                                <tbody>
                                                    {foreach $verified_preview.rows as $row}
                                                        <tr>
                                                            <td>{$row->date|escape}</td>
                                                            <td>{$row->name|escape}<br><span class="rr-muted">{$row->email_shown|escape}</span></td>
                                                            <td>{$row->product_name|escape}</td>
                                                            <td><a href="{url controller=OrderAdmin id=$row->order_id return=null active_tab=null log_status=null}" target="_blank" rel="noopener">#{$row->order_id|escape}</a></td>
                                                        </tr>
                                                    {/foreach}
                                                </tbody>
                                            </table>
                                        </div>
                                        {if $verified_preview.total > $verified_preview.rows|count}<p class="rr-warn">{$btr->sviat__review_requests__verified_truncated|escape}</p>{/if}
                                    {/if}
                                    {if $verified_preview.total > 0}
                                        <button type="submit" name="action" value="verified_confirm" class="btn btn_small btn-warning">{$btr->sviat__review_requests__verified_confirm|escape} ({$verified_preview.total|escape})</button>
                                    {/if}
                                </div>
                            {/if}
                        </form>
                    {/if}
                </section>
            </div>
        </div>

        {* ── Журнал ── *}
        <div id="rr_log" class="tab">
            <section class="boxed rr-card">
                <div class="rr-log-head">
                    <h2 class="rr-card__title">{$btr->sviat__review_requests__log_title|escape}</h2>
                    <nav class="rr-filter" aria-label="{$btr->sviat__review_requests__log_status|escape}">
                        <a class="rr-filter__item {if !$rr_log_status}is-active{/if}" href="{url active_tab='rr_log' log_status=null}">{$btr->sviat__review_requests__filter_all|escape}</a>
                        {foreach ['pending','sent','skipped','cancelled','error'] as $s}
                            {$rr_key = "sviat__review_requests__status_`$s`"}
                            <a class="rr-filter__item {if $rr_log_status == $s}is-active{/if}" href="{url active_tab='rr_log' log_status=$s}">{$btr->getTranslation($rr_key)|escape} <span class="rr-filter__count">{$rr_stats.by_status.$s|default:0}</span></a>
                        {/foreach}
                    </nav>
                </div>
                {if $rr_log}
                    <div class="rr-table-wrap">
                        <table class="rr-table">
                            <thead>
                                <tr>
                                    <th>{$btr->sviat__review_requests__log_order|escape}</th>
                                    <th>{$btr->sviat__review_requests__log_email|escape}</th>
                                    <th>{$btr->sviat__review_requests__log_status|escape}</th>
                                    <th>{$btr->sviat__review_requests__log_detected|escape}</th>
                                    <th>{$btr->sviat__review_requests__log_sent|escape}</th>
                                    <th>{$btr->sviat__review_requests__log_visited|escape}</th>
                                    <th class="rr-num-col">{$btr->sviat__review_requests__log_reviews|escape}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach $rr_log as $row}
                                    {$rr_key = "sviat__review_requests__status_`$row->status`"}
                                    <tr>
                                        <td><a href="{url controller=OrderAdmin id=$row->order_id return=null active_tab=null log_status=null}">#{$row->order_id}</a></td>
                                        <td>{$row->email_shown|escape}</td>
                                        <td>
                                            <span class="rr-badge rr-badge--{$row->status|escape}">{$btr->getTranslation($rr_key)|escape}</span>
                                            {if $row->skip_reason}{$rr_key = "sviat__review_requests__reason_`$row->skip_reason`"}<div class="rr-reason">{$btr->getTranslation($rr_key)|escape}</div>{/if}
                                            {if $row->error_text}<div class="rr-reason rr-reason--error">{$row->error_text|escape}</div>{/if}
                                        </td>
                                        <td>{$row->detected_at|date_format:'%d.%m.%Y %H:%M'}</td>
                                        <td>{if $row->sent_at}{$row->sent_at|date_format:'%d.%m.%Y %H:%M'}{else}—{/if}</td>
                                        <td>{if $row->first_visit_at}{$row->first_visit_at|date_format:'%d.%m.%Y %H:%M'}{else}—{/if}</td>
                                        <td class="rr-num-col">{$row->reviews}</td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                {else}
                    <p class="rr-empty">{if $rr_log_status}{$btr->sviat__review_requests__log_empty_filtered|escape}{else}{$btr->sviat__review_requests__log_empty|escape}{/if}</p>
                {/if}
            </section>
        </div>

    </div>
</div>
