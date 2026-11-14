@props(['profiles', 'current' => null, 'returnTo' => null, 'idPrefix' => 'qa'])
<div {{ $attributes->class('sg-qa-picker') }}>
    @foreach ([__('qa.picker.account_types') => $profiles['groups'], __('qa.picker.common_stacks') => $profiles['stacks']] as $heading => $list)
        <section class="sg-qa-picker__group" aria-labelledby="{{ $idPrefix }}-{{ \Illuminate\Support\Str::slug($heading) }}">
            <h3 id="{{ $idPrefix }}-{{ \Illuminate\Support\Str::slug($heading) }}" class="sg-qa-picker__heading">{{ $heading }}</h3>
            <ul class="sg-qa-picker__list">
                @foreach ($list as $profile)
                    <li>
                        <form method="post" action="{{ route('qa.login') }}">
                            @csrf
                            <input type="hidden" name="profile" value="{{ $profile['key'] }}">
                            @if ($returnTo)
                                <input type="hidden" name="return_to" value="{{ $returnTo }}">
                            @endif
                            <button
                                type="submit"
                                class="sg-qa-btn"
                                @if ($current === $profile['key']) aria-current="true" @endif
                            >
                                {{ $profile['label'] }}
                                @if ($current === $profile['key'])
                                    <span class="sg-qa-btn__current">{{ __('qa.picker.current') }}</span>
                                @endif
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
