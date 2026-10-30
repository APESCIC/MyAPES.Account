@php($status = session('status'))
<div class="sg-toasts" data-toast-region>
    @if(is_string($status) && $status !== '')
        <div class="sg-toast sg-toast-ok" role="status" aria-live="polite" aria-atomic="true" data-toast data-toast-tone="ok">
            <i data-lucide="circle-check-big" aria-hidden="true"></i>
            <p class="sg-toast__message" data-toast-message>{{ $status }}</p>
            <button type="button" class="sg-toast__close" data-toast-dismiss aria-label="{{ __('flash.dismiss') }}">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>
    @endif
    @if($errors->any())
        <div class="sg-toast sg-toast-error" role="alert" aria-atomic="true" data-toast data-toast-tone="error">
            <i data-lucide="circle-help" aria-hidden="true"></i>
            <div class="sg-toast__message" data-toast-message>
                <p><strong>{{ trans_choice('flash.errors_heading', $errors->count()) }}</strong></p>
                <ul class="error-list">
                    @foreach($errors->all() as $error)
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
