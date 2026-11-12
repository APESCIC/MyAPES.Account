<section class="panel" id="local-replies" aria-labelledby="local-replies-heading" data-local-replies>
    <h2 id="local-replies-heading">{{ __('admin.emails.inbound.local.heading') }}</h2>
    <p class="muted">{{ __('admin.emails.inbound.local.help') }}</p>

    @if(is_array(session('local_fetched')) && session('local_fetched') !== [])
        <ul data-local-fetched>
            @foreach(session('local_fetched') as $fetched)
                <li>
                    <a href="{{ $fetched['url'] }}">{{ $fetched['label'] }}</a> ({{ $fetched['status'] }})
                    @if($fetched['record_url'])
                        · <a href="{{ $fetched['record_url'] }}">{{ __('admin.emails.inbound.detail_open_record') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if($local['error'] !== null)
        <p class="muted" data-local-error>{{ __('admin.emails.inbound.local.unavailable', ['reason' => $local['error']]) }}</p>
    @elseif($local['emails'] === [])
        <p data-local-empty>{{ __('admin.emails.inbound.local.empty') }}</p>
    @else
        <div class="table-wrap" role="region" aria-label="{{ __('admin.emails.inbound.local.caption') }}" tabindex="0">
            <table>
                <caption>{{ __('admin.emails.inbound.local.caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.emails.inbound.local.sent_at') }}</th>
                        <th scope="col">{{ __('admin.emails.inbound.detail_subject') }}</th>
                        <th scope="col">{{ __('admin.emails.inbound.local.recipient') }}</th>
                        <th scope="col">{{ __('admin.emails.inbound.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($local['emails'] as $email)
                        <tr data-local-email="{{ $email['id'] }}">
                            <td>{{ $email['created'] !== '' ? $email['created'] : '—' }}</td>
                            <td>{{ $email['subject'] }}</td>
                            <td>{{ $email['to'] }}</td>
                            <td><a href="{{ $email['url'] }}" target="_blank" rel="noopener">{{ __('admin.emails.inbound.local.open_in_mailpit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="post" action="{{ route('admin.emails.inbound.local.reply') }}" class="stacked-form" data-local-reply-form>
            @csrf
            <h3>{{ __('admin.emails.inbound.local.reply_heading') }}</h3>
            <label for="local-reply-message">{{ __('admin.emails.inbound.local.message') }}</label>
            <select id="local-reply-message" name="message" required>
                @foreach($local['emails'] as $email)
                    <option value="{{ $email['id'] }}" @selected(old('message') === $email['id'])>{{ $email['subject'] }} → {{ $email['to'] }}</option>
                @endforeach
            </select>

            <label for="local-reply-body">{{ __('admin.emails.inbound.local.body') }}</label>
            <textarea id="local-reply-body" name="body" rows="3" required maxlength="5000">{{ old('body', __('admin.emails.inbound.local.body_default')) }}</textarea>

            <label for="local-reply-as">{{ __('admin.emails.inbound.local.as') }}</label>
            <input id="local-reply-as" name="as" type="email" value="{{ old('as') }}" placeholder="{{ __('admin.emails.inbound.local.as_placeholder') }}">

            <fieldset>
                <legend>{{ __('admin.emails.inbound.local.variants') }}</legend>
                @foreach($local['variants'] as $variant => $label)
                    <label class="inline-check">
                        <input type="checkbox" name="variants[]" value="{{ $variant }}" @checked(in_array($variant, old('variants', []), true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="actions">
                <button type="submit">{{ __('admin.emails.inbound.local.reply_submit') }}</button>
            </div>
        </form>
    @endif

    <form method="post" action="{{ route('admin.emails.inbound.local.fetch') }}" data-local-fetch-form>
        @csrf
        <div class="actions">
            <button type="submit" class="button button-secondary">{{ __('admin.emails.inbound.local.fetch') }}</button>
        </div>
    </form>
</section>
