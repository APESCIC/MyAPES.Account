@php($status = session('status'))
@php($errorBag = $errors ?? session('errors'))
<div class="visually-hidden" aria-live="polite" data-toast-announcer="polite"></div>
<div class="visually-hidden" aria-live="assertive" data-toast-announcer="assertive"></div>
<div class="sg-toasts" data-toast-region>
    @if(is_string($status) && $status !== '')
        <div class="sg-toast sg-toast-ok" data-toast data-toast-tone="ok">
            <i data-lucide="circle-check-big" aria-hidden="true"></i>
            <p class="sg-toast__message" data-toast-message>{{ $status }}</p>
            <button type="button" class="sg-toast__close" data-toast-dismiss aria-label="{{ __('flash.dismiss') }}">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>
    @endif
    @if($errorBag?->any())
        <div class="sg-toast sg-toast-error" data-toast data-toast-tone="error">
            <i data-lucide="circle-help" aria-hidden="true"></i>
            <div class="sg-toast__message" data-toast-message>
                <p><strong>{{ trans_choice('flash.errors_heading', $errorBag->count()) }}</strong></p>
                <ul class="error-list">
                    @foreach($errorBag->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            <button type="button" class="sg-toast__close" data-toast-dismiss aria-label="{{ __('flash.dismiss') }}">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>
    @endif
</div>
