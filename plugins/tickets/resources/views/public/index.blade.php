@extends('layouts.app')

@section('title', __('tickets::public.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel" data-public-open-ticket data-public-open-ticket-mode="{{ $mode }}">
        <h1>{{ __('tickets::public.title') }}</h1>
        @if($mode === 'guest')
            <p class="muted">@registrationOpen{{ __('tickets::public.intro_guest') }}@else{{ __('tickets::public.intro_guest_closed') }}@endregistrationOpen</p>
            <x-mascot-tip />
            <div class="actions">
                <a href="{{ route('public.login') }}" data-open-ticket-login>{{ __('tickets::public.sign_in_to_open') }}</a>
                @registrationOpen
                    <a href="{{ route('public.register') }}" data-open-ticket-register>{{ __('tickets::public.register_to_open') }}</a>
                @endregistrationOpen
            </div>
        @elseif($mode === 'staff')
            <p class="muted">{{ __('tickets::public.intro_staff') }}</p>
            <x-mascot-tip />
            <x-public-account-required only-staff-side />
        @else
            <p class="muted">{{ __('tickets::public.intro_form') }}</p>
            <x-mascot-tip />
        @endif
    </div>

    @if($mode === 'guest' && count($services) > 0)
        @foreach($services as $service)
            <section class="panel" aria-labelledby="open-ticket-{{ $service['key'] }}" data-public-open-ticket-service="{{ $service['key'] }}">
                <h2 id="open-ticket-{{ $service['key'] }}">{{ $service['name'] }}</h2>
                <p class="muted">{{ $service['description'] }}</p>
                <h3>{{ __('tickets::public.areas_heading') }}</h3>
                <ul class="stack-spaced">
                    @foreach($service['areas'] as $area)
                        <li><strong>{{ $area['label'] }}</strong></li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @elseif($mode === 'form')
        @if(count($services) === 0)
            <div class="panel">
                <p>{{ __('tickets::public.none') }}</p>
            </div>
        @else
            <div class="panel" id="create" data-public-open-ticket-form>
                <h2>{{ __('tickets::public.form_heading') }}</h2>
                <form
                    method="post"
                    action="{{ route('tickets.public.store') }}"
                    enctype="multipart/form-data"
                    data-ticket-create-form
                    data-open-ticket-combined
                >
                    @csrf
                    <x-bot-protection form="ticket" />
                    <div class="row">
                        <div>
                            <label for="service">{{ __('tickets::public.service_label') }}</label>
                            <select id="service" name="service" required data-open-ticket-service>
                                <option value="">{{ __('tickets::public.select_service') }}</option>
                                @foreach($services as $service)
                                    <option value="{{ $service['key'] }}" @selected(old('service', $prefillService) === $service['key'])>
                                        {{ $service['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="service_area">{{ __('tickets::ui.index.blade.service_area') }}</label>
                            <select id="service_area" name="service_area" required data-open-ticket-area data-category-parent>
                                <option value="">{{ __('tickets::public.select_area') }}</option>
                            </select>
                        </div>
                        <div data-cic-fields hidden>
                            <label for="sub_category">{{ __('tickets::ui.index.blade.subcategory') }}</label>
                            <select id="sub_category" name="sub_category" data-category-child>
                                <option value="">{{ __('tickets::ui.index.blade.select_subcategory') }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="priority">{{ __('tickets::ui.index.blade.priority') }}</label>
                            <select id="priority" name="priority">
                                @foreach($priorities as $priority)
                                    <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>
                                        {{ \App\Support\WorkflowLabels::priority($priority) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div data-website-field hidden>
                        <label for="affected_website_key">
                            {{ __('tickets::ui.index.blade.affected_website') }}
                            <span class="required-mark" data-required-mark>{{ __('tickets::ui.index.blade.required') }}</span>
                        </label>
                        <select id="affected_website_key" name="affected_website_key">
                            <option value="">{{ __('tickets::ui.index.blade.select_website') }}</option>
                            @foreach($cicWebsites as $website)
                                <option value="{{ $website['key'] }}" @selected(old('affected_website_key') === $website['key'])>
                                    {{ $website['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <label for="subject">{{ __('tickets::ui.index.blade.subject') }}</label>
                    <input id="subject" name="subject" value="{{ old('subject') }}" required>
                    <label for="description">{{ __('tickets::ui.index.blade.description') }}</label>
                    <x-rich-text-editor name="description" :value="old('description')" required />
                    <div data-attachment-fields>
                        <x-attachment-uploads id-prefix="ticket-create" />
                    </div>
                    <button type="submit">{{ __('tickets::ui.index.blade.create_ticket') }}</button>
                </form>
            </div>

            @include('tickets::public.combined-form-script', [
                'services' => $services,
                'cicGroups' => $cicGroups,
                'prefillService' => old('service', $prefillService),
                'prefillArea' => old('service_area', $prefillArea),
                'oldSubCategory' => old('sub_category'),
            ])
        @endif
    @endif
@endsection
