<x-tickets::embed.layout :parent-origin="$parentOrigin">
    <h1>{{ __('tickets::embed.title') }}</h1>
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('tickets.embed.store', $form->public_key) }}" data-myapes-embed-form novalidate>
        @csrf
        <input type="hidden" name="embed_token" value="{{ $token }}">
        <input type="hidden" name="parent_origin" value="{{ $parentOrigin }}">

        <p>
            <label for="embed-name">{{ __('tickets::embed.name') }}</label>
            <input id="embed-name" type="text" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
        </p>
        <p>
            <label for="embed-email">{{ __('tickets::embed.email') }}</label>
            <input id="embed-email" type="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email">
        </p>
        <p>
            <label for="embed-service">{{ __('tickets::embed.service') }}</label>
            <select id="embed-service" name="service" required data-embed-service>
                <option value="">{{ __('tickets::embed.select_service') }}</option>
                @foreach ($services as $service)
                    <option value="{{ $service['key'] }}" @selected(old('service') === $service['key']) data-areas="{{ e(json_encode($service['areas'])) }}">
                        {{ $service['label'] }}
                    </option>
                @endforeach
            </select>
        </p>
        <p>
            <label for="embed-area">{{ __('tickets::embed.service_area') }}</label>
            <select id="embed-area" name="service_area" required data-embed-area>
                <option value="">{{ __('tickets::embed.select_area') }}</option>
            </select>
        </p>
        <p>
            <label for="embed-subject">{{ __('tickets::embed.subject') }}</label>
            <input id="embed-subject" type="text" name="subject" value="{{ old('subject') }}" required maxlength="255">
        </p>
        <p>
            <label for="embed-details">{{ __('tickets::embed.details') }}</label>
            <textarea id="embed-details" name="details" rows="6" required maxlength="10000">{{ old('details') }}</textarea>
        </p>
        <p>
            <label>
                <input type="checkbox" name="consent" value="1" @checked(old('consent')) required>
                {{ __('tickets::embed.consent') }}
                <a href="{{ \App\Support\PrivacyNotice::url() }}" target="_blank" rel="noopener noreferrer">{{ __('tickets::embed.privacy_link') }}</a>
            </label>
        </p>

        <x-bot-protection :form="\App\Core\BotProtection\BotProtection::TICKET_EMBED" />

        <p>
            <button type="submit">{{ __('tickets::embed.submit') }}</button>
        </p>
    </form>

    @push('scripts')
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            (function () {
                var service = document.querySelector('[data-embed-service]');
                var area = document.querySelector('[data-embed-area]');
                if (!service || !area) return;
                var selected = @json(old('service_area'));
                function refresh() {
                    var opt = service.options[service.selectedIndex];
                    var areas = [];
                    try { areas = JSON.parse(opt.getAttribute('data-areas') || '[]'); } catch (e) { areas = []; }
                    area.innerHTML = '';
                    var blank = document.createElement('option');
                    blank.value = '';
                    blank.textContent = @json(__('tickets::embed.select_area'));
                    area.appendChild(blank);
                    areas.forEach(function (key) {
                        var o = document.createElement('option');
                        o.value = key;
                        o.textContent = key.replace(/_/g, ' ');
                        if (selected && selected === key) o.selected = true;
                        area.appendChild(o);
                    });
                }
                service.addEventListener('change', function () { selected = null; refresh(); });
                refresh();
            })();
        </script>
    @endpush
</x-tickets::embed.layout>
